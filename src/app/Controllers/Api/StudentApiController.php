<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\SettingModel;

class StudentApiController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
    }

    private function requireUser(): ?int
    {
        $userId = session('user_id');
        if (!$userId) {
            return null;
        }
        return (int) $userId;
    }

    public function exams()
    {
        $userId = $this->requireUser();
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Sesi berakhir. Silakan login kembali.']);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $sql = "
            SELECT DISTINCT t.id, t.name, t.description, t.exam_mode, t.duration_minutes,
                   t.begin_time, t.end_time, t.passing_score, t.max_score,
                   t.password, t.is_repeatable, t.show_menu, t.allow_noanswer,
                   t.auto_submit_on_cheat, t.show_score_after_exam, t.allow_review,
                   (SELECT status FROM test_attempts ta WHERE ta.test_id = t.id AND ta.user_id = ? ORDER BY ta.id DESC LIMIT 1) as attempt_status
            FROM tests t
            JOIN test_groups tg ON tg.test_id = t.id
            JOIN user_groups ug ON ug.group_id = tg.group_id
            WHERE ug.user_id = ?
              AND t.is_enabled = 1
              AND t.deleted_at IS NULL
            ORDER BY t.created_at DESC
        ";
        $tests = $db->query($sql, [$userId, $userId])->getResult();

        $settingModel = new SettingModel();
        $globalShowScore = (bool) $settingModel->getValue('show_score_after_exam', false);
        $globalAllowReview = (bool) $settingModel->getValue('allow_review', false);

        $activeAttempt = null;
        $exams = [];
        foreach ($tests as $t) {
            // ikuti pola DashboardController: nilai per-test menang, fallback ke global
            if ($t->show_score_after_exam !== null) {
                $canShowScore = (bool) $t->show_score_after_exam;
            } else {
                $canShowScore = $globalShowScore;
            }
            if ($t->allow_review !== null) {
                $canAllowReview = (bool) $t->allow_review;
            } else {
                $canAllowReview = $globalAllowReview;
            }

            $exams[] = [
                'id' => (int) $t->id,
                'name' => $t->name,
                'description' => (string) ($t->description ?? ''),
                'exam_mode' => $t->exam_mode,
                'duration_minutes' => (int) $t->duration_minutes,
                'begin_time' => $t->begin_time,
                'end_time' => $t->end_time,
                'passing_score' => (float) ($t->passing_score ?? 0),
                'max_score' => (float) ($t->max_score ?? 0),
                'password_required' => !empty($t->password),
                'is_repeatable' => (int) $t->is_repeatable,
                'auto_submit_on_cheat' => (int) ($t->auto_submit_on_cheat ?? 0),
                'show_menu' => (int) $t->show_menu,
                'allow_noanswer' => (int) $t->allow_noanswer,
                'attempt_status' => $t->attempt_status !== null ? (int) $t->attempt_status : null,
                'can_show_score' => $canShowScore,
                'can_allow_review' => $canAllowReview,
                // Ketersediaan dihitung SERVER, bukan dari jam perangkat: jam
                // tablet kiosk gampang meleset dan bisa diubah siswa, jadi
                // klien tidak boleh jadi wasit jendela waktu ujian.
                'availability' => $this->availability($t),
            ];

            if ($t->attempt_status !== null && (int) $t->attempt_status === 1 && !$activeAttempt) {
                $activeAttempt = ['test_id' => (int) $t->id, 'attempt_status' => 1];
            }
        }

        // attempt aktif: test_logs sudah dibuat, status 1
        if (!$activeAttempt) {
            $row = $db->table('test_attempts')
                ->select('test_id')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->orderBy('id', 'DESC')
                ->get()->getRow();
            if ($row) {
                $activeAttempt = ['test_id' => (int) $row->test_id, 'attempt_status' => 1];
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            // Identitas dikirim agar dashboard bisa menampilkan "login sebagai siapa":
            // siswa yang salah akun harus tahu SEBELUM ujian dimulai, karena
            // attempt yang terlanjur dibuat tidak bisa dibatalkan sendiri.
            'user' => [
                'id' => $userId,
                'username' => session('username'),
                'firstname' => session('firstname'),
                'lastname' => session('lastname'),
            ],
            'exams' => $exams,
            'active_attempt' => $activeAttempt,
            // Jam server dikirim supaya hitung mundur di kiosk tidak memakai
            // jam perangkat, yang bisa meleset jauh atau sengaja diubah.
            'server_now_ms' => (int) round(microtime(true) * 1000),
        ]);
    }

    public function results()
    {
        $userId = $this->requireUser();
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error']);
        }

        $db = \Config\Database::connect();
        $rows = $db->query("
            SELECT ta.id as attempt_id, ta.test_id, t.name as test_name, ta.score, t.max_score,
                   ta.status, ta.finished_at
            FROM test_attempts ta
            JOIN tests t ON t.id = ta.test_id
            WHERE ta.user_id = ? AND ta.status IN (3, 4)
            ORDER BY ta.finished_at DESC
        ", [$userId])->getResult();

        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'attempt_id' => (int) $r->attempt_id,
                'test_id' => (int) $r->test_id,
                'test_name' => $r->test_name,
                'score' => $r->score !== null ? (float) $r->score : null,
                'max_score' => $r->max_score !== null ? (float) $r->max_score : null,
                'status' => (int) $r->status,
                'finished_at' => $r->finished_at,
            ];
        }

        return $this->response->setJSON(['status' => 'success', 'results' => $results]);
    }

    /**
     * Status ketersediaan satu ujian bagi siswa ini.
     *
     * @return array{status:string, message:string}
     */
    private function availability($t): array
    {
        $status = $t->attempt_status !== null ? (int) $t->attempt_status : null;

        if ($status === 0 || $status === 1) {
            return ['status' => 'resume', 'message' => 'Anda punya pengerjaan yang belum selesai.'];
        }
        if ($status === 2) {
            return ['status' => 'locked', 'message' => 'Ujian dikunci karena pelanggaran. Hubungi pengawas.'];
        }
        if ($status === 3 && empty($t->is_repeatable)) {
            return ['status' => 'done', 'message' => 'Ujian ini sudah Anda kerjakan.'];
        }

        $now = time();
        if (!empty($t->begin_time) && $now < strtotime((string) $t->begin_time)) {
            return ['status' => 'not_yet', 'message' => 'Ujian belum dibuka.'];
        }
        if (!empty($t->end_time) && $now > strtotime((string) $t->end_time)) {
            return ['status' => 'closed', 'message' => 'Waktu ujian sudah berakhir.'];
        }

        return ['status' => 'open', 'message' => ''];
    }

    public function review()
    {
        $userId = $this->requireUser();
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error']);
        }

        $testId = (int) $this->request->getGet('test_id');
        if ($testId <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'test_id diperlukan.']);
        }

        $test = $this->testModel->find($testId);
        if (!$test) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Ujian tidak ditemukan.']);
        }

        $attempt = $this->attemptModel->where('test_id', $testId)->where('user_id', $userId)->orderBy('id', 'DESC')->first();
        if (!$attempt) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Anda belum mengerjakan ujian ini.']);
        }
        if ((int) $attempt->status !== 3) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'Ujian ini belum selesai.']);
        }

        $showScore = $test->show_score_after_exam !== null ? (bool) $test->show_score_after_exam : (bool) (new SettingModel())->getValue('show_score_after_exam', true);
        $showCorrect = $test->show_correct_answers !== null ? (bool) $test->show_correct_answers : (bool) (new SettingModel())->getValue('show_correct_answers', false);
        $allowReview = $test->allow_review !== null ? (bool) $test->allow_review : (bool) (new SettingModel())->getValue('allow_review', true);

        // Copy struktur ResultController::view + review() ke JSON (lihat file referensi,
        // baris 30-210): summary counts, per-question data, kunci jawaban hanya bila $showCorrect.
        // --- referensi data ---
        $db = \Config\Database::connect();
        $logs = $db->table('test_logs')->where('test_attempt_id', $attempt->id)->orderBy('display_order', 'ASC')->get()->getResult();

        $summary = ['total' => count($logs), 'correct' => 0, 'wrong' => 0, 'unanswered' => 0, 'score' => (float) ($attempt->score ?? 0), 'max_score' => (float) ($test->max_score ?? 0)];
        $questions = [];
        foreach ($logs as $log) {
            $q = [
                'question_id' => (int) $log->question_id,
                'question_text' => $log->question_text,
                'question_type' => (int) $log->question_type,
                'is_unsure' => (int) ($log->is_unsure ?? 0),
                'score' => (float) ($log->score ?? 0),
                'user_answers' => [],
                'correct_answers' => [],
            ];
            if ((int) $log->question_type === 3) {
                // Kunci 'answer_text', sama seperti cabang lain: klien membaca
                // satu nama field untuk semua tipe soal.
                $q['user_answers'] = trim((string) ($log->answer_text ?? '')) === '' ? [] : [['answer_text' => $log->answer_text]];
                if (empty($q['user_answers'])) {
                    $summary['unanswered']++;
                } elseif ($log->score > 0) {
                    $summary['correct']++;
                } else {
                    $summary['wrong']++;
                }
            } elseif ((int) $log->question_type === 4 || (int) $log->question_type === 5) {
                // Menjodohkan & Benar/Salah tidak memakai is_selected sama sekali:
                // pilihan siswa disimpan sebagai JSON {kiri: kanan} di
                // test_logs.answer_text (lihat ExamApiController::autosave).
                // Membacanya lewat test_log_answers seperti tipe lain selalu
                // menghasilkan daftar kosong -- soal yang jelas terjawab pun
                // tampil "(tidak dijawab)" dan ikut terhitung kosong.
                $picked = json_decode((string) ($log->answer_text ?? ''), true);
                if (!is_array($picked)) {
                    $picked = [];
                }

                // Baris test_log_answers menyimpan pasangan benar "kiri|::|kanan".
                $rows = $db->table('test_log_answers')->where('test_log_id', $log->id)->orderBy('display_order', 'ASC')->get()->getResult();
                $pairs = [];
                $answeredPairs = 0;
                foreach ($rows as $a) {
                    $parts = explode('|::|', (string) $a->answer_text);
                    if (count($parts) !== 2) {
                        continue;
                    }
                    $left  = trim($parts[0]);
                    $right = trim($parts[1]);
                    $mine  = trim((string) ($picked[$left] ?? ''));
                    if ($mine !== '') {
                        $answeredPairs++;
                    }
                    $pairs[] = [
                        'left'       => $left,
                        'user'       => $mine,
                        'correct'    => $showCorrect ? $right : null,
                        'is_correct' => $mine !== '' && $mine === $right,
                    ];
                }

                $q['matching'] = $pairs;
                foreach ($pairs as $pair) {
                    if ($pair['user'] !== '') {
                        $q['user_answers'][] = ['answer_text' => $pair['left'] . ' → ' . $pair['user']];
                    }
                    if ($showCorrect) {
                        $q['correct_answers'][] = ['answer_text' => $pair['left'] . ' → ' . $pair['correct']];
                    }
                }

                if ($answeredPairs === 0) {
                    $summary['unanswered']++;
                } elseif ($log->score > 0) {
                    $summary['correct']++;
                } else {
                    $summary['wrong']++;
                }
            } else {
                $raw = $db->table('test_log_answers')->where('test_log_id', $log->id)->orderBy('display_order', 'ASC')->get()->getResult();
                $userSel = []; $correctSet = [];
                foreach ($raw as $a) {
                    if ($showCorrect && (int) $a->is_correct === 1) {
                        $correctSet[] = ['answer_id' => (int) $a->answer_id, 'answer_text' => $a->answer_text];
                    }
                    if ((int) $a->is_selected === 1) {
                        $userSel[] = ['answer_id' => (int) $a->answer_id, 'answer_text' => $a->answer_text];
                    }
                }
                $q['user_answers'] = $userSel;
                $q['correct_answers'] = $correctSet;
                if (empty($userSel)) {
                    $summary['unanswered']++;
                } elseif ($log->score > 0) {
                    $summary['correct']++;
                } else {
                    $summary['wrong']++;
                }
            }
            $questions[] = $q;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'test' => ['id' => (int) $test->id, 'name' => $test->name, 'max_score' => (float) ($test->max_score ?? 0)],
            'show_score' => (bool) $showScore,
            'show_correct' => (bool) $showCorrect,
            'allow_review' => (bool) $allowReview,
            'summary' => $summary,
            'questions' => $questions,
        ]);
    }
}
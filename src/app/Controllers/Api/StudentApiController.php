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
            SELECT DISTINCT t.id, t.name, t.exam_mode, t.duration_minutes, t.begin_time, t.end_time,
                   t.password, t.is_repeatable, t.show_menu, t.allow_noanswer,
                   t.show_score_after_exam, t.allow_review,
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
                'exam_mode' => $t->exam_mode,
                'duration_minutes' => (int) $t->duration_minutes,
                'begin_time' => $t->begin_time,
                'end_time' => $t->end_time,
                'password_required' => !empty($t->password),
                'is_repeatable' => (int) $t->is_repeatable,
                'show_menu' => (int) $t->show_menu,
                'allow_noanswer' => (int) $t->allow_noanswer,
                'attempt_status' => $t->attempt_status !== null ? (int) $t->attempt_status : null,
                'can_show_score' => $canShowScore,
                'can_allow_review' => $canAllowReview,
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
            'exams' => $exams,
            'active_attempt' => $activeAttempt,
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
                $q['user_answers'] = trim((string) ($log->answer_text ?? '')) === '' ? [] : [['text' => $log->answer_text]];
                if (empty($q['user_answers'])) {
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
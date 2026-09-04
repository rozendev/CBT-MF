<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\TestLogModel;

class ResultController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;
    protected TestLogModel $testLogModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
        $this->testLogModel = new TestLogModel();
    }

    /**
     * List all tests that have attempts
     */
    public function index()
    {
        $db = \Config\Database::connect();
        
        $sql = "
            SELECT t.id, t.name, t.max_score, 
                   COUNT(ta.id) as total_attempts,
                   AVG(ta.score) as average_score
            FROM tests t
            LEFT JOIN test_attempts ta ON ta.test_id = t.id AND ta.status = 3
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ";
        
        $tests = $db->query($sql)->getResult();

        return view('admin/results/index', [
            'tests' => $tests
        ]);
    }

    /**
     * View all student attempts for a specific test
     */
    public function view($testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        $db = \Config\Database::connect();
        
        $sql = "
            SELECT ta.*, u.firstname, u.lastname, u.username, u.registration_number
            FROM test_attempts ta
            JOIN users u ON u.id = ta.user_id
            WHERE ta.test_id = ? AND ta.status = 3
            ORDER BY ta.score DESC, ta.finished_at ASC
        ";
        
        $attempts = $db->query($sql, [$testId])->getResult();

        // Tombol Koreksi Cepat hanya bermakna bila ada jawaban soal esai
        // manual yang sudah masuk lewat attempt selesai.
        $hasManualGrading = (bool) $db->query("
            SELECT COUNT(*) AS c
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE ta.test_id = ? AND ta.status = 3 AND q.type = 3 AND q.answer_mode = 'manual'
        ", [$testId])->getRow()->c;

        // Analisis butir baru punya arti kalau sudah ada attempt yang selesai;
        // dengan nol peserta, layarnya hanya akan menampilkan pesan kosong.
        $hasAnalysis = !empty($attempts);

        return view('admin/results/view', [
            'test'             => $test,
            'attempts'         => $attempts,
            'hasManualGrading' => $hasManualGrading,
            'hasAnalysis'      => $hasAnalysis,
        ]);
    }

    /**
     * View detailed answers of a specific attempt
     */
    public function detail($attemptId)
    {
        $attempt = $this->attemptModel->find($attemptId);
        if (!$attempt) return redirect()->back();

        $test = $this->testModel->find($attempt->test_id);
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find($attempt->user_id);

        $db = \Config\Database::connect();
        $sql = "
            SELECT tl.*, tl.question_difficulty as difficulty
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
            ORDER BY tl.display_order ASC
        ";
        $logs = $db->query($sql, [$attemptId])->getResult();

        $answers = [];
        $logIds = array_column($logs, 'id');
        
        if (!empty($logIds)) {
            $ansSql = "
                SELECT tla.*
                FROM test_log_answers tla
                WHERE tla.test_log_id IN ?
                ORDER BY tla.display_order ASC
            ";
            $rawAnswers = $db->query($ansSql, [$logIds])->getResult();
            
            foreach ($rawAnswers as $ans) {
                $answers[$ans->test_log_id][] = $ans;
            }
        }

        return view('admin/results/detail', [
            'attempt' => $attempt,
            'test' => $test,
            'user' => $user,
            'logs' => $logs,
            'answers' => $answers
        ]);
    }

    /**
     * Manually update question score and recalculate total score
     */
    public function updateManualScore()
    {
        $logId = $this->request->getPost('log_id');
        $score = (float) $this->request->getPost('score');

        $log = $this->testLogModel->find($logId);
        if (!$log) return redirect()->back()->with('error', 'Data tidak valid.');

        $this->testLogModel->update($logId, ['score' => $score]);

        $this->recalcAttemptScore((int) $log->test_attempt_id);

        return redirect()->back()->with('success', 'Nilai soal berhasil diperbarui dan skor akhir telah dikalkulasi ulang.');
    }

    /**
     * Hitung ulang dan simpan skor akhir satu attempt dari nilai test_logs.
     * Dipakai bersama oleh updateManualScore() dan gradeSave().
     *
     * Hanya soal yang sudah dinilai yang ikut jadi pembagi. Esai yang
     * menunggu koreksi bernilai NULL; kalau ikut dihitung, nilai siswa
     * tertekan turun hanya karena gurunya belum sempat mengoreksi.
     */
    private function recalcAttemptScore(int $attemptId): float
    {
        $db = \Config\Database::connect();

        $attempt = $this->attemptModel->find($attemptId);
        if (!$attempt) return 0.0;

        $test = $this->testModel->find($attempt->test_id);
        if (!$test) return 0.0;

        $result = $db->query("SELECT SUM(score) as total FROM test_logs WHERE test_attempt_id = ?", [$attemptId])->getRow();
        $rawScore = $result->total ?? 0;

        $resultMax = $db->query(
            "SELECT COUNT(*) as num_questions FROM test_logs WHERE test_attempt_id = ? AND score IS NOT NULL",
            [$attemptId]
        )->getRow();
        $numQuestions = $resultMax->num_questions ?? 0;

        $maxPossiblePoints = $numQuestions * $test->score_right;

        $finalScore = 0;
        if ($maxPossiblePoints > 0) {
            $finalScore = ($rawScore / $maxPossiblePoints) * $test->max_score;
        }
        if ($finalScore < 0) $finalScore = 0;

        $finalScore = round($finalScore, 3);
        $this->attemptModel->update($attemptId, ['score' => $finalScore]);

        return (float) $finalScore;
    }

    /**
     * Delete an exam result (attempt)
     */
    public function deleteAttempt($attemptId)
    {
        $db = \Config\Database::connect();
        $attempt = $db->table('test_attempts')->where('id', $attemptId)->get()->getRow();
        
        if (!$attempt) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['status' => 'error', 'message' => 'Data ujian tidak ditemukan.']);
            }
            return redirect()->back()->with('error', 'Data ujian tidak ditemukan.');
        }

        $db->transStart();

        // Delete log answers
        $logIds = $db->table('test_logs')
            ->where('test_attempt_id', $attemptId)
            ->select('id')
            ->get()->getResultArray();
        $logIds = array_column($logIds, 'id');

        if (!empty($logIds)) {
            $db->table('test_log_answers')->whereIn('test_log_id', $logIds)->delete();
        }
        $db->table('test_logs')->where('test_attempt_id', $attemptId)->delete();

        // Clear Redis cache for this attempt
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->del("exam_answers:{$attemptId}");
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on delete attempt: ' . $e->getMessage());
        }

        // Clear CI4 Application Cache to prevent ghost sessions
        try {
            $cache = \Config\Services::cache();
            $cache->delete("attempt_{$attemptId}");
            $cache->delete("active_attempt_{$attempt->test_id}_{$attempt->user_id}");
            $cache->delete("attempt_questions_{$attemptId}");
            $cache->delete("attempt_answers_{$attemptId}");
        } catch (\Exception $e) {
            log_message('error', 'Cache error on delete attempt: ' . $e->getMessage());
        }

        // Publish a kick event so the student is kicked instantly if they are currently taking the exam
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $kickPayload = json_encode([
                    'event' => 'kick',
                    'user_id' => $attempt->user_id,
                    'message' => 'Ujian Anda telah di-reset oleh pengawas/admin. Silakan mulai ulang.'
                ]);
                $redis->publish('exam_events', $kickPayload);
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis publish error on delete attempt: ' . $e->getMessage());
        }

        // Delete attempt
        $db->table('test_attempts')->where('id', $attemptId)->delete();

        $db->transComplete();

        if ($db->transStatus() === false) {
            if ($this->request->isAJAX()) {
                return $this->response->setJSON(['status' => 'error', 'message' => 'Gagal menghapus hasil ujian.']);
            }
            return redirect()->back()->with('error', 'Gagal menghapus hasil ujian.');
        }

        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['status' => 'success', 'message' => 'Hasil ujian berhasil dihapus.']);
        }
        return redirect()->back()->with('success', 'Hasil ujian berhasil dihapus.');
    }

    /**
     * Titik masuk koreksi cepat: arahkan ke soal manual pertama yang masih
     * ada nilai kosong. Kalau semua sudah terkoreksi, tetap masuk ke soal
     * pertama — guru bisa menyesuaikan nilai lama dari layar yang sama.
     */
    public function gradeRedirect(int $testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        $db   = \Config\Database::connect();
        $base = "
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE ta.test_id = ? AND ta.status = 3 AND q.type = 3 AND q.answer_mode = 'manual'
        ";

        $row = $db->query("
            SELECT tl.question_id
            {$base}
            GROUP BY tl.question_id
            HAVING SUM(tl.score IS NULL) > 0
            ORDER BY MIN(tl.display_order) ASC
            LIMIT 1
        ", [$testId])->getRow();

        if (!$row) {
            $row = $db->query("
                SELECT tl.question_id
                {$base}
                GROUP BY tl.question_id
                ORDER BY MIN(tl.display_order) ASC
                LIMIT 1
            ", [$testId])->getRow();
        }

        if (!$row) {
            return redirect()->to('/admin/results/view/' . $testId)
                ->with('error', 'Belum ada jawaban soal esai untuk dikoreksi pada ujian ini.');
        }

        return redirect()->to('/admin/results/grade/' . $testId . '/' . $row->question_id);
    }

    /**
     * Shell layar koreksi cepat; datanya diambil gradeData() via AJAX.
     */
    public function grade(int $testId, int $questionId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        $question = (new \App\Models\QuestionModel())->find($questionId);
        if (!$question || (int) $question->type !== 3 || $question->answer_mode !== 'manual') {
            return redirect()->to('/admin/results/view/' . $testId)
                ->with('error', 'Soal ini tidak dapat dinilai lewat koreksi cepat.');
        }

        return view('admin/results/grade', ['test' => $test, 'questionId' => (int) $questionId]);
    }

    /**
     * JSON roster: daftar siswa (jawaban + nilai saat ini) untuk satu soal.
     */
    public function gradeData(int $testId, int $questionId)
    {
        $test     = $this->testModel->find($testId);
        $question = (new \App\Models\QuestionModel())->find($questionId);
        if (!$test || !$question || (int) $question->type !== 3 || $question->answer_mode !== 'manual') {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Data koreksi tidak ditemukan.']);
        }

        $db = \Config\Database::connect();

        // Roster: attempt selesai yang punya baris log untuk soal ini.
        // Attempt tanpa log (soal ditambah setelah dia selesai) dikeluarkan —
        // tidak ada tempat menaruh nilainya, dan counter tetap jujur.
        $rows = $db->query("
            SELECT tl.id AS log_id, tl.answer_text, tl.score,
                   u.firstname, u.lastname, u.registration_number
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN users u ON u.id = ta.user_id
            WHERE ta.test_id = ? AND ta.status = 3 AND tl.question_id = ?
            ORDER BY u.firstname ASC, u.lastname ASC
        ", [$testId, $questionId])->getResult();

        // Kunci referensi per log (snapshot ikut attempt).
        $keys   = [];
        $logIds = array_column($rows, 'log_id');
        if (!empty($logIds)) {
            $keyRows = $db->query("
                SELECT tla.test_log_id, tla.answer_text
                FROM test_log_answers tla
                WHERE tla.test_log_id IN ? AND tla.is_correct = 1
                ORDER BY tla.display_order ASC
            ", [$logIds])->getResult();
            foreach ($keyRows as $k) {
                $keys[$k->test_log_id] ??= $k->answer_text;
            }
        }

        $students = [];
        $graded   = 0;
        foreach ($rows as $r) {
            if ($r->score !== null) $graded++;
            $students[] = [
                'log_id' => (int) $r->log_id,
                'name'   => trim($r->firstname . ' ' . $r->lastname),
                'nis'    => (string) $r->registration_number,
                'answer' => (string) ($r->answer_text ?? ''),
                'key'    => (string) ($keys[$r->log_id] ?? ''),
                'score'  => $r->score === null ? null : (float) $r->score,
            ];
        }

        // Dropdown pemilih soal: semua soal manual ujian ini + counter pending.
        $qRows = $db->query("
            SELECT q.id, q.description, COUNT(*) AS total, SUM(tl.score IS NULL) AS pending
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE ta.test_id = ? AND ta.status = 3 AND q.type = 3 AND q.answer_mode = 'manual'
            GROUP BY q.id, q.description
            ORDER BY MIN(tl.display_order) ASC
        ", [$testId])->getResult();

        $questions = [];
        foreach ($qRows as $qr) {
            $label = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $qr->description)));
            $questions[] = [
                'id'      => (int) $qr->id,
                'label'   => mb_substr($label, 0, 80),
                'pending' => (int) $qr->pending,
                'total'   => (int) $qr->total,
            ];
        }

        return $this->response->setJSON([
            'status'    => 'success',
            'question'  => [
                'id'         => (int) $questionId,
                'max_points' => (float) $test->score_right,
            ],
            'counts'    => ['total' => count($students), 'graded' => $graded],
            'students'  => $students,
            'questions' => $questions,
        ]);
    }

    /**
     * Simpan satu nilai dari layar koreksi cepat. score kosong berarti NULL
     * ("belum dikoreksi") — dipakai tombol undo untuk mengembalikan kondisi.
     */
    public function gradeSave()
    {
        $logId = (int) $this->request->getPost('log_id');
        $raw   = $this->request->getPost('score');
        $score = ($raw === '' || $raw === null) ? null : (float) $raw;

        $db  = \Config\Database::connect();
        $log = $db->query("
            SELECT tl.id, tl.question_id, tl.test_attempt_id, ta.test_id, q.type, q.answer_mode
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE tl.id = ?
        ", [$logId])->getRow();

        if (!$log || (int) $log->type !== 3 || $log->answer_mode !== 'manual') {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Soal tidak dapat dinilai lewat koreksi cepat.']);
        }

        if ($score !== null && $score < 0) {
            $score = 0;
        }

        $this->testLogModel->update($logId, ['score' => $score]);
        $attemptScore = $this->recalcAttemptScore((int) $log->test_attempt_id);

        $remaining = (int) ($db->query("
            SELECT COUNT(*) AS c
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            WHERE ta.test_id = ? AND ta.status = 3 AND tl.question_id = ? AND tl.score IS NULL
        ", [$log->test_id, $log->question_id])->getRow()->c ?? 0);

        return $this->response->setJSON([
            'status'        => 'success',
            'log_id'        => $logId,
            'attempt_score' => $attemptScore,
            'remaining'     => $remaining,
        ]);
    }
}

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

        return view('admin/results/view', [
            'test' => $test,
            'attempts' => $attempts
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

        // Update the log score
        $this->testLogModel->update($logId, ['score' => $score]);

        // Recalculate total score for the attempt
        $attemptId = $log->test_attempt_id;
        $db = \Config\Database::connect();
        
        $sql = "SELECT SUM(score) as total FROM test_logs WHERE test_attempt_id = ?";
        $result = $db->query($sql, [$attemptId])->getRow();
        
        $rawScore = $result->total ?? 0;

        // Apply scale (Max Score)
        $attempt = $this->attemptModel->find($attemptId);
        $test = $this->testModel->find($attempt->test_id);
        
        // Hanya soal yang sudah dinilai yang ikut jadi pembagi. Esai yang
        // menunggu koreksi bernilai NULL; kalau ikut dihitung, nilai siswa
        // tertekan turun hanya karena gurunya belum sempat mengoreksi.
        $sqlMax = "SELECT COUNT(*) as num_questions FROM test_logs
                   WHERE test_attempt_id = ? AND score IS NOT NULL";
        $resultMax = $db->query($sqlMax, [$attemptId])->getRow();
        $numQuestions = $resultMax->num_questions ?? 0;

        $maxPossiblePoints = $numQuestions * $test->score_right;
        
        $finalScore = 0;
        if ($maxPossiblePoints > 0) {
            $finalScore = ($rawScore / $maxPossiblePoints) * $test->max_score;
        }

        if ($finalScore < 0) $finalScore = 0;

        $this->attemptModel->update($attemptId, ['score' => round($finalScore, 3)]);

        return redirect()->back()->with('success', 'Nilai soal berhasil diperbarui dan skor akhir telah dikalkulasi ulang.');
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
}

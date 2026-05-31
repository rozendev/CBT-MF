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
            SELECT tl.*, q.description as question_text, q.type as question_type, q.difficulty
            FROM test_logs tl
            JOIN questions q ON q.id = tl.question_id
            WHERE tl.test_attempt_id = ?
            ORDER BY tl.display_order ASC
        ";
        $logs = $db->query($sql, [$attemptId])->getResult();

        $answers = [];
        $logIds = array_column($logs, 'id');
        
        if (!empty($logIds)) {
            $ansSql = "
                SELECT tla.*, a.description as answer_text, a.is_correct
                FROM test_log_answers tla
                JOIN answers a ON a.id = tla.answer_id
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
     * Manually grade an essay question and recalculate total score
     */
    public function gradeEssay()
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
        
        // Ensure max theoretical score is fetched from the ScoringEngine logic
        // But since this is a manual override, we'll use a simplified proportion
        // Actually, to be accurate, we should ideally reuse ScoringEngine logic.
        // For now, we'll just re-fetch all questions and max scores
        
        $scorer = new \App\Libraries\ScoringEngine();
        $scorer->calculateAndSaveScore($attemptId);

        return redirect()->back()->with('success', 'Nilai esai berhasil disimpan dan skor akhir telah dikalkulasi ulang.');
    }
}

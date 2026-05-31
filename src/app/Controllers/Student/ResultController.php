<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;

class ResultController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
    }

    public function view($testId)
    {
        $userId = session('user_id');
        $test = $this->testModel->find($testId);
        
        if (!$test || $test->results_visible == 0) {
            return redirect()->to('/student/dashboard')->with('error', 'Nilai untuk ujian ini tidak dipublikasikan.');
        }

        $attempt = $this->attemptModel->where('test_id', $testId)
                                      ->where('user_id', $userId)
                                      ->where('status', 3)
                                      ->orderBy('id', 'DESC')
                                      ->first();
                                      
        if (!$attempt) {
            return redirect()->to('/student/dashboard')->with('error', 'Anda belum menyelesaikan ujian ini.');
        }

        $logs = [];
        $answers = [];
        
        if ($test->report_visible == 1) {
            $db = \Config\Database::connect();
            $sql = "
                SELECT tl.*, q.description as question_text, q.type as question_type, q.difficulty
                FROM test_logs tl
                JOIN questions q ON q.id = tl.question_id
                WHERE tl.test_attempt_id = ?
                ORDER BY tl.display_order ASC
            ";
            $logs = $db->query($sql, [$attempt->id])->getResult();

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
        }

        return view('student/exam/result', [
            'test' => $test,
            'attempt' => $attempt,
            'logs' => $logs,
            'answers' => $answers
        ]);
    }
}

<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\TestSubjectSetModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Models\TestLogModel;
use App\Models\TestLogAnswerModel;
use App\Models\ActivityLogModel;

class ExamController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
        $this->activityLog = new ActivityLogModel();
    }

    /**
     * Show Preparation Screen (T&C, Start Button)
     */
    public function prepare($id)
    {
        $test = $this->testModel->findCached($id);
        if (!$test || !$test->is_enabled) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak tersedia.');
        }

        // Check if user has an active attempt
        $activeAttempt = $this->attemptModel->getActiveAttemptCached($id, session('user_id'));
        if ($activeAttempt) {
            if ($test->exam_mode == 'static' && !empty($test->static_page_path)) {
                return redirect()->to(base_url($test->static_page_path));
            }
            return redirect()->to('/student/exam/take/' . $id)->with('info', 'Anda memiliki ujian yang sedang berlangsung.');
        }

        // Check time boundaries
        $now = date('Y-m-d H:i:s');
        if ($test->begin_time && $now < $test->begin_time) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian ini belum dimulai.');
        }
        if ($test->end_time && $now > $test->end_time) {
            return redirect()->to('/student/dashboard')->with('error', 'Waktu ujian ini telah berakhir.');
        }

        return view('student/exam/prepare', ['test' => $test]);
    }

    /**
     * Generate the exam for the student
     */
    public function start($id)
    {
        $test = $this->testModel->findCached($id);
        if (!$test || !$test->is_enabled) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak valid.');
        }

        $userId = session('user_id');

        // Verify password if any
        $password = $this->request->getPost('password');
        if (!empty($test->password) && !hash_equals($test->password, (string)$password)) {
            return redirect()->back()->with('error', 'Password ujian salah.');
        }

        // Check for active attempt again
        $activeAttempt = $this->attemptModel->getActiveAttemptCached($id, $userId);
        if ($activeAttempt) {
            return redirect()->to('/student/exam/take/' . $id);
        }

        $examService = new \App\Libraries\ExamService();
        $attempt = $examService->generateAttempt((int)$id, (int)$userId, $this->request->getIPAddress());

        if (!$attempt) {
            return redirect()->back()->with('error', 'Terjadi kesalahan saat men-generate soal ujian.');
        }

        if ($test->exam_mode === 'static' && !empty($test->static_page_path)) {
            return redirect()->to(base_url($test->static_page_path));
        }
        return redirect()->to('/student/exam/take/' . $id);
    }

    /**
     * Exam Taking Interface
     */
    public function take($id)
    {
        $userId = session('user_id');
        $attempt = $this->attemptModel->getActiveAttemptCached($id, $userId);
        
        if (!$attempt) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian telah selesai atau belum dimulai.');
        }

        $test = $this->testModel->findCached($id);

        if ($test->exam_mode == 'static' && !empty($test->static_page_path)) {
            return redirect()->to(base_url($test->static_page_path));
        }

        // Fetch questions generated for this attempt
        $db = \Config\Database::connect();
        $cache = service('cache');
        
        $questionsKey = "attempt_questions_{$attempt->id}";
        $questions = $cache->get($questionsKey);
        if ($questions === null) {
            $sql = "
                SELECT tl.*, tl.id as log_id
                FROM test_logs tl
                WHERE tl.test_attempt_id = ?
                ORDER BY tl.display_order ASC
            ";
            $questions = $db->query($sql, [$attempt->id])->getResult();
            try {
                $cache->save($questionsKey, $questions, 7200); // 2 hours
            } catch (\Exception $e) {}
        }

        // Fetch answers for all these questions
        $logIds = array_column($questions, 'log_id');
        $answers = [];
        if (!empty($logIds)) {
            $answersKey = "attempt_answers_{$attempt->id}";
            $answers = $cache->get($answersKey);
            if ($answers === null) {
                $ansSql = "
                    SELECT tla.*, tla.id as log_answer_id
                    FROM test_log_answers tla
                    WHERE tla.test_log_id IN ?
                    ORDER BY tla.display_order ASC
                ";
                $rawAnswers = $db->query($ansSql, [$logIds])->getResult();
                
                $answers = [];
                // Group answers by test_log_id
                foreach ($rawAnswers as $ans) {
                    $answers[$ans->test_log_id][] = $ans;
                }
                try {
                    $cache->save($answersKey, $answers, 7200); // 2 hours
                } catch (\Exception $e) {}
            }
        }

        // Merge Redis answers if any
        $redis = \App\Libraries\RedisClient::getInstance();
        try {
            if ($redis) {
                $redisKey = "exam_answers:{$attempt->id}";
                $redisAnswers = $redis->hGetAll($redisKey);
                
                if (!empty($redisAnswers)) {
                    foreach ($questions as &$q) {
                        if (isset($redisAnswers[$q->log_id])) {
                            $saved = json_decode($redisAnswers[$q->log_id], true);
                            
                            if ($q->question_type == 3) {
                                // Essay override
                                $q->answer_text = $saved['answer_text'] ?? '';
                            } elseif ($q->question_type == 4 || $q->question_type == 5) {
                                // Matching override
                                $q->answer_text = json_encode($saved['matching_answers'] ?? []);
                            } else {
                                // Multiple Choice override
                                $selectedIds = $saved['selected_answers'] ?? [];
                                if (isset($answers[$q->log_id])) {
                                    foreach ($answers[$q->log_id] as &$ans) {
                                        $ans->is_selected = in_array($ans->answer_id, $selectedIds) ? 1 : 0;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // If Redis fails, gracefully fall back to DB only
        }

        $settingModel = new \App\Models\SettingModel();
        $isAntiCheatEnabled = $settingModel->getValue('anti_cheat_enabled', false);

        return view('student/exam/take', [
            'test' => $test,
            'attempt' => $attempt,
            'questions' => $questions,
            'answers' => $answers,
            'isAntiCheatEnabled' => $isAntiCheatEnabled
        ]);
    }


    /**
     * Save an answer via AJAX
     */
    public function saveAnswer()
    {
        if (!$this->request->isAJAX()) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Bad request']);
        }

        $logId = $this->request->getPost('log_id');
        $questionType = $this->request->getPost('question_type');
        
        $testLogModel = new TestLogModel();
        $log = $testLogModel->findCached($logId);
        if (!$log) return $this->response->setJSON(['status' => 'error']);

        $attemptModel = new \App\Models\TestAttemptModel();
        $attempt = $attemptModel->findCached($log->test_attempt_id);
        if (!$attempt || $attempt->user_id !== session('user_id')) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Unauthorized']);
        }

        // Check if student was kicked/banned/finished
        if ($attempt->status == 3) {
            return $this->response->setJSON(['status' => 'kicked', 'message' => 'Ujian Anda telah diselesaikan (waktu habis/dikumpulkan).']);
        }
        if ($attempt->status == 4) {
            return $this->response->setJSON(['status' => 'kicked', 'message' => 'Ujian Anda telah dikunci karena melanggar aturan.']);
        }

        // Release session lock early to prevent Redis bottleneck and CSRF token loss on concurrent AJAX
        session_write_close();

        $payload = [];
        if ($questionType == 3) {
            $answerText = $this->request->getPost('answer_text') ?? '';
            $sanitized = strip_tags($answerText);
            $payload['answer_text'] = mb_substr($sanitized, 0, 5000);
        } elseif ($questionType == 4 || $questionType == 5) {
            $payload['matching_answers'] = json_decode($this->request->getPost('matching_answers_json') ?? '{}', true) ?: [];
        } else {
            $selectedIds = $this->request->getPost('selected_answers') ?? [];
            if (!is_array($selectedIds)) {
                $selectedIds = [$selectedIds];
            }
            $payload['selected_answers'] = $selectedIds;
        }

        // Save payload to Redis (Write-Behind Cache)
        $attemptId = $log->test_attempt_id;
        $redisKey = "exam_answers:{$attemptId}";
        
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->hSet($redisKey, $logId, json_encode($payload));
                // Set expiry of the entire hash to 24 hours just in case of orphaned attempts
                $redis->expire($redisKey, 86400); 
            } else {
                return $this->response->setJSON(['status' => 'error', 'message' => 'Redis connection failed']);
            }
        } catch (\Exception $e) {
            return $this->response->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }

        // Update answered_at timestamp directly on DB for simple tracking
        // (This is lightweight enough, but if extreme concurrency is needed, this can be moved to Redis too)
        $testLogModel->update($logId, ['answered_at' => date('Y-m-d H:i:s')]);

        return $this->response->setJSON(['status' => 'success']);
    }

    /**
     * Finish and Submit Exam
     */
    public function finish($id)
    {
        $userId = session('user_id');
        $attempt = $this->attemptModel->getActiveAttemptCached($id, $userId);
        
        if ($attempt) {
            $this->flushRedisAnswersToDb($attempt->id);

            $scorer = new \App\Libraries\ScoringEngine();
            $scorer->calculateAndSaveScore($attempt->id);
            
            $this->activityLog->log('finish_test', $userId, 'test', $id, "Menyelesaikan ujian");
        }

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'status' => 'success',
                'redirect' => site_url('/student/results/view/' . $id)
            ]);
        }

        return redirect()->to('/student/results/view/' . $id);
    }

    public function reportCheat()
    {
        $userId = session('user_id');
        $attemptId = $this->request->getPost('attempt_id') ?? $this->request->getGet('attempt_id');
        $cheatType = $this->request->getPost('type') ?? 'unknown';

        $examService = new \App\Libraries\ExamService();
        $result = $examService->handleCheat((int)$attemptId, (int)$userId, $cheatType);

        if (isset($result['action']) && $result['action'] === 'lock') {
            session()->destroy();
        }

        return $this->response->setJSON($result);
    }


    /**
     * Helper to automatically flush Redis cached answers to MySQL every 1 minute
     */
    public function autoSync()
    {
        $attemptId = $this->request->getPost('attempt_id');
        $userId = session('user_id');

        // Release session lock early
        session_write_close();

        $attempt = $this->attemptModel->findCached($attemptId);
        if (!$attempt || (string)$attempt->user_id !== (string)$userId) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Invalid attempt.']);
        }

        // Flush redis cache 
        $this->flushRedisAnswersToDb($attemptId);

        return $this->response->setJSON(['status' => 'success']);
    }

    /**
     * Helper to flush Redis cached answers to MySQL and check score
     */
    public function checkCurrentScore()
    {
        $attemptId = $this->request->getPost('attempt_id');
        $userId = session('user_id');

        // Release session lock early
        session_write_close();

        $attemptModel = new \App\Models\TestAttemptModel();
        $attempt = $attemptModel->findCached($attemptId);
        if (!$attempt || (string)$attempt->user_id !== (string)$userId) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Invalid attempt.']);
        }

        // Flush redis cache first
        $this->flushRedisAnswersToDb($attemptId);

        // Preview score
        $scoringEngine = new \App\Libraries\ScoringEngine();
        $score = $scoringEngine->calculateScorePreview($attemptId);

        return $this->response->setJSON([
            'status' => 'success',
            'score' => $score
        ]);
    }

    private function flushRedisAnswersToDb($attemptId)
    {
        $examService = new \App\Libraries\ExamService();
        $examService->flushRedisAnswersToDb((int)$attemptId);
    }
}

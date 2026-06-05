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
        $test = $this->testModel->find($id);
        if (!$test || !$test->is_enabled) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak tersedia.');
        }

        // Check if user has an active attempt
        $activeAttempt = $this->attemptModel->getActiveAttempt($id, session('user_id'));
        if ($activeAttempt) {
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
        $test = $this->testModel->find($id);
        if (!$test || !$test->is_enabled) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak valid.');
        }

        $userId = session('user_id');

        // Verify password if any
        $password = $this->request->getPost('password');
        if (!empty($test->password) && $test->password !== $password) {
            return redirect()->back()->with('error', 'Password ujian salah.');
        }

        // Check for active attempt again
        $activeAttempt = $this->attemptModel->getActiveAttempt($id, $userId);
        if ($activeAttempt) {
            return redirect()->to('/student/exam/take/' . $id);
        }

        // Optional: Check if repeatable logic here if they already completed it

        // Begin Generation
        $db = \Config\Database::connect();
        $db->transStart();

        // 1. Create Test Attempt
        $attemptData = [
            'test_id' => $id,
            'user_id' => $userId,
            'status' => 1, // active
            'started_at' => date('Y-m-d H:i:s'),
        ];
        $this->attemptModel->insert($attemptData);
        $attemptId = $this->attemptModel->getInsertID();

        // 2. Generate Questions from Subject Sets
        $setBuilder = $db->table('test_subject_sets');
        $sets = $setBuilder->where('test_id', $id)->get()->getResult();

        $questionModel = new QuestionModel();
        $answerModel = new AnswerModel();
        $testLogModel = new TestLogModel();
        $testLogAnswerModel = new TestLogAnswerModel();

        $displayOrder = 1;

        foreach ($sets as $set) {
            // Get Subjects for this set
            $subjects = $db->table('test_subjects')->where('test_subject_set_id', $set->id)->get()->getResultArray();
            $subjectIds = array_column($subjects, 'subject_id');

            if (empty($subjectIds)) continue;

            // Fetch eligible questions
            $qBuilder = $db->table('questions')
                           ->whereIn('subject_id', $subjectIds)
                           ->where('is_enabled', 1);

            if ($set->question_type != 0) {
                $qBuilder->where('type', $set->question_type);
            }
            if ($set->difficulty != 0) {
                $qBuilder->where('difficulty', $set->difficulty);
            }

            // Fetch exactly $set->quantity random questions
            // Order by RAND() can be slow on huge tables, but it's the standard way for this scope
            $questions = $qBuilder->orderBy('RAND()')->limit($set->quantity)->get()->getResult();

            foreach ($questions as $q) {
                // Insert into test_logs
                $testLogModel->insert([
                    'test_attempt_id'     => $attemptId,
                    'question_id'         => $q->id,
                    'question_text'       => $q->description,
                    'question_type'       => $q->type,
                    'question_difficulty' => $q->difficulty,
                    'display_order'       => $displayOrder,
                    'num_answers'         => $set->num_answers ?: 0,
                    'user_ip'             => $this->request->getIPAddress(),
                ]);
                
                $testLogId = $testLogModel->getInsertID();

                // Fetch answers and insert into test_log_answers
                $answers = $answerModel->getAnswersByQuestion($q->id);
                
                // Shuffle answers if test configured to randomize answers
                if ($test->random_answers && in_array($q->type, [1, 2])) {
                    shuffle($answers);
                }

                $ansOrder = 1;
                foreach ($answers as $ans) {
                    $testLogAnswerModel->insert([
                        'test_log_id'   => $testLogId,
                        'answer_id'     => $ans->id,
                        'answer_text'   => $ans->description,
                        'is_correct'    => $ans->is_correct,
                        'display_order' => $ansOrder,
                        'position'      => $ans->position,
                        'is_selected'   => 0
                    ]);
                    $ansOrder++;
                }

                $displayOrder++;
            }
        }

        // If random questions is globally true, we can shuffle the display_order of the generated test_logs
        if ($test->random_questions) {
            $logs = $testLogModel->where('test_attempt_id', $attemptId)->findAll();
            shuffle($logs);
            $newOrder = 1;
            foreach ($logs as $l) {
                $testLogModel->update($l->id, ['display_order' => $newOrder]);
                $newOrder++;
            }
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return redirect()->back()->with('error', 'Terjadi kesalahan saat men-generate soal ujian.');
        }

        $this->activityLog->log('start_test', $userId, 'test', $id, "Memulai ujian: {$test->name}");
        return redirect()->to('/student/exam/take/' . $id);
    }

    /**
     * Exam Taking Interface
     */
    public function take($id)
    {
        $userId = session('user_id');
        $attempt = $this->attemptModel->getActiveAttempt($id, $userId);
        
        if (!$attempt) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian telah selesai atau belum dimulai.');
        }

        $test = $this->testModel->find($id);

        // Fetch questions generated for this attempt
        $db = \Config\Database::connect();
        
        $sql = "
            SELECT tl.*, tl.id as log_id
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
            ORDER BY tl.display_order ASC
        ";
        $questions = $db->query($sql, [$attempt->id])->getResult();

        // Fetch answers for all these questions
        $logIds = array_column($questions, 'log_id');
        $answers = [];
        if (!empty($logIds)) {
            $ansSql = "
                SELECT tla.*, tla.id as log_answer_id
                FROM test_log_answers tla
                WHERE tla.test_log_id IN ?
                ORDER BY tla.display_order ASC
            ";
            $rawAnswers = $db->query($ansSql, [$logIds])->getResult();
            
            // Group answers by test_log_id
            foreach ($rawAnswers as $ans) {
                $answers[$ans->test_log_id][] = $ans;
            }
        }

        // Merge Redis answers if any
        $redis = new \Redis();
        try {
            if ($redis->connect('redis', 6379)) {
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

        return view('student/exam/take', [
            'test' => $test,
            'attempt' => $attempt,
            'questions' => $questions,
            'answers' => $answers
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
        $log = $testLogModel->find($logId);
        if (!$log) return $this->response->setJSON(['status' => 'error']);

        $attemptModel = new \App\Models\TestAttemptModel();
        $attempt = $attemptModel->find($log->test_attempt_id);
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
            $payload['answer_text'] = $this->request->getPost('answer_text');
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
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
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
        $attempt = $this->attemptModel->getActiveAttempt($id, $userId);
        
        if ($attempt) {
            // Flush Redis Answers to Database before scoring
            $this->flushRedisAnswersToDb($attempt->id);

            // Call Scoring Engine
            $scorer = new \App\Libraries\ScoringEngine();
            $scorer->calculateAndSaveScore($attempt->id);
            
            $this->activityLog->log('finish_test', $userId, 'test', $id, "Menyelesaikan ujian");
        }

        return redirect()->to('/student/dashboard')->with('success', 'Ujian berhasil diselesaikan! Skor Anda telah disimpan.');
    }

    public function reportCheat()
    {
        $userId = session('user_id');
        $attemptId = $this->request->getPost('attempt_id') ?? $this->request->getGet('attempt_id');
        $cheatType = $this->request->getPost('type') ?? 'unknown';
        
        $attempt = $this->attemptModel
            ->where('id', $attemptId)
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1, 2])
            ->first();

        if (!$attempt) {
            return $this->response->setJSON(['status' => 'error', 'action' => 'kick', 'message' => 'Sesi ujian tidak valid.']);
        }

        // Load Settings
        $settingModel = new \App\Models\SettingModel();
        $isAntiCheatEnabled = $settingModel->getValue('anti_cheat_enabled', false);
        if (!$isAntiCheatEnabled) {
            return $this->response->setJSON(['status' => 'success', 'action' => 'none']);
        }

        $maxStrikes = (int) $settingModel->getValue('max_cheat_strikes', 2);
        $suspendTimer = (int) $settingModel->getValue('suspend_timer_seconds', 30);
        $forceLogout = (bool) $settingModel->getValue('anti_cheat_force_logout', false);
        $currentStrikes = (int) $attempt->cheat_strikes + 1;

        if ($cheatType === 'tab_switch' || $currentStrikes >= $maxStrikes || $forceLogout) {
            // INSTANT BAN (tab switch OR strike limit reached OR force logout enabled)
            $this->attemptModel->update($attemptId, [
                'cheat_strikes' => $currentStrikes,
                'status' => 2 // Paused instead of Locked, so progress is saved
            ]);
            // Also deactivate user account
            $userModel = new \App\Models\UserModel();
            $userModel->update($userId, ['is_active' => 0]);

            // Delete session from Redis to kick immediately
            try {
                $redis = new \Redis();
                if ($redis->connect('redis', 6379)) {
                    $redis->del("user_session:{$userId}");
                    // Signal for SSE stream — detected within 3 seconds
                    $redis->setex("ban_signal:{$userId}", 120, '1');
                }
            } catch (\Exception $e) {}
            session()->destroy();

            $reason = '';
            if ($forceLogout) {
                $reason = 'melakukan pelanggaran saat ujian (Auto-Lock)';
            } else {
                $reason = $cheatType === 'tab_switch' ? 'mengganti tab' : 'melewati batas maksimal peringatan keluar layar penuh';
            }
            
            $this->activityLog->log('exam_locked', $userId, 'test', $attempt->test_id, 
                "INSTANT BAN: Siswa $reason saat ujian (Strike: $currentStrikes)");
            
            return $this->response->setJSON([
                'status' => 'success',
                'action' => 'lock',
                'message' => "Ujian dikunci karena Anda terdeteksi $reason. Akun Anda dikunci."
            ]);
        } else {
            // FULLSCREEN EXIT = WARNING (suspend sementara)
            $this->attemptModel->update($attemptId, [
                'cheat_strikes' => $currentStrikes
            ]);
            $this->activityLog->log('exam_suspended', $userId, 'test', $attempt->test_id, 
                "WARNING: Siswa keluar dari fullscreen (Strike: $currentStrikes)");

            return $this->response->setJSON([
                'status' => 'success',
                'action' => 'suspend',
                'timer' => $suspendTimer,
                'strike' => $currentStrikes
            ]);
        }
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

        $attempt = $this->attemptModel->where('id', $attemptId)->where('user_id', $userId)->first();
        if (!$attempt) {
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
        $attempt = $attemptModel->where('id', $attemptId)->where('user_id', $userId)->first();
        if (!$attempt) {
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
        try {
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
                $redisKey = "exam_answers:{$attemptId}";
                $answers = $redis->hGetAll($redisKey);
                
                if (empty($answers)) return;

                $db = \Config\Database::connect();
                $db->transStart();

                foreach ($answers as $logId => $payloadJson) {
                    $payload = json_decode($payloadJson, true);
                    
                    if (isset($payload['answer_text'])) {
                        // Essay
                        $db->table('test_logs')
                           ->where('id', $logId)
                           ->update(['answer_text' => $payload['answer_text']]);
                    } elseif (isset($payload['matching_answers'])) {
                        // Matching
                        $db->table('test_logs')
                           ->where('id', $logId)
                           ->update(['answer_text' => json_encode($payload['matching_answers'])]);
                    } elseif (isset($payload['selected_answers'])) {
                        // Multiple choice
                        $selectedIds = $payload['selected_answers'];
                        
                        // Reset all to 0
                        $db->table('test_log_answers')
                           ->where('test_log_id', $logId)
                           ->update(['is_selected' => 0]);
                           
                        // Set selected to 1
                        if (!empty($selectedIds)) {
                            $db->table('test_log_answers')
                               ->where('test_log_id', $logId)
                               ->whereIn('answer_id', $selectedIds)
                               ->update(['is_selected' => 1]);
                        }
                    }
                }

                $db->transComplete();
                
                // Clear the cache once flushed
                if ($db->transStatus() !== false) {
                    $redis->del($redisKey);
                }
            }
        } catch (\Exception $e) {
            // Log error
            log_message('error', 'Redis flush failed: ' . $e->getMessage());
        }
    }
}

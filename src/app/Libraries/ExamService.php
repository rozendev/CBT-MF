<?php

namespace App\Libraries;

use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Models\TestLogModel;
use App\Models\TestLogAnswerModel;
use App\Models\ActivityLogModel;
use Config\Database;

class ExamService
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;
    protected QuestionModel $questionModel;
    protected AnswerModel $answerModel;
    protected TestLogModel $testLogModel;
    protected TestLogAnswerModel $testLogAnswerModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
        $this->questionModel = new QuestionModel();
        $this->answerModel = new AnswerModel();
        $this->testLogModel = new TestLogModel();
        $this->testLogAnswerModel = new TestLogAnswerModel();
        $this->activityLog = new ActivityLogModel();
    }

    /**
     * Generate Exam Attempt for a user.
     * Pessimistic locks prevent race conditions, and questions are shuffled in PHP.
     */
    public function generateAttempt(int $testId, int $userId, string $ipAddress): ?object
    {
        $db = Database::connect();
        $db->transStart();

        // Pessimistic lock: SELECT ... FOR UPDATE prevents concurrent inserts
        $existing = $db->query(
            "SELECT id FROM test_attempts WHERE user_id = ? AND test_id = ? AND status IN (0, 1, 2) LIMIT 1 FOR UPDATE",
            [$userId, $testId]
        )->getRow();

        if ($existing) {
            $db->transRollback();
            return $this->attemptModel->find($existing->id);
        }

        // Fetch test details
        $test = $this->testModel->find($testId);
        if (!$test) {
            $db->transRollback();
            return null;
        }

        // 1. Create Test Attempt
        $attemptData = [
            'test_id'    => $testId,
            'user_id'    => $userId,
            'status'     => 1, // active
            'started_at' => date('Y-m-d H:i:s'),
        ];
        $this->attemptModel->insert($attemptData);
        $attemptId = $this->attemptModel->getInsertID();

        // 2. Generate Questions from Subject Sets
        $sets = $db->table('test_subject_sets')->where('test_id', $testId)->get()->getResult();

        $displayOrder = 1;

        foreach ($sets as $set) {
            $subjects = $db->table('test_subjects')->where('test_subject_set_id', $set->id)->get()->getResultArray();
            $subjectIds = array_column($subjects, 'subject_id');

            if (empty($subjectIds)) {
                continue;
            }

            // Fetch eligible question IDs to shuffle in PHP (eliminates ORDER BY RAND())
            $qBuilder = $db->table('questions')
                           ->select('id')
                           ->whereIn('subject_id', $subjectIds)
                           ->where('is_enabled', 1)
                           ->orderBy('id', 'ASC');

            if ($set->question_type != 0) {
                $qBuilder->where('type', $set->question_type);
            }
            if ($set->difficulty != 0) {
                $qBuilder->where('difficulty', $set->difficulty);
            }

            $questionRows = $qBuilder->get()->getResult();
            $questionIds = array_column($questionRows, 'id');

            if (empty($questionIds)) {
                continue;
            }

            // PHP shuffle (seeded for static, random for dynamic)
            if ($test->exam_mode === 'static') {
                mt_srand($test->id + $set->id);
                shuffle($questionIds);
                mt_srand(); // reset seed
            } else {
                shuffle($questionIds);
            }

            // Slice to get the required quantity
            $selectedIds = array_slice($questionIds, 0, $set->quantity);
            if (empty($selectedIds)) {
                continue;
            }

            // Fetch full rows for the selected IDs
            $questions = $db->table('questions')
                            ->whereIn('id', $selectedIds)
                            ->get()
                            ->getResult();

            // To preserve the shuffled order, sort the fetched questions based on the position in $selectedIds
            $idToIndex = array_flip($selectedIds);
            usort($questions, function ($a, $b) use ($idToIndex) {
                return $idToIndex[$a->id] <=> $idToIndex[$b->id];
            });

            foreach ($questions as $q) {
                // Insert into test_logs
                $this->testLogModel->insert([
                    'test_attempt_id'     => $attemptId,
                    'question_id'         => $q->id,
                    'question_text'       => $q->description,
                    'question_type'       => $q->type,
                    'question_difficulty' => $q->difficulty,
                    'display_order'       => $displayOrder,
                    'num_answers'         => $set->num_answers ?: 0,
                    'user_ip'             => $ipAddress,
                ]);
                
                $testLogId = $this->testLogModel->getInsertID();

                // Fetch answers
                $answers = $this->answerModel->getAnswersByQuestion($q->id);
                
                // Shuffle answers if test configured to randomize answers
                if ($test->random_answers && in_array($q->type, [1, 2])) {
                    if ($test->exam_mode === 'static') {
                        mt_srand($test->id + $q->id);
                        shuffle($answers);
                        mt_srand(); // reset seed
                    } else {
                        shuffle($answers);
                    }
                }

                $ansOrder = 1;
                foreach ($answers as $ans) {
                    $this->testLogAnswerModel->insert([
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

        // If random questions is globally true, we shuffle the display_order of the generated test_logs
        if ($test->random_questions) {
            $logs = $this->testLogModel->where('test_attempt_id', $attemptId)->findAll();
            if ($test->exam_mode === 'static') {
                mt_srand($test->id);
                shuffle($logs);
                mt_srand();
            } else {
                shuffle($logs);
            }
            $newOrder = 1;
            foreach ($logs as $l) {
                $this->testLogModel->update($l->id, ['display_order' => $newOrder]);
                $newOrder++;
            }
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return null;
        }

        $this->activityLog->log('start_test', $userId, 'test', $testId, "Memulai ujian: {$test->name}");
        return $this->attemptModel->find($attemptId);
    }

    /**
     * Groups all updates to test_logs into a single updateBatch() call.
     * Updates multiple choice answers in batches, only deleting the Redis key if the transaction status is successful.
     */
    public function flushRedisAnswersToDb(int $attemptId): bool
    {
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if (!$redis) {
                return false;
            }

            $redisKey = "exam_answers:{$attemptId}";
            $answers = $redis->hGetAll($redisKey);
            if (empty($answers)) {
                return true;
            }

            $db = Database::connect();
            $db->transStart();

            $logsBatch = [];
            $mcLogIds = [];
            $allSelectedAnswerIds = [];

            foreach ($answers as $logId => $payloadJson) {
                $payload = json_decode($payloadJson, true);
                if (!$payload) {
                    continue;
                }

                if (isset($payload['answer_text'])) {
                    // Essay
                    $logsBatch[] = [
                        'id'          => $logId,
                        'answer_text' => $payload['answer_text']
                    ];
                } elseif (isset($payload['matching_answers'])) {
                    // Matching
                    $logsBatch[] = [
                        'id'          => $logId,
                        'answer_text' => json_encode($payload['matching_answers'])
                    ];
                } elseif (isset($payload['selected_answers'])) {
                    // Multiple choice
                    $mcLogIds[] = $logId;
                    $selectedIds = $payload['selected_answers'];
                    if (!empty($selectedIds)) {
                        foreach ($selectedIds as $sId) {
                            $allSelectedAnswerIds[] = $sId;
                        }
                    }
                }
            }

            // Perform batch update for test_logs (essays & matching)
            if (!empty($logsBatch)) {
                $db->table('test_logs')->updateBatch($logsBatch, 'id');
            }

            // Perform optimized updates for test_log_answers (multiple choice)
            if (!empty($mcLogIds)) {
                // Reset all options to 0 in one query
                $db->table('test_log_answers')
                   ->whereIn('test_log_id', $mcLogIds)
                   ->update(['is_selected' => 0]);

                // Set selected to 1 in one query
                if (!empty($allSelectedAnswerIds)) {
                    $db->table('test_log_answers')
                       ->whereIn('test_log_id', $mcLogIds)
                       ->whereIn('answer_id', $allSelectedAnswerIds)
                       ->update(['is_selected' => 1]);
                }
            }

            $db->transComplete();

            if ($db->transStatus() !== false) {
                $redis->del($redisKey);
                try {
                    $cache = \Config\Services::cache();
                    $cache->delete("attempt_questions_{$attemptId}");
                    $cache->delete("attempt_answers_{$attemptId}");
                } catch (\Exception $e) {}
                return true;
            }
            return false;
        } catch (\Exception $e) {
            log_message('error', 'Redis flush failed for attempt ' . $attemptId . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Unified anti-cheat warnings, strikes logging, deactivations, and Redis ban signaling.
     */
    public function handleCheat(int $attemptId, int $userId, string $cheatType): array
    {
        $attempt = $this->attemptModel
            ->where('id', $attemptId)
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1, 2])
            ->first();

        if (!$attempt) {
            return ['status' => 'error', 'action' => 'kick', 'message' => 'Sesi ujian tidak valid.'];
        }

        $test = $this->testModel->find($attempt->test_id);
        $isAutoSubmitOnCheat = $test && (int)($test->auto_submit_on_cheat ?? 0) === 1;

        $settingModel = new \App\Models\SettingModel();
        $isAntiCheatEnabled = $settingModel->getValue('anti_cheat_enabled', false);

        if (!$isAntiCheatEnabled && !$isAutoSubmitOnCheat) {
            return ['status' => 'success', 'action' => 'none', 'current_strikes' => (int)($attempt->cheat_strikes ?? 0)];
        }

        // ─── Auto-Submit on Cheat (per-test feature) ───
        if ($isAutoSubmitOnCheat) {
            // Flush Redis answers to DB first
            $this->flushRedisAnswersToDb($attemptId);
            
            // Calculate and save score (this also sets status = 3 / finished)
            $scorer = new \App\Libraries\ScoringEngine();
            $scored = $scorer->calculateAndSaveScore($attemptId);
            
            $label = match($cheatType) {
                'tab_switch' => 'membuka tab lain',
                'fullscreen_exit' => 'keluar dari layar penuh',
                default => 'pelanggaran saat ujian',
            };

            if (!$scored) {
                // Already scored/finished by another concurrent request
                return [
                    'status' => 'success',
                    'action' => 'auto_submitted',
                    'message' => 'Ujian Anda telah diselesaikan.',
                    'redirect' => base_url('/student/results/view/' . $test->id),
                ];
            }
            
            // Increment cheat_strikes for tracking purposes
            $this->attemptModel->update($attemptId, [
                'cheat_strikes' => (int)($attempt->cheat_strikes ?? 0) + 1,
            ]);

            // Real-time Proctor WebSocket Alert
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->publish('exam_events', json_encode([
                        'event' => 'proctor_alert',
                        'data' => [
                            'user_id' => $userId,
                            'test_id' => (int)$test->id,
                            'event'   => 'auto_submit',
                            'reason'  => $label
                        ]
                    ]));
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error publishing auto_submit proctor alert: ' . $e->getMessage());
            }
            
            $this->activityLog->log('exam_auto_submit', $userId, 'test', $test->id,
                "AUTO-SUBMIT: Siswa $label saat fitur auto-submit aktif");
            
            return [
                'status' => 'success',
                'action' => 'auto_submitted',
                'message' => 'Ujian Anda telah otomatis dikumpulkan dan dinilai karena terdeteksi ' . $label . '.',
                'redirect' => base_url('/student/results/view/' . $test->id),
            ];
        }

        $maxStrikes = (int) $settingModel->getValue('max_cheat_strikes', 2);
        $forceLogout = (bool) $settingModel->getValue('anti_cheat_force_logout', false);

        if ($cheatType === 'ban_report') {
            $request = \Config\Services::request();
            $clientStrikes = (int)($request->getPost('client_strikes') ?? $maxStrikes);
            $violationType = $request->getPost('violation_type') ?? 'unknown';
            return $this->triggerBan($userId, $attemptId, $clientStrikes, $violationType, $forceLogout);
        }

        $currentStrikes = (int)($attempt->cheat_strikes ?? 0);

        if ($currentStrikes >= $maxStrikes || $attempt->status == 2) {
            $currentStrikes++;
            $this->attemptModel->update($attemptId, [
                'cheat_strikes' => $currentStrikes,
            ]);

            $reason = $forceLogout
                ? 'melakukan pelanggaran saat ujian (Auto-Lock)'
                : 'melewati batas maksimal peringatan (' . $currentStrikes . '/' . $maxStrikes . ')';

            $this->activityLog->log('exam_violation', $userId, 'test', $attempt->test_id,
                "Additional violation while banned (Strike: $currentStrikes/$maxStrikes)");

            return [
                'status' => 'success',
                'action' => 'lock',
                'message' => "Ujian dikunci karena Anda terdeteksi $reason.",
                'current_strikes' => $currentStrikes,
                'max_strikes' => $maxStrikes,
            ];
        }

        $currentStrikes++;
        $isBanned = ($currentStrikes >= $maxStrikes) || $forceLogout;

        if ($isBanned) {
            return $this->triggerBan($userId, $attemptId, $currentStrikes, $cheatType, $forceLogout);
        } else {
            $this->attemptModel->update($attemptId, [
                'cheat_strikes' => $currentStrikes
            ]);

            $label = match($cheatType) {
                'tab_switch' => 'membuka tab lain',
                'fullscreen_exit' => 'keluar dari fullscreen',
                'suspend_bypass' => 'melewati hukuman suspend',
                default => 'pelanggaran tidak diketahui',
            };

            $this->activityLog->log('exam_suspended', $userId, 'test', $attempt->test_id,
                "WARNING: Siswa $label (Strike: $currentStrikes/$maxStrikes)");

            $suspendTimer = (int) $settingModel->getValue('suspend_timer_seconds', 30);

            return [
                'status' => 'success',
                'action' => 'suspend',
                'timer' => $suspendTimer,
                'strike' => $currentStrikes,
                'current_strikes' => $currentStrikes,
                'max_strikes' => $maxStrikes,
            ];
        }
    }

    /**
     * Trigger ban mechanism
     */
    private function triggerBan(int $userId, int $attemptId, int $clientStrikes, string $violationType, bool $forceLogout): array
    {
        $db = \Config\Database::connect();
        $userModel = new \App\Models\UserModel();
        $maxStrikes = (int) (new \App\Models\SettingModel())->getValue('max_cheat_strikes', 2);

        $db->transStart();

        $userModel->update($userId, ['is_active' => 0]);
        $this->attemptModel->update($attemptId, [
            'cheat_strikes' => $clientStrikes,
            'status' => 2
        ]);

        $db->transComplete();

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                $redis->setex("ban_signal:{$userId}", 120, '1');

                $reason = $forceLogout
                    ? 'melakukan pelanggaran saat ujian (Auto-Lock)'
                    : 'melewati batas maksimal peringatan (' . $clientStrikes . '/' . $maxStrikes . ')';

                $redis->publish('exam_events', json_encode([
                    'event' => 'ban',
                    'user_id' => $userId,
                    'message' => "Akun Anda telah dikunci karena pelanggaran ($violationType: $clientStrikes strikes). $reason"
                ]));

                // Real-time Proctor WebSocket Alert for System Auto-Ban
                $redis->publish('exam_events', json_encode([
                    'event' => 'proctor_alert',
                    'data' => [
                        'user_id' => $userId,
                        'test_id' => (int)$this->attemptModel->find($attemptId)->test_id,
                        'event'   => 'ban'
                    ]
                ]));

                $currentSessionKey = 'ci_session:' . session_id();
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, 'ci_session:*', 100);
                    if ($keys) {
                        foreach ($keys as $key) {
                            // Do not manually delete the current request's session key!
                            // Doing so will crash CodeIgniter's shutdown handler and orphan the lock.
                            if ($key === $currentSessionKey) {
                                continue;
                            }
                            
                            $data = $redis->get($key);
                            if ($data && (strpos($data, "user_id|i:{$userId};") !== false ||
                                          strpos($data, "user_id|s:" . strlen((string)$userId) . ":\"{$userId}\";") !== false)) {
                                $redis->del($key);
                            }
                        }
                    }
                } while ($iterator > 0);
                
                // Gracefully destroy the session if the banned user is the current user
                if (session('user_id') == $userId) {
                    session()->destroy();
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on ban: ' . $e->getMessage());
        }

        $db->table('ci_sessions')
           ->groupStart()
               ->like('data', "user_id|i:{$userId};")
               ->orLike('data', "user_id|s:" . strlen((string)$userId) . ":\"{$userId}\";")
           ->groupEnd()
           ->delete();

        $this->activityLog->log('exam_locked', $userId, 'test', $this->attemptModel->find($attemptId)->test_id,
            "AUTO-BAN: Client reported $clientStrikes strikes (violation: $violationType)");

        return [
            'status' => 'success',
            'action' => 'lock',
            'message' => 'Ujian dikunci karena pelanggaran berulang.',
            'current_strikes' => $clientStrikes,
            'max_strikes' => $maxStrikes,
        ];
    }
}

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

        // 1. Fetch test details
        $test = $this->testModel->find($testId);
        if (!$test) {
            $db->transRollback();
            return null;
        }

        // 2. Lock and check if an active attempt exists
        $active = $db->query(
            "SELECT id FROM test_attempts WHERE user_id = ? AND test_id = ? AND status IN (0, 1, 2) LIMIT 1 FOR UPDATE",
            [$userId, $testId]
        )->getRow();

        if ($active) {
            $db->transRollback();
            return $this->attemptModel->find($active->id);
        }

        // 3. Lock and check if an existing completed attempt exists
        $existing = $db->query(
            "SELECT id FROM test_attempts WHERE user_id = ? AND test_id = ? AND status IN (3, 4) ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [$userId, $testId]
        )->getRow();

        if ($existing) {
            if (empty($test->is_repeatable)) {
                // If not repeatable and already finished, reject
                $db->transRollback();
                return null;
            }

            // REUSE & RESET the existing attempt record on retake instead of inserting stacked new attempt rows
            $attemptId = (int)$existing->id;

            $db->table('test_attempts')
               ->where('id', $attemptId)
               ->update([
                   'status'        => 1, // reset to active
                   'started_at'    => date('Y-m-d H:i:s'),
                   'finished_at'   => null,
                   'score'         => null,
                   'cheat_strikes' => 0,
               ]);

            // Clean up old test_log_answers & test_logs for this attempt
            $existingLogIds = $db->table('test_logs')->select('id')->where('test_attempt_id', $attemptId)->get()->getResultArray();
            if (!empty($existingLogIds)) {
                $logIdList = array_column($existingLogIds, 'id');
                $db->table('test_log_answers')->whereIn('test_log_id', $logIdList)->delete();
                $db->table('test_logs')->where('test_attempt_id', $attemptId)->delete();
            }

            // Invalidate caches via model helper
            $this->attemptModel->clearCacheForAttempt($attemptId, $testId, $userId);

        } else {
            // No previous attempt exists: Create new Test Attempt
            $lastAttempt = $this->attemptModel->where('test_id', $testId)
                                              ->where('user_id', $userId)
                                              ->selectMax('attempt_number')
                                              ->first();
            
            $attemptNumber = ($lastAttempt && !empty($lastAttempt->attempt_number)) ? (int)$lastAttempt->attempt_number + 1 : 1;

            $attemptData = [
                'test_id'        => $testId,
                'user_id'        => $userId,
                'attempt_number' => $attemptNumber,
                'status'         => 1, // active
                'started_at'     => date('Y-m-d H:i:s'),
            ];
            $this->attemptModel->insert($attemptData);
            $attemptId = $this->attemptModel->getInsertID();
        }

        // 2. Generate Questions from Subject Sets
        $sets = $db->table('test_subject_sets')->where('test_id', $testId)->get()->getResult();

        $displayOrder = 1;

        foreach ($sets as $set) {
            $subjects = $db->table('test_subjects')->where('test_subject_set_id', $set->id)->get()->getResultArray();
            $subjectIds = array_column($subjects, 'subject_id');

            if (empty($subjectIds)) {
                continue;
            }

            // Guard: set yang dibatasi topik hanya boleh menarik dari subject pemilik topik.
            // Mencegah exclusion diam-diam soal dari subject lain yang tidak punya topic ini.
            if (!empty($set->topic_id)) {
                $topicOwner = $db->table('topics')
                                 ->select('subject_id')
                                 ->where('id', $set->topic_id)
                                 ->where('deleted_at', null)
                                 ->get()->getRow();
                if ($topicOwner) {
                    $subjectIds = [(int) $topicOwner->subject_id];
                }
            }

            // Fetch eligible question IDs to shuffle in PHP (eliminates ORDER BY RAND())
            $qBuilder = $db->table('questions')
                           ->select('id')
                           ->whereIn('subject_id', $subjectIds)
                           ->where('is_enabled', 1)
                           ->orderBy('id', 'ASC');

            // Jika set dibatasi ke topik/bab tertentu, ambil hanya dari topik itu
            if (!empty($set->topic_id)) {
                $qBuilder->where('topic_id', $set->topic_id);
            }

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

            // Deterministic question pool selection per test & subject set (always fixed set of questions for the test)
            mt_srand($test->id * 100000 + $set->id);
            shuffle($questionIds);
            mt_srand(); // reset seed

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

            // To preserve the initial order, sort the fetched questions based on the position in $selectedIds
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
                        // Per-attempt seed: each student sees different answer order
                        // while the question pool remains the same.
                        mt_srand($attemptId + $q->id);
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

        // If random questions is enabled, shuffle the display_order of the selected questions for this attempt
        if ($test->random_questions) {
            $logs = $this->testLogModel->where('test_attempt_id', $attemptId)->findAll();
            if ($test->exam_mode === 'static') {
                // Per-attempt seed: each student sees different question order.
                mt_srand($attemptId);
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

            // C-1 Fix: Acquire row lock to prevent concurrent flushes from corrupting data
            $db->query("SELECT id FROM test_attempts WHERE id = ? FOR UPDATE", [$attemptId]);

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
                // Use a Lua script to conditionally delete hash fields only if their value hasn't changed.
                // This prevents deleting a newer answer that was saved while we were writing to the DB.
                $luaScript = <<<LUA
local deleted = 0
for i=1, #ARGV, 2 do
    local current = redis.call("HGET", KEYS[1], ARGV[i])
    if current == ARGV[i+1] then
        redis.call("HDEL", KEYS[1], ARGV[i])
        deleted = deleted + 1
    end
end
return deleted
LUA;
                $evalArgs = [$redisKey];
                foreach ($answers as $k => $v) {
                    $evalArgs[] = (string)$k;
                    $evalArgs[] = (string)$v;
                }
                $redis->eval($luaScript, $evalArgs, 1);

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

        // ─── ADMIN BYPASS ───
        $userModel = new \App\Models\UserModel();
        $user = $userModel->find($userId);
        if ($user && $user->role === 'admin') {
            return ['status' => 'success', 'action' => 'none', 'current_strikes' => (int)($attempt->cheat_strikes ?? 0)];
        }

        // ─── Early Violation Bypass Detection ───
        // Jika pelanggaran terjadi di 30 detik pertama, ini adalah anomali "race condition"
        // yang disebabkan oleh aplikasi floating window/split screen saat memaksa masuk fullscreen.
        $forcedDetail = null;
        if (in_array($cheatType, ['tab_switch', 'fullscreen_exit'])) {
            if (!empty($attempt->started_at)) {
                $startTime = strtotime($attempt->started_at);
                $elapsedSeconds = time() - $startTime;
                
                if ($elapsedSeconds >= 0 && $elapsedSeconds <= 30) {
                    $cheatType = 'modified_browser';
                    $forcedDetail = 'early_violation_bypass_in_' . $elapsedSeconds . 's';
                }
            }
        }

        // ─── Modified Browser Detection (immediate ban, bypasses strikes) ───
        if ($cheatType === 'modified_browser') {
            $request = \Config\Services::request();
            $detail = $forcedDetail ?? ($request->getPost('detail') ?? 'unknown');


            // Flush answers before banning
            $this->flushRedisAnswersToDb($attemptId);

            // Calculate and save score
            $scorer = new \App\Libraries\ScoringEngine();
            $scorer->calculateAndSaveScore($attemptId);

            // Deactivate user account
            $userModel = new \App\Models\UserModel();
            $userModel->update($userId, ['is_active' => 0]);

            // Lock the attempt
            $this->attemptModel->update($attemptId, [
                'status' => 2,
                'cheat_strikes' => 999, // Flag as modified browser
            ]);

            // Redis ban signals
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                    $redis->setex("ban_signal:{$userId}", 120, '1');

                    $redis->publish('exam_events', json_encode([
                        'event' => 'ban',
                        'user_id' => $userId,
                        'message' => "Akun dikunci: terdeteksi menggunakan browser modifikasi ($detail)"
                    ]));

                    $redis->publish('exam_events', json_encode([
                        'event' => 'proctor_alert',
                        'data' => [
                            'user_id' => $userId,
                            'test_id' => (int)$attempt->test_id,
                            'event'   => 'modified_browser',
                            'detail'  => $detail,
                        ]
                    ]));

                    // Destroy all sessions for this user
                    $currentSessionKey = 'ci_session:' . session_id();
                    $iterator = null;
                    do {
                        $keys = $redis->scan($iterator, 'ci_session:*', 100);
                        if ($keys === false) break;
                        if ($keys) {
                            foreach ($keys as $key) {
                                if ($key === $currentSessionKey) continue;
                                $data = $redis->get($key);
                                if ($data && (strpos($data, "user_id|i:{$userId};") !== false ||
                                              strpos($data, "user_id|s:" . strlen((string)$userId) . ":\"{$userId}\";") !== false)) {
                                    $redis->del($key);
                                }
                            }
                        }
                    } while ($iterator > 0);

                    if (session('user_id') == $userId) {
                        session()->destroy();
                    }
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error on modified_browser ban: ' . $e->getMessage());
            }

            $this->activityLog->log('modified_browser_ban', $userId, 'test', $attempt->test_id,
                "AUTO-BAN: Browser modifikasi terdeteksi (detail: $detail)");

            return [
                'status'  => 'success',
                'action'  => 'lock',
                'message' => 'Akun Anda telah dikunci karena terdeteksi menggunakan browser modifikasi/tidak resmi.',
                'redirect' => base_url('/login'),
                'current_strikes' => 999,
                'max_strikes' => 0,
            ];
        }

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

        $currentStrikes = (int)($attempt->cheat_strikes ?? 0);

        if ($attempt->status == 2) {
            $currentStrikes++;
            $this->attemptModel->update($attemptId, [
                'cheat_strikes' => $currentStrikes,
            ]);

            $reason = 'melakukan pelanggaran tambahan saat ujian sudah dikunci';

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
    private function triggerBan(int $userId, int $attemptId, int $serverStrikes, string $violationType, bool $forceLogout): array
    {
        $db = \Config\Database::connect();
        $userModel = new \App\Models\UserModel();
        $maxStrikes = (int) (new \App\Models\SettingModel())->getValue('max_cheat_strikes', 2);
        
        $attempt = $this->attemptModel->find($attemptId);
        $testId = $attempt ? (int)$attempt->test_id : 0;

        $db->transStart();

        $userModel->update($userId, ['is_active' => 0]);
        $this->attemptModel->update($attemptId, [
            'cheat_strikes' => $serverStrikes,
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
                    : 'melewati batas maksimal peringatan (' . $serverStrikes . '/' . $maxStrikes . ')';

                $redis->publish('exam_events', json_encode([
                    'event' => 'ban',
                    'user_id' => $userId,
                    'message' => "Akun Anda telah dikunci karena pelanggaran ($violationType: $serverStrikes strikes). $reason"
                ]));

                // Real-time Proctor WebSocket Alert for System Auto-Ban
                $redis->publish('exam_events', json_encode([
                    'event' => 'proctor_alert',
                    'data' => [
                        'user_id' => $userId,
                        'test_id' => $testId,
                        'event'   => 'ban'
                    ]
                ]));

                $currentSessionKey = 'ci_session:' . session_id();
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, 'ci_session:*', 100);
                    if ($keys === false) break;
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

        $this->activityLog->log('exam_locked', $userId, 'test', $testId,
            "AUTO-BAN: System reported $serverStrikes strikes (violation: $violationType)");

        return [
            'status' => 'success',
            'action' => 'lock',
            'message' => 'Ujian dikunci karena pelanggaran berulang.',
            'current_strikes' => $serverStrikes,
            'max_strikes' => $maxStrikes,
        ];
    }
}

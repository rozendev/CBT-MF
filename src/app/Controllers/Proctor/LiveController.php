<?php

namespace App\Controllers\Proctor;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\SettingModel;

class LiveController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;
    protected SettingModel $settingModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
        $this->settingModel = new SettingModel();
    }

    public function monitor($testId)
    {
        $session = session();
        
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/proctor')->with('error', 'Ujian tidak ditemukan.');
        }

        // Get all attempts for this test, joined with user info
        $db = \Config\Database::connect();
        $attempts = $db->table('test_attempts')
                       ->select('test_attempts.id as attempt_id, test_attempts.user_id, test_attempts.status, test_attempts.cheat_strikes, users.firstname, users.lastname, users.username, users.last_active_at')
                       ->join('users', 'users.id = test_attempts.user_id')
                       ->where('test_attempts.test_id', $testId)
                       ->get()
                       ->getResultArray(); // Use getResultArray for easier manipulation

        // Hydrate online status from Redis
        $redis = \App\Libraries\RedisClient::getInstance();
        if ($redis) {
            $now = time();
            $activeUsers = $redis->zRangeByScore('active_sessions', $now - 300, '+inf');
            $activeUserIds = array_map('intval', $activeUsers);
            
            foreach ($attempts as &$attempt) {
                // Determine if user is online based on Redis session presence
                $attempt['is_online'] = in_array((int)$attempt['user_id'], $activeUserIds, true);
            }
        } else {
            // Fallback if Redis is down
            foreach ($attempts as &$attempt) {
                $attempt['is_online'] = false;
            }
        }

        // Generate Secure WebSocket Token for Proctor
        $proctorToken = bin2hex(random_bytes(16));
        if ($redis) {
            $redis->setex("ws_proctor_token:{$proctorToken}", 14400, json_encode([
                'user_id' => $session->get('user_id'),
                'test_id' => (int) $testId
            ]));
        }

        $wsUrl = $this->settingModel->getValue('websocket_url', 'ws://localhost:8060');

        $data = [
            'title'        => 'Live Proctoring: ' . $test->name,
            'test'         => $test,
            'attempts'     => $attempts,
            'wsUrl'        => $wsUrl,
            'proctorToken' => $proctorToken,
            'userRole'     => $session->get('role')
        ];

        return view('proctor/live', $data);
    }

    public function lockAttempt()
    {
        $userId = $this->request->getPost('user_id');
        $testId = $this->request->getPost('test_id');

        if (!$userId || !$testId) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Data tidak lengkap']);
        }

        // Lock attempt
        $attempt = $this->attemptModel->where('user_id', $userId)->where('test_id', $testId)->first();
        if ($attempt) {
            $db = \Config\Database::connect();
            $db->transStart();

            $userModel = new \App\Models\UserModel();
            $userModel->update($userId, ['is_active' => 0]);

            $this->attemptModel->update($attempt->id, [
                'status' => 2, // 2 = locked
                'cheat_strikes' => $attempt->cheat_strikes + 1
            ]);

            $db->transComplete();

            // Publish ban event and invalidate sessions exactly like Admin's Ban
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                    $redis->setex("ban_signal:{$userId}", 120, '1');

                    // 1. Tell proctors about it
                    $redis->publish('exam_events', json_encode([
                        'event' => 'proctor_alert',
                        'data' => [
                            'user_id' => $userId,
                            'test_id' => $testId,
                            'event'   => 'ban'
                        ]
                    ]));

                    // 2. Tell the student and kick them
                    $redis->publish('exam_events', json_encode([
                        'event' => 'ban',
                        'user_id' => $userId,
                        'message' => 'Akun Anda telah ditangguhkan/diblokir oleh pengawas. Hubungi pengawas ujian.'
                    ]));

                    // 3. Clear PHP sessions in Redis
                    $currentSessionKey = 'ci_session:' . session_id();
                    $iterator = null;
                    do {
                        $keys = $redis->scan($iterator, 'ci_session:*', 100);
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
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error on lockAttempt: ' . $e->getMessage());
            }

            // Clear DB sessions as well
            $db->table('ci_sessions')
               ->groupStart()
                   ->like('data', "user_id|i:{$userId};")
                   ->orLike('data', "user_id|s:" . strlen((string)$userId) . ":\"{$userId}\";")
               ->groupEnd()
               ->delete();

            return $this->response->setJSON(['status' => 'success']);
        }

        return $this->response->setJSON(['status' => 'error', 'message' => 'Ujian tidak ditemukan']);
    }
}

<?php

namespace App\Controllers\Proctor;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\SettingModel;
use App\Models\ActivityLogModel;

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

    public function reportStudent()
    {
        $userId = $this->request->getPost('user_id');
        $testId = $this->request->getPost('test_id');
        $action = $this->request->getPost('action');
        $reason = $this->request->getPost('reason');

        if (!$userId || !$testId || !$action || !$reason) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Data pelaporan tidak lengkap']);
        }

        // Log it to ActivityLog for persistence
        $activityLog = new ActivityLogModel();
        $activityLog->log(
            'proctor_report',
            session('user_id'), // The proctor's user_id
            'test_attempt',
            (int)$testId,
            json_encode([
                'student_id' => $userId,
                'proctor_name' => session('username'),
                'suggested_action' => $action,
                'reason' => $reason
            ])
        );

        // Publish to Redis so Admins viewing the Live Dashboard or any admin dashboard can get it
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->publish('exam_events', json_encode([
                    'event' => 'proctor_report_alert',
                    'data' => [
                        'student_id' => $userId,
                        'test_id' => $testId,
                        'student_username' => $this->request->getPost('student_username'),
                        'proctor_name' => session('username'),
                        'suggested_action' => $action,
                        'reason' => $reason
                    ]
                ]));
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on reportStudent: ' . $e->getMessage());
        }

        return $this->response->setJSON(['status' => 'success', 'message' => 'Laporan berhasil dikirim ke Admin']);
    }
}

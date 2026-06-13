<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\SettingModel;
use App\Models\ActivityLogModel;

class QueueController extends BaseController
{
    public function index()
    {
        $session = session();
        
        // If already fully logged in, go to dashboard
        if ($session->get('logged_in')) {
            $role = $session->get('role');
            if (in_array($role, ['admin', 'guru'])) {
                return redirect()->to('/admin/dashboard');
            }
            return redirect()->to('/student/dashboard');
        }

        // If not in queue, go to login
        if (!$session->get('is_queued')) {
            return redirect()->to('/login');
        }

        $settingModel = new SettingModel();
        $message = $settingModel->getValue('queue_waiting_message', 'Server sedang penuh. Anda berada dalam antrean. Mohon tunggu tanpa menutup halaman ini.');

        return view('auth/queue', ['message' => $message]);
    }

    public function ping()
    {
        $session = session();
        
        if ($session->get('logged_in') && !$session->get('is_queued')) {
            return $this->response->setJSON(['status' => 'ready']);
        }

        if (!$session->get('is_queued')) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Sesi tidak valid']);
        }

        $userId = $session->get('user_id');
        if (!$userId) return $this->response->setJSON(['status' => 'error']);

        $settingModel = new SettingModel();
        $maxConnections = (int) $settingModel->getValue('max_concurrent_connections', 90);

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                // Update queue member heartbeat so we know they are still waiting
                // Wait, if we update their score in ZSET, they lose their FIFO position!
                // Instead of updating the main login_queue score, we can use a separate hash for queue heartbeats if we want to clean up dead queue members. 
                // For simplicity, we just clean up active_sessions. Dead queue members will be ignored eventually, or popped and never enter.
                
                $redis->zRemRangeByScore('active_sessions', 0, time() - 300);
                $activeCount = $redis->zCard('active_sessions');
                
                if ($activeCount < $maxConnections) {
                    // Check if they are first in queue
                    $firstInQueue = $redis->zRange('login_queue', 0, 0);
                    
                    if (!empty($firstInQueue) && $firstInQueue[0] == $userId) {
                        // It's their turn!
                        $redis->zRem('login_queue', $userId);
                        $redis->zAdd('active_sessions', time(), $userId);
                        
                        // Upgrade session
                        $session->set('logged_in', true);
                        $session->remove('is_queued');
                        
                        $activityLog = new ActivityLogModel();
                        $activityLog->log('login_dequeue', $userId, 'user', $userId, 'Masuk dari antrean');
                        
                        return $this->response->setJSON(['status' => 'ready']);
                    } else if (empty($firstInQueue)) {
                        // Queue empty but they are here? Rare race condition or ghost queue. Fix their state.
                        $redis->zRem('login_queue', $userId);
                        $redis->zAdd('active_sessions', time(), $userId);
                        $session->set('logged_in', true);
                        $session->remove('is_queued');
                        return $this->response->setJSON(['status' => 'ready']);
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error in QueueController: ' . $e->getMessage());
        }

        return $this->response->setJSON(['status' => 'wait']);
    }
}

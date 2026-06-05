<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class SyncController extends BaseController
{
    public function keepAlive()
    {
        $session = session();
        if (!$session->get('logged_in')) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Not logged in']);
        }

        $userId = $session->get('user_id');

        try {
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
                // The RoleFilter already updated active_sessions for this user.
                
                // Check if we need to sync Redis to MySQL (Write-Behind, max once every 30 seconds)
                $lastSync = $redis->get('last_db_sync_time');
                $now = time();
                
                if (!$lastSync || ($now - (int)$lastSync) >= 30) {
                    // Acquire lock to prevent multiple concurrent syncs
                    if ($redis->set('sync_lock', '1', ['nx', 'ex' => 10])) {
                        
                        $redis->set('last_db_sync_time', $now);
                        
                        // Get all active users
                        $activeUsers = $redis->zRangeByScore('active_sessions', $now - 300, '+inf');
                        
                        if (!empty($activeUsers)) {
                            $db = \Config\Database::connect();
                            $builder = $db->table('users');
                            
                            // Perform a batch update. Since CI4 doesn't have a simple WHERE IN update for different values,
                            // we just update ALL active users to have last_active_at = NOW().
                            // This is efficient enough for 500-1000 users.
                            $builder->whereIn('id', $activeUsers)
                                    ->update(['last_active_at' => date('Y-m-d H:i:s', $now)]);
                        }
                        
                        $redis->del('sync_lock');
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error in SyncController: ' . $e->getMessage());
        }

        return $this->response->setJSON(['status' => 'ok']);
    }
}

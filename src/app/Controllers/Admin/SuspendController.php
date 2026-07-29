<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TestAttemptModel;
use App\Models\UserModel;

class SuspendController extends BaseController
{
    protected UserModel $userModel;
    protected TestAttemptModel $attemptModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->attemptModel = new TestAttemptModel();
    }

    public function index()
    {
        $search = $this->request->getGet('search');

        $this->userModel->select('users.id, users.username, users.firstname, users.lastname, users.is_active, users.created_at')
            ->select('(SELECT COUNT(*) FROM test_attempts ta WHERE ta.user_id = users.id) as total_attempts')
            ->select('(SELECT COUNT(*) FROM test_attempts ta2 WHERE ta2.user_id = users.id AND ta2.status IN (1,2)) as active_attempts')
            ->select('(SELECT SUM(ta3.cheat_strikes) FROM test_attempts ta3 WHERE ta3.user_id = users.id) as total_strikes')
            ->where('users.role', 'siswa');

        if (!empty($search)) {
            $this->userModel->groupStart()
                ->like('users.username', $search)
                ->orLike('users.firstname', $search)
                ->orLike('users.lastname', $search)
                ->groupEnd();
        }

        $this->userModel->orderBy('users.is_active', 'ASC')
            ->orderBy('users.username', 'ASC');

        $users = $this->userModel->paginate(20, 'users');
        $pager = $this->userModel->pager;

        return view('admin/suspend/index', [
            'users' => $users,
            'pager' => $pager,
            'search' => $search
        ]);
    }

    public function bulkAction()
    {
        $action = $this->request->getPost('action');
        $userIds = $this->request->getPost('user_ids');

        if (empty($userIds) || !is_array($userIds)) {
            return redirect()->to('/admin/suspend')->with('error', 'Pilih minimal satu user.');
        }

        $count = 0;
        foreach ($userIds as $id) {
            $user = $this->userModel->find($id);
            if ($user) {
                if ($action === 'ban') {
                    $this->_doBan($id);
                } elseif ($action === 'unban') {
                    $this->_doRelease($id);
                } elseif ($action === 'reset_login') {
                    $this->_doResetLogin($id);
                }
                $count++;
            }
        }

        return redirect()->to('/admin/suspend')->with('success', "Aksi massal berhasil diterapkan pada {$count} user.");
    }

    /**
     * Ban a user: set is_active = 0 and lock all their active attempts
     */
    private function _doBan($userId)
    {
        $db = \Config\Database::connect();
        $db->transStart();

        $this->userModel->update($userId, ['is_active' => 0]);

        $db->table('test_attempts')
           ->where('user_id', $userId)
           ->whereIn('status', [1, 2])
           ->update(['status' => 2]);

        $db->transComplete();

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                $redis->setex("ban_signal:{$userId}", 120, '1');
                $redis->publish('exam_events', json_encode([
                    'event' => 'ban',
                    'user_id' => $userId,
                    'message' => 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.'
                ]));
                
                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, 'ci_session:*', 100);
                    if ($keys) {
                        foreach ($keys as $key) {
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
            log_message('error', 'Redis error on ban: ' . $e->getMessage());
        }


    }

    public function ban($userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return redirect()->to('/admin/suspend')->with('error', 'User tidak ditemukan.');
        }

        $this->_doBan($userId);

        return redirect()->to('/admin/suspend')->with('success', "User {$user->username} telah di-BAN.");
    }

    private function _doRelease($userId)
    {
        $this->userModel->update($userId, ['is_active' => 1, 'unbanned_at' => date('Y-m-d H:i:s')]);

        $db = \Config\Database::connect();
        $db->table('test_attempts')
           ->where('user_id', $userId)
           ->where('cheat_strikes >', 0)
           ->update(['cheat_strikes' => 0]);

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->del("user_login_token:{$userId}");
                $redis->del("ban_signal:{$userId}");
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on release: ' . $e->getMessage());
        }
    }

    public function release($userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return redirect()->to('/admin/suspend')->with('error', 'User tidak ditemukan.');
        }

        $this->_doRelease($userId);

        return redirect()->to('/admin/suspend')->with('success', "User {$user->username} telah di-RELEASE.");
    }

    private function _doResetLogin($userId)
    {
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->del("user_login_token:{$userId}");
                $redis->zRem('active_sessions', $userId);
                $redis->zRem('login_queue', $userId);

                $failedIp = $redis->get("last_failed_login_ip:{$userId}");
                if ($failedIp) {
                    $redis->del("login_attempts_ip:{$failedIp}");
                    $redis->del("last_failed_login_ip:{$userId}");
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on reset login: ' . $e->getMessage());
        }
    }

    public function resetLogin($userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return redirect()->to('/admin/suspend')->with('error', 'User tidak ditemukan.');
        }

        $this->_doResetLogin($userId);

        return redirect()->to('/admin/suspend')->with('success', "Sesi login {$user->username} berhasil di-reset. Siswa kini bisa login kembali.");
    }

    /**
     * Reset all exam attempts for a user (delete everything)
     */
    public function reset($userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return redirect()->to('/admin/suspend')->with('error', 'User tidak ditemukan.');
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Get all attempt IDs
        $attempts = $db->table('test_attempts')
            ->where('user_id', $userId)
            ->select('id')
            ->get()->getResult();

        foreach ($attempts as $attempt) {
            // Delete log answers
            $logIds = $db->table('test_logs')
                ->where('test_attempt_id', $attempt->id)
                ->select('id')
                ->get()->getResultArray();
            $logIds = array_column($logIds, 'id');

            if (!empty($logIds)) {
                $db->table('test_log_answers')->whereIn('test_log_id', $logIds)->delete();
            }
            $db->table('test_logs')->where('test_attempt_id', $attempt->id)->delete();

            // Clear Redis
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->del("exam_answers:{$attempt->id}");
                    $redis->publish('exam_events', json_encode([
                        'event' => 'kick',
                        'attempt_id' => $attempt->id,
                        'message' => 'Sesi ujian Anda telah dihapus oleh Admin.'
                    ]));
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error in SuspendController::reset: ' . $e->getMessage());
            }
        }

        // Delete all attempts
        $db->table('test_attempts')->where('user_id', $userId)->delete();

        $db->transComplete();

        return redirect()->to('/admin/suspend')->with('success', "Seluruh sesi ujian {$user->username} telah direset.");
    }

    /**
     * Get all exam attempts for a specific user via AJAX
     */
    public function getUserAttempts($userId)
    {
        $db = \Config\Database::connect();
        $attempts = $db->table('test_attempts')
            ->select('test_attempts.*, tests.name as title')
            ->join('tests', 'tests.id = test_attempts.test_id')
            ->where('test_attempts.user_id', $userId)
            ->orderBy('test_attempts.created_at', 'DESC')
            ->get()->getResult();
        
        return $this->response->setJSON($attempts);
    }

    /**
     * Reset a specific exam attempt via AJAX
     */
    public function resetAttempt($attemptId)
    {
        $db = \Config\Database::connect();
        $attempt = $db->table('test_attempts')->where('id', $attemptId)->get()->getRow();
        if (!$attempt) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Sesi ujian tidak ditemukan.']);
        }

        $db->transStart();

        // Delete log answers
        $logIds = $db->table('test_logs')
            ->where('test_attempt_id', $attemptId)
            ->select('id')
            ->get()->getResultArray();
        $logIds = array_column($logIds, 'id');

        if (!empty($logIds)) {
            $db->table('test_log_answers')->whereIn('test_log_id', $logIds)->delete();
        }
        $db->table('test_logs')->where('test_attempt_id', $attemptId)->delete();

        // Clear Redis cache for this attempt
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->del("exam_answers:{$attemptId}");
                $redis->publish('exam_events', json_encode([
                    'event' => 'kick',
                    'attempt_id' => $attemptId,
                    'message' => 'Sesi ujian Anda telah dihapus oleh Admin.'
                ]));
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on reset attempt: ' . $e->getMessage());
        }

        // Delete attempt
        $db->table('test_attempts')->where('id', $attemptId)->delete();

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Gagal menghapus progress ujian.']);
        }

        return $this->response->setJSON(['status' => 'success', 'message' => 'Progress ujian berhasil dihapus.']);
    }
}

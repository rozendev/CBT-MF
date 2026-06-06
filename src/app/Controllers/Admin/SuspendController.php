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
        $db = \Config\Database::connect();

        // Get ALL students with their latest attempt info (if any)
        $users = $db->query("
            SELECT u.id, u.username, u.firstname, u.lastname, u.is_active, u.created_at,
                   (SELECT COUNT(*) FROM test_attempts ta WHERE ta.user_id = u.id) as total_attempts,
                   (SELECT COUNT(*) FROM test_attempts ta2 WHERE ta2.user_id = u.id AND ta2.status IN (1,2)) as active_attempts,
                   (SELECT SUM(ta3.cheat_strikes) FROM test_attempts ta3 WHERE ta3.user_id = u.id) as total_strikes
            FROM users u
            WHERE u.role = 'siswa'
              AND u.deleted_at IS NULL
            ORDER BY u.is_active ASC, u.username ASC
        ")->getResult();

        return view('admin/suspend/index', ['users' => $users]);
    }

    /**
     * Ban a user: set is_active = 0 and lock all their active attempts
     */
    public function ban($userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return redirect()->to('/admin/suspend')->with('error', 'User tidak ditemukan.');
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Deactivate user
        $this->userModel->update($userId, ['is_active' => 0]);

        // Lock all active/paused attempts
        $db->table('test_attempts')
           ->where('user_id', $userId)
           ->whereIn('status', [1, 2])
           ->update(['status' => 2]); // Paused instead of Locked, so progress is saved and can be resumed

        $db->transComplete();

        // Invalidate session in Redis to kick immediately via MultiLoginFilter + SSE
        try {
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
                // Signal for MultiLoginFilter (existing)
                $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                // Signal for SSE stream (new) — detected within 3 seconds
                $redis->setex("ban_signal:{$userId}", 120, '1');
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on ban: ' . $e->getMessage());
        }

        // Delete CI sessions from database to fully invalidate server-side
        $db->table('ci_sessions')
           ->groupStart()
               ->like('data', "user_id|i:{$userId};")
               ->orLike('data', "user_id|s:" . strlen((string)$userId) . ":\"{$userId}\";")
           ->groupEnd()
           ->delete();

        return redirect()->to('/admin/suspend')->with('success', "User {$user->username} telah di-BAN.");
    }

    /**
     * Release a banned user: set is_active = 1 and clean up Redis ban keys
     */
    public function release($userId)
    {
        $user = $this->userModel->find($userId);
        if (!$user) {
            return redirect()->to('/admin/suspend')->with('error', 'User tidak ditemukan.');
        }

        $this->userModel->update($userId, ['is_active' => 1]);

        // Clean up Redis ban keys so they don't interfere with next login
        try {
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
                $redis->del("user_login_token:{$userId}");
                $redis->del("ban_signal:{$userId}");
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error on release: ' . $e->getMessage());
        }

        return redirect()->to('/admin/suspend')->with('success', "User {$user->username} telah di-RELEASE.");
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
                $redis = new \Redis();
                if ($redis->connect('redis', 6379)) {
                    $redis->del("exam_answers:{$attempt->id}");
                }
            } catch (\Exception $e) {}
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
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
                $redis->del("exam_answers:{$attemptId}");
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

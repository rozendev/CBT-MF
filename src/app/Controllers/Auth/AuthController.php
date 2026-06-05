<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\ActivityLogModel;

class AuthController extends BaseController
{
    protected UserModel $userModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->userModel   = new UserModel();
        $this->activityLog = new ActivityLogModel();
    }

    /**
     * Show login form
     */
    public function login()
    {
        // If already logged in, redirect
        if (session()->get('logged_in')) {
            return $this->redirectByRole();
        }

        return view('auth/login');
    }

    /**
     * Handle login attempt
     */
    public function attemptLogin()
    {
        // Validate input
        $rules = [
            'username' => 'required',
            'password' => 'required',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Username dan password wajib diisi.');
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        // Find user
        $user = $this->userModel->getByUsername($username);

        if (!$user) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Username atau password salah.');
        }

        // Check if account is active
        if (!$user->is_active) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Akun Anda telah dinonaktifkan. Hubungi administrator.');
        }

        // Check if account is locked
        if ($this->userModel->isLocked($user)) {
            $lockedUntil = date('H:i', strtotime($user->locked_until));
            return redirect()->back()
                ->withInput()
                ->with('error', "Akun dikunci karena terlalu banyak percobaan login. Coba lagi setelah {$lockedUntil}.");
        }

        // Verify password
        if (!$this->userModel->verifyPassword($password, $user->password)) {
            $this->userModel->incrementLoginAttempts($user->id);

            // Check if we should lock the account
            $maxAttempts = $this->getSettingValue('max_login_attempts', 5);
            $updatedUser = $this->userModel->find($user->id);

            if ($updatedUser->login_attempts >= $maxAttempts) {
                $lockoutDuration = $this->getSettingValue('lockout_duration', 15);
                $this->userModel->lockAccount($user->id, $lockoutDuration);

                $this->activityLog->log('login_locked', $user->id, 'user', $user->id,
                    "Akun dikunci setelah {$maxAttempts} percobaan gagal");

                return redirect()->back()
                    ->withInput()
                    ->with('error', "Akun dikunci selama {$lockoutDuration} menit karena terlalu banyak percobaan login.");
            }

            return redirect()->back()
                ->withInput()
                ->with('error', 'Username atau password salah.');
        }

        // Regenerate session ID to prevent fixation
        session()->regenerate(true);

        // Check active sessions limit using Redis
        $maxConnections = (int) $this->getSettingValue('max_concurrent_connections', 90);
        $isQueued = false;
        
        try {
            $redis = new \Redis();
            if ($redis->connect('redis', 6379)) {
                $redis->zRemRangeByScore('active_sessions', 0, time() - 300); // Clean dead sessions
                $activeCount = $redis->zCard('active_sessions');
                
                // If this user is already in active_sessions, let them re-login without queuing
                $score = $redis->zScore('active_sessions', $user->id);
                
                if ($score === false && $activeCount >= $maxConnections) {
                    $isQueued = true;
                    $redis->zAdd('login_queue', time(), $user->id);
                } else {
                    $redis->zAdd('active_sessions', time(), $user->id);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error in AuthController: ' . $e->getMessage());
        }

        // Generate unique login token for multi-login prevention (immune to session regeneration)
        $loginToken = bin2hex(random_bytes(16));

        // Login successful — set session
        $this->userModel->recordLogin($user->id, $this->request->getIPAddress());

        session()->set([
            'user_id'     => $user->id,
            'username'    => $user->username,
            'role'        => $user->role,
            'firstname'   => $user->firstname ?? $user->username,
            'lastname'    => $user->lastname ?? '',
            'email'       => $user->email,
            'is_active'   => $user->is_active,
            'logged_in'   => !$isQueued,
            'is_queued'   => $isQueued,
            'login_token' => $loginToken,
        ]);

        // Store login token in Redis for multi-login detection (overwrites any old login token)
        try {
            if (isset($redis) && $redis->isConnected()) {
                $redis->setex("user_login_token:{$user->id}", 7200, $loginToken);
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis session store error: ' . $e->getMessage());
        }

        // Redirect to queue if limited
        if ($isQueued) {
            $this->activityLog->log('login_queued', $user->id, 'user', $user->id, 'Login ditunda (masuk antrean)');
            return redirect()->to('/queue');
        }

        // Log activity
        $this->activityLog->log('login', $user->id, 'user', $user->id, 'Login berhasil');

        // Redirect to intended URL or role-based dashboard
        $redirectUrl = session()->get('redirect_url');
        if ($redirectUrl) {
            session()->remove('redirect_url');
            return redirect()->to($redirectUrl);
        }

        return $this->redirectByRole();
    }

    /**
     * Logout user
     */
    public function logout()
    {
        $userId = session()->get('user_id');

        if ($userId) {
            // Remove Redis session tracking and active status
            try {
                $redis = new \Redis();
                if ($redis->connect('redis', 6379)) {
                    $redis->del("user_login_token:{$userId}");
                    $redis->zRem('active_sessions', $userId);
                    $redis->zRem('login_queue', $userId);
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis session cleanup error: ' . $e->getMessage());
            }

            $this->activityLog->log('logout', $userId, 'user', $userId, 'Logout');
        }

        session()->destroy();

        return redirect()->to('/login')
            ->with('success', 'Anda telah berhasil logout.');
    }

    /**
     * Redirect user based on their role
     */
    private function redirectByRole()
    {
        $role = session()->get('role');

        return match ($role) {
            'admin', 'guru' => redirect()->to('/admin/dashboard'),
            default         => redirect()->to('/student/dashboard'),
        };
    }

    /**
     * Get setting value from database
     */
    private function getSettingValue(string $key, mixed $default = null): mixed
    {
        $db = \Config\Database::connect();
        $setting = $db->table('settings')->where('key', $key)->get()->getRow();

        if (!$setting) {
            return $default;
        }

        return match ($setting->type) {
            'integer' => (int) $setting->value,
            'boolean' => (bool) $setting->value,
            'json'    => json_decode($setting->value, true),
            default   => $setting->value,
        };
    }
}

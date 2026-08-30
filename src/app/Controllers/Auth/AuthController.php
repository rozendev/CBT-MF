<?php

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\DeviceBan;
use App\Libraries\SessionTakeover;
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

    public function maintenance()
    {
        $settingModel = new \App\Models\SettingModel();
        $message = $settingModel->getValue('maintenance_message', 'Sistem sedang dalam pemeliharaan. Silakan coba lagi beberapa saat lagi.');

        return view('auth/maintenance', ['message' => $message]);
    }

    /**
     * Handle login attempt
     */
    public function attemptLogin()
    {
        $wantsJson = str_contains($this->request->getHeaderLine('Accept'), 'application/json')
            || $this->request->getHeaderLine('X-Requested-With') === 'kiosk-bundle';

        $fail = function (string $message) use ($wantsJson) {
            if ($wantsJson) {
                return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => $message]);
            }
            return redirect()->back()->withInput()->with('error', $message);
        };

        // Validate input
        $rules = [
            'username' => 'required',
            'password' => 'required',
        ];

        if (!$this->validate($rules)) {
            return $fail('Username dan password wajib diisi.');
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');

        // Find user
        $user = $this->userModel->getByUsername($username);

        if (!$user) {
            return $fail('Username atau password salah.');
        }

        // Check if account is active
        if (!$user->is_active) {
            return $fail('Akun Anda telah dinonaktifkan. Hubungi administrator.');
        }

        // Check if account is locked
        if ($this->userModel->isLocked($user)) {
            $lockedUntil = date('H:i', strtotime($user->locked_until));
            return $fail("Akun dikunci karena terlalu banyak percobaan login. Coba lagi setelah {$lockedUntil}.");
        }

        // Verify password
        if (!$this->userModel->verifyPassword($password, $user->password)) {
            $this->userModel->incrementLoginAttempts($user->id);

            // Record last failed login IP in Redis to allow admin to reset the IP rate limit when unlocking
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->setex("last_failed_login_ip:{$user->id}", 86400, $this->request->getIPAddress());
                }
            } catch (\Exception $e) {
                // Ignore Redis write failure for failed IP log
            }

            // Check if we should lock the account
            $maxAttempts = $this->getSettingValue('max_login_attempts', 5);
            $updatedUser = $this->userModel->find($user->id);

            if ($updatedUser->login_attempts >= $maxAttempts) {
                $lockoutDuration = $this->getSettingValue('lockout_duration', 15);
                $this->userModel->lockAccount($user->id, $lockoutDuration);

                $this->activityLog->log('login_locked', $user->id, 'user', $user->id,
                    "Akun dikunci setelah {$maxAttempts} percobaan gagal");

                return $fail("Akun dikunci selama {$lockoutDuration} menit karena terlalu banyak percobaan login.");
            }

            return $fail('Username atau password salah.');
        }

        // Try to connect to Redis
        $redis = \App\Libraries\RedisClient::getInstance();
        if (!$redis) {
            log_message('error', 'Redis connection failed during login process.');
            return $fail('Layanan database sesi tidak tersedia. Coba lagi beberapa saat.');
        }

        $loginToken = bin2hex(random_bytes(16));
        $tokenKey = "user_login_token:{$user->id}";
        $deviceKey = SessionTakeover::deviceKey($user->id);

        // Hanya diterima dari aplikasi kiosk, yang mengambilnya dari
        // DeviceIdentityStore — sumber yang sama dengan heartbeat dan
        // /api/kiosk/config. Browser biasa tidak mengirimnya, dan itu berarti
        // ia tidak pernah bisa merebut sesi milik perangkat lain.
        $rawDevice = $this->request->getPost('device_id');
        $incomingDevice = is_string($rawDevice) ? $rawDevice : '';
        if (!DeviceBan::isValidDeviceId($incomingDevice)) {
            $incomingDevice = '';
        }

        // Block second login for students if prevent_multi_login is enabled
        $preventMultiLogin = ($user->role === 'siswa' && $this->getSettingValue('prevent_multi_login', 1) == 1);
        if ($preventMultiLogin) {
            try {
                $existingRaw = $redis->get($tokenKey);
                $storedRaw = $redis->get($deviceKey);
                $existingToken = $existingRaw === false ? null : (string) $existingRaw;
                $storedDevice = $storedRaw === false ? null : (string) $storedRaw;

                $decision = SessionTakeover::decide($existingToken, $storedDevice, $incomingDevice);

                if ($decision === SessionTakeover::BUSY) {
                    return $fail('Akun Anda sedang digunakan di perangkat lain. Silakan ke Administrator jika Anda merasa ini kesalahan.');
                }

                if ($decision === SessionTakeover::FRESH) {
                    // Tetap 'nx': dua perangkat yang login bersamaan saat tidak
                    // ada token tidak boleh sama-sama menang.
                    $set = $redis->set($tokenKey, $loginToken, ['nx', 'ex' => SessionTakeover::TTL_SECONDS]);
                    if (!$set) {
                        return $fail('Akun Anda sedang digunakan di perangkat lain. Silakan ke Administrator jika Anda merasa ini kesalahan.');
                    }
                } else {
                    // TAKEOVER dan CLEAR_BANNED sama-sama menimpa. Tidak perlu
                    // 'nx': perangkat LAIN yang mencoba bersamaan sudah ditolak
                    // di pembacaannya sendiri, jadi ia tidak pernah menulis.
                    //
                    // Yang tidak ikut tertutup: dua login serentak dari
                    // perangkat yang SAMA, misalnya tombol login tertekan dua
                    // kali. Keduanya memutuskan TAKEOVER dan sama-sama menulis,
                    // lalu sesi yang kalah dimatikan MultiLoginFilter pada
                    // permintaan berikutnya. Tulisan terakhir menang — dan untuk
                    // pemilik perangkat yang sama, itu memang hasil yang benar.
                    $stored = $redis->setex($tokenKey, SessionTakeover::TTL_SECONDS, $loginToken);
                    if (!$stored) {
                        // Bukan perebutan, melainkan penyimpanan yang gagal,
                        // jadi pesannya pun harus mengatakan itu. phpredis
                        // melempar untuk kegagalan koneksi — sudah ditangkap di
                        // bawah — tapi mengembalikan false untuk sebagian balasan
                        // galat dari server. Kalau diloloskan, sesi ini berjalan
                        // dengan login_token yang tidak pernah tersimpan, dan
                        // MultiLoginFilter membaca token yang hilang sebagai
                        // "lanjutkan": perlindungan multi-login mati diam-diam
                        // untuk sesi itu. Gagal tertutup.
                        return $fail('Layanan sedang tidak tersedia. Coba lagi.');
                    }
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis multi-login block/reserve error: ' . $e->getMessage());
                return $fail('Layanan sedang tidak tersedia. Coba lagi.');
            }
        } else {
            // Write it anyway (overwriting)
            try {
                // Cabang ini TIDAK boleh berasumsi MultiLoginFilter ikut mati.
                // Sakelar mati di filter membandingkan `$isEnabled === '0'`
                // dengan sebuah string, sedangkan setelan prevent_multi_login
                // bertipe boolean dan sampai ke sini sebagai bool. Begitu admin
                // mematikannya, cabang ini yang jalan — selagi filter masih
                // menegakkan, karena `false === '0'` bernilai false.
                //
                // Jadi kegagalan penulisan di sini punya akibat yang sama
                // dengan di jalur TAKEOVER: sesi berjalan dengan login_token
                // yang tidak pernah tersimpan, dan filter membaca token yang
                // hilang sebagai "lanjutkan". Gagal tertutup. Benar atau
                // tidaknya perbandingan di berkas lain bukan sesuatu yang
                // pantas dijadikan sandaran di sini.
                $stored = $redis->setex($tokenKey, SessionTakeover::TTL_SECONDS, $loginToken);
                if (!$stored) {
                    return $fail('Layanan sedang tidak tersedia. Coba lagi.');
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis session store error: ' . $e->getMessage());
                return $fail('Layanan sedang tidak tersedia. Coba lagi.');
            }
        }

        // Samakan penanda perangkat dengan pemegang sesi yang baru: ditulis
        // kalau login ini membawa device_id, dihapus kalau tidak. Dijalankan di
        // kedua jalur di atas.
        $this->syncSessionDevice($redis, $user->id, $incomingDevice);

        // Regenerate session ID to prevent fixation
        session()->regenerate(true);

        // Check active sessions limit using Redis
        $maxConnections = (int) $this->getSettingValue('max_concurrent_connections', 90);
        $isQueued = false;
        
        try {
            $redis->zRemRangeByScore('active_sessions', 0, time() - 300); // Clean dead sessions
            $activeCount = $redis->zCard('active_sessions');
            
            // If this user is already in active_sessions, let them re-login without queuing
            $score = $redis->zScore('active_sessions', $user->id);
            
            if ($score === false && $activeCount >= $maxConnections) {
                $isQueued = true;
                // EDGE-05: Use microtime(true) as score for login_queue
                $redis->zAdd('login_queue', microtime(true), $user->id);
            } else {
                $redis->zAdd('active_sessions', time(), $user->id);
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error in AuthController during queue management: ' . $e->getMessage());
        }

        try {
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
        } catch (\Exception $e) {
            log_message('error', 'Login database/session record failed: ' . $e->getMessage());
            // Clean up the Redis token/activity to avoid blocking future logins
            try {
                $redis->del($tokenKey);
                $redis->zRem('active_sessions', $user->id);
                $redis->zRem('login_queue', $user->id);
            } catch (\Exception $re) {
                log_message('error', 'Redis cleanup failed during login failure: ' . $re->getMessage());
            }
            return $fail('Gagal memproses login. Coba lagi.');
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
            
            $parsedBase = parse_url(base_url());
            $parsedRedirect = parse_url($redirectUrl);
            
            $redirectHost = $parsedRedirect['host'] ?? '';
            $baseHost = $parsedBase['host'] ?? '';
            $scheme = $parsedRedirect['scheme'] ?? '';
            
            $isSameHost = ($redirectHost !== '' && strcasecmp($redirectHost, $baseHost) === 0);
            $isRelative = ($redirectHost === '' && $scheme === '' && !str_starts_with($redirectUrl, '//'));
            
            if ($isSameHost || $isRelative) {
                return redirect()->to($redirectUrl);
            }
        }

        if ($wantsJson) {
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Login berhasil.',
                'user' => [
                    'id' => (int) session('user_id'),
                    'username' => session('username'),
                    'firstname' => session('firstname'),
                    'lastname' => session('lastname'),
                ],
            ]);
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
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
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

        // Bundle kiosk memanggil lewat fetch lintas origin: redirect tidak berguna
        // di sana (fetch mengikutinya dan menerima HTML login). Pola sama dengan
        // attemptLogin.
        $wantsJson = str_contains($this->request->getHeaderLine('Accept'), 'application/json')
            || $this->request->getHeaderLine('X-Requested-With') === 'kiosk-bundle';

        if ($wantsJson) {
            return $this->response->setJSON([
                'status'  => 'success',
                'message' => 'Anda telah berhasil logout.',
            ]);
        }

        return redirect()->to('/login')
            ->with('success', 'Anda telah berhasil logout.');
    }

    /**
     * Catat perangkat pemegang sesi, atau buang catatannya.
     *
     * `$deviceId` kosong berarti "sesi ini tidak dipegang perangkat kiosk mana
     * pun" — konvensi yang sama dengan SessionTakeover::decide() — sehingga
     * pemanggil tidak perlu memilih sendiri antara menulis dan menghapus.
     * Login lewat browser biasa masuk ke cabang hapus, supaya pendamping lama
     * tidak tertinggal dan membuat kiosk basi mengira masih memegang sesi ini.
     *
     * Kegagalan di sini tidak boleh menggagalkan login. Tapi arah kebocorannya
     * berbeda di dua cabang, dan keduanya aman:
     *
     * - Gagal menulis mengunci: pendamping tidak ada, jadi perangkat ini nanti
     *   dianggap asing dan pemiliknya harus menunggu TTL atau minta admin —
     *   persis keadaan sebelum fitur ini ada.
     * - Gagal menghapus justru melonggarkan: pendamping lama tetap hidup selagi
     *   token dipegang browser, jadi kiosk basi itu masih bisa merebut sesinya
     *   kembali. Ini kebalikan arah dari cabang di atas.
     *
     * Cabang kedua menyisakan risiko, dan risiko itu tidak nol. Di tingkat akun
     * ia aman — pendamping hanya pernah berisi device_id milik pemilik akun yang
     * sama, jadi tidak ada jalur lintas-akun sama sekali (spec §4.3). Tapi model
     * ancaman fitur ini memang mencakup penyerang yang sudah melewati verifikasi
     * password, dan bagi dia pendamping basi mengubah BUSY menjadi TAKEOVER bila
     * ia kebetulan berada di kiosk yang dulu terdaftar. Sisa risikonya kecil:
     * butuh gangguan Redis, penguasaan fisik atas kiosk itu, DAN password
     * korban sekaligus. Dengan ketiganya terpenuhi, menggagalkan login pun tidak
     * menolong. Karena itu tetap tidak menggagalkan login — tapi dicatat di sini
     * sebagai sisa risiko, bukan sebagai nol.
     */
    private function syncSessionDevice(\Redis $redis, int|string $userId, string $deviceId): void
    {
        $deviceKey = SessionTakeover::deviceKey($userId);

        try {
            if ($deviceId !== '') {
                $redis->setex($deviceKey, SessionTakeover::TTL_SECONDS, $deviceId);
            } else {
                $redis->del($deviceKey);
            }
        } catch (\Exception $e) {
            log_message('warning', 'Gagal memperbarui penanda perangkat sesi: ' . $e->getMessage());
        }
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
        $settingModel = new \App\Models\SettingModel();
        return $settingModel->getValue($key, $default);
    }
}

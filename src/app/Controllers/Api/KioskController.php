<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class KioskController extends BaseController
{
    protected const MAX_EXIT_FAILS = 5;

    protected const EXIT_LOCKOUT_SECONDS = 600;

    public function config()
    {
        $settingModel = new SettingModel();

        $bundleInfo = ['version' => '', 'url' => '', 'size' => 0, 'sha256' => ''];
        $manifestPath = FCPATH . 'ui-bundle/manifest.json';
        $zipPath      = FCPATH . 'ui-bundle/ui-bundle.zip';
        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                $version = (string) ($manifest['version'] ?? '');
                // ?v= wajib: nginx menyajikan zip dengan Cache-Control public
                // max-age 14400, jadi Cloudflare boleh menahan berkas LAMA
                // sampai 4 jam. Perangkat lalu mengunduh zip usang yang isinya
                // konsisten dengan dirinya sendiri, versi lokalnya tidak pernah
                // menyusul versi yang dilaporkan config, dan aplikasi mengunduh
                // ulang selamanya tanpa pernah maju. URL unik per versi
                // memutus lingkaran itu.
                // sha256 zip UTUH adalah satu-satunya jangkar kepercayaan yang
                // dimiliki perangkat. manifest.json ikut terbungkus di dalam zip,
                // jadi mencocokkan 'version' saja tak membuktikan apa pun:
                // penyusun zip palsu tinggal menyalin nomor versi yang sah.
                // Nilai ini datang lewat HTTPS dari server, di luar zip.
                $bundleInfo = [
                    'version' => $version,
                    'url'     => base_url('ui-bundle/ui-bundle.zip') . ($version !== '' ? '?v=' . substr($version, 0, 16) : ''),
                    'size'    => (int) (is_file($zipPath) ? filesize($zipPath) : 0),
                    'sha256'  => is_file($zipPath) ? hash_file('sha256', $zipPath) : '',
                ];
            } catch (\Throwable $e) {
                log_message('error', 'Kiosk config bundle manifest error: ' . $e->getMessage());
            }
        }

        return $this->response->setJSON([
            'school_name'     => $settingModel->getValue('app_name', 'CBT-MF Kiosk System'),
            'exam_url'        => base_url('student/dashboard'),
            'min_app_version' => $settingModel->getValue('kiosk_min_app_version', '1.0.0'),
            'features'        => [
                'siren_enabled'             => (bool) $settingModel->getValue('kiosk_siren_enabled', true),
                'siren_max_volume'          => (bool) $settingModel->getValue('kiosk_siren_max_volume', true),
                'enforce_home_launcher'     => (bool) $settingModel->getValue('kiosk_enforce_home_launcher', true),
                'block_clipboard'          => (bool) $settingModel->getValue('kiosk_block_clipboard', true),
                'root_detection_strictness' => $settingModel->getValue('kiosk_root_strictness', 'warning'),
                'overlay_guard_enabled'     => (bool) $settingModel->getValue('kiosk_overlay_guard_enabled', true),
            ],
            'ui_bundle'       => $bundleInfo,
        ]);
    }

    /**
     * Verify a proctor/teacher exit password.
     * Password NEVER leaves the device as a deliverable: it is only sent here
     * from the native app when the proctor types it into the unlock dialog.
     *
     * POST /api/kiosk/verify-exit  { "password": "..." }
     */
    public function verifyExit()
    {
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body)) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            $password = (string) ($body['password'] ?? '');

            // Optional shared secret for defense-in-depth (env KIOSK_APP_SECRET)
            $expectedSecret = env('KIOSK_APP_SECRET', '');
            if ($expectedSecret !== '') {
                $givenSecret = (string) ($body['app_secret'] ?? '');
                if (!hash_equals($expectedSecret, $givenSecret)) {
                    return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'allowed' => false]);
                }
            }

            if ($password === '') {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            // Brute-force lockout: max 5 wrong attempts per 10 minutes.
            // Keyed per device (bila app mengirim device_id) agar siswa di NAT/WiFi
            // yang sama tidak saling mengunci. Fallback per IP.
            $deviceId = (string) ($body['device_id'] ?? '');
            $deviceId = preg_replace('/[^a-zA-Z0-9_-]/', '', $deviceId);
            if ($deviceId !== '' && strlen($deviceId) <= 64) {
                $cacheKey = 'kiosk_exit_fail_dev_' . md5($deviceId);
            } else {
                $ip = $this->request->getIPAddress();
                // NB: colon tidak boleh dipakai di cache key CI4 (reservedCharacters)
                $cacheKey = 'kiosk_exit_fail_ip_' . md5((string) $ip);
            }
            $cache = service('cache');
            $fails = (int) $cache->get($cacheKey);

            if ($fails >= self::MAX_EXIT_FAILS) {
                return $this->response->setStatusCode(429)->setJSON([
                    'status'   => 'error',
                    'allowed'  => false,
                    'message'  => 'Terlalu banyak percobaan. Kunci dibuka sementara.',
                    'retry_in' => self::EXIT_LOCKOUT_SECONDS,
                ]);
            }

            $settingModel = new SettingModel();
            $expected = (string) $settingModel->getValue('kiosk_exit_password', '123456');

            if ($expected === '' || !hash_equals($expected, $password)) {
                $cache->save($cacheKey, $fails + 1, self::EXIT_LOCKOUT_SECONDS);
                return $this->response->setStatusCode(403)->setJSON([
                    'status'             => 'error',
                    'allowed'            => false,
                    'message'            => 'Password salah.',
                    'remaining_attempts' => max(0, self::MAX_EXIT_FAILS - ($fails + 1)),
                ]);
            }

            $cache->delete($cacheKey);

            return $this->response->setJSON(['status' => 'ok', 'allowed' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'Kiosk verifyExit ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'allowed' => false]);
        }
    }

    /**
     * Native app asks "may this kiosk session exit?".
     * The ws_token was bound to (user_id, attempt_id, test_id) by the server
     * when the exam page was served, so only a genuinely finished attempt of
     * the actual student can unlock the device.
     *
     * POST /api/kiosk/can-exit  { "token": "<ws_token 32 hex>" }
     */
    public function canExit()
    {
        try {
            $body = $this->request->getJSON(true);
            $token = (string) ($body['token'] ?? '');

            if (!preg_match('/^[a-f0-9]{32}$/i', $token)) {
                return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            $redis = \App\Libraries\RedisClient::getInstance();
            if (!$redis) {
                return $this->response->setStatusCode(503)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            $tokenData = $redis->get("ws_student_token:{$token}");
            if (!$tokenData) {
                return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            $tokenData = json_decode($tokenData, true);
            $attemptId = (int) ($tokenData['attempt_id'] ?? 0);
            if ($attemptId <= 0) {
                return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            $db = \Config\Database::connect();
            $attempt = $db->table('test_attempts')->select('status')->where('id', $attemptId)->get()->getRow();

            // Hanya izinkan unlock jika ujian benar-benar SELESAI (status 3).
            // Status 2 (dikunci karena pelanggaran) TIDAK melepas kiosk:
            // siswa menunggu pengawas membuka lewat password (verify-exit).
            if (!$attempt || (int) $attempt->status !== 3) {
                return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'allowed' => false]);
            }

            // Token ini hanya membuktikan SATU attempt selesai. Kalau siswa masih
            // punya ujian lain yang berjalan atau terkunci, kiosk tetap tidak
            // boleh lepas: sekali ujian dimulai, satu-satunya jalan keluar adalah
            // password pengawas. Tanpa cek ini, token dari ujian yang sudah
            // selesai tetap sah selama 4 jam dan bisa dipakai membuka kunci di
            // tengah ujian berikutnya -- termasuk lewat jalur wajar, mis. ujian
            // kedua sudah dibuat oleh exam/start tapi halamannya gagal memuat
            // sehingga token di sessionStorage belum tertimpa.
            $userId  = (int) ($tokenData['user_id'] ?? 0);
            $pending = $db->table('test_attempts')
                ->where('user_id', $userId)
                ->whereIn('status', [0, 1, 2])
                ->countAllResults();

            if ($pending > 0) {
                try {
                    (new \App\Models\ActivityLogModel())->log(
                        'kiosk_exit_denied',
                        $userId,
                        'test',
                        (int) ($tokenData['test_id'] ?? 0),
                        "Kiosk tidak dilepas: masih ada {$pending} ujian yang belum selesai"
                    );
                } catch (\Throwable $e) {
                    log_message('error', 'Kiosk canExit audit error: ' . $e->getMessage());
                }

                return $this->response->setStatusCode(403)->setJSON([
                    'status'  => 'error',
                    'allowed' => false,
                    'reason'  => 'unfinished_attempt',
                    'message' => 'Masih ada ujian yang belum selesai. Minta pengawas membuka kunci.',
                ]);
            }

            try {
                (new \App\Models\ActivityLogModel())->log('kiosk_exit', (int) ($tokenData['user_id'] ?? 0), 'test', (int) ($tokenData['test_id'] ?? 0), 'Kiosk mode dilepas setelah ujian selesai');
            } catch (\Throwable $e) {
                log_message('error', 'Kiosk canExit activity log error: ' . $e->getMessage());
            }

            return $this->response->setJSON(['status' => 'ok', 'allowed' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'Kiosk canExit ERROR: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'allowed' => false]);
        }
    }
}
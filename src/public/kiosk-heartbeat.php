<?php

/**
 * Kiosk heartbeat endpoint — intentionally framework-free.
 *
 * Served through the nginx `location ~ \.php$` regex (like
 * maintenance-check.php), so it bypasses the maintenance flag gate and
 * keeps working during manual maintenance mode. When Redis itself is
 * down it answers 503 {mode:redis} so the kiosk can back off.
 *
 * Contract (POST, JSON body):
 *   {token, device_id, battery, charging, network, app_version}
 *   200 {"status":"ok"} | 401 {"status":"invalid_token"} |
 *   403 {"status":"device_banned"} | 503 {"status":"maintenance","mode":"redis"}
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$status = 200;
$body   = ['status' => 'ok'];

try {
    $raw = file_get_contents('php://input');
    $req = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($req)) {
        $req = [];
    }

    $token = (string) ($req['token'] ?? '');
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['status' => 'invalid_token']);
        exit;
    }

    $redis = new Redis();
    if (!$redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 1.5)) {
        http_response_code(503);
        echo json_encode(['status' => 'maintenance', 'mode' => 'redis']);
        exit;
    }
    $password = (string) getenv('REDIS_PASSWORD');
    if ($password !== '' && !$redis->auth($password)) {
        http_response_code(503);
        echo json_encode(['status' => 'maintenance', 'mode' => 'redis']);
        exit;
    }

    // Wajib, dan wajib sebelum perintah pertama. Redis yang beku tetap
    // menyelesaikan handshake TCP, jadi timeout connect di atas tidak pernah
    // menyala — tanpa baris ini heartbeat menggantung selamanya, justru di
    // berkas yang seluruh gunanya adalah tetap hidup saat yang lain mati.
    $redis->setOption(Redis::OPT_READ_TIMEOUT, 3);

    $sessionRaw = $redis->get('ws_student_token:' . $token);
    $session    = $sessionRaw !== false ? json_decode($sessionRaw, true) : null;
    if (!is_array($session) || !isset($session['user_id'], $session['attempt_id'], $session['test_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'invalid_token']);
        exit;
    }

    $testId  = (int) $session['test_id'];
    $userId  = (int) $session['user_id'];
    $key     = "kiosk_live:{$testId}:{$userId}";
    $now     = time();

    $battery = (int) ($req['battery'] ?? -1);
    if ($battery < 0 || $battery > 100) {
        $battery = -1;
    }

    $fields = [
        'battery'     => (string) $battery,
        'charging'    => !empty($req['charging']) ? '1' : '0',
        'network'     => in_array(($req['network'] ?? ''), ['wifi', 'mobile', 'none'], true) ? $req['network'] : 'unknown',
        'app_version' => substr((string) ($req['app_version'] ?? ''), 0, 32),
        'device_id'   => substr((string) ($req['device_id'] ?? ''), 0, 64),
        'ts'          => (string) $now,
    ];

    // Perangkat terblokir: jangan tulis kiosk_live sama sekali. Selain
    // menjawab 403 supaya aplikasi menampilkan layar terkunci, berhentinya
    // tulisan membuat heartbeat menjadi basi, sehingga KioskPresence menolak
    // tulisan jawaban setelah STALE_SECONDS. Lapis itu datang gratis.
    //
    // Bebas framework dengan sengaja, jadi tabelnya dibaca lewat PDO yang
    // sudah dipakai di berkas ini — bukan lewat App\Libraries\DeviceBan.
    $deviceId = $fields['device_id'];
    // \A dan \z, bukan ^ dan $ — lihat alasannya di DeviceBan::isValidDeviceId().
    if ($deviceId !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $deviceId) === 1) {
        $banCacheKey = 'kiosk_device_ban:' . $deviceId;
        $cached      = $redis->get($banCacheKey);

        $isBanned  = null;
        $banReason = '';
        if ($cached === '1') {
            $isBanned = true;
        } elseif ($cached === '0') {
            $isBanned = false;
        }

        // Kueri di bawah menduplikasi KioskBannedDeviceModel::activeFor()
        // dengan sengaja — berkas ini bebas framework, lihat komentar di
        // model itu untuk alasannya.
        $fetchBanRow = static function () use ($deviceId): array|false {
            $pdoBan = new PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('DB_PORT') ?: '3306')
                . ';dbname=' . (getenv('DB_DATABASE') ?: 'cbt')
                . ';charset=utf8mb4',
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: '',
                [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdoBan->prepare(
                'SELECT reason FROM kiosk_banned_devices
                 WHERE device_id = ? AND unlocked_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$deviceId]);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        };

        if ($isBanned === null) {
            // Cache dingin atau rusak: tanya database. TIDAK boleh dianggap
            // "tidak terblokir" — itu jalur gagal-terbuka yang sunyi.
            try {
                $row       = $fetchBanRow();
                $isBanned  = $row !== false;
                $banReason = $isBanned ? (string) $row['reason'] : '';

                // try/catch terpisah dengan sengaja, seperti yang dilakukan
                // DeviceBan::isBanned(): kegagalan MENULIS cache adalah
                // masalah lain dari kegagalan MEMBACA database di atas, dan
                // tidak boleh membatalkan verdict yang baru saja benar
                // didapat dari sumber kebenaran. Nested di dalam try yang
                // sukses ini dengan sengaja: kalau baca DB di atas gagal,
                // catch di bawah sudah menangani fail-open TANPA menulis
                // cache sama sekali, supaya verdict "tidak terblokir" yang
                // sekadar tebakan itu tidak ikut disimpan. Angka 30
                // mencerminkan DeviceBan::CACHE_TTL_SECONDS.
                try {
                    $redis->setex($banCacheKey, 30, $isBanned ? '1' : '0');
                } catch (Throwable $e) {
                    error_log('[kiosk-heartbeat] gagal menulis cache ban: ' . $e->getMessage());
                }
            } catch (Throwable $e) {
                // Gagal-tertutup di sini akan menolak SETIAP perangkat
                // saat database bermasalah sekejap, bukan hanya yang
                // terblokir — presence seluruh armada ujian ikut runtuh
                // karena masalah yang tak ada hubungannya dengan status
                // ban perangkat mana pun. Cabang ini sengaja tidak menulis
                // '0' ke cache, jadi verdict keliru itu tidak bertahan
                // melewati gangguan — heartbeat berikutnya menanyakan
                // ulang ke database. Alasan "situsnya toh sudah
                // maintenance" TIDAK berlaku di sini: berkas ini sengaja
                // dikecualikan dari gerbang maintenance nginx dan tetap
                // menjawab saat sisa situs tidak, dan catch (Throwable)
                // ini menyala untuk kegagalan apa pun — timeout,
                // max_connections habis, blip jaringan — bukan hanya
                // gangguan menyeluruh yang sudah ditandai deps:probe.
                error_log('[kiosk-heartbeat] cek ban gagal: ' . $e->getMessage());
                $isBanned  = false;
                $banReason = '';
            }
        }

        if ($isBanned) {
            if ($banReason === '') {
                // Cache hangat cuma menyimpan '0'/'1', tanpa alasan — dan
                // dengan TTL 30 detik berbanding heartbeat 15 detik, ini
                // jalur yang dilewati hampir setiap 403 setelah yang
                // pertama, bukan kasus tepi. /api/kiosk/config tidak
                // membuat ini mubazir: config hanya jalan saat aplikasi
                // start, jadi heartbeat inilah satu-satunya kanal yang bisa
                // menjelaskan ban yang terjadi di tengah sesi. Kueri ini
                // TIDAK PERNAH boleh membalik $isBanned — degradasi ke ''
                // saja kalau gagal.
                try {
                    $row = $fetchBanRow();
                    if ($row !== false) {
                        $banReason = (string) $row['reason'];
                    }
                } catch (Throwable $e) {
                    error_log('[kiosk-heartbeat] gagal ambil alasan ban: ' . $e->getMessage());
                }
            }

            http_response_code(403);
            echo json_encode(['status' => 'device_banned', 'reason' => $banReason]);
            exit;
        }
    }

    // Race-free audit of first heartbeat of a session:
    // HSETNX returns 1 only when the key is created.
    $isNew = $redis->hSetNx($key, 'ts', (string) $now);
    $redis->hMSet($key, $fields);

    if ($isNew) {
        try {
            $pdo = new PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('DB_PORT') ?: '3306')
                . ';dbname=' . (getenv('DB_DATABASE') ?: 'cbt')
                . ';charset=utf8mb4',
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: '',
                [PDO::ATTR_TIMEOUT => 2, PDO::ERRMODE_EXCEPTION => true]
            );
            $stmt = $pdo->prepare(
                'INSERT INTO exam_kiosk_events (exam_session_id, student_id, event_type, event_details, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $testId,
                $userId,
                'kiosk_online',
                json_encode([
                    'device_id'   => $fields['device_id'],
                    'battery'     => $fields['battery'],
                    'network'     => $fields['network'],
                    'app_version' => $fields['app_version'],
                ], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s', $now),
            ]);
        } catch (Throwable $e) {
            // Audit is best-effort: a DB failure must not break the heartbeat.
            error_log('[kiosk-heartbeat] audit insert failed: ' . $e->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    error_log('[kiosk-heartbeat] error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'maintenance', 'mode' => 'redis']);
}

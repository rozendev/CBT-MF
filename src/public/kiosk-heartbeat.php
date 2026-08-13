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
 *   200 {"status":"ok"} | 401 {"status":"invalid_token"} | 503 {"status":"maintenance","mode":"redis"}
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

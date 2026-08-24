<?php

/**
 * Standalone maintenance status probe — intentionally framework-free.
 *
 * Served directly by nginx (exact-match location, bypasses the maintenance
 * flag gate) so the maintenance troubleshooting page can poll it while the
 * app itself is unreachable. Answers with JSON only; never throws.
 *
 * Response: { "mode": "redis"|"manual"|"none", "redis_ok": bool,
 *             "message": string, "ts": int, "now": int }
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
http_response_code(200); // always 200: the BODY carries the state

$writable = dirname(__DIR__) . '/writable';
$flags    = [
    'manual' => $writable . '/.maintenance_manual',
    'redis'  => $writable . '/.maintenance_redis',
];

$mode    = 'none';
$message = '';
$ts      = 0;

foreach ($flags as $flagMode => $flagPath) {
    $raw = is_file($flagPath) ? @file_get_contents($flagPath) : false;
    if ($raw === false) {
        continue;
    }

    $data = json_decode($raw, true);
    if (is_array($data)) {
        $mode    = $flagMode;
        $message = (string) ($data['message'] ?? '');
        $ts      = (int) ($data['ts'] ?? 0);
    } else {
        $mode = $flagMode;
        $ts   = (int) @filemtime($flagPath);
    }
    break; // manual takes precedence for display
}

$redisOk = false;
try {
    $redis = new Redis();
    if ($redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 1.5)) {
        $password = (string) getenv('REDIS_PASSWORD');
        if ($password === '' || $redis->auth($password)) {
            $redisOk = $redis->ping();
        }
        $redis->close();
    }
} catch (Throwable $e) {
    $redisOk = false;
}

echo json_encode([
    'mode'     => $mode,
    'redis_ok' => (bool) $redisOk,
    'message'  => $message,
    'ts'       => $ts,
    'now'      => time(),
]);

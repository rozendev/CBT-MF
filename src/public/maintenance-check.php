<?php

/**
 * Standalone maintenance status probe — intentionally framework-free.
 *
 * Served directly by nginx (exact-match location, bypasses the maintenance
 * flag gate) so the maintenance troubleshooting page can poll it while the
 * app itself is unreachable. Answers with JSON only; never throws.
 *
 * Cannot share code with App\Libraries\DependencyHealth on purpose: loading
 * the framework needs the very dependencies this endpoint exists to report on.
 * Keep the timeouts here in step with that class.
 *
 * Response: { "mode": "deps"|"manual"|"none", "down": string[],
 *             "redis_ok": bool, "db_ok": bool, "message": string,
 *             "ts": int, "now": int }
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
http_response_code(200); // always 200: the BODY carries the state

const PROBE_TIMEOUT_SECONDS = 2;

$writable = dirname(__DIR__) . '/writable';
$flags    = [
    'manual' => $writable . '/.maintenance_manual',
    'deps'   => $writable . '/.maintenance_deps',
];

$mode    = 'none';
$message = '';
$down    = [];
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
        $down    = is_array($data['down'] ?? null) ? array_values($data['down']) : [];
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
    if ($redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), PROBE_TIMEOUT_SECONDS)) {
        $password = (string) getenv('REDIS_PASSWORD');
        if ($password === '' || $redis->auth($password)) {
            $redisOk = (bool) $redis->ping();
        }
        $redis->close();
    }
} catch (Throwable $e) {
    $redisOk = false;
}

// SELECT 1 rather than connect-only: a MariaDB that is read-only or out of
// disk still completes a handshake while every write fails.
$dbOk   = false;
$mysqli = null;
try {
    $mysqli = mysqli_init();
    if ($mysqli !== false) {
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, PROBE_TIMEOUT_SECONDS);
        $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, PROBE_TIMEOUT_SECONDS);

        $connected = @$mysqli->real_connect(
            getenv('DB_HOST') ?: 'mariadb',
            getenv('DB_USERNAME') ?: '',
            getenv('DB_PASSWORD') ?: '',
            getenv('DB_DATABASE') ?: '',
            (int) (getenv('DB_PORT') ?: 3306),
        );

        if ($connected) {
            $result = @$mysqli->query('SELECT 1');
            if ($result !== false) {
                $dbOk = true;
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
            }
        }
    }
} catch (Throwable $e) {
    $dbOk = false;
} finally {
    if ($mysqli instanceof mysqli) {
        @$mysqli->close();
    }
}

echo json_encode([
    'mode'     => $mode,
    'down'     => $down,
    'redis_ok' => (bool) $redisOk,
    'db_ok'    => (bool) $dbOk,
    'message'  => $message,
    'ts'       => $ts,
    'now'      => time(),
]);

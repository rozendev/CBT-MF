<?php

namespace App\Libraries;

/**
 * Manages the nginx maintenance flag files (writable/.maintenance_*).
 *
 * Each mode has its OWN flag file so nginx can distinguish them with plain
 * `-f` checks:
 *   - writable/.maintenance_redis   → Redis outage (set by the cron probe)
 *   - writable/.maintenance_manual  → admin maintenance toggle
 *
 * nginx answers 503 → maintenance page when a flag exists, WITHOUT passing
 * the request through PHP-FPM. This keeps the troubleshooting page alive
 * even when the whole session stack (Redis) is down.
 *
 * Payload JSON: { "mode": "redis"|"manual", "message": string, "ts": int }
 */
class MaintenanceFlag
{
    public const MODE_REDIS  = 'redis';
    public const MODE_MANUAL = 'manual';

    /** Grace: do not delete a redis flag until it is this old (anti-flap). */
    public const RECOVERY_STABLE_SECONDS = 15;

    /** Throttle: rewrite the flag at most once per this window (write-storm guard). */
    public const TOUCH_THROTTLE_SECONDS = 30;

    public static function path(string $mode): string
    {
        return WRITEPATH . ($mode === self::MODE_MANUAL ? '.maintenance_manual' : '.maintenance_redis');
    }

    public static function set(string $mode, string $message = ''): void
    {
        $path    = self::path($mode);
        $payload = json_encode([
            'mode'    => $mode,
            'message' => $message,
            'ts'      => time(),
        ]);

        $existing = @file_get_contents($path);
        $data     = $existing !== false ? json_decode($existing, true) : null;

        if (is_array($data) && ($data['message'] ?? '') === $message) {
            $age = time() - (int) @filemtime($path);
            if ($age < self::TOUCH_THROTTLE_SECONDS) {
                return; // same payload, recently written → skip
            }
        }

        @file_put_contents($path, $payload, LOCK_EX);
    }

    public static function clear(string $mode): void
    {
        @unlink(self::path($mode));
    }

    public static function get(string $mode): ?array
    {
        $path = self::path($mode);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['mode' => $mode, 'message' => '', 'ts' => (int) @filemtime($path)];
        }

        return [
            'mode'    => $mode,
            'message' => (string) ($data['message'] ?? ''),
            'ts'      => (int) ($data['ts'] ?? @filemtime($path)),
        ];
    }

    public static function isActive(?string $mode = null): bool
    {
        if ($mode !== null) {
            return is_file(self::path($mode));
        }

        return is_file(self::path(self::MODE_MANUAL)) || is_file(self::path(self::MODE_REDIS));
    }
}

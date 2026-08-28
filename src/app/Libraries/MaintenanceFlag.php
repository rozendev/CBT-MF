<?php

namespace App\Libraries;

/**
 * Manages the nginx maintenance flag files (writable/.maintenance_*).
 *
 * Each mode has its OWN flag file so nginx can distinguish them with plain
 * `-f` checks:
 *   - writable/.maintenance_deps    → a required dependency is unreachable
 *                                     (set by the cron probe: Redis, MariaDB,
 *                                     or both)
 *   - writable/.maintenance_manual  → admin maintenance toggle
 *
 * There is deliberately no per-dependency mode. Redis is not a detachable
 * cache in this application — sessions, the answer buffer and WebSocket tokens
 * live there with no table behind them — so Redis down and MariaDB down are
 * the same event as far as serving traffic goes. Which one failed is carried
 * in the payload for the maintenance page to display, not in the routing.
 *
 * nginx answers 503 → maintenance page when a flag exists, WITHOUT passing
 * the request through PHP-FPM. This keeps the troubleshooting page alive even
 * when the whole stack behind it is down.
 *
 * Payload JSON: { "mode": "deps"|"manual", "message": string,
 *                 "down": string[], "ts": int }
 *
 * The file's MTIME is meaningful and separate from "ts": it is refreshed on
 * every probe that observes an outage, so it always means "last seen
 * unhealthy". Recovery is measured from mtime — see secondsSinceLastDown().
 */
class MaintenanceFlag
{
    public const MODE_DEPS   = 'deps';
    public const MODE_MANUAL = 'manual';

    /** Grace: a deps flag is not cleared until the stack has been healthy this long. */
    public const RECOVERY_STABLE_SECONDS = 15;

    /** Pre-unification flag file, removed on sight so nothing stale lingers after an upgrade. */
    private const LEGACY_DEPS_FILE = '.maintenance_redis';

    public static function path(string $mode): string
    {
        return WRITEPATH . ($mode === self::MODE_MANUAL ? '.maintenance_manual' : '.maintenance_deps');
    }

    /**
     * @param list<string> $down Dependency names, for the maintenance page.
     */
    public static function set(string $mode, string $message = '', array $down = []): void
    {
        $path = self::path($mode);

        $existing = @file_get_contents($path);
        $data     = $existing !== false ? json_decode($existing, true) : null;

        if (is_array($data) && ($data['message'] ?? '') === $message) {
            // Nothing new to say. Bump mtime only: that is what recovery is
            // measured from, and it costs an inode update instead of a write.
            @touch($path);

            return;
        }

        @file_put_contents($path, json_encode([
            'mode'    => $mode,
            'message' => $message,
            'down'    => array_values($down),
            'ts'      => time(),
        ]), LOCK_EX);
    }

    public static function clear(string $mode): void
    {
        @unlink(self::path($mode));
    }

    public static function get(string $mode): ?array
    {
        $path = self::path($mode);
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return ['mode' => $mode, 'message' => '', 'down' => [], 'ts' => (int) @filemtime($path)];
        }

        return [
            'mode'    => $mode,
            'message' => (string) ($data['message'] ?? ''),
            'down'    => is_array($data['down'] ?? null) ? $data['down'] : [],
            'ts'      => (int) ($data['ts'] ?? @filemtime($path)),
        ];
    }

    /**
     * Seconds since the dependency stack was last observed unhealthy.
     *
     * Reads mtime rather than the payload's "ts" on purpose. "ts" only moves
     * when the message changes, so during a long outage it goes stale — and
     * comparing against a stale "ts" would report a grace period that has
     * already elapsed the moment the stack came back, defeating the anti-flap
     * window entirely. mtime is refreshed by every set() call.
     */
    public static function secondsSinceLastDown(string $mode): int
    {
        $mtime = @filemtime(self::path($mode));

        if ($mtime === false) {
            return PHP_INT_MAX;
        }

        // Clamped: a clock stepped backwards leaves an mtime in the future,
        // and a negative age would read as "healthy for minus a minute" in the
        // probe's own log. Zero means "seen down just now", which keeps the
        // flag up and resolves on its own as the clock advances.
        return max(0, time() - (int) $mtime);
    }

    public static function isActive(?string $mode = null): bool
    {
        if ($mode !== null) {
            return is_file(self::path($mode));
        }

        return is_file(self::path(self::MODE_MANUAL)) || is_file(self::path(self::MODE_DEPS));
    }

    /**
     * Drops the flag file used before Redis and the database were unified
     * behind one mode. nginx no longer reads it, so an upgrade that left one
     * behind would otherwise keep a confusing file in writable/ forever.
     */
    public static function forgetLegacyFlag(): void
    {
        $legacy = WRITEPATH . self::LEGACY_DEPS_FILE;

        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }
}

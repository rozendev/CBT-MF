<?php

namespace App\Libraries;

/**
 * Liveness checks for the two dependencies the application cannot run without.
 *
 * Redis is NOT a cache here: sessions, the answer write-buffer, WebSocket
 * tokens, ban signals and kiosk presence live there and have no table to fall
 * back to. Losing it is exactly as fatal as losing MariaDB, so both are probed
 * by the same code and reported through the same flag.
 *
 * Every check is bounded by CONNECT_TIMEOUT_SECONDS, well under the 10s probe
 * interval, so a hung dependency can never make probes pile up on each other.
 * CodeIgniter's own MySQLi driver hardcodes a 10s connect timeout — equal to
 * the probe interval — which is why the database check here talks to mysqli
 * directly instead of going through the framework.
 */
class DependencyHealth
{
    public const REDIS    = 'redis';
    public const DATABASE = 'database';

    /** Connect/read timeout for a probe, in seconds. */
    public const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * Names of the dependencies that are currently unreachable.
     *
     * @return list<string> Empty when everything is healthy.
     */
    public static function down(): array
    {
        $down = [];

        if (! self::redisOk()) {
            $down[] = self::REDIS;
        }

        if (! self::dbOk()) {
            $down[] = self::DATABASE;
        }

        return $down;
    }

    /**
     * Round-trips Redis. Connection details stay in RedisClient so there is
     * only one definition of how this application reaches Redis.
     */
    public static function redisOk(): bool
    {
        try {
            $redis = RedisClient::getInstance();

            return $redis !== null && $redis->ping() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Connects and runs SELECT 1.
     *
     * The query matters: a MariaDB that is up but read-only, out of disk, or
     * out of connections still completes a handshake. In production DBDebug is
     * false, which makes CodeIgniter return false from a failed query instead
     * of throwing — so a connect-only check would report that database as
     * healthy while every write silently fails.
     */
    public static function dbOk(): bool
    {
        $mysqli = null;

        try {
            $config = config('Database')->default;

            $mysqli = mysqli_init();
            if ($mysqli === false) {
                return false;
            }

            $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
            $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, self::CONNECT_TIMEOUT_SECONDS);

            $connected = @$mysqli->real_connect(
                (string) ($config['hostname'] ?? 'localhost'),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                (string) ($config['database'] ?? ''),
                (int) ($config['port'] ?? 3306),
            );

            if (! $connected) {
                return false;
            }

            $result = @$mysqli->query('SELECT 1');
            if ($result === false) {
                return false;
            }

            if ($result instanceof \mysqli_result) {
                $result->free();
            }

            return true;
        } catch (\Throwable $e) {
            // mysqli throws mysqli_sql_exception on PHP 8 unless reporting is
            // disabled globally. Deliberately not touching mysqli_report():
            // it is process-wide state the framework's own driver relies on.
            return false;
        } finally {
            if ($mysqli instanceof \mysqli) {
                @$mysqli->close();
            }
        }
    }

    /**
     * Human-readable outage message, shown on the maintenance page.
     *
     * @param list<string> $down
     */
    public static function describe(array $down): string
    {
        if ($down === []) {
            return '';
        }

        $labels = [
            self::REDIS    => 'Redis',
            self::DATABASE => 'Database',
        ];

        $names = array_map(static fn (string $dep): string => $labels[$dep] ?? $dep, $down);

        return implode(' dan ', $names) . ' tidak dapat dijangkau';
    }
}

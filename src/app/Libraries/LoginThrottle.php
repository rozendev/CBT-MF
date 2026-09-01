<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * Satu sumber kebenaran untuk rem login per-IP.
 *
 * Format kunci, penambahan hitungan, penghapusan, dan pendaftaran dipakai oleh
 * LoginRateLimitFilter, AuthController (reset-on-success), command auth:unblock,
 * dan SuspendController. Tidak ada salinan kedua yang bisa menyimpang.
 */
class LoginThrottle
{
    /** Jendela hitung ulang, konstan (lihat spec: knob utama adalah jumlah, bukan durasi). */
    public const WINDOW_SECONDS = 900;

    /** Dipakai bila setting login_ip_max_attempts belum ada di DB. */
    public const DEFAULT_MAX_ATTEMPTS = 50;

    private const PREFIX = 'login_attempts_ip:';

    public static function key(string $ip): string
    {
        return self::PREFIX . $ip;
    }

    /**
     * INCR hitungan IP; pasang TTL pada hit pertama; kembalikan hitungan kini.
     * Null bila Redis tak tersedia. Sengaja TIDAK menelan exception: pemanggil
     * (filter) yang memutuskan fail-open, supaya keputusan A hidup di satu tempat.
     */
    public static function hit(string $ip): ?int
    {
        $redis = RedisClient::getInstance();
        if (!$redis) {
            return null;
        }
        $key   = self::key($ip);
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, self::WINDOW_SECONDS);
        }
        return $count;
    }

    public static function maxAttempts(): int
    {
        return (int) (new SettingModel())->getValue('login_ip_max_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    public static function clearForIp(string $ip): void
    {
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $redis->del(self::key($ip));
            }
        } catch (\Throwable $e) {
            log_message('error', 'LoginThrottle::clearForIp gagal: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,int> ip => hitungan kini, untuk diagnostik admin/CLI.
     */
    public static function activeBlocks(): array
    {
        $out = [];
        try {
            $redis = RedisClient::getInstance();
            if (!$redis) {
                return $out;
            }
            $cursor = null;
            do {
                $keys = $redis->scan($cursor, self::PREFIX . '*', 500);
                if (!is_array($keys)) {
                    break;
                }
                foreach ($keys as $key) {
                    $ip       = substr($key, strlen(self::PREFIX));
                    $out[$ip] = (int) $redis->get($key);
                }
            } while ($cursor !== null && $cursor > 0);
        } catch (\Throwable $e) {
            log_message('error', 'LoginThrottle::activeBlocks gagal: ' . $e->getMessage());
        }
        return $out;
    }

    public static function clearAll(): int
    {
        $removed = 0;
        try {
            $redis = RedisClient::getInstance();
            if (!$redis) {
                return 0;
            }
            $cursor = null;
            do {
                $keys = $redis->scan($cursor, self::PREFIX . '*', 500);
                if (!is_array($keys)) {
                    break;
                }
                foreach ($keys as $key) {
                    $redis->del($key);
                    $removed++;
                }
            } while ($cursor !== null && $cursor > 0);
        } catch (\Throwable $e) {
            log_message('error', 'LoginThrottle::clearAll gagal: ' . $e->getMessage());
        }
        return $removed;
    }
}

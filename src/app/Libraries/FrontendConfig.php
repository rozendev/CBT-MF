<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * FrontendConfig — satu sumber kebenaran konfigurasi yang dikonsumsi frontend JS.
 * Semua nilai ditentukan di backend; JS hanya membaca window.APP_CONFIG
 * (dengan fallback literal lama agar file statis yang belum digenerate tetap berfungsi).
 *
 * Tidak pernah memuat secret: ws_token, honeypot, kredensial DB, dsb.
 */
class FrontendConfig
{
    private static ?array $cache = null;

    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $setting = new SettingModel();

        self::$cache = [
            'base_url'        => rtrim(base_url(), '/'),
            'app_name'        => $setting->getValue('app_name', 'CBT-MF'),
            'app_version'     => $setting->getValue('app_version', '1.30'),
            'app_description' => $setting->getValue('app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)'),
            'site_author'     => $setting->getValue('site_author', 'Sekolah/Lembaga'),
            'websocket_url'   => self::websocketUrl($setting),

            'suspend_timer_seconds' => (int) $setting->getValue('suspend_timer_seconds', 30),

            'keep_alive_ms'         => 30000,
            'queue_poll_ms'         => 5000,
            'proctor_poll_ms'       => 10000,
            'proctor_reconnect_ms'  => 3000,
            'warning_threshold_ms'  => 300000, // 5 menit sisa waktu
            'auto_sync_max_wait_ms' => 180000, // 3 menit tanpa server → auto sync
            'auto_sync_debounce_ms' => 60000,  // 60 detik debounce auto sync
            'fetch_timeout_ms'      => 15000,
            'ws_reconnect_base_ms'  => 1000,
            'ws_reconnect_cap_ms'   => 30000,
            'grace_seconds'         => 60,

            'status' => [
                'banned'   => 2,
                'finished' => 3,
            ],
        ];

        return self::$cache;
    }

    public static function value(string $key, $default = null)
    {
        return self::get()[$key] ?? $default;
    }

    public static function json(): string
    {
        return json_encode(
            self::get(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    /**
     * Tentukan URL WebSocket final: setting websocket_url bila ada,
     * jika tidak/localhost → turunkan dari host permintaan saat ini.
     */
    private static function websocketUrl(SettingModel $setting): string
    {
        $wsUrl = (string) $setting->getValue('websocket_url', '');

        if (!empty($wsUrl) && !str_contains($wsUrl, 'localhost')) {
            return rtrim($wsUrl, '/');
        }

        $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $protocol = $https ? 'wss:' : 'ws:';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        if (str_contains($host, ':8080')) {
            return $protocol . '//' . str_replace(':8080', ':8060', $host) . '/ws';
        }

        return $protocol . '//' . $host . '/ws';
    }
}

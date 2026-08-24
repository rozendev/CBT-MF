<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * Satu sumber kebenaran URL WebSocket untuk SEMUA klien: halaman ujian web,
 * halaman ujian static, bundle kiosk Exambro, dan dashboard pengawas.
 *
 * Sebelum kelas ini ada, cara menurunkan URL ditulis ulang di FrontendConfig,
 * SettingModel, exam-app.js, take.php, dan proctor/live.php -- lima salinan yang
 * bisa saling menyimpang, dan memang sudah menyimpang: versi JS menghasilkan
 * host tanpa path untuk stack dev, versi PHP menambahkan '/ws'. Path dan port
 * masih punya default, tapi sekarang hanya tertulis di sini.
 *
 * Penurunan sengaja memakai HOST saja, tanpa subpath aplikasi: proxy `/ws`
 * dipasang di root host (lihat docker/nginx/default.conf). Ini mempertahankan
 * perilaku yang sudah berjalan, bukan menambah asumsi baru.
 */
final class WebSocketUrl
{
    /** Path proxy WebSocket. Harus cocok dengan `location /ws/` di nginx. */
    public const DEFAULT_PATH = '/ws';

    /**
     * Pemetaan port khusus stack pengembangan: nginx dipublikasikan di 8080,
     * server Ratchet langsung di 8060 tanpa lewat proxy.
     */
    public const DEV_PORT_MAP = ['8080' => '8060'];

    /**
     * URL final yang harus dipakai klien. Setting menang; kalau kosong atau
     * masih menunjuk localhost, turunkan dari base URL aplikasi.
     */
    public static function resolve(?SettingModel $setting = null): string
    {
        $setting ??= new SettingModel();

        return self::pick(
            (string) $setting->getValue('websocket_url', ''),
            (string) base_url()
        );
    }

    /** Apakah nilai final berasal dari setting admin, bukan diturunkan. */
    public static function isConfigured(?SettingModel $setting = null): bool
    {
        $setting ??= new SettingModel();
        $configured = trim((string) $setting->getValue('websocket_url', ''));

        return $configured !== '' && !str_contains($configured, 'localhost');
    }

    /** Logika murni: setting menang kecuali kosong atau menunjuk localhost. */
    public static function pick(string $configured, string $baseUrl): string
    {
        $configured = trim($configured);

        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            return self::normalize($configured);
        }

        return self::derive($baseUrl);
    }

    /** Logika murni: turunkan URL WebSocket dari base URL aplikasi. */
    public static function derive(string $baseUrl): string
    {
        $parts  = parse_url(trim($baseUrl));
        $scheme = (($parts['scheme'] ?? 'http') === 'https') ? 'wss' : 'ws';
        $host   = $parts['host'] ?? 'localhost';
        $port   = isset($parts['port']) ? (string) $parts['port'] : '';

        if ($port !== '' && isset(self::DEV_PORT_MAP[$port])) {
            $port = self::DEV_PORT_MAP[$port];
        }

        $authority = $host . ($port !== '' ? ':' . $port : '');

        return $scheme . '://' . $authority . self::DEFAULT_PATH;
    }

    /** Logika murni: buang slash berlebih di ujung. */
    public static function normalize(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}

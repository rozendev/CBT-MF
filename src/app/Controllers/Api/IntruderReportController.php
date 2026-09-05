<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\IntruderReportModel;

class IntruderReportController extends BaseController
{
    protected const MAX_PHOTO_BYTES = 1048576; // 1 MB

    protected const MAX_PHOTOS_PER_IP_PER_DAY = 50;

    protected const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public function report()
    {
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body)) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid payload']);
            }

            // Tidak ada token bawaan. Repositori ini publik, jadi token yang
            // tertulis di dalamnya sama saja dengan tanpa token: siapa pun bisa
            // memalsukan laporan penyusup pada pemasangan yang belum mengaturnya.
            // Kalau INTRUDER_TOKEN kosong, gerbang ini menutup sepenuhnya —
            // honeypot mati lebih baik daripada honeypot yang bisa dibanjiri.
            $token = trim((string) env('INTRUDER_TOKEN', ''));
            if ($token === '') {
                log_message('warning', 'Laporan penyusup ditolak: INTRUDER_TOKEN belum diatur di .env.');

                return $this->response->setStatusCode(503)->setJSON(['status' => 'error', 'message' => 'Service unavailable']);
            }

            if (!hash_equals($token, (string) ($body['token'] ?? ''))) {
                return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Forbidden']);
            }

            $photoPath = $this->savePhoto($body['photo'] ?? '');
            if ($photoPath !== null && !$this->withinPhotoQuota()) {
                @unlink(FCPATH . 'uploads/intruder/' . $photoPath);
                return $this->response->setStatusCode(429)->setJSON(['status' => 'error', 'message' => 'Melebihi batas laporan harian.']);
            }

            $ip = $this->detectIp();

            (new IntruderReportModel())->record([
                'photo_path'   => $photoPath,
                'latitude'     => $this->validDecimal($body['latitude'] ?? null),
                'longitude'    => $this->validDecimal($body['longitude'] ?? null),
                'accuracy'     => $this->validDecimal($body['accuracy'] ?? null),
                'ip_address'   => $ip,
                'user_agent'   => mb_substr((string) ($body['ua'] ?? $this->request->getUserAgent()), 0, 2000),
                'requested_uri'=> mb_substr((string) ($body['uri'] ?? ''), 0, 500),
                'referer'      => mb_substr((string) ($body['referer'] ?? ''), 0, 500),
                'screen'       => $this->validScreen($body['screen'] ?? null),
                'platform'     => mb_substr((string) ($body['platform'] ?? ''), 0, 100),
            ]);

            return $this->response->setJSON(['status' => 'ok']);
        } catch (\Throwable $e) {
            log_message('error', 'IntruderReport ERROR: ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Internal error']);
        }
    }

    private function savePhoto($dataUrl): ?string
    {
        if (!is_string($dataUrl) || !str_starts_with($dataUrl, 'data:image/')) {
            return null;
        }

        $comma = strpos($dataUrl, ',');
        if ($comma === false) {
            return null;
        }

        $meta = substr($dataUrl, 5, $comma - 5);
        $mime = strtok($meta, ';');

        if (!isset(self::ALLOWED_MIMES[$mime])) {
            return null;
        }

        $raw = base64_decode(substr($dataUrl, $comma + 1), true);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_PHOTO_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($raw);
        if ($info === false) {
            return null;
        }

        $ext = self::ALLOWED_MIMES[$info['mime']] ?? self::ALLOWED_MIMES[$mime];
        $dir = FCPATH . 'uploads/intruder';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $raw, LOCK_EX);

        return $name;
    }

    private function detectIp(): string
    {
        $request = service('request');

        $cf = $request->getHeaderLine('CF-Connecting-IP');
        if (filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }

        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return $request->getIPAddress();
    }

    private function validDecimal($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Resolusi layar hanya boleh berbentuk "LEBARxTINGGI" (lihat halaman
     * honeypot: window.screen.width + 'x' + window.screen.height). Apa pun
     * selain itu dibuang, bukan disimpan apa adanya — nilai ini berasal dari
     * request tanpa autentikasi dan ditampilkan di halaman admin.
     */
    private function validScreen($value): string
    {
        $value = (string) $value;

        return preg_match('/^\d{1,5}x\d{1,5}$/D', $value) === 1 ? $value : '';
    }

    private function withinPhotoQuota(): bool
    {
        try {
            $ip = $this->detectIp();
            $key = 'intruder_photos:' . md5($ip) . ':' . date('Ymd');
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $count = (int) $redis->incr($key);
                if ($count === 1) {
                    $redis->expire($key, 86400);
                }
                return $count <= self::MAX_PHOTOS_PER_IP_PER_DAY;
            }
        } catch (\Throwable $e) {
            log_message('error', 'IntruderReport quota check error: ' . $e->getMessage());
        }
        return true;
    }
}
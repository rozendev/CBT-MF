<?php

namespace App\Libraries;

use App\Models\KioskBannedDeviceModel;

/**
 * Blokir satu perangkat dari menjalankan aplikasi ujian.
 *
 * Menyasar perangkat, BUKAN akun: siswa yang perangkatnya diblokir tetap bisa
 * melanjutkan di perangkat lain bila pengawas mengizinkan. Untuk menghukum
 * akun, yang dipakai tetap ProctorAction::lockAccount().
 *
 * Sumber kebenaran ada di MariaDB. Redis hanya cache per-perangkat, karena
 * `cbt.sh redis flush` adalah perintah yang memang ada dan memang dipakai —
 * kalau ban hanya hidup di Redis, perintah itu akan membuka semua blokir tanpa
 * suara. Kendali keamanan tidak boleh punya jalur gagal-terbuka yang sunyi.
 */
class DeviceBan
{
    /**
     * Umur cache. Pendek karena satu-satunya yang dibayar saat kedaluwarsa
     * adalah satu kueri primary-key. Ban dan unlock tidak menunggu TTL ini:
     * keduanya menghapus kunci cache-nya langsung.
     */
    public const CACHE_TTL_SECONDS = 30;

    /**
     * Batas yang sama dengan yang sudah dipakai kiosk-heartbeat.php
     * (`substr(..., 0, 64)`) dan KioskController (`[a-zA-Z0-9_-]`, `<= 64`).
     */
    public static function isValidDeviceId(string $raw): bool
    {
        return $raw !== ''
            && strlen($raw) <= 64
            && preg_match('/^[A-Za-z0-9_-]+$/', $raw) === 1;
    }

    public static function cacheKey(string $deviceId): string
    {
        return 'kiosk_device_ban:' . $deviceId;
    }

    /**
     * Terjemahkan isi cache menjadi keputusan.
     *
     * null berarti "belum tahu, tanya database" — BUKAN "tidak terblokir".
     * Nilai yang tidak dikenal ikut diperlakukan sebagai belum tahu, supaya
     * cache yang rusak atau baru dikosongkan tidak pernah membuka blokir.
     */
    public static function decideFromCache(?string $cached): ?bool
    {
        if ($cached === '1') {
            return true;
        }
        if ($cached === '0') {
            return false;
        }

        return null;
    }
}

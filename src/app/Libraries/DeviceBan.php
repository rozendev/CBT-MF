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
     *
     * src/public/kiosk-heartbeat.php menyalin angka ini sebagai literal 30
     * pada setex()-nya sendiri, karena berkas itu bebas framework dan tidak
     * bisa memakai konstanta ini. Ubah salah satu, cek yang lain.
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
            // \A dan \z, bukan ^ dan $: dalam PCRE, $ ikut cocok TEPAT SEBELUM
            // newline di ujung, sehingga "abc\n" akan lolos sebagai id yang sah
            // dan menyelundupkan newline ke dalam kunci Redis lewat cacheKey().
            && preg_match('/\A[A-Za-z0-9_-]+\z/', $raw) === 1;
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

    /**
     * Apakah perangkat ini sedang terblokir.
     *
     * Cache dulu, database kalau cache dingin. Redis mati bukan alasan untuk
     * melewatkan pemeriksaan: jatuh ke database, lebih lambat tapi tetap benar.
     */
    public static function isBanned(string $deviceId): bool
    {
        if (!self::isValidDeviceId($deviceId)) {
            // Tidak ada yang bisa dicocokkan. Diperlakukan seperti perangkat
            // yang tidak mengirim identitas sama sekali — lolos di sini, dan
            // tertangkap heartbeat begitu APK-nya diperbarui.
            return false;
        }

        $redis = null;
        try {
            $redis = RedisClient::getInstance();
        } catch (\Throwable $e) {
            $redis = null;
        }

        if ($redis !== null) {
            try {
                $cached = $redis->get(self::cacheKey($deviceId));
                $decision = self::decideFromCache($cached === false ? null : (string) $cached);
                if ($decision !== null) {
                    return $decision;
                }
            } catch (\Throwable $e) {
                log_message('warning', 'DeviceBan: gagal baca cache: ' . $e->getMessage());
            }
        }

        $banned = (new KioskBannedDeviceModel())->activeFor($deviceId) !== null;

        if ($redis !== null) {
            try {
                $redis->setex(self::cacheKey($deviceId), self::CACHE_TTL_SECONDS, $banned ? '1' : '0');
            } catch (\Throwable $e) {
                log_message('warning', 'DeviceBan: gagal isi cache: ' . $e->getMessage());
            }
        }

        return $banned;
    }

    /**
     * Buang cache satu perangkat supaya ban atau unlock berlaku seketika
     * alih-alih menunggu TTL habis.
     */
    public static function forget(string $deviceId): void
    {
        if (!self::isValidDeviceId($deviceId)) {
            return;
        }

        try {
            $redis = RedisClient::getInstance();
            if ($redis !== null) {
                $redis->del(self::cacheKey($deviceId));
            }
        } catch (\Throwable $e) {
            log_message('warning', 'DeviceBan: gagal buang cache: ' . $e->getMessage());
        }
    }

    /**
     * Blokir satu perangkat. Kalau sudah ada baris aktif untuk perangkat ini,
     * baris itu diperbarui alasannya di tempat — tapi ini bukan jaminan
     * idempoten tanpa syarat: di bawah konkurensi, dua pemanggilan bisa
     * sama-sama tidak melihat baris aktif yang lain dan sama-sama insert.
     * unlock() menutup SEMUA baris aktif sekaligus, jadi duplikat semacam
     * itu tidak berbahaya.
     *
     * @return array{ok:bool, message:string}
     */
    public static function ban(
        string $deviceId,
        string $reason,
        int $actorId,
        ?int $lastUserId = null,
        ?int $lastTestId = null
    ): array {
        if (!self::isValidDeviceId($deviceId)) {
            return ['ok' => false, 'message' => 'ID perangkat tidak sah.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Alasan wajib diisi.'];
        }
        $reason = mb_substr($reason, 0, 255);

        $model = new KioskBannedDeviceModel();
        $db    = \Config\Database::connect();

        $db->transStart();

        $existing = $model->activeFor($deviceId);
        if ($existing !== null) {
            $model->update($existing->id, ['reason' => $reason]);
        } else {
            $model->insert([
                'device_id'    => $deviceId,
                'reason'       => $reason,
                'banned_by'    => $actorId,
                'banned_at'    => date('Y-m-d H:i:s'),
                'last_user_id' => $lastUserId,
                'last_test_id' => $lastTestId,
            ]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['ok' => false, 'message' => 'Gagal menyimpan blokir perangkat.'];
        }

        self::forget($deviceId);

        try {
            (new \App\Models\ActivityLogModel())->log(
                'device_ban',
                $actorId,
                'device',
                null,
                'Memblokir perangkat ' . substr($deviceId, 0, 8) . '… — ' . $reason
            );
        } catch (\Throwable $e) {
            log_message('error', 'DeviceBan audit gagal: ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => 'Perangkat diblokir.'];
    }

    /**
     * Buka blokir. Idempoten: perangkat yang tidak terblokir bukan galat.
     *
     * @return array{ok:bool, message:string}
     */
    public static function unlock(string $deviceId, int $actorId): array
    {
        if (!self::isValidDeviceId($deviceId)) {
            return ['ok' => false, 'message' => 'ID perangkat tidak sah.'];
        }

        $model    = new KioskBannedDeviceModel();
        $existing = $model->activeFor($deviceId);

        if ($existing === null) {
            self::forget($deviceId);

            return ['ok' => true, 'message' => 'Perangkat memang tidak terblokir.'];
        }

        // Menutup SEMUA baris aktif untuk perangkat ini, bukan cuma baris
        // $existing yang barusan ditemukan: kalau ban() pernah race dan
        // sempat menghasilkan dua baris aktif untuk perangkat yang sama,
        // menutup satu baris saja membuat unlock() melaporkan sukses padahal
        // isBanned() tetap true karena baris aktif yang lain masih ada —
        // pengawas dibohongi, dan perangkat yang "sudah dibuka" itu
        // dikembalikan ke siswa tapi tetap terkunci.
        //
        // Nilai kembalian update() DIPERIKSA, bukan dibuang, dan dua bentuk
        // kegagalan ditangkap: exception (DBDebug menyala, lingkungan
        // pengembangan) maupun return false (DBDebug mati di produksi —
        // lihat Config\Database::DBDebug). Kalau ini diabaikan, lock
        // timeout atau koneksi yang putus akan membuat kode ini lolos ke
        // jalur sukses: cache terhapus, audit "device_unban" tertulis, dan
        // fungsi menjawab ok:true — padahal barisnya masih unlocked_at IS
        // NULL. Itu persis kebohongan "melaporkan sukses padahal masih
        // terblokir" yang tadi diperbaiki di level baris ganda, muncul lagi
        // satu lapis di atas.
        try {
            $updated = $model->where('device_id', $deviceId)
                ->where('unlocked_at', null)
                ->update(null, [
                    'unlocked_by' => $actorId,
                    'unlocked_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Throwable $e) {
            $updated = false;
            log_message('error', 'DeviceBan: gagal menulis unlock: ' . $e->getMessage());
        }

        // Cache tetap dihapus di kedua kasus, sukses maupun gagal. Kalau
        // penulisannya gagal dan barisnya ternyata masih aktif, menghapus
        // kunci cache tidak membuka blokir yang sesungguhnya: isBanned()
        // berikutnya jatuh ke database (cache dingin) dan tetap benar
        // membaca true. Membiarkan cache basi di sana tidak ada untungnya,
        // jadi dihapus saja supaya pembacaan berikutnya dijamin dari sumber
        // kebenaran, bukan dari nilai lama yang sudah tidak bisa dipercaya.
        self::forget($deviceId);

        if ($updated === false) {
            return ['ok' => false, 'message' => 'Gagal membuka blokir perangkat.'];
        }

        try {
            (new \App\Models\ActivityLogModel())->log(
                'device_unban',
                $actorId,
                'device',
                null,
                'Membuka blokir perangkat ' . substr($deviceId, 0, 8) . '…'
            );
        } catch (\Throwable $e) {
            log_message('error', 'DeviceBan audit gagal: ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => 'Blokir perangkat dibuka.'];
    }
}

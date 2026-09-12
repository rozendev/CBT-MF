<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Kode keluar offline: 8 digit harian yang diturunkan dari kiosk_exit_password.
 *
 * Perangkat TIDAK pernah menerima password-nya. Yang dikirim adalah amplop
 * berisi hash PBKDF2 dari kode beberapa hari ke depan, sehingga HP yang
 * dibongkar membocorkan paling jauh sepekan kode — bukan password pengawas,
 * yang juga menjaga jalur keluar normal (/api/kiosk/verify-exit).
 *
 * Kelas ini SENGAJA murni: tanpa service(), model, atau helper CodeIgniter,
 * supaya dapat diuji dengan bootstrap ringan tanpa memuat framework.
 */
final class KioskOfflineCode
{
    /**
     * Dipaku, bukan mengikuti zona perangkat: kalau ikut zona perangkat, siswa
     * cukup mengubah setelan zona untuk menggeser kode yang berlaku.
     */
    public const TIMEZONE = 'Asia/Jakarta';

    public const PREFIX = 'cbtmf-offline-exit:';

    public const ENVELOPE_DAYS = 7;

    /**
     * Ruang kode hanya 10^8. Dengan hash cepat, penyerang yang memegang amplop
     * memulihkan kodenya dalam hitungan detik; 120k iterasi membuat satu
     * tebakan berbiaya ~100 ms sehingga menyisir seluruh ruang tidak praktis,
     * sementara satu verifikasi yang sah tetap terasa seketika.
     */
    public const PBKDF2_ITERATIONS = 120000;

    public const MIN_STRONG_LENGTH = 12;

    public const MAX_EVENTS_PER_REQUEST = 20;

    private const COMMON_PASSWORDS = [
        '123456', '1234567', '12345678', '123456789', '1234567890',
        'password', 'qwerty', 'admin', 'administrator', 'guru', 'sekolah',
        'ujian', 'kiosk', 'pengawas', '111111', '000000', 'abc123',
    ];

    /**
     * Kode 8 digit untuk satu hari. Truncation dinamis RFC 4226 dipakai
     * alih-alih "ambil 4 byte pertama" karena ia standar dan menghilangkan
     * pertanyaan byte mana yang diambil saat diimplementasi ulang.
     */
    public static function codeForDay(string $password, string $day): string
    {
        $mac = hash_hmac('sha256', self::PREFIX . $day, $password, true);

        $offset = ord($mac[31]) & 0x0F;
        $binary = ((ord($mac[$offset]) & 0x7F) << 24)
                | ((ord($mac[$offset + 1]) & 0xFF) << 16)
                | ((ord($mac[$offset + 2]) & 0xFF) << 8)
                |  (ord($mac[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 100000000), 8, '0', STR_PAD_LEFT);
    }

    /** Daftar tanggal YYYY-MM-DD mulai hari ini di zona sekolah. */
    public static function upcomingDays(int $count = self::ENVELOPE_DAYS, ?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(self::TIMEZONE));

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $days[] = $today->modify(sprintf('+%d day', $i))->format('Y-m-d');
        }

        return $days;
    }

    /**
     * Amplop untuk perangkat.
     *
     * Salt disimpan sebagai string hex dan diteruskan apa adanya ke
     * hash_pbkdf2 — BUKAN di-decode lebih dulu. Sisi Kotlin harus memakai
     * byte UTF-8 dari string yang sama; men-decode hex di salah satu sisi
     * membuat kedua implementasi tidak akan pernah sepakat.
     */
    public static function buildEnvelope(string $password, ?DateTimeImmutable $now = null): array
    {
        $entries = [];

        foreach (self::upcomingDays(self::ENVELOPE_DAYS, $now) as $day) {
            $salt = bin2hex(random_bytes(16));
            $entries[] = [
                'day'  => $day,
                'salt' => $salt,
                'hash' => hash_pbkdf2('sha256', self::codeForDay($password, $day), $salt, self::PBKDF2_ITERATIONS, 64),
            ];
        }

        return $entries;
    }

    /**
     * Alasan kelemahan password; array kosong berarti cukup kuat.
     *
     * Seluruh kekuatan kode offline bertumpu pada entropi password ini, karena
     * algoritmanya ada di dalam APK yang bisa dibongkar siapa pun. Pemeriksaan
     * ini hanya MEMPERINGATKAN dan tidak pernah memblokir — itu keputusan sadar
     * pemilik produk, dicatat di §2.1 spec.
     */
    public static function passwordWeaknesses(string $password): array
    {
        if ($password === '') {
            return ['Password keluar kiosk belum diisi.'];
        }

        $reasons = [];

        if ($password === '123456') {
            $reasons[] = 'Masih memakai password bawaan 123456.';
        }
        if (mb_strlen($password) < self::MIN_STRONG_LENGTH) {
            $reasons[] = 'Kurang dari ' . self::MIN_STRONG_LENGTH . ' karakter.';
        }
        if (in_array(mb_strtolower($password), self::COMMON_PASSWORDS, true)) {
            $reasons[] = 'Termasuk password yang umum ditebak.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Membersihkan daftar event audit dari perangkat. Rute pelapornya tidak
     * terautentikasi, jadi apa pun yang masuk diperlakukan sebagai data asing.
     */
    public static function sanitizeEvents($events): array
    {
        if (!is_array($events)) {
            return [];
        }

        $clean = [];
        foreach ($events as $event) {
            if (count($clean) >= self::MAX_EVENTS_PER_REQUEST) {
                break;
            }
            if (!is_array($event)) {
                continue;
            }

            $at      = $event['at'] ?? null;
            $codeDay = (string) ($event['code_day'] ?? '');
            $version = (string) ($event['app_version'] ?? '');

            if (!is_int($at) && !(is_string($at) && ctype_digit($at))) {
                continue;
            }
            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $codeDay) !== 1) {
                continue;
            }

            $clean[] = [
                'at'          => (int) $at,
                'code_day'    => $codeDay,
                'app_version' => mb_substr(preg_replace('/[^0-9A-Za-z._-]/', '', $version) ?? '', 0, 32),
            ];
        }

        return $clean;
    }
}

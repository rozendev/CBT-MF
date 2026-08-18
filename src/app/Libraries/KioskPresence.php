<?php

namespace App\Libraries;

/**
 * Gerbang "ujian ini wajib dikerjakan lewat aplikasi kiosk".
 *
 * Buktinya bukan rahasia di dalam APK — apa pun yang disembunyikan di sana
 * bisa dibongkar — melainkan KONSISTENSI: hanya aplikasi native yang mengirim
 * heartbeat 15 detikan ke /kiosk-heartbeat.php, dan heartbeat itu menulis
 * kiosk_live:{testId}:{userId} di Redis. Tab browser biasa tidak punya jejak
 * itu sama sekali, jadi tulisan jawabannya ditolak.
 *
 * Ini sengaja TIDAK bergantung pada deteksi root atau attestation perangkat:
 * perangkat pengujian sekolah ini di-root, dan gerbang yang memblokir root
 * akan memblokir pengujiannya sendiri.
 *
 * Batasnya jujur: siswa yang mau menulis skrip heartbeat sendiri tetap bisa
 * lolos. Yang ditutup di sini adalah jalur termudah — "buka Chrome, kerjakan
 * ujian" — dan setiap penyimpangan meninggalkan catatan di exam_kiosk_events.
 */
class KioskPresence
{
    /**
     * Heartbeat dianggap hidup bila umurnya di bawah ini. Aplikasi mengirim
     * tiap 15 detik dan mundur ke 30 detik saat jaringan bermasalah, jadi 75
     * detik memberi ruang dua kali gagal berturut-turut tanpa menendang siswa.
     */
    public const STALE_SECONDS = 75;

    /**
     * Jeda sesudah attempt dibuat sebelum gerbang mulai berlaku. Heartbeat
     * pertama baru terkirim ~15 detik setelah halaman ujian siap, dan token-nya
     * sendiri baru lahir di exam/init — tanpa jeda ini setiap ujian akan
     * tertolak pada detik pertamanya.
     */
    public const GRACE_SECONDS = 120;

    public static function isRequired($test): bool
    {
        return $test !== null && (int) ($test->require_kiosk ?? 0) === 1;
    }

    /**
     * @param object|null $test    Baris tests (butuh require_kiosk).
     * @param object|null $attempt Baris test_attempts (butuh started_at/created_at).
     *
     * @return array{ok: bool, reason: string} reason kosong bila lolos.
     */
    public static function check($test, $attempt, int $userId): array
    {
        if (!self::isRequired($test)) {
            return ['ok' => true, 'reason' => ''];
        }

        $startedAt = $attempt->started_at ?? $attempt->created_at ?? null;
        if ($startedAt !== null) {
            $startedTs = strtotime((string) $startedAt);
            if ($startedTs !== false && (time() - $startedTs) < self::GRACE_SECONDS) {
                return ['ok' => true, 'reason' => ''];
            }
        }

        $redis = RedisClient::getInstance();
        if (!$redis) {
            // Redis tumbang bukan salah siswa. Gagal-terbuka, tapi catat:
            // selama Redis mati, gerbang ini memang tidak menjaga apa pun.
            log_message('warning', 'KioskPresence: Redis tidak tersedia, gerbang kiosk dilewati.');
            return ['ok' => true, 'reason' => ''];
        }

        try {
            $ts = $redis->hGet("kiosk_live:" . (int) $test->id . ":" . $userId, 'ts');
        } catch (\Throwable $e) {
            log_message('warning', 'KioskPresence: gagal baca heartbeat: ' . $e->getMessage());
            return ['ok' => true, 'reason' => ''];
        }

        if ($ts === false || $ts === null || $ts === '') {
            return ['ok' => false, 'reason' => 'no_heartbeat'];
        }
        if ((time() - (int) $ts) > self::STALE_SECONDS) {
            return ['ok' => false, 'reason' => 'stale_heartbeat'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    public static function message(): string
    {
        return 'Ujian ini hanya dapat dikerjakan lewat aplikasi CBT Kiosk. '
            . 'Jawaban yang sudah masuk tetap tersimpan — buka aplikasi kiosk untuk melanjutkan.';
    }

    /**
     * Catat penolakan supaya terlihat pengawas. Best-effort: kegagalan audit
     * tidak boleh ikut menggagalkan request.
     */
    public static function audit(int $testId, int $userId, string $reason, string $endpoint): void
    {
        try {
            \Config\Database::connect()->table('exam_kiosk_events')->insert([
                'exam_session_id' => $testId,
                'student_id'      => $userId,
                'event_type'      => 'kiosk_absent',
                'event_details'   => json_encode([
                    'reason'   => $reason,
                    'endpoint' => $endpoint,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'KioskPresence audit gagal: ' . $e->getMessage());
        }
    }
}

<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * Satu-satunya pembaca setelan `prevent_multi_login`.
 *
 * Sebelumnya ada dua pembaca untuk SATU kunci cache yang sama, dan ketiganya
 * berbeda:
 *
 *   AuthController   getValue() -> nilai bertipe, TTL 3600, default AKTIF
 *   MultiLoginFilter cache->get() mentah -> harap string, TTL 300, default MATI
 *
 * Filter mematikan diri hanya bila `$isEnabled === '0'`. Ketika setelannya
 * bertipe boolean dan bernilai false, getValue menyimpan `false` ke kunci yang
 * sama, lalu `false === '0'` bernilai false — jadi filter TETAP menegakkan
 * sesudah admin mematikannya. Kedua TTL juga saling menimpa: yang menulis
 * terakhir menentukan berapa lama nilainya bertahan.
 *
 * Sekarang keduanya lewat sini: satu kunci, satu tipe, satu TTL, satu default.
 */
final class MultiLoginPolicy
{
    public const SETTING_KEY = 'prevent_multi_login';

    /**
     * Default ketika barisnya belum ada. AKTIF, mengikuti AuthController.
     *
     * Sengaja bukan default filter yang lama (mati): sebuah baris yang
     * kebetulan belum pernah ditulis tidak boleh berarti perlindungan
     * multi-login ikut mati diam-diam.
     */
    public const DEFAULT_ENABLED = true;

    public static function isEnabled(?SettingModel $settings = null): bool
    {
        $settings ??= new SettingModel();

        try {
            return self::interpret($settings->getValue(self::SETTING_KEY, self::DEFAULT_ENABLED));
        } catch (\Throwable $e) {
            // Gagal TERTUTUP. Tidak terbacanya setelan bukan izin untuk
            // berhenti menegakkan — penegakan baru menggigit kalau Redis
            // memang melaporkan sesi yang direbut perangkat lain.
            log_message('error', 'MultiLoginPolicy gagal membaca setelan: ' . $e->getMessage());

            return self::DEFAULT_ENABLED;
        }
    }

    /**
     * Nilai setelan bisa sampai sebagai bool (baris bertipe boolean), string
     * ('0'/'1', bila barisnya terlanjur tersimpan bertipe string), atau int.
     * Justru perbandingan yang mengasumsikan satu bentuk saja yang dulu
     * membuat sakelar admin tidak berefek.
     */
    public static function interpret($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return self::DEFAULT_ENABLED;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

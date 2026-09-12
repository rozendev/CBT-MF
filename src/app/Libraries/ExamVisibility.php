<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * Satu-satunya tempat yang memutuskan apakah siswa boleh melihat nilai, kunci
 * jawaban, dan review sesudah ujian.
 *
 * Dulu keputusan yang sama disalin di empat tempat, dan salinannya menyimpang:
 * daftar ujian (dashboard web dan API) memakai default global `false`,
 * sedangkan halaman hasil dan endpoint review memakai `true`. Karena ketiga
 * baris setelannya memang tidak pernah di-seed, yang berlaku adalah default
 * kode — jadi tombolnya disembunyikan sementara halamannya sendiri terbuka.
 *
 * Defaultnya sengaja MENUTUP. Kunci jawaban yang bocor tidak bisa ditarik
 * kembali, jadi membukanya harus berupa tindakan sadar seorang admin, bukan
 * akibat sebuah baris yang kebetulan belum ada.
 */
final class ExamVisibility
{
    public const DEFAULT_SHOW_SCORE   = false;
    public const DEFAULT_SHOW_CORRECT = false;
    public const DEFAULT_ALLOW_REVIEW = false;

    /**
     * Nilai per-ujian menang; `null` berarti "ikut setelan global".
     *
     * Perhatikan bedanya `null` dengan `0`: kolom yang di-set 0 adalah
     * keputusan guru untuk MENUTUP ujian itu, dan tidak boleh diam-diam
     * ditimpa setelan global yang membuka.
     */
    public static function resolve($testValue, bool $global): bool
    {
        return $testValue !== null ? (bool) $testValue : $global;
    }

    /**
     * Ketiga setelan global sekaligus, dengan default yang sama untuk semua
     * pemanggil. Meneruskan model dari luar membuat pemanggil yang sudah punya
     * instance tidak perlu membuat yang kedua.
     *
     * @return array{show_score: bool, show_correct: bool, allow_review: bool}
     */
    public static function globals(?SettingModel $settings = null): array
    {
        $settings ??= new SettingModel();

        return [
            'show_score'   => (bool) $settings->getValue('show_score_after_exam', self::DEFAULT_SHOW_SCORE),
            'show_correct' => (bool) $settings->getValue('show_correct_answers', self::DEFAULT_SHOW_CORRECT),
            'allow_review' => (bool) $settings->getValue('allow_review', self::DEFAULT_ALLOW_REVIEW),
        ];
    }
}

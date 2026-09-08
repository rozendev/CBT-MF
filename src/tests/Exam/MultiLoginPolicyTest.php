<?php

namespace Tests\Exam;

use App\Libraries\MultiLoginPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Penafsiran nilai setelan prevent_multi_login.
 *
 * Justru bagian inilah yang dulu rusak: filter membandingkan nilainya dengan
 * string `'0'`, sedangkan setelan bertipe boolean sampai ke sana sebagai
 * `false`. `false === '0'` bernilai false, jadi sakelar admin yang sudah
 * dimatikan tidak pernah mematikan penegakan.
 */
final class MultiLoginPolicyTest extends TestCase
{
    /**
     * Bentuk apa pun yang bisa keluar dari SettingModel harus dibaca sama.
     */
    public function testBentukMatiSelaluDibacaMati(): void
    {
        foreach ([false, '0', 0, '', 'false', 'off', 'no'] as $nilai) {
            $this->assertFalse(
                MultiLoginPolicy::interpret($nilai),
                'Nilai ' . var_export($nilai, true) . ' seharusnya dibaca MATI'
            );
        }
    }

    public function testBentukAktifSelaluDibacaAktif(): void
    {
        foreach ([true, '1', 1, 'true', 'on', 'yes'] as $nilai) {
            $this->assertTrue(
                MultiLoginPolicy::interpret($nilai),
                'Nilai ' . var_export($nilai, true) . ' seharusnya dibaca AKTIF'
            );
        }
    }

    public function testNilaiKosongJatuhKeDefaultAktif(): void
    {
        // Baris yang tidak ada tidak boleh berarti perlindungan ikut mati.
        $this->assertTrue(MultiLoginPolicy::DEFAULT_ENABLED);
        $this->assertSame(MultiLoginPolicy::DEFAULT_ENABLED, MultiLoginPolicy::interpret(null));
    }

    public function testKunciSetelanTidakBerubah(): void
    {
        // Kunci ini juga menentukan kunci cache SettingModel
        // (`setting_prevent_multi_login`); mengubahnya diam-diam berarti
        // pembaca lama dan baru kembali menunjuk tempat yang berbeda.
        $this->assertSame('prevent_multi_login', MultiLoginPolicy::SETTING_KEY);
    }
}

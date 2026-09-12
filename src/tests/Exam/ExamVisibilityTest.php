<?php

namespace Tests\Exam;

use App\Libraries\ExamVisibility;
use PHPUnit\Framework\TestCase;

/**
 * Aturan siapa yang boleh melihat nilai dan kunci jawaban sesudah ujian.
 *
 * Diuji terpisah dari database dan cache karena inilah bagian yang salah
 * menyimpulkannya berarti kunci jawaban terbuka untuk siswa yang belum
 * selesai — atau sebaliknya, siswa terkunci dari hasilnya sendiri.
 */
final class ExamVisibilityTest extends TestCase
{
    public function testNilaiPerUjianMenangAtasSetelanGlobal(): void
    {
        $this->assertTrue(ExamVisibility::resolve(1, false));
        $this->assertFalse(ExamVisibility::resolve(0, true));
    }

    public function testNolPerUjianBukanBerartiIkutGlobal(): void
    {
        // Kolom yang di-set 0 adalah keputusan guru untuk MENUTUP ujian itu.
        // Memperlakukannya sebagai "belum diisi" akan membuat setelan global
        // yang membuka diam-diam menimpa keputusan tersebut.
        $this->assertFalse(ExamVisibility::resolve(0, true));
        $this->assertFalse(ExamVisibility::resolve('0', true));
    }

    public function testNullBerartiIkutSetelanGlobal(): void
    {
        $this->assertTrue(ExamVisibility::resolve(null, true));
        $this->assertFalse(ExamVisibility::resolve(null, false));
    }

    public function testDefaultGlobalMenutup(): void
    {
        // Kunci jawaban yang bocor tidak bisa ditarik kembali, jadi membukanya
        // harus berupa tindakan sadar admin. Ketiganya sengaja sama supaya
        // tidak ada lagi jalur yang membuka sementara jalur lain menutup.
        $this->assertFalse(ExamVisibility::DEFAULT_SHOW_SCORE);
        $this->assertFalse(ExamVisibility::DEFAULT_SHOW_CORRECT);
        $this->assertFalse(ExamVisibility::DEFAULT_ALLOW_REVIEW);
    }
}

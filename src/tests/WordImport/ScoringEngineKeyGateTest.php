<?php

namespace Tests\WordImport;

use App\Libraries\ScoringEngine;
use PHPUnit\Framework\TestCase;

class ScoringEngineKeyGateTest extends TestCase
{
    private function invokeHasUsableKey(array $answers): bool
    {
        // ScoringEngine menyentuh database hanya di method publiknya;
        // kelasnya sendiri bisa dibuat tanpa koneksi.
        $method = new \ReflectionMethod(ScoringEngine::class, 'hasUsableKey');
        $method->setAccessible(true);

        return $method->invoke(new ScoringEngine(), $answers);
    }

    public function testNoAnswerRowsIsNotUsable(): void
    {
        // Jejak form manual: tidak ada baris jawaban tersimpan.
        $this->assertFalse($this->invokeHasUsableKey([]));
    }

    public function testBlankAnswerRowIsNotUsable(): void
    {
        // Jejak impor Word: baris ada, tapi description-nya kosong.
        $this->assertFalse($this->invokeHasUsableKey([
            (object) ['answer_text' => ''],
            (object) ['answer_text' => '   '],
            (object) ['answer_text' => null],
        ]));
    }

    public function testFilledAnswerRowIsUsable(): void
    {
        $this->assertTrue($this->invokeHasUsableKey([
            (object) ['answer_text' => 'Ir. Soekarno'],
        ]));
    }
}

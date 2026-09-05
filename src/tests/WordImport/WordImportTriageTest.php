<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordImportTriage;
use PHPUnit\Framework\TestCase;

class WordImportTriageTest extends TestCase
{
    private function question(array $overrides = []): array
    {
        return array_merge([
            'question'   => 'Siapa penemu bola lampu?',
            'type'       => 1,
            'options'    => ['A' => 'Einstein', 'B' => 'Edison'],
            'correct'    => ['B'],
            'answer_key' => '',
            'matches'    => null,
        ], $overrides);
    }

    public function testHealthyQuestionsAreAllAccepted(): void
    {
        $result = (new WordImportTriage())->classify([
            $this->question(),
            $this->question(['question' => 'Ibukota Jepang adalah?']),
        ]);

        $this->assertCount(2, $result['accepted']);
        $this->assertSame([], $result['duplicates']);
        $this->assertSame([], $result['rejected']);
    }

    public function testBrokenQuestionIsRejectedWhileTheRestStillGetIn(): void
    {
        $result = (new WordImportTriage())->classify([
            $this->question(),
            $this->question(['question' => 'Soal tanpa kunci', 'correct' => []]),
            $this->question(['question' => 'Ibukota Jepang adalah?']),
        ]);

        // Satu soal rusak tidak lagi membatalkan seluruh dokumen.
        $this->assertCount(2, $result['accepted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('Soal tanpa kunci', $result['rejected'][0]['soal']);
        // Teks soalnya sudah jadi judul baris, jadi alasannya tidak mengulangnya.
        $this->assertSame(
            'Belum ada opsi yang ditandai (*) sebagai jawaban benar.',
            $result['rejected'][0]['alasan'][0]
        );
    }

    public function testQuestionWrittenTwiceInTheSameDocumentIsCountedOnce(): void
    {
        $result = (new WordImportTriage())->classify([
            $this->question(),
            $this->question(),
        ]);

        $this->assertCount(1, $result['accepted']);
        $this->assertCount(1, $result['duplicates']);
        $this->assertStringContainsString('lebih dari sekali di dokumen', $result['duplicates'][0]['alasan']);
    }

    public function testQuestionAlreadyStoredInTheSubjectIsSkipped(): void
    {
        $result = (new WordImportTriage())->classify(
            [$this->question()],
            ['Siapa penemu bola lampu?']
        );

        $this->assertSame([], $result['accepted']);
        $this->assertCount(1, $result['duplicates']);
        $this->assertStringContainsString('Sudah ada di subjek tujuan', $result['duplicates'][0]['alasan']);
    }

    public function testDuplicateDetectionIgnoresCaseSpacingAndMarkup(): void
    {
        $result = (new WordImportTriage())->classify(
            [$this->question(['question' => 'siapa   penemu <b>bola</b> lampu?'])],
            ['Siapa penemu bola lampu?']
        );

        $this->assertSame([], $result['accepted']);
        $this->assertCount(1, $result['duplicates']);
    }

    public function testImageOnlyQuestionsAreNeverTreatedAsDuplicatesOfEachOther(): void
    {
        $result = (new WordImportTriage())->classify([
            $this->question(['question' => '<img src="/uploads/questions/img_a.png">']),
            $this->question(['question' => '<img src="/uploads/questions/img_b.png">']),
        ]);

        // Dua soal bergambar berbeda sama-sama tidak punya teks; menyamakannya
        // berarti membuang soal yang sebenarnya lain.
        $this->assertCount(2, $result['accepted']);
        $this->assertSame([], $result['duplicates']);
    }

    public function testRejectedQuestionIsNotAlsoCountedAsDuplicate(): void
    {
        $result = (new WordImportTriage())->classify([
            $this->question(['correct' => []]),
            $this->question(['correct' => []]),
        ]);

        $this->assertSame([], $result['accepted']);
        $this->assertSame([], $result['duplicates']);
        $this->assertCount(2, $result['rejected']);
    }

    public function testReasonKeepsItsOwnWordingWhenItDoesNotQuoteTheQuestion(): void
    {
        $result = (new WordImportTriage())->classify([
            $this->question(['type' => 4, 'options' => [], 'correct' => [], 'matches' => []]),
        ]);

        $this->assertStringContainsString('tidak ditemukan tabel pasangan', $result['rejected'][0]['alasan'][0]);
        $this->assertStringStartsWith('Bertipe Menjodohkan', $result['rejected'][0]['alasan'][0]);
    }
}

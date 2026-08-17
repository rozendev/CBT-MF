<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordQuestionParser;
use PHPUnit\Framework\TestCase;

class WordQuestionParserTest extends TestCase
{
    private function line(string $text, bool $isListItem = false, int $depth = 0): array
    {
        return ['kind' => 'line', 'text' => $text, 'is_list_item' => $isListItem, 'list_depth' => $depth];
    }

    public function testSingleChoiceQuestionWithStarredOption(): void
    {
        $blocks = [
            $this->line('1. Siapa penemu bola lampu?'),
            $this->line('A. Albert Einstein'),
            $this->line('*B. Thomas Alva Edison'),
            $this->line('C. Isaac Newton'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $q = $questions[0];
        $this->assertSame('Siapa penemu bola lampu?', $q['question']);
        $this->assertSame(1, $q['type']);
        $this->assertSame(
            ['A' => 'Albert Einstein', 'B' => 'Thomas Alva Edison', 'C' => 'Isaac Newton'],
            $q['options']
        );
        $this->assertSame(['B'], $q['correct']);
    }

    public function testMultipleStarredOptionsBecomeComplexMultipleChoice(): void
    {
        $blocks = [
            $this->line('2. Pilihlah semua jawaban yang merupakan nama benua:'),
            $this->line('*A. Asia'),
            $this->line('B. Pasifik'),
            $this->line('*C. Eropa'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(2, $questions[0]['type']);
        $this->assertSame(['A', 'C'], $questions[0]['correct']);
    }

    public function testQuestionAndOptionsFromNativeWordList(): void
    {
        $blocks = [
            $this->line('Ibukota Jepang adalah?', true, 0),
            $this->line('Osaka', true, 1),
            $this->line('*Tokyo', true, 1),
            $this->line('Kyoto', true, 1),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $q = $questions[0];
        $this->assertSame('Ibukota Jepang adalah?', $q['question']);
        $this->assertSame(
            ['A' => 'Osaka', 'B' => 'Tokyo', 'C' => 'Kyoto'],
            $q['options']
        );
        $this->assertSame(['B'], $q['correct']);
        $this->assertSame(1, $q['type']);
    }

    public function testQuestionWithoutOptionsDefaultsToEssay(): void
    {
        $blocks = [
            $this->line('5. Jelaskan pendapatmu tentang lingkungan.'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('', $questions[0]['answer_key']);
    }

    public function testMultiLineQuestionAndOptionTextIsJoinedWithBr(): void
    {
        $blocks = [
            $this->line('1. Baris pertama soal'),
            $this->line('lanjutan baris kedua soal'),
            $this->line('A. Opsi baris pertama'),
            $this->line('lanjutan opsi'),
            $this->line('*B. Jawaban benar'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame('Baris pertama soal<br>lanjutan baris kedua soal', $questions[0]['question']);
        $this->assertSame('Opsi baris pertama<br>lanjutan opsi', $questions[0]['options']['A']);
    }

    public function testTableBeforeAnyQuestionIsDroppedSilently(): void
    {
        $blocks = [
            ['kind' => 'table', 'html' => '<table><tr><td>orphan</td></tr></table>', 'rows' => [['orphan']]],
            $this->line('1. Soal setelah tabel'),
            $this->line('A. Satu'),
            $this->line('*B. Dua'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $this->assertStringNotContainsString('orphan', $questions[0]['question']);
    }

    public function testReferenceTableIsAppendedToCurrentQuestionBody(): void
    {
        $blocks = [
            $this->line('1. Soal dengan tabel data:'),
            ['kind' => 'table', 'html' => '<table><tr><td>Nama</td><td>Usia</td></tr></table>', 'rows' => [['Nama', 'Usia']]],
            $this->line('A. Satu'),
            $this->line('*B. Dua'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertStringContainsString('Soal dengan tabel data:', $questions[0]['question']);
        $this->assertStringContainsString('<table><tr><td>Nama</td><td>Usia</td></tr></table>', $questions[0]['question']);
    }

    public function testJawabanLineIsStoredAsOptionalAnswerKey(): void
    {
        $blocks = [
            $this->line('4. Siapa nama presiden pertama Republik Indonesia?'),
            $this->line('Jawaban: Ir. Soekarno'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('Ir. Soekarno', $questions[0]['answer_key']);
        $this->assertSame([], $questions[0]['options']);
    }
}

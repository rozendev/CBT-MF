<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordImportValidator;
use PHPUnit\Framework\TestCase;

class WordImportValidatorTest extends TestCase
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

    public function testEmptyDocumentProducesOneError(): void
    {
        $errors = (new WordImportValidator())->validate([]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Tidak ada soal', $errors[0]);
    }

    public function testValidMultipleChoiceQuestionProducesNoErrors(): void
    {
        $errors = (new WordImportValidator())->validate([$this->question()]);

        $this->assertSame([], $errors);
    }

    public function testMultipleChoiceWithFewerThanTwoOptionsIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['options' => ['A' => 'Einstein'], 'correct' => ['A']]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('minimal 2 pilihan jawaban', $errors[0]);
        $this->assertStringContainsString('Siapa penemu bola lampu?', $errors[0]);
    }

    public function testMultipleChoiceWithoutStarredOptionIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['correct' => []]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('belum ada opsi yang ditandai (*)', $errors[0]);
    }

    public function testEssayQuestionIsAlwaysValidRegardlessOfAnswerKey(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['type' => 3, 'options' => [], 'correct' => [], 'answer_key' => '']),
        ]);

        $this->assertSame([], $errors);
    }
}

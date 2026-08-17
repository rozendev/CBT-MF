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

    public function testImageOnlyQuestionGetsFallbackSnippetInErrorMessage(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'question' => '<br><img src="/uploads/questions/img_abc123.png" class="img-fluid rounded shadow-sm"><br>',
                'correct'  => [],
            ]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('(soal tanpa teks / berisi gambar)', $errors[0]);
        $this->assertStringNotContainsString('Soal ""', $errors[0]);
    }

    public function testMatchingWithoutTableIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['type' => 4, 'options' => [], 'correct' => [], 'matches' => []]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bertipe Menjodohkan', $errors[0]);
        $this->assertStringContainsString('tidak ditemukan tabel pasangan', $errors[0]);
    }

    public function testMatchingPairWithEmptyCellIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'type' => 4, 'options' => [], 'correct' => [],
                'matches' => [['left' => 'Jepang', 'right' => '']],
            ]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('tidak lengkap', $errors[0]);
    }

    public function testTrueFalseWithInvalidValueIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'type' => 5, 'options' => [], 'correct' => [],
                'matches' => [['left' => 'Bumi itu berbentuk datar', 'right' => 'Salahh']],
            ]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bukan "Benar"/"Salah" yang valid', $errors[0]);
    }

    public function testTrueFalseWithValidValuesProducesNoErrors(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'type' => 5, 'options' => [], 'correct' => [],
                'matches' => [
                    ['left' => 'Matahari terbit dari timur', 'right' => 'Benar'],
                    ['left' => 'Bumi itu berbentuk datar', 'right' => 'salah'],
                ],
            ]),
        ]);

        $this->assertSame([], $errors);
    }
}

<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordQuestionParser;
use PHPUnit\Framework\TestCase;

class WordQuestionParserTest extends TestCase
{
    private function line(string $text, bool $isListItem = false, int $depth = 0, ?string $format = null): array
    {
        return [
            'kind'         => 'line',
            'text'         => $text,
            'is_list_item' => $isListItem,
            'list_depth'   => $depth,
            'list_format'  => $format,
        ];
    }

    private function table(array $rows): array
    {
        return ['kind' => 'table', 'html' => '<table></table>', 'rows' => $rows];
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

    public function testExplicitOptionLetterBeatsTopLevelListMetadata(): void
    {
        // Word sering menandai paragraf biasa sebagai list (numId 0 =
        // "tanpa penomoran") sesudah user mematikan AutoCorrect. Kalau user
        // sudah mengetik "A."/"*C." sendiri, teks itu yang menang -- bukan
        // metadata list-nya.
        $blocks = [
            $this->line('9. Siapakah dibawah ini yang menurut kamu benar?', true, 0),
            $this->line('*A. Ronaldo.', true, 0),
            $this->line('B. Messi.', true, 0),
            $this->line('*C. Mbappe', true, 0),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $this->assertSame('Siapakah dibawah ini yang menurut kamu benar?', $questions[0]['question']);
        $this->assertSame(
            ['A' => 'Ronaldo.', 'B' => 'Messi.', 'C' => 'Mbappe'],
            $questions[0]['options']
        );
        $this->assertSame(['A', 'C'], $questions[0]['correct']);
        $this->assertSame(2, $questions[0]['type']);
    }

    public function testLetterFormattedTopLevelListItemsBecomeOptions(): void
    {
        // AutoCorrect Word mengubah "A. Osaka" jadi list ber-huruf otomatis:
        // huruf pindah ke penomoran, teks tinggal "Osaka", dan levelnya tetap
        // 0 -- sejajar dengan soal.
        $blocks = [
            $this->line('10. Apa yang lebih berat?'),
            $this->line('Satu kilogram emas', true, 0, 'letter'),
            $this->line('*Satu kilogram besi', true, 0, 'letter'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $this->assertSame('Apa yang lebih berat?', $questions[0]['question']);
        $this->assertSame(
            ['A' => 'Satu kilogram emas', 'B' => 'Satu kilogram besi'],
            $questions[0]['options']
        );
        $this->assertSame(['B'], $questions[0]['correct']);
    }

    public function testBulletFormattedTopLevelListItemsBecomeOptions(): void
    {
        // Dokumen Word asli: soal ditulis sebagai list bernomor, opsinya sebagai
        // list ber-bullet yang tidak diindentasi -- keduanya berakhir di depth 0.
        // Bullet tidak pernah membawa nomor urut, jadi tidak mungkin jadi soal.
        $blocks = [
            $this->line('Ibukota provinsi Jawa Barat adalah?', true, 0, 'number'),
            $this->line('Cirebon', true, 0, 'bullet'),
            $this->line('*Bandung', true, 0, 'bullet'),
            $this->line('Bekasi', true, 0, 'bullet'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $this->assertSame('Ibukota provinsi Jawa Barat adalah?', $questions[0]['question']);
        $this->assertSame(
            ['A' => 'Cirebon', 'B' => 'Bandung', 'C' => 'Bekasi'],
            $questions[0]['options']
        );
        $this->assertSame(['B'], $questions[0]['correct']);
        $this->assertSame(1, $questions[0]['type']);
    }

    public function testNumberPrefixIsStrippedFromListItemQuestion(): void
    {
        $blocks = [
            $this->line('9. Siapakah dibawah ini yang menurut kamu benar?', true, 0),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame('Siapakah dibawah ini yang menurut kamu benar?', $questions[0]['question']);
    }

    public function testNumberFormattedTopLevelListItemStillStartsNewQuestion(): void
    {
        $blocks = [
            $this->line('Ibukota Jepang adalah?', true, 0, 'number'),
            $this->line('Osaka', true, 1),
            $this->line('*Tokyo', true, 1),
            $this->line('Ibukota Korea Selatan adalah?', true, 0, 'number'),
            $this->line('Seoul', true, 1),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(2, $questions);
        $this->assertSame('Ibukota Jepang adalah?', $questions[0]['question']);
        $this->assertSame('Ibukota Korea Selatan adalah?', $questions[1]['question']);
    }

    public function testQuestionWithoutOptionsDefaultsToEssay(): void
    {
        $blocks = [
            $this->line('5. Jelaskan pendapatmu tentang lingkungan.'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('', $questions[0]['answer_key']);
        $this->assertSame('manual', $questions[0]['answer_mode']);
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
        $this->assertSame('exact', $questions[0]['answer_mode']);
    }

    public function testDeclaredEsaiMarkerStaysManualEvenWithEmptyKey(): void
    {
        $blocks = [
            $this->line('5. Jelaskan pendapatmu tentang pentingnya menjaga kelestarian hutan.'),
            $this->line('Tipe: Esai'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('manual', $questions[0]['answer_mode']);
    }

    public function testMatchingTypeConsumesTableAsPairsSkippingHeaderRow(): void
    {
        $blocks = [
            $this->line('3. Pasangkan negara berikut dengan ibukotanya!'),
            $this->line('Tipe: Menjodohkan'),
            $this->table([
                ['Negara', 'Ibukota'],
                ['Indonesia', 'Jakarta'],
                ['Jepang', 'Tokyo'],
            ]),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(4, $questions[0]['type']);
        $this->assertSame(
            [
                ['left' => 'Indonesia', 'right' => 'Jakarta'],
                ['left' => 'Jepang', 'right' => 'Tokyo'],
            ],
            $questions[0]['matches']
        );
    }

    public function testTrueFalseTypeUsesSameTableMechanism(): void
    {
        $blocks = [
            $this->line('4. Tentukan benar atau salah pernyataan berikut!'),
            $this->line('Tipe: Benar/Salah'),
            $this->table([
                ['Pernyataan', 'Jawaban'],
                ['Matahari terbit dari timur', 'Benar'],
                ['Bumi itu berbentuk datar', 'Salah'],
            ]),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(5, $questions[0]['type']);
        $this->assertSame('Benar', $questions[0]['matches'][0]['right']);
    }

    public function testDeclaredPairTypeWithoutTableStillResolvesToThatType(): void
    {
        $blocks = [
            $this->line('3. Pasangkan negara berikut dengan ibukotanya!'),
            $this->line('Tipe: Menjodohkan'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(4, $questions[0]['type']);
        $this->assertSame([], $questions[0]['matches']);
    }

    public function testPlainTableWithoutTipeMarkerStaysAsReferenceTable(): void
    {
        $blocks = [
            $this->line('7. Soal dengan tabel data:'),
            $this->table([
                ['Nama', 'Usia'],
                ['Andi', '15 Tahun'],
            ]),
            $this->line('Berapa usia Andi?'),
            $this->line('*A. 15 Tahun'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(1, $questions[0]['type']);
        $this->assertNull($questions[0]['matches']);
        $this->assertStringContainsString('<table></table>', $questions[0]['question']);
    }

    public function testDuplicateOptionLetterIsRecordedInsteadOfSilentlyMerging(): void
    {
        $blocks = [
            $this->line('1. Soal dengan huruf opsi kembar'),
            $this->line('*A. Pertama'),
            $this->line('B. Kedua'),
            $this->line('*A. Ketiga'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $q = $questions[0];
        $this->assertSame(['A' => 'Ketiga', 'B' => 'Kedua'], $q['options']);
        // Huruf yang sama tidak boleh tercatat dua kali sebagai kunci: kalau
        // tercatat dua kali, finalize() menganggapnya PG kompleks padahal
        // jawaban benarnya cuma satu.
        $this->assertSame(['A'], $q['correct']);
        $this->assertSame(1, $q['type']);
        $this->assertSame(['A'], $q['duplicate_letters']);
    }

    public function testDuplicateOptionLetterWithoutStarDropsPreviousCorrectMark(): void
    {
        $blocks = [
            $this->line('1. Soal dengan huruf opsi kembar'),
            $this->line('*A. Pertama'),
            $this->line('B. Kedua'),
            $this->line('A. Ketiga'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        // Teks A sudah tertimpa jadi "Ketiga" yang tidak berbintang, jadi tanda
        // benarnya tidak boleh tertinggal menunjuk teks yang sudah hilang.
        $this->assertSame([], $questions[0]['correct']);
        $this->assertSame(['A'], $questions[0]['duplicate_letters']);
    }

    public function testNormalQuestionHasNoDuplicateLetters(): void
    {
        $blocks = [
            $this->line('1. Siapa penemu bola lampu?'),
            $this->line('A. Albert Einstein'),
            $this->line('*B. Thomas Alva Edison'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame([], $questions[0]['duplicate_letters']);
    }
}

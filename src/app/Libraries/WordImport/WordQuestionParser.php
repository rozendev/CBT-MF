<?php

namespace App\Libraries\WordImport;

/**
 * Mengubah array blok terstruktur (dari WordBlockExtractor) jadi daftar soal
 * siap divalidasi & disimpan.
 *
 * Lihat docs/superpowers/specs/2026-08-17-humane-word-import-format-design.md
 * untuk aturan format lengkapnya.
 */
class WordQuestionParser
{
    private const QUESTION_NUMBER_RE = '/^\d+\s*[.\-):]+\s*(.*)$/';
    private const OPTION_LETTER_RE   = '/^(\*?)([A-Za-z])\s*[.\-):]+\s*(.*)$/';
    private const JAWABAN_RE         = '/^Jawaban\s*:\s*(.*)$/i';
    private const TIPE_RE            = '/^Tipe\s*:\s*(Menjodohkan|Benar\s*\/?\s*Salah|Esai|Essay|Uraian)/i';

    /** @return array<int, array<string, mixed>> */
    public function parse(array $blocks): array
    {
        $questions = [];
        $current = null;
        $section = 'none'; // 'question' | 'option' | 'none'
        $lastOptionLetter = null;

        foreach ($blocks as $block) {
            if ($block['kind'] === 'table') {
                $current = $this->handleTable($current, $block);
                $section = 'none';
                continue;
            }

            $text = trim($block['text']);
            if ($text === '') {
                continue;
            }

            if (preg_match(self::TIPE_RE, $text, $m)) {
                if ($current !== null) {
                    if (preg_match('/Esai|Essay|Uraian/i', $m[1])) {
                        // Esai tetap tipe 3; yang membedakannya dari isian
                        // singkat hanya cara menilainya.
                        $current['declared_answer_mode'] = 'manual';
                    } else {
                        $current['declared_pair_type'] = stripos($m[1], 'Menjodohkan') !== false ? 'MENJODOHKAN' : 'BENARSALAH';
                    }
                }
                $section = 'none';
                continue;
            }

            if (preg_match(self::JAWABAN_RE, $text, $m)) {
                if ($current !== null) {
                    $current['answer_key'] = trim($m[1]);
                }
                $section = 'none';
                continue;
            }

            $option = $current === null ? null : $this->matchOptionBoundary($block, $text);

            // Opsi menang lebih dulu kalau penandanya eksplisit ("A." / "*C.")
            // atau kalau list-nya bernomor huruf: dua-duanya bukti jauh lebih
            // kuat daripada kedalaman list, yang gampang meleset di dokumen
            // Word asli.
            if ($option === null || !$option['explicit']) {
                $questionText = $this->matchQuestionBoundary($block, $text);
                if ($questionText !== null) {
                    if ($current !== null && $current['question'] !== '') {
                        $questions[] = $this->finalize($current);
                    }
                    $current = $this->emptyQuestion();
                    $current['question'] = $questionText;
                    $section = 'question';
                    $lastOptionLetter = null;
                    continue;
                }
            }

            if ($current === null) {
                continue;
            }

            if ($option !== null) {
                $letter = $option['letter'] ?? $this->nextLetter($current['options']);
                $current['options'][$letter] = $option['text'];
                if ($option['is_correct']) {
                    $current['correct'][] = $letter;
                }
                $section = 'option';
                $lastOptionLetter = $letter;
                continue;
            }

            if ($section === 'question') {
                $current['question'] .= ($current['question'] !== '' ? '<br>' : '') . $text;
            } elseif ($section === 'option' && $lastOptionLetter !== null) {
                $current['options'][$lastOptionLetter] .= '<br>' . $text;
            }
        }

        if ($current !== null && $current['question'] !== '') {
            $questions[] = $this->finalize($current);
        }

        return $questions;
    }

    private function handleTable(?array $current, array $block): ?array
    {
        if ($current === null) {
            return $current;
        }
        if (($current['declared_pair_type'] ?? null) !== null && $current['matches'] === null) {
            $current['matches'] = $this->rowsToMatches($block['rows']);
            return $current;
        }
        $current['question'] .= ($current['question'] !== '' ? '<br>' : '') . $block['html'];
        return $current;
    }

    private function rowsToMatches(array $rows): array
    {
        $pairs = [];
        foreach (array_slice($rows, 1) as $row) {
            $left = trim($row[0] ?? '');
            $right = trim($row[1] ?? '');
            if ($left === '' && $right === '') {
                continue;
            }
            $pairs[] = ['left' => $left, 'right' => $right];
        }
        return $pairs;
    }

    private function matchQuestionBoundary(array $block, string $text): ?string
    {
        if (preg_match(self::QUESTION_NUMBER_RE, $text, $m)) {
            return trim($m[1]);
        }
        // Bullet tidak membawa nomor urut, jadi tidak pernah menandai soal baru.
        // Di dokumen Word asli, opsi sering ditulis sebagai bullet tanpa
        // diindentasi, sehingga mendarat di depth 0 -- sejajar dengan soal.
        if ($block['is_list_item']
            && $block['list_depth'] === 0
            && ($block['list_format'] ?? null) !== 'bullet') {
            return $text;
        }
        return null;
    }

    /**
     * 'explicit' menandai opsi yang pengenalnya tidak bergantung pada kedalaman
     * list: huruf yang diketik sendiri, atau list yang penomorannya memang
     * huruf (hasil AutoCorrect Word saat user mengetik "A. ").
     *
     * @return array{letter: ?string, text: string, is_correct: bool, explicit: bool}|null
     */
    private function matchOptionBoundary(array $block, string $text): ?array
    {
        $isLetterList = $block['is_list_item'] && ($block['list_format'] ?? null) === 'letter';
        $isBulletList = $block['is_list_item'] && ($block['list_format'] ?? null) === 'bullet';

        if (preg_match(self::OPTION_LETTER_RE, $text, $m)) {
            return [
                'letter'     => strtoupper($m[2]),
                'text'       => trim($m[3]),
                'is_correct' => $m[1] === '*',
                'explicit'   => true,
            ];
        }
        if ($block['is_list_item'] && ($block['list_depth'] >= 1 || $isLetterList || $isBulletList)) {
            $isCorrect = str_starts_with($text, '*');
            return [
                'letter'     => null,
                'text'       => $isCorrect ? trim(substr($text, 1)) : $text,
                'is_correct' => $isCorrect,
                'explicit'   => $isLetterList,
            ];
        }
        return null;
    }

    private function nextLetter(array $options): string
    {
        return chr(65 + count($options));
    }

    private function emptyQuestion(): array
    {
        return [
            'question'           => '',
            'options'            => [],
            'correct'            => [],
            'answer_key'         => '',
            'matches'            => null,
            'declared_pair_type' => null,
            'declared_answer_mode' => null,
        ];
    }

    private function finalize(array $q): array
    {
        if ($q['declared_pair_type'] !== null) {
            $q['type'] = $q['declared_pair_type'] === 'BENARSALAH' ? 5 : 4;
            $q['matches'] = $q['matches'] ?? [];
        } elseif (!empty($q['options'])) {
            $q['type'] = count($q['correct']) > 1 ? 2 : 1;
        } else {
            $q['type'] = 3; // Esai: tidak ada opsi berlabel.
        }
        $q['answer_mode'] = 'exact';
        if ($q['type'] === 3) {
            // Sejajar dengan penanda "Tipe: Esai": tanpa kunci yang benar-benar
            // berisi, satu-satunya penilaian yang jujur adalah koreksi guru.
            // Mengizinkan exact berkunci kosong berarti mesin mencocokkan
            // persis dengan "tidak ada apa-apa" dan selalu menghasilkan 0.
            $hasKey = trim($q['answer_key']) !== '';
            $q['answer_mode'] = ($q['declared_answer_mode'] === 'manual' || !$hasKey) ? 'manual' : 'exact';
        }
        unset($q['declared_pair_type'], $q['declared_answer_mode']);
        return $q;
    }
}

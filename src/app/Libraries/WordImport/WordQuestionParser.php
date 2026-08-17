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

            if (preg_match(self::JAWABAN_RE, $text, $m)) {
                if ($current !== null) {
                    $current['answer_key'] = trim($m[1]);
                }
                $section = 'none';
                continue;
            }

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

            if ($current === null) {
                continue;
            }

            $option = $this->matchOptionBoundary($block, $text);
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
        // Tanpa "Tipe: Menjodohkan/Benar-Salah" (Task 6), tabel selalu dianggap
        // tabel referensi biasa dan ditempel ke body soal.
        $current['question'] .= ($current['question'] !== '' ? '<br>' : '') . $block['html'];
        return $current;
    }

    private function matchQuestionBoundary(array $block, string $text): ?string
    {
        if ($block['is_list_item'] && $block['list_depth'] === 0) {
            return $text;
        }
        if (preg_match(self::QUESTION_NUMBER_RE, $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** @return array{letter: ?string, text: string, is_correct: bool}|null */
    private function matchOptionBoundary(array $block, string $text): ?array
    {
        if (preg_match(self::OPTION_LETTER_RE, $text, $m)) {
            return [
                'letter'     => strtoupper($m[2]),
                'text'       => trim($m[3]),
                'is_correct' => $m[1] === '*',
            ];
        }
        if ($block['is_list_item'] && $block['list_depth'] >= 1) {
            $isCorrect = str_starts_with($text, '*');
            return [
                'letter'     => null,
                'text'       => $isCorrect ? trim(substr($text, 1)) : $text,
                'is_correct' => $isCorrect,
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
            'question'   => '',
            'options'    => [],
            'correct'    => [],
            'answer_key' => '',
            'matches'    => null,
        ];
    }

    private function finalize(array $q): array
    {
        if (!empty($q['options'])) {
            $q['type'] = count($q['correct']) > 1 ? 2 : 1;
        } else {
            $q['type'] = 3; // Esai: tidak ada opsi berlabel.
        }
        return $q;
    }
}

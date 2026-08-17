<?php

namespace App\Libraries\WordImport;

/**
 * Validasi hasil WordQuestionParser::parse() dan menghasilkan pesan error
 * berbahasa manusia yang mengutip cuplikan soal, bukan cuma nomor urut.
 */
class WordImportValidator
{
    private const SNIPPET_LENGTH = 55;

    /** @return string[] */
    public function validate(array $questions): array
    {
        if (empty($questions)) {
            return ['Tidak ada soal yang terdeteksi. Pastikan format dokumen sesuai (contoh: "1. Teks Soal").'];
        }

        $errors = [];
        foreach ($questions as $q) {
            $errors = array_merge($errors, $this->validateQuestion($q));
        }
        return $errors;
    }

    /** @return string[] */
    private function validateQuestion(array $q): array
    {
        $snippet = $this->snippet($q['question']);

        if ($q['type'] === 1 || $q['type'] === 2) {
            return $this->validateMultipleChoice($q, $snippet);
        }

        if ($q['type'] === 4 || $q['type'] === 5) {
            return $this->validatePairs($q, $snippet);
        }

        return []; // Esai (type 3): tidak ada aturan wajib.
    }

    private function validateMultipleChoice(array $q, string $snippet): array
    {
        $errors = [];
        if (count($q['options']) < 2) {
            $errors[] = "Soal \"{$snippet}\" harus punya minimal 2 pilihan jawaban.";
        }
        if (empty($q['correct'])) {
            $errors[] = "Soal \"{$snippet}\" belum ada opsi yang ditandai (*) sebagai jawaban benar.";
        }
        return $errors;
    }

    private function validatePairs(array $q, string $snippet): array
    {
        $label = $q['type'] === 5 ? 'Benar/Salah' : 'Menjodohkan';

        if (empty($q['matches'])) {
            return ["Soal \"{$snippet}\" bertipe {$label} tapi tidak ditemukan tabel pasangan di bawahnya."];
        }

        $errors = [];
        foreach ($q['matches'] as $pair) {
            if ($pair['left'] === '' || $pair['right'] === '') {
                $errors[] = "Soal \"{$snippet}\" baris pasangan \"{$pair['left']}\" → \"{$pair['right']}\" tidak lengkap.";
                continue;
            }
            if ($q['type'] === 5 && !in_array(mb_strtolower($pair['right']), ['benar', 'salah'], true)) {
                $errors[] = "Soal \"{$snippet}\" baris \"{$pair['left']}\" → \"{$pair['right']}\" bukan \"Benar\"/\"Salah\" yang valid.";
            }
        }
        return $errors;
    }

    private function snippet(string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if ($text === '') {
            return '(soal tanpa teks / berisi gambar)';
        }
        if (mb_strlen($text) > self::SNIPPET_LENGTH) {
            $text = mb_substr($text, 0, self::SNIPPET_LENGTH) . '...';
        }
        return $text;
    }
}

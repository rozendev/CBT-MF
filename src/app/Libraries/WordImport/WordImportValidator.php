<?php

namespace App\Libraries\WordImport;

/**
 * Validasi hasil WordQuestionParser::parse() dan menghasilkan pesan error
 * berbahasa manusia yang mengutip cuplikan soal, bukan cuma nomor urut.
 */
class WordImportValidator
{
    private const SNIPPET_LENGTH = 55;

    /** Pemisah pasangan kiri/kanan yang dipakai saat menyimpan ke tabel answers. */
    private const PAIR_DELIMITER = '|::|';

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

        // Huruf yang dipakai dua kali membuat opsi saling menimpa: yang tersimpan
        // lebih sedikit daripada yang diketik guru, dan itu tidak kelihatan
        // di dokumen aslinya.
        foreach ($q['duplicate_letters'] ?? [] as $letter) {
            $errors[] = "Soal \"{$snippet}\" punya lebih dari satu opsi berhuruf \"{$letter}\". "
                . 'Perbaiki penomoran opsinya supaya tidak ada yang hilang.';
        }

        // Opsi yang teksnya kosong lolos sampai ke ruang ujian sebagai pilihan
        // jawaban kosong, karena insert jawaban di controller memang
        // skipValidation.
        $blank = [];
        foreach ($q['options'] as $letter => $text) {
            if ($this->isBlank($text)) {
                $blank[] = $letter;
            }
        }
        if ($blank !== []) {
            $daftar = implode(', ', $blank);
            $errors[] = "Soal \"{$snippet}\" punya opsi tanpa teks jawaban: {$daftar}.";
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
            $left = $this->cell($pair['left']);
            $right = $this->cell($pair['right']);

            if ($pair['left'] === '' || $pair['right'] === '') {
                $errors[] = "Soal \"{$snippet}\" baris pasangan {$left} → {$right} tidak lengkap.";
                continue;
            }
            // Kiri dan kanan disatukan dengan penanda ini waktu disimpan, jadi
            // sel yang memuatnya sendiri akan terbaca salah saat dinilai.
            if (str_contains($pair['left'], self::PAIR_DELIMITER) || str_contains($pair['right'], self::PAIR_DELIMITER)) {
                $errors[] = "Soal \"{$snippet}\" baris pasangan {$left} → {$right} memuat teks \""
                    . self::PAIR_DELIMITER . '" yang dipakai sistem sebagai pemisah. Hapus teks tersebut.';
                continue;
            }
            if ($q['type'] === 5 && !in_array(mb_strtolower($pair['right']), ['benar', 'salah'], true)) {
                $errors[] = "Soal \"{$snippet}\" baris {$left} → {$right} bukan \"Benar\"/\"Salah\" yang valid.";
            }
        }
        return $errors;
    }

    private function cell(string $text): string
    {
        return $text === '' ? '(kosong)' : "\"{$text}\"";
    }

    /**
     * Teks dianggap kosong kalau tidak menyisakan karakter apa pun selain spasi
     * -- termasuk non-breaking space bawaan Word -- dan tidak memuat gambar.
     */
    private function isBlank(string $html): bool
    {
        if (stripos($html, '<img') !== false) {
            return false;
        }
        return preg_replace('/[\pZ\s]+/u', '', strip_tags($html)) === '';
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

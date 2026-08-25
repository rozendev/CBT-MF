<?php

namespace App\Libraries\WordImport;

/**
 * Memilah hasil parser jadi tiga keranjang: soal yang masuk, soal yang kembar,
 * dan soal yang ditolak karena formatnya bermasalah.
 *
 * Dokumen yang sebagian isinya bermasalah tidak lagi membatalkan seluruh impor:
 * soal yang sehat tetap masuk, sisanya dilaporkan satu per satu supaya guru tahu
 * persis mana yang perlu diperbaiki dan tidak perlu mengunggah ulang semuanya.
 */
class WordImportTriage
{
    private WordImportValidator $validator;

    public function __construct(?WordImportValidator $validator = null)
    {
        $this->validator = $validator ?? new WordImportValidator();
    }

    /**
     * @param array<int, array<string, mixed>> $questions      hasil WordQuestionParser::parse()
     * @param string[]                         $existingTexts  description soal yang sudah ada di subjek tujuan
     *
     * @return array{
     *     accepted: array<int, array<string, mixed>>,
     *     duplicates: array<int, array{soal: string, alasan: string}>,
     *     rejected: array<int, array{soal: string, alasan: string[]}>
     * }
     */
    public function classify(array $questions, array $existingTexts = []): array
    {
        $seen = [];
        foreach ($existingTexts as $text) {
            $fingerprint = $this->fingerprint($text);
            if ($fingerprint !== null) {
                $seen[$fingerprint] = 'subjek';
            }
        }

        $accepted = [];
        $duplicates = [];
        $rejected = [];

        $errorsPerQuestion = $this->validator->validateEach($questions);

        foreach ($questions as $index => $q) {
            $snippet = $this->validator->snippet($q['question']);
            $errors = $errorsPerQuestion[$index] ?? [];

            if ($errors !== []) {
                $rejected[] = [
                    'soal'   => $snippet,
                    'alasan' => array_map(fn (string $e) => $this->trimSnippetPrefix($e, $snippet), array_values($errors)),
                ];
                continue;
            }

            $fingerprint = $this->fingerprint($q['question']);
            if ($fingerprint !== null && isset($seen[$fingerprint])) {
                $duplicates[] = [
                    'soal'   => $snippet,
                    'alasan' => $seen[$fingerprint] === 'subjek'
                        ? 'Sudah ada di subjek tujuan.'
                        : 'Ditulis lebih dari sekali di dokumen ini.',
                ];
                continue;
            }

            if ($fingerprint !== null) {
                $seen[$fingerprint] = 'dokumen';
            }
            $accepted[] = $q;
        }

        return ['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected];
    }

    /**
     * Membuang awalan 'Soal "..." ' dari pesan validator: di daftar hasil,
     * teks soalnya sudah tampil sebagai judul barisnya sendiri, jadi mengulangnya
     * di dalam alasan cuma bikin panjang.
     */
    private function trimSnippetPrefix(string $message, string $snippet): string
    {
        $prefix = 'Soal "' . $snippet . '" ';
        if (!str_starts_with($message, $prefix)) {
            return $message;
        }

        $rest = substr($message, strlen($prefix));

        return mb_strtoupper(mb_substr($rest, 0, 1)) . mb_substr($rest, 1);
    }

    /**
     * Sidik jari teks soal untuk membandingkan kembar: tanpa tag, tanpa beda
     * spasi, tanpa beda huruf besar-kecil. Hanya teks soalnya yang dibandingkan,
     * bukan pilihan jawabannya.
     *
     * Soal yang teksnya kosong (isinya cuma gambar) mengembalikan null dan tidak
     * pernah dianggap kembar: menyamakan semua soal bergambar cuma karena
     * sama-sama tanpa teks akan membuang soal yang sebenarnya berbeda.
     */
    private function fingerprint(string $description): ?string
    {
        $text = html_entity_decode(strip_tags($description), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/[\pZ\s]+/u', ' ', $text) ?? '');

        return $text === '' ? null : mb_strtolower($text);
    }
}

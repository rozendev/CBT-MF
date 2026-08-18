<?php

namespace App\Libraries;

/**
 * Mengeluarkan gambar base64 dari HTML soal/jawaban dan menyimpannya sebagai
 * berkas, lalu menggantinya dengan <img src="/uploads/questions/...">.
 *
 * Kenapa ini penting: teks soal disalin utuh ke test_logs SETIAP attempt --
 * itu memang disengaja supaya mengedit bank soal tidak mengubah ujian yang
 * sedang berjalan. Konsekuensinya, satu gambar base64 290 KB yang ditanam di
 * satu soal menjadi ~29 MB di database untuk 100 siswa, dikirim ulang ke tiap
 * siswa saat exam/init, dan disimpan lagi di cache per attempt. Sebagai berkas,
 * gambar itu ditulis sekali, dikirim sekali per perangkat, lalu di-cache
 * browser -- dan test_logs menyusut ratusan kali lipat.
 *
 * Editor soal sudah punya jalur unggah yang benar (QuestionController::
 * uploadImage). Base64 masuk lewat tempel/seret gambar langsung ke editor,
 * yang melewati jalur itu.
 */
class InlineImageExtractor
{
    /** Sengaja sama dengan aturan uploadImage() di QuestionController. */
    private const ALLOWED = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg'  => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024;

    private string $uploadDir;
    private string $urlPrefix;

    /** @var int Jumlah gambar yang berhasil dikeluarkan pada panggilan terakhir. */
    public int $extracted = 0;

    /** @var int Total byte base64 yang lenyap dari HTML pada panggilan terakhir. */
    public int $bytesSaved = 0;

    public function __construct(?string $uploadDir = null, string $urlPrefix = '/uploads/questions/')
    {
        $this->uploadDir = $uploadDir ?? (defined('FCPATH') ? FCPATH . 'uploads/questions/' : sys_get_temp_dir() . '/uploads/questions/');
        $this->urlPrefix = $urlPrefix;
    }

    /**
     * Kembalikan HTML dengan seluruh data URI gambar diganti URL berkas.
     * HTML tanpa base64 dikembalikan apa adanya (dan tidak menyentuh disk).
     */
    public function process(?string $html): string
    {
        $html = (string) $html;
        $this->extracted = 0;
        $this->bytesSaved = 0;

        if ($html === '' || stripos($html, 'data:image/') === false) {
            return $html;
        }

        // Cocokkan data URI di dalam atribut src, dengan tanda kutip apa pun.
        $pattern = '/data:image\/([a-zA-Z0-9.+-]+);base64,([A-Za-z0-9+\/=\s]+)/';

        return (string) preg_replace_callback($pattern, function (array $m) {
            $original = $m[0];
            $mime = 'image/' . strtolower($m[1]);
            if (!isset(self::ALLOWED[$mime])) {
                return $original;
            }

            $raw = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
            if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
                return $original;
            }

            // Nama berdasarkan isi: gambar yang sama dipakai ulang di banyak
            // soal hanya menghasilkan satu berkas.
            $ext = self::ALLOWED[$mime];
            $name = 'q_' . hash('sha256', $raw) . '.' . $ext;
            $path = $this->uploadDir . $name;

            if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
                return $original;
            }
            if (!is_file($path) && @file_put_contents($path, $raw) === false) {
                return $original;
            }

            $this->extracted++;
            $this->bytesSaved += strlen($original);

            return rtrim($this->urlPrefix, '/') . '/' . $name;
        }, $html);
    }
}

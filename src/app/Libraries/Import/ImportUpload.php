<?php
namespace App\Libraries\Import;

use CodeIgniter\HTTP\Files\UploadedFile;

final class ImportUpload
{
    public static function enqueue(string $kind, int $owner, ?UploadedFile $file, array $options, string $requestId): array
    {
        if (!$file || !$file->isValid() || $file->hasMoved()) throw new ImportFailure('File tidak valid atau gagal diunggah.');
        $extension = strtolower($file->getClientExtension());
        $allowed = $kind === 'word' ? ['docx'] : ['xls', 'xlsx'];
        $maxBytes = ($kind === 'word' ? 5 : 10) * 1024 * 1024;
        if (!in_array($extension, $allowed, true) || $file->getSize() > $maxBytes) {
            throw new ImportFailure($kind === 'word' ? 'Gunakan file .docx maksimal 5 MiB.' : 'Gunakan file .xls/.xlsx maksimal 10 MiB.');
        }
        if ($requestId === '') throw new ImportFailure('ID permintaan impor wajib diisi. Muat ulang formulir.');
        return (new ImportQueue())->enqueue($kind, $owner, $file->getTempName(), $options, $requestId, $extension);
    }

    /** Bound decompressed input before either Office parser allocates its objects. */
    public static function checkArchive(string $path): void
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xls'
            && file_get_contents($path, false, null, 0, 8) === hex2bin('d0cf11e0a1b11ae1')) return;
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new ImportFailure('Dokumen Office rusak atau formatnya tidak sesuai.');
        try {
            $size = 0;
            if ($zip->numFiles > 4000) throw new ImportFailure('Dokumen memiliki terlalu banyak bagian.');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += $zip->statIndex($i)['size'];
                if ($size > 64 * 1024 * 1024) throw new ImportFailure('Ukuran isi dokumen melebihi batas 64 MiB.');
            }
        } finally { $zip->close(); }
    }
}

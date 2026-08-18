<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\InlineImageExtractor;
use Config\Database;

/**
 * Mengeluarkan gambar base64 yang terlanjur tersimpan di database menjadi
 * berkas. Lihat App\Libraries\InlineImageExtractor untuk alasannya.
 *
 * DRY-RUN adalah default. Tabel test_logs dan test_log_answers adalah salinan
 * ujian yang sudah/sedang berjalan, jadi menulisinya harus keputusan sadar,
 * bukan efek samping dari menjalankan perintah tanpa argumen.
 */
class ExtractInlineImages extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:extract-inline-images';
    protected $description = 'Ubah gambar base64 di bank soal & salinan ujian jadi berkas di uploads/questions.';
    protected $usage       = 'cbt:extract-inline-images [--commit] [--skip-logs]';
    protected $options     = [
        '--commit'     => 'Tulis perubahan. Tanpa ini perintah hanya melaporkan.',
        '--skip-logs'  => 'Lewati test_logs & test_log_answers (hanya bereskan bank soal).',
    ];

    /** kolom yang discan: [tabel, kolom kunci, kolom teks] */
    private const TARGETS = [
        ['questions',        'id', 'description'],
        ['questions',        'id', 'explanation'],
        ['answers',          'id', 'description'],
        ['test_logs',        'id', 'question_text'],
        ['test_log_answers', 'id', 'answer_text'],
    ];

    public function run(array $params)
    {
        $commit    = CLI::getOption('commit') !== null;
        $skipLogs  = CLI::getOption('skip-logs') !== null;
        $db        = Database::connect();
        $extractor = new InlineImageExtractor();

        if (!$commit) {
            CLI::write('MODE LAPORAN — tidak ada yang ditulis. Tambahkan --commit untuk menerapkan.', 'yellow');
        }
        CLI::newLine();

        $totalRows = 0;
        $totalImgs = 0;
        $totalBytes = 0;

        foreach (self::TARGETS as [$table, $key, $column]) {
            if ($skipLogs && in_array($table, ['test_logs', 'test_log_answers'], true)) {
                continue;
            }
            if (!$db->fieldExists($column, $table)) {
                continue;
            }

            $rows = $db->table($table)
                ->select("$key, $column")
                ->like($column, 'data:image/', 'both')
                ->get()->getResultArray();

            $rowCount = 0;
            $imgCount = 0;
            $bytes    = 0;

            foreach ($rows as $row) {
                $cleaned = $extractor->process($row[$column]);
                if ($extractor->extracted === 0 || $cleaned === $row[$column]) {
                    continue;
                }
                $rowCount++;
                $imgCount += $extractor->extracted;
                $bytes    += strlen($row[$column]) - strlen($cleaned);

                if ($commit) {
                    $db->table($table)->where($key, $row[$key])->update([$column => $cleaned]);
                }
            }

            if ($rowCount > 0) {
                CLI::write(sprintf(
                    '%-22s %-14s  %4d baris, %3d gambar, %s',
                    $table,
                    $column,
                    $rowCount,
                    $imgCount,
                    $this->human($bytes)
                ));
            }

            $totalRows  += $rowCount;
            $totalImgs  += $imgCount;
            $totalBytes += $bytes;
        }

        CLI::newLine();
        if ($totalRows === 0) {
            CLI::write('Tidak ada gambar base64 yang tersisa.', 'green');
            return;
        }

        CLI::write(sprintf(
            'Total: %d baris, %d gambar, %s %s',
            $totalRows,
            $totalImgs,
            $this->human($totalBytes),
            $commit ? 'dibebaskan.' : 'akan dibebaskan.'
        ), $commit ? 'green' : 'yellow');

        if ($commit) {
            CLI::newLine();
            CLI::write('Bersihkan cache soal supaya attempt berjalan memuat versi baru:', 'yellow');
            CLI::write('  php spark cache:clear');
        }
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}

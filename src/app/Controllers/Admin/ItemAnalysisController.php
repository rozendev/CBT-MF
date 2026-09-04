<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\ItemAnalysis;
use App\Libraries\ItemAnalysisCsv;
use App\Libraries\ItemAnalysisDataset;
use App\Models\TestModel;

/**
 * Analisis butir soal untuk satu ujian: tingkat kesukaran, daya beda,
 * korelasi butir-total, efektivitas pengecoh, dan reliabilitas.
 *
 * Seluruh jalur di controller ini hanya membaca (SELECT). Tidak ada satu pun
 * penulisan ke database, cache, maupun session.
 *
 * Pembagian tugas: SQL dan penyusunan matriks di sini, aritmetikanya di
 * App\Libraries\ItemAnalysis yang diuji terpisah tanpa database.
 *
 * Rujukan rancangan: docs/superpowers/specs/2026-09-04-analisis-butir-soal-design.md
 */
class ItemAnalysisController extends BaseController
{
    protected TestModel $testModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
    }

    /** Shell halaman; datanya diambil data() lewat AJAX. */
    public function show(int $testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        return view('admin/results/analysis', ['test' => $test]);
    }

    public function data(int $testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return $this->response->setStatusCode(404)
                ->setJSON(['status' => 'error', 'message' => 'Ujian tidak ditemukan.']);
        }

        $hasil = $this->hitung($test);

        return $this->response->setJSON([
            'status' => 'success',
            'test'   => [
                'id'          => (int) $test->id,
                'nama'        => (string) $test->name,
                'poin_butir'  => (float) $test->score_right,
            ],
            'hasil'  => $hasil,
        ]);
    }

    /**
     * Ekspor CSV. Sengaja CSV, bukan xlsx: jalur ini tidak perlu menyeret
     * PhpSpreadsheet (dan jejak memorinya) ke fitur baru, dan CSV terbuka
     * di Excel maupun LibreOffice tanpa perantara.
     */
    public function export(int $testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        $nama = (string) $test->name;

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . ItemAnalysisCsv::namaBerkas($nama) . '"')
            ->setBody(ItemAnalysisCsv::render($this->hitung($test), $nama));
    }

    // ── Pengambilan data ──────────────────────────────────────────────────

    /**
     * Susun matriks dari database lalu serahkan ke library.
     *
     * Butir dijajarkan dengan question_id, BUKAN display_order. Saat
     * tests.random_questions menyala, ExamService menulis ulang display_order
     * per attempt, jadi "soal nomor 3" milik dua siswa bisa dua soal berbeda.
     */
    private function hitung(object $test): array
    {
        $db     = \Config\Database::connect();
        $testId = (int) $test->id;

        $logs = $db->query("
            SELECT tl.test_attempt_id, tl.question_id, tl.score,
                   tl.question_type, tl.question_text, tl.display_order, tl.answer_text
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            WHERE ta.test_id = ? AND ta.status = 3
        ", [$testId])->getResult();

        if (empty($logs)) {
            return (new ItemAnalysis((float) $test->score_right))->analyze([], []);
        }

        // Opsi hanya ditarik untuk tipe soal beropsi — memangkas jumlah baris,
        // dan tipe lain memang tidak punya pengecoh untuk dianalisis.
        $opsiRows = $db->query("
            SELECT tl.test_attempt_id, tl.question_id, tla.answer_id,
                   tla.answer_text, tla.is_correct, tla.is_selected
            FROM test_log_answers tla
            JOIN test_logs tl ON tl.id = tla.test_log_id
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            WHERE ta.test_id = ? AND ta.status = 3 AND tl.question_type IN (1, 2)
        ", [$testId])->getResult();

        $set = ItemAnalysisDataset::susun($logs, $opsiRows);

        $hasil = (new ItemAnalysis((float) $test->score_right))->analyze(
            $set['scores'],
            $set['meta'],
            $set['answered'],
            $set['optionPicks'],
        );

        if (!$set['nomor_seragam'] && $hasil['butir_dianalisis'] > 0) {
            $hasil['catatan'][] = 'Urutan soal berbeda tiap peserta (pengacakan soal aktif), '
                . 'jadi tidak ada nomor soal yang sama untuk semua orang. Butir di sini dinomori ulang '
                . 'sebagai rujukan internal — cocokkan dengan teks soalnya, bukan dengan nomor di layar siswa.';
        }

        return $hasil;
    }
}

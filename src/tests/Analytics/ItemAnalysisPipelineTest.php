<?php

namespace Tests\Analytics;

use App\Libraries\ItemAnalysis;
use App\Libraries\ItemAnalysisCsv;
use App\Libraries\ItemAnalysisDataset;
use PHPUnit\Framework\TestCase;

/**
 * Uji jalur penuh: baris mentah ala test_logs / test_log_answers →
 * ItemAnalysisDataset → ItemAnalysis → ItemAnalysisCsv. Persis rangkaian yang
 * dipakai ItemAnalysisController, hanya sumber barisnya diganti dari query
 * database ke baris tiruan.
 *
 * Kelas simulasi: 30 peserta berperingkat 1 (terbaik) sampai 30.
 *   Soal 1-8  : soal sehat, ambang benar 27/24/21/18/15/12/9/6
 *   Soal 9    : KUNCI TERBALIK — benar justru untuk peringkat > 15
 *   Soal 10   : benar untuk peringkat <= 20, dua opsinya tidak pernah dipilih
 *   Soal 11   : esai, tiga peserta belum dikoreksi
 *
 * Nilai benar 4 poin, salah -1 poin (penalti tebak) dari score_right = 4.
 */
class ItemAnalysisPipelineTest extends TestCase
{
    private const POIN     = 4.0;
    private const PESERTA  = 30;
    private const AMBANG   = [1 => 27, 2 => 24, 3 => 21, 4 => 18, 5 => 15, 6 => 12, 7 => 9, 8 => 6];

    /** Peringkat r menjawab benar soal nomor $n? */
    private function benar(int $n, int $r): bool
    {
        if ($n <= 8)  return $r <= self::AMBANG[$n];
        if ($n === 9) return $r > 15;      // kunci terbalik
        if ($n === 10) return $r <= 20;

        return false;
    }

    /** @return array{0: list<object>, 1: list<object>} */
    private function baris(): array
    {
        $logs = [];
        $opsi = [];

        foreach (range(1, self::PESERTA) as $r) {
            $aid = 500 + $r;

            foreach (range(1, 10) as $n) {
                $qid   = 100 + $n;
                $benar = $this->benar($n, $r);

                $logs[] = (object) [
                    'test_attempt_id' => $aid,
                    'question_id'     => $qid,
                    'score'           => $benar ? self::POIN : -1.0,
                    'question_type'   => 1,
                    'question_text'   => 'Soal nomor ' . $n,
                    'display_order'   => $n,
                    'answer_text'     => null,
                ];

                // Soal 10 punya empat opsi; dua di antaranya tidak pernah
                // dipilih siapa pun. Soal lain cukup dua opsi.
                $jumlahOpsi = $n === 10 ? 4 : 2;
                $kunciId    = $qid * 10;
                $pilihanId  = $benar ? $kunciId : $kunciId + 1;

                for ($i = 0; $i < $jumlahOpsi; $i++) {
                    $ansId = $kunciId + $i;
                    $opsi[] = (object) [
                        'test_attempt_id' => $aid,
                        'question_id'     => $qid,
                        'answer_id'       => $ansId,
                        'answer_text'     => 'Opsi ' . chr(65 + $i),
                        'is_correct'      => $i === 0 ? 1 : 0,
                        'is_selected'     => $ansId === $pilihanId ? 1 : 0,
                    ];
                }
            }

            // Soal 11: esai, tiga peserta terakhir belum dikoreksi.
            $logs[] = (object) [
                'test_attempt_id' => $aid,
                'question_id'     => 111,
                'score'           => $r > 27 ? null : 2.0,
                'question_type'   => 3,
                'question_text'   => 'Jelaskan pendapatmu.',
                'display_order'   => 11,
                'answer_text'     => 'Jawaban esai peringkat ' . $r,
            ];
        }

        return [$logs, $opsi];
    }

    private function hasil(): array
    {
        [$logs, $opsi] = $this->baris();
        $set = ItemAnalysisDataset::susun($logs, $opsi);

        return (new ItemAnalysis(self::POIN))->analyze(
            $set['scores'],
            $set['meta'],
            $set['answered'],
            $set['optionPicks'],
        );
    }

    private function butir(array $hasil, int $nomor): array
    {
        foreach ($hasil['butir'] as $b) {
            if ($b['nomor'] === $nomor) return $b;
        }

        $this->fail('Butir nomor ' . $nomor . ' tidak ada.');
    }

    // ── Bentuk hasil ──────────────────────────────────────────────────────

    public function testSeluruhPesertaDanButirLengkapTerhitung(): void
    {
        $hasil = $this->hasil();

        $this->assertSame(30, $hasil['peserta']);
        $this->assertSame(10, $hasil['butir_dianalisis']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], array_column($hasil['butir'], 'nomor'));
    }

    public function testEsaiYangBelumSelesaiDikoreksiDikeluarkanDenganAlasannya(): void
    {
        $hasil = $this->hasil();

        $this->assertCount(1, $hasil['butir_dikeluarkan']);
        $this->assertSame(111, $hasil['butir_dikeluarkan'][0]['question_id']);
        $this->assertSame(11, $hasil['butir_dikeluarkan'][0]['nomor']);
        $this->assertSame('Belum selesai dikoreksi', $hasil['butir_dikeluarkan'][0]['alasan']);
    }

    // ── Temuan yang memang jadi tujuan fitur ini ──────────────────────────

    /**
     * Soal 9 kuncinya terbalik. Kelompok atas (8 peserta teratas, semuanya
     * peringkat <= 15) salah semua; kelompok bawah benar semua. D = -1,000.
     */
    public function testSoalBerkunciTerbalikTertangkapSebagaiDayaBedaNegatif(): void
    {
        $b = $this->butir($this->hasil(), 9);

        $this->assertEqualsWithDelta(-1.0, $b['d'], 1e-9);
        $this->assertSame('Sangat buruk', $b['d_label']);
        $this->assertSame('Tolak', $b['saran']);
        $this->assertStringContainsString('kunci', $b['alasan']);
    }

    public function testSoalSehatDisarankanDiterima(): void
    {
        $hasil = $this->hasil();

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 10] as $n) {
            $this->assertSame('Terima', $this->butir($hasil, $n)['saran'], 'Soal ' . $n);
        }
    }

    public function testOpsiYangTidakPernahDipilihDitandaiPengecohMati(): void
    {
        $b    = $this->butir($this->hasil(), 10);
        $mati = array_values(array_filter($b['pengecoh'], static fn($o) => in_array('Pengecoh mati', $o['tanda'], true)));

        $this->assertCount(4, $b['pengecoh']);
        $this->assertCount(2, $mati, 'Opsi C dan D tidak pernah dipilih siapa pun.');
        $this->assertSame(['Opsi C', 'Opsi D'], array_column($mati, 'teks'));
    }

    public function testKunciSoalSepuluhDipilihDuaPuluhPeserta(): void
    {
        $opsi = $this->butir($this->hasil(), 10)['pengecoh'][0];

        $this->assertTrue($opsi['kunci']);
        $this->assertSame(20, $opsi['jumlah']);
        $this->assertEqualsWithDelta(2 / 3, $opsi['proporsi'], 1e-4);
    }

    /**
     * Skor salah bernilai -1 (penalti tebak). Kalau penjepitan bawah tidak
     * jalan, P soal 8 akan keluar dari rentang 0–1.
     */
    public function testPenaltiNegatifTidakMembuatTingkatKesukaranKeluarRentang(): void
    {
        $hasil = $this->hasil();

        foreach ($hasil['butir'] as $b) {
            $this->assertGreaterThanOrEqual(0.0, $b['p'], 'Soal ' . $b['nomor']);
            $this->assertLessThanOrEqual(1.0, $b['p'], 'Soal ' . $b['nomor']);
        }

        // Soal 8: benar hanya untuk peringkat <= 6 → 6/30.
        $this->assertEqualsWithDelta(0.2, $this->butir($hasil, 8)['p'], 1e-9);
        // Soal 1: benar untuk peringkat <= 27 → 27/30.
        $this->assertEqualsWithDelta(0.9, $this->butir($hasil, 1)['p'], 1e-9);
    }

    public function testReliabilitasTerhitungDanTinggiUntukKelasSimulasiIni(): void
    {
        $r = $this->hasil()['ringkasan'];

        $this->assertNotNull($r['alpha']);
        $this->assertGreaterThan(0.7, $r['alpha']);
        $this->assertNotNull($r['sem']);
        $this->assertSame(8, $r['ukuran_kelompok']);  // round(0.27 × 30)
    }

    public function testTigaPuluhPesertaTidakLagiDiberiPitaIndikatif(): void
    {
        $catatan = implode(' ', $this->hasil()['catatan']);

        $this->assertStringNotContainsString('indikatif', $catatan);
        $this->assertStringContainsString('dikeluarkan dari perhitungan', $catatan);
    }

    public function testSemuaSoalPilihanTercatatTerisiBukanKosong(): void
    {
        foreach ($this->hasil()['butir'] as $b) {
            $this->assertSame(0, $b['cacah']['kosong'], 'Soal ' . $b['nomor']);
            $this->assertSame(30, $b['cacah']['benar'] + $b['cacah']['salah'], 'Soal ' . $b['nomor']);
        }
    }

    // ── CSV ───────────────────────────────────────────────────────────────

    public function testCsvMemuatBomHeaderDanSatuBarisTiapButir(): void
    {
        $csv = ItemAnalysisCsv::render($this->hasil(), 'Sumatif Akhir Semester');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM UTF-8 supaya Excel tidak mengacak huruf beraksen.');
        $this->assertStringContainsString('Analisis Butir Soal', $csv);
        $this->assertStringContainsString('Sumatif Akhir Semester', $csv);
        $this->assertStringContainsString('P (kesukaran)', $csv);
        $this->assertStringContainsString('Belum selesai dikoreksi', $csv);
        $this->assertStringContainsString('Pengecoh mati', $csv);

        $baris = ItemAnalysisCsv::baris($this->hasil(), 'X');
        $this->assertSame(['Nomor', 'Tipe', 'Soal', 'P (kesukaran)', 'Kategori kesukaran',
            'D (daya beda)', 'Kategori daya beda', 'r butir-total',
            'Benar', 'Sebagian', 'Salah', 'Kosong', 'Rekomendasi', 'Alasan'], $baris[8]);
        $this->assertSame(1, $baris[9][0]);
        $this->assertSame('PG', $baris[9][1]);
    }

    public function testCsvHasilKosongTetapTerbentukDanMenyebutAlasannya(): void
    {
        $kosong = (new ItemAnalysis(4.0))->analyze([], []);

        $csv = ItemAnalysisCsv::render($kosong, 'Ujian Tanpa Peserta');

        $this->assertStringContainsString('Belum ada peserta', $csv);
        $this->assertStringContainsString('Peserta selesai', $csv);
    }

    public function testCsvAlphaTakTerhitungMenulisAlasanBukanSelKosong(): void
    {
        $scores = [];
        foreach (range(1, 6) as $aid) {
            $scores[$aid] = [1 => (float) ($aid <= 3 ? 1 : 0), 2 => (float) ($aid <= 4 ? 1 : 0)];
        }
        $meta = [
            1 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'A'],
            2 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'B'],
        ];

        $baris = ItemAnalysisCsv::baris((new ItemAnalysis(1.0))->analyze($scores, $meta), 'Kelas Kecil');

        $this->assertSame('Cronbach alpha', $baris[5][0]);
        $this->assertStringContainsString('Peserta kurang dari 10', (string) $baris[5][1]);
    }

    public function testNamaBerkasMembersihkanKarakterTakAman(): void
    {
        $nama = ItemAnalysisCsv::namaBerkas('Sumatif: IPA / Kelas 9-A');

        $this->assertMatchesRegularExpression('/^Analisis_Butir_Sumatif_IPA_Kelas_9_A_\d{8}_\d{6}\.csv$/', $nama);
    }

    public function testCsvMengutipTeksSoalYangMengandungKomaDanTandaKutip(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze(
            [1 => [1 => 1.0], 2 => [1 => 0.0]],
            [1 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'Sebut "ibu kota", lalu jelaskan']],
        );

        $csv = ItemAnalysisCsv::render($hasil, 'X');

        $this->assertStringContainsString('"Sebut ""ibu kota"", lalu jelaskan"', $csv);
    }
}

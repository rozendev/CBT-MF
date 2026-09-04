<?php

namespace Tests\Analytics;

use App\Libraries\ItemAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Angka harapan di berkas ini dihitung tangan lebih dulu (lihat komentar tiap
 * kasus), bukan disalin dari keluaran implementasi. Kalau rumusnya bergeser,
 * uji ini harus gagal — itu gunanya.
 */
class ItemAnalysisTest extends TestCase
{
    /**
     * Data acuan yang dipakai berulang: 10 peserta, 3 butir dikotomis,
     * poin per butir = 1.
     *
     *        A  B  C   total
     *   s1   1  1  1     3
     *   s2   1  1  1     3
     *   s3   1  1  1     3
     *   s4   1  1  0     2
     *   s5   1  1  0     2
     *   s6   1  1  0     2
     *   s7   1  0  0     1
     *   s8   1  0  0     1
     *   s9   0  0  0     0
     *   s10  0  0  0     0
     */
    private function dataAcuan(): array
    {
        $pola = [
            1  => [1, 1, 1],
            2  => [1, 1, 1],
            3  => [1, 1, 1],
            4  => [1, 1, 0],
            5  => [1, 1, 0],
            6  => [1, 1, 0],
            7  => [1, 0, 0],
            8  => [1, 0, 0],
            9  => [0, 0, 0],
            10 => [0, 0, 0],
        ];

        $scores = [];
        foreach ($pola as $aid => $baris) {
            $scores[$aid] = [101 => (float) $baris[0], 102 => (float) $baris[1], 103 => (float) $baris[2]];
        }

        return $scores;
    }

    private function metaAcuan(): array
    {
        return [
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'Butir A'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'Butir B'],
            103 => ['nomor' => 3, 'tipe' => 1, 'teks' => 'Butir C'],
        ];
    }

    private function butirBernomor(array $hasil, int $nomor): array
    {
        foreach ($hasil['butir'] as $b) {
            if ($b['nomor'] === $nomor) return $b;
        }

        $this->fail('Butir nomor ' . $nomor . ' tidak ada di hasil.');
    }

    // ── Gerbang masukan ───────────────────────────────────────────────────

    public function testTanpaPesertaMengembalikanHasilKosongBerpesan(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze([], $this->metaAcuan());

        $this->assertSame(0, $hasil['peserta']);
        $this->assertSame(0, $hasil['butir_dianalisis']);
        $this->assertSame([], $hasil['butir']);
        $this->assertStringContainsString('Belum ada peserta', $hasil['catatan'][0]);
    }

    public function testPoinMaksimumNolDitolakBukanDibagiNol(): void
    {
        $hasil = (new ItemAnalysis(0.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        $this->assertSame(0, $hasil['butir_dianalisis']);
        $this->assertStringContainsString('score_right', $hasil['catatan'][0]);
    }

    public function testPoinMaksimumNegatifJugaDitolak(): void
    {
        $hasil = (new ItemAnalysis(-4.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        $this->assertSame(0, $hasil['butir_dianalisis']);
    }

    // ── Penyaringan butir ─────────────────────────────────────────────────

    public function testButirDenganSkorNullDikeluarkanSebagaiBelumDikoreksi(): void
    {
        $scores = $this->dataAcuan();
        $scores[4][103] = null; // satu esai belum dinilai

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $this->metaAcuan());

        $this->assertSame(2, $hasil['butir_dianalisis']);
        $this->assertCount(1, $hasil['butir_dikeluarkan']);
        $this->assertSame(103, $hasil['butir_dikeluarkan'][0]['question_id']);
        $this->assertSame('Belum selesai dikoreksi', $hasil['butir_dikeluarkan'][0]['alasan']);
    }

    public function testButirYangHilangDiSebagianAttemptDikeluarkanDenganAlasanLain(): void
    {
        $scores = $this->dataAcuan();
        unset($scores[7][102]); // soal ditambahkan setelah s7 selesai

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $this->metaAcuan());

        $this->assertSame(2, $hasil['butir_dianalisis']);
        $this->assertSame('Tidak dikerjakan semua peserta', $hasil['butir_dikeluarkan'][0]['alasan']);
    }

    /**
     * Esai yang belum dikoreksi tidak boleh diperlakukan sebagai nol.
     * Kalau iya, P butir itu jadi 0.00 dan total tiap peserta ikut turun.
     */
    public function testButirBelumDikoreksiTidakIkutKeTotalPeserta(): void
    {
        $scores = $this->dataAcuan();
        foreach ($scores as $aid => $_) {
            $scores[$aid][104] = null;
        }
        $meta        = $this->metaAcuan();
        $meta[104]   = ['nomor' => 4, 'tipe' => 3, 'teks' => 'Esai belum dikoreksi'];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);

        $this->assertSame(3, $hasil['butir_dianalisis']);
        $this->assertSame(3, $hasil['ringkasan']['skor_maksimum']);
        // Rata-rata total tetap 17/10 seperti data acuan tanpa butir esai.
        $this->assertEqualsWithDelta(1.7, $hasil['ringkasan']['rata_rata'], 1e-9);
    }

    public function testSemuaButirTidakLengkapMenghasilkanHasilKosongTapiPesertaTetapDilaporkan(): void
    {
        $scores = [];
        foreach ([1, 2, 3] as $aid) {
            $scores[$aid] = [101 => null];
        }

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 3, 'teks' => 'Esai']]);

        $this->assertSame(3, $hasil['peserta']);
        $this->assertSame(0, $hasil['butir_dianalisis']);
        $this->assertCount(1, $hasil['butir_dikeluarkan']);
    }

    // ── Tingkat kesukaran ─────────────────────────────────────────────────

    public function testTingkatKesukaranAdalahProporsiRerataSkor(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        // A: 8/10, B: 6/10, C: 3/10
        $this->assertEqualsWithDelta(0.8, $this->butirBernomor($hasil, 1)['p'], 1e-9);
        $this->assertEqualsWithDelta(0.6, $this->butirBernomor($hasil, 2)['p'], 1e-9);
        $this->assertEqualsWithDelta(0.3, $this->butirBernomor($hasil, 3)['p'], 1e-9);
    }

    public function testTingkatKesukaranMemakaiPoinSebagianBukanBenarSalah(): void
    {
        // Poin maksimum 4; dua peserta dapat 4, dua dapat 2 → P = (1+1+0.5+0.5)/4
        $scores = [1 => [101 => 4.0], 2 => [101 => 4.0], 3 => [101 => 2.0], 4 => [101 => 2.0]];

        $hasil = (new ItemAnalysis(4.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 4, 'teks' => 'Menjodohkan']]);

        $this->assertEqualsWithDelta(0.75, $hasil['butir'][0]['p'], 1e-9);
    }

    /**
     * score_wrong boleh negatif (penalti tebak). Tanpa penjepitan bawah,
     * tingkat kesukaran bisa keluar dari rentang 0–1 dan kehilangan arti.
     */
    public function testSkorNegatifDijepitKeNolSebelumDirataRata(): void
    {
        $scores = [
            1 => [101 => 4.0],
            2 => [101 => -1.0],
            3 => [101 => -1.0],
            4 => [101 => -1.0],
        ];

        $hasil = (new ItemAnalysis(4.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'PG']]);

        $this->assertEqualsWithDelta(0.25, $hasil['butir'][0]['p'], 1e-9);
    }

    public function testSkorMelebihiPoinMaksimumDijepitKeSatu(): void
    {
        $scores = [1 => [101 => 9.0], 2 => [101 => 0.0]];

        $hasil = (new ItemAnalysis(4.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'PG']]);

        $this->assertEqualsWithDelta(0.5, $hasil['butir'][0]['p'], 1e-9);
    }

    public function testLabelKesukaranDiBatasKategori(): void
    {
        $lib = new ItemAnalysis(1.0);

        $this->assertSame('Sukar', $lib->labelKesukaran(0.29));
        $this->assertSame('Sedang', $lib->labelKesukaran(0.30));
        $this->assertSame('Sedang', $lib->labelKesukaran(0.70));
        $this->assertSame('Mudah', $lib->labelKesukaran(0.71));
    }

    // ── Daya beda ─────────────────────────────────────────────────────────

    /**
     * N=10 → g = round(0.27 × 10) = 3. Urut menurun total:
     * atas = s1,s2,s3 · bawah = s8,s9,s10.
     * Butir A: atas (1,1,1)=1.000 · bawah (1,0,0)=0.333 → D = 0.6667.
     */
    public function testDayaBedaMemakaiKelompokDuaPuluhTujuhPersen(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        $this->assertSame(3, $hasil['ringkasan']['ukuran_kelompok']);
        $this->assertEqualsWithDelta(2 / 3, $this->butirBernomor($hasil, 1)['d'], 1e-4);
        $this->assertSame('Sangat baik', $this->butirBernomor($hasil, 1)['d_label']);
    }

    public function testLabelDayaBedaDiBatasKategori(): void
    {
        $lib = new ItemAnalysis(1.0);

        $this->assertSame('Sangat buruk', $lib->labelDayaBeda(-0.01));
        $this->assertSame('Buruk', $lib->labelDayaBeda(0.0));
        $this->assertSame('Buruk', $lib->labelDayaBeda(0.19));
        $this->assertSame('Cukup', $lib->labelDayaBeda(0.20));
        $this->assertSame('Baik', $lib->labelDayaBeda(0.30));
        $this->assertSame('Sangat baik', $lib->labelDayaBeda(0.40));
    }

    /**
     * Butir berkunci terbalik: kelompok atas justru salah semua.
     * X dan Z menentukan peringkat, Y adalah butir yang kuncinya keliru.
     */
    public function testDayaBedaNegatifMenghasilkanTolakDenganAlasanPeriksaKunci(): void
    {
        $scores = [];
        foreach (range(1, 10) as $aid) {
            $atas = $aid <= 5 ? 1.0 : 0.0;
            $scores[$aid] = [201 => $atas, 202 => $atas, 203 => 1.0 - $atas];
        }
        $meta = [
            201 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'X'],
            202 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'Z'],
            203 => ['nomor' => 3, 'tipe' => 1, 'teks' => 'Y salah kunci'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);
        $y     = $this->butirBernomor($hasil, 3);

        $this->assertEqualsWithDelta(-1.0, $y['d'], 1e-9);
        $this->assertSame('Sangat buruk', $y['d_label']);
        $this->assertSame('Tolak', $y['saran']);
        $this->assertStringContainsString('kunci', $y['alasan']);
    }

    public function testPesertaKurangDariSepuluhTidakMenghitungDayaBeda(): void
    {
        $scores = [];
        foreach (range(1, 5) as $aid) {
            $scores[$aid] = [101 => (float) ($aid <= 3 ? 1 : 0), 102 => (float) ($aid <= 2 ? 1 : 0)];
        }
        $meta = [
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'A'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'B'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);

        $this->assertNull($hasil['butir'][0]['d']);
        $this->assertNull($hasil['butir'][0]['d_label']);
        $this->assertNull($hasil['ringkasan']['ukuran_kelompok']);
        $this->assertSame('Belum dinilai', $hasil['butir'][0]['saran']);
        $this->assertStringContainsString('Peserta hanya 5 orang', $hasil['catatan'][0]);
    }

    public function testPesertaAntaraSepuluhDanTigaPuluhDiberiPitaIndikatif(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        $gabungan = implode(' ', $hasil['catatan']);
        $this->assertStringContainsString('indikatif', $gabungan);
    }

    // ── Korelasi butir-total terkoreksi ───────────────────────────────────

    /**
     * Butir A vs (total − A):
     *   A    = [1,1,1,1,1,1,1,1,0,0]      mean 0.8
     *   rest = [2,2,2,1,1,1,0,0,0,0]      mean 0.9
     *   Σdxdy = 1.80 · Σdx² = 1.60 · Σdy² = 6.90
     *   r = 1.80 / √(1.60 × 6.90) = 1.80 / √11.04 = 0.54173
     */
    public function testKorelasiButirTotalDikoreksiDenganMengeluarkanButirnya(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        $this->assertEqualsWithDelta(0.54173, $this->butirBernomor($hasil, 1)['rpb'], 1e-4);
    }

    public function testKorelasiTidakTerdefinisiSaatButirTanpaRagam(): void
    {
        $scores = [];
        foreach (range(1, 10) as $aid) {
            $scores[$aid] = [101 => 1.0, 102 => (float) ($aid <= 5 ? 1 : 0)];
        }
        $meta = [
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'Semua benar'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'Beragam'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);

        $this->assertNull($this->butirBernomor($hasil, 1)['rpb']);
    }

    // ── Reliabilitas ──────────────────────────────────────────────────────

    /**
     * Data acuan: Σ var butir = 6.10/9, var total = 12.10/9.
     * α = (3/2) × (1 − 6.10/12.10) = 1.5 × 0.4958678 = 0.7438017.
     * SEM = √(12.10/9) × √(1 − α) = 1.1595022 × 0.5061604 = 0.5869.
     */
    public function testCronbachAlphaDanSemSesuaiHitunganTangan(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        $this->assertEqualsWithDelta(0.7438017, $hasil['ringkasan']['alpha'], 1e-4);
        $this->assertSame('Memadai', $hasil['ringkasan']['alpha_label']);
        $this->assertEqualsWithDelta(0.5869, $hasil['ringkasan']['sem'], 1e-3);
        $this->assertEqualsWithDelta(1.1595022, $hasil['ringkasan']['simpangan_baku'], 1e-4);
    }

    public function testLabelAlphaDiBatasKategori(): void
    {
        $lib = new ItemAnalysis(1.0);

        $this->assertSame('Rendah', $lib->labelAlpha(0.59));
        $this->assertSame('Marginal', $lib->labelAlpha(0.60));
        $this->assertSame('Memadai', $lib->labelAlpha(0.70));
        $this->assertSame('Tinggi', $lib->labelAlpha(0.80));
        $this->assertSame('Sangat tinggi', $lib->labelAlpha(0.90));
    }

    public function testAlphaTidakDihitungSaatHanyaSatuButir(): void
    {
        $scores = [];
        foreach (range(1, 12) as $aid) {
            $scores[$aid] = [101 => (float) ($aid <= 6 ? 1 : 0)];
        }

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'A']]);

        $this->assertNull($hasil['ringkasan']['alpha']);
        $this->assertNull($hasil['ringkasan']['sem']);
        $this->assertStringContainsString('minimal 2 butir', $hasil['ringkasan']['alpha_alasan']);
    }

    public function testAlphaTidakDihitungSaatSemuaTotalSama(): void
    {
        $scores = [];
        foreach (range(1, 12) as $aid) {
            $scores[$aid] = [101 => 1.0, 102 => 0.0];
        }
        $meta = [
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'A'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'B'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);

        $this->assertNull($hasil['ringkasan']['alpha']);
        $this->assertStringContainsString('ragam nol', $hasil['ringkasan']['alpha_alasan']);
    }

    public function testAlphaTidakDihitungSaatPesertaTerlaluSedikit(): void
    {
        $scores = [];
        foreach (range(1, 6) as $aid) {
            $scores[$aid] = [101 => (float) ($aid <= 3 ? 1 : 0), 102 => (float) ($aid <= 4 ? 1 : 0)];
        }
        $meta = [
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'A'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'B'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);

        $this->assertNull($hasil['ringkasan']['alpha']);
        $this->assertStringContainsString('Peserta kurang dari 10', $hasil['ringkasan']['alpha_alasan']);
    }

    // ── Rekomendasi ───────────────────────────────────────────────────────

    /**
     * P > 0,90 dan D >= 0,30 sekaligus menuntut peserta banyak: batas
     * atas daya beda untuk butir dikotomis adalah (1 - P) / 0,27, jadi
     * P = 0,91 baru memungkinkan D sampai 0,333. Karena itu kasus ini
     * memakai 100 peserta, bukan satu kelas.
     *
     *   Butir 101 : salah hanya pada s92-s100 (9 orang)  -> P = 0,91
     *   Butir 102 : benar pada s1-s50, penentu peringkat
     *
     * Total: s1-s50 = 2 · s51-s91 = 1 · s92-s100 = 0. Dengan g = 27,
     * kelompok atas seluruhnya dari blok bernilai 2 (101 benar semua,
     * P_atas = 1,000) dan kelompok bawah berisi 18 orang blok bernilai 1
     * ditambah 9 orang blok bernilai 0 (P_bawah = 18/27 = 0,667).
     * D = 0,333.
     */
    public function testButirHampirSemuaBenarDisarankanRevisiMeskiDayaBedaBagus(): void
    {
        $scores = [];
        foreach (range(1, 100) as $aid) {
            $scores[$aid] = [
                101 => (float) ($aid >= 92 ? 0 : 1),
                102 => (float) ($aid <= 50 ? 1 : 0),
            ];
        }
        $meta = [
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'Terlalu mudah'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'Pembeda'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);
        $butir = $this->butirBernomor($hasil, 1);

        $this->assertEqualsWithDelta(0.91, $butir['p'], 1e-9);
        $this->assertEqualsWithDelta(1 / 3, $butir['d'], 1e-4);
        $this->assertSame('Revisi', $butir['saran']);
        $this->assertStringContainsString('P > 0,90', $butir['alasan']);
    }

    /**
     * Butir yang sama sekali tidak berkaitan dengan penguasaan materi.
     *
     * Enam butir penentu peringkat memberi basis 6/4/2/0 untuk empat blok
     * peserta, cukup lebar sehingga butir sasaran (yang hanya menambah 1)
     * tidak lagi ikut menentukan siapa masuk kelompok atas atau bawah —
     * dan tidak ada skor seri tepat di batas kelompok, jadi hasilnya tidak
     * bergantung pada cara memutus seri.
     *
     * Butir sasaran benar pada s1-s3, s6-s7, s11-s12, s16-s18.
     * Kelompok atas s1-s5 -> 3/5 · kelompok bawah s16-s20 -> 3/5 · D = 0.
     */
    public function testButirTanpaDayaBedaDisarankanTolak(): void
    {
        $basis   = static fn(int $aid): int => match (true) {
            $aid <= 5  => 6,
            $aid <= 10 => 4,
            $aid <= 15 => 2,
            default    => 0,
        };
        $sasaran = [1, 2, 3, 6, 7, 11, 12, 16, 17, 18];

        $scores = [];
        $meta   = [];
        foreach (range(1, 20) as $aid) {
            foreach (range(1, 6) as $k) {
                $scores[$aid][200 + $k] = (float) ($k <= $basis($aid) ? 1 : 0);
            }
            $scores[$aid][299] = (float) (in_array($aid, $sasaran, true) ? 1 : 0);
        }
        foreach (range(1, 6) as $k) {
            $meta[200 + $k] = ['nomor' => $k, 'tipe' => 1, 'teks' => 'Penentu peringkat ' . $k];
        }
        $meta[299] = ['nomor' => 7, 'tipe' => 1, 'teks' => 'Tidak berkaitan'];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);
        $butir = $this->butirBernomor($hasil, 7);

        $this->assertEqualsWithDelta(0.5, $butir['p'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $butir['d'], 1e-9);
        $this->assertSame('Buruk', $butir['d_label']);
        $this->assertSame('Tolak', $butir['saran']);
    }

    // ── Cacah jawaban ─────────────────────────────────────────────────────

    public function testCacahMembedakanBenarSebagianSalahDanKosong(): void
    {
        $scores = [
            1 => [101 => 4.0], // benar penuh
            2 => [101 => 2.0], // sebagian
            3 => [101 => 0.0], // menjawab tapi salah
            4 => [101 => 0.0], // tidak menjawab
        ];
        $answered = [
            1 => [101 => true],
            2 => [101 => true],
            3 => [101 => true],
            4 => [101 => false],
        ];

        $hasil = (new ItemAnalysis(4.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 2, 'teks' => 'PG']], $answered);
        $cacah = $hasil['butir'][0]['cacah'];

        $this->assertSame(['benar' => 1, 'sebagian' => 1, 'salah' => 1, 'kosong' => 1], $cacah);
    }

    public function testTanpaBenderaMenjawabSelNolDihitungSalahBukanKosong(): void
    {
        $scores = [1 => [101 => 1.0], 2 => [101 => 0.0]];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'PG']]);

        $this->assertSame(0, $hasil['butir'][0]['cacah']['kosong']);
        $this->assertSame(1, $hasil['butir'][0]['cacah']['salah']);
    }

    // ── Pengecoh ──────────────────────────────────────────────────────────

    /**
     * Peringkat memakai data acuan: atas = s1,s2,s3 · bawah = s8,s9,s10.
     *   A (kunci) dipilih 7 orang            → 0.70
     *   B dipilih hanya s1 (kelompok atas)   → 0.10, atas 1 > bawah 0
     *   C dipilih s9,s10 (kelompok bawah)    → 0.20, pengecoh sehat
     *   D tidak dipilih siapa pun            → 0.00, pengecoh mati
     */
    public function testPengecohMenandaiOpsiMatiDanOpsiPenjebakKelompokAtas(): void
    {
        $optionPicks = [
            101 => [
                ['teks' => 'A', 'kunci' => true,  'dipilih' => [2, 3, 4, 5, 6, 7, 8]],
                ['teks' => 'B', 'kunci' => false, 'dipilih' => [1]],
                ['teks' => 'C', 'kunci' => false, 'dipilih' => [9, 10]],
                ['teks' => 'D', 'kunci' => false, 'dipilih' => []],
            ],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan(), [], $optionPicks);
        $opsi  = $this->butirBernomor($hasil, 1)['pengecoh'];

        $this->assertCount(4, $opsi);

        $this->assertTrue($opsi[0]['kunci']);
        $this->assertSame(7, $opsi[0]['jumlah']);
        $this->assertEqualsWithDelta(0.7, $opsi[0]['proporsi'], 1e-9);
        $this->assertSame([], $opsi[0]['tanda']);

        $this->assertSame(1, $opsi[1]['atas']);
        $this->assertSame(0, $opsi[1]['bawah']);
        $this->assertSame(['Menjebak kelompok atas'], $opsi[1]['tanda']);

        $this->assertSame(0, $opsi[2]['atas']);
        $this->assertSame(2, $opsi[2]['bawah']);
        $this->assertSame([], $opsi[2]['tanda']);

        $this->assertSame(['Pengecoh mati'], $opsi[3]['tanda']);
    }

    public function testKunciTidakPernahDitandaiPengecohMati(): void
    {
        $optionPicks = [
            101 => [
                ['teks' => 'A', 'kunci' => true,  'dipilih' => []],
                ['teks' => 'B', 'kunci' => false, 'dipilih' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
            ],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan(), [], $optionPicks);
        $opsi  = $this->butirBernomor($hasil, 1)['pengecoh'];

        $this->assertSame([], $opsi[0]['tanda']);
    }

    public function testPengecohKosongUntukTipeSoalTanpaOpsi(): void
    {
        $meta = $this->metaAcuan();
        $meta[101]['tipe'] = 3; // esai

        $optionPicks = [
            101 => [['teks' => 'A', 'kunci' => true, 'dipilih' => []]],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $meta, [], $optionPicks);

        $this->assertSame([], $this->butirBernomor($hasil, 1)['pengecoh']);
    }

    public function testPengecohTanpaKelompokSaatPesertaSedikit(): void
    {
        $scores = [];
        foreach (range(1, 4) as $aid) {
            $scores[$aid] = [101 => (float) ($aid <= 2 ? 1 : 0)];
        }
        $optionPicks = [101 => [['teks' => 'A', 'kunci' => true, 'dipilih' => [1, 2]]]];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, [101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'PG']], [], $optionPicks);
        $opsi  = $hasil['butir'][0]['pengecoh'][0];

        $this->assertNull($opsi['atas']);
        $this->assertNull($opsi['bawah']);
        $this->assertSame([], $opsi['tanda']);
    }

    // ── Bentuk keluaran ───────────────────────────────────────────────────

    public function testButirDiurutkanMenurutNomorTampilan(): void
    {
        $scores = $this->dataAcuan();
        $meta   = [
            103 => ['nomor' => 3, 'tipe' => 1, 'teks' => 'C'],
            101 => ['nomor' => 1, 'tipe' => 1, 'teks' => 'A'],
            102 => ['nomor' => 2, 'tipe' => 1, 'teks' => 'B'],
        ];

        $hasil = (new ItemAnalysis(1.0))->analyze($scores, $meta);

        $this->assertSame([1, 2, 3], array_column($hasil['butir'], 'nomor'));
    }

    public function testRingkasanMelaporkanRataRataDalamPersenSkalaButir(): void
    {
        $hasil = (new ItemAnalysis(1.0))->analyze($this->dataAcuan(), $this->metaAcuan());

        // Rata-rata total 1.7 dari maksimum 3 butir → 56.67%
        $this->assertEqualsWithDelta(56.67, $hasil['ringkasan']['rata_rata_persen'], 0.01);
        $this->assertSame(3, $hasil['ringkasan']['skor_maksimum']);
    }
}

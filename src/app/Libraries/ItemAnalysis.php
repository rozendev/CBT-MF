<?php

namespace App\Libraries;

/**
 * Analisis butir soal (item analysis) untuk satu ujian.
 *
 * Kelas ini sengaja murni: array masuk, array keluar. Tidak menyentuh
 * database, session, cache, maupun helper CodeIgniter, supaya seluruh
 * aritmetikanya bisa diuji dengan PHPUnit polos tanpa fixture DB.
 * Pengambilan datanya jadi tanggung jawab Admin\ItemAnalysisController.
 *
 * Rujukan rancangan: docs/superpowers/specs/2026-09-04-analisis-butir-soal-design.md
 */
final class ItemAnalysis
{
    /** Proporsi kelompok atas/bawah untuk daya beda (konvensi Kelley). */
    public const GROUP_FRACTION = 0.27;

    /** Di bawah ini daya beda & alpha tidak ditampilkan sama sekali. */
    public const MIN_PESERTA_DAYA_BEDA = 10;

    /** Di bawah ini angka tetap tampil tapi diberi pita "indikatif". */
    public const MIN_PESERTA_STABIL = 30;

    /** Pengecoh dengan pemilih di bawah proporsi ini dianggap mati. */
    public const AMBANG_PENGECOH_MATI = 0.05;

    /** Tipe soal yang punya opsi terpilih, jadi bisa dianalisis pengecohnya. */
    private const TIPE_BEROPSI = [1, 2];

    /** Toleransi banding float; skor melewati DECIMAL(10,3) di database. */
    private const EPS = 1.0e-9;

    public function __construct(private float $maxPoints)
    {
    }

    /**
     * @param array<int, array<int, float|null>> $scores
     *        [attemptId][questionId] => skor mentah butir, NULL = belum dinilai.
     * @param array<int, array{nomor:int, tipe:int, teks:string}> $itemMeta
     *        [questionId] => metadata butir untuk penyajian.
     * @param array<int, array<int, bool>> $answered
     *        [attemptId][questionId] => siswa mengisi sesuatu? Sel yang tak
     *        diketahui dihitung sebagai "salah", bukan "kosong".
     * @param array<int, list<array{teks:string, kunci:bool, dipilih:list<int>}>> $optionPicks
     *        [questionId] => daftar opsi beserta attemptId yang memilihnya.
     */
    public function analyze(array $scores, array $itemMeta, array $answered = [], array $optionPicks = []): array
    {
        $attemptIds = array_keys($scores);
        $peserta    = count($attemptIds);
        $catatan    = [];

        if ($peserta === 0) {
            return $this->kosong('Belum ada peserta yang menyelesaikan ujian ini.');
        }

        if ($this->maxPoints <= 0) {
            // Tanpa poin maksimum yang positif, normalisasi skor tidak punya
            // makna: pembaginya nol atau membalik tanda. Lebih baik menolak
            // daripada menyajikan angka yang kelihatan sah tapi tidak berarti.
            return $this->kosong('Poin per butir (score_right) ujian ini bukan angka positif, analisis tidak dapat dihitung.');
        }

        // ── 1. Pilah butir yang layak dihitung ────────────────────────────
        // Satu butir ikut hanya bila punya skor angka untuk SEMUA peserta.
        // Matriks berlubang akan memalsukan alpha dan korelasi butir-total.
        $dipakai      = [];
        $dikeluarkan  = [];

        foreach ($itemMeta as $qid => $meta) {
            $adaHilang = false;
            $adaNull   = false;

            foreach ($attemptIds as $aid) {
                if (!array_key_exists($qid, $scores[$aid])) {
                    $adaHilang = true;
                    break;
                }
                if ($scores[$aid][$qid] === null) {
                    $adaNull = true;
                }
            }

            if ($adaHilang) {
                $dikeluarkan[] = [
                    'question_id' => (int) $qid,
                    'nomor'       => (int) $meta['nomor'],
                    'teks'        => (string) $meta['teks'],
                    'alasan'      => 'Tidak dikerjakan semua peserta',
                ];
                continue;
            }

            if ($adaNull) {
                $dikeluarkan[] = [
                    'question_id' => (int) $qid,
                    'nomor'       => (int) $meta['nomor'],
                    'teks'        => (string) $meta['teks'],
                    'alasan'      => 'Belum selesai dikoreksi',
                ];
                continue;
            }

            $dipakai[] = (int) $qid;
        }

        $butir = count($dipakai);

        if ($butir === 0) {
            $hasil = $this->kosong('Tidak ada butir yang punya skor lengkap untuk semua peserta.');
            $hasil['peserta']          = $peserta;
            $hasil['butir_dikeluarkan'] = $dikeluarkan;

            return $hasil;
        }

        // ── 2. Normalisasi ke 0–1 dan total per peserta ───────────────────
        // Penjepitan bawah wajib: score_wrong / score_unanswered boleh negatif
        // (penalti tebak), dan tingkat kesukaran negatif tidak punya arti.
        $norm  = [];
        $total = [];

        foreach ($attemptIds as $aid) {
            $t = 0.0;
            foreach ($dipakai as $qid) {
                $n = (float) $scores[$aid][$qid] / $this->maxPoints;
                if ($n < 0.0) $n = 0.0;
                if ($n > 1.0) $n = 1.0;

                $norm[$aid][$qid] = $n;
                $t += $n;
            }
            $total[$aid] = $t;
        }

        // ── 3. Kelompok atas & bawah 27% ──────────────────────────────────
        $pakaiKelompok = $peserta >= self::MIN_PESERTA_DAYA_BEDA;
        $atas = $bawah = [];

        if ($pakaiKelompok) {
            $urut = $attemptIds;
            usort($urut, static fn($a, $b) => $total[$b] <=> $total[$a]);

            $g     = max(1, (int) round(self::GROUP_FRACTION * $peserta));
            $atas  = array_slice($urut, 0, $g);
            $bawah = array_slice($urut, -$g);
        }

        $setAtas  = array_flip($atas);
        $setBawah = array_flip($bawah);

        // ── 4. Statistik per butir ────────────────────────────────────────
        $hasilButir = [];
        $varButir   = 0.0;

        foreach ($dipakai as $qid) {
            $meta = $itemMeta[$qid];
            $x    = [];
            foreach ($attemptIds as $aid) {
                $x[$aid] = $norm[$aid][$qid];
            }

            $p = array_sum($x) / $peserta;
            $varButir += $this->variance(array_values($x));

            $d = null;
            if ($pakaiKelompok) {
                $pAtas  = $this->meanOf($x, $atas);
                $pBawah = $this->meanOf($x, $bawah);
                $d      = $pAtas - $pBawah;
            }

            // Korelasi butir-total terkoreksi: butirnya dikeluarkan dari
            // total pembanding, kalau tidak setiap butir berkorelasi dengan
            // dirinya sendiri dan angkanya menggelembung di tes pendek.
            $rest = [];
            $item = [];
            foreach ($attemptIds as $aid) {
                $item[] = $x[$aid];
                $rest[] = $total[$aid] - $x[$aid];
            }
            $rpb = $this->pearson($item, $rest);

            $cacah = ['benar' => 0, 'sebagian' => 0, 'salah' => 0, 'kosong' => 0];
            foreach ($attemptIds as $aid) {
                $n = $x[$aid];
                if ($n >= 1.0 - self::EPS) {
                    $cacah['benar']++;
                } elseif ($n > self::EPS) {
                    $cacah['sebagian']++;
                } elseif (($answered[$aid][$qid] ?? true) === false) {
                    $cacah['kosong']++;
                } else {
                    $cacah['salah']++;
                }
            }

            $saran = $this->rekomendasi($p, $d);

            $hasilButir[] = [
                'question_id'  => (int) $qid,
                'nomor'        => (int) $meta['nomor'],
                'tipe'         => (int) $meta['tipe'],
                'teks'         => (string) $meta['teks'],
                'p'            => round($p, 4),
                'p_label'      => $this->labelKesukaran($p),
                'd'            => $d === null ? null : round($d, 4),
                'd_label'      => $d === null ? null : $this->labelDayaBeda($d),
                'rpb'          => $rpb === null ? null : round($rpb, 4),
                'cacah'        => $cacah,
                'saran'        => $saran['saran'],
                'alasan'       => $saran['alasan'],
                'pengecoh'     => in_array((int) $meta['tipe'], self::TIPE_BEROPSI, true)
                    ? $this->pengecoh($optionPicks[$qid] ?? [], $peserta, $setAtas, $setBawah, $pakaiKelompok)
                    : [],
            ];
        }

        usort($hasilButir, static fn($a, $b) => $a['nomor'] <=> $b['nomor']);
        usort($dikeluarkan, static fn($a, $b) => $a['nomor'] <=> $b['nomor']);

        // ── 5. Statistik seluruh tes ──────────────────────────────────────
        $nilaiTotal = array_values($total);
        $rata       = array_sum($nilaiTotal) / $peserta;
        $varTotal   = $this->variance($nilaiTotal);
        $sd         = sqrt($varTotal);

        $alpha      = null;
        $alasanAlpha = null;

        if ($butir < 2) {
            $alasanAlpha = 'Butuh minimal 2 butir lengkap untuk menghitung alpha.';
        } elseif ($peserta < self::MIN_PESERTA_DAYA_BEDA) {
            $alasanAlpha = 'Peserta kurang dari ' . self::MIN_PESERTA_DAYA_BEDA . ' orang.';
        } elseif ($varTotal <= self::EPS) {
            $alasanAlpha = 'Semua peserta memperoleh total yang sama, ragam nol.';
        } else {
            $alpha = ($butir / ($butir - 1)) * (1 - ($varButir / $varTotal));
        }

        $sem = ($alpha !== null && $alpha >= 0.0 && $alpha <= 1.0)
            ? $sd * sqrt(1 - $alpha)
            : null;

        // ── 6. Peringatan batas keberlakuan ───────────────────────────────
        if (!$pakaiKelompok) {
            $catatan[] = 'Peserta hanya ' . $peserta . ' orang. Daya beda dan reliabilitas tidak dihitung: '
                . 'kelompok 27% dari jumlah sekecil ini berisi satu-dua orang, dan selisih satu-dua orang bukan pengukuran.';
        } elseif ($peserta < self::MIN_PESERTA_STABIL) {
            $catatan[] = 'Peserta ' . $peserta . ' orang (di bawah ' . self::MIN_PESERTA_STABIL . '). '
                . 'Angka daya beda dan reliabilitas bersifat indikatif — pergeseran satu-dua siswa bisa memindahkan butir antar kategori.';
        }

        if (!empty($dikeluarkan)) {
            $catatan[] = count($dikeluarkan) . ' butir dikeluarkan dari perhitungan karena skornya belum lengkap untuk semua peserta. Rinciannya ada di daftar terpisah.';
        }

        if ($pakaiKelompok) {
            $catatan[] = 'Kelompok atas dan bawah masing-masing ' . count($atas) . ' peserta (27%). '
                . 'Bila ada skor total yang seri tepat di batas kelompok, pemotongannya arbitrer — batasan yang melekat pada metode ini.';
        }

        return [
            'peserta'           => $peserta,
            'butir_dianalisis'  => $butir,
            'butir_dikeluarkan' => $dikeluarkan,
            'ringkasan'         => [
                'rata_rata'       => round($rata, 4),
                'rata_rata_persen'=> round(($rata / $butir) * 100, 2),
                'simpangan_baku'  => round($sd, 4),
                'skor_maksimum'   => $butir,
                'alpha'           => $alpha === null ? null : round($alpha, 4),
                'alpha_label'     => $alpha === null ? null : $this->labelAlpha($alpha),
                'alpha_alasan'    => $alasanAlpha,
                'sem'             => $sem === null ? null : round($sem, 4),
                'ukuran_kelompok' => $pakaiKelompok ? count($atas) : null,
            ],
            'butir'             => $hasilButir,
            'catatan'           => $catatan,
        ];
    }

    // ── Penyajian ambang ──────────────────────────────────────────────────

    public function labelKesukaran(float $p): string
    {
        if ($p < 0.30) return 'Sukar';
        if ($p > 0.70) return 'Mudah';

        return 'Sedang';
    }

    public function labelDayaBeda(float $d): string
    {
        if ($d < 0.0)  return 'Sangat buruk';
        if ($d < 0.20) return 'Buruk';
        if ($d < 0.30) return 'Cukup';
        if ($d < 0.40) return 'Baik';

        return 'Sangat baik';
    }

    public function labelAlpha(float $alpha): string
    {
        if ($alpha >= 0.90) return 'Sangat tinggi';
        if ($alpha >= 0.80) return 'Tinggi';
        if ($alpha >= 0.70) return 'Memadai';
        if ($alpha >= 0.60) return 'Marginal';

        return 'Rendah';
    }

    /**
     * Rekomendasi tindak lanjut butir. Bila daya beda tidak dihitung
     * (peserta terlalu sedikit), keputusan sengaja ditahan — memberi vonis
     * "Terima" hanya dari tingkat kesukaran akan menyesatkan.
     *
     * @return array{saran:string, alasan:string}
     */
    private function rekomendasi(float $p, ?float $d): array
    {
        if ($d === null) {
            return [
                'saran'  => 'Belum dinilai',
                'alasan' => 'Daya beda tidak dihitung karena peserta kurang dari ' . self::MIN_PESERTA_DAYA_BEDA . ' orang.',
            ];
        }

        if ($d < 0.0) {
            return [
                'saran'  => 'Tolak',
                'alasan' => 'Daya beda negatif: kelompok atas justru lebih banyak salah. Periksa kunci jawaban lebih dulu — pola ini lebih sering berarti kuncinya keliru daripada berarti soalnya sukar.',
            ];
        }

        if ($d < 0.20) {
            return [
                'saran'  => 'Tolak',
                'alasan' => 'Daya beda di bawah 0,20: soal ini nyaris tidak memisahkan siswa yang menguasai materi dari yang tidak.',
            ];
        }

        if ($d < 0.30) {
            return [
                'saran'  => 'Revisi',
                'alasan' => 'Daya beda 0,20–0,29 masih di batas cukup. Tinjau rumusan soal dan pengecohnya.',
            ];
        }

        if ($p < 0.20) {
            return [
                'saran'  => 'Revisi',
                'alasan' => 'Daya bedanya memadai tapi hampir semua peserta salah (P < 0,20). Periksa apakah materinya memang belum diajarkan atau rumusannya membingungkan.',
            ];
        }

        if ($p > 0.90) {
            return [
                'saran'  => 'Revisi',
                'alasan' => 'Daya bedanya memadai tapi hampir semua peserta benar (P > 0,90). Butir seperti ini hanya menambah panjang tes tanpa menambah informasi.',
            ];
        }

        return [
            'saran'  => 'Terima',
            'alasan' => 'Tingkat kesukaran dan daya beda berada dalam rentang yang layak dipakai ulang.',
        ];
    }

    // ── Pengecoh ──────────────────────────────────────────────────────────

    /**
     * @param list<array{teks:string, kunci:bool, dipilih:list<int>}> $opsi
     * @param array<int,int> $setAtas
     * @param array<int,int> $setBawah
     */
    private function pengecoh(array $opsi, int $peserta, array $setAtas, array $setBawah, bool $pakaiKelompok): array
    {
        $hasil = [];

        foreach ($opsi as $o) {
            $dipilih = $o['dipilih'] ?? [];
            $jumlah  = count($dipilih);
            $kunci   = (bool) ($o['kunci'] ?? false);

            $atas = $bawah = null;
            if ($pakaiKelompok) {
                $atas = $bawah = 0;
                foreach ($dipilih as $aid) {
                    if (isset($setAtas[$aid]))  $atas++;
                    if (isset($setBawah[$aid])) $bawah++;
                }
            }

            $proporsi = $peserta > 0 ? $jumlah / $peserta : 0.0;
            $tanda    = [];

            if (!$kunci && $proporsi < self::AMBANG_PENGECOH_MATI) {
                $tanda[] = 'Pengecoh mati';
            }
            if (!$kunci && $pakaiKelompok && $atas > $bawah) {
                $tanda[] = 'Menjebak kelompok atas';
            }

            $hasil[] = [
                'teks'     => (string) ($o['teks'] ?? ''),
                'kunci'    => $kunci,
                'jumlah'   => $jumlah,
                'proporsi' => round($proporsi, 4),
                'atas'     => $atas,
                'bawah'    => $bawah,
                'tanda'    => $tanda,
            ];
        }

        return $hasil;
    }

    // ── Aritmetika ────────────────────────────────────────────────────────

    /** Ragam sampel (pembagi n-1). Nol untuk n < 2. */
    private function variance(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;

        $mean = array_sum($values) / $n;
        $sum  = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return $sum / ($n - 1);
    }

    /** @param array<int,float> $x @param list<int> $ids */
    private function meanOf(array $x, array $ids): float
    {
        if (empty($ids)) return 0.0;

        $sum = 0.0;
        foreach ($ids as $id) {
            $sum += $x[$id];
        }

        return $sum / count($ids);
    }

    /**
     * Korelasi Pearson. NULL bila salah satu deret tidak punya ragam —
     * korelasinya memang tidak terdefinisi di situ, bukan nol.
     */
    private function pearson(array $a, array $b): ?float
    {
        $n = count($a);
        if ($n < 2 || $n !== count($b)) return null;

        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;

        $cov = $sa = $sb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $meanA;
            $db = $b[$i] - $meanB;
            $cov += $da * $db;
            $sa  += $da * $da;
            $sb  += $db * $db;
        }

        if ($sa <= self::EPS || $sb <= self::EPS) return null;

        return $cov / sqrt($sa * $sb);
    }

    private function kosong(string $pesan): array
    {
        return [
            'peserta'           => 0,
            'butir_dianalisis'  => 0,
            'butir_dikeluarkan' => [],
            'ringkasan'         => [
                'rata_rata'        => null,
                'rata_rata_persen' => null,
                'simpangan_baku'   => null,
                'skor_maksimum'    => 0,
                'alpha'            => null,
                'alpha_label'      => null,
                'alpha_alasan'     => $pesan,
                'sem'              => null,
                'ukuran_kelompok'  => null,
            ],
            'butir'             => [],
            'catatan'           => [$pesan],
        ];
    }
}

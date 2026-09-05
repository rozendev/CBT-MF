<?php

namespace App\Libraries;

/**
 * Menyusun baris CSV dari hasil ItemAnalysis.
 *
 * Dipisah dari controller supaya bentuk berkasnya bisa diuji tanpa
 * menjalankan HTTP request, termasuk saat hasil analisisnya kosong.
 */
final class ItemAnalysisCsv
{
    private const LABEL_TIPE = [
        1 => 'PG',
        2 => 'PG Kompleks',
        3 => 'Esai',
        4 => 'Menjodohkan',
        5 => 'Benar/Salah',
    ];

    public static function labelTipe(int $tipe): string
    {
        return self::LABEL_TIPE[$tipe] ?? 'Lainnya';
    }

    /**
     * @param array $hasil keluaran ItemAnalysis::analyze()
     * @return list<list<scalar|null>>
     */
    public static function baris(array $hasil, string $namaUjian): array
    {
        $r = $hasil['ringkasan'];

        $baris = [
            ['Analisis Butir Soal', $namaUjian],
            ['Peserta selesai', $hasil['peserta']],
            ['Butir dianalisis', $hasil['butir_dianalisis']],
            ['Rata-rata', $r['rata_rata'], 'dari', $r['skor_maksimum']],
            ['Simpangan baku', $r['simpangan_baku']],
            // Sel alpha tidak pernah dibiarkan kosong: kalau tidak dihitung,
            // alasannya yang ditulis, supaya pembaca CSV tahu bedanya
            // "tidak dihitung" dengan "kebetulan nol".
            ['Cronbach alpha', $r['alpha'] ?? $r['alpha_alasan']],
            ['SEM', $r['sem']],
            [],
            [
                'Nomor', 'Tipe', 'Soal', 'P (kesukaran)', 'Kategori kesukaran',
                'D (daya beda)', 'Kategori daya beda', 'r butir-total',
                'Benar', 'Sebagian', 'Salah', 'Kosong', 'Rekomendasi', 'Alasan',
            ],
        ];

        foreach ($hasil['butir'] as $b) {
            $baris[] = [
                $b['nomor'],
                self::labelTipe((int) $b['tipe']),
                $b['teks'],
                $b['p'],
                $b['p_label'],
                $b['d'],
                $b['d_label'],
                $b['rpb'],
                $b['cacah']['benar'],
                $b['cacah']['sebagian'],
                $b['cacah']['salah'],
                $b['cacah']['kosong'],
                $b['saran'],
                $b['alasan'],
            ];
        }

        foreach ($hasil['butir'] as $b) {
            if (empty($b['pengecoh'])) {
                continue;
            }

            $baris[] = [];
            $baris[] = ['Pengecoh soal ' . $b['nomor'], $b['teks']];
            $baris[] = ['Opsi', 'Kunci', 'Dipilih', 'Proporsi', 'Kel. atas', 'Kel. bawah', 'Catatan'];
            foreach ($b['pengecoh'] as $o) {
                $baris[] = [
                    $o['teks'],
                    $o['kunci'] ? 'Ya' : '',
                    $o['jumlah'],
                    $o['proporsi'],
                    $o['atas'],
                    $o['bawah'],
                    implode('; ', $o['tanda']),
                ];
            }
        }

        if (!empty($hasil['butir_dikeluarkan'])) {
            $baris[] = [];
            $baris[] = ['Butir yang dikeluarkan dari perhitungan'];
            $baris[] = ['Nomor', 'Soal', 'Alasan'];
            foreach ($hasil['butir_dikeluarkan'] as $b) {
                $baris[] = [$b['nomor'], $b['teks'], $b['alasan']];
            }
        }

        if (!empty($hasil['catatan'])) {
            $baris[] = [];
            $baris[] = ['Catatan'];
            foreach ($hasil['catatan'] as $c) {
                $baris[] = [$c];
            }
        }

        return $baris;
    }

    /** Merangkai baris jadi teks CSV, lengkap dengan BOM UTF-8 untuk Excel. */
    public static function render(array $hasil, string $namaUjian): string
    {
        $keluar = fopen('php://temp', 'r+');
        fwrite($keluar, "\xEF\xBB\xBF");

        foreach (self::baris($hasil, $namaUjian) as $b) {
            fputcsv($keluar, $b, ',', '"', '\\');
        }

        rewind($keluar);
        $isi = stream_get_contents($keluar);
        fclose($keluar);

        return $isi;
    }

    public static function namaBerkas(string $namaUjian): string
    {
        return 'Analisis_Butir_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $namaUjian)
            . '_' . date('Ymd_His') . '.csv';
    }
}

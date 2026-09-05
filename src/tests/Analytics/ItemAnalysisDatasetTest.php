<?php

namespace Tests\Analytics;

use App\Libraries\ItemAnalysisDataset;
use PHPUnit\Framework\TestCase;

class ItemAnalysisDatasetTest extends TestCase
{
    /** Baris test_logs tiruan. */
    private function log(int $aid, int $qid, ?float $score, int $tipe = 1, int $order = 1, string $teks = 'Soal', ?string $jawaban = null): object
    {
        return (object) [
            'test_attempt_id' => $aid,
            'question_id'     => $qid,
            'score'           => $score,
            'question_type'   => $tipe,
            'question_text'   => $teks,
            'display_order'   => $order,
            'answer_text'     => $jawaban,
        ];
    }

    /** Baris test_log_answers tiruan. */
    private function opsi(int $aid, int $qid, int $ansId, string $teks, int $benar, int $dipilih): object
    {
        return (object) [
            'test_attempt_id' => $aid,
            'question_id'     => $qid,
            'answer_id'       => $ansId,
            'answer_text'     => $teks,
            'is_correct'      => $benar,
            'is_selected'     => $dipilih,
        ];
    }

    // ── Penjajaran butir ──────────────────────────────────────────────────

    /**
     * Inti fitur ini: dua peserta melihat soal yang sama pada nomor tampilan
     * yang berbeda (pengacakan soal aktif). Skor harus berkumpul menurut
     * question_id, bukan menurut display_order.
     */
    public function testSkorDijajarkanMenurutQuestionIdBukanUrutanTampilan(): void
    {
        $logs = [
            // s1 melihat soal 101 di nomor 1 dan soal 102 di nomor 2
            $this->log(1, 101, 1.0, 1, 1),
            $this->log(1, 102, 0.0, 1, 2),
            // s2 melihat urutan terbalik
            $this->log(2, 101, 0.0, 1, 2),
            $this->log(2, 102, 1.0, 1, 1),
        ];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertSame(1.0, $set['scores'][1][101]);
        $this->assertSame(0.0, $set['scores'][2][101]);
        $this->assertSame(0.0, $set['scores'][1][102]);
        $this->assertSame(1.0, $set['scores'][2][102]);
    }

    public function testUrutanTampilanBerbedaMenonaktifkanNomorBersama(): void
    {
        $logs = [
            $this->log(1, 101, 1.0, 1, 1),
            $this->log(1, 102, 1.0, 1, 2),
            $this->log(2, 101, 1.0, 1, 2),
            $this->log(2, 102, 1.0, 1, 1),
        ];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertFalse($set['nomor_seragam']);
        // Penomoran cadangan: urut question_id menaik, 1..k.
        $this->assertSame(1, $set['meta'][101]['nomor']);
        $this->assertSame(2, $set['meta'][102]['nomor']);
    }

    public function testUrutanTampilanSeragamDipakaiSebagaiNomorSoal(): void
    {
        $logs = [
            $this->log(1, 205, 1.0, 1, 1),
            $this->log(1, 101, 1.0, 1, 2),
            $this->log(2, 205, 0.0, 1, 1),
            $this->log(2, 101, 1.0, 1, 2),
        ];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertTrue($set['nomor_seragam']);
        // question_id 205 lebih besar dari 101 tapi tampil lebih dulu; nomor
        // harus mengikuti layar siswa, bukan urutan id.
        $this->assertSame(1, $set['meta'][205]['nomor']);
        $this->assertSame(2, $set['meta'][101]['nomor']);
    }

    /**
     * Nomor yang seragam per butir tapi bertabrakan antar butir tetap tidak
     * bisa dipakai — dua soal tidak boleh sama-sama jadi "soal nomor 1".
     */
    public function testNomorBersamaDitolakSaatDuaButirBerbagiNomorYangSama(): void
    {
        $logs = [
            $this->log(1, 101, 1.0, 1, 1),
            $this->log(1, 102, 1.0, 1, 1),
            $this->log(2, 101, 1.0, 1, 1),
            $this->log(2, 102, 1.0, 1, 1),
        ];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertFalse($set['nomor_seragam']);
        $this->assertSame([1, 2], [$set['meta'][101]['nomor'], $set['meta'][102]['nomor']]);
    }

    // ── Bendera "siswa mengisi" ───────────────────────────────────────────

    public function testSoalPilihanDitandaiTerisiLewatOpsiTerpilih(): void
    {
        $logs = [$this->log(1, 101, 0.0, 1, 1, 'PG', null)];
        $opsi = [
            $this->opsi(1, 101, 900, 'A', 1, 0),
            $this->opsi(1, 101, 901, 'B', 0, 1),
        ];

        $set = ItemAnalysisDataset::susun($logs, $opsi);

        $this->assertTrue($set['answered'][1][101]);
    }

    public function testSoalPilihanTanpaOpsiTerpilihDianggapKosong(): void
    {
        $logs = [$this->log(1, 101, 0.0, 1, 1, 'PG', null)];
        $opsi = [
            $this->opsi(1, 101, 900, 'A', 1, 0),
            $this->opsi(1, 101, 901, 'B', 0, 0),
        ];

        $set = ItemAnalysisDataset::susun($logs, $opsi);

        $this->assertFalse($set['answered'][1][101]);
    }

    public function testEsaiDitandaiTerisiLewatAnswerText(): void
    {
        $logs = [
            $this->log(1, 101, 2.0, 3, 1, 'Esai', 'Menurut saya…'),
            $this->log(2, 101, 0.0, 3, 1, 'Esai', '   '),
            $this->log(3, 101, 0.0, 3, 1, 'Esai', null),
        ];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertTrue($set['answered'][1][101]);
        $this->assertFalse($set['answered'][2][101], 'Spasi saja bukan jawaban.');
        $this->assertFalse($set['answered'][3][101]);
    }

    // ── Opsi ──────────────────────────────────────────────────────────────

    public function testOpsiDikumpulkanLintasPesertaDanDiurutkanMenurutAnswerId(): void
    {
        $logs = [
            $this->log(1, 101, 1.0, 1, 1),
            $this->log(2, 101, 0.0, 1, 1),
        ];
        // Urutan baris sengaja diacak seperti tampilan yang di-shuffle.
        $opsi = [
            $this->opsi(1, 101, 902, 'C', 0, 0),
            $this->opsi(1, 101, 900, 'A', 1, 1),
            $this->opsi(1, 101, 901, 'B', 0, 0),
            $this->opsi(2, 101, 901, 'B', 0, 1),
            $this->opsi(2, 101, 900, 'A', 1, 0),
            $this->opsi(2, 101, 902, 'C', 0, 0),
        ];

        $set = ItemAnalysisDataset::susun($logs, $opsi);
        $opt = $set['optionPicks'][101];

        $this->assertSame(['A', 'B', 'C'], array_column($opt, 'teks'));
        $this->assertTrue($opt[0]['kunci']);
        $this->assertSame([1], $opt[0]['dipilih']);
        $this->assertSame([2], $opt[1]['dipilih']);
        $this->assertSame([], $opt[2]['dipilih']);
    }

    public function testTanpaBarisOpsiDaftarPengecohKosong(): void
    {
        $set = ItemAnalysisDataset::susun([$this->log(1, 101, 1.0, 3, 1)], []);

        $this->assertSame([], $set['optionPicks']);
    }

    // ── Metadata ──────────────────────────────────────────────────────────

    public function testSkorNullDiteruskanApaAdanyaBukanDijadikanNol(): void
    {
        $set = ItemAnalysisDataset::susun([$this->log(1, 101, null, 3, 1)], []);

        $this->assertNull($set['scores'][1][101]);
    }

    public function testTeksSoalDibersihkanDariHtml(): void
    {
        $logs = [$this->log(1, 101, 1.0, 1, 1, '<p>Ibu kota <b>Indonesia</b>?</p>')];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertSame('Ibu kota Indonesia?', $set['meta'][101]['teks']);
    }

    public function testTeksKosongDiberiPenandaBukanStringHampa(): void
    {
        $this->assertSame('(tanpa teks)', ItemAnalysisDataset::ringkasTeks('<img src="x.png">'));
        $this->assertSame('(tanpa teks)', ItemAnalysisDataset::ringkasTeks('   '));
    }

    public function testTeksPanjangDipotongDenganElipsis(): void
    {
        $panjang = str_repeat('a', 200);

        $hasil = ItemAnalysisDataset::ringkasTeks($panjang, 160);

        $this->assertSame(161, mb_strlen($hasil));
        $this->assertStringEndsWith('…', $hasil);
    }

    public function testTipeSoalIkutKeMetadata(): void
    {
        $logs = [
            $this->log(1, 101, 1.0, 1, 1),
            $this->log(1, 102, 1.0, 4, 2),
        ];

        $set = ItemAnalysisDataset::susun($logs, []);

        $this->assertSame(1, $set['meta'][101]['tipe']);
        $this->assertSame(4, $set['meta'][102]['tipe']);
    }
}

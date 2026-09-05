<?php

namespace App\Libraries;

/**
 * Mengubah baris mentah test_logs / test_log_answers menjadi bentuk masukan
 * yang dimengerti ItemAnalysis.
 *
 * Dipisah dari controller supaya bagian yang paling rawan salah — penjajaran
 * butir antar peserta dan penomorannya — bisa diuji tanpa database.
 *
 * Rujukan rancangan: docs/superpowers/specs/2026-09-04-analisis-butir-soal-design.md
 */
final class ItemAnalysisDataset
{
    /**
     * @param list<object> $logs     baris test_logs: test_attempt_id, question_id,
     *                               score, question_type, question_text,
     *                               display_order, answer_text
     * @param list<object> $opsiRows baris test_log_answers untuk soal beropsi:
     *                               test_attempt_id, question_id, answer_id,
     *                               answer_text, is_correct, is_selected
     *
     * @return array{
     *     scores: array<int, array<int, float|null>>,
     *     meta: array<int, array{nomor:int, tipe:int, teks:string}>,
     *     answered: array<int, array<int, bool>>,
     *     optionPicks: array<int, list<array{teks:string, kunci:bool, dipilih:list<int>}>>,
     *     nomor_seragam: bool
     * }
     */
    public static function susun(array $logs, array $opsiRows): array
    {
        $scores   = [];
        $answered = [];
        $meta     = [];
        $urutan   = [];   // [questionId][display_order] => true

        foreach ($logs as $l) {
            $aid = (int) $l->test_attempt_id;
            $qid = (int) $l->question_id;

            // Butir dijajarkan dengan question_id, BUKAN display_order.
            // ExamService menulis ulang display_order per attempt saat
            // tests.random_questions menyala, jadi "soal nomor 3" milik dua
            // siswa bisa dua soal yang berbeda.
            $scores[$aid][$qid] = $l->score === null ? null : (float) $l->score;

            // Tipe 3/4/5 menyimpan jawaban di answer_text; tipe 1/2 lewat
            // is_selected di test_log_answers, ditambahkan di bawah.
            // num_answers TIDAK dipakai: kolom itu berisi jumlah opsi yang
            // ditampilkan, bukan jumlah jawaban yang diisi siswa.
            $answered[$aid][$qid] = trim((string) ($l->answer_text ?? '')) !== '';

            $urutan[$qid][(int) $l->display_order] = true;

            $meta[$qid] ??= [
                'nomor' => 0,
                'tipe'  => (int) $l->question_type,
                'teks'  => self::ringkasTeks((string) ($l->question_text ?? '')),
            ];
        }

        $picks = [];
        foreach ($opsiRows as $o) {
            $qid = (int) $o->question_id;
            $ans = (int) $o->answer_id;
            $aid = (int) $o->test_attempt_id;

            $picks[$qid][$ans] ??= [
                'teks'    => self::ringkasTeks((string) ($o->answer_text ?? '')),
                'kunci'   => ((int) $o->is_correct) === 1,
                'dipilih' => [],
            ];

            if (((int) $o->is_selected) === 1) {
                $picks[$qid][$ans]['dipilih'][] = $aid;
                $answered[$aid][$qid] = true;
            }
        }

        $seragam = self::nomorSeragam($urutan);

        if ($seragam) {
            foreach ($meta as $qid => $_) {
                $meta[$qid]['nomor'] = array_key_first($urutan[$qid]);
            }
        } else {
            $ids = array_keys($meta);
            sort($ids);
            foreach ($ids as $i => $qid) {
                $meta[$qid]['nomor'] = $i + 1;
            }
        }

        $optionPicks = [];
        foreach ($picks as $qid => $opsi) {
            // answer_id naik sesuai urutan penulisan opsi di bank soal, jadi
            // urutan ini stabil walau tampilannya diacak per peserta.
            ksort($opsi);
            $optionPicks[$qid] = array_values($opsi);
        }

        return [
            'scores'        => $scores,
            'meta'          => $meta,
            'answered'      => $answered,
            'optionPicks'   => $optionPicks,
            'nomor_seragam' => $seragam,
        ];
    }

    /**
     * display_order layak jadi nomor bersama hanya bila tiap butir memakai
     * satu nilai yang sama di seluruh attempt DAN nomornya tidak bertabrakan.
     * Diperiksa dari data, bukan dari bendera tests.random_questions, supaya
     * ujian yang benderanya sempat diubah di tengah jalan tetap terbaca benar.
     *
     * @param array<int, array<int, true>> $urutan
     */
    private static function nomorSeragam(array $urutan): bool
    {
        $terpakai = [];

        foreach ($urutan as $dipakai) {
            if (count($dipakai) !== 1) {
                return false;
            }

            $n = array_key_first($dipakai);
            if (isset($terpakai[$n])) {
                return false;
            }
            $terpakai[$n] = true;
        }

        return true;
    }

    /** Teks soal/opsi dipakai sebagai label; HTML dan gambar dibuang. */
    public static function ringkasTeks(string $html, int $batas = 160): string
    {
        $teks = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if ($teks === '') {
            return '(tanpa teks)';
        }

        return mb_strlen($teks) > $batas ? mb_substr($teks, 0, $batas) . '…' : $teks;
    }
}

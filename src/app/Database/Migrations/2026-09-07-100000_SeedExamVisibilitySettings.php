<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Menjadikan tiga setelan visibilitas hasil ujian EKSPLISIT.
 *
 * Ketiganya dibaca di empat tempat tapi tidak pernah di-seed, jadi yang
 * berlaku selama ini adalah default kode — dan default itu saling
 * bertentangan (daftar ujian menutup, halaman hasil membuka). Menyeragamkan
 * defaultnya saja tidak cukup: selama barisnya tidak ada, admin tidak punya
 * apa pun untuk dilihat maupun diubah di halaman pengaturan, dan keadaannya
 * tetap tersembunyi.
 *
 * Nilainya '0' — menutup — sama dengan default kode yang baru. Admin yang
 * ingin membukanya cukup mencentang di /admin/settings.
 */
class SeedExamVisibilitySettings extends Migration
{
    private const ROWS = [
        ['key' => 'show_score_after_exam', 'description' => 'Tampilkan nilai kepada siswa setelah ujian selesai'],
        ['key' => 'show_correct_answers',  'description' => 'Tampilkan kunci jawaban kepada siswa pada halaman review'],
        ['key' => 'allow_review',          'description' => 'Izinkan siswa membuka kembali jawabannya setelah ujian'],
    ];

    public function up()
    {
        $now = date('Y-m-d H:i:s');

        foreach (self::ROWS as $row) {
            // Instalasi yang sudah pernah menyimpan pengaturan tidak boleh
            // ditimpa: pilihan admin lebih berhak daripada default migrasi.
            $existing = $this->db->table('settings')->where('key', $row['key'])->countAllResults();
            if ($existing > 0) {
                continue;
            }

            $this->db->table('settings')->insert([
                'key'         => $row['key'],
                'value'       => '0',
                'type'        => 'boolean',
                'group'       => 'exam',
                'description' => $row['description'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down()
    {
        foreach (self::ROWS as $row) {
            $this->db->table('settings')->where('key', $row['key'])->delete();
        }
    }
}

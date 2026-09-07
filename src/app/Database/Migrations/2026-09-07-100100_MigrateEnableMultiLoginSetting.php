<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Menghapus setelan hantu `enable_multi_login` dan menjadikan
 * `prevent_multi_login` eksplisit.
 *
 * Seeder menuliskan `enable_multi_login`; seluruh kode membaca
 * `prevent_multi_login` — kunci lain, POLARITAS TERBALIK. Jadi baris yang
 * di-seed tidak pernah dibaca siapa pun, sementara baris yang dibaca tidak
 * pernah ada, dan yang berlaku adalah default kode.
 *
 * Nilainya dibalik saat dipindahkan: `enable_multi_login = '0'` berarti login
 * ganda TIDAK diizinkan, yang sama artinya dengan `prevent_multi_login = '1'`.
 */
class MigrateEnableMultiLoginSetting extends Migration
{
    public function up()
    {
        $now = date('Y-m-d H:i:s');
        $table = $this->db->table('settings');

        $lama = $this->db->table('settings')->where('key', 'enable_multi_login')->get()->getRow();
        $baru = $this->db->table('settings')->where('key', 'prevent_multi_login')->get()->getRow();

        if ($baru === null) {
            // Tanpa baris lama, pakai default kode: penegakan AKTIF.
            $nilai = '1';
            if ($lama !== null) {
                $nilai = filter_var($lama->value, FILTER_VALIDATE_BOOLEAN) ? '0' : '1';
            }

            $table->insert([
                'key'         => 'prevent_multi_login',
                'value'       => $nilai,
                'type'        => 'boolean',
                'group'       => 'security',
                'description' => 'Cegah satu akun siswa dipakai login di beberapa perangkat',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        if ($lama !== null) {
            $this->db->table('settings')->where('key', 'enable_multi_login')->delete();
        }
    }

    public function down()
    {
        $baru = $this->db->table('settings')->where('key', 'prevent_multi_login')->get()->getRow();
        if ($baru === null) {
            return;
        }

        $this->db->table('settings')->insert([
            'key'         => 'enable_multi_login',
            'value'       => filter_var($baru->value, FILTER_VALIDATE_BOOLEAN) ? '0' : '1',
            'type'        => 'boolean',
            'group'       => 'security',
            'description' => 'Izinkan login dari beberapa perangkat',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->db->table('settings')->where('key', 'prevent_multi_login')->delete();
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKioskBannedDevicesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            // sha256 heksadesimal = 64 karakter. UUID jalur cadangan = 36.
            'device_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'banned_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'banned_at' => [
                'type' => 'DATETIME',
            ],
            'unlocked_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'unlocked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Konteks kejadian, bukan kunci. Ban tidak pernah menyempit ke
            // satu siswa atau satu ujian.
            'last_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'last_test_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        // Ban aktif = unlocked_at IS NULL. MariaDB tidak punya indeks unik
        // parsial, jadi jaminan "paling banyak satu ban aktif per perangkat"
        // ditegakkan di DeviceBan::ban() di dalam transaksi.
        $this->forge->addKey(['device_id', 'unlocked_at']);

        $this->forge->createTable('kiosk_banned_devices', false, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_general_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('kiosk_banned_devices', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRequireKioskToTests extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tests', [
            'require_kiosk' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 0,
                'after'      => 'auto_submit_on_cheat',
                'comment'    => 'Wajib dikerjakan lewat aplikasi CBT Kiosk (dibuktikan heartbeat perangkat).',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tests', 'require_kiosk');
    }
}

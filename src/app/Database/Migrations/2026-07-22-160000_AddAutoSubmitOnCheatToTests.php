<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAutoSubmitOnCheatToTests extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tests', [
            'auto_submit_on_cheat' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'auto_logout_on_timeout',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tests', 'auto_submit_on_cheat');
    }
}

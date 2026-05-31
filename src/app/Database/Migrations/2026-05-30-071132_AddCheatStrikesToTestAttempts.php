<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCheatStrikesToTestAttempts extends Migration
{
    public function up()
    {
        $this->forge->addColumn('test_attempts', [
            'cheat_strikes' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 0,
                'after'      => 'status'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('test_attempts', 'cheat_strikes');
    }
}

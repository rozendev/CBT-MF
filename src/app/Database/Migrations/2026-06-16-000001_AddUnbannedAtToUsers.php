<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUnbannedAtToUsers extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'unbanned_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'is_active',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'unbanned_at');
    }
}

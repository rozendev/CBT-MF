<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddExitPasswordToTests extends Migration
{
    public function up()
    {
        $fields = [
            'exit_password' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'default'    => '123456',
                // Tanpa 'after': tabel tests tidak pernah punya kolom 'title',
                // jadi penempatan relatif itu selalu gagal di instalasi baru.
            ],
        ];

        $this->forge->addColumn('tests', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('tests', 'exit_password');
    }
}

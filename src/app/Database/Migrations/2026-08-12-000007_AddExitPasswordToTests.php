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
                'after'      => 'title',
            ],
        ];

        $this->forge->addColumn('tests', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('tests', 'exit_password');
    }
}

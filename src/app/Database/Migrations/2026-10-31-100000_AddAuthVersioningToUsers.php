<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAuthVersioningToUsers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'auth_version' => [
                'type'       => 'INT',
                'unsigned'   => true,
                'default'    => 1,
                'null'       => false,
                'after'      => 'is_active',
            ],
            'cred_version' => [
                'type'       => 'INT',
                'unsigned'   => true,
                'default'    => 1,
                'null'       => false,
                'after'      => 'auth_version',
            ],
            'cred_grace_until' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'cred_version',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('users', ['auth_version', 'cred_version', 'cred_grace_until']);
    }
}

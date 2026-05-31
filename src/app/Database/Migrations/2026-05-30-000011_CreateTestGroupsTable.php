<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTestGroupsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'test_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'group_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['test_id', 'group_id']);
        $this->forge->addForeignKey('test_id', 'tests', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('group_id', 'groups', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('test_groups', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('test_groups', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTestSubjectsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'test_subject_set_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'subject_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['test_subject_set_id', 'subject_id']);
        $this->forge->addForeignKey('test_subject_set_id', 'test_subject_sets', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('subject_id', 'subjects', 'id', 'RESTRICT', 'RESTRICT');

        $this->forge->createTable('test_subjects', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('test_subjects', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTestSubjectSetsTable extends Migration
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
            'question_type' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'default'  => 1,
            ],
            'difficulty' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'default'  => 1,
            ],
            'quantity' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'default'  => 1,
            ],
            'num_answers' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('test_id', 'tests', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('test_subject_sets', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('test_subject_sets', true);
    }
}

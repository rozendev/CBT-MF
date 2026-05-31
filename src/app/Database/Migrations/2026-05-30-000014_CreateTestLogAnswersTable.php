<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTestLogAnswersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'test_log_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'answer_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'is_selected' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'display_order' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'default'  => 1,
            ],
            'position' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['test_log_id', 'answer_id']);
        $this->forge->addForeignKey('test_log_id', 'test_logs', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('answer_id', 'answers', 'id', 'RESTRICT', 'RESTRICT');

        $this->forge->createTable('test_log_answers', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('test_log_answers', true);
    }
}

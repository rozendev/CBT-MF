<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTestLogsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'test_attempt_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'question_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'answer_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'score' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,3',
                'null'       => true,
            ],
            'display_order' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'default'  => 1,
            ],
            'num_answers' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'user_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'reaction_time_ms' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'is_unsure' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'comment' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'displayed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'answered_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['test_attempt_id', 'question_id']);
        $this->forge->addForeignKey('test_attempt_id', 'test_attempts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('question_id', 'questions', 'id', 'RESTRICT', 'RESTRICT');

        $this->forge->createTable('test_logs', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('test_logs', true);
    }
}

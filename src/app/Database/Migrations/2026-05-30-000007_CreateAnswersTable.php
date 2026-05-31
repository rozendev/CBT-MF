<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAnswersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'question_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'description' => [
                'type' => 'LONGTEXT',
            ],
            'explanation' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'is_correct' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'is_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'position' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'weight' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'Answer weight as percentage of right score',
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
        $this->forge->addForeignKey('question_id', 'questions', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('answers', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('answers', true);
    }
}

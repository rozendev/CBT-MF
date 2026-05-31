<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestionsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'subject_id' => [
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
            'type' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 1,
                'comment'    => '1=MCSA, 2=MCMA, 3=TEXT, 4=ORDER',
            ],
            'difficulty' => [
                'type'       => 'TINYINT',
                'unsigned'   => true,
                'default'    => 1,
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
            'timer' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'Per-question timer in seconds',
            ],
            'is_fullscreen' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'inline_answers' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'auto_next' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('type');
        $this->forge->addKey('difficulty');
        $this->forge->addKey('is_enabled');
        $this->forge->addForeignKey('subject_id', 'subjects', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('questions', false, [
            'ENGINE' => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE' => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('questions', true);
    }
}

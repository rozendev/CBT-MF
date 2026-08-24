<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExamKioskEventsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'exam_session_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'student_id' => [
                'type'       => 'INT',
                'constraint' => 11,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'event_details' => [
                'type' => 'JSON',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['exam_session_id', 'student_id']);

        $this->forge->createTable('exam_kiosk_events', false, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('exam_kiosk_events', true);
    }
}

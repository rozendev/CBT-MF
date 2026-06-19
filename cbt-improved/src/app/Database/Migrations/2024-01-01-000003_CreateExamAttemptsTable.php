<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExamAttemptsTable extends Migration
{
    public function up(): void
    {
        // Exam attempts table
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGSERIAL',
                'auto_increment' => true,
            ],
            'uuid' => [
                'type'       => 'UUID',
                'default'    => 'gen_random_uuid()',
            ],
            'exam_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
            ],
            'user_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['in_progress', 'submitted', 'graded', 'reviewed', 'cancelled'],
                'default'    => 'in_progress',
            ],
            'started_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
            'submitted_at' => [
                'type'       => 'TIMESTAMP',
                'null'       => true,
            ],
            'time_remaining' => [
                'type'       => 'INTEGER',
                'comment'    => 'Remaining time in seconds',
                'null'       => true,
            ],
            'score' => [
                'type'       => 'DECIMAL',
                'constraint' => '7,2',
                'null'       => true,
            ],
            'correct_count' => [
                'type'       => 'INTEGER',
                'default'    => 0,
            ],
            'wrong_count' => [
                'type'       => 'INTEGER',
                'default'    => 0,
            ],
            'skipped_count' => [
                'type'       => 'INTEGER',
                'default'    => 0,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'user_agent' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'browser_fingerprint' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'cheat_warnings' => [
                'type'       => 'JSONB',
                'null'       => true,
                'comment'    => 'Array of cheat warning events',
            ],
            'tab_switches' => [
                'type'       => 'INTEGER',
                'default'    => 0,
            ],
            'notes' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'created_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
            'updated_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('uuid');
        $this->forge->addKey('exam_id');
        $this->forge->addKey('user_id');
        $this->forge->addKey('status');
        $this->forge->addKey(['exam_id', 'user_id']);
        
        $this->forge->addForeignKey('exam_id', 'exams(id)', 'CASCADE', 'CASCADE', 'attempts_exam_fk');
        $this->forge->addForeignKey('user_id', 'users(id)', 'CASCADE', 'CASCADE', 'attempts_user_fk');

        $this->forge->createTable('exam_attempts');

        // Add comment
        $this->db->query("COMMENT ON TABLE exam_attempts IS 'Student exam attempt records';");
        $this->db->query("COMMENT ON COLUMN exam_attempts.cheat_warnings IS 'JSON array of detected suspicious activities';");
        $this->db->query("COMMENT ON COLUMN exam_attempts.browser_fingerprint IS 'Browser fingerprint for session validation';");
    }

    public function down(): void
    {
        $this->forge->dropTable('exam_attempts');
    }
}

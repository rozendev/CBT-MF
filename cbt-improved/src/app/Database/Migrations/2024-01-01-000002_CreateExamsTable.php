<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateExamsTable extends Migration
{
    public function up(): void
    {
        // Exams table
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGSERIAL',
                'auto_increment' => true,
            ],
            'uuid' => [
                'type'       => 'UUID',
                'default'    => 'gen_random_uuid()',
            ],
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'unique'     => true,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'published', 'active', 'closed', 'archived'],
                'default'    => 'draft',
            ],
            'duration_minutes' => [
                'type'       => 'INTEGER',
                'default'    => 60,
            ],
            'passing_score' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
            ],
            'negative_score' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'negative_score_value' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 0.00,
            ],
            'randomize_questions' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'randomize_answers' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'show_results' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'show_correct_answers' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'start_at' => [
                'type'       => 'TIMESTAMP',
                'null'       => true,
            ],
            'end_at' => [
                'type'       => 'TIMESTAMP',
                'null'       => true,
            ],
            'created_by' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
            ],
            'created_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
            'updated_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
            'deleted_at' => [
                'type'       => 'TIMESTAMP',
                'null'       => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('uuid');
        $this->forge->addKey('code');
        $this->forge->addKey('status');
        $this->forge->addKey('start_at');
        $this->forge->addKey('end_at');
        $this->forge->addForeignKey('created_by', 'users(id)', 'CASCADE', 'RESTRICT', 'exams_created_by_fk');

        $this->forge->createTable('exams');

        // Add comment
        $this->db->query("COMMENT ON TABLE exams IS 'Exam/test definitions';");
        $this->db->query("COMMENT ON COLUMN exams.status IS 'Exam lifecycle status';");
        $this->db->query("COMMENT ON COLUMN exams.randomize_questions IS 'Whether to randomize question order';");
        $this->db->query("COMMENT ON COLUMN exams.negative_score IS 'Enable negative scoring for wrong answers';");
    }

    public function down(): void
    {
        $this->forge->dropTable('exams');
    }
}

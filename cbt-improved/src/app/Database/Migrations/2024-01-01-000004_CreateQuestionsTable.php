<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuestionsTable extends Migration
{
    public function up(): void
    {
        // Questions table
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
            'question_number' => [
                'type'       => 'INTEGER',
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['multiple_choice', 'true_false', 'essay', 'matching', 'fill_blank', 'ordering'],
                'default'    => 'multiple_choice',
            ],
            'question_text' => [
                'type'       => 'TEXT',
            ],
            'question_html' => [
                'type'       => 'TEXT',
                'null'       => true,
                'comment'    => 'HTML formatted question',
            ],
            'media_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
            ],
            'media_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'comment'    => 'image, video, audio, file',
            ],
            'options' => [
                'type'       => 'JSONB',
                'comment'    => 'Answer options for multiple choice',
            ],
            'correct_answer' => [
                'type'       => 'JSONB',
                'comment'    => 'Correct answer(s)',
            ],
            'explanation' => [
                'type'       => 'TEXT',
                'null'       => true,
                'comment'    => 'Explanation shown after exam',
            ],
            'points' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'default'    => 1.00,
            ],
            'difficulty' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'medium',
                'comment'    => 'easy, medium, hard',
            ],
            'tags' => [
                'type'       => 'JSONB',
                'null'       => true,
                'comment'    => 'Array of tags for categorization',
            ],
            'metadata' => [
                'type'       => 'JSONB',
                'null'       => true,
                'comment'    => 'Additional metadata',
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
        $this->forge->addKey('exam_id');
        $this->forge->addKey('type');
        $this->forge->addKey('difficulty');
        $this->forge->addForeignKey('exam_id', 'exams(id)', 'CASCADE', 'CASCADE', 'questions_exam_fk');
        $this->forge->addForeignKey('created_by', 'users(id)', 'CASCADE', 'RESTRICT', 'questions_created_by_fk');

        $this->forge->createTable('questions');

        // GIN index for JSONB columns (PostgreSQL specific)
        $this->db->query('CREATE INDEX questions_options_gin_idx ON questions USING GIN (options);');
        $this->db->query('CREATE INDEX questions_tags_gin_idx ON questions USING GIN (tags);');

        // Add comment
        $this->db->query("COMMENT ON TABLE questions IS 'Exam questions with support for multiple types';");
        $this->db->query("COMMENT ON COLUMN questions.options IS 'JSON structure for answer options';");
        $this->db->query("COMMENT ON COLUMN questions.correct_answer IS 'Correct answer in JSON format';");
    }

    public function down(): void
    {
        $this->forge->dropTable('questions');
    }
}

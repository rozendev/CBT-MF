<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSnapshotColumnsToTestLogs extends Migration
{
    public function up()
    {
        // 1. Drop foreign key constraints on test_logs and test_log_answers
        $db = \Config\Database::connect();
        
        // MariaDB/MySQL specific drop foreign key
        try {
            $db->query("ALTER TABLE test_logs DROP FOREIGN KEY test_logs_question_id_foreign");
        } catch (\Exception $e) {
            // Ignore if already dropped
        }

        try {
            $db->query("ALTER TABLE test_log_answers DROP FOREIGN KEY test_log_answers_answer_id_foreign");
        } catch (\Exception $e) {
            // Ignore if already dropped
        }

        // 2. Add columns to test_logs
        $this->forge->addColumn('test_logs', [
            'question_text' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'question_id'
            ],
            'question_type' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => true,
                'after' => 'question_text'
            ],
            'question_difficulty' => [
                'type' => 'INT',
                'null' => true,
                'after' => 'question_type'
            ]
        ]);

        // 3. Add columns to test_log_answers
        $this->forge->addColumn('test_log_answers', [
            'answer_text' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'answer_id'
            ],
            'is_correct' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => true,
                'after' => 'answer_text'
            ]
        ]);

        // 4. Backfill existing data to preserve history
        try {
            // Backfill test_logs
            $db->query("
                UPDATE test_logs tl
                JOIN questions q ON q.id = tl.question_id
                SET tl.question_text = q.description,
                    tl.question_type = q.type,
                    tl.question_difficulty = q.difficulty
            ");

            // Backfill test_log_answers
            $db->query("
                UPDATE test_log_answers tla
                JOIN answers a ON a.id = tla.answer_id
                SET tla.answer_text = a.description,
                    tla.is_correct = a.is_correct
            ");
        } catch (\Exception $e) {
            log_message('error', 'Failed to backfill snapshot columns: ' . $e->getMessage());
        }
    }

    public function down()
    {
        // Remove columns
        $this->forge->dropColumn('test_logs', 'question_text');
        $this->forge->dropColumn('test_logs', 'question_type');
        $this->forge->dropColumn('test_logs', 'question_difficulty');
        
        $this->forge->dropColumn('test_log_answers', 'answer_text');
        $this->forge->dropColumn('test_log_answers', 'is_correct');

        // We could re-add FK constraints here, but it's risky if data has been deleted, 
        // so we'll leave it as a one-way architectural change.
    }
}

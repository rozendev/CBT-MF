<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTopicToQuestions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('questions', [
            'topic_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'subject_id',
            ],
        ]);

        $this->forge->addForeignKey('topic_id', 'topics', 'id', 'SET NULL', 'CASCADE');
    }

    public function down(): void
    {
        // FK questions_topic_id_foreign is dropped by 000003 (reverse order)
        $this->forge->dropColumn('questions', 'topic_id');
    }
}

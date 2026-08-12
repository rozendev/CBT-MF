<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTopicFkToQuestions extends Migration
{
    /**
     * addForeignKey() after addColumn() requires processIndexes()
     * to actually create the FK on an existing table.
     * Signature: (field, table, tableField, ON_UPDATE, ON_DELETE, name)
     */
    public function up(): void
    {
        $this->forge->addForeignKey('topic_id', 'topics', 'id', 'CASCADE', 'SET NULL', 'questions_topic_id_foreign');
        $this->forge->processIndexes('questions');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('questions', 'questions_topic_id_foreign');
    }
}

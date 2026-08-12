<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTopicToTestSubjectSets extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('test_subject_sets', [
            'topic_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'test_id',
            ],
        ]);

        // addForeignKey() after addColumn() requires processIndexes() (CI4 bug workaround)
        $this->forge->addForeignKey('topic_id', 'topics', 'id', 'CASCADE', 'SET NULL', 'test_subject_sets_topic_id_foreign');
        $this->forge->processIndexes('test_subject_sets');
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('test_subject_sets', 'test_subject_sets_topic_id_foreign');
        $this->forge->dropColumn('test_subject_sets', 'topic_id');
    }
}

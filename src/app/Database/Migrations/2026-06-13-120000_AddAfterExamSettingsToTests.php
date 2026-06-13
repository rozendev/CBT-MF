<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAfterExamSettingsToTests extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('tests', [
            'show_score_after_exam' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'default'    => null,
                'after'      => 'report_visible',
            ],
            'show_correct_answers' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'default'    => null,
                'after'      => 'show_score_after_exam',
            ],
            'allow_review' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => true,
                'default'    => null,
                'after'      => 'show_correct_answers',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('tests', ['show_score_after_exam', 'show_correct_answers', 'allow_review']);
    }
}

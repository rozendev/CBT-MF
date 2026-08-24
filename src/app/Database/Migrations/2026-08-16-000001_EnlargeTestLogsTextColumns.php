<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnlargeTestLogsTextColumns extends Migration
{
    // test_logs.question_text adalah snapshot questions.description (LONGTEXT).
    // Soal berisi gambar base64 >64KB — TEXT (65535) memotong HTML di tengah
    // atribut src, membuat soal kosong saat dirender. Perbesar ke MEDIUMTEXT.
    public function up()
    {
        $this->forge->modifyColumn('test_logs', [
            'question_text' => [
                'type' => 'MEDIUMTEXT',
            ],
            'answer_text' => [
                'type' => 'MEDIUMTEXT',
            ],
        ]);
        $this->forge->modifyColumn('test_log_answers', [
            'answer_text' => [
                'type' => 'MEDIUMTEXT',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('test_log_answers', [
            'answer_text' => [
                'type' => 'TEXT',
            ],
        ]);
        $this->forge->modifyColumn('test_logs', [
            'question_text' => [
                'type' => 'TEXT',
            ],
            'answer_text' => [
                'type' => 'TEXT',
            ],
        ]);
    }
}
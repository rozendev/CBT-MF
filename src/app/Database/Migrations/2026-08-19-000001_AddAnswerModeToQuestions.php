<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAnswerModeToQuestions extends Migration
{
    public function up()
    {
        // Tipe 3 selama ini dirender sebagai textarea esai tapi dinilai dengan
        // kecocokan string persis, jadi esai sungguhan selalu bernilai nol.
        // Kolom ini memisahkan keduanya tanpa menambah nomor tipe baru: bagi
        // kode keduanya sama-sama teks bebas, yang berbeda hanya cara menilai.
        //
        // Default 'exact' menjaga perilaku soal yang sudah ada: semuanya
        // ditulis dengan asumsi jawaban harus sama persis.
        $this->forge->addColumn('questions', [
            'answer_mode' => [
                'type'       => 'ENUM',
                'constraint' => ['exact', 'manual'],
                'null'       => false,
                'default'    => 'exact',
                'after'      => 'type',
                'comment'    => "Khusus tipe 3: 'exact' isian singkat dinilai otomatis, 'manual' esai dikoreksi guru.",
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('questions', 'answer_mode');
    }
}

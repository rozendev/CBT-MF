<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddStaticPageToTests extends Migration
{
    public function up()
    {
        $this->forge->addColumn('tests', [
            'exam_mode' => [
                'type' => 'ENUM',
                'constraint' => ['normal', 'static'],
                'default' => 'normal',
                'after' => 'is_enabled',
            ],
            'static_page_path' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => true,
                'after' => 'exam_mode',
            ],
            'static_generated_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'static_page_path',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('tests', ['exam_mode', 'static_page_path', 'static_generated_at']);
    }
}

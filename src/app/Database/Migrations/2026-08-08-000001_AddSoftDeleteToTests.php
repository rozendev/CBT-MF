<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSoftDeleteToTests extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('deleted_at', 'tests')) {
            $this->forge->addColumn('tests', [
                'deleted_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('deleted_at', 'tests')) {
            $this->forge->dropColumn('tests', 'deleted_at');
        }
    }
}

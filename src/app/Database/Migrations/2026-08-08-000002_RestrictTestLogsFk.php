<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RestrictTestLogsFk extends Migration
{
    public function up(): void
    {
        // Temukan nama constraint FK dari test_attempts ke tests secara dinamis
        $query = $this->db->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'test_attempts' 
              AND COLUMN_NAME = 'test_id' 
              AND REFERENCED_TABLE_NAME = 'tests'
        ");
        
        $row = $query->getRow();
        if ($row) {
            $constraintName = $row->CONSTRAINT_NAME;
            $this->db->query("ALTER TABLE test_attempts DROP FOREIGN KEY {$constraintName}");
        }

        // Tambahkan constraint baru dengan ON DELETE RESTRICT
        $this->db->query("
            ALTER TABLE test_attempts 
            ADD CONSTRAINT fk_test_attempts_test_restrict 
            FOREIGN KEY (test_id) REFERENCES tests(id) 
            ON DELETE RESTRICT ON UPDATE CASCADE
        ");
    }

    public function down(): void
    {
        try {
            $this->db->query("ALTER TABLE test_attempts DROP FOREIGN KEY fk_test_attempts_test_restrict");
        } catch (\Exception $e) {}

        $this->db->query("
            ALTER TABLE test_attempts 
            ADD CONSTRAINT test_attempts_test_id_foreign 
            FOREIGN KEY (test_id) REFERENCES tests(id) 
            ON DELETE CASCADE ON UPDATE CASCADE
        ");
    }
}

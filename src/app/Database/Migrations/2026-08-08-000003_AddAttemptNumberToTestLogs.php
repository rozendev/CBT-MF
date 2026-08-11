<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAttemptNumberToTestLogs extends Migration
{
    public function up(): void
    {
        // Add attempt_number to test_attempts
        if (!$this->db->fieldExists('attempt_number', 'test_attempts')) {
            $this->forge->addColumn('test_attempts', [
                'attempt_number' => [
                    'type'    => 'INT',
                    'null'    => true, // Sementara nullable untuk backfill
                ]
            ]);

            // Backfill
            $this->db->query("UPDATE test_attempts SET attempt_number = 1 WHERE attempt_number IS NULL");
            
            // Jadikan NOT NULL
            $this->db->query("ALTER TABLE test_attempts MODIFY attempt_number INT NOT NULL DEFAULT 1");
            
            // Drop Index lama (meskipun di migrasi sebelumnya tidak terlihat, kita try-catch untuk berjaga-jaga jika admin membuatnya manual)
            try {
                $this->db->query("ALTER TABLE test_attempts DROP INDEX user_test_unique");
            } catch (\Exception $e) {}

            // Tambahkan Unique Constraint baru
            $this->db->query("ALTER TABLE test_attempts ADD UNIQUE INDEX user_test_attempt_unique (user_id, test_id, attempt_number)");
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('attempt_number', 'test_attempts')) {
            try {
                $this->db->query("ALTER TABLE test_attempts DROP INDEX user_test_attempt_unique");
            } catch (\Exception $e) {}

            $this->forge->dropColumn('test_attempts', 'attempt_number');
        }
    }
}

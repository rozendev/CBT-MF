<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddIndexes extends Migration
{
    public function up()
    {
        // Add composite index to test_attempts
        $this->db->query("CREATE INDEX idx_test_attempts_user_test_status ON test_attempts (user_id, test_id, status)");

        // Add index on test_logs for test_attempt_id and display_order
        $this->db->query("CREATE INDEX idx_test_logs_attempt_display ON test_logs (test_attempt_id, display_order)");

        // Add index on test_log_answers for test_log_id and display_order
        $this->db->query("CREATE INDEX idx_test_log_answers_log_display ON test_log_answers (test_log_id, display_order)");

        // Add index on user_groups for user_id and group_id
        $this->db->query("CREATE INDEX idx_user_groups_user_group ON user_groups (user_id, group_id)");
    }

    public function down()
    {
        $this->db->query("DROP INDEX idx_test_attempts_user_test_status ON test_attempts");
        $this->db->query("DROP INDEX idx_test_logs_attempt_display ON test_logs");
        $this->db->query("DROP INDEX idx_test_log_answers_log_display ON test_log_answers");
        $this->db->query("DROP INDEX idx_user_groups_user_group ON user_groups");
    }
}

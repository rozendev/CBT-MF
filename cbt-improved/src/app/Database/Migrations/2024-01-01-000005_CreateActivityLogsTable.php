<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateActivityLogsTable extends Migration
{
    public function up(): void
    {
        // Activity logs table for audit trail
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGSERIAL',
                'auto_increment' => true,
            ],
            'uuid' => [
                'type'       => 'UUID',
                'default'    => 'gen_random_uuid()',
            ],
            'user_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
            ],
            'action' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'entity_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'comment'    => 'Type of entity affected (user, exam, question, etc.)',
            ],
            'entity_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
            ],
            'user_agent' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'request_data' => [
                'type'       => 'JSONB',
                'null'       => true,
                'comment'    => 'Request payload',
            ],
            'response_status' => [
                'type'       => 'INTEGER',
                'null'       => true,
                'comment'    => 'HTTP response status code',
            ],
            'level' => [
                'type'       => 'ENUM',
                'constraint' => ['info', 'warning', 'error', 'critical'],
                'default'    => 'info',
            ],
            'created_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('uuid');
        $this->forge->addKey('user_id');
        $this->forge->addKey('action');
        $this->forge->addKey('entity_type');
        $this->forge->addKey('level');
        $this->forge->addKey('created_at');
        
        // Composite index for filtering
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey(['user_id', 'created_at']);
        
        $this->forge->addForeignKey('user_id', 'users(id)', 'SET NULL', 'RESTRICT', 'logs_user_fk');

        $this->forge->createTable('activity_logs');

        // GIN index for JSONB column
        $this->db->query('CREATE INDEX activity_logs_request_data_gin_idx ON activity_logs USING GIN (request_data);');

        // Add comment
        $this->db->query("COMMENT ON TABLE activity_logs IS 'Comprehensive audit trail for all system activities';");
        $this->db->query("COMMENT ON COLUMN activity_logs.level IS 'Log severity level';");
        $this->db->query("COMMENT ON COLUMN activity_logs.request_data IS 'JSON formatted request data';");
    }

    public function down(): void
    {
        $this->forge->dropTable('activity_logs');
    }
}

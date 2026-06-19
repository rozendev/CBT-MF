<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        // Users table
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGSERIAL',
                'auto_increment' => true,
            ],
            'uuid' => [
                'type'       => 'UUID',
                'default'    => 'gen_random_uuid()',
            ],
            'username' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'unique'     => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'unique'     => true,
            ],
            'password_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'full_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'role' => [
                'type'       => 'ENUM',
                'constraint' => ['super_admin', 'admin', 'proctor', 'teacher', 'student'],
                'default'    => 'student',
            ],
            'is_active' => [
                'type'       => 'BOOLEAN',
                'default'    => true,
            ],
            'mfa_enabled' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'mfa_secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'last_login_at' => [
                'type'       => 'TIMESTAMP',
                'null'       => true,
            ],
            'last_login_ip' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'created_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
            'updated_at' => [
                'type'       => 'TIMESTAMP',
                'default'    => 'CURRENT_TIMESTAMP',
            ],
            'deleted_at' => [
                'type'       => 'TIMESTAMP',
                'null'       => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('uuid');
        $this->forge->addKey('username');
        $this->forge->addKey('email');
        $this->forge->addKey('role');
        $this->forge->addKey('is_active');
        
        // Full-text search index
        $this->forge->addKey('full_name', false, false, 'users_full_name_idx');

        $this->forge->createTable('users');

        // Add comment
        $this->db->query("COMMENT ON TABLE users IS 'System users with role-based access control';");
        $this->db->query("COMMENT ON COLUMN users.role IS 'User role for authorization';");
        $this->db->query("COMMENT ON COLUMN users.mfa_enabled IS 'Whether MFA is enabled for this user';");
    }

    public function down(): void
    {
        $this->forge->dropTable('users');
    }
}

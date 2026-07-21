<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterUserRoleEnum extends Migration
{
    public function up()
    {
        // Modify the ENUM to include 'proctor'
        $this->db->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'guru', 'siswa', 'proctor') DEFAULT 'siswa'");
    }

    public function down()
    {
        // Revert the ENUM back (WARNING: Make sure there are no 'proctor' users before running down!)
        $this->db->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'guru', 'siswa') DEFAULT 'siswa'");
    }
}

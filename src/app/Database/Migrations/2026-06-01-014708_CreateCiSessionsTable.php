<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCiSessionsTable extends Migration
{
    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS ci_sessions (
                id varchar(128) NOT NULL,
                ip_address varchar(45) NOT NULL,
                timestamp timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
                data blob NOT NULL,
                PRIMARY KEY (id),
                KEY ci_sessions_timestamp (timestamp)
            )
        ");
    }

    public function down()
    {
        $this->forge->dropTable('ci_sessions', true);
    }
}

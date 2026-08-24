<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedWebsocketUrlSetting extends Migration
{
    public function up(): void
    {
        $existing = $this->db->table('settings')->where('key', 'websocket_url')->get()->getRow();
        if ($existing) {
            return;
        }

        $this->db->table('settings')->insert([
            'key'         => 'websocket_url',
            'value'       => '',
            'type'        => 'string',
            'group'       => 'system',
            'description' => 'URL WebSocket untuk klien. Kosongkan agar diturunkan otomatis dari alamat aplikasi.',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $this->db->table('settings')->where('key', 'websocket_url')->delete();
    }
}

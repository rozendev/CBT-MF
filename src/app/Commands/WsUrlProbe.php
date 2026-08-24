<?php

namespace App\Commands;

use App\Libraries\WebSocketUrl;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * "Kenapa siswa tidak dapat real-time?" biasanya berujung pada URL WebSocket
 * yang salah. Perintah ini mencetak URL yang AKAN dipakai klien beserta
 * asalnya, tanpa perlu membuka halaman ujian.
 */
class WsUrlProbe extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:ws-url';
    protected $description = 'Cetak URL WebSocket yang dipakai klien dan dari mana asalnya.';

    public function run(array $params)
    {
        $url = WebSocketUrl::resolve();
        $fromSetting = WebSocketUrl::isConfigured();

        CLI::write('Base URL aplikasi : ' . rtrim((string) base_url(), '/'));
        CLI::write('URL WebSocket     : ' . $url, 'green');
        CLI::write('Sumber            : ' . ($fromSetting
            ? 'setting websocket_url (admin)'
            : 'diturunkan dari base URL (setting kosong)'), $fromSetting ? 'yellow' : 'blue');
        CLI::write('Path default      : ' . WebSocketUrl::DEFAULT_PATH);
    }
}

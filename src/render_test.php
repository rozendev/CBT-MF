<?php
require '/var/www/html/vendor/codeigniter4/framework/system/util_bootstrap.php';

$rows = [(object) [
    'id' => 1,
    'photo_path' => '20260812_115207_e1c694c447fe.jpg',
    'latitude' => '-6.2000000',
    'longitude' => '106.8166667',
    'accuracy' => '12.50',
    'ip_address' => '103.133.62.44',
    'user_agent' => 'Mozilla/5.0 Test',
    'requested_uri' => 'https://x/.env',
    'referer' => '',
    'screen' => '1920x1080',
    'platform' => 'Win32',
    'created_at' => '2026-08-12 11:52:07',
]];

$html = view('admin/logging/intruders', [
    'reports' => $rows,
    'pager' => null,
    'stats' => ['total' => 1, 'today' => 1, 'photo' => 1],
    'dateFrom' => '',
    'dateTo' => '',
    'search' => '',
]);

echo strlen($html) > 1000
    ? "RENDER OK (" . strlen($html) . " bytes)\n"
    : "SHORT:\n$html\n";

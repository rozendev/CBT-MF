<?php

require __DIR__ . '/../vendor/autoload.php';

// Beberapa unit yang diuji memanggil helper CodeIgniter langsung, sedangkan
// bootstrap ini sengaja tidak memuat framework (tes unit harus cepat dan tidak
// butuh container). Sediakan seadanya, dan hanya kalau framework belum ada.
if (! function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) ?: $default);
    }
}

if (! function_exists('log_message')) {
    function log_message(string $level, string $message, array $context = []): void
    {
        // Tes tidak menegaskan apa pun soal log; buang saja.
    }
}

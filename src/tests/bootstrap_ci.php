<?php

/**
 * Bootstrap yang MEMUAT framework CodeIgniter, khusus untuk suite yang menguji
 * perilaku terintegrasi (filter login, command spark, resolusi IP via
 * Config\App::$proxyIPs). Suite lain tetap memakai tests/bootstrap.php yang
 * sengaja ringan dan tanpa framework.
 *
 * Dipakai lewat: vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling
 * (dijalankan dari /var/www/html sehingga getcwd() = HOMEPATH).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/codeigniter4/framework/system/Test/bootstrap.php';

<?php
define('FCPATH', realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . '/bootstrap.php';

$app = \Config\Services::codeigniter();
$app->initialize();

$session = session();
echo "Logged in: " . ($session->get('logged_in') ? 'Yes' : 'No') . "\n";
echo "Role: " . $session->get('role') . "\n";

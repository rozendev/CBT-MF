<?php
define('FCPATH', __DIR__ . '/public/');
require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . '/bootstrap.php';

$examService = new \App\Libraries\ExamService();
$db = \Config\Database::connect();
$attempt = $db->table('test_attempts')->orderBy('id', 'DESC')->get()->getRow();
if ($attempt) {
    echo "Testing attempt {$attempt->id} user {$attempt->user_id}\n";
    try {
        $res = $examService->handleCheat($attempt->id, $attempt->user_id, 'fullscreen_exit');
        print_r($res);
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    }
} else {
    echo "No attempts found.\n";
}

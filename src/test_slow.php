<?php
define('FCPATH', __DIR__ . '/public/');
require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . '/bootstrap.php';

$userId = 1;

echo "Testing DB query...\n";
$start = microtime(true);
$db = \Config\Database::connect();
$db->table('ci_sessions')
   ->groupStart()
       ->like('data', "user_id|i:{$userId};")
       ->orLike('data', "user_id|s:" . strlen((string)$userId) . ":\"{$userId}\";")
   ->groupEnd()
   ->delete();
echo "DB query took " . (microtime(true) - $start) . " seconds\n";

echo "Testing Redis scan...\n";
$start = microtime(true);
$redis = \App\Libraries\RedisClient::getInstance();
if ($redis) {
    $iterator = null;
    $count = 0;
    do {
        $keys = $redis->scan($iterator, 'ci_session:*', 100);
        if ($keys) {
            $count += count($keys);
        }
    } while ($iterator > 0);
    echo "Redis scan found $count keys, took " . (microtime(true) - $start) . " seconds\n";
}

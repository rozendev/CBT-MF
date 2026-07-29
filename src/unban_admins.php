<?php
define('FCPATH', __DIR__ . '/public/');
chdir(__DIR__);
require 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . '/bootstrap.php';

$userModel = new \App\Models\UserModel();
$db = \Config\Database::connect();

// Get all admins
$admins = $userModel->where('role', 'admin')->findAll();
$count = 0;

foreach ($admins as $admin) {
    if ($admin->is_active == 0) {
        $userModel->update($admin->id, ['is_active' => 1]);
        
        // Delete ban signal from Redis if connected
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->del("user_login_token:{$admin->id}");
                $redis->del("ban_signal:{$admin->id}");
            }
        } catch (\Exception $e) {}
        
        // Reset their test attempt status if locked
        $db->table('test_attempts')
           ->where('user_id', $admin->id)
           ->where('status', 2)
           ->update(['status' => 1, 'cheat_strikes' => 0]);
           
        echo "Restored admin account: " . $admin->username . "\n";
        $count++;
    }
}
if ($count === 0) {
    echo "No banned admin accounts found.\n";
} else {
    echo "Total admin accounts restored: $count\n";
}

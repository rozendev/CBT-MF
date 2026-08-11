<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ActivityLogModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $db = \Config\Database::connect();
        $cache = \Config\Services::cache();
        
        $redisDown = false;
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->ping();
            } else {
                $redisDown = true;
            }
        } catch (\Exception $e) {
            $redisDown = true;
        }

        $stats = $cache->get('admin_dashboard_stats');
        if ($stats === null) {
            $stats = [
                'users'        => $db->table('users')->where('deleted_at', null)->countAllResults(),
                'tests'        => $db->table('tests')->where('deleted_at', null)->countAllResults(),
                'questions'    => $db->table('questions')->where('deleted_at', null)->countAllResults(),
                'active_tests' => $db->table('tests')
                                     ->where('is_enabled', 1)
                                     ->where('deleted_at', null)
                                     ->countAllResults(),
            ];
            $cache->save('admin_dashboard_stats', $stats, 120);
        }

        $activityLog = new ActivityLogModel();
        $activities = $activityLog->getRecent(10);

        // Fetch online users (last_active_at within 5 minutes)
        $fiveMinsAgo = date('Y-m-d H:i:s', time() - 300);
        $onlineUsers = $db->table('users')
                          ->where('last_active_at >=', $fiveMinsAgo)
                          ->where('deleted_at', null)
                          ->orderBy('last_active_at', 'DESC')
                          ->get()
                          ->getResultArray();

        // Fetch Chart Data (Pie Chart: Belum vs Sudah Mengerjakan)
        $chartData = $cache->get('admin_dashboard_chart');
        if ($chartData === null) {
            $totalStudents = $db->table('users')
                                ->where('role', 'siswa')
                                ->where('deleted_at', null)
                                ->countAllResults();

            $studentsTakenQuery = $db->query("
                SELECT COUNT(DISTINCT user_id) as total 
                FROM test_attempts ta
                JOIN users u ON u.id = ta.user_id
                WHERE u.role = 'siswa' AND u.deleted_at IS NULL
            ")->getRow();
            
            $studentsTaken = $studentsTakenQuery ? (int)$studentsTakenQuery->total : 0;
            $studentsNotTaken = max(0, $totalStudents - $studentsTaken);

            $chartData = [
                'labels' => ['Sudah Mengerjakan', 'Belum Mengerjakan'],
                'data'   => [$studentsTaken, $studentsNotTaken]
            ];
            $cache->save('admin_dashboard_chart', $chartData, 120);
        }

        return view('admin/dashboard', [
            'stats'      => $stats,
            'activities' => $activities,
            'onlineUsers'=> $onlineUsers,
            'chartData'  => json_encode($chartData),
            'redis_down' => $redisDown,
        ]);
    }
}

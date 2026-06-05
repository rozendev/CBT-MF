<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ActivityLogModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $db = \Config\Database::connect();

        $stats = [
            'users'        => $db->table('users')->where('deleted_at', null)->countAllResults(),
            'tests'        => $db->table('tests')->where('deleted_at', null)->countAllResults(),
            'questions'    => $db->table('questions')->where('deleted_at', null)->countAllResults(),
            'active_tests' => $db->table('tests')
                                 ->where('is_enabled', 1)
                                 ->where('deleted_at', null)
                                 ->countAllResults(),
        ];

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

        return view('admin/dashboard', [
            'stats'      => $stats,
            'activities' => $activities,
            'onlineUsers'=> $onlineUsers,
        ]);
    }
}

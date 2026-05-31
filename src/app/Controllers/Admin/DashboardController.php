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

        return view('admin/dashboard', [
            'stats'      => $stats,
            'activities' => $activities,
        ]);
    }
}

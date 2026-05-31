<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;
use App\Models\TestModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $userId = session('user_id');
        $db = \Config\Database::connect();
        
        // Find tests assigned to this user's groups
        // and check if they are currently active
        $now = date('Y-m-d H:i:s');
        
        $sql = "
            SELECT DISTINCT t.*,
                   (SELECT status FROM test_attempts ta WHERE ta.test_id = t.id AND ta.user_id = ? ORDER BY ta.id DESC LIMIT 1) as attempt_status
            FROM tests t
            JOIN test_groups tg ON tg.test_id = t.id
            JOIN user_groups ug ON ug.group_id = tg.group_id
            WHERE ug.user_id = ?
              AND t.is_enabled = 1
              AND t.deleted_at IS NULL
              AND (t.begin_time IS NULL OR t.begin_time <= ?)
              AND (t.end_time IS NULL OR t.end_time >= ?)
            ORDER BY t.created_at DESC
        ";
        
        $query = $db->query($sql, [$userId, $userId, $now, $now]);
        $availableTests = $query->getResult();

        return view('student/dashboard', [
            'availableTests' => $availableTests
        ]);
    }
}

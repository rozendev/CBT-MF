<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

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

        // Chart Data (Pie: Sudah / Sedang / Belum Mengerjakan — kategori eksklusif)
        $chartData = $cache->get('admin_dashboard_chart');
        if ($chartData === null) {
            $totalStudents = $db->table('users')
                                ->where('role', 'siswa')
                                ->where('deleted_at', null)
                                ->countAllResults();

            // Sedang mengerjakan: peserta dengan attempt berstatus aktif/paused
            $inProgressQuery = $db->query("
                SELECT COUNT(DISTINCT ta.user_id) as total
                FROM test_attempts ta
                JOIN users u ON u.id = ta.user_id
                WHERE u.role = 'siswa' AND u.deleted_at IS NULL
                  AND ta.status IN (1, 2)
            ")->getRow();

            // Sudah mengerjakan: peserta dengan attempt selesai yang tidak sedang mengerjakan
            $doneQuery = $db->query("
                SELECT COUNT(DISTINCT ta.user_id) as total
                FROM test_attempts ta
                JOIN users u ON u.id = ta.user_id
                WHERE u.role = 'siswa' AND u.deleted_at IS NULL
                  AND ta.status = 3
                  AND NOT EXISTS (
                      SELECT 1 FROM test_attempts ta2
                      WHERE ta2.user_id = ta.user_id AND ta2.status IN (1, 2)
                  )
            ")->getRow();

            $studentsInProgress = $inProgressQuery ? (int)$inProgressQuery->total : 0;
            $studentsDone       = $doneQuery ? (int)$doneQuery->total : 0;
            $studentsNotTaken   = max(0, $totalStudents - $studentsDone - $studentsInProgress);

            $chartData = [
                'labels' => ['Sudah Mengerjakan', 'Sedang Mengerjakan', 'Belum Mengerjakan'],
                'data'   => [$studentsDone, $studentsInProgress, $studentsNotTaken],
            ];
            $cache->save('admin_dashboard_chart', $chartData, 120);
        }

        $totalStudents = $chartData['data'][0] + $chartData['data'][1] + $chartData['data'][2];
        $participationPercent = $totalStudents > 0
            ? round(($chartData['data'][0] / $totalStudents) * 100)
            : 0;

        return view('admin/dashboard', [
            'chartData'  => json_encode($chartData),
            'participationPercent' => $participationPercent,
            'redis_down' => $redisDown,
        ]);
    }
}
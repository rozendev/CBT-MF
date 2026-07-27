<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ActivityLogModel;

class NotificationController extends BaseController
{
    /**
     * Returns proctor report notifications newer than the given timestamp.
     * Used by admin layout polling to show real-time-ish alerts.
     */
    public function proctorReports()
    {
        if (!$this->request->isAJAX()) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error']);
        }

        $since = $this->request->getGet('since') ?? date('Y-m-d H:i:s', strtotime('-30 seconds'));

        $db = \Config\Database::connect();
        $reports = $db->table('activity_logs')
                      ->select('activity_logs.*, users.username as proctor_username, users.firstname as proctor_firstname')
                      ->join('users', 'users.id = activity_logs.user_id', 'left')
                      ->where('activity_logs.action', 'proctor_report')
                      ->where('activity_logs.created_at >', $since)
                      ->orderBy('activity_logs.created_at', 'ASC')
                      ->get()
                      ->getResultArray();

        // Parse the JSON description for each report
        $parsed = [];
        foreach ($reports as $report) {
            $detail = json_decode($report['description'] ?? '{}', true);
            $parsed[] = [
                'id'                => $report['id'],
                'proctor_name'      => $detail['proctor_name'] ?? $report['proctor_firstname'] ?? 'Unknown',
                'student_id'        => $detail['student_id'] ?? null,
                'student_username'  => $detail['student_username'] ?? 'Siswa',
                'suggested_action'  => $detail['suggested_action'] ?? 'ban',
                'reason'            => $detail['reason'] ?? '',
                'created_at'        => $report['created_at'],
                'test_id'           => $report['entity_id'] ?? null,
            ];
        }

        return $this->response->setJSON([
            'status'  => 'success',
            'reports' => $parsed,
            'server_time' => date('Y-m-d H:i:s')
        ]);
    }
}

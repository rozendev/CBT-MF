<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

/** Read-only fallback for exam events; never flushes or loads answers. */
final class ExamStatusController extends BaseController
{
    public function show(int $attemptId)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $userId = (int) session('user_id');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error']);
        }
        // One indexed snapshot, scoped in SQL to the authenticated owner. Read
        // fresh rows: proctor actions can update attempts without model callbacks.
        $row = db_connect()->table('test_attempts ta')
            ->select('ta.status, ta.started_at, t.id AS test_id, t.duration_minutes, t.exam_mode, t.static_page_path, u.is_active')
            ->join('tests t', 't.id = ta.test_id')
            ->join('users u', 'u.id = ta.user_id')
            ->where('ta.id', $attemptId)->where('ta.user_id', $userId)
            ->where('u.deleted_at', null)->where('t.deleted_at', null)
            ->get()->getRow();
        if (!$row) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error']);
        }
        if (!(int) $row->is_active) {
            return $this->response->setJSON(['status' => 'ban', 'message' => 'Akun Anda telah dinonaktifkan. Hubungi pengawas.']);
        }
        if (in_array((int) $row->status, [2, 4], true)) {
            return $this->response->setJSON(['status' => 'ejected', 'message' => \App\Libraries\ProctorAction::STUDENT_MESSAGE]);
        }
        if ((int) $row->status === 3) {
            return $this->response->setJSON(['status' => 'finished', 'message' => 'Ujian Anda telah diselesaikan.']);
        }
        $started = $row->started_at ? strtotime($row->started_at) : false;
        return $this->response->setJSON([
            'status' => 'success',
            'test_id' => (int) $row->test_id,
            'duration_minutes' => (int) $row->duration_minutes,
            'end_time_ms' => $started !== false && (int) $row->duration_minutes > 0
                ? ($started + (int) $row->duration_minutes * 60) * 1000 : null,
            'server_time_ms' => (int) round(microtime(true) * 1000),
            'exam_mode' => $row->exam_mode,
            'static_page_path' => $row->static_page_path,
        ]);
    }
}

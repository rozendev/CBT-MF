<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;

/**
 * SSE (Server-Sent Events) Controller for real-time exam monitoring.
 * 
 * Maintains a persistent HTTP connection with the student's browser during exam.
 * Uses Redis key polling (every 3 seconds) to detect ban/kick signals from admin.
 * 
 * When admin bans a student, a Redis key `ban_signal:{userId}` is set,
 * which this controller detects and pushes a 'ban' event to the browser instantly.
 */
class SseController extends BaseController
{
    /**
     * SSE stream endpoint for exam real-time ban detection.
     * 
     * Flow:
     * 1. Validate attempt belongs to current user
     * 2. Set SSE headers and release session lock
     * 3. Loop every 3 seconds:
     *    - Check Redis for ban_signal:{userId}
     *    - Check DB for attempt status (kicked/locked/finished)
     *    - Send heartbeat every 30 seconds to keep connection alive
     *    - If ban detected → push 'ban' event → close
     */
    public function stream($attemptId)
    {
        $userId = session('user_id');
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized']);
        }

        // Validate attempt belongs to current user
        $db = \Config\Database::connect();
        $attempt = $db->table('test_attempts')
                      ->select('id, user_id, status, test_id')
                      ->where('id', $attemptId)
                      ->where('user_id', $userId)
                      ->get()->getRow();

        if (!$attempt) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Invalid attempt']);
        }

        // Release session lock immediately — SSE is long-lived, we must not hold the session
        session_write_close();

        // Allow long-running script
        set_time_limit(0);
        ignore_user_abort(false);

        // Disable output buffering at all levels
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Set SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable Nginx buffering

        // Send initial connection event
        $this->sendSseEvent('connected', [
            'message' => 'SSE connected',
            'attempt_id' => (int)$attemptId,
        ]);

        $lastHeartbeat = time();
        $redis = null;

        // Try to connect to Redis once
        try {
            $redis = new \Redis();
            $redis->connect('redis', 6379);
        } catch (\Exception $e) {
            log_message('error', 'SSE Redis connect failed: ' . $e->getMessage());
            $redis = null;
        }

        $lastExamMode = null;
        $lastStaticPath = null;

        // Main SSE loop — poll every 3 seconds
        while (true) {
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }

            $shouldKick = false;
            $kickMessage = '';
            $kickEvent = 'ban';

            // ─── Check 1: Redis ban_signal (fastest, set by admin ban action) ───
            if ($redis) {
                try {
                    $banSignal = $redis->get("ban_signal:{$userId}");
                    if ($banSignal) {
                        $shouldKick = true;
                        $kickMessage = 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.';
                        $kickEvent = 'ban';
                    }
                } catch (\Exception $e) {
                    log_message('error', 'SSE Redis read error: ' . $e->getMessage());
                    // Try to reconnect Redis
                    try {
                        $redis = new \Redis();
                        $redis->connect('redis', 6379);
                    } catch (\Exception $e2) {
                        $redis = null;
                    }
                }
            }

            // ─── Check 2: DB attempt status (fallback, catches all cases) ───
            if (!$shouldKick) {
                try {
                    $currentAttempt = $db->table('test_attempts')
                                         ->select('test_attempts.status, tests.exam_mode, tests.static_page_path')
                                         ->join('tests', 'tests.id = test_attempts.test_id')
                                         ->where('test_attempts.id', $attemptId)
                                         ->get()->getRow();

                    if ($currentAttempt) {
                        if ($currentAttempt->status == 4) {
                            $shouldKick = true;
                            $kickMessage = 'Sesi ujian Anda telah dikunci karena melanggar aturan.';
                            $kickEvent = 'kick';
                        } elseif ($currentAttempt->status == 3) {
                            $shouldKick = true;
                            $kickMessage = 'Ujian Anda telah diselesaikan.';
                            $kickEvent = 'finished';
                        }
                        
                        // Check if exam mode changed
                        if (!$shouldKick) {
                            if ($lastExamMode !== $currentAttempt->exam_mode || $lastStaticPath !== $currentAttempt->static_page_path) {
                                $lastExamMode = $currentAttempt->exam_mode;
                                $lastStaticPath = $currentAttempt->static_page_path;
                                $this->sendSseEvent('sync_mode', [
                                    'exam_mode' => $currentAttempt->exam_mode,
                                    'static_page_path' => $currentAttempt->static_page_path
                                ]);
                            }
                        }
                    } else {
                        // Attempt deleted (admin reset)
                        $shouldKick = true;
                        $kickMessage = 'Sesi ujian Anda telah dihapus oleh Admin.';
                        $kickEvent = 'kick';
                    }
                } catch (\Exception $e) {
                    log_message('error', 'SSE DB check error: ' . $e->getMessage());
                }
            }

            // ─── Check 3: User still active? ───
            if (!$shouldKick) {
                try {
                    $user = $db->table('users')
                               ->select('is_active')
                               ->where('id', $userId)
                               ->get()->getRow();
                    
                    if ($user && !$user->is_active) {
                        $shouldKick = true;
                        $kickMessage = 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.';
                        $kickEvent = 'ban';
                    }
                } catch (\Exception $e) {
                    log_message('error', 'SSE user check error: ' . $e->getMessage());
                }
            }

            // ─── Send kick/ban event if detected ───
            if ($shouldKick) {
                $this->sendSseEvent($kickEvent, [
                    'message' => $kickMessage,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
                break; // Close connection after ban event
            }

            // ─── Heartbeat every 30 seconds to keep connection alive ───
            $now = time();
            if ($now - $lastHeartbeat >= 30) {
                $lastHeartbeat = $now;
                $this->sendSseEvent('heartbeat', [
                    'time' => date('H:i:s'),
                ]);
            }

            // Sleep 3 seconds before next check
            sleep(3);
        }

        // Cleanup
        if ($redis) {
            try { $redis->close(); } catch (\Exception $e) {}
        }

        exit; // End the SSE stream cleanly
    }

    /**
     * Send an SSE event to the client.
     */
    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}

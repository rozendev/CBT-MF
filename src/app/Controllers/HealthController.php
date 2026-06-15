<?php

namespace App\Controllers;

class HealthController extends BaseController
{
    public function index()
    {
        $checks = [
            'status'    => 'ok',
            'timestamp' => date('c'),
            'checks'    => [],
        ];

        $allOk = true;

        // Database check
        try {
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            $checks['checks']['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['checks']['database'] = 'error: connection_failed';
            $allOk = false;
            log_message('error', 'Health check DB failed: ' . $e->getMessage());
        }

        // Redis check
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis && $redis->ping()) {
                $checks['checks']['redis'] = 'ok';
            } else {
                $checks['checks']['redis'] = 'error: connection_failed';
                $allOk = false;
            }
        } catch (\Throwable $e) {
            $checks['checks']['redis'] = 'error: connection_failed';
            $allOk = false;
            log_message('error', 'Health check Redis failed: ' . $e->getMessage());
        }

        // Disk check (writable directory)
        $writablePath = WRITEPATH;
        if (is_writable($writablePath)) {
            $freeBytes = disk_free_space($writablePath);
            $freeMB = round($freeBytes / 1024 / 1024, 1);
            $checks['checks']['disk'] = "ok ({$freeMB} MB free)";
            if ($freeBytes < 100 * 1024 * 1024) {
                $checks['checks']['disk'] = "warning: low disk ({$freeMB} MB free)";
                $allOk = false;
            }
        } else {
            $checks['checks']['disk'] = 'error: writable directory not writable';
            $allOk = false;
        }

        $checks['status'] = $allOk ? 'ok' : 'degraded';
        $statusCode = $allOk ? 200 : 503;

        return $this->response
            ->setStatusCode($statusCode)
            ->setJSON($checks);
    }
}

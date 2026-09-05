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

        // Dependency checks. Delegated to DependencyHealth so this endpoint and
        // `spark deps:probe` can never disagree about what "healthy" means —
        // one of them raising the maintenance flag while the other reports ok
        // would be worse than either check being wrong on its own.
        $down = \App\Libraries\DependencyHealth::down();

        foreach ([\App\Libraries\DependencyHealth::DATABASE, \App\Libraries\DependencyHealth::REDIS] as $dependency) {
            $isDown = in_array($dependency, $down, true);

            $checks['checks'][$dependency] = $isDown ? 'error: connection_failed' : 'ok';

            if ($isDown) {
                $allOk = false;
                log_message('error', 'Health check failed for dependency: ' . $dependency);
            }
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

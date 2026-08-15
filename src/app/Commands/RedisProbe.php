<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\MaintenanceFlag;
use App\Libraries\RedisClient;

/**
 * Probes Redis health and maintains the nginx maintenance flag.
 *
 * Intended to run as a short-interval cron loop (every ~10s):
 *   while true; do php spark redis:probe; sleep 10; done
 *
 * - Redis unreachable        → writes writable/.maintenance_redis (throttled)
 * - Redis reachable + flag   → clears it after RECOVERY_STABLE_SECONDS of
 *                              stability (anti-flap: Redis restarts, AOF
 *                              reloads and connection races all settle first)
 *
 * Runs framework-free on Redis DNS/latency: the phpredis connect timeout is
 * short, so a probe never blocks a worker.
 */
class RedisProbe extends BaseCommand
{
    protected $group       = 'System';
    protected $name        = 'redis:probe';
    protected $description = 'Probe Redis health and maintain the nginx maintenance flag (run every ~10s from cron).';
    protected $usage       = 'redis:probe';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params)
    {
        $redis = null;
        try {
            $redis = RedisClient::getInstance();
        } catch (\Throwable $e) {
            $redis = null;
        }

        $flag = MaintenanceFlag::get(MaintenanceFlag::MODE_REDIS);

        if ($redis === null) {
            // Redis down → raise (or refresh) the flag. Throttling inside
            // MaintenanceFlag prevents an mtime write-storm every 10s.
            MaintenanceFlag::set(MaintenanceFlag::MODE_REDIS, 'Redis tidak tersedia');
            CLI::write('redis:probe — Redis DOWN, flag dipasang', 'yellow');

            return EXIT_SUCCESS;
        }

        if ($flag === null) {
            // Healthy and no flag → nothing to do.
            CLI::write('redis:probe — Redis OK, flag tidak ada', 'green');

            return EXIT_SUCCESS;
        }

        // Healthy but flag still up: wait for stability before clearing, so
        // a flapping Redis (restart, AOF reload) does not bounce nginx.
        $flagAge = time() - (int) $flag['ts'];

        if ($flagAge < MaintenanceFlag::RECOVERY_STABLE_SECONDS) {
            CLI::write(
                sprintf('redis:probe — Redis OK, flag menunggu stabil (%ds/%ds)', $flagAge, MaintenanceFlag::RECOVERY_STABLE_SECONDS),
                'yellow'
            );

            return EXIT_SUCCESS;
        }

        MaintenanceFlag::clear(MaintenanceFlag::MODE_REDIS);
        CLI::write('redis:probe — Redis OK, flag dibersihkan', 'green');

        return EXIT_SUCCESS;
    }
}

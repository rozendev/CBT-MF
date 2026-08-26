<?php

namespace App\Commands;

use App\Libraries\DependencyHealth;
use App\Libraries\MaintenanceFlag;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Probes every dependency the application cannot serve traffic without, and
 * maintains the nginx maintenance flag.
 *
 * Intended to run as a short-interval cron loop (every ~10s):
 *   while true; do php spark deps:probe; sleep 10; done
 *
 * - Any dependency unreachable → writes writable/.maintenance_deps
 * - All healthy again + flag    → clears it after RECOVERY_STABLE_SECONDS of
 *                                 stability (anti-flap: container restarts,
 *                                 AOF reloads and InnoDB recovery all settle
 *                                 first)
 *
 * Redis and MariaDB are probed by ONE command rather than two on purpose. Two
 * probes sharing one flag file would race: whichever ran first after its own
 * dependency recovered would clear a flag the other still needs, briefly
 * reopening the site while the stack is still broken.
 */
class DepsProbe extends BaseCommand
{
    protected $group       = 'System';
    protected $name        = 'deps:probe';
    protected $description = 'Probe Redis and the database, and maintain the nginx maintenance flag (run every ~10s from cron).';
    protected $usage       = 'deps:probe';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params)
    {
        MaintenanceFlag::forgetLegacyFlag();

        $down = DependencyHealth::down();

        if ($down !== []) {
            $message = DependencyHealth::describe($down);
            MaintenanceFlag::set(MaintenanceFlag::MODE_DEPS, $message, $down);
            CLI::write(sprintf('deps:probe — %s, flag dipasang', $message), 'red');

            return EXIT_SUCCESS;
        }

        if (! MaintenanceFlag::isActive(MaintenanceFlag::MODE_DEPS)) {
            CLI::write('deps:probe — Redis dan database OK, flag tidak ada', 'green');

            return EXIT_SUCCESS;
        }

        // Healthy but the flag is still up: wait for stability before clearing,
        // so a flapping dependency does not bounce nginx.
        $stableFor = MaintenanceFlag::secondsSinceLastDown(MaintenanceFlag::MODE_DEPS);

        if ($stableFor < MaintenanceFlag::RECOVERY_STABLE_SECONDS) {
            CLI::write(
                sprintf(
                    'deps:probe — pulih, menunggu stabil (%ds/%ds)',
                    $stableFor,
                    MaintenanceFlag::RECOVERY_STABLE_SECONDS,
                ),
                'yellow',
            );

            return EXIT_SUCCESS;
        }

        MaintenanceFlag::clear(MaintenanceFlag::MODE_DEPS);
        CLI::write('deps:probe — pulih dan stabil, flag dibersihkan', 'green');

        return EXIT_SUCCESS;
    }
}

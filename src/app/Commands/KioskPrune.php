<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\RedisClient;

/**
 * Prunes stale kiosk heartbeat keys (no heartbeat for > 90s) and records
 * the offline transition audit.
 *
 * Intended to run as a cron loop (every 60s):
 *   while true; do php spark kiosk:prune; sleep 60; done
 */
class KioskPrune extends BaseCommand
{
    protected $group       = 'Exam';
    protected $name        = 'kiosk:prune';
    protected $description = 'Remove stale kiosk_live keys and audit offline transitions (run every 60s from cron).';

    /** No heartbeat within this window → device considered offline. */
    private const STALE_SECONDS = 90;

    public function run(array $params)
    {
        $redis = RedisClient::getInstance();
        if ($redis === null) {
            CLI::write('kiosk:prune — Redis unavailable, skipping.', 'yellow');

            return EXIT_SUCCESS;
        }

        $cutoff   = time() - self::STALE_SECONDS;
        $cursor   = null;
        $pruned   = 0;

        do {
            $keys = $redis->scan($cursor, 'kiosk_live:*', 500);
            if (!is_array($keys)) {
                break;
            }

            foreach ($keys as $key) {
                $ts = (int) $redis->hGet($key, 'ts');
                if ($ts === 0 || $ts >= $cutoff) {
                    continue;
                }

                $fields = $redis->hMGet($key, ['device_id', 'battery', 'network']);

                $redis->del($key);

                $parts = explode(':', $key); // kiosk_live:{test_id}:{user_id}
                $testId = (int) ($parts[1] ?? 0);
                $userId = (int) ($parts[2] ?? 0);

                try {
                    $db = \Config\Database::connect();
                    $db->table('exam_kiosk_events')->insert([
                        'exam_session_id' => $testId,
                        'student_id'      => $userId,
                        'event_type'      => 'kiosk_offline',
                        'event_details'   => json_encode([
                            'device_id' => (string) ($fields['device_id'] ?? ''),
                            'last_seen' => date('Y-m-d H:i:s', $ts),
                            'battery'   => (int) ($fields['battery'] ?? -1),
                            'network'   => (string) ($fields['network'] ?? ''),
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {
                    log_message('error', 'kiosk:prune audit insert failed: ' . $e->getMessage());
                }

                $pruned++;
                CLI::write(sprintf('kiosk:prune — offline: user %d test %d', $userId, $testId), 'yellow');
            }
        } while ($cursor !== null && $cursor > 0);

        CLI::write('kiosk:prune — done, ' . $pruned . ' stale key(s) cleaned.', 'green');

        return EXIT_SUCCESS;
    }
}

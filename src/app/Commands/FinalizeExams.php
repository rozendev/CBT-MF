<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\TestAttemptModel;
use App\Models\ActivityLogModel;
use App\Libraries\ExamService;
use App\Libraries\ScoringEngine;
use Config\Database;

/**
 * Finalizes exam attempts that have exceeded their time limit
 * but were never submitted by the client (e.g., browser crash, network loss).
 *
 * Intended to be run as a cron job:
 *   * * * * * cd /var/www/html && php spark finalize:expired >> /dev/null 2>&1
 *
 * Safety:
 * - ScoringEngine uses optimistic locking (status check before update),
 *   so concurrent calls or race with student submit are safe.
 * - Grace period prevents finalizing attempts where the client is still
 *   within the auto-submit window.
 */
class FinalizeExams extends BaseCommand
{
    protected $group       = 'Exam';
    protected $name        = 'finalize:expired';
    protected $description = 'Finalize exam attempts that have exceeded their time limit but were not submitted by the client.';
    protected $usage       = 'finalize:expired [--grace-seconds=120]';
    protected $arguments   = [];
    protected $options     = [
        '--grace-seconds' => 'Additional grace period in seconds beyond the exam duration before force-finalizing. Default: 120',
    ];

    public function run(array $params)
    {
        $graceSeconds = (int) ($params['grace-seconds'] ?? CLI::getOption('grace-seconds') ?? 120);

        $db = Database::connect();

        // Find active attempts where (started_at + duration + grace) < NOW.
        // Only considers tests with a positive duration (timed exams).
        $sql = "
            SELECT ta.id AS attempt_id, ta.user_id, ta.test_id, ta.started_at,
                   t.duration_minutes, t.name AS test_name
            FROM test_attempts ta
            INNER JOIN tests t ON t.id = ta.test_id
            WHERE ta.status = 1
              AND t.duration_minutes > 0
              AND ta.started_at IS NOT NULL
              AND (UNIX_TIMESTAMP(ta.started_at) + (t.duration_minutes * 60) + ?) < UNIX_TIMESTAMP(NOW())
            ORDER BY ta.started_at ASC
        ";

        $expiredAttempts = $db->query($sql, [$graceSeconds])->getResult();

        $count = count($expiredAttempts);

        if ($count === 0) {
            CLI::write('No expired attempts found.', 'green');
            return;
        }

        CLI::write("Found {$count} expired attempt(s). Finalizing...", 'yellow');

        $examService  = new ExamService();
        $scorer       = new ScoringEngine();
        $attemptModel = new TestAttemptModel();
        $activityLog  = new ActivityLogModel();
        $finalized    = 0;
        $skipped      = 0;
        $errors       = 0;

        foreach ($expiredAttempts as $attempt) {
            try {
                // 1. Flush any pending Redis answers to MariaDB
                $flushed = $examService->flushRedisAnswersToDb((int) $attempt->attempt_id);

                if (!$flushed) {
                    // flushRedisAnswersToDb returned false: either Redis is unreachable,
                    // or the DB transaction failed. We must determine whether unflushed
                    // answers still exist in Redis before scoring.
                    $hasUnflushedAnswers = false;
                    try {
                        $redis = \App\Libraries\RedisClient::getInstance();
                        if ($redis) {
                            $pending = $redis->hLen("exam_answers:{$attempt->attempt_id}");
                            $hasUnflushedAnswers = ($pending > 0);
                        }
                    } catch (\Exception $re) {
                        // Redis itself is unreachable — we can't determine state
                    }

                    if ($hasUnflushedAnswers) {
                        // SAFETY GUARD: Answers exist in Redis but failed to flush to DB.
                        // Scoring now would produce an INCORRECT grade (missing answers).
                        // Skip this attempt and retry on the next cron cycle.
                        $errors++;
                        log_message('critical', "[finalize:expired] SKIPPED attempt #{$attempt->attempt_id}: "
                            . "Redis flush failed but unflushed answers exist. "
                            . "Scoring deferred to prevent incorrect grade.");
                        CLI::write("  ✗ #{$attempt->attempt_id} — DEFERRED: Redis flush failed, unflushed answers exist. Will retry next cycle.", 'red');
                        continue;
                    }

                    // Redis is unreachable entirely — proceed with DB-only data as best-effort.
                    // Any answers previously synced via autoSync are already in DB.
                    log_message('warning', "[finalize:expired] Redis unreachable for attempt #{$attempt->attempt_id}. "
                        . "Proceeding with DB-only scoring (answers synced before outage are safe).");
                    CLI::write("  ⚠ #{$attempt->attempt_id} — Redis unreachable, scoring with DB-only data.", 'yellow');
                }

                // 2. Calculate score and set status = 3 (finished)
                //    Returns falsy if already finalized (optimistic lock).
                $scored = $scorer->calculateAndSaveScore((int) $attempt->attempt_id);

                if ($scored) {
                    $finalized++;

                    // 3. Clear cached data for this attempt
                    $attemptModel->clearCacheForAttempt(
                        (int) $attempt->attempt_id,
                        (int) $attempt->test_id,
                        (int) $attempt->user_id
                    );

                    // 4. Audit trail
                    $elapsed = round((time() - strtotime($attempt->started_at)) / 60, 1);
                    $activityLog->log(
                        'auto_finalize',
                        (int) $attempt->user_id,
                        'test',
                        (int) $attempt->test_id,
                        "AUTO-FINALIZE: Ujian \"{$attempt->test_name}\" difinalisasi otomatis "
                        . "(elapsed: {$elapsed} menit, limit: {$attempt->duration_minutes} menit)"
                    );

                    log_message('info', "[finalize:expired] Auto-finalized attempt #{$attempt->attempt_id} "
                        . "(user:{$attempt->user_id}, test:\"{$attempt->test_name}\", "
                        . "elapsed:{$elapsed}min, limit:{$attempt->duration_minutes}min)");

                    CLI::write("  ✓ #{$attempt->attempt_id} user:{$attempt->user_id} \"{$attempt->test_name}\" — finalized ({$elapsed}min elapsed)", 'green');
                } else {
                    $skipped++;
                    CLI::write("  ⊘ #{$attempt->attempt_id} — already finalized by another process (skipped)", 'light_gray');
                }
            } catch (\Exception $e) {
                $errors++;
                log_message('error', "[finalize:expired] Failed attempt #{$attempt->attempt_id}: " . $e->getMessage());
                CLI::write("  ✗ #{$attempt->attempt_id} — ERROR: " . $e->getMessage(), 'red');
            }
        }

        $summary = "Done. Finalized: {$finalized}, Skipped: {$skipped}, Errors: {$errors}";
        CLI::write($summary, $errors > 0 ? 'yellow' : 'green');
        log_message('info', "[finalize:expired] {$summary}");
    }
}

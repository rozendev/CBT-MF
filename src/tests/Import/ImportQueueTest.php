<?php
namespace Tests\Import;

use App\Libraries\Import\ImportQueue;
use App\Libraries\Import\ImportFailure;
use PHPUnit\Framework\TestCase;

final class ImportQueueTest extends TestCase
{
    private string $dir;
    private string $upload;
    private float $now = 1000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cbt-import-test-' . bin2hex(random_bytes(6));
        $this->upload = tempnam(sys_get_temp_dir(), 'cbt-sheet-') . '.xlsx';
        file_put_contents($this->upload, 'fixture');
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path)) { foreach (glob($path . '/*') as $child) $remove($child); rmdir($path); }
            elseif (is_file($path)) unlink($path);
        };
        $remove($this->dir);
        unlink($this->upload);
        unlink(substr($this->upload, 0, -5));
    }

    private function queue(int $capacity = 10): ImportQueue
    {
        return new ImportQueue($this->dir, $capacity, fn () => $this->now);
    }

    private function submit(string $request = 'one', int $owner = 7): array
    {
        return $this->queue()->enqueue('students', $owner, $this->upload, ['group_id' => 2], $request);
    }

    public function testSubmissionPersistsWithoutBrowserAndIsOwnerScoped(): void
    {
        $job = $this->submit();
        $this->assertSame('queued', $job['status']);
        $this->assertNull($this->queue()->status($job['job_id'], 8));
        $this->assertNull($this->queue()->status('../secret', 7));
        $this->assertSame($job, $this->queue()->status($job['job_id'], 7));
        $this->assertStringNotContainsString($this->dir, json_encode($job));
        $this->assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function testLostUploadResponseCanBeRetriedWithoutCreatingAnotherJob(): void
    {
        $first = $this->submit();
        $this->assertSame($first['job_id'], $this->submit()['job_id']);
        $this->assertNotSame($first['job_id'], $this->submit('two')['job_id']);
        $this->assertNotSame($first['job_id'], $this->submit('one', 8)['job_id']);
    }

    public function testQueueAppliesBackpressureBeforeAcceptingAnotherUpload(): void
    {
        $this->submit();
        $this->expectException(ImportFailure::class);
        $this->queue(1)->enqueue('word', 7, $this->upload, [], 'two');
    }

    public function testOnlyOneWorkerCanProcessAcrossImportTypes(): void
    {
        $one = $this->submit();
        $this->now += 0.01;
        $this->queue()->enqueue('word', 7, $this->upload, [], 'two');
        $this->assertTrue($this->queue()->runOne(function (array $job, callable $checkpoint): array {
            $this->assertFalse($this->queue()->runOne(fn () => $this->fail('Concurrent worker executed')));
            $checkpoint(['processed' => 1, 'total' => 1]);
            return ['status' => 'success', 'success' => 1];
        }));
        $status = $this->queue()->status($one['job_id'], 7);
        $this->assertSame('completed', $status['status']);
        $this->assertSame(1, $status['progress']['processed']);
        $this->assertSame('success', $status['result']['status']);
    }

    public function testTransientFailuresStopAfterInitialAttemptPlusThreeRetries(): void
    {
        $job = $this->submit();
        $calls = 0;
        $processor = function () use (&$calls): array { $calls++; throw new ImportFailure('Database sementara tidak tersedia.', true); };
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($this->queue()->runOne($processor));
            $this->assertFalse($this->queue()->runOne($processor));
            $this->now += 60;
        }
        $this->assertSame(4, $calls);
        $this->assertSame('failed', $this->queue()->status($job['job_id'], 7)['status']);
    }

    public function testValidationFailuresAreNotRetried(): void
    {
        $job = $this->submit();
        $this->queue()->runOne(fn () => throw new ImportFailure('Format tidak valid.'));
        $this->assertSame('failed', $this->queue()->status($job['job_id'], 7)['status']);
        $this->assertFalse($this->queue()->runOne(fn () => $this->fail('Permanent error was retried')));
    }

    public function testCrashedWorkerResumesFromCheckpointAfterBoundedDelay(): void
    {
        $job = $this->submit();
        $pid = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $this->queue()->runOne(function ($job, $checkpoint): array { $checkpoint(['cursor' => 12]); exit(0); });
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        $this->assertSame('running', $this->queue()->status($job['job_id'], 7)['status']);
        $this->assertFalse($this->queue()->runOne(fn () => $this->fail('Crash recovery skipped backoff')));
        $this->now += 60;
        $this->assertTrue($this->queue()->runOne(function ($job): array {
            $this->assertSame(12, $job['progress']['cursor']);
            return ['status' => 'success'];
        }));
        $this->assertSame('completed', $this->queue()->status($job['job_id'], 7)['status']);
    }

    public function testRequestIdentityCanRecoverAnAcceptedUploadWithoutTheFile(): void
    {
        $job = $this->submit('lost-response');
        $this->assertSame($job['job_id'], $this->queue()->lookup('lost-response', 7)['job_id']);
        $this->assertNull($this->queue()->lookup('lost-response', 8));
    }

    public function testOldOrphanUploadsAndTemporaryMetadataArePruned(): void
    {
        $this->queue();
        $orphan = $this->dir . '/uploads/orphan.xlsx';
        $temp = $this->dir . '/jobs/unfinished.tmp';
        file_put_contents($orphan, 'password-fixture'); file_put_contents($temp, '{}');
        touch($orphan, 100); touch($temp, 100);
        $this->now += 86401;
        $this->submit();
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileDoesNotExist($temp);
    }

    public function testCorruptMetadataDoesNotBlockHealthyJobs(): void
    {
        $bad = $this->submit('bad');
        file_put_contents($this->dir . '/jobs/' . $bad['job_id'] . '.json', '{broken');
        $this->now++;
        $good = $this->submit('good');
        $this->assertTrue($this->queue()->runOne(fn () => ['status' => 'success']));
        $this->assertSame('completed', $this->queue()->status($good['job_id'], 7)['status']);
    }
}

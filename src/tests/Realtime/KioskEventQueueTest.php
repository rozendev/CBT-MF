<?php

namespace Tests\Realtime;

use App\Libraries\KioskEventQueue;
use PHPUnit\Framework\TestCase;

final class FakeQueueRedis
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    public function rPush(string $key, string $value): int
    {
        $this->lists[$key] ??= [];
        $this->lists[$key][] = $value;
        return count($this->lists[$key]);
    }

    public function lPop(string $key): string|false
    {
        if (empty($this->lists[$key])) {
            return false;
        }
        return array_shift($this->lists[$key]);
    }
}

class KioskEventQueueTest extends TestCase
{
    public function testPrepareIncomingEventAcceptsValidKioskEventAndPreservesTimestamp(): void
    {
        $now = 1_700_000_123;
        $row = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_event',
            'type'   => 'exit_attempt',
            'data'   => ['source' => 'hardware_back', 'count' => 2],
        ], 14, 108, $now);

        $this->assertNotNull($row);
        $this->assertSame(14, $row['exam_session_id']);
        $this->assertSame(108, $row['student_id']);
        $this->assertSame('exit_attempt', $row['event_type']);
        $this->assertSame('{"source":"hardware_back","count":2}', $row['event_details']);
        $this->assertSame(date('Y-m-d H:i:s', $now), $row['created_at']);
    }

    public function testPrepareIncomingEventSupportsKioskStatusFallbackType(): void
    {
        $now = 1_700_000_200;
        $withStatus = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_status',
            'status' => 'pinned_active',
        ], 5, 22, $now);

        $this->assertNotNull($withStatus);
        $this->assertSame('pinned_active', $withStatus['event_type']);
        $this->assertNull($withStatus['event_details']);

        $withoutStatus = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_status',
        ], 5, 22, $now);

        $this->assertNotNull($withoutStatus);
        $this->assertSame('status_change', $withoutStatus['event_type']);
    }

    public function testPrepareIncomingEventTruncatesMultiByteEventTypeTo50Chars(): void
    {
        // 60 multi-byte UTF-8 characters ("é" = 2 bytes each -> 120 bytes)
        $longType = str_repeat('é', 60);
        $row = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_event',
            'type'   => $longType,
            'data'   => ['ok' => true],
        ], 3, 9, 1_700_000_000);

        $this->assertNotNull($row);
        $this->assertSame(50, mb_strlen($row['event_type'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($row['event_type'], 'UTF-8'));
    }

    public function testPrepareIncomingEventRejectsUnknownActionInvalidIdsOrOversizedDetails(): void
    {
        $this->assertNull(KioskEventQueue::prepareIncomingEvent([
            'action' => 'arbitrary_sql_spam',
            'type'   => 'anything',
        ], 3, 9, 1_700_000_000));

        $this->assertNull(KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_event',
            'type'   => 'exit_attempt',
        ], 0, 9, 1_700_000_000));

        $this->assertNull(KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_event',
            'type'   => 'exit_attempt',
        ], 3, 0, 1_700_000_000));

        $oversized = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_event',
            'type'   => 'security_alert',
            'data'   => ['blob' => str_repeat('X', KioskEventQueue::MAX_DETAILS_BYTES + 10)],
        ], 3, 9, 1_700_000_000);

        $this->assertNull($oversized);
    }

    public function testDrainToDatabaseBatchesValidItemsSkipsCorruptEntriesAndHandlesDbErrors(): void
    {
        $redis = new FakeQueueRedis();
        $valid1 = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_event',
            'type'   => 'exit_attempt',
            'data'   => ['step' => 1],
        ], 7, 31, 1_700_000_001);
        $valid2 = KioskEventQueue::prepareIncomingEvent([
            'action' => 'kiosk_status',
            'type'   => 'started',
            'data'   => ['step' => 2],
        ], 7, 32, 1_700_000_002);

        $redis->rPush(KioskEventQueue::QUEUE_KEY, (string) json_encode($valid1));
        $redis->rPush(KioskEventQueue::QUEUE_KEY, '{corrupt-json');
        $redis->rPush(KioskEventQueue::QUEUE_KEY, (string) json_encode(['missing_fields' => true]));
        $redis->rPush(KioskEventQueue::QUEUE_KEY, (string) json_encode($valid2));

        $insertedBatches = [];
        $count = KioskEventQueue::drainToDatabase(
            $redis,
            10,
            static function (array $rows) use (&$insertedBatches): void {
                $insertedBatches[] = $rows;
            },
            false
        );

        $this->assertSame(2, $count);
        $this->assertCount(1, $insertedBatches);
        $this->assertCount(2, $insertedBatches[0]);
        $this->assertSame(31, $insertedBatches[0][0]['student_id']);
        $this->assertSame(32, $insertedBatches[0][1]['student_id']);
        $this->assertFalse($redis->lPop(KioskEventQueue::QUEUE_KEY));

        // Simulate DB failure during insertBatch: must not throw out of drainToDatabase()
        $redis->rPush(KioskEventQueue::QUEUE_KEY, (string) json_encode($valid1));
        $failedCount = KioskEventQueue::drainToDatabase(
            $redis,
            10,
            static function (): void {
                throw new \RuntimeException('MariaDB connection refused');
            },
            false
        );
        $this->assertSame(0, $failedCount);
    }
}

<?php

namespace Tests\Realtime;

use App\Libraries\KioskEventQueue;
use App\Libraries\SessionInvalidation;
use App\Libraries\WebSocketServerHandler;
use App\Libraries\WebSocketToken;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class FakeUri
{
    public function __construct(private string $query)
    {
    }

    public function getQuery(): string
    {
        return $this->query;
    }
}

final class FakeHttpRequest
{
    public function __construct(private FakeUri $uri)
    {
    }

    public function getUri(): FakeUri
    {
        return $this->uri;
    }
}

#[\AllowDynamicProperties]
final class FakeWsConnection implements ConnectionInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public int $closeCalls = 0;

    public FakeHttpRequest $httpRequest;

    /** @var (callable(self):void)|null */
    public $onCloseCallback = null;

    public function __construct(string $queryString)
    {
        $this->httpRequest = new FakeHttpRequest(new FakeUri($queryString));
    }

    public function send($data)
    {
        $decoded = json_decode((string) $data, true);
        $this->sent[] = is_array($decoded) ? $decoded : ['_raw' => (string) $data];
        return $this;
    }

    public function close(): void
    {
        $this->closeCalls++;
        if ($this->onCloseCallback !== null && $this->closeCalls === 1) {
            ($this->onCloseCallback)($this);
        }
    }
}

final class FakeAsyncRedis
{
    /** @var array<string, string|null> */
    public array $kv = [];

    /** @var array<string, Deferred> */
    public array $deferredGets = [];

    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var list<array{0:string, 1:array<int, mixed>}> */
    public array $commands = [];

    public function get(string $key): PromiseInterface
    {
        $this->commands[] = ['get', [$key]];
        if (isset($this->deferredGets[$key])) {
            return $this->deferredGets[$key]->promise();
        }
        return resolve($this->kv[$key] ?? null);
    }

    public function rpush(string $key, string $value): PromiseInterface
    {
        $this->commands[] = ['rpush', [$key, $value]];
        $this->lists[$key] ??= [];
        $this->lists[$key][] = $value;
        return resolve(count($this->lists[$key]));
    }

    public function ltrim(string $key, int $start, int $stop): PromiseInterface
    {
        $this->commands[] = ['ltrim', [$key, $start, $stop]];
        return resolve(true);
    }

    public function expire(string $key, int $ttl): PromiseInterface
    {
        $this->commands[] = ['expire', [$key, $ttl]];
        return resolve(1);
    }
}

class WebSocketServerHandlerTest extends TestCase
{
    private function openAuthenticatedStudent(
        WebSocketServerHandler $handler,
        FakeAsyncRedis $redis,
        string $token,
        int $userId,
        int $attemptId,
        int $testId,
        int $authVersion = 1
    ): FakeWsConnection {
        $redis->kv[WebSocketToken::studentTokenKey($token)] = (string) json_encode([
            'user_id'      => $userId,
            'attempt_id'   => $attemptId,
            'test_id'      => $testId,
            'auth_version' => $authVersion,
        ]);

        $conn = new FakeWsConnection('ws_token=' . $token);
        $conn->onCloseCallback = static fn (FakeWsConnection $c) => $handler->onClose($c);
        $handler->onOpen($conn);
        $conn->sent = []; // clear initial `connected` frame for focused assertions
        return $conn;
    }

    private function openAuthenticatedProctor(
        WebSocketServerHandler $handler,
        FakeAsyncRedis $redis,
        string $token,
        int $userId,
        int $testId,
        int $authVersion = 1
    ): FakeWsConnection {
        $redis->kv[WebSocketToken::proctorTokenKey($token)] = (string) json_encode([
            'user_id'      => $userId,
            'test_id'      => $testId,
            'auth_version' => $authVersion,
        ]);

        $conn = new FakeWsConnection('proctor_token=' . $token);
        $conn->onCloseCallback = static fn (FakeWsConnection $c) => $handler->onClose($c);
        $handler->onOpen($conn);
        $conn->sent = [];
        return $conn;
    }

    public function testSyncModeAndExtendTimeAreIsolatedToMatchingTestIdOnly(): void
    {
        $redis   = new FakeAsyncRedis();
        $handler = new WebSocketServerHandler($redis);

        $studentTest5  = $this->openAuthenticatedStudent($handler, $redis, 'tok5', 101, 201, 5);
        $studentTest99 = $this->openAuthenticatedStudent($handler, $redis, 'tok99', 102, 202, 99);

        // sync_mode for test 5 must NEVER reach student in test 99
        $handler->broadcastEvent([
            'event'            => 'sync_mode',
            'test_id'          => 5,
            'exam_mode'        => 'static',
            'static_page_path' => 'static/2026-11-02/test5.html',
        ]);

        $this->assertCount(1, $studentTest5->sent);
        $this->assertSame('sync_mode', $studentTest5->sent[0]['event']);
        $this->assertCount(0, $studentTest99->sent, 'Student in test 99 must receive 0 sync_mode frames from test 5');

        // Unscoped event without user_id, attempt_id, or test_id must be dropped rather than broadcast globally
        $studentTest5->sent = [];
        $handler->broadcastEvent([
            'event'            => 'sync_mode',
            'exam_mode'        => 'static',
            'static_page_path' => 'static/2026-11-02/unscoped.html',
        ]);
        $this->assertCount(0, $studentTest5->sent);
        $this->assertCount(0, $studentTest99->sent);
    }

    public function testProctorAlertAndReportAlertNeverLeakToStudentsAndArriveUnwrappedInTargetProctorRoom(): void
    {
        $redis   = new FakeAsyncRedis();
        $handler = new WebSocketServerHandler($redis);

        $studentTest5  = $this->openAuthenticatedStudent($handler, $redis, 'stu5', 101, 201, 5);
        $proctorTest5  = $this->openAuthenticatedProctor($handler, $redis, 'proc5', 501, 5);
        $proctorTest99 = $this->openAuthenticatedProctor($handler, $redis, 'proc99', 502, 99);

        // 1) proctor_alert (auto_submit from ExamService)
        $handler->broadcastEvent([
            'event' => 'proctor_alert',
            'data'  => [
                'user_id' => 101,
                'test_id' => 5,
                'event'   => 'auto_submit',
                'reason'  => 'membuka tab lain',
            ],
        ]);

        $this->assertCount(0, $studentTest5->sent, 'Students must NEVER receive proctor_alert');
        $this->assertCount(0, $proctorTest99->sent, 'Proctor in test 99 must not receive test 5 alert');
        $this->assertCount(1, $proctorTest5->sent);
        $this->assertSame('proctor_alert', $proctorTest5->sent[0]['event']);
        $this->assertSame('auto_submit', $proctorTest5->sent[0]['data']['event']);
        $this->assertSame(101, $proctorTest5->sent[0]['data']['user_id']);

        // 2) proctor_report_alert (from LiveController::reportStudent)
        $proctorTest5->sent = [];
        $handler->broadcastEvent([
            'event' => 'proctor_report_alert',
            'data'  => [
                'student_id'       => 101,
                'test_id'          => 5,
                'student_username' => 'siswa01',
                'proctor_name'     => 'guru01',
                'suggested_action' => 'ban',
                'reason'           => 'Membuka catatan',
            ],
        ]);

        $this->assertCount(0, $studentTest5->sent, 'Students must NEVER receive proctor_report_alert');
        $this->assertCount(0, $proctorTest99->sent);
        $this->assertCount(1, $proctorTest5->sent);
        $this->assertSame('proctor_alert', $proctorTest5->sent[0]['event']);
        $this->assertSame('proctor_report_alert', $proctorTest5->sent[0]['data']['event']);
        $this->assertSame('guru01', $proctorTest5->sent[0]['data']['proctor_name']);
    }

    public function testKioskEventEnforcesStudentRoleSizeCapRateLimitAndQueuesAsyncWithoutSql(): void
    {
        $now     = 1_700_000_000;
        $redis   = new FakeAsyncRedis();
        $handler = new WebSocketServerHandler($redis, static function () use (&$now): int {
            return $now;
        });

        $proctor = $this->openAuthenticatedProctor($handler, $redis, 'proc5', 501, 5);
        $student = $this->openAuthenticatedStudent($handler, $redis, 'stu5', 101, 201, 5);

        // Unauthenticated socket sending kiosk_event must be ignored
        $unauth = new FakeWsConnection('ws_token=pending');
        $redis->deferredGets[WebSocketToken::studentTokenKey('pending')] = new Deferred();
        $handler->onOpen($unauth);
        $handler->onMessage($unauth, (string) json_encode([
            'action' => 'kiosk_event',
            'type'   => 'spoof',
            'data'   => [],
        ]));
        $this->assertEmpty($redis->lists[KioskEventQueue::QUEUE_KEY] ?? []);

        // Proctor socket sending kiosk_event must be ignored
        $handler->onMessage($proctor, (string) json_encode([
            'action' => 'kiosk_event',
            'type'   => 'proctor_spoof',
            'data'   => [],
        ]));
        $this->assertEmpty($redis->lists[KioskEventQueue::QUEUE_KEY] ?? []);

        // Oversized message (> 4096 bytes) must be dropped before decode/queue
        $handler->onMessage($student, json_encode([
            'action' => 'kiosk_event',
            'type'   => 'huge',
            'data'   => ['pad' => str_repeat('A', KioskEventQueue::MAX_INCOMING_BYTES + 100)],
        ]));
        $this->assertEmpty($redis->lists[KioskEventQueue::QUEUE_KEY] ?? []);

        // Rate limit: 12 events in same 10s window -> exactly 10 queued and 10 broadcast to proctor
        $proctor->sent = [];
        for ($i = 1; $i <= 12; $i++) {
            $handler->onMessage($student, (string) json_encode([
                'action' => 'kiosk_event',
                'type'   => 'exit_attempt',
                'data'   => ['seq' => $i],
            ]));
        }

        $this->assertCount(KioskEventQueue::RATE_MAX_EVENTS, $redis->lists[KioskEventQueue::QUEUE_KEY]);
        $this->assertCount(KioskEventQueue::RATE_MAX_EVENTS, $proctor->sent);

        // Advance clock by 10s -> window resets and accepts new event
        $now += KioskEventQueue::RATE_WINDOW_SECONDS;
        $handler->onMessage($student, (string) json_encode([
            'action' => 'kiosk_status',
            'status' => 'started',
            'data'   => ['seq' => 13],
        ]));
        $this->assertCount(KioskEventQueue::RATE_MAX_EVENTS + 1, $redis->lists[KioskEventQueue::QUEUE_KEY]);
    }

    public function testHandshakeRejectsRevokedAuthStateAndTerminalEventsCloseStudentAndProctorSockets(): void
    {
        $redis   = new FakeAsyncRedis();
        $handler = new WebSocketServerHandler($redis);

        // 1) Student or proctor whose user_auth_state has higher auth_version or is_active=0 is rejected on onOpen
        $redis->kv[WebSocketToken::proctorTokenKey('stale-proc')] = (string) json_encode([
            'user_id'      => 77,
            'test_id'      => 5,
            'auth_version' => 1,
        ]);
        $redis->kv[SessionInvalidation::stateKey(77)] = (string) json_encode([
            'auth_version' => 2,
            'cred_version' => 1,
            'is_active'    => 1,
            'role'         => 'guru',
        ]);

        $staleProctor = new FakeWsConnection('proctor_token=stale-proc');
        $handler->onOpen($staleProctor);
        $this->assertSame(1, $staleProctor->closeCalls, 'Proctor socket with stale auth_version must be closed');
        $this->assertCount(0, $staleProctor->sent);

        // 2) Active student & active proctor sockets are forcibly closed by server on kick/ban/ejected
        $student = $this->openAuthenticatedStudent($handler, $redis, 'live-stu', 101, 201, 5, 1);
        $proctor = $this->openAuthenticatedProctor($handler, $redis, 'live-proc', 501, 5, 1);

        // Eject student -> student receives `ejected` AND server closes $student
        $handler->broadcastEvent([
            'event'      => 'ejected',
            'user_id'    => 101,
            'attempt_id' => 201,
            'test_id'    => 5,
            'message'    => 'Dikeluarkan pengawas',
        ]);
        $this->assertSame(1, $student->closeCalls);
        $this->assertSame('ejected', $student->sent[0]['event']);

        // Kick proctor (e.g. admin changed proctor's password / role -> UserAuthState publishes kick for user_id=501)
        $proctor->sent = [];
        $handler->broadcastEvent([
            'event'   => 'kick',
            'user_id' => 501,
            'message' => 'Kredensial pengawas diperbarui.',
        ]);
        $this->assertSame(1, $proctor->closeCalls, 'Server must close proctor socket when proctor user_id is kicked');
        $this->assertSame('kick', $proctor->sent[0]['event']);

        // Subsequent test 5 alerts must no longer reach the closed proctor socket
        $proctor->sent = [];
        $handler->broadcastToProctors(['event' => 'auto_submit', 'user_id' => 101], 5);
        $this->assertCount(0, $proctor->sent);
    }

    public function testSocketClosedBeforeAsyncRedisResolvesLeavesZeroPhantomConnections(): void
    {
        $redis   = new FakeAsyncRedis();
        $handler = new WebSocketServerHandler($redis);

        $proctorMonitor = $this->openAuthenticatedProctor($handler, $redis, 'monitor', 900, 5);

        $deferredStu = new Deferred();
        $redis->deferredGets[WebSocketToken::studentTokenKey('race-stu')] = $deferredStu;

        $ghostStudent = new FakeWsConnection('ws_token=race-stu');
        $handler->onOpen($ghostStudent);

        // Client disconnects BEFORE Redis replies to GET ws_student_token:race-stu
        $handler->onClose($ghostStudent);

        // Now Redis fulfills with a valid token payload
        $deferredStu->resolve((string) json_encode([
            'user_id'      => 333,
            'attempt_id'   => 444,
            'test_id'      => 5,
            'auth_version' => 1,
        ]));

        // Must NOT send `connected`, must NOT notify proctor of `student_connected`,
        // and must NOT register in $userConnections or $testConnections!
        $this->assertCount(0, $ghostStudent->sent);
        $this->assertCount(0, $proctorMonitor->sent, 'Phantom closed socket must not trigger student_connected');

        $handler->broadcastEvent([
            'event'   => 'cred_updated',
            'user_id' => 333,
            'message' => 'test',
        ]);
        $handler->broadcastEvent([
            'event'            => 'sync_mode',
            'test_id'          => 5,
            'exam_mode'        => 'static',
            'static_page_path' => 'static/x.html',
        ]);
        $this->assertCount(0, $ghostStudent->sent, 'Closed socket must not leak into userConnections or testConnections');

        // Second-stage race: disconnect AFTER token GET resolves, while user_auth_state GET is in flight
        $redis->kv[WebSocketToken::proctorTokenKey('race-proc')] = (string) json_encode([
            'user_id'      => 777,
            'test_id'      => 5,
            'auth_version' => 1,
        ]);
        $deferredState = new Deferred();
        $redis->deferredGets[SessionInvalidation::stateKey(777)] = $deferredState;

        $ghostProctor = new FakeWsConnection('proctor_token=race-proc');
        $handler->onOpen($ghostProctor);
        $handler->onClose($ghostProctor);

        $deferredState->resolve((string) json_encode([
            'auth_version' => 1,
            'cred_version' => 1,
            'is_active'    => 1,
            'role'         => 'guru',
        ]));

        $this->assertCount(0, $ghostProctor->sent);
        $handler->broadcastToProctors(['event' => 'auto_submit', 'user_id' => 101], 5);
        $this->assertCount(0, $ghostProctor->sent, 'Closed proctor socket must not leak into proctorRooms');
    }
}

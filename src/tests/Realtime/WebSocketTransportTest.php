<?php

namespace Tests\Realtime;

use App\Libraries\BoundedWsServer;
use App\Libraries\WebSocketOriginGuard;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\RFC6455\Messaging\Frame;
use App\Libraries\WebSocketServerHandler;
use React\Promise\Deferred;

#[\AllowDynamicProperties]
final class TransportConnection implements ConnectionInterface
{
    public array $sent = [];
    public int $closes = 0;
    public function send($data) { $this->sent[] = (string) $data; return $this; }
    public function close() { $this->closes++; }
}

final class TransportApplication implements MessageComponentInterface
{
    public array $messages = [];
    public int $opens = 0;
    public int $closes = 0;
    public bool $closeOnOpen = false;
    public bool $throwOnOpen = false;
    public function onOpen(ConnectionInterface $conn) {
        $this->opens++;
        if ($this->throwOnOpen) throw new \RuntimeException('open failed');
        if ($this->closeOnOpen) $conn->close();
    }
    public function onMessage(ConnectionInterface $from, $msg) { $this->messages[] = (string) $msg; }
    public function onClose(ConnectionInterface $conn) { $this->closes++; }
    public function onError(ConnectionInterface $conn, \Exception $e) { $conn->close(); }
}

final class WebSocketTransportTest extends TestCase
{
    protected function setUp(): void
    {
        $this->assertTrue(class_exists(BoundedWsServer::class), 'Bounded transport is missing');
        $this->assertTrue(class_exists(WebSocketOriginGuard::class), 'Origin guard is missing');
    }

    private function request(?string $origin = 'http://localhost:8080'): Request
    {
        $headers = ['Host' => 'localhost:8080', 'Upgrade' => 'websocket', 'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Version' => '13',
            'X-Forwarded-Proto' => 'http'];
        if ($origin !== null) $headers['Origin'] = $origin;
        return new Request('GET', 'http://localhost:8080/ws?ws_token=secret', $headers);
    }

    private function frame(string $payload, bool $final = true, int $opcode = Frame::OP_TEXT): string
    {
        $frame = new Frame($payload, $final, $opcode);
        $frame->maskPayload('abcd');
        return $frame->getContents();
    }

    private function stack(TransportApplication $app): WebSocketOriginGuard
    {
        return new WebSocketOriginGuard(new BoundedWsServer($app), 'https://school.test/cbt/',
            'https://appassets.androidplatform.net', ['https://extra.test:8443']);
    }

    public function testCoalescedSmallMessagesAndBoundaryPayloadAreDelivered(): void
    {
        $app = new TransportApplication(); $guard = $this->stack($app); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request());
        $this->assertStringStartsWith('HTTP/1.1 101', $conn->sent[0]);
        $guard->onMessage($conn, $this->frame('one') . $this->frame('two') . $this->frame(str_repeat('x', 65536)));
        $this->assertSame(['one', 'two', str_repeat('x', 65536)], $app->messages);
        $guard->onClose($conn);
        $guard->onClose($conn);
        $this->assertSame(1, $app->closes);
    }

    public function testOversizedDeclaredFrameClosesBeforeFullPayloadArrives(): void
    {
        $app = new TransportApplication(); $guard = $this->stack($app); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request());
        // Authentication could still be pending: no application message has been delivered.
        $guard->onMessage($conn, substr($this->frame(str_repeat('x', 65537)), 0, 160));
        $this->assertSame(1, $conn->closes);
        $this->assertSame(1009, unpack('n', substr(end($conn->sent), 2, 2))[1]);
        $this->assertSame([], $app->messages);
    }

    public function testOversizedFrameWhileTokenLookupIsPendingCannotRegisterAfterClose(): void
    {
        $pending = new Deferred();
        $redis = new class($pending) {
            public function __construct(private Deferred $pending) {}
            public function get(string $key) { return $this->pending->promise(); }
        };
        $handler = new WebSocketServerHandler($redis);
        $guard = new WebSocketOriginGuard(new BoundedWsServer($handler), 'http://localhost:8080');
        $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request());
        $this->assertCount(1, $conn->sent, 'Token lookup is unresolved: only HTTP upgrade is sent');
        $guard->onMessage($conn, substr($this->frame(str_repeat('x', 65537)), 0, 160));
        $this->assertSame(1, $conn->closes);
        $this->assertSame(1009, unpack('n', substr(end($conn->sent), 2, 2))[1]);
        $guard->onClose($conn);
        $pending->resolve('{"user_id":101,"attempt_id":201,"test_id":5,"auth_version":1}');
        $handler->broadcastEvent(['event' => 'extend_time', 'test_id' => 5, 'extra_minutes' => 1]);
        $this->assertCount(2, $conn->sent, 'Late token resolution cannot register or receive events');
    }

    public function testFragmentedLimitIncludesContinuationAndAllowsInterleavedPing(): void
    {
        $app = new TransportApplication(); $guard = $this->stack($app); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request());
        $guard->onMessage($conn, $this->frame(str_repeat('a', 32768), false));
        $guard->onMessage($conn, $this->frame('ping', true, Frame::OP_PING));
        $this->assertSame("\x8a\x04ping", end($conn->sent));
        $guard->onMessage($conn, $this->frame(str_repeat('b', 32768), true, Frame::OP_CONTINUE));
        $this->assertSame([str_repeat('a', 32768) . str_repeat('b', 32768)], $app->messages);
        $guard->onMessage($conn, $this->frame(str_repeat('c', 32768), false));
        $guard->onMessage($conn, $this->frame(str_repeat('d', 32769), true, Frame::OP_CONTINUE));
        $this->assertSame(1, $conn->closes);
        $this->assertCount(1, $app->messages);
    }

    public static function allowedOrigins(): array
    {
        return [['http://localhost:8080'], ['https://school.test'], ['https://SCHOOL.TEST:443'],
            ['https://appassets.androidplatform.net'], ['https://extra.test:8443']];
    }

    #[DataProvider('allowedOrigins')]
    public function testAllowedOriginUpgrades(string $origin): void
    {
        $guard = $this->stack(new TransportApplication()); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request($origin));
        $this->assertStringStartsWith('HTTP/1.1 101', $conn->sent[0]);
    }

    public static function rejectedOrigins(): array
    {
        return [[null], [''], ['null'], ['http://localhost'], ['https://school.test.evil'],
            ['https://school.test/'], ['https://user@school.test'], ['https://school.test?x'],
            ['https://school.test#x'], ['https://school.test, https://extra.test'], ['ftp://school.test']];
    }

    #[DataProvider('rejectedOrigins')]
    public function testRejectedOriginNeverReachesApplication(?string $origin): void
    {
        $app = new TransportApplication(); $guard = $this->stack($app); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request($origin));
        $this->assertStringStartsWith('HTTP/1.1 403', $conn->sent[0]);
        $guard->onMessage($conn, $this->frame('blocked'));
        $guard->onClose($conn);
        $guard->onError($conn, new \RuntimeException('secret'));
        $this->assertSame(0, $app->opens);
        $this->assertSame([], $app->messages);
        $this->assertSame(0, $app->closes);
        $this->assertStringNotContainsString('secret', implode('', $conn->sent));
    }

    public function testDuplicateOriginIsRejectedAndKioskOverrideWorks(): void
    {
        $app = new TransportApplication(); $guard = $this->stack($app); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request()->withHeader('Origin', ['http://localhost:8080', 'https://school.test']));
        $this->assertStringStartsWith('HTTP/1.1 403', $conn->sent[0]);
        $custom = new WebSocketOriginGuard(new BoundedWsServer($app), 'https://school.test/', 'https://custom-kiosk.test');
        $other = new TransportConnection(); $custom->onOpen($other, $this->request('https://custom-kiosk.test'));
        $this->assertStringStartsWith('HTTP/1.1 101', $other->sent[0]);
    }

    public function testFailedHandshakeAndImmediateApplicationCloseLeaveNoMessages(): void
    {
        $app = new TransportApplication(); $guard = $this->stack($app); $conn = new TransportConnection();
        $guard->onOpen($conn, $this->request()->withoutHeader('Sec-WebSocket-Key'));
        $guard->onClose($conn);
        $this->assertSame(0, $app->opens);
        $app->closeOnOpen = true; $closed = new TransportConnection();
        $guard->onOpen($closed, $this->request());
        $guard->onMessage($closed, $this->frame('ignored'));
        $guard->onClose($closed);
        $this->assertSame([], $app->messages);
    }

    public function testThrowingOpenCleansApplicationContextExactlyOnce(): void
    {
        $app = new TransportApplication(); $app->throwOnOpen = true;
        $guard = $this->stack($app); $conn = new TransportConnection();
        try {
            $guard->onOpen($conn, $this->request());
            $this->fail('Expected open failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('open failed', $e->getMessage());
        }
        $guard->onClose($conn);
        $this->assertSame(1, $app->closes);
        $this->assertSame(1, $conn->closes);
    }
}

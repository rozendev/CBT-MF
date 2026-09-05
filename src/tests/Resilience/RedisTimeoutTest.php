<?php

namespace Tests\Resilience;

use App\Libraries\RedisClient;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * A frozen Redis must fail fast, never hang.
 *
 * Regression guard for the outage that left students staring at a spinning
 * autosave forever: every Redis connection in the app set at most a CONNECT
 * timeout, and connecting to a frozen Redis succeeds instantly. The first
 * command then blocked with no read timeout at all, pinning the php-fpm
 * worker permanently — the browser request never completed, so the frontend
 * never reached any error path.
 */
class RedisTimeoutTest extends TestCase
{
    private const PORT = 63999;

    /**
     * A listening socket that is never accept()ed.
     *
     * The kernel completes the TCP handshake from the listen backlog on its
     * own, so phpredis connects successfully and then waits forever for a
     * reply that no userspace code will ever write. That is a frozen Redis,
     * with no second process required — proc_open is in disable_functions.
     *
     * @var resource|null
     */
    private static $listener;

    public static function setUpBeforeClass(): void
    {
        self::$listener = @stream_socket_server(
            'tcp://127.0.0.1:' . self::PORT,
            $errno,
            $errstr,
        );

        if (self::$listener === false) {
            self::fail('cannot bind frozen-Redis stub on port ' . self::PORT . ': ' . $errstr);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$listener)) {
            fclose(self::$listener);
        }
    }

    /**
     * Hard stop, so a regression fails the suite instead of hanging it forever.
     */
    private function failIfItHangs(int $seconds): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            echo "\nHANG: perintah Redis tidak pernah kembali — read timeout hilang.\n";
            exit(1);
        });
        pcntl_alarm($seconds);
    }

    public function testReadTimeoutIsConfiguredAndNonZero(): void
    {
        // phpredis treats 0 as "wait forever". That single value is the whole bug.
        $this->assertGreaterThan(0, RedisClient::READ_TIMEOUT_SECONDS);
        $this->assertGreaterThan(0, RedisClient::CONNECT_TIMEOUT_SECONDS);
    }

    public function testCommandAgainstFrozenRedisFailsFastInsteadOfHanging(): void
    {
        $budget = RedisClient::READ_TIMEOUT_SECONDS + 5;
        $this->failIfItHangs($budget);

        $redis = new Redis();
        $this->assertTrue(
            $redis->connect('127.0.0.1', self::PORT, RedisClient::CONNECT_TIMEOUT_SECONDS),
            'a frozen Redis still completes the TCP handshake — that is the trap',
        );
        $redis->setOption(Redis::OPT_READ_TIMEOUT, RedisClient::READ_TIMEOUT_SECONDS);

        $start = microtime(true);
        try {
            $redis->ping();
            $this->fail('a frozen Redis must not answer PING');
        } catch (\RedisException $e) {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan($budget, $elapsed, 'PING took too long to give up');
        } finally {
            pcntl_alarm(0);
        }
    }

    public function testRedisClientAppliesTheReadTimeoutItself(): void
    {
        // The constants above are useless if RedisClient never applies them.
        $budget = RedisClient::READ_TIMEOUT_SECONDS + 5;
        $this->failIfItHangs($budget);

        $_ENV['redis.host'] = '127.0.0.1';
        $_ENV['redis.port'] = (string) self::PORT;
        $_ENV['REDIS_PASSWORD'] = '';

        $client = RedisClient::getInstance();
        $this->assertNotNull($client, 'connect succeeds against a frozen Redis');

        $start = microtime(true);
        try {
            $client->ping();
            $this->fail('a frozen Redis must not answer PING');
        } catch (\RedisException $e) {
            $this->assertLessThan($budget, microtime(true) - $start);
        } finally {
            pcntl_alarm(0);
            RedisClient::reset();
        }
    }
}

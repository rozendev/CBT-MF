<?php

namespace Tests\Throttling;

use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use CodeIgniter\Test\CIUnitTestCase;

final class LoginThrottleTest extends CIUnitTestCase
{
    private const IP_A = '203.0.113.201';
    private const IP_B = '203.0.113.202';

    private function redis(): \Redis
    {
        $r = RedisClient::getInstance();
        if ($r === null) {
            $this->markTestSkipped('Redis tidak tersedia di lingkungan test.');
        }
        return $r;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $r = $this->redis();
        $r->del(LoginThrottle::key(self::IP_A));
        $r->del(LoginThrottle::key(self::IP_B));
    }

    protected function tearDown(): void
    {
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP_A));
            $r->del(LoginThrottle::key(self::IP_B));
        }
        RedisClient::reset();
        parent::tearDown();
    }

    public function testKeyFormatIsStable(): void
    {
        $this->assertSame('login_attempts_ip:203.0.113.201', LoginThrottle::key(self::IP_A));
    }

    public function testHitIncrementsAndSetsTtlOnFirstHit(): void
    {
        $this->assertSame(1, LoginThrottle::hit(self::IP_A));
        $this->assertSame(2, LoginThrottle::hit(self::IP_A));
        $ttl = $this->redis()->ttl(LoginThrottle::key(self::IP_A));
        $this->assertGreaterThan(0, $ttl, 'TTL harus dipasang pada hit pertama');
        $this->assertLessThanOrEqual(LoginThrottle::WINDOW_SECONDS, $ttl);
    }

    public function testClearForIpRemovesTheCounter(): void
    {
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::clearForIp(self::IP_A);
        $this->assertSame(0, (int) $this->redis()->exists(LoginThrottle::key(self::IP_A)));
    }

    public function testActiveBlocksReportsCounts(): void
    {
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::hit(self::IP_B);
        $blocks = LoginThrottle::activeBlocks();
        $this->assertSame(2, $blocks[self::IP_A] ?? null);
        $this->assertSame(1, $blocks[self::IP_B] ?? null);
    }

    public function testClearAllRemovesEveryCounter(): void
    {
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::hit(self::IP_B);
        $removed = LoginThrottle::clearAll();
        $this->assertGreaterThanOrEqual(2, $removed);
        $this->assertSame([], LoginThrottle::activeBlocks());
    }
}

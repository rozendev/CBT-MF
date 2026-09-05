<?php

namespace Tests\Throttling;

use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

final class AuthUnblockCommandTest extends CIUnitTestCase
{
    // CLI::write/error menulis ke STDOUT/STDERR; command() TIDAK menangkapnya di
    // nilai kembalian, jadi tangkap lewat stream filter dan periksa buffernya.
    use StreamFilterTrait;

    private const IP = '203.0.113.210';

    protected function setUp(): void
    {
        parent::setUp();
        $r = RedisClient::getInstance();
        if ($r === null) {
            $this->markTestSkipped('Redis tidak tersedia.');
        }
        $r->del(LoginThrottle::key(self::IP));
    }

    protected function tearDown(): void
    {
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP));
        }
        RedisClient::reset();
        parent::tearDown();
    }

    public function testUnblockByIpRemovesTheKey(): void
    {
        LoginThrottle::hit(self::IP);
        command('auth:unblock --ip ' . self::IP);
        $this->assertStringContainsString('dibuka', $this->getStreamFilterBuffer());
        $this->assertSame([], LoginThrottle::activeBlocks());
    }

    public function testInvalidIpIsRejected(): void
    {
        command('auth:unblock --ip not-an-ip');
        $this->assertStringContainsString('tidak valid', $this->getStreamFilterBuffer());
    }

    public function testListWithoutArgsShowsBlockedIp(): void
    {
        LoginThrottle::hit(self::IP);
        command('auth:unblock');
        $this->assertStringContainsString(self::IP, $this->getStreamFilterBuffer());
    }

    // Jalur --user butuh tabel users yang termigrasi; lingkungan test 'testing'
    // memakai SQLite kosong, jadi jalur itu diverifikasi runtime (Task 6 Step 6)
    // terhadap MariaDB, bukan di sini.
}

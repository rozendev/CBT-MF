<?php

namespace Tests\Resilience;

use App\Libraries\DeviceBan;
use PHPUnit\Framework\TestCase;

class DeviceBanTest extends TestCase
{
    public function testValidDeviceIdAccepted(): void
    {
        $this->assertTrue(DeviceBan::isValidDeviceId(str_repeat('a', 64)));
        $this->assertTrue(DeviceBan::isValidDeviceId('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertTrue(DeviceBan::isValidDeviceId('abc_123-XYZ'));
    }

    public function testInvalidDeviceIdRejected(): void
    {
        $this->assertFalse(DeviceBan::isValidDeviceId(''));
        $this->assertFalse(DeviceBan::isValidDeviceId(str_repeat('a', 65)), 'lebih dari 64');
        $this->assertFalse(DeviceBan::isValidDeviceId('spasi tidak boleh'));
        $this->assertFalse(DeviceBan::isValidDeviceId("a\nb"));
        $this->assertFalse(DeviceBan::isValidDeviceId('titik.koma;'));
        // PCRE: $ ikut cocok TEPAT SEBELUM newline di ujung, jadi pola yang
        // dijangkar dengan ^...$ meloloskan ini. Harus \A...\z.
        $this->assertFalse(DeviceBan::isValidDeviceId("abc\n"), 'newline di ujung');
        $this->assertFalse(DeviceBan::isValidDeviceId(str_repeat('a', 64) . "\n"), 'newline sesudah 64 karakter');
    }

    public function testCacheKeyIsNamespacedAndStable(): void
    {
        $key = DeviceBan::cacheKey('abc123');

        $this->assertSame('kiosk_device_ban:abc123', $key);
        $this->assertSame($key, DeviceBan::cacheKey('abc123'));
    }

    public function testCacheHitDecidesDirectly(): void
    {
        $this->assertTrue(DeviceBan::decideFromCache('1'));
        $this->assertFalse(DeviceBan::decideFromCache('0'));
    }

    /**
     * Ini tes terpenting di berkas ini. Cache dingin HARUS berarti "belum
     * tahu, tanya database" dan tidak boleh berarti "tidak terblokir".
     * Kalau ini gagal-terbuka, `cbt.sh redis flush` akan membuka semua blokir
     * tanpa suara.
     */
    public function testColdOrGarbageCacheMeansAskTheDatabase(): void
    {
        $this->assertNull(DeviceBan::decideFromCache(null), 'cache dingin');
        $this->assertNull(DeviceBan::decideFromCache(''), 'nilai kosong');
        $this->assertNull(DeviceBan::decideFromCache('true'), 'nilai tak dikenal');
        $this->assertNull(DeviceBan::decideFromCache('2'), 'nilai tak dikenal');
    }

    public function testCacheTtlIsPositive(): void
    {
        $this->assertGreaterThan(0, DeviceBan::CACHE_TTL_SECONDS);
    }
}

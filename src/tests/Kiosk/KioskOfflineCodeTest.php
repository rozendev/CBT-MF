<?php

namespace Tests\Kiosk;

use App\Libraries\KioskOfflineCode;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class KioskOfflineCodeTest extends TestCase
{
    /**
     * Vektor tetap. Nilai ini JUGA dipakai di
     * cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/OfflineExitCodeTest.kt —
     * dua implementasi yang diam-diam tidak sepakat berarti kode di dashboard
     * tidak pernah cocok dengan yang diminta perangkat, dan itu baru ketahuan
     * saat ujian berlangsung.
     */
    public static function vectorProvider(): array
    {
        return [
            'password bawaan'        => ['123456', '2026-09-09', '25967489'],
            'password kuat'          => ['R4hasia-Pengawas-2026', '2026-09-09', '98255973'],
            'pergantian bulan'       => ['R4hasia-Pengawas-2026', '2026-10-01', '52727708'],
            'pergantian tahun'       => ['R4hasia-Pengawas-2026', '2027-01-01', '89306567'],
            'password non-ASCII'     => ['sandi-ünïcode-ø', '2026-12-31', '14433010'],
        ];
    }

    /** @dataProvider vectorProvider */
    public function testKodeHarianCocokDenganVektorTetap(string $password, string $day, string $expected): void
    {
        $this->assertSame($expected, KioskOfflineCode::codeForDay($password, $day));
    }

    public function testKodeSelaluDelapanDigit(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $code = KioskOfflineCode::codeForDay('pw-' . $i, '2026-09-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT));
            $this->assertMatchesRegularExpression('/\A[0-9]{8}\z/', $code, "iterasi $i");
        }
    }

    public function testPasswordBerbedaMenghasilkanKodeBerbeda(): void
    {
        $this->assertNotSame(
            KioskOfflineCode::codeForDay('satu', '2026-09-09'),
            KioskOfflineCode::codeForDay('dua', '2026-09-09')
        );
    }

    public function testHariBerurutanDihitungDiZonaJakarta(): void
    {
        // 2026-09-09 23:45 WIB masih tanggal 9, walau di UTC sudah 16:45.
        $now = new DateTimeImmutable('2026-09-09 23:45:00', new DateTimeZone('Asia/Jakarta'));
        $days = KioskOfflineCode::upcomingDays(3, $now);

        $this->assertSame(['2026-09-09', '2026-09-10', '2026-09-11'], $days);
    }

    public function testAmplopBerisiTujuhHariDenganSaltBerbeda(): void
    {
        $now = new DateTimeImmutable('2026-09-09 10:00:00', new DateTimeZone('Asia/Jakarta'));
        $envelope = KioskOfflineCode::buildEnvelope('R4hasia-Pengawas-2026', $now);

        $this->assertCount(7, $envelope);
        $this->assertSame('2026-09-09', $envelope[0]['day']);
        $this->assertSame('2026-09-15', $envelope[6]['day']);

        $salts = array_column($envelope, 'salt');
        $this->assertCount(7, array_unique($salts), 'salt harus berbeda tiap hari');

        foreach ($envelope as $entry) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $entry['salt']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $entry['hash']);
        }
    }

    public function testHashAmplopDapatDiverifikasiUlang(): void
    {
        $now = new DateTimeImmutable('2026-09-09 10:00:00', new DateTimeZone('Asia/Jakarta'));
        $envelope = KioskOfflineCode::buildEnvelope('R4hasia-Pengawas-2026', $now);
        $entry = $envelope[0];

        $recomputed = hash_pbkdf2(
            'sha256',
            KioskOfflineCode::codeForDay('R4hasia-Pengawas-2026', $entry['day']),
            $entry['salt'],
            KioskOfflineCode::PBKDF2_ITERATIONS,
            64
        );

        $this->assertSame($entry['hash'], $recomputed);
    }

    public function testPasswordBawaanDinilaiLemah(): void
    {
        $reasons = KioskOfflineCode::passwordWeaknesses('123456');

        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('bawaan', implode(' ', $reasons));
    }

    public function testPasswordPendekDinilaiLemah(): void
    {
        $this->assertNotEmpty(KioskOfflineCode::passwordWeaknesses('pengawas'));
    }

    public function testPasswordUmumDinilaiLemah(): void
    {
        $this->assertNotEmpty(KioskOfflineCode::passwordWeaknesses('PASSWORD'));
    }

    public function testPasswordKuatTidakDikeluhkan(): void
    {
        $this->assertSame([], KioskOfflineCode::passwordWeaknesses('R4hasia-Pengawas-2026'));
    }

    public function testEventDibersihkanDanDibatasi(): void
    {
        $events = [];
        for ($i = 0; $i < 40; $i++) {
            $events[] = ['at' => 1757400000 + $i, 'code_day' => '2026-09-09', 'app_version' => '1.0.0'];
        }

        $clean = KioskOfflineCode::sanitizeEvents($events);

        $this->assertCount(KioskOfflineCode::MAX_EVENTS_PER_REQUEST, $clean);
        $this->assertSame(1757400000, $clean[0]['at']);
    }

    public function testEventCacatDibuang(): void
    {
        $clean = KioskOfflineCode::sanitizeEvents([
            ['at' => 1757400000, 'code_day' => '2026-09-09', 'app_version' => '1.0.0'],
            ['at' => 'bukan angka', 'code_day' => '2026-09-09', 'app_version' => '1.0.0'],
            ['at' => 1757400001, 'code_day' => 'bukan tanggal', 'app_version' => '1.0.0'],
            'bukan array',
        ]);

        $this->assertCount(1, $clean);
    }

    public function testEventBukanArrayMenghasilkanArrayKosong(): void
    {
        $this->assertSame([], KioskOfflineCode::sanitizeEvents('bukan array'));
        $this->assertSame([], KioskOfflineCode::sanitizeEvents(null));
    }
}

<?php

namespace Tests\Resilience;

use App\Libraries\SessionTakeover;
use PHPUnit\Framework\TestCase;

/**
 * Aturan gerbang login. Diuji terpisah dari Redis karena inilah bagian yang
 * salah menyimpulkannya berarti siswa terkunci dari ujiannya sendiri, atau
 * sebaliknya, perlindungan multi-login terlewati.
 */
class SessionTakeoverTest extends TestCase
{
    public function testTanpaTokenSelaluBolehLogin(): void
    {
        $this->assertSame(
            SessionTakeover::FRESH,
            SessionTakeover::decide(null, null, 'perangkat-a')
        );
        // Tanpa token, tidak dikirimnya device_id pun tidak masalah:
        // tidak ada sesi yang bisa direbut dari siapa pun.
        $this->assertSame(
            SessionTakeover::FRESH,
            SessionTakeover::decide(null, null, '')
        );
    }

    public function testPerangkatSamaBolehMerebutSesinyaSendiri(): void
    {
        $this->assertSame(
            SessionTakeover::TAKEOVER,
            SessionTakeover::decide('token-lama', 'perangkat-a', 'perangkat-a')
        );
    }

    public function testPerangkatBerbedaDitolak(): void
    {
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', 'perangkat-a', 'perangkat-b')
        );
    }

    /**
     * Login dari browser biasa tidak mengirim device_id. Kalau ini diloloskan,
     * perlindungan multi-login bisa dilewati hanya dengan TIDAK mengirim field
     * itu — ketiadaan bukti bukan bukti.
     */
    public function testTanpaDeviceIdDitolakSaatTokenAda(): void
    {
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', 'perangkat-a', '')
        );
    }

    /**
     * Kunci pendamping bisa hilang lebih dulu (TTL, flush, versi lama).
     * Tidak tahu siapa pemegangnya berarti tidak boleh merebut.
     */
    public function testPendampingHilangDitolak(): void
    {
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', null, 'perangkat-a')
        );
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', '', 'perangkat-a')
        );
    }

    /**
     * 'BANNED' bukan sesi. Perilaku lamanya dipertahankan persis: login yang
     * lolos pemeriksaan kredensial menimpanya, dan penegakan ban sesungguhnya
     * ada di pemeriksaan is_active, bukan di sini.
     */
    public function testBannedDitimpaBukanDitolak(): void
    {
        $this->assertSame(
            SessionTakeover::CLEAR_BANNED,
            SessionTakeover::decide('BANNED', null, 'perangkat-a')
        );
        $this->assertSame(
            SessionTakeover::CLEAR_BANNED,
            SessionTakeover::decide('BANNED', 'perangkat-a', '')
        );
    }
}

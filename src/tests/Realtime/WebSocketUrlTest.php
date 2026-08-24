<?php

namespace Tests\Realtime;

use App\Libraries\WebSocketUrl;
use PHPUnit\Framework\TestCase;

class WebSocketUrlTest extends TestCase
{
    public function testDeriveUsesWssForHttps(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::derive('https://sekolah.id'));
    }

    public function testDeriveUsesWsForHttp(): void
    {
        $this->assertSame('ws://sekolah.id/ws', WebSocketUrl::derive('http://sekolah.id'));
    }

    public function testDeriveToleratesTrailingSlash(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::derive('https://sekolah.id/'));
    }

    public function testDeriveIgnoresApplicationSubpath(): void
    {
        // Paritas dengan perilaku lama: proxy /ws dipasang di root host,
        // bukan di bawah subpath aplikasi.
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::derive('https://sekolah.id/cbt'));
    }

    public function testDeriveMapsDevPortToWebsocketPort(): void
    {
        // Stack dev: nginx di 8080, server Ratchet dipublikasikan di 8060.
        $this->assertSame('ws://localhost:8060/ws', WebSocketUrl::derive('http://localhost:8080'));
    }

    public function testDeriveKeepsOtherPorts(): void
    {
        $this->assertSame('wss://sekolah.id:8443/ws', WebSocketUrl::derive('https://sekolah.id:8443'));
    }

    public function testPickPrefersConfiguredValue(): void
    {
        $this->assertSame(
            'wss://ws.sekolah.id',
            WebSocketUrl::pick('wss://ws.sekolah.id', 'https://sekolah.id')
        );
    }

    public function testPickTrimsTrailingSlashFromConfiguredValue(): void
    {
        $this->assertSame(
            'wss://ws.sekolah.id/soket',
            WebSocketUrl::pick('wss://ws.sekolah.id/soket///', 'https://sekolah.id')
        );
    }

    public function testPickFallsBackWhenConfiguredIsEmpty(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::pick('', 'https://sekolah.id'));
    }

    public function testPickFallsBackWhenConfiguredIsWhitespace(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::pick('   ', 'https://sekolah.id'));
    }

    public function testPickFallsBackWhenConfiguredPointsAtLocalhost(): void
    {
        // Nilai warisan instalasi lama; tidak berguna bagi perangkat siswa.
        $this->assertSame(
            'wss://sekolah.id/ws',
            WebSocketUrl::pick('ws://localhost:8060', 'https://sekolah.id')
        );
    }
}

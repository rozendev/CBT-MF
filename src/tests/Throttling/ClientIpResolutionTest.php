<?php

namespace Tests\Throttling;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class ClientIpResolutionTest extends CIUnitTestCase
{
    private function requestWith(array $server): IncomingRequest
    {
        // getServer() dan populateHeaders() membaca service 'superglobals' (snapshot
        // saat boot), bukan $_SERVER langsung — jadi injeksi lewat service SEBELUM
        // request dibangun agar header CF-Connecting-IP ikut terbaca.
        service('superglobals')->setServerArray($server);

        // App() constructor membangun $proxyIPs dari env (default 172.16.0.0/12).
        return new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
    }

    protected function tearDown(): void
    {
        \Config\Services::resetSingle('superglobals');
        parent::tearDown();
    }

    public function testTrustedPeerUsesCfConnectingIp(): void
    {
        $req = $this->requestWith([
            'REMOTE_ADDR'           => '172.20.0.5',   // bridge docker → tepercaya
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',  // IP publik siswa (dari CF)
        ]);
        $this->assertSame('203.0.113.9', $req->getIPAddress());
    }

    public function testUntrustedPeerIgnoresHeader(): void
    {
        $req = $this->requestWith([
            'REMOTE_ADDR'           => '203.0.113.9',  // bukan proxy tepercaya
            'HTTP_CF_CONNECTING_IP' => '10.0.0.1',     // upaya spoof
        ]);
        $this->assertSame('203.0.113.9', $req->getIPAddress());
    }

    public function testTrustedPeerWithoutHeaderFallsBackToRemoteAddr(): void
    {
        $req = $this->requestWith([
            'REMOTE_ADDR' => '172.20.0.5',             // akses LAN langsung, tanpa CF
        ]);
        $this->assertSame('172.20.0.5', $req->getIPAddress());
    }
}

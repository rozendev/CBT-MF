<?php

namespace Tests\Throttling;

use App\Filters\LoginRateLimitFilter;
use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class LoginRateLimitFilterTest extends CIUnitTestCase
{
    private const IP = '203.0.113.150';        // peer tak-tepercaya → getIPAddress = REMOTE_ADDR
    private const FROZEN_PORT = 63997;

    /** @var resource|null */
    private static $frozen;

    public static function setUpBeforeClass(): void
    {
        self::$frozen = @stream_socket_server('tcp://127.0.0.1:' . self::FROZEN_PORT, $errno, $errstr);
        if (self::$frozen === false) {
            self::fail('gagal bind frozen-Redis stub: ' . $errstr);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$frozen)) {
            fclose(self::$frozen);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP));
        }
        // Ambang kecil = 3, ditanam ke cache agar getValue tak menyentuh DB.
        service('cache')->save('setting_login_ip_max_attempts', 3, 120);
    }

    protected function tearDown(): void
    {
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP));
        }
        service('cache')->delete('setting_login_ip_max_attempts');
        RedisClient::reset();
        \Config\Services::resetSingle('superglobals');
        parent::tearDown();
    }

    private function postRequest(): IncomingRequest
    {
        // getMethod() & getIPAddress() membaca service 'superglobals', bukan $_SERVER.
        service('superglobals')->setServerArray([
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR'    => self::IP,
        ]);
        return new IncomingRequest(new App(), new URI('http://localhost/login'), null, new UserAgent());
    }

    private function failIfItHangs(int $seconds): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            echo "\nHANG: filter tidak pernah kembali\n";
            exit(1);
        });
        pcntl_alarm($seconds);
    }

    public function testThresholdComesFromSettingAndBlocksAboveIt(): void
    {
        $filter = new LoginRateLimitFilter();
        // Ambang 3: percobaan 1,2,3 lolos (null), ke-4 diblokir.
        $this->assertNull($filter->before($this->postRequest()));
        $this->assertNull($filter->before($this->postRequest()));
        $this->assertNull($filter->before($this->postRequest()));
        $blocked = $filter->before($this->postRequest());
        $this->assertInstanceOf(ResponseInterface::class, $blocked);
    }

    public function testFailsOpenWhenRedisFrozen(): void
    {
        $budget = RedisClient::READ_TIMEOUT_SECONDS + 5;
        $this->failIfItHangs($budget);

        // Simpan env asli untuk dipulihkan.
        $orig = [
            'redis.host'     => $_ENV['redis.host'] ?? null,
            'redis.port'     => $_ENV['redis.port'] ?? null,
            'REDIS_PASSWORD' => $_ENV['REDIS_PASSWORD'] ?? null,
        ];

        // Arahkan ke frozen stub. REDIS_PASSWORD='' WAJIB: tanpa ini getInstance
        // mencoba auth() ke stub dan gagal → mengembalikan null (jalur "Redis
        // unreachable" yang memang sudah fail-open sejak dulu). Dengan auth
        // dilewati, getInstance mengembalikan koneksi hidup ke stub yang beku,
        // sehingga incr() melempar exception — persis jalur yang DULU jadi 503.
        RedisClient::reset();
        $_ENV['redis.host']     = '127.0.0.1';
        $_ENV['redis.port']     = (string) self::FROZEN_PORT;
        $_ENV['REDIS_PASSWORD'] = '';

        try {
            $filter = new LoginRateLimitFilter();
            $result = $filter->before($this->postRequest());
            // Keputusan A: error Redis (bukan sekadar unreachable) TIDAK boleh
            // memblokir login. Filter lama mengembalikan 503 di titik ini.
            $this->assertNull($result, 'fail-open: login harus diteruskan saat perintah Redis error');
        } finally {
            pcntl_alarm(0);
            foreach ($orig as $k => $v) {
                if ($v === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $v;
                }
            }
            RedisClient::reset();
        }
    }
}

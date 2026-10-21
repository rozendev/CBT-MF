<?php

namespace Tests\Throttling;

use App\Filters\ApiRateLimitFilter;
use App\Filters\CorsApiFilter;
use App\Libraries\RedisClient;
use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Cache;
use Config\Services;

final class ApiRateLimitFilterTest extends CIUnitTestCase
{
    private const USER_A = 987654301;
    private const USER_B = 987654302;
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir() . '/cbt-api-rate-' . uniqid();
        mkdir($this->cacheDir);
        $config = new Cache();
        $config->file['storePath'] = $this->cacheDir;
        Services::injectMock('cache', new FileHandler($config));
        $this->asUser(self::USER_A);
    }

    protected function tearDown(): void
    {
        RedisClient::reset();
        foreach (glob($this->cacheDir . '/*') as $file) unlink($file);
        rmdir($this->cacheDir);
        parent::tearDown();
    }

    private function asUser(int $id): void
    {
        Services::injectMock('session', new class ($id) {
            public function __construct(private int $id) {}
            public function get(string $key): ?int { return $key === 'user_id' ? $this->id : null; }
        });
    }

    private function request(): IncomingRequest
    {
        service('superglobals')->setServerArray([
            'REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.151',
            'HTTP_ORIGIN' => 'https://appassets.androidplatform.net',
        ]);
        return new IncomingRequest(new App(), new URI('http://localhost/api/exam/autosave'), null, new UserAgent());
    }

    private function brokenRedis(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('isConnected')->willReturn(true);
        $redis->method('ping')->willReturn(true);
        $redis->method('incr')->willThrowException(new \RedisException('Redis unavailable'));
        (new \ReflectionProperty(RedisClient::class, 'instance'))->setValue(null, $redis);
    }

    public function testRedisLimitReportsRemainingWindowAndExposesItToKiosk(): void
    {
        $redis = RedisClient::getInstance();
        $this->assertNotNull($redis);
        $key = 'api_rate_limit:user:' . self::USER_A;
        $redis->setex($key, 7, 30);
        try {
            $request = $this->request();
            $response = (new ApiRateLimitFilter())->before($request);
            $this->assertSame(429, $response->getStatusCode());
            $wait = (int) $response->getHeaderLine('Retry-After');
            $this->assertGreaterThanOrEqual(1, $wait);
            $this->assertLessThanOrEqual(7, $wait);
            $this->assertSame($wait, json_decode($response->getBody(), true)['retry_after']);
            (new CorsApiFilter())->after($request, $response);
            $this->assertStringContainsString('Retry-After', $response->getHeaderLine('Access-Control-Expose-Headers'));
        } finally {
            $redis->del($key);
        }
    }

    public function testFallbackAllowsThirtyRequestsAndDoesNotShareStudentQuotaByIp(): void
    {
        $this->brokenRedis();
        $filter = new ApiRateLimitFilter();
        for ($i = 0; $i < 30; $i++) $this->assertNull($filter->before($this->request()));
        $response = $filter->before($this->request());
        $this->assertNotNull($response, 'The 31st request must be blocked');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));
        $this->asUser(self::USER_B);
        $this->assertNull($filter->before($this->request()), 'Another student behind the same IP must retain their quota');
    }

    public function testFallbackUsesFixedWindowRatherThanExtendingLockoutOnEveryRetry(): void
    {
        $this->brokenRedis();
        $key = 'api_fallback_' . md5('api_rate_limit:user:' . self::USER_A);
        service('cache')->save($key, ['count' => 30, 'reset_at' => time() + 3], 10);
        $filter = new ApiRateLimitFilter();
        $response = $filter->before($this->request());
        $this->assertNotNull($response);
        $this->assertLessThanOrEqual(3, (int) $response->getHeaderLine('Retry-After'));
        $this->assertLessThanOrEqual(time() + 3, service('cache')->get($key)['reset_at']);
        service('cache')->save($key, ['count' => 31, 'reset_at' => time() - 1], 10);
        $this->assertNull($filter->before($this->request()), 'Expired window must let students retry');
    }
}

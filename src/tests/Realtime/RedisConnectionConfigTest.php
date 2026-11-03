<?php

namespace Tests\Realtime;

use App\Libraries\RedisConnectionConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisConnectionConfigTest extends TestCase
{
    protected function setUp(): void
    {
        $this->assertTrue(class_exists(RedisConnectionConfig::class), 'Shared Redis resolver is missing');
    }

    public function testDefaultsAndEnvironmentFallbackKeepRawPassword(): void
    {
        $default = RedisConnectionConfig::fromArray([]);
        $this->assertSame('redis://redis:6379', $default->uri());
        $config = RedisConnectionConfig::fromArray([
            'redis.host' => '', 'redis.port' => '', 'redis.password' => '',
            'REDIS_HOST' => 'external-redis', 'REDIS_PORT' => '6380', 'REDIS_PASSWORD' => '@:#/%&+= x',
        ]);
        $this->assertSame('external-redis', $config->host);
        $this->assertSame(6380, $config->port);
        $this->assertSame('@:#/%&+= x', $config->password);
        $this->assertSame('redis://:%40%3A%23%2F%25%26%2B%3D%20x@external-redis:6380', $config->uri());
    }

    public function testExplicitValuesTakePriorityAndZeroPasswordIsNotEmpty(): void
    {
        $config = RedisConnectionConfig::fromArray([
            'redis.host' => 'chosen', 'redis.port' => '6381', 'redis.password' => '0',
            'REDIS_HOST' => 'ignored', 'REDIS_PORT' => '6379', 'REDIS_PASSWORD' => 'ignored',
        ]);
        $this->assertSame('redis://:0@chosen:6381', $config->uri());
        $this->assertSame('0', $config->password);
    }

    public function testIpv6HasRawConnectHostAndBracketedUri(): void
    {
        $config = RedisConnectionConfig::fromArray(['REDIS_HOST' => '[::1]']);
        $this->assertSame('::1', $config->host);
        $this->assertSame('redis://[::1]:6379', $config->uri());
    }

    public static function literalPasswords(): array
    {
        return [['0'], ['true'], ['false'], ['null'], ['empty']];
    }

    #[DataProvider('literalPasswords')]
    public function testEnvironmentPasswordsAreReadWithoutHelperCoercion(string $password): void
    {
        $keys = ['redis.host', 'redis.port', 'redis.password', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'];
        $environment = $_ENV; $server = $_SERVER; $original = [];
        foreach ($keys as $key) { $original[$key] = getenv($key); unset($_ENV[$key], $_SERVER[$key]); putenv($key); }
        try {
            putenv('REDIS_PASSWORD=' . $password);
            $this->assertSame($password, RedisConnectionConfig::fromEnvironment()->password);
        } finally {
            $_ENV = $environment; $_SERVER = $server;
            foreach ($original as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    public static function invalidConfigurations(): array
    {
        return [
            [['REDIS_PORT' => '0']], [['REDIS_PORT' => '65536']], [['REDIS_PORT' => '1.5']],
            [['REDIS_HOST' => 'user:secret@host']], [['REDIS_HOST' => 'host/path']],
            [['REDIS_HOST' => 'host?password=secret']], [['REDIS_HOST' => "host\r\n"]],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidEndpointFailsWithoutLeakingInput(array $values): void
    {
        try {
            RedisConnectionConfig::fromArray($values);
            $this->fail('Invalid endpoint accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}

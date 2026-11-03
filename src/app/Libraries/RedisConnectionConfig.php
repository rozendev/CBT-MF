<?php

namespace App\Libraries;

final class RedisConnectionConfig
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $password,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $values = [];
        foreach (['redis.host', 'redis.port', 'redis.password', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'] as $key) {
            // CI's env() coerces literal true/false/null/empty. Credentials must
            // retain their bytes; use the same source precedence without coercion.
            $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $values[$key] = $raw === false ? null : $raw;
        }
        return self::fromArray($values);
    }

    public static function fromArray(array $values): self
    {
        $pick = static function (string $first, string $second, $default) use ($values) {
            foreach ([$first, $second] as $key) {
                if (isset($values[$key]) && $values[$key] !== '') {
                    return $values[$key];
                }
            }
            return $default;
        };
        $host = $pick('redis.host', 'REDIS_HOST', 'redis');
        $port = $pick('redis.port', 'REDIS_PORT', 6379);
        $password = $pick('redis.password', 'REDIS_PASSWORD', '');

        if (!is_string($host)) {
            throw new \InvalidArgumentException('Invalid Redis host configuration');
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new \InvalidArgumentException('Invalid Redis IPv6 configuration');
            }
        }
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && !preg_match('/\A[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*\.?\z/D', $host)) {
            throw new \InvalidArgumentException('Invalid Redis host configuration');
        }
        if ((!is_int($port) && (!is_string($port) || !ctype_digit($port))) || (int) $port < 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException('Invalid Redis port configuration');
        }
        if (!is_string($password) && !is_int($password)) {
            throw new \InvalidArgumentException('Invalid Redis password configuration');
        }
        return new self($host, (int) $port, (string) $password);
    }

    public function uri(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $auth = $this->password !== '' ? ':' . rawurlencode($this->password) . '@' : '';
        return 'redis://' . $auth . $host . ':' . $this->port;
    }
}

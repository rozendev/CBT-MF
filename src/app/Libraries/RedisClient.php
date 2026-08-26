<?php

namespace App\Libraries;

class RedisClient
{
    /**
     * Connect timeout. Bounds the case where Redis is gone or the network is
     * partitioned.
     */
    public const CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * Read timeout. Bounds the case that actually took the site down: Redis
     * alive but frozen — OOM, an AOF rewrite stall, swap thrash, or a paused
     * container. The TCP handshake still completes from the listen backlog, so
     * connect() succeeds instantly and the connect timeout never fires. Without
     * this option phpredis waits forever on the reply, pinning one php-fpm
     * worker per request until the pool is exhausted and the whole site,
     * /admin included, stops answering.
     *
     * MUST stay above zero: phpredis reads 0 as "wait forever", which is the
     * default and was the bug.
     */
    public const READ_TIMEOUT_SECONDS = 3;

    private static ?\Redis $instance = null;

    public static function getInstance(): ?\Redis
    {
        if (self::$instance && self::$instance->isConnected()) {
            try {
                if (self::$instance->ping()) {
                    return self::$instance;
                }
            } catch (\Exception $e) {
                self::$instance = null;
            }
        }

        try {
            $redis = new \Redis();
            $host = env('redis.host', 'redis');
            $port = (int) env('redis.port', 6379);
            $password = env('REDIS_PASSWORD', '');

            if (!$redis->connect($host, $port, self::CONNECT_TIMEOUT_SECONDS)) {
                log_message('error', "Could not connect to Redis at {$host}:{$port}");
                return null;
            }

            // Before AUTH, not after: a frozen Redis blocks on the auth reply
            // just as readily as on any other command.
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::READ_TIMEOUT_SECONDS);

            if (!empty($password)) {
                if (!$redis->auth($password)) {
                    log_message('error', "Redis authentication failed at {$host}:{$port}");
                    return null;
                }
            }

            self::$instance = $redis;
            return $redis;
        } catch (\Exception $e) {
            log_message('error', 'Redis connection exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Drops the cached connection so the next getInstance() dials again.
     * Used by tests; also the honest way to recover after a connection has
     * been seen to misbehave.
     */
    public static function reset(): void
    {
        if (self::$instance !== null) {
            try {
                self::$instance->close();
            } catch (\Throwable $e) {
                // Already broken — nothing to salvage.
            }
        }

        self::$instance = null;
    }
}

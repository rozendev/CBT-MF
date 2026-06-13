<?php

namespace App\Libraries;

class RedisClient
{
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
            $password = env('redis.password', '');

            if (!$redis->connect($host, $port, 2.0)) {
                log_message('error', "Could not connect to Redis at {$host}:{$port}");
                return null;
            }

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
}

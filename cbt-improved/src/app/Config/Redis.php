<?php

declare(strict_types=1);

namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class Redis extends BaseConfig
{
    /**
     * Default Redis connection settings
     */
    public array $default = [
        'host'     => getenv('REDIS_HOST') ?: 'redis',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'timeout'  => 0.0,
        'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
        'prefix'   => 'cbt_improved:',
    ];

    /**
     * Session Redis configuration
     */
    public array $session = [
        'host'     => getenv('REDIS_HOST') ?: 'redis',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'timeout'  => 0.0,
        'database' => (int) (getenv('REDIS_DATABASE') ?: 1),
        'prefix'   => 'cbt_session:',
    ];

    /**
     * Cache Redis configuration
     */
    public array $cache = [
        'host'     => getenv('REDIS_HOST') ?: 'redis',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'timeout'  => 0.0,
        'database' => (int) (getenv('REDIS_DATABASE') ?: 2),
        'prefix'   => 'cbt_cache:',
    ];

    /**
     * Queue Redis configuration
     */
    public array $queue = [
        'host'     => getenv('REDIS_HOST') ?: 'redis',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'timeout'  => 0.0,
        'database' => (int) (getenv('REDIS_DATABASE') ?: 3),
        'prefix'   => 'cbt_queue:',
    ];

    /**
     * WebSocket/Real-time Redis configuration (Pub/Sub)
     */
    public array $websocket = [
        'host'     => getenv('REDIS_HOST') ?: 'redis',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'timeout'  => 0.0,
        'database' => (int) (getenv('REDIS_DATABASE') ?: 4),
        'prefix'   => 'cbt_ws:',
    ];
}

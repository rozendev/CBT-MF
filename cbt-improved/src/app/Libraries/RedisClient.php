<?php

declare(strict_types=1);

namespace App\Libraries;

use Predis\ClientInterface;
use CodeIgniter\Config\BaseConfig;

/**
 * Redis Client Wrapper
 * 
 * Provides a centralized Redis client with multiple database support
 * for sessions, cache, queues, and WebSocket pub/sub.
 */
class RedisClient
{
    private static ?ClientInterface $defaultClient = null;
    private static ?ClientInterface $sessionClient = null;
    private static ?ClientInterface $cacheClient = null;
    private static ?ClientInterface $queueClient = null;
    private static ?ClientInterface $websocketClient = null;

    /**
     * Get the default Redis client
     */
    public static function getDefault(): ClientInterface
    {
        if (self::$defaultClient === null) {
            self::$defaultClient = self::createClient('default');
        }
        return self::$defaultClient;
    }

    /**
     * Get the session Redis client
     */
    public static function getSession(): ClientInterface
    {
        if (self::$sessionClient === null) {
            self::$sessionClient = self::createClient('session');
        }
        return self::$sessionClient;
    }

    /**
     * Get the cache Redis client
     */
    public static function getCache(): ClientInterface
    {
        if (self::$cacheClient === null) {
            self::$cacheClient = self::createClient('cache');
        }
        return self::$cacheClient;
    }

    /**
     * Get the queue Redis client
     */
    public static function getQueue(): ClientInterface
    {
        if (self::$queueClient === null) {
            self::$queueClient = self::createClient('queue');
        }
        return self::$queueClient;
    }

    /**
     * Get the WebSocket/Real-time Redis client (for pub/sub)
     */
    public static function getWebSocket(): ClientInterface
    {
        if (self::$websocketClient === null) {
            self::$websocketClient = self::createClient('websocket');
        }
        return self::$websocketClient;
    }

    /**
     * Create a Redis client based on configuration
     */
    private static function createClient(string $type): ClientInterface
    {
        $config = config('Redis')->$type;
        
        $uri = sprintf(
            'tcp://%s:%d',
            $config['host'],
            $config['port']
        );

        $options = [
            'parameters' => [
                'password' => $config['password'] ?: null,
                'database' => $config['database'],
            ],
            'prefix' => $config['prefix'] ?? '',
        ];

        return new \Predis\Client($uri, $options);
    }

    /**
     * Test Redis connection
     * 
     * @param string $type The type of connection to test
     * @return bool True if connection is successful
     */
    public static function testConnection(string $type = 'default'): bool
    {
        try {
            $client = match ($type) {
                'session' => self::getSession(),
                'cache' => self::getCache(),
                'queue' => self::getQueue(),
                'websocket' => self::getWebSocket(),
                default => self::getDefault(),
            };
            
            return $client->ping() === 'PONG';
        } catch (\Exception $e) {
            log_message('error', "Redis {$type} connection failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if all Redis connections are healthy
     */
    public static function healthCheck(): array
    {
        $types = ['default', 'session', 'cache', 'queue', 'websocket'];
        $status = [];

        foreach ($types as $type) {
            $status[$type] = self::testConnection($type);
        }

        return $status;
    }

    /**
     * Publish message to a channel (for WebSocket real-time features)
     */
    public static function publish(string $channel, mixed $data): int|false
    {
        $client = self::getWebSocket();
        $payload = is_string($data) ? $data : json_encode($data);
        
        return $client->publish($channel, $payload);
    }

    /**
     * Store exam answer temporarily in Redis
     */
    public static function storeExamAnswer(int $attemptId, int $questionId, array $answer): bool
    {
        try {
            $key = "exam_answers:{$attemptId}:{$questionId}";
            $client = self::getCache();
            
            return $client->setex($key, 7200, json_encode($answer)) !== false;
        } catch (\Exception $e) {
            log_message('error', "Failed to store exam answer: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve exam answer from Redis
     */
    public static function getExamAnswer(int $attemptId, int $questionId): ?array
    {
        try {
            $key = "exam_answers:{$attemptId}:{$questionId}";
            $client = self::getCache();
            
            $data = $client->get($key);
            
            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            log_message('error', "Failed to retrieve exam answer: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Track cheat warning in Redis
     */
    public static function trackCheatWarning(int $attemptId, array $warning): bool
    {
        try {
            $key = "cheat_warnings:{$attemptId}";
            $client = self::getCache();
            
            // Add to list
            $client->rpush($key, json_encode($warning));
            
            // Set expiry (24 hours)
            $client->expire($key, 86400);
            
            return true;
        } catch (\Exception $e) {
            log_message('error', "Failed to track cheat warning: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all cheat warnings for an attempt
     */
    public static function getCheatWarnings(int $attemptId): array
    {
        try {
            $key = "cheat_warnings:{$attemptId}";
            $client = self::getCache();
            
            $warnings = $client->lrange($key, 0, -1);
            
            return array_map(fn($w) => json_decode($w, true), $warnings);
        } catch (\Exception $e) {
            log_message('error', "Failed to retrieve cheat warnings: " . $e->getMessage());
            return [];
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

/**
 * Health Check API Controller
 * 
 * Provides endpoints for monitoring application health,
 * database connectivity, and Redis status.
 */
class HealthController extends ResourceController
{
    use ResponseTrait;

    /**
     * Get overall system health status
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function index()
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'services' => [],
        ];

        // Check database
        try {
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            $health['services']['database'] = [
                'status' => 'healthy',
                'driver' => config('Database')->default['DBDriver'],
                'host' => config('Database')->default['hostname'],
                'database' => config('Database')->default['database'],
            ];
        } catch (\Exception $e) {
            $health['services']['database'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
            $health['status'] = 'unhealthy';
        }

        // Check Redis
        try {
            $redisClient = new \App\Libraries\RedisClient();
            $redisStatus = \App\Libraries\RedisClient::healthCheck();
            
            $allHealthy = !in_array(false, $redisStatus, true);
            
            $health['services']['redis'] = [
                'status' => $allHealthy ? 'healthy' : 'degraded',
                'connections' => $redisStatus,
            ];

            if (!$allHealthy) {
                $health['status'] = 'degraded';
            }
        } catch (\Exception $e) {
            $health['services']['redis'] = [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
            ];
            $health['status'] = 'unhealthy';
        }

        $statusCode = match ($health['status']) {
            'healthy' => 200,
            'degraded' => 207,
            default => 503,
        };

        return $this->respond($health, $statusCode);
    }

    /**
     * Check database connectivity only
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function database()
    {
        try {
            $db = \Config\Database::connect();
            $startTime = microtime(true);
            $db->query('SELECT 1');
            $responseTime = (microtime(true) - $startTime) * 1000;

            return $this->respond([
                'status' => 'healthy',
                'driver' => config('Database')->default['DBDriver'],
                'host' => config('Database')->default['hostname'],
                'port' => config('Database')->default['port'],
                'database' => config('Database')->default['database'],
                'response_time_ms' => round($responseTime, 2),
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 503);
        }
    }

    /**
     * Check Redis connectivity only
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function redis()
    {
        try {
            $startTime = microtime(true);
            $status = \App\Libraries\RedisClient::healthCheck();
            $responseTime = (microtime(true) - $startTime) * 1000;

            $allHealthy = !in_array(false, $status, true);

            return $this->respond([
                'status' => $allHealthy ? 'healthy' : 'degraded',
                'connections' => $status,
                'response_time_ms' => round($responseTime, 2),
                'timestamp' => date('c'),
            ], $allHealthy ? 200 : 207);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 503);
        }
    }

    /**
     * Liveness probe for Kubernetes/container orchestration
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function live()
    {
        return $this->respond([
            'alive' => true,
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Readiness probe for Kubernetes/container orchestration
     * 
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function ready()
    {
        try {
            // Check critical dependencies
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            
            \App\Libraries\RedisClient::testConnection('default');

            return $this->respond([
                'ready' => true,
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            return $this->fail('Not ready: ' . $e->getMessage(), 503);
        }
    }
}

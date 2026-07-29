<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiRateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $userId = session('user_id');

        // Use user_id as the rate limit key for authenticated sessions.
        // This avoids false positives for multiple users sharing the same public IP (e.g. in school computer labs).
        if ($userId) {
            $key = "api_rate_limit:user:{$userId}";
        } else {
            $ip = $request->getIPAddress();
            $key = "api_rate_limit:ip:{$ip}";
        }

        // Non-aggressive limits for authenticated API requests
        $limit  = 30; // Max 30 requests
        $window = 10; // per 10 seconds (allows burst of 3 reqs/sec average, quick recovery)

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $currentCount = (int)$redis->incr($key);
                if ($currentCount === 1) {
                    $redis->expire($key, $window);
                }

                if ($currentCount > $limit) {
                    $response = service('response');
                    return $response->setStatusCode(429)->setJSON([
                        'status'  => 'error',
                        'message' => 'Terlalu banyak permintaan (Rate limit exceeded). Silakan tunggu beberapa detik.'
                    ]);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'ApiRateLimitFilter Redis error: ' . $e->getMessage());
            // Fallback for API
            $ip = $request->getIPAddress();
            $cacheKey = 'api_fallback_' . md5($ip);
            $cache = service('cache');
            $attempts = (int)$cache->get($cacheKey);
            $cache->save($cacheKey, $attempts + 1, $window);
            if ($attempts > $limit) {
                $response = service('response');
                return $response->setStatusCode(429)->setJSON([
                    'status'  => 'error',
                    'message' => 'Terlalu banyak permintaan (Rate limit exceeded). Silakan tunggu beberapa detik.'
                ]);
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}

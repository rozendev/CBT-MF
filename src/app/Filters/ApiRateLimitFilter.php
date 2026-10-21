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
                    $ttl = (int) $redis->ttl($key);
                    return $this->rateLimited($ttl > 0 ? $ttl : $window);
                }
                return null;
            }
        } catch (\Exception $e) {
            log_message('error', 'ApiRateLimitFilter Redis error: ' . $e->getMessage());
        }

        // Pertahankan identitas siswa dan window tetap saat Redis tidak tersedia.
        // Memperpanjang TTL setiap request dapat mengunci siswa selama retry terus datang.
        $cacheKey = 'api_fallback_' . md5($key);
        $cache = service('cache');
        $now = time();
        $bucket = $cache->get($cacheKey);
        if (!is_array($bucket) || ($bucket['reset_at'] ?? 0) <= $now) {
            $bucket = ['count' => 0, 'reset_at' => $now + $window];
        }
        $remaining = max(1, $bucket['reset_at'] - $now);
        if ($bucket['count'] >= $limit) {
            return $this->rateLimited($remaining);
        }
        $bucket['count']++;
        $cache->save($cacheKey, $bucket, $remaining);
        return null;
    }

    private function rateLimited(int $seconds): ResponseInterface
    {
        return service('response')->setStatusCode(429)
            ->setHeader('Retry-After', (string) $seconds)
            ->setJSON([
                'status' => 'error',
                'message' => 'Terlalu banyak permintaan. Silakan tunggu beberapa detik.',
                'retry_after' => $seconds,
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}

<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class LoginRateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Rate limiting only applies to POST /login attempts
        if (strcasecmp($request->getMethod(), 'post') !== 0) {
            return;
        }

        $ip = $request->getIPAddress();
        $key = "login_attempts_ip:{$ip}";
        
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $currentAttempts = (int)$redis->incr($key);
                if ($currentAttempts === 1) {
                    $redis->expire($key, 900); // 15-minute window
                }
                
                if ($currentAttempts > 20) { // 20 attempts per window
                    return redirect()->back()
                        ->withInput()
                        ->with('error', 'Terlalu banyak percobaan login dari koneksi Anda. Silakan coba lagi dalam 15 menit.');
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'LoginRateLimitFilter Redis error: ' . $e->getMessage());
            // Fallback: use in-memory file-based rate limiting when Redis is down
            $ip = $request->getIPAddress();
            $cacheKey = 'login_fallback_' . md5($ip);
            $cache = service('cache');
            $attempts = (int)$cache->get($cacheKey);
            $cache->save($cacheKey, $attempts + 1, 900);
            if ($attempts > 20) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Terlalu banyak percobaan login. Silakan coba lagi dalam 15 menit.');
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}

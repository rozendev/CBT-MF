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
                $attempts = (int)$redis->get($key);
                if ($attempts >= 20) { // 20 attempts per window
                    return redirect()->back()
                        ->withInput()
                        ->with('error', 'Terlalu banyak percobaan login dari koneksi Anda. Silakan coba lagi dalam 15 menit.');
                }
                
                $redis->multi();
                $redis->incr($key);
                $redis->expire($key, 900); // 15-minute window
                $redis->exec();
            }
        } catch (\Exception $e) {
            log_message('error', 'LoginRateLimitFilter Redis error: ' . $e->getMessage());
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}

<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class MultiLoginFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (!$session->get('logged_in')) {
            return;
        }

        // Check if multi-login detection is enabled
        $db = \Config\Database::connect();
        $setting = $db->table('settings')
                      ->where('key', 'enable_multi_login')
                      ->get()
                      ->getRow();

        // If multi-login is allowed, skip check
        if ($setting && $setting->value === '1') {
            return;
        }

        $userId    = $session->get('user_id');
        $sessionId = session_id();

        // Use Redis to track active sessions per user
        try {
            $redis = new \Redis();
            $redis->connect('redis', 6379);

            $key = "user_session:{$userId}";
            $storedSessionId = $redis->get($key);

            if ($storedSessionId && $storedSessionId !== $sessionId) {
                // Another session is active — destroy this one
                $session->destroy();
                return redirect()->to('/login')
                    ->with('error', 'Akun ini terdeteksi login di perangkat lain. Sesi Anda telah diakhiri.');
            }

            // Store/refresh current session mapping (TTL = session expiration)
            $redis->setex($key, 7200, $sessionId);
        } catch (\Exception $e) {
            // If Redis fails, log and continue (don't block user)
            log_message('error', 'MultiLoginFilter Redis error: ' . $e->getMessage());
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}

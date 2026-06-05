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

        // Check if multi-login prevention is enabled
        $db = \Config\Database::connect();
        $setting = $db->table('settings')
                      ->where('key', 'prevent_multi_login')
                      ->get()
                      ->getRow();

        // If prevent multi-login is NOT enabled, skip check
        if (!$setting || $setting->value === '0') {
            return;
        }

        $userId = $session->get('user_id');
        $currentToken = $session->get('login_token');

        if (!$currentToken) {
            return; // Legacy session or not fully logged in yet
        }

        // Use Redis to track active login tokens per user
        try {
            $redis = new \Redis();
            $redis->connect('redis', 6379);

            $key = "user_login_token:{$userId}";
            $storedToken = $redis->get($key);

            if ($storedToken && $storedToken !== $currentToken) {
                $session->destroy();
                
                $message = 'Akun ini telah digunakan untuk login di perangkat atau browser lain. Sesi Anda diakhiri demi keamanan.';
                if ($storedToken === 'BANNED') {
                    $message = 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.';
                }
                
                return redirect()->to('/login')->with('error', $message);
            }
            
            // Keep the TTL alive for active sessions
            $redis->expire($key, 7200);

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

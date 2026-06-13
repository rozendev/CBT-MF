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

        // Allow Admin and Guru to have multiple active sessions and bypass maintenance
        $role = $session->get('role');
        if (in_array($role, ['admin', 'guru'])) {
            return;
        }

        // Maintenance mode: block siswa access
        try {
            $settingModel = new \App\Models\SettingModel();
            if ($settingModel->getValue('maintenance_mode', false)) {
                return redirect()->to('/maintenance');
            }
        } catch (\Exception $e) {
            // DB unreachable, don't block
        }

        // Check if multi-login prevention is enabled (cached for 5 mins)
        try {
            $isEnabled = service('cache')->get('setting_prevent_multi_login');
            if ($isEnabled === null) {
                $db = \Config\Database::connect();
                $setting = $db->table('settings')
                              ->where('key', 'prevent_multi_login')
                              ->get()
                              ->getRow();
                $isEnabled = $setting ? $setting->value : '0';
                service('cache')->save('setting_prevent_multi_login', $isEnabled, 300);
            }
        } catch (\Exception $e) {
            // Fallback if cache driver fails
            $db = \Config\Database::connect();
            $setting = $db->table('settings')->where('key', 'prevent_multi_login')->get()->getRow();
            $isEnabled = $setting ? $setting->value : '0';
        }

        // If prevent multi-login is NOT enabled, skip check
        if ($isEnabled === '0') {
            return;
        }

        $userId = $session->get('user_id');
        $currentToken = $session->get('login_token');

        if (!$currentToken) {
            return; // Legacy session or not fully logged in yet
        }

        // Use Redis to track active login tokens per user
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
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
            }
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

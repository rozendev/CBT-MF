<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (!$session->get('logged_in')) {
            if ($request->isAJAX() || strpos($request->getPath(), 'api/') === 0) {
                return service('response')->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Silakan login terlebih dahulu.']);
            }
            
            // Store intended URL for post-login redirect
            $session->set('redirect_url', current_url());

            return redirect()->to('/login')
                ->with('error', 'Silakan login terlebih dahulu.');
        }

        // Check if user account is still active
        if (!$session->get('is_active')) {
            $session->destroy();
            if ($request->isAJAX() || strpos($request->getPath(), 'api/') === 0) {
                return service('response')->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Akun Anda telah dinonaktifkan.']);
            }
            return redirect()->to('/login')
                ->with('error', 'Akun Anda telah dinonaktifkan.');
        }

        // Write-Through Active Session Tracking in Redis
        $userId = $session->get('user_id');
        if ($userId) {
            try {
                $redis = new \Redis();
                if ($redis->connect('redis', 6379)) {
                    // Update this user's last activity timestamp
                    $redis->zAdd('active_sessions', time(), $userId);
                    
                    // Cleanup inactive sessions (idle > 5 minutes / 300 seconds)
                    // This is extremely fast in Redis
                    $redis->zRemRangeByScore('active_sessions', 0, time() - 300);
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error in AuthFilter: ' . $e->getMessage());
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}

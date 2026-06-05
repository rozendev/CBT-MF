<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (!$session->get('logged_in')) {
            return redirect()->to('/login')
                ->with('error', 'Silakan login terlebih dahulu.');
        }

        // If no role arguments specified, allow any authenticated user
        if (empty($arguments)) {
            $this->updateRedisActivity($session);
            return;
        }

        $userRole = $session->get('role');

        // Check if user's role is in the allowed list
        if (!in_array($userRole, $arguments, true)) {
            // Return 403 Forbidden
            return service('response')
                ->setStatusCode(403)
                ->setBody(view('errors/html/error_403', [
                    'message' => 'Anda tidak memiliki akses ke halaman ini.',
                ]));
        }

        $this->updateRedisActivity($session);
    }

    private function updateRedisActivity($session)
    {
        $userId = $session->get('user_id');
        if ($userId) {
            try {
                $redis = new \Redis();
                if ($redis->connect('redis', 6379)) {
                    // Update this user's last activity timestamp
                    $redis->zAdd('active_sessions', time(), $userId);
                    
                    // Cleanup inactive sessions (idle > 5 minutes / 300 seconds)
                    $redis->zRemRangeByScore('active_sessions', 0, time() - 300);
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error in RoleFilter: ' . $e->getMessage());
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}

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
            // Store intended URL for post-login redirect
            $session->set('redirect_url', current_url());

            return redirect()->to('/login')
                ->with('error', 'Silakan login terlebih dahulu.');
        }

        // Check if user account is still active
        if (!$session->get('is_active')) {
            $session->destroy();
            return redirect()->to('/login')
                ->with('error', 'Akun Anda telah dinonaktifkan.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}

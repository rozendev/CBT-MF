<?php

namespace App\Filters;

use App\Libraries\LoginThrottle;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class LoginRateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Rate limiting hanya berlaku untuk POST /login.
        if (strcasecmp($request->getMethod(), 'post') !== 0) {
            return;
        }

        $ip = $request->getIPAddress();

        try {
            $max   = LoginThrottle::maxAttempts();
            $count = LoginThrottle::hit($ip);

            // Redis tak tersedia (getInstance null) → hit() null → lolos (fail-open).
            if ($count !== null && $count > $max) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Terlalu banyak percobaan login dari koneksi Anda. Silakan coba lagi dalam 15 menit.');
            }
        } catch (\Throwable $e) {
            // Keputusan A — FAIL-OPEN: satu blip/beku Redis tak boleh melumpuhkan
            // login. Lockout per-akun (DB) tetap menahan brute force tanpa Redis,
            // dan Cloudflare menahan flood CPU di edge. Cukup catat peringatan.
            log_message('error', 'LoginRateLimitFilter fail-open (Redis error): ' . $e->getMessage());
            return;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}

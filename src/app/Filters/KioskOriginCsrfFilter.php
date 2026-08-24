<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class KioskOriginCsrfFilter implements FilterInterface
{
    /**
     * Hanya request dengan Origin persis milik kiosk WebView yang bebas CSRF.
     * Semua request lain (web form, curl, origin asing) tetap wajib token CSRF.
     */
    private function isKioskOrigin(string $origin): bool
    {
        $kioskOrigin = env('KIOSK_CORS_ORIGIN', 'https://appassets.androidplatform.net');
        return $origin !== '' && $origin === $kioskOrigin;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');

        if ($this->isKioskOrigin($origin)) {
            return null; // trusted kiosk origin: skip CSRF, CORS allowlist menjaga
        }

        // Jalur web (Windows) tetap wajib CSRF — pola persis Filter\CSRF bawaan CI4:
        try {
            service('security')->verify($request);
        } catch (\CodeIgniter\Security\Exceptions\SecurityException $e) {
            $security = service('security');
            if ($security->shouldRedirect() && !$request->isAJAX()) {
                return redirect()->back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
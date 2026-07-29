<?php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CorsApiFilter implements FilterInterface
{
    /**
     * Get the list of allowed origins from environment configuration.
     *
     * @return array<string>
     */
    private function getAllowedOrigins(): array
    {
        $raw = env('CORS_ALLOWED_ORIGINS', rtrim(config('App')->baseURL, '/'));
        return array_map('trim', explode(',', $raw));
    }

    /**
     * Check if the given origin is in the allowed list.
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }
        return in_array($origin, $this->getAllowedOrigins(), true);
    }

    /**
     * Set CORS headers on the response for the given origin.
     */
    private function setCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        return $response
            ->setHeader('Access-Control-Allow-Origin', $origin)
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, X-CSRF-TOKEN, Authorization')
            ->setHeader('Access-Control-Allow-Credentials', 'true')
            ->setHeader('Vary', 'Origin');
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');

        // Handle preflight OPTIONS requests
        if ($request->getMethod() === 'OPTIONS') {
            $response = service('response');
            if ($this->isOriginAllowed($origin)) {
                $this->setCorsHeaders($response, $origin);
            }
            $response->setHeader('Access-Control-Max-Age', '86400')
                     ->setStatusCode(200);
            return $response;
        }

        // Strict Origin/Referer validation for state-changing requests (CSRF mitigation)
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $referer = $request->getHeaderLine('Referer');
            $baseURL = rtrim(config('App')->baseURL, '/');
            
            $isValid = false;
            if (!empty($origin)) {
                $isValid = $this->isOriginAllowed($origin);
            } elseif (!empty($referer)) {
                $isValid = str_starts_with($referer, $baseURL);
            } else {
                // Browsers send Origin for cross-origin POST. If both missing, assume safe client/same-origin.
                $isValid = true;
            }

            if (!$isValid) {
                return service('response')->setStatusCode(403)->setJSON([
                    'status' => 'error',
                    'message' => 'CSRF detected via Origin/Referer mismatch.'
                ]);
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');

        if ($this->isOriginAllowed($origin)) {
            $this->setCorsHeaders($response, $origin);
        }

        return $response;
    }
}

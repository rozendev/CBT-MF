<?php

namespace App\Libraries;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;

final class WebSocketOriginGuard implements HttpServerInterface
{
    private array $allowedOrigins = [];
    private \SplObjectStorage $accepted;

    public function __construct(
        private HttpServerInterface $delegate,
        string $baseUrl,
        string $kioskOrigin = 'https://appassets.androidplatform.net',
        array $additionalOrigins = [],
    ) {
        $this->accepted = new \SplObjectStorage();
        $baseOrigin = self::origin($baseUrl, true);
        if ($baseOrigin === null) {
            throw new \InvalidArgumentException('Invalid WebSocket base URL configuration');
        }
        $this->allowedOrigins[$baseOrigin] = true;
        foreach (array_merge([$kioskOrigin], $additionalOrigins) as $value) {
            $value = trim((string) $value);
            if ($value === '') continue;
            $origin = self::origin($value);
            if ($origin === null) {
                throw new \InvalidArgumentException('Invalid WebSocket Origin configuration');
            }
            $this->allowedOrigins[$origin] = true;
        }
    }

    private static function origin(string $value, bool $baseUrl = false): ?string
    {
        if ($value === '' || preg_match('/[\s,]/', $value)) return null;
        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;
        if (!$baseUrl && isset($parts['path']) && $parts['path'] !== '') return null;
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
        $ipv6 = str_starts_with($host, '[') && str_ends_with($host, ']');
        if ($ipv6 ? !filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            : !preg_match('/\A[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\.?\z/D', $host)) return null;
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) return null;
        return $scheme . '://' . $host . ':' . $port;
    }

    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null)
    {
        $origin = $request !== null && count($request->getHeader('Origin')) === 1
            ? self::origin($request->getHeaderLine('Origin')) : null;
        $sameOrigin = null;
        if ($request !== null && count($request->getHeader('Host')) === 1) {
            // Only the internal Nginx upstream may set this header. Nginx overwrites
            // client input with $scheme; TLS edge origins use the configured allowlist.
            $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: $request->getUri()->getScheme();
            $sameOrigin = self::origin($scheme . '://' . $request->getHeaderLine('Host'));
        }
        if ($origin === null || ($origin !== $sameOrigin && !isset($this->allowedOrigins[$origin]))) {
            $conn->send(Message::toString(new Response(403, ['Content-Length' => '0'])));
            $conn->close();
            return;
        }
        $this->accepted->attach($conn);
        try {
            $this->delegate->onOpen($conn, $request);
        } catch (\Throwable $e) {
            $this->accepted->detach($conn);
            try {
                $this->delegate->onClose($conn);
            } finally {
                $conn->close();
            }
            throw $e;
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        if ($this->accepted->contains($from)) $this->delegate->onMessage($from, $msg);
    }

    public function onClose(ConnectionInterface $conn)
    {
        if ($this->accepted->contains($conn)) {
            $this->accepted->detach($conn);
            $this->delegate->onClose($conn);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        if ($this->accepted->contains($conn)) $this->delegate->onError($conn, $e);
        else $conn->close();
    }
}

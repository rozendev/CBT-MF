<?php

namespace App\Libraries;

use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Ratchet\WebSocket\WsServer;

final class BoundedWsServer extends WsServer
{
    public const MAX_INCOMING_BYTES = 65536;

    public function __construct(private MessageComponentInterface $application)
    {
        parent::__construct($application);
    }

    public function onOpen(ConnectionInterface $conn, RequestInterface $request = null)
    {
        parent::onOpen($conn, $request);
        if (!$this->connections->contains($conn) || $conn->WebSocket->closing) {
            return;
        }

        // No incoming data is dispatched by the event loop until onOpen returns.
        // Retain Ratchet's context/lifecycle; replace only its unused parser buffer.
        $context = $this->connections[$conn];
        $ws = $context->connection;
        $context->buffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (MessageInterface $message) use ($ws): void {
                $this->application->onMessage($ws, $message->getPayload());
            },
            function (FrameInterface $frame) use ($ws): void {
                $this->onControlFrame($frame, $ws);
            },
            true,
            null,
            self::MAX_INCOMING_BYTES,
            self::MAX_INCOMING_BYTES,
        );
    }
}

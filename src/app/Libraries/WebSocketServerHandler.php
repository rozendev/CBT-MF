<?php

namespace App\Libraries;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServerHandler implements MessageComponentInterface
{
    protected $clients;
    protected $userConnections;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->userConnections = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        
        // Parse query string from the URI to get user_id and attempt_id
        $querystring = $conn->httpRequest->getUri()->getQuery();
        parse_str($querystring, $query);

        if (isset($query['user_id']) && isset($query['attempt_id'])) {
            $userId = (int) $query['user_id'];
            $attemptId = (int) $query['attempt_id'];
            
            $conn->userId = $userId;
            $conn->attemptId = $attemptId;
            
            if (!isset($this->userConnections[$userId])) {
                $this->userConnections[$userId] = [];
            }
            $this->userConnections[$userId][] = $conn;
            
            // Send connected event
            $conn->send(json_encode([
                'event' => 'connected',
                'data' => [
                    'message' => 'WebSocket connected',
                    'attempt_id' => $attemptId
                ]
            ]));
        } else {
            // Reject connection
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        // We don't process incoming messages from the client in this daemon.
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        
        if (isset($conn->userId)) {
            $userId = $conn->userId;
            if (isset($this->userConnections[$userId])) {
                foreach ($this->userConnections[$userId] as $key => $userConn) {
                    if ($userConn === $conn) {
                        unset($this->userConnections[$userId][$key]);
                    }
                }
                // Reindex array
                $this->userConnections[$userId] = array_values($this->userConnections[$userId]);
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        log_message('error', 'WebSocket Error: ' . $e->getMessage());
        $conn->close();
    }

    public function broadcastEvent(array $eventData)
    {
        $userId = $eventData['user_id'] ?? null;
        $attemptId = $eventData['attempt_id'] ?? null;
        $event = $eventData['event'] ?? 'unknown';
        
        $message = json_encode([
            'event' => $event,
            'data' => $eventData
        ]);

        if ($userId) {
            // Target specific user
            if (isset($this->userConnections[$userId])) {
                foreach ($this->userConnections[$userId] as $conn) {
                    $conn->send($message);
                    if ($event === 'ban' || $event === 'kick' || $event === 'finished') {
                        $conn->close();
                    }
                }
            }
        } elseif ($attemptId) {
            // Target specific attempt
            foreach ($this->clients as $conn) {
                if (isset($conn->attemptId) && $conn->attemptId == $attemptId) {
                    $conn->send($message);
                    if ($event === 'ban' || $event === 'kick' || $event === 'finished') {
                        $conn->close();
                    }
                }
            }
        } else {
            // Broadcast to all (e.g. for sync_mode or extend_time)
            foreach ($this->clients as $conn) {
                $conn->send($message);
            }
        }
    }

    public function broadcastHeartbeat()
    {
        $message = json_encode([
            'event' => 'heartbeat',
            'data' => [
                'time' => date('H:i:s')
            ]
        ]);
        
        foreach ($this->clients as $conn) {
            $conn->send($message);
        }
    }
}

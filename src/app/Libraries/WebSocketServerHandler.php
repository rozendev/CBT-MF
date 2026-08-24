<?php

namespace App\Libraries;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServerHandler implements MessageComponentInterface
{
    protected $clients;
    protected $userConnections;
    protected $proctorRooms;

    protected $asyncRedis;

    public function __construct($asyncRedis = null)
    {
        $this->asyncRedis = $asyncRedis;
        $this->clients = new \SplObjectStorage;
        $this->userConnections = [];
        $this->proctorRooms = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        $conn->lastPong = time();
        
        // Parse query string from the URI to get user_id and attempt_id
        $querystring = $conn->httpRequest->getUri()->getQuery();
        parse_str($querystring, $query);

        if (isset($query['proctor_token'])) {
            if ($this->asyncRedis) {
                $this->asyncRedis->get("ws_proctor_token:{$query['proctor_token']}")->then(function ($tokenData) use ($conn) {
                    if ($tokenData) {
                        $tokenData = json_decode($tokenData, true);
                        $testId = $tokenData['test_id'] ?? 0;
                        
                        $conn->role = 'proctor';
                        $conn->testId = $testId;
                        
                        if (!isset($this->proctorRooms[$testId])) {
                            $this->proctorRooms[$testId] = [];
                        }
                        $this->proctorRooms[$testId][] = $conn;
                        
                        $conn->send(json_encode([
                            'event' => 'connected',
                            'data' => ['message' => 'Proctor WebSocket connected to test ' . $testId]
                        ]));
                    } else {
                        // Invalid token
                        $conn->close();
                    }
                })->catch(function (\Exception $e) use ($conn) {
                    log_message('error', 'Async Redis error: ' . $e->getMessage());
                    $conn->close();
                });
            } else {
                $conn->close();
            }
            return;
        } elseif (isset($query['ws_token'])) {
            if ($this->asyncRedis) {
                $this->asyncRedis->get("ws_student_token:{$query['ws_token']}")->then(function ($tokenData) use ($conn) {
                    if ($tokenData) {
                        $tokenData = json_decode($tokenData, true);
                        $userId = (int)($tokenData['user_id'] ?? 0);
                        $attemptId = (int)($tokenData['attempt_id'] ?? 0);
                        $testId = (int)($tokenData['test_id'] ?? 0);

                        if ($userId && $attemptId && $testId) {
                            $conn->userId = $userId;
                            $conn->attemptId = $attemptId;
                            $conn->testId = $testId;
                            
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

                            // Notify proctors that a student connected
                            $this->broadcastToProctors([
                                'event' => 'student_connected',
                                'user_id' => $userId,
                                'attempt_id' => $attemptId,
                                'test_id' => $testId
                            ], $testId);
                        } else {
                            $conn->close();
                        }
                    } else {
                        // Invalid token
                        $conn->close();
                    }
                })->catch(function (\Exception $e) use ($conn) {
                    log_message('error', 'Async Redis error: ' . $e->getMessage());
                    $conn->close();
                });
            } else {
                $conn->close();
            }
            return;
        } else {
            // Reject connection
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = @json_decode($msg, true);
        if (!$data) {
            return;
        }

        if (($data['event'] ?? '') === 'pong') {
            $from->lastPong = time();
        }

        if (isset($data['action']) && ($data['action'] === 'kiosk_event' || $data['action'] === 'kiosk_status')) {
            $db = \Config\Database::connect();
            $eventType = $data['type'] ?? ($data['action'] === 'kiosk_status' ? ($data['status'] ?? 'status_change') : 'unknown');
            $eventDetails = isset($data['data']) ? json_encode($data['data']) : null;

            $db->table('exam_kiosk_events')->insert([
                'exam_session_id' => $from->testId ?? 0,
                'student_id'      => $from->userId ?? 0,
                'event_type'      => $eventType,
                'event_details'   => $eventDetails,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            // Broadcast event to proctors monitoring this exam session
            $testId = $from->testId ?? null;
            $this->broadcastToProctors([
                'event'      => 'kiosk_event',
                'action'     => $data['action'],
                'user_id'    => $from->userId ?? 0,
                'attempt_id' => $from->attemptId ?? 0,
                'test_id'    => $testId,
                'type'       => $eventType,
                'data'       => $data['data'] ?? [],
                'timestamp'  => date('Y-m-d H:i:s'),
            ], $testId);
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);
        
        if (isset($conn->role) && $conn->role === 'proctor') {
            $testId = $conn->testId ?? 0;
            if (isset($this->proctorRooms[$testId])) {
                foreach ($this->proctorRooms[$testId] as $key => $proctorConn) {
                    if ($proctorConn === $conn) {
                        unset($this->proctorRooms[$testId][$key]);
                    }
                }
                if (empty($this->proctorRooms[$testId])) {
                    unset($this->proctorRooms[$testId]);
                } else {
                    $this->proctorRooms[$testId] = array_values($this->proctorRooms[$testId]);
                }
            }
        } elseif (isset($conn->userId)) {
            $userId = $conn->userId;
            $attemptId = $conn->attemptId ?? null;
            if (isset($this->userConnections[$userId])) {
                foreach ($this->userConnections[$userId] as $key => $userConn) {
                    if ($userConn === $conn) {
                        unset($this->userConnections[$userId][$key]);
                    }
                }
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                } else {
                    // Reindex array
                    $this->userConnections[$userId] = array_values($this->userConnections[$userId]);
                }
            }

            // Notify proctors that a student disconnected
            $testId = $conn->testId ?? 0;
            if ($testId > 0) {
                $this->broadcastToProctors([
                    'event' => 'student_disconnected',
                    'user_id' => $userId,
                    'attempt_id' => $attemptId,
                    'test_id' => $testId
                ], $testId);
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
                }
            }
        } elseif ($attemptId) {
            // Target specific attempt
            foreach ($this->clients as $conn) {
                if (isset($conn->attemptId) && $conn->attemptId == $attemptId) {
                    $conn->send($message);
                }
            }
        } else {
            // Broadcast to all (e.g. for sync_mode or extend_time)
            foreach ($this->clients as $conn) {
                $conn->send($message);
            }
        }

        // Always broadcast exam events to proctors too!
        if (!in_array($event, ['connected', 'heartbeat', 'student_connected', 'student_disconnected'])) {
            $tId = $eventData['test_id'] ?? null;
            $this->broadcastToProctors($eventData, $tId);
        }
    }

    public function broadcastToProctors(array $eventData, $testId = null)
    {
        $message = json_encode([
            'event' => 'proctor_alert',
            'data' => $eventData
        ]);

        if ($testId && isset($this->proctorRooms[$testId])) {
            // Broadcast only to proctors monitoring this test
            foreach ($this->proctorRooms[$testId] as $conn) {
                $conn->send($message);
            }
        } elseif (!$testId) {
            // Broadcast to all proctors (fallback if testId is missing)
            foreach ($this->proctorRooms as $room) {
                foreach ($room as $conn) {
                    $conn->send($message);
                }
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

    public function pruneStaleConnections()
    {
        $now = time();
        $staleThreshold = 90; // 3x heartbeat interval (30s)
        foreach ($this->clients as $conn) {
            if (($now - ($conn->lastPong ?? 0)) > $staleThreshold) {
                $conn->close();
            }
        }
    }
}

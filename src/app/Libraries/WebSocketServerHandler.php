<?php

namespace App\Libraries;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class WebSocketServerHandler implements MessageComponentInterface
{
    /** @var \SplObjectStorage */
    protected $clients;

    /** @var array<int, list<ConnectionInterface>> */
    protected $userConnections;

    /** @var array<int, list<ConnectionInterface>> */
    protected $testConnections;

    /** @var array<int, list<ConnectionInterface>> */
    protected $proctorRooms;

    /** @var array<int, list<ConnectionInterface>> */
    protected $proctorUserConnections;

    protected $asyncRedis;

    /** @var callable():int */
    private $clock;

    public function __construct($asyncRedis = null, ?callable $clock = null)
    {
        $this->asyncRedis             = $asyncRedis;
        $this->clock                  = $clock ?? static fn (): int => time();
        $this->clients                = new \SplObjectStorage();
        $this->userConnections        = [];
        $this->testConnections        = [];
        $this->proctorRooms           = [];
        $this->proctorUserConnections = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $conn->closed   = false;
        $conn->lastPong = $this->now();
        $this->clients->attach($conn);

        $querystring = isset($conn->httpRequest) ? $conn->httpRequest->getUri()->getQuery() : '';
        parse_str($querystring, $query);

        if (isset($query['proctor_token'])) {
            $token = is_string($query['proctor_token']) ? trim($query['proctor_token']) : '';
            if ($token === '' || !$this->asyncRedis) {
                $this->closeAndDetach($conn);
                return;
            }

            $this->asyncRedis->get(WebSocketToken::proctorTokenKey($token))->then(function ($tokenData) use ($conn) {
                if (!$this->isOpenConnection($conn)) {
                    return;
                }

                $decoded = is_string($tokenData) && $tokenData !== '' ? json_decode($tokenData, true) : null;
                $testId  = is_array($decoded) ? (int) ($decoded['test_id'] ?? 0) : 0;
                $userId  = is_array($decoded) ? (int) ($decoded['user_id'] ?? 0) : 0;

                if (!is_array($decoded) || $testId <= 0) {
                    $this->closeAndDetach($conn);
                    return;
                }

                $registerProctor = function () use ($conn, $testId, $userId): void {
                    if (!$this->isOpenConnection($conn)) {
                        return;
                    }

                    $conn->role   = 'proctor';
                    $conn->testId = $testId;
                    $conn->userId = $userId;

                    $this->proctorRooms[$testId] ??= [];
                    $this->proctorRooms[$testId][] = $conn;

                    if ($userId > 0) {
                        $this->proctorUserConnections[$userId] ??= [];
                        $this->proctorUserConnections[$userId][] = $conn;
                    }

                    $conn->send(json_encode([
                        'event' => 'connected',
                        'data'  => ['message' => 'Proctor WebSocket connected to test ' . $testId],
                    ]));
                };

                if ($userId > 0) {
                    return $this->asyncRedis->get(SessionInvalidation::stateKey($userId))->then(
                        function ($rawState) use ($conn, $decoded, $registerProctor): void {
                            if (!$this->isOpenConnection($conn)) {
                                return;
                            }
                            $authState = WebSocketToken::parseAuthStateJson($rawState);
                            if (!WebSocketToken::isTokenAllowedByAuthState($decoded, $authState)) {
                                $this->closeAndDetach($conn);
                                return;
                            }
                            $registerProctor();
                        }
                    );
                }

                $registerProctor();
            })->catch(function (\Throwable $e) use ($conn): void {
                log_message('error', 'Async Redis error on proctor handshake (' . get_class($e) . ')');
                $this->closeAndDetach($conn);
            });

            return;
        }

        if (isset($query['ws_token'])) {
            $token = is_string($query['ws_token']) ? trim($query['ws_token']) : '';
            if ($token === '' || !$this->asyncRedis) {
                $this->closeAndDetach($conn);
                return;
            }

            $this->asyncRedis->get(WebSocketToken::studentTokenKey($token))->then(function ($tokenData) use ($conn) {
                if (!$this->isOpenConnection($conn)) {
                    return;
                }

                $decoded   = is_string($tokenData) && $tokenData !== '' ? json_decode($tokenData, true) : null;
                $userId    = is_array($decoded) ? (int) ($decoded['user_id'] ?? 0) : 0;
                $attemptId = is_array($decoded) ? (int) ($decoded['attempt_id'] ?? 0) : 0;
                $testId    = is_array($decoded) ? (int) ($decoded['test_id'] ?? 0) : 0;

                if (!is_array($decoded) || $userId <= 0 || $attemptId <= 0 || $testId <= 0) {
                    $this->closeAndDetach($conn);
                    return;
                }

                return $this->asyncRedis->get(SessionInvalidation::stateKey($userId))->then(
                    function ($rawState) use ($conn, $decoded, $userId, $attemptId, $testId): void {
                        if (!$this->isOpenConnection($conn)) {
                            return;
                        }

                        $authState = WebSocketToken::parseAuthStateJson($rawState);
                        if (!WebSocketToken::isTokenAllowedByAuthState($decoded, $authState)) {
                            $this->closeAndDetach($conn);
                            return;
                        }

                        $conn->role      = 'student';
                        $conn->userId    = $userId;
                        $conn->attemptId = $attemptId;
                        $conn->testId    = $testId;

                        $this->userConnections[$userId] ??= [];
                        $this->userConnections[$userId][] = $conn;

                        $this->testConnections[$testId] ??= [];
                        $this->testConnections[$testId][] = $conn;

                        $conn->send(json_encode([
                            'event' => 'connected',
                            'data'  => [
                                'message'    => 'WebSocket connected',
                                'attempt_id' => $attemptId,
                            ],
                        ]));

                        $this->broadcastToProctors([
                            'event'      => 'student_connected',
                            'user_id'    => $userId,
                            'attempt_id' => $attemptId,
                            'test_id'    => $testId,
                        ], $testId);
                    }
                );
            })->catch(function (\Throwable $e) use ($conn): void {
                log_message('error', 'Async Redis error on student handshake (' . get_class($e) . ')');
                $this->closeAndDetach($conn);
            });

            return;
        }

        $this->closeAndDetach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $raw = (string) $msg;
        if ($raw === '' || strlen($raw) > KioskEventQueue::MAX_INCOMING_BYTES) {
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        $now = $this->now();

        if (($data['event'] ?? '') === 'pong') {
            $from->lastPong = $now;
            return;
        }

        if (isset($data['action']) && ($data['action'] === 'kiosk_event' || $data['action'] === 'kiosk_status')) {
            if (($from->role ?? '') !== 'student' || empty($from->userId) || empty($from->testId)) {
                return;
            }

            $windowStart = (int) ($from->kioskRateWindowStart ?? 0);
            $rateCount   = (int) ($from->kioskRateCount ?? 0);
            if (($now - $windowStart) >= KioskEventQueue::RATE_WINDOW_SECONDS) {
                $windowStart = $now;
                $rateCount   = 0;
            }
            if ($rateCount >= KioskEventQueue::RATE_MAX_EVENTS) {
                return;
            }

            $testId  = (int) $from->testId;
            $userId  = (int) $from->userId;
            $record  = KioskEventQueue::prepareIncomingEvent($data, $testId, $userId, $now);
            if ($record === null) {
                return;
            }

            $from->kioskRateWindowStart = $windowStart;
            $from->kioskRateCount       = $rateCount + 1;

            if ($this->asyncRedis) {
                $encodedRecord = json_encode($record, JSON_UNESCAPED_UNICODE);
                if ($encodedRecord !== false) {
                    $this->asyncRedis->rpush(KioskEventQueue::QUEUE_KEY, $encodedRecord)
                        ->then(function () {
                            return $this->asyncRedis->ltrim(
                                KioskEventQueue::QUEUE_KEY,
                                -KioskEventQueue::QUEUE_MAX_LEN,
                                -1
                            );
                        })
                        ->then(function () {
                            return $this->asyncRedis->expire(
                                KioskEventQueue::QUEUE_KEY,
                                KioskEventQueue::QUEUE_TTL_SECONDS
                            );
                        })
                        ->catch(function (\Throwable $e): void {
                            log_message('error', 'Async Redis kiosk_event_queue error (' . get_class($e) . ')');
                        });
                }
            }

            $this->broadcastToProctors([
                'event'      => 'kiosk_event',
                'action'     => $data['action'],
                'user_id'    => $userId,
                'attempt_id' => (int) ($from->attemptId ?? 0),
                'test_id'    => $testId,
                'type'       => $record['event_type'],
                'data'       => $data['data'] ?? [],
                'timestamp'  => $record['created_at'],
            ], $testId);
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->detachConnection($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        log_message('error', 'WebSocket Error (' . get_class($e) . ')');
        $this->closeAndDetach($conn);
    }

    public function broadcastEvent(array $eventData)
    {
        $event = isset($eventData['event']) && is_string($eventData['event']) ? $eventData['event'] : 'unknown';

        // 1) Event internal khusus pengawas: tidak boleh bocor ke soket siswa
        //    dan tidak boleh dibungkus ganda.
        if ($event === 'proctor_alert' || $event === 'proctor_report_alert') {
            $inner = (isset($eventData['data']) && is_array($eventData['data']))
                ? $eventData['data']
                : $eventData;

            if ($event === 'proctor_report_alert') {
                $inner['event'] = 'proctor_report_alert';
            }

            $tId = (int) ($eventData['test_id'] ?? ($inner['test_id'] ?? 0));
            $this->broadcastToProctors($inner, $tId > 0 ? $tId : null);
            return;
        }

        $userId    = isset($eventData['user_id']) ? (int) $eventData['user_id'] : 0;
        $attemptId = isset($eventData['attempt_id']) ? (int) $eventData['attempt_id'] : 0;
        $testId    = isset($eventData['test_id']) ? (int) $eventData['test_id'] : 0;

        $message = json_encode([
            'event' => $event,
            'data'  => $eventData,
        ]);

        $isTerminal = in_array($event, ['kick', 'ban', 'ejected'], true);

        // 2) Event tingkat pengguna atau attempt
        if ($userId > 0 || $attemptId > 0) {
            $targetStudents = [];
            if ($userId > 0) {
                $targetStudents = array_values($this->userConnections[$userId] ?? []);
            } else {
                foreach ($this->clients as $conn) {
                    if (($conn->role ?? '') === 'student' && (int) ($conn->attemptId ?? 0) === $attemptId) {
                        $targetStudents[] = $conn;
                    }
                }
            }

            $affectedTestIds = [];
            foreach ($targetStudents as $conn) {
                $connTestId = (int) ($conn->testId ?? 0);
                if ($connTestId > 0) {
                    $affectedTestIds[$connTestId] = $connTestId;
                }
                $conn->send($message);
                if ($isTerminal) {
                    $this->closeAndDetach($conn);
                }
            }

            // Bila akun pengawas/staf dicabut seketika (kick/ban), putus juga soket pengawas miliknya.
            if ($userId > 0 && ($event === 'kick' || $event === 'ban')) {
                $targetProctors = array_values($this->proctorUserConnections[$userId] ?? []);
                foreach ($targetProctors as $proctorConn) {
                    $proctorConn->send($message);
                    $this->closeAndDetach($proctorConn);
                }
            }

            if (!in_array($event, ['connected', 'heartbeat', 'student_connected', 'student_disconnected', 'cred_updated'], true)) {
                if ($testId > 0) {
                    $this->broadcastToProctors($eventData, $testId);
                } else {
                    foreach ($affectedTestIds as $inferredTestId) {
                        $this->broadcastToProctors($eventData, $inferredTestId);
                    }
                }
            }

            return;
        }

        // 3) Event tingkat ujian (sync_mode, extend_time): isolasi ketat ke test_id terkait
        if ($testId > 0) {
            $targets = array_values($this->testConnections[$testId] ?? []);
            foreach ($targets as $conn) {
                $conn->send($message);
            }

            if (!in_array($event, ['connected', 'heartbeat', 'student_connected', 'student_disconnected', 'cred_updated'], true)) {
                $this->broadcastToProctors($eventData, $testId);
            }

            return;
        }

        // 4) Event tanpa cakupan user_id / attempt_id / test_id tidak pernah disiarkan buta ke semua klien.
        log_message('warning', "WebSocketServerHandler: menjatuhkan event '{$event}' karena tidak memuat user_id, attempt_id, maupun test_id.");
    }

    public function broadcastToProctors(array $eventData, $testId = null)
    {
        $message = json_encode([
            'event' => 'proctor_alert',
            'data'  => $eventData,
        ]);

        $numericTestId = (int) ($testId ?? 0);

        if ($numericTestId > 0) {
            if (!isset($this->proctorRooms[$numericTestId])) {
                return;
            }
            foreach ($this->proctorRooms[$numericTestId] as $conn) {
                $conn->send($message);
            }
            return;
        }

        foreach ($this->proctorRooms as $room) {
            foreach ($room as $conn) {
                $conn->send($message);
            }
        }
    }

    public function broadcastHeartbeat()
    {
        $message = json_encode([
            'event' => 'heartbeat',
            'data'  => [
                'time' => date('H:i:s', $this->now()),
            ],
        ]);

        foreach ($this->clients as $conn) {
            $conn->send($message);
        }
    }

    public function pruneStaleConnections()
    {
        $now            = $this->now();
        $staleThreshold = 90; // 3x heartbeat interval (30s)
        $stale          = [];

        foreach ($this->clients as $conn) {
            if (($now - (int) ($conn->lastPong ?? 0)) > $staleThreshold) {
                $stale[] = $conn;
            }
        }

        foreach ($stale as $conn) {
            $this->closeAndDetach($conn);
        }
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function isOpenConnection(ConnectionInterface $conn): bool
    {
        return empty($conn->closed) && $this->clients->contains($conn);
    }

    private function closeAndDetach(ConnectionInterface $conn): void
    {
        $wasAlreadyClosed = !empty($conn->closed);
        $this->detachConnection($conn);
        if (!$wasAlreadyClosed) {
            $conn->close();
        }
    }

    private function detachConnection(ConnectionInterface $conn): void
    {
        $conn->closed = true;
        $this->clients->detach($conn);

        $role = $conn->role ?? '';
        if ($role === 'proctor') {
            $this->removeFromIndex($this->proctorRooms, (int) ($conn->testId ?? 0), $conn);
            $this->removeFromIndex($this->proctorUserConnections, (int) ($conn->userId ?? 0), $conn);
            return;
        }

        if ($role === 'student' || !empty($conn->userId)) {
            $userId    = (int) ($conn->userId ?? 0);
            $testId    = (int) ($conn->testId ?? 0);
            $attemptId = $conn->attemptId ?? null;

            $this->removeFromIndex($this->userConnections, $userId, $conn);
            $this->removeFromIndex($this->testConnections, $testId, $conn);

            if ($testId > 0 && empty($conn->disconnectedNotified)) {
                $conn->disconnectedNotified = true;
                $this->broadcastToProctors([
                    'event'      => 'student_disconnected',
                    'user_id'    => $userId,
                    'attempt_id' => $attemptId,
                    'test_id'    => $testId,
                ], $testId);
            }
        }
    }

    /**
     * @param array<int, list<ConnectionInterface>> $index
     */
    private function removeFromIndex(array &$index, int $key, ConnectionInterface $target): void
    {
        if ($key <= 0 || !isset($index[$key])) {
            return;
        }

        foreach ($index[$key] as $idx => $existing) {
            if ($existing === $target) {
                unset($index[$key][$idx]);
            }
        }

        if (empty($index[$key])) {
            unset($index[$key]);
        } else {
            $index[$key] = array_values($index[$key]);
        }
    }
}

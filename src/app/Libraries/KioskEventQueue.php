<?php

namespace App\Libraries;

/**
 * Validasi dan antrean Redis untuk catatan telemetri kiosk (`exam_kiosk_events`)
 * agar proses event loop WebSocket tidak pernah menjalankan kueri SQL sinkronus.
 *
 * Alur kerja:
 * 1. `WebSocketServerHandler::onMessage()` memvalidasi pesan lewat
 *    `prepareIncomingEvent()` (murni tanpa I/O), lalu mendorong JSON hasilnya
 *    secara async ke list Redis `kiosk_event_queue` (dibatasi `LTRIM` maks 5.000).
 * 2. `App\Commands\KioskPrune` (berjalan tiap 60 detik di container `cron`)
 *    memanggil `drainToDatabase()` untuk menulis secara batch (`insertBatch`)
 *    ke tabel `exam_kiosk_events` dengan 0 perubahan skema database.
 */
final class KioskEventQueue
{
    public const QUEUE_KEY         = 'kiosk_event_queue';
    public const QUEUE_MAX_LEN     = 5000;
    public const QUEUE_TTL_SECONDS = 7200;

    public const MAX_INCOMING_BYTES = 4096;
    public const MAX_DETAILS_BYTES  = 2048;

    public const RATE_WINDOW_SECONDS = 10;
    public const RATE_MAX_EVENTS     = 10;

    public const DRAIN_BATCH_SIZE = 500;

    /**
     * Validasi dan normalisasi payload pesan WebSocket masuk menjadi baris siap
     * simpan untuk tabel `exam_kiosk_events`. Mengembalikan `null` apabila
     * aksi tidak dikenal, identitas tidak sah, atau detail melebihi batas ukuran.
     *
     * @param array<string, mixed> $payload
     * @return array{exam_session_id:int, student_id:int, event_type:string, event_details:?string, created_at:string}|null
     */
    public static function prepareIncomingEvent(array $payload, int $testId, int $studentId, int $nowTs): ?array
    {
        if ($testId <= 0 || $studentId <= 0) {
            return null;
        }

        $action = isset($payload['action']) && is_string($payload['action']) ? $payload['action'] : '';
        if ($action !== 'kiosk_event' && $action !== 'kiosk_status') {
            return null;
        }

        if (isset($payload['type']) && is_scalar($payload['type']) && trim((string) $payload['type']) !== '') {
            $rawType = trim((string) $payload['type']);
        } elseif ($action === 'kiosk_status' && isset($payload['status']) && is_scalar($payload['status']) && trim((string) $payload['status']) !== '') {
            $rawType = trim((string) $payload['status']);
        } else {
            $rawType = $action === 'kiosk_status' ? 'status_change' : 'unknown';
        }

        $eventType = self::truncateUtf8($rawType, 50);

        $encodedDetails = null;
        if (array_key_exists('data', $payload) && $payload['data'] !== null) {
            $encoded = json_encode($payload['data'], JSON_UNESCAPED_UNICODE);
            if ($encoded === false || strlen($encoded) > self::MAX_DETAILS_BYTES) {
                return null;
            }
            $encodedDetails = $encoded;
        }

        return [
            'exam_session_id' => $testId,
            'student_id'      => $studentId,
            'event_type'      => $eventType,
            'event_details'   => $encodedDetails,
            'created_at'      => date('Y-m-d H:i:s', $nowTs),
        ];
    }

    /**
     * Kuras antrean `kiosk_event_queue` dari Redis ke tabel `exam_kiosk_events`
     * memakai `insertBatch()`. Kegagalan MariaDB dicatat ke log error tanpa
     * melempar exception agar siklus `kiosk:prune` tetap berjalan.
     */
    public static function drainToDatabase(
        ?object $redis = null,
        int $maxItems = self::DRAIN_BATCH_SIZE,
        ?callable $batchInserter = null,
        bool $resolveRedis = true
    ): int {
        if ($maxItems <= 0) {
            return 0;
        }

        $client = $redis ?? ($resolveRedis ? RedisClient::getInstance() : null);
        if ($client === null) {
            return 0;
        }

        $rows = [];
        try {
            for ($i = 0; $i < $maxItems; $i++) {
                $raw = $client->lPop(self::QUEUE_KEY);
                if (!is_string($raw) || $raw === '') {
                    break;
                }

                $normalized = self::normalizeQueuedJson($raw);
                if ($normalized !== null) {
                    $rows[] = $normalized;
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'KioskEventQueue::drainToDatabase gagal membaca antrean Redis: ' . $e->getMessage());
        }

        if ($rows === []) {
            return 0;
        }

        try {
            if ($batchInserter !== null) {
                $batchInserter($rows);
            } else {
                $db = \Config\Database::connect();
                $db->table('exam_kiosk_events')->insertBatch($rows);
            }
            return count($rows);
        } catch (\Throwable $e) {
            log_message('error', 'KioskEventQueue::drainToDatabase gagal insertBatch ke exam_kiosk_events: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return array{exam_session_id:int, student_id:int, event_type:string, event_details:?string, created_at:string}|null
     */
    private static function normalizeQueuedJson(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $sessionId = (int) ($decoded['exam_session_id'] ?? 0);
        $studentId = (int) ($decoded['student_id'] ?? 0);
        $eventType = isset($decoded['event_type']) && is_string($decoded['event_type'])
            ? self::truncateUtf8(trim($decoded['event_type']), 50)
            : '';
        $createdAt = isset($decoded['created_at']) && is_string($decoded['created_at'])
            ? trim($decoded['created_at'])
            : '';

        if ($sessionId <= 0 || $studentId <= 0 || $eventType === '' || $createdAt === '') {
            return null;
        }

        $details = null;
        if (array_key_exists('event_details', $decoded) && $decoded['event_details'] !== null) {
            if (!is_string($decoded['event_details']) || strlen($decoded['event_details']) > self::MAX_DETAILS_BYTES) {
                return null;
            }
            json_decode($decoded['event_details'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            $details = $decoded['event_details'];
        }

        return [
            'exam_session_id' => $sessionId,
            'student_id'      => $studentId,
            'event_type'      => $eventType,
            'event_details'   => $details,
            'created_at'      => $createdAt,
        ];
    }

    private static function truncateUtf8(string $value, int $maxChars): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxChars, 'UTF-8');
        }
        return substr($value, 0, $maxChars);
    }
}

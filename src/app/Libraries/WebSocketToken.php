<?php

namespace App\Libraries;

/**
 * Koordinasi penerbitan, rotasi, pencabutan, dan pemeriksaan versi autentikasi
 * untuk token WebSocket siswa (`ws_student_token:*`) maupun pengawas
 * (`ws_proctor_token:*`).
 *
 * Invarian:
 * 1. Format token wajib 32 karakter heksadesimal (`bin2hex(random_bytes(16))`),
 *    sejajar dengan validasi pola `/^[a-f0-9]{32}$/i` pada `KioskController::canExit()`.
 * 2. Setiap `attempt_id` hanya memiliki maksimal satu `ws_student_token` aktif
 *    yang ditautkan melalui kunci balik `attempt_ws_token:{attemptId}` baik pada
 *    jalur web dinamis (`ExamController::take()`) maupun jalur statis/bundle
 *    (`ExamApiController::init()`).
 */
final class WebSocketToken
{
    public const TTL_SECONDS = 14400; // 4 jam

    public static function studentTokenKey(string $token): string
    {
        return "ws_student_token:{$token}";
    }

    public static function proctorTokenKey(string $token): string
    {
        return "ws_proctor_token:{$token}";
    }

    public static function attemptTokenKey(int|string $attemptId): string
    {
        return "attempt_ws_token:{$attemptId}";
    }

    /**
     * Terbitkan token WebSocket siswa baru sambil mencabut token lama milik
     * attempt yang sama bila ada.
     */
    public static function issueStudentToken(
        int $userId,
        int $attemptId,
        int $testId,
        int $authVersion = 1,
        ?object $redis = null,
        bool $resolveRedis = true
    ): string {
        $token  = bin2hex(random_bytes(16));
        $client = $redis ?? ($resolveRedis ? RedisClient::getInstance() : null);

        if ($client === null) {
            return $token;
        }

        try {
            $attemptKey = self::attemptTokenKey($attemptId);
            $previous   = $client->get($attemptKey);
            if (is_string($previous) && $previous !== '') {
                $client->del(self::studentTokenKey($previous));
            }

            $payload = json_encode([
                'user_id'      => $userId,
                'attempt_id'   => $attemptId,
                'test_id'      => $testId,
                'auth_version' => SessionInvalidation::normalizeVersion($authVersion),
            ], JSON_UNESCAPED_UNICODE);

            $client->setex(self::studentTokenKey($token), self::TTL_SECONDS, (string) $payload);
            $client->setex($attemptKey, self::TTL_SECONDS, $token);
        } catch (\Throwable $e) {
            log_message('error', 'WebSocketToken::issueStudentToken gagal menulis ke Redis: ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Terbitkan token WebSocket pengawas dengan mengikat `user_id`, `test_id`,
     * dan `auth_version` saat ini.
     */
    public static function issueProctorToken(
        int $userId,
        int $testId,
        int $authVersion = 1,
        ?object $redis = null,
        bool $resolveRedis = true
    ): string {
        $token  = bin2hex(random_bytes(16));
        $client = $redis ?? ($resolveRedis ? RedisClient::getInstance() : null);

        if ($client === null) {
            return $token;
        }

        try {
            $payload = json_encode([
                'user_id'      => $userId,
                'test_id'      => $testId,
                'auth_version' => SessionInvalidation::normalizeVersion($authVersion),
            ], JSON_UNESCAPED_UNICODE);

            $client->setex(self::proctorTokenKey($token), self::TTL_SECONDS, (string) $payload);
        } catch (\Throwable $e) {
            log_message('error', 'WebSocketToken::issueProctorToken gagal menulis ke Redis: ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Cabut token WebSocket milik satu attempt beserta kunci indeks baliknya.
     */
    public static function revokeAttemptToken(
        int $attemptId,
        ?object $redis = null,
        bool $resolveRedis = true
    ): bool {
        if ($attemptId <= 0) {
            return false;
        }

        $client = $redis ?? ($resolveRedis ? RedisClient::getInstance() : null);
        if ($client === null) {
            return false;
        }

        try {
            $attemptKey = self::attemptTokenKey($attemptId);
            $token      = $client->get($attemptKey);
            if (is_string($token) && $token !== '') {
                $client->del(self::studentTokenKey($token));
            }
            $client->del($attemptKey);
            return true;
        } catch (\Throwable $e) {
            log_message('error', "WebSocketToken::revokeAttemptToken gagal untuk attempt #{$attemptId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cabut token WebSocket untuk daftar attempt milik pengguna (mis. saat akun dikunci).
     *
     * @param array<int, int|string> $attemptIds
     */
    public static function revokeUserAttemptTokens(
        int $userId,
        array $attemptIds,
        ?object $redis = null,
        bool $resolveRedis = true
    ): int {
        $client = $redis ?? ($resolveRedis ? RedisClient::getInstance() : null);
        if ($client === null) {
            return 0;
        }

        $seen  = [];
        $count = 0;

        foreach ($attemptIds as $rawId) {
            $attemptId = (int) $rawId;
            if ($attemptId <= 0 || isset($seen[$attemptId])) {
                continue;
            }
            $seen[$attemptId] = true;

            if (self::revokeAttemptToken($attemptId, $client, false)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Urai JSON snapshot `user_auth_state:{userId}` dari Redis menjadi array
     * tervalidasi, atau `null` bila key belum ada / korup.
     *
     * @return array<string, mixed>|null
     */
    public static function parseAuthStateJson(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_key_exists('is_active', $decoded) || !array_key_exists('auth_version', $decoded)) {
            return null;
        }

        return [
            'auth_version' => SessionInvalidation::normalizeVersion($decoded['auth_version']),
            'is_active'    => (int) $decoded['is_active'],
            'role'         => (string) ($decoded['role'] ?? ''),
        ];
    }

    /**
     * Evaluasi murni tanpa I/O apakah payload token WS masih diizinkan oleh
     * state autentikasi pengguna di Redis.
     *
     * Jika `$authState === null` (cache belum terisi di Redis), mengembalikan
     * `true` agar proses event loop WebSocket tidak pernah melakukan kueri
     * sinkronus ke MariaDB.
     *
     * @param array<string, mixed>      $tokenData
     * @param array<string, mixed>|null $authState
     */
    public static function isTokenAllowedByAuthState(array $tokenData, ?array $authState): bool
    {
        if ($authState === null) {
            return true;
        }

        if (((int) ($authState['is_active'] ?? 0)) !== 1) {
            return false;
        }

        $tokenAuth = SessionInvalidation::normalizeVersion($tokenData['auth_version'] ?? 1);
        $stateAuth = SessionInvalidation::normalizeVersion($authState['auth_version'] ?? 1);

        return $tokenAuth >= $stateAuth;
    }
}

<?php

namespace App\Libraries;

/**
 * Koordinasi I/O untuk state pembatalan sesi pengguna (Redis cache + fallback
 * MariaDB + publikasi event WebSocket).
 *
 * Logika keputusannya tetap murni di SessionInvalidation; kelas ini hanya
 * menjembatani pembacaan/penulisan agar pemanggil (AuthController,
 * UserController, SessionGuardFilter, ProctorAction, ExamService) memakai
 * format kunci dan aturan pembersihan yang seragam.
 */
final class UserAuthState
{
    /**
     * Bangun struktur state terstandar dari baris objek user.
     *
     * @return array{auth_version:int, cred_version:int, cred_grace_until_ts:?int, is_active:int, role:string}
     */
    public static function fromUserRow(object $user): array
    {
        $graceTs = null;
        if (!empty($user->cred_grace_until)) {
            $parsed = strtotime((string) $user->cred_grace_until);
            if ($parsed !== false) {
                $graceTs = $parsed;
            }
        }

        return [
            'auth_version'        => SessionInvalidation::normalizeVersion($user->auth_version ?? 1),
            'cred_version'        => SessionInvalidation::normalizeVersion($user->cred_version ?? 1),
            'cred_grace_until_ts' => $graceTs,
            'is_active'           => (int) ($user->is_active ?? 0),
            'role'                => (string) ($user->role ?? 'siswa'),
        ];
    }

    /**
     * Simpan snapshot state ke Redis. Kegagalan Redis tidak boleh melempar
     * exception ke pemanggil karena DB tetap menjadi sumber kebenaran.
     *
     * @param array<string, mixed> $state
     */
    public static function writeCache(int $userId, array $state): void
    {
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $redis->setex(
                    SessionInvalidation::stateKey($userId),
                    SessionInvalidation::STATE_TTL_SECONDS,
                    json_encode($state, JSON_UNESCAPED_UNICODE)
                );
            }
        } catch (\Throwable $e) {
            log_message('warning', "UserAuthState::writeCache gagal untuk user #{$userId}: " . $e->getMessage());
        }
    }

    /**
     * Hapus cache Redis sehingga pembacaan berikutnya menarik baris segar dari DB.
     */
    public static function invalidateCache(int $userId): void
    {
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $redis->del(SessionInvalidation::stateKey($userId));
            }
        } catch (\Throwable $e) {
            log_message('warning', "UserAuthState::invalidateCache gagal untuk user #{$userId}: " . $e->getMessage());
        }
    }

    /**
     * Baca state terkini user: utamakan Redis (0 query DB), fallback ke PK
     * lookup MariaDB bila key belum ada / ter-evict / Redis bermasalah.
     *
     * Mengembalikan null HANYA bila baik Redis maupun MariaDB gagal dihubungi
     * secara bersamaan (mis. outage infrastruktur), supaya filter tidak
     * menghancurkan seluruh sesi peserta akibat gangguan sesaat.
     *
     * @return array{auth_version:int, cred_version:int, cred_grace_until_ts:?int, is_active:int, role:string}|null
     */
    public static function resolve(int $userId): ?array
    {
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $raw = $redis->get(SessionInvalidation::stateKey($userId));
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && isset($decoded['auth_version'], $decoded['cred_version'])) {
                        return [
                            'auth_version'        => SessionInvalidation::normalizeVersion($decoded['auth_version']),
                            'cred_version'        => SessionInvalidation::normalizeVersion($decoded['cred_version']),
                            'cred_grace_until_ts' => isset($decoded['cred_grace_until_ts']) && $decoded['cred_grace_until_ts'] !== null
                                ? (int) $decoded['cred_grace_until_ts']
                                : null,
                            'is_active'           => (int) ($decoded['is_active'] ?? 0),
                            'role'                => (string) ($decoded['role'] ?? 'siswa'),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            log_message('warning', "UserAuthState::resolve Redis fallback ke DB untuk user #{$userId}: " . $e->getMessage());
        }

        // Lazy fallback ke MariaDB saat cache miss / Redis ter-evict / sebelum migrasi dijalankan.
        try {
            $db  = \Config\Database::connect();
            $row = $db->table('users')
                ->select('*')
                ->where('id', $userId)
                ->get()
                ->getRow();

            if (!$row || !empty($row->deleted_at)) {
                $deletedState = [
                    'auth_version'        => PHP_INT_MAX,
                    'cred_version'        => PHP_INT_MAX,
                    'cred_grace_until_ts' => null,
                    'is_active'           => 0,
                    'role'                => '',
                ];
                self::writeCache($userId, $deletedState);
                return $deletedState;
            }

            $state = self::fromUserRow($row);
            self::writeCache($userId, $state);
            return $state;
        } catch (\Throwable $e) {
            log_message('error', "UserAuthState::resolve gagal membaca DB untuk user #{$userId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Apakah siswa berstatus "Sedang Mengerjakan" (attempt status 1 = aktif,
     * 2 = paused/suspended), persis definisi pie chart Admin\DashboardController.
     */
    public static function isStudentTakingExam(int $userId): bool
    {
        $db  = \Config\Database::connect();
        $row = $db->table('test_attempts')
            ->select('id')
            ->where('user_id', $userId)
            ->whereIn('status', [1, 2])
            ->limit(1)
            ->get()
            ->getRow();

        return $row !== null;
    }

    /**
     * Compare-and-delete penanda sesi multi-login di Redis hanya jika sesi yang
     * dicabut memang masih pemegang sah token tersebut.
     *
     * Tanpa pembersihan ini, siswa di browser desktop (tanpa device_id) yang
     * sesinya kedaluwarsa setelah 10 menit akan terhadang SessionTakeover::BUSY
     * sampai 2 jam saat login ulang. Sebaliknya, pencocokan hash_equals menjaga
     * agar sesi lama tidak pernah menghapus token milik sesi baru maupun
     * sentinel 'BANNED'.
     */
    public static function clearSessionTrackingIfOwned(int $userId, ?string $sessionLoginToken): void
    {
        if ($sessionLoginToken === null || $sessionLoginToken === '') {
            return;
        }

        try {
            $redis = RedisClient::getInstance();
            if (!$redis) {
                return;
            }

            $tokenKey = "user_login_token:{$userId}";
            $stored   = $redis->get($tokenKey);

            if (is_string($stored) && $stored !== '' && hash_equals($stored, $sessionLoginToken)) {
                $redis->del($tokenKey);
                $redis->del(SessionTakeover::deviceKey($userId));
                $redis->zRem('active_sessions', $userId);
                $redis->zRem('login_queue', $userId);
            }
        } catch (\Throwable $e) {
            log_message('warning', "UserAuthState::clearSessionTrackingIfOwned gagal untuk user #{$userId}: " . $e->getMessage());
        }
    }

    /**
     * Terapkan pencabutan seketika (0 detik TTL) ke Redis dan opsional kirim
     * perintah kick ke koneksi WebSocket ujian milik user tersebut.
     *
     * @param array<string, mixed> $state
     */
    public static function revokeImmediatelyInRedis(
        int $userId,
        array $state,
        bool $preserveBannedSentinel = false,
        ?string $wsKickMessage = null
    ): void {
        self::writeCache($userId, $state);

        try {
            $redis = RedisClient::getInstance();
            if (!$redis) {
                return;
            }

            if (!$preserveBannedSentinel) {
                $redis->del("user_login_token:{$userId}");
                $redis->del(SessionTakeover::deviceKey($userId));
            }
            $redis->zRem('active_sessions', $userId);
            $redis->zRem('login_queue', $userId);

            if ($wsKickMessage !== null && $wsKickMessage !== '') {
                $redis->publish('exam_events', json_encode([
                    'event'   => 'kick',
                    'user_id' => $userId,
                    'message' => $wsKickMessage,
                ], JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            log_message('warning', "UserAuthState::revokeImmediatelyInRedis gagal untuk user #{$userId}: " . $e->getMessage());
        }
    }

    /**
     * Simpan state masa tenggang 10 menit siswa ke Redis dan kirim peringatan
     * WebSocket `cred_updated` ke koneksi ujian milik user_id tersebut.
     *
     * @param array<string, mixed> $state
     */
    public static function notifyStudentGraceInRedis(int $userId, array $state, int $nowTs): void
    {
        self::writeCache($userId, $state);

        $graceUntilTs = isset($state['cred_grace_until_ts'])
            ? (int) $state['cred_grace_until_ts']
            : ($nowTs + SessionInvalidation::STUDENT_GRACE_SECONDS);

        $credVersion = SessionInvalidation::normalizeVersion($state['cred_version'] ?? 1);

        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $payload = SessionInvalidation::buildCredUpdatedPayload(
                    $userId,
                    $credVersion,
                    $graceUntilTs,
                    $nowTs
                );
                $redis->publish('exam_events', json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            log_message('warning', "UserAuthState::notifyStudentGraceInRedis gagal untuk user #{$userId}: " . $e->getMessage());
        }
    }
}

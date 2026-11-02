<?php

namespace App\Libraries;

/**
 * Aturan murni pencabutan sesi saat kredensial, role, atau status aktif akun
 * berubah.
 *
 * Dipisahkan sepenuhnya dari Redis dan database supaya dua invarian keamanan
 * yang saling berlawanan dapat diuji deterministik:
 * 1. Pergantian username/password pada admin/guru, perubahan role, atau
 *    penonaktifan akun mencabut sesi seketika (0 detik).
 * 2. Pergantian username/password pada siswa aktif memberi masa tenggang
 *    600 detik hanya bagi sesi pra-perubahan yang belum pernah terkena
 *    pencabutan keras (ban/demote), tanpa mematikan sesi baru bila siswa
 *    sudah login ulang lebih dulu.
 */
final class SessionInvalidation
{
    public const VALID   = 'valid';
    public const GRACE   = 'grace';
    public const REVOKED = 'revoked';

    public const REASON_INACTIVE      = 'inactive';
    public const REASON_REVOKED       = 'revoked';
    public const REASON_GRACE_EXPIRED = 'grace_expired';

    public const CHANGE_NONE             = 'none';
    public const CHANGE_REFRESH_ONLY     = 'refresh_only';
    public const CHANGE_IMMEDIATE_REVOKE = 'immediate_revoke';
    public const CHANGE_STUDENT_GRACE    = 'student_grace';

    /** Masa tenggang sesi siswa saat username/password diganti (10 menit). */
    public const STUDENT_GRACE_SECONDS = 600;

    /** Umur cache state autentikasi di Redis, sejajar dengan Config\Session::$expiration. */
    public const STATE_TTL_SECONDS = 7200;

    public const STUDENT_WARNING_MESSAGE = 'Informasi akun (username/password) Anda telah diperbarui oleh Administrator. Sesi ini akan berakhir dalam 10 menit.';

    public static function stateKey(int|string $userId): string
    {
        return "user_auth_state:{$userId}";
    }

    /**
     * Sesi lama yang dibuat sebelum migrasi belum memiliki auth_version /
     * cred_version di payload sesi. Menormalisasinya ke 1 (sama dengan nilai
     * DEFAULT kolom baru) mencegah logout massal saat deploy, tetapi tetap
     * mencabut sesi lama itu begitu versi di DB naik ke 2.
     */
    public static function normalizeVersion(mixed $version): int
    {
        if (is_int($version)) {
            return $version >= 1 ? $version : 1;
        }
        if (is_string($version) && ctype_digit($version)) {
            $parsed = (int) $version;
            return $parsed >= 1 ? $parsed : 1;
        }
        return 1;
    }

    /**
     * Klasifikasikan dampak perubahan data user terhadap sesi yang sedang hidup.
     *
     * @param object               $oldUser Baris user sebelum diupdate.
     * @param array<string, mixed> $newData Data baru yang hendak disimpan.
     */
    public static function classifyUserUpdate(object $oldUser, array $newData): string
    {
        $oldUsername = (string) ($oldUser->username ?? '');
        $newUsername = array_key_exists('username', $newData)
            ? (string) $newData['username']
            : $oldUsername;
        $usernameChanged = $newUsername !== $oldUsername;

        $passwordChanged = isset($newData['password']) && (string) $newData['password'] !== '';
        $credChanged     = $usernameChanged || $passwordChanged;

        $oldRole     = (string) ($oldUser->role ?? 'siswa');
        $newRole     = array_key_exists('role', $newData) ? (string) $newData['role'] : $oldRole;
        $roleChanged = $newRole !== $oldRole;

        $oldActive   = ((int) ($oldUser->is_active ?? 1)) === 1;
        $newActive   = array_key_exists('is_active', $newData)
            ? (((int) $newData['is_active']) === 1)
            : $oldActive;
        $deactivated = $oldActive && !$newActive;
        $reactivated = !$oldActive && $newActive;

        // Perubahan role atau penonaktifan selalu menang atas masa tenggang siswa:
        // guru yang diturunkan jadi siswa sambil diganti password-nya tidak boleh
        // mempertahankan hak sesi lama selama 10 menit.
        if ($roleChanged || $deactivated) {
            return self::CHANGE_IMMEDIATE_REVOKE;
        }

        if ($credChanged) {
            if ($newRole === 'siswa' && $oldActive && $newActive) {
                return self::CHANGE_STUDENT_GRACE;
            }
            return self::CHANGE_IMMEDIATE_REVOKE;
        }

        if ($reactivated) {
            return self::CHANGE_REFRESH_ONLY;
        }

        return self::CHANGE_NONE;
    }

    /**
     * Evaluasi apakah sebuah sesi masih sah, berada dalam masa tenggang, atau
     * harus dicabut.
     *
     * @param int|null             $sessionAuthVer Versi auth di sesi (null pada sesi pra-migrasi).
     * @param int|null             $sessionCredVer Versi kredensial di sesi (null pada sesi pra-migrasi).
     * @param array<string, mixed> $state          State terkini dari Redis/DB.
     * @param int                  $nowTs          Unix timestamp detik saat ini.
     *
     * @return array{status:string, reason:?string, remaining_seconds:?int, grace_until_ts:?int}
     */
    public static function evaluate(?int $sessionAuthVer, ?int $sessionCredVer, array $state, int $nowTs): array
    {
        if (empty($state['is_active'])) {
            return [
                'status'            => self::REVOKED,
                'reason'            => self::REASON_INACTIVE,
                'remaining_seconds' => 0,
                'grace_until_ts'    => null,
            ];
        }

        $sAuth = self::normalizeVersion($sessionAuthVer);
        $uAuth = self::normalizeVersion($state['auth_version'] ?? 1);

        if ($sAuth < $uAuth) {
            return [
                'status'            => self::REVOKED,
                'reason'            => self::REASON_REVOKED,
                'remaining_seconds' => 0,
                'grace_until_ts'    => null,
            ];
        }

        $sCred = self::normalizeVersion($sessionCredVer);
        $uCred = self::normalizeVersion($state['cred_version'] ?? 1);

        if ($sCred < $uCred) {
            if (($state['role'] ?? '') !== 'siswa') {
                return [
                    'status'            => self::REVOKED,
                    'reason'            => self::REASON_REVOKED,
                    'remaining_seconds' => 0,
                    'grace_until_ts'    => null,
                ];
            }

            $graceUntil = isset($state['cred_grace_until_ts']) ? (int) $state['cred_grace_until_ts'] : 0;
            if ($graceUntil <= $nowTs) {
                return [
                    'status'            => self::REVOKED,
                    'reason'            => self::REASON_GRACE_EXPIRED,
                    'remaining_seconds' => 0,
                    'grace_until_ts'    => null,
                ];
            }

            return [
                'status'            => self::GRACE,
                'reason'            => null,
                'remaining_seconds' => $graceUntil - $nowTs,
                'grace_until_ts'    => $graceUntil,
            ];
        }

        return [
            'status'            => self::VALID,
            'reason'            => null,
            'remaining_seconds' => null,
            'grace_until_ts'    => null,
        ];
    }

    /**
     * Payload yang dipublikasikan ke kanal Redis `exam_events` untuk diteruskan
     * WebSocketServerHandler hanya ke koneksi milik user_id terkait.
     *
     * @return array<string, int|string>
     */
    public static function buildCredUpdatedPayload(int $userId, int $credVersion, int $graceUntilTs, int $nowTs): array
    {
        return [
            'event'          => 'cred_updated',
            'user_id'        => $userId,
            'cred_version'   => $credVersion,
            'grace_until_ms' => $graceUntilTs * 1000,
            'server_time_ms' => $nowTs * 1000,
            'ttl_seconds'    => max(0, $graceUntilTs - $nowTs),
            'message'        => self::STUDENT_WARNING_MESSAGE,
        ];
    }
}

<?php

namespace App\Libraries;

use App\Models\ActivityLogModel;
use App\Models\TestAttemptModel;
use App\Models\UserModel;

/**
 * Dua tindakan keras yang boleh diambil pengawas terhadap peserta yang sedang
 * ujian. Dipakai bersama oleh KioskLiveController dan SuspendController supaya
 * tidak ada dua salinan logika penguncian.
 *
 * Prinsip: DATABASE dulu, Redis belakangan. Menulis status attempt adalah
 * penegakan intinya dan tidak boleh bergantung pada Redis; pencabutan token dan
 * publish real-time adalah usaha terbaik yang kegagalannya dilaporkan, bukan
 * membatalkan tindakan.
 */
final class ProctorAction
{
    public const ACTIONS = ['eject', 'lock', 'eject_lock'];

    public const DEFAULT_REASON = 'Dikeluarkan oleh pengawas';

    public const STUDENT_MESSAGE = 'Ujian Anda dihentikan oleh pengawas. Serahkan perangkat kepada pengawas.';

    /** Logika murni: apakah nama aksi dikenal. */
    public static function isValidAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /** Logika murni: bentuk pesan yang dipublikasikan ke kanal exam_events. */
    public static function buildEjectPayload(int $userId, int $attemptId, int $testId, string $reason): array
    {
        $reason = trim($reason);

        return [
            'event'      => 'ejected',
            'user_id'    => $userId,
            'attempt_id' => $attemptId,
            'test_id'    => $testId,
            'reason'     => $reason !== '' ? $reason : self::DEFAULT_REASON,
            'message'    => self::STUDENT_MESSAGE,
        ];
    }

    /**
     * Keluarkan peserta dari ujian yang sedang berjalan.
     *
     * Pencabutan token disengaja membuat perangkat MAKIN terkunci, bukan
     * terlepas: heartbeat native menjawab 401 dan /api/kiosk/can-exit menolak,
     * sehingga satu-satunya jalan keluar tetap password pengawas.
     *
     * @return array{ok:bool, message:string, realtime:bool, attempt_id:int}
     */
    public function eject(int $testId, int $userId, int $actorId, string $reason = ''): array
    {
        $db = \Config\Database::connect();

        $attempt = $db->table('test_attempts')
            ->select('id')
            ->where('test_id', $testId)
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1])
            ->orderBy('id', 'DESC')
            ->get()->getRow();

        if (!$attempt) {
            return [
                'ok'         => false,
                'message'    => 'Siswa ini tidak sedang mengerjakan ujian tersebut.',
                'realtime'   => false,
                'attempt_id' => 0,
            ];
        }

        $attemptId = (int) $attempt->id;

        // 1) Penegakan inti — tidak bergantung Redis.
        $db->table('test_attempts')->where('id', $attemptId)->update(['status' => 2]);
        (new TestAttemptModel())->clearCacheForAttempt($attemptId, $testId, $userId);

        // 2) Audit — best effort, kegagalannya tidak boleh membatalkan tindakan.
        try {
            (new ActivityLogModel())->log(
                'proctor_eject',
                $actorId,
                'test',
                $testId,
                "Mengeluarkan user #{$userId} dari ujian (attempt #{$attemptId})"
            );
            $db->table('exam_kiosk_events')->insert([
                'exam_session_id' => $testId,
                'student_id'      => $userId,
                'event_type'      => 'proctor_eject',
                'event_details'   => json_encode(['actor_id' => $actorId, 'reason' => trim($reason)], JSON_UNESCAPED_UNICODE),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction eject audit gagal: ' . $e->getMessage());
        }

        // 3) Cabut token + kabari perangkat — best effort.
        $realtime = false;
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $token = $redis->get("attempt_ws_token:{$attemptId}");
                if ($token) {
                    $redis->del("ws_student_token:{$token}");
                }
                $redis->del("attempt_ws_token:{$attemptId}");

                $redis->publish('exam_events', json_encode(
                    self::buildEjectPayload($userId, $attemptId, $testId, $reason)
                ));
                $realtime = true;
            }
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction eject realtime gagal: ' . $e->getMessage());
        }

        return [
            'ok'         => true,
            'message'    => $realtime
                ? 'Siswa dikeluarkan dari ujian.'
                : 'Siswa sudah dikunci, tetapi perintah real-time gagal terkirim. Layar siswa akan berubah paling lambat 60 detik.',
            'realtime'   => $realtime,
            'attempt_id' => $attemptId,
        ];
    }

    /**
     * Kunci akun seketika: nonaktifkan user, kunci attempt yang berjalan, cabut
     * token login, dan hapus sesi aktifnya.
     *
     * Ini adalah logika yang sebelumnya hidup di SuspendController::_doBan.
     *
     * @return array{ok:bool, message:string, realtime:bool}
     */
    public function lockAccount(int $userId, int $actorId): array
    {
        $db = \Config\Database::connect();
        $db->transStart();

        (new UserModel())->update($userId, ['is_active' => 0]);

        $db->table('test_attempts')
            ->where('user_id', $userId)
            ->whereIn('status', [1, 2])
            ->update(['status' => 2]);

        $db->transComplete();

        try {
            (new ActivityLogModel())->log('proctor_lock', $actorId, 'user', $userId, "Mengunci akun user #{$userId}");
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction lock audit gagal: ' . $e->getMessage());
        }

        $realtime = false;
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                $redis->setex("ban_signal:{$userId}", 120, '1');
                $redis->publish('exam_events', json_encode([
                    'event'   => 'ban',
                    'user_id' => $userId,
                    'message' => 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.',
                ]));

                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, 'ci_session:*', 100);
                    if ($keys) {
                        foreach ($keys as $key) {
                            $data = $redis->get($key);
                            if ($data && (strpos($data, "user_id|i:{$userId};") !== false ||
                                          strpos($data, "user_id|s:" . strlen((string) $userId) . ":\"{$userId}\";") !== false)) {
                                $redis->del($key);
                            }
                        }
                    }
                } while ($iterator > 0);
                $realtime = true;
            }
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction lock realtime gagal: ' . $e->getMessage());
        }

        return [
            'ok'       => true,
            'message'  => $realtime
                ? 'Akun dikunci.'
                : 'Akun dikunci di database, tetapi sesi aktif mungkin belum tercabut karena Redis tidak tersedia.',
            'realtime' => $realtime,
        ];
    }
}

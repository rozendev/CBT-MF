<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DeviceBan;
use App\Libraries\ProctorAction;
use App\Libraries\RedisClient;
use App\Models\TestModel;

class KioskLiveController extends BaseController
{
    private const STALE_SECONDS = 90;

    public function index()
    {
        $db = \Config\Database::connect();

        $activeTests = $db->table('tests')
            ->select('tests.id, tests.name, COUNT(ta.id) AS attempt_count')
            ->join('test_attempts ta', 'ta.test_id = tests.id', 'inner')
            ->whereIn('ta.status', [0, 1, 2])
            ->groupBy('tests.id, tests.name')
            ->orderBy('tests.id', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        return view('admin/kiosk/live', [
            'title'       => 'Monitoring Kiosk Real-Time',
            'activeTests' => $activeTests,
            // Perangkat sekolah dipakai bergilir. Blokir yang terlupakan akan
            // mengunci siswa berikutnya, jadi jumlahnya harus selalu terlihat.
            'bannedCount' => (new \App\Models\KioskBannedDeviceModel())->countActive(),
        ]);
    }

    public function data()
    {
        $testId = (int) $this->request->getGet('test_id');
        if ($testId <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'test_id wajib']);
        }

        $db = \Config\Database::connect();
        $attempts = $db->table('test_attempts')
            ->select('test_attempts.user_id, users.firstname, users.lastname, users.username')
            ->join('users', 'users.id = test_attempts.user_id')
            ->where('test_attempts.test_id', $testId)
            ->whereIn('test_attempts.status', [0, 1, 2])
            ->get()
            ->getResultArray();

        $redis = RedisClient::getInstance();
        $now   = time();

        $students = [];
        foreach ($attempts as $attempt) {
            $userId = (int) $attempt['user_id'];
            $status = 'offline';
            $device = [
                'battery'     => -1,
                'charging'    => false,
                'network'     => 'unknown',
                'app_version' => '',
                'device_id'   => '',
                'last_seen'   => null,
            ];

            if ($redis) {
                $info = $redis->hGetAll("kiosk_live:{$testId}:{$userId}");
                if (!empty($info)) {
                    $ts = (int) ($info['ts'] ?? 0);
                    $status = ($now - $ts) <= 30 ? 'online' : 'stale';
                    $device = [
                        'battery'     => (int) ($info['battery'] ?? -1),
                        'charging'    => ($info['charging'] ?? '0') === '1',
                        'network'     => (string) ($info['network'] ?? 'unknown'),
                        'app_version' => (string) ($info['app_version'] ?? ''),
                        'device_id'   => (string) ($info['device_id'] ?? ''),
                        'last_seen'   => date('Y-m-d H:i:s', $ts),
                    ];
                }
            }

            $students[] = array_merge([
                'user_id'  => $userId,
                'username' => $attempt['username'],
                'firstname'=> $attempt['firstname'],
                'lastname' => $attempt['lastname'],
                'status'   => $status,
            ], $device);
        }

        return $this->response->setJSON([
            'test_id'  => $testId,
            'now'      => $now,
            'students' => $students,
        ]);
    }

    /**
     * Tindakan pengawas terhadap satu peserta.
     * POST { test_id, user_id, action: eject|lock|eject_lock|ban_device, reason?, device_id? }
     */
    public function action()
    {
        $body = $this->request->getJSON(true);
        if (!is_array($body)) {
            $body = $this->request->getPost();
        }

        $testId = (int) ($body['test_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        $action = (string) ($body['action'] ?? '');
        $reason = (string) ($body['reason'] ?? '');

        if ($testId <= 0 || $userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error', 'message' => 'test_id dan user_id wajib.',
            ]);
        }

        // ban_device bukan ProctorAction: ia menyasar perangkat, bukan akun,
        // jadi sengaja tidak masuk ke ProctorAction::ACTIONS.
        if ($action !== 'ban_device' && !ProctorAction::isValidAction($action)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error', 'message' => 'Aksi tidak dikenal.',
            ]);
        }

        if ($action === 'ban_device') {
            $deviceId = (string) ($body['device_id'] ?? '');
            $actorId  = (int) session('user_id');

            if (!DeviceBan::isValidDeviceId($deviceId)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => 'Perangkat ini belum melaporkan ID — aplikasinya perlu diperbarui.',
                ]);
            }

            $banResult = DeviceBan::ban($deviceId, (string) ($body['reason'] ?? ''), $actorId, $userId, $testId);
            if (!$banResult['ok']) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error', 'message' => $banResult['message'],
                ]);
            }

            // Blokir perangkat SEKALIGUS mengeluarkan sesi berjalan: pengawas
            // menekan tombol ini justru untuk menghentikan yang sedang terjadi.
            // Akun TIDAK dikunci — "perangkat ini bermasalah" bukan "siswa ini
            // dihukum", dan siswanya masih bisa dipindah ke perangkat lain.
            //
            // Eject yang gagal (mis. siswa memang tidak sedang ujian) TIDAK
            // membatalkan ban: perangkatnya tetap terblokir, dan pesannya
            // mengatakan apa adanya supaya pengawas tidak salah menyimpulkan.
            $ejectResult = (new ProctorAction())->eject($testId, $userId, $actorId, 'Perangkat diblokir pengawas');

            return $this->response->setJSON([
                'status'  => 'success',
                'message' => $banResult['message'] . ' ' . $ejectResult['message'],
            ]);
        }

        $actorId  = (int) session('user_id');
        $proctor  = new ProctorAction();
        $messages = [];
        $ok       = true;

        if ($action === 'eject' || $action === 'eject_lock') {
            $result = $proctor->eject($testId, $userId, $actorId, $reason);
            $messages[] = $result['message'];
            // Gagal eject (mis. siswa tidak sedang ujian) TIDAK membatalkan lock:
            // pengawas yang memilih eject_lock tetap ingin akunnya terkunci.
            if ($action === 'eject') {
                $ok = $result['ok'];
            }
        }

        if ($action === 'lock' || $action === 'eject_lock') {
            $result = $proctor->lockAccount($userId, $actorId);
            $messages[] = $result['message'];
            $ok = $ok && $result['ok'];
        }

        return $this->response->setJSON([
            'status'  => $ok ? 'success' : 'error',
            'message' => implode(' ', $messages),
        ]);
    }
}

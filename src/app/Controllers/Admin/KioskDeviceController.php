<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DeviceBan;
use App\Models\KioskBannedDeviceModel;

/**
 * Daftar perangkat yang diblokir dari menjalankan aplikasi ujian, dan tombol
 * untuk membukanya.
 *
 * Halaman ini ada karena perangkat sekolah dipakai bergilir: blokir yang
 * terlupakan akan mengunci siswa berikutnya yang memegang perangkat itu, dan
 * tanpa daftar yang gampang dilihat, blokir semacam itu tidak terlihat oleh
 * siapa pun sampai ada yang mengeluh.
 */
class KioskDeviceController extends BaseController
{
    public function index()
    {
        $devices = (new KioskBannedDeviceModel())->allActive();

        // Nama pengunci dilengkapi supaya pengawas tahu harus bertanya kepada
        // siapa, bukan sekadar melihat angka id.
        $db = \Config\Database::connect();
        foreach ($devices as $device) {
            $device->banned_by_name = $this->userLabel($db, (int) $device->banned_by);
            $device->last_user_name = $device->last_user_id !== null
                ? $this->userLabel($db, (int) $device->last_user_id)
                : '—';
        }

        return view('admin/kiosk/devices', [
            'title'   => 'Perangkat Terkunci',
            'devices' => $devices,
        ]);
    }

    public function unlock()
    {
        $body = $this->request->getJSON(true);
        if (!is_array($body)) {
            $body = $this->request->getPost();
        }

        $deviceId = (string) ($body['device_id'] ?? '');
        $result   = DeviceBan::unlock($deviceId, (int) session('user_id'));

        return $this->response
            ->setStatusCode($result['ok'] ? 200 : 400)
            ->setJSON([
                'status'  => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
            ]);
    }

    private function userLabel($db, int $userId): string
    {
        $row = $db->table('users')
            ->select('username, firstname, lastname')
            ->where('id', $userId)
            ->get()
            ->getRow();

        if ($row === null) {
            return 'user #' . $userId;
        }

        return trim($row->firstname . ' ' . $row->lastname) . ' (' . $row->username . ')';
    }
}

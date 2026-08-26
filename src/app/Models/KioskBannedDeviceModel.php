<?php

namespace App\Models;

use CodeIgniter\Model;

class KioskBannedDeviceModel extends Model
{
    protected $table         = 'kiosk_banned_devices';
    protected $primaryKey    = 'id';
    protected $returnType    = 'object';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'device_id',
        'reason',
        'banned_by',
        'banned_at',
        'unlocked_by',
        'unlocked_at',
        'last_user_id',
        'last_test_id',
    ];

    /**
     * Ban yang sedang berlaku untuk satu perangkat, atau null.
     *
     * src/public/kiosk-heartbeat.php menyimpan salinan kueri mentah dari
     * method ini lewat PDO — berkas itu bebas framework dengan sengaja,
     * jadi tidak bisa memanggil model ini. Ubah salah satu, cek yang lain.
     */
    public function activeFor(string $deviceId): ?object
    {
        return $this->where('device_id', $deviceId)
            ->where('unlocked_at', null)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Semua ban yang sedang berlaku, terbaru dulu, untuk halaman admin.
     */
    public function allActive(): array
    {
        return $this->where('unlocked_at', null)
            ->orderBy('banned_at', 'DESC')
            ->findAll();
    }

    public function countActive(): int
    {
        return $this->where('unlocked_at', null)->countAllResults();
    }
}

<?php

namespace App\Filters;

use App\Libraries\MultiLoginPolicy;
use App\Libraries\SessionTakeover;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class MultiLoginFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (!$session->get('logged_in')) {
            return;
        }

        // Allow Admin and Guru to have multiple active sessions and bypass maintenance
        $role = $session->get('role');
        if (in_array($role, ['admin', 'guru'])) {
            return;
        }

        // Maintenance mode: block siswa access
        try {
            $settingModel = new \App\Models\SettingModel();
            if ($settingModel->getValue('maintenance_mode', false)) {
                return redirect()->to('/maintenance');
            }
        } catch (\Exception $e) {
            // DB unreachable, don't block
        }

        // Setelan dibaca lewat penyelesai bersama, BUKAN dengan membuka
        // kunci cache milik SettingModel sendiri. Membacanya langsung berarti
        // menebak bentuk nilai yang disimpan pihak lain, dan tebakan itu
        // (`=== '0'`) meleset persis ketika barisnya bertipe boolean —
        // sakelar admin mati, filter tetap menegakkan.
        if (!MultiLoginPolicy::isEnabled()) {
            return;
        }

        $userId = $session->get('user_id');
        $currentToken = $session->get('login_token');

        if (!$currentToken) {
            return; // Legacy session or not fully logged in yet
        }

        // Use Redis to track active login tokens per user
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $key = "user_login_token:{$userId}";
                $storedToken = $redis->get($key);

                if ($storedToken && $storedToken !== $currentToken) {
                    $session->destroy();
                    
                    $message = 'Akun ini telah digunakan untuk login di perangkat atau browser lain. Sesi Anda diakhiri demi keamanan.';
                    if ($storedToken === 'BANNED') {
                        $message = 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.';
                    }
                    
                    return redirect()->to('/login')->with('error', $message);
                }
                
                // Perpanjang umur sesi yang masih dipakai. Penanda perangkat
                // ikut diperpanjang supaya umurnya tidak pernah menyimpang dari
                // tokennya: pendamping yang mati lebih dulu akan mengunci siswa
                // dari sesinya sendiri persis seperti sebelum fitur ini ada,
                // justru di ujian panjang yang paling membutuhkannya.
                $redis->expire($key, SessionTakeover::TTL_SECONDS);
                $redis->expire(SessionTakeover::deviceKey($userId), SessionTakeover::TTL_SECONDS);
            }
        } catch (\Exception $e) {
            // If Redis fails, log and continue (don't block user)
            log_message('error', 'MultiLoginFilter Redis error: ' . $e->getMessage());
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}

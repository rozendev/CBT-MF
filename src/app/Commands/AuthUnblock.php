<?php

namespace App\Commands;

use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Break-glass: buka blokir rate-limit login dari shell walau web terkunci.
 *
 *   php spark auth:unblock --ip 203.0.113.9   → hapus blokir satu IP
 *   php spark auth:unblock --user budi         → reset lockout akun + IP terakhirnya
 *   php spark auth:unblock --all               → hapus semua blokir IP (konfirmasi)
 *   php spark auth:unblock                      → daftar IP yang sedang terblokir
 */
class AuthUnblock extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'auth:unblock';
    protected $description = 'Buka blokir rate-limit login: per-IP, per-user, atau semua. Tanpa argumen: daftar IP terblokir.';
    protected $usage       = 'auth:unblock [--ip A.B.C.D] [--user USERNAME] [--all]';
    protected $options     = [
        '--ip'   => 'Hapus blokir satu IP.',
        '--user' => 'Reset lockout akun + blokir IP terakhirnya.',
        '--all'  => 'Hapus SEMUA blokir IP (minta konfirmasi).',
    ];

    public function run(array $params)
    {
        // Baca opsi dari $params (jalur yang dipakai helper command() di test)
        // dengan fallback ke CLI::getOption (jalur spark sungguhan dari argv).
        $ip   = $params['ip']   ?? CLI::getOption('ip');
        $user = $params['user'] ?? CLI::getOption('user');
        $all  = array_key_exists('all', $params) || CLI::getOption('all');

        if ($all) {
            if (CLI::prompt('Hapus SEMUA blokir IP login?', ['y', 'n']) !== 'y') {
                CLI::write('Dibatalkan.', 'yellow');
                return EXIT_SUCCESS;
            }
            $n = LoginThrottle::clearAll();
            CLI::write("Selesai — {$n} blokir IP dihapus.", 'green');
            return EXIT_SUCCESS;
        }

        if (is_string($ip) && $ip !== '') {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                CLI::error("IP tidak valid: {$ip}");
                return EXIT_ERROR;
            }
            LoginThrottle::clearForIp($ip);
            CLI::write("Blokir login untuk IP {$ip} dibuka.", 'green');
            return EXIT_SUCCESS;
        }

        if (is_string($user) && $user !== '') {
            $userModel = new UserModel();
            $row       = $userModel->where('username', $user)->first();
            if (!$row) {
                CLI::error("User tidak ditemukan: {$user}");
                return EXIT_ERROR;
            }

            $userModel->resetLoginAttempts((int) $row->id);

            try {
                $redis = RedisClient::getInstance();
                if ($redis) {
                    $failedIp = $redis->get("last_failed_login_ip:{$row->id}");
                    if ($failedIp) {
                        LoginThrottle::clearForIp((string) $failedIp);
                        $redis->del("last_failed_login_ip:{$row->id}");
                    }
                }
            } catch (\Throwable $e) {
                CLI::write('Peringatan: gagal membersihkan kunci IP Redis: ' . $e->getMessage(), 'yellow');
            }

            CLI::write("Lockout akun '{$user}' direset dan blokir IP terakhirnya dibuka.", 'green');
            return EXIT_SUCCESS;
        }

        // Tanpa argumen: diagnostik.
        $blocks = LoginThrottle::activeBlocks();
        if (empty($blocks)) {
            CLI::write('Tidak ada IP dengan percobaan login aktif.', 'green');
            return EXIT_SUCCESS;
        }

        $max = LoginThrottle::maxAttempts();
        CLI::write("Percobaan login aktif (diblokir bila > {$max}):", 'yellow');
        $rows = [];
        foreach ($blocks as $bip => $count) {
            $rows[] = [$bip, (string) $count, $count > $max ? 'TERBLOKIR' : 'ok'];
        }
        CLI::table($rows, ['IP', 'Percobaan', 'Status']);
        return EXIT_SUCCESS;
    }
}

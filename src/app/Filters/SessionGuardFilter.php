<?php

namespace App\Filters;

use App\Libraries\SessionInvalidation;
use App\Libraries\UserAuthState;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Penjaga global keabsahan sesi pengguna terhadap perubahan kredensial, role,
 * dan status aktif akun.
 *
 * Dipasang di $globals['before'] karena rute /admin, /student, dan /proctor
 * hanya memakai RoleFilter (bukan AuthFilter), sedangkan MultiLoginFilter
 * melewatkan admin dan guru. Menyatukan pemeriksaan di sini menutup seluruh
 * permukaan rute terautentikasi maupun sesi antrean (/queue).
 */
class SessionGuardFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session    = session();
        $isLoggedIn = (bool) $session->get('logged_in');
        $isQueued   = (bool) $session->get('is_queued');

        if (!$isLoggedIn && !$isQueued) {
            return;
        }

        $userId = (int) $session->get('user_id');
        if ($userId <= 0) {
            $session->destroy();
            return $this->deny($request, 'Sesi tidak valid. Silakan login kembali.');
        }

        $state = UserAuthState::resolve($userId);
        if ($state === null) {
            // Baik Redis maupun MariaDB sedang gagal dihubungi; fail-open satu
            // request ini agar gangguan sesaat tidak menghancurkan sesi massal.
            return;
        }

        $rawAuthVer = $session->get('auth_version');
        $rawCredVer = $session->get('cred_version');

        $decision = SessionInvalidation::evaluate(
            $rawAuthVer !== null ? (int) $rawAuthVer : null,
            $rawCredVer !== null ? (int) $rawCredVer : null,
            $state,
            time()
        );

        if ($decision['status'] === SessionInvalidation::REVOKED) {
            $loginToken = $session->get('login_token');
            UserAuthState::clearSessionTrackingIfOwned(
                $userId,
                is_string($loginToken) ? $loginToken : null
            );
            $session->destroy();

            $message = match ($decision['reason']) {
                SessionInvalidation::REASON_INACTIVE      => 'Akun Anda telah dinonaktifkan.',
                SessionInvalidation::REASON_GRACE_EXPIRED => 'Masa berlaku sesi telah berakhir karena informasi akun diperbarui. Silakan login kembali.',
                default                                   => 'Informasi akun atau hak akses telah diperbarui. Silakan login kembali.',
            };

            return $this->deny($request, $message);
        }

        // Jaga snapshot is_active dan role di sesi tetap sinkron dengan state
        // yang terverifikasi.
        if ((int) $session->get('is_active') !== (int) $state['is_active']) {
            $session->set('is_active', (int) $state['is_active']);
        }
        if ((string) $session->get('role') !== (string) $state['role']) {
            $session->set('role', (string) $state['role']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tidak ada pemrosesan pasca-respons.
    }

    private function deny(RequestInterface $request, string $message): ResponseInterface
    {
        $path      = ltrim($request->getPath(), '/');
        $wantsJson = $request->isAJAX()
            || str_starts_with($path, 'api/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'kiosk-bundle';

        if ($wantsJson) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'  => 'error',
                    'action'  => 'logout',
                    'message' => $message,
                ]);
        }

        return redirect()->to('/login')->with('error', $message);
    }
}

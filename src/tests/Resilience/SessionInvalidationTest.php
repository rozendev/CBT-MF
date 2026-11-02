<?php

namespace Tests\Resilience;

use App\Libraries\SessionInvalidation;
use PHPUnit\Framework\TestCase;

/**
 * Aturan murni pencabutan sesi akibat perubahan kredensial, role, atau status
 * aktif akun. Diuji tanpa Redis maupun database agar setiap cabang masa
 * tenggang siswa vs pencabutan seketika admin/guru terverifikasi deterministik.
 */
class SessionInvalidationTest extends TestCase
{
    public function testPerubahanProfilSajaTidakMemicuPencabutan(): void
    {
        $siswa = (object) [
            'username'  => 'siswa01',
            'role'      => 'siswa',
            'is_active' => 1,
        ];

        $this->assertSame(
            SessionInvalidation::CHANGE_NONE,
            SessionInvalidation::classifyUserUpdate($siswa, [
                'username'  => 'siswa01',
                'firstname' => 'Budi Baru',
                'lastname'  => 'Santoso',
                'email'     => 'budi@sekolah.sch.id',
                'role'      => 'siswa',
                'is_active' => 1,
            ])
        );
    }

    public function testAdminAtauGuruGantiKredensialLangsungDicabutTanpaGrace(): void
    {
        foreach (['admin', 'guru', 'proctor'] as $role) {
            $user = (object) [
                'username'  => 'staf01',
                'role'      => $role,
                'is_active' => 1,
            ];

            $this->assertSame(
                SessionInvalidation::CHANGE_IMMEDIATE_REVOKE,
                SessionInvalidation::classifyUserUpdate($user, [
                    'username'  => 'staf01_baru',
                    'role'      => $role,
                    'is_active' => 1,
                ]),
                "Perubahan username pada role {$role} wajib langsung mencabut sesi."
            );

            $this->assertSame(
                SessionInvalidation::CHANGE_IMMEDIATE_REVOKE,
                SessionInvalidation::classifyUserUpdate($user, [
                    'username'  => 'staf01',
                    'password'  => 'SandiBaru123',
                    'role'      => $role,
                    'is_active' => 1,
                ]),
                "Perubahan password pada role {$role} wajib langsung mencabut sesi."
            );
        }
    }

    public function testSiswaAktifGantiUsernameAtauPasswordMendapatGrace(): void
    {
        $siswa = (object) [
            'username'  => 'siswa01',
            'role'      => 'siswa',
            'is_active' => 1,
        ];

        $this->assertSame(
            SessionInvalidation::CHANGE_STUDENT_GRACE,
            SessionInvalidation::classifyUserUpdate($siswa, [
                'username'  => 'siswa01_baru',
                'role'      => 'siswa',
                'is_active' => 1,
            ])
        );

        $this->assertSame(
            SessionInvalidation::CHANGE_STUDENT_GRACE,
            SessionInvalidation::classifyUserUpdate($siswa, [
                'username'  => 'siswa01',
                'password'  => 'SandiSiswaBaru',
                'role'      => 'siswa',
                'is_active' => 1,
            ])
        );
    }

    public function testPerubahanRoleAtauPenonaktifanMenangAtasGraceSiswa(): void
    {
        $siswa = (object) [
            'username'  => 'siswa01',
            'role'      => 'siswa',
            'is_active' => 1,
        ];

        // Siswa dinonaktifkan sambil password diganti -> harus langsung putus, bukan grace 10 menit.
        $this->assertSame(
            SessionInvalidation::CHANGE_IMMEDIATE_REVOKE,
            SessionInvalidation::classifyUserUpdate($siswa, [
                'username'  => 'siswa01',
                'password'  => 'SandiBaru',
                'role'      => 'siswa',
                'is_active' => 0,
            ])
        );

        // Guru diturunkan jadi siswa sambil password diganti -> wajib langsung putus!
        $guru = (object) [
            'username'  => 'guru01',
            'role'      => 'guru',
            'is_active' => 1,
        ];
        $this->assertSame(
            SessionInvalidation::CHANGE_IMMEDIATE_REVOKE,
            SessionInvalidation::classifyUserUpdate($guru, [
                'username'  => 'guru01',
                'password'  => 'SandiBaru',
                'role'      => 'siswa',
                'is_active' => 1,
            ])
        );
    }

    public function testReaktivasiAkunTanpaPerubahanKredensialHanyaMenyegarkanCache(): void
    {
        $nonaktif = (object) [
            'username'  => 'siswa01',
            'role'      => 'siswa',
            'is_active' => 0,
        ];

        $this->assertSame(
            SessionInvalidation::CHANGE_REFRESH_ONLY,
            SessionInvalidation::classifyUserUpdate($nonaktif, [
                'username'  => 'siswa01',
                'role'      => 'siswa',
                'is_active' => 1,
            ])
        );
    }

    public function testSesiLegacyTanpaVersiDiperlakukanSebagaiVersiSatu(): void
    {
        $state = [
            'auth_version'        => 1,
            'cred_version'        => 1,
            'cred_grace_until_ts' => null,
            'is_active'           => 1,
            'role'                => 'admin',
        ];

        $res = SessionInvalidation::evaluate(null, null, $state, 1_700_000_000);
        $this->assertSame(SessionInvalidation::VALID, $res['status']);
    }

    public function testSesiAdminLangsungDitolakSaatAuthVersionNaik(): void
    {
        $state = [
            'auth_version'        => 2,
            'cred_version'        => 1,
            'cred_grace_until_ts' => null,
            'is_active'           => 1,
            'role'                => 'admin',
        ];

        $res = SessionInvalidation::evaluate(1, 1, $state, 1_700_000_000);
        $this->assertSame(SessionInvalidation::REVOKED, $res['status']);
        $this->assertSame(SessionInvalidation::REASON_REVOKED, $res['reason']);
    }

    public function testSesiSiswaMasukMasaTenggangSepuluhMenitLaluKedaluwarsaTepatWaktu(): void
    {
        $changedAt = 1_700_000_000;
        $graceUntil = $changedAt + SessionInvalidation::STUDENT_GRACE_SECONDS;

        $state = [
            'auth_version'        => 1,
            'cred_version'        => 2,
            'cred_grace_until_ts' => $graceUntil,
            'is_active'           => 1,
            'role'                => 'siswa',
        ];

        // Detik ke-599: masih dalam masa tenggang
        $inGrace = SessionInvalidation::evaluate(1, 1, $state, $changedAt + 599);
        $this->assertSame(SessionInvalidation::GRACE, $inGrace['status']);
        $this->assertSame(1, $inGrace['remaining_seconds']);

        // Tepat detik ke-600: masa tenggang habis -> REVOKED
        $expired = SessionInvalidation::evaluate(1, 1, $state, $graceUntil);
        $this->assertSame(SessionInvalidation::REVOKED, $expired['status']);
        $this->assertSame(SessionInvalidation::REASON_GRACE_EXPIRED, $expired['reason']);
    }

    public function testSesiBaruSiswaSetelahLoginUlangTidakIkutMatiSaatGraceHabis(): void
    {
        $changedAt = 1_700_000_000;
        $graceUntil = $changedAt + SessionInvalidation::STUDENT_GRACE_SECONDS;

        $state = [
            'auth_version'        => 1,
            'cred_version'        => 2,
            'cred_grace_until_ts' => $graceUntil,
            'is_active'           => 1,
            'role'                => 'siswa',
        ];

        // Siswa sudah login ulang di menit ke-2 membawa cred_version = 2;
        // setelah menit ke-10 lewat, sesi barunya tetap VALID.
        $afterGrace = SessionInvalidation::evaluate(1, 2, $state, $graceUntil + 300);
        $this->assertSame(SessionInvalidation::VALID, $afterGrace['status']);
    }

    public function testSesiPraBanTidakPernahMendapatGraceWalauPasswordDigantiSetelahUnban(): void
    {
        // Ban menaikkan auth_version 1 -> 2; setelah di-release lalu password diganti,
        // cred_version naik 1 -> 2 dengan grace_until aktif.
        $state = [
            'auth_version'        => 2,
            'cred_version'        => 2,
            'cred_grace_until_ts' => 1_700_000_600,
            'is_active'           => 1,
            'role'                => 'siswa',
        ];

        // Sesi dari sebelum ban masih memegang auth_version = 1 -> wajib REVOKED seketika.
        $preBanSession = SessionInvalidation::evaluate(1, 1, $state, 1_700_000_100);
        $this->assertSame(SessionInvalidation::REVOKED, $preBanSession['status']);
        $this->assertSame(SessionInvalidation::REASON_REVOKED, $preBanSession['reason']);
    }

    public function testPayloadWebSocketCredUpdatedMemuatKoordinatWaktuServer(): void
    {
        $payload = SessionInvalidation::buildCredUpdatedPayload(42, 3, 1_700_000_600, 1_700_000_000);

        $this->assertSame('cred_updated', $payload['event']);
        $this->assertSame(42, $payload['user_id']);
        $this->assertSame(3, $payload['cred_version']);
        $this->assertSame(1_700_000_600_000, $payload['grace_until_ms']);
        $this->assertSame(1_700_000_000_000, $payload['server_time_ms']);
        $this->assertSame(600, $payload['ttl_seconds']);
        $this->assertNotEmpty($payload['message']);
    }
}

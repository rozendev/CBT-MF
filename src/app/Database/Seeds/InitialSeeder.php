<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class InitialSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Default admin user
        $this->db->table('users')->insert([
            'username'   => 'admin',
            'email'      => 'admin@sekolah.sch.id',
            'password'   => password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 8]),
            'role'       => 'admin',
            'firstname'  => 'Administrator',
            'lastname'   => 'Sistem',
            'is_active'  => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Default group
        $this->db->table('groups')->insert([
            'name'        => 'Default',
            'description' => 'Grup default untuk semua pengguna',
            'is_active'   => 1,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // 3. Assign admin to default group
        $this->db->table('user_groups')->insert([
            'user_id'    => 1,
            'group_id'   => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 4. Default module
        $this->db->table('modules')->insert([
            'name'       => 'Default',
            'is_enabled' => 1,
            'user_id'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // 5. Default settings
        $settings = [
            ['key' => 'app_name',            'value' => 'Sistem Ujian',    'type' => 'string',  'group' => 'general',  'description' => 'Nama aplikasi'],
            ['key' => 'app_version',         'value' => '1.0.0',           'type' => 'string',  'group' => 'general',  'description' => 'Versi aplikasi'],
            ['key' => 'institution_name',    'value' => 'Sekolah',         'type' => 'string',  'group' => 'general',  'description' => 'Nama institusi'],
            ['key' => 'timezone',            'value' => 'Asia/Jakarta',    'type' => 'string',  'group' => 'general',  'description' => 'Zona waktu'],
            ['key' => 'enable_multi_login',  'value' => '0',               'type' => 'boolean', 'group' => 'security', 'description' => 'Izinkan login dari beberapa perangkat'],
            ['key' => 'max_login_attempts',  'value' => '5',               'type' => 'integer', 'group' => 'security', 'description' => 'Maksimal percobaan login sebelum dikunci'],
            ['key' => 'lockout_duration',    'value' => '15',              'type' => 'integer', 'group' => 'security', 'description' => 'Durasi penguncian akun (menit)'],
            ['key' => 'login_ip_max_attempts', 'value' => '50',            'type' => 'integer', 'group' => 'security', 'description' => 'Maksimal percobaan login gagal per IP dalam 15 menit sebelum diblokir sementara'],
            ['key' => 'allow_registration',  'value' => '0',               'type' => 'boolean', 'group' => 'security', 'description' => 'Izinkan registrasi mandiri'],
            ['key' => 'default_role',        'value' => 'siswa',           'type' => 'string',  'group' => 'security', 'description' => 'Role default untuk pengguna baru'],
            ['key' => 'anti_cheat_enabled',  'value' => '1',               'type' => 'boolean', 'group' => 'security', 'description' => 'Aktifkan Anti-Cheat Sederhana'],
            ['key' => 'suspend_timer_seconds', 'value' => '30',            'type' => 'integer', 'group' => 'security', 'description' => 'Durasi suspend sementara (detik)'],
            ['key' => 'max_cheat_strikes',   'value' => '2',               'type' => 'integer', 'group' => 'security', 'description' => 'Toleransi pelanggaran sebelum blokir permanen'],
            ['key' => 'primary_color',       'value' => '#0d6efd',         'type' => 'string',  'group' => 'logo',     'description' => 'Warna Utama'],
            ['key' => 'secondary_color',     'value' => '#f4f6f9',         'type' => 'string',  'group' => 'logo',     'description' => 'Warna Sekunder'],
            ['key' => 'navbar_color',        'value' => '#ffffff',         'type' => 'string',  'group' => 'logo',     'description' => 'Warna Navbar'],
            ['key' => 'text_color',          'value' => '#212529',         'type' => 'string',  'group' => 'logo',     'description' => 'Warna Teks'],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($settings as $setting) {
            $setting['created_at'] = $now;
            $setting['updated_at'] = $now;
            $this->db->table('settings')->insert($setting);
        }
    }
}

<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class SettingController extends BaseController
{
    protected SettingModel $settingModel;

    public function __construct()
    {
        $this->settingModel = new SettingModel();
    }

    public function index()
    {
        $groupedSettings = $this->settingModel->getGroupedSettings();
        
        // Ensure default structure if empty
        if (empty($groupedSettings)) {
            $groupedSettings = [
                'website' => [],
                'logo' => [],
                'security' => [],
            ];
        }

        return view('admin/settings/index', ['groupedSettings' => $groupedSettings]);
    }

    public function update()
    {
        $settings = $this->request->getPost('settings');
        
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                // Handle Special .env configuration
                if ($key === 'installer_locked') {
                    $this->updateEnv('INSTALLER_LOCKED', $value === '1' ? 'true' : 'false');
                    continue;
                }

                // If it's a checkbox, it might not be sent if unchecked, so we handle boolean types manually later.
                // However, for this simple array, we just update what is sent.
                $existing = $this->settingModel->where('key', $key)->first();
                if ($existing) {
                    // Checkbox handling (if value is string 'on' or '1')
                    if ($existing['type'] === 'boolean' || $key === 'prevent_multi_login') {
                        $value = ($value === 'on' || $value === '1') ? '1' : '0';
                    }
                    $updateData = ['value' => $value];
                    
                    // Auto-heal misplaced settings from previous bugs
                    if ($key === 'prevent_multi_login') {
                        $updateData['group'] = 'security';
                        $updateData['type'] = 'boolean';
                    }
                    if (strpos($key, 'color') !== false) {
                        $updateData['group'] = 'logo';
                    }
                    
                    $this->settingModel->update($existing['id'], $updateData);
                } else {
                    $group = (strpos($key, 'color') !== false || $key === 'app_logo') ? 'logo' : 'general';
                    if (strpos($key, 'anti_cheat') !== false || strpos($key, 'suspend_timer') !== false || strpos($key, 'max_cheat') !== false || strpos($key, 'multi_login') !== false || strpos($key, 'concurrent') !== false || strpos($key, 'queue') !== false) {
                        $group = 'security';
                    }
                    // Force boolean type for checkboxes
                    $type = in_array($key, $booleans ?? ['anti_cheat_enabled', 'allow_registration', 'prevent_multi_login', 'anti_cheat_force_logout']) ? 'boolean' : 'string';
                    $this->settingModel->setValue($key, $value, $type, $group);
                }
            }
        }
        
        // Handle unchecked booleans that are missing from POST payload
        // E.g., anti_cheat_enabled
        $booleans = ['anti_cheat_enabled', 'allow_registration', 'prevent_multi_login', 'anti_cheat_force_logout'];
        foreach ($booleans as $boolKey) {
            if (!isset($settings[$boolKey])) {
                $existing = $this->settingModel->where('key', $boolKey)->first();
                if ($existing) {
                    $this->settingModel->update($existing['id'], ['value' => '0']);
                }
            }
        }

        // Handle unchecked installer_locked
        if (!isset($settings['installer_locked'])) {
            $this->updateEnv('INSTALLER_LOCKED', 'false');
        }

        // Handle File Upload for Logo (if any)
        $logoFile = $this->request->getFile('app_logo');
        if ($logoFile && $logoFile->isValid() && !$logoFile->hasMoved()) {
            // Validate file to prevent RCE
            $rules = [
                'app_logo' => 'is_image[app_logo]|ext_in[app_logo,png,jpg,jpeg]|max_size[app_logo,2048]'
            ];
            
            if ($this->validate($rules)) {
                $newName = $logoFile->getRandomName();
                $logoFile->move(FCPATH . 'uploads', $newName);
                $this->settingModel->setValue('app_logo', 'uploads/' . $newName, 'string', 'logo');
            } else {
                return redirect()->back()->with('error', 'Format logo tidak valid. Harus berupa gambar (PNG/JPG) maksimal 2MB.');
            }
        }

        $bgFile = $this->request->getFile('login_background');
        if ($bgFile && $bgFile->isValid() && !$bgFile->hasMoved()) {
            $rulesBg = [
                'login_background' => 'is_image[login_background]|ext_in[login_background,png,jpg,jpeg]|max_size[login_background,5120]'
            ];
            
            if ($this->validate($rulesBg)) {
                $newName = $bgFile->getRandomName();
                $bgFile->move(FCPATH . 'uploads', $newName);
                $this->settingModel->setValue('login_background', 'uploads/' . $newName, 'string', 'logo');
            } else {
                return redirect()->back()->with('error', 'Format background tidak valid. Harus berupa gambar maksimal 5MB.');
            }
        }

        $cheatLogoFile = $this->request->getFile('anti_cheat_logo');
        if ($cheatLogoFile && $cheatLogoFile->isValid() && !$cheatLogoFile->hasMoved()) {
            $rulesCheat = [
                'anti_cheat_logo' => 'ext_in[anti_cheat_logo,svg]|max_size[anti_cheat_logo,1024]'
            ];
            
            if ($this->validate($rulesCheat)) {
                $newName = $cheatLogoFile->getRandomName();
                $cheatLogoFile->move(FCPATH . 'uploads', $newName);
                $this->settingModel->setValue('anti_cheat_logo', 'uploads/' . $newName, 'string', 'security');
            } else {
                return redirect()->back()->with('error', 'Format logo peringatan tidak valid. Harus berupa SVG maksimal 1MB.');
            }
        }

        return redirect()->to('/admin/settings')->with('success', 'Pengaturan berhasil diperbarui.');
    }

    private function updateEnv($key, $value)
    {
        $path = FCPATH . '../.env';
        if (file_exists($path)) {
            if (!is_writable($path)) {
                session()->setFlashdata('warning', "Pengaturan berhasil disimpan, tetapi gagal memperbarui $key di .env karena masalah permission. Pastikan file .env memiliki hak akses tulis (writable) oleh web server.");
                return;
            }
            $contents = file_get_contents($path);
            if (preg_match("/^{$key}\s*=/m", $contents)) {
                $contents = preg_replace("/^{$key}\s*=.*/m", "{$key} = {$value}", $contents);
            } else {
                $contents .= "\n{$key} = {$value}\n";
            }
            if (@file_put_contents($path, $contents) === false) {
                session()->setFlashdata('warning', "Gagal menulis ke file .env. Silakan ubah $key = $value secara manual di file .env.");
            }
        }
    }
}

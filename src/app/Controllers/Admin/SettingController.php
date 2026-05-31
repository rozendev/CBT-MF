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
                // If it's a checkbox, it might not be sent if unchecked, so we handle boolean types manually later.
                // However, for this simple array, we just update what is sent.
                $existing = $this->settingModel->where('key', $key)->first();
                if ($existing) {
                    // Checkbox handling (if value is string 'on' or '1')
                    if ($existing['type'] === 'boolean') {
                        $value = ($value === 'on' || $value === '1') ? '1' : '0';
                    }
                    $this->settingModel->update($existing['id'], ['value' => $value]);
                }
            }
        }
        
        // Handle unchecked booleans that are missing from POST payload
        // E.g., anti_cheat_enabled
        $booleans = ['anti_cheat_enabled', 'allow_registration', 'enable_multi_login'];
        foreach ($booleans as $boolKey) {
            if (!isset($settings[$boolKey])) {
                $existing = $this->settingModel->where('key', $boolKey)->first();
                if ($existing) {
                    $this->settingModel->update($existing['id'], ['value' => '0']);
                }
            }
        }

        // Handle File Upload for Logo (if any)
        $logoFile = $this->request->getFile('app_logo');
        if ($logoFile && $logoFile->isValid() && !$logoFile->hasMoved()) {
            $newName = $logoFile->getRandomName();
            $logoFile->move(FCPATH . 'uploads', $newName);
            $this->settingModel->setValue('app_logo', 'uploads/' . $newName, 'string', 'logo');
        }

        return redirect()->to('/admin/settings')->with('success', 'Pengaturan berhasil diperbarui.');
    }
}

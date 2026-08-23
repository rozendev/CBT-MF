<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SettingModel;
use App\Models\ActivityLogModel;

class KioskSettingsController extends BaseController
{
    protected SettingModel $settingModel;
    protected ActivityLogModel $activityLog;

    private const BOOLEAN_KEYS = [
        'kiosk_siren_enabled',
        'kiosk_siren_max_volume',
        'kiosk_enforce_home_launcher',
        'kiosk_block_clipboard',
        'kiosk_overlay_guard_enabled',
    ];

    private const KEY_META = [
        'kiosk_exit_password'        => ['group' => 'kiosk', 'type' => 'string'],
        'kiosk_siren_enabled'        => ['group' => 'kiosk', 'type' => 'boolean'],
        'kiosk_siren_max_volume'     => ['group' => 'kiosk', 'type' => 'boolean'],
        'kiosk_enforce_home_launcher' => ['group' => 'kiosk', 'type' => 'boolean'],
        'kiosk_block_clipboard'      => ['group' => 'kiosk', 'type' => 'boolean'],
        'kiosk_overlay_guard_enabled' => ['group' => 'kiosk', 'type' => 'boolean'],
        'kiosk_min_app_version'       => ['group' => 'kiosk', 'type' => 'string'],
        'kiosk_root_strictness'        => ['group' => 'kiosk', 'type' => 'string'],
    ];

    public function __construct()
    {
        $this->settingModel = new SettingModel();
        $this->activityLog = new ActivityLogModel();
    }

    public function index()
    {
        $groupedSettings = $this->settingModel->getGroupedSettings();
        $kioskSettings   = $groupedSettings['kiosk'] ?? [];

        return view('admin/kiosk/index', [
            'kioskSettings' => $kioskSettings
        ]);
    }

    public function update()
    {
        $postedSettings = $this->request->getPost('settings') ?? [];

        // Handle boolean keys (checkbox switches)
        foreach (self::BOOLEAN_KEYS as $boolKey) {
            if (!isset($postedSettings[$boolKey])) {
                $postedSettings[$boolKey] = '0';
            }
        }

        foreach ($postedSettings as $key => $val) {
            if (array_key_exists($key, self::KEY_META)) {
                $meta = self::KEY_META[$key];
                $this->settingModel->setValue($key, (string) $val, $meta['type'], $meta['group']);
            }
        }

        $this->activityLog->log('update', session('user_id'), 'setting', 0, 'Memperbarui pengaturan Aplikasi Kiosk Android');

        return redirect()->to('/admin/kiosk')->with('success', 'Pengaturan Integrasi EXAMBRO berhasil disimpan.');
    }
}

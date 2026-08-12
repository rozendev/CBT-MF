<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class KioskController extends BaseController
{
    public function config()
    {
        $settingModel = new SettingModel();

        return $this->response->setJSON([
            'school_name'     => $settingModel->getValue('app_name', 'CBT-MF Kiosk System'),
            'exam_url'        => base_url('student/dashboard'),
            'min_app_version' => $settingModel->getValue('kiosk_min_app_version', '1.0.0'),
            'exit_password'   => $settingModel->getValue('kiosk_exit_password', '123456'),
            'features'        => [
                'siren_enabled'             => (bool) $settingModel->getValue('kiosk_siren_enabled', true),
                'siren_max_volume'          => (bool) $settingModel->getValue('kiosk_siren_max_volume', true),
                'enforce_home_launcher'     => (bool) $settingModel->getValue('kiosk_enforce_home_launcher', true),
                'block_clipboard'          => (bool) $settingModel->getValue('kiosk_block_clipboard', true),
                'root_detection_strictness' => $settingModel->getValue('kiosk_root_strictness', 'warning'),
                'overlay_guard_enabled'     => (bool) $settingModel->getValue('kiosk_overlay_guard_enabled', true),
            ]
        ]);
    }
}

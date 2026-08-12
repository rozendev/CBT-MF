<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class KioskController extends BaseController
{
    public function config()
    {
        $settingModel = new SettingModel();

        $appName        = $settingModel->getValue('app_name', 'CBT-MF Kiosk System');
        $minVersion     = $settingModel->getValue('kiosk_min_app_version', '1.0.0');
        $enforceHome    = $settingModel->getValue('kiosk_enforce_home_launcher', true);
        $blockClipboard = $settingModel->getValue('kiosk_block_clipboard', true);
        $rootStrictness = $settingModel->getValue('kiosk_root_strictness', 'warning');
        $overlayGuard   = $settingModel->getValue('kiosk_overlay_guard_enabled', true);

        return $this->response->setJSON([
            'school_name'     => $appName,
            'exam_url'        => base_url('student/dashboard'),
            'min_app_version' => $minVersion,
            'features'        => [
                'enforce_home_launcher'     => (bool) $enforceHome,
                'block_clipboard'          => (bool) $blockClipboard,
                'root_detection_strictness' => $rootStrictness,
                'overlay_guard_enabled'     => (bool) $overlayGuard,
            ]
        ]);
    }
}

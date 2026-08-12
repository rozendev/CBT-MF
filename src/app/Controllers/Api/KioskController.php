<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class KioskController extends BaseController
{
    public function config()
    {
        return $this->response->setJSON([
            'school_name' => 'CBT-MF Kiosk System',
            'exam_url'    => base_url('student/dashboard'),
            'min_app_version' => '1.0.0',
            'features' => [
                'enforce_home_launcher' => true,
                'block_clipboard'      => true,
                'root_detection_strictness' => 'warning',
                'overlay_guard_enabled' => true
            ]
        ]);
    }
}

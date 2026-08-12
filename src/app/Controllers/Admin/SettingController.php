<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SettingModel;

class SettingController extends BaseController
{
    protected SettingModel $settingModel;

    private const BOOLEAN_KEYS = [
        'anti_cheat_enabled', 'prevent_multi_login', 'anti_cheat_force_logout',
        'allow_registration', 'auto_submit', 'mcma_partial_score',
        'show_score_after_exam', 'show_correct_answers', 'allow_review',
        'maintenance_mode', 'default_random_questions', 'default_random_answers',
        'kiosk_siren_enabled', 'kiosk_siren_max_volume',
        'kiosk_enforce_home_launcher', 'kiosk_block_clipboard', 'kiosk_overlay_guard_enabled',
    ];

    private const INTEGER_KEYS = [
        'max_cheat_strikes', 'suspend_timer_seconds', 'max_concurrent_connections',
        'max_login_attempts', 'lockout_duration',
        'default_duration', 'default_passing_grade',
        'default_score_right', 'default_score_wrong', 'default_score_unanswered',
    ];

    private const KEY_META = [
        'app_name'                  => ['group' => 'general',  'type' => 'string'],
        'app_description'           => ['group' => 'general',  'type' => 'string'],
        'site_author'               => ['group' => 'general',  'type' => 'string'],
        'timezone'                  => ['group' => 'general',  'type' => 'string'],
        'app_logo'                  => ['group' => 'logo',     'type' => 'string'],
        'app_favicon'               => ['group' => 'logo',     'type' => 'string'],
        'login_background'          => ['group' => 'logo',     'type' => 'string'],
        'font_family'               => ['group' => 'logo',     'type' => 'string'],
        'border_radius'             => ['group' => 'logo',     'type' => 'string'],
        'primary_color'             => ['group' => 'logo',     'type' => 'string'],
        'secondary_color'           => ['group' => 'logo',     'type' => 'string'],
        'text_color'                => ['group' => 'logo',     'type' => 'string'],
        'anti_cheat_enabled'        => ['group' => 'security', 'type' => 'boolean'],
        'prevent_multi_login'       => ['group' => 'security', 'type' => 'boolean'],
        'anti_cheat_force_logout'   => ['group' => 'security', 'type' => 'boolean'],
        'anti_cheat_title'          => ['group' => 'security', 'type' => 'string'],
        'anti_cheat_message'        => ['group' => 'security', 'type' => 'string'],
        'anti_cheat_logo'           => ['group' => 'security', 'type' => 'string'],
        'max_cheat_strikes'         => ['group' => 'security', 'type' => 'integer'],
        'suspend_timer_seconds'     => ['group' => 'security', 'type' => 'integer'],
        'max_concurrent_connections'=> ['group' => 'security', 'type' => 'integer'],
        'queue_waiting_message'     => ['group' => 'security', 'type' => 'string'],
        'maintenance_mode'          => ['group' => 'security', 'type' => 'boolean'],
        'maintenance_message'       => ['group' => 'security', 'type' => 'string'],
        'allow_registration'        => ['group' => 'security', 'type' => 'boolean'],
        'default_duration'          => ['group' => 'exam',     'type' => 'integer'],
        'default_passing_grade'     => ['group' => 'exam',     'type' => 'integer'],
        'default_score_right'       => ['group' => 'exam',     'type' => 'integer'],
        'default_score_wrong'       => ['group' => 'exam',     'type' => 'integer'],
        'default_score_unanswered'  => ['group' => 'exam',     'type' => 'integer'],
        'auto_submit'               => ['group' => 'exam',     'type' => 'boolean'],
        'mcma_partial_score'        => ['group' => 'exam',     'type' => 'boolean'],
        'default_random_questions'  => ['group' => 'exam',     'type' => 'boolean'],
        'default_random_answers'    => ['group' => 'exam',     'type' => 'boolean'],
        'show_score_after_exam'     => ['group' => 'exam',     'type' => 'boolean'],
        'show_correct_answers'     => ['group' => 'exam',     'type' => 'boolean'],
        'allow_review'              => ['group' => 'exam',     'type' => 'boolean'],
        'kiosk_exit_password'        => ['group' => 'kiosk',  'type' => 'string'],
        'kiosk_siren_enabled'        => ['group' => 'kiosk',  'type' => 'boolean'],
        'kiosk_siren_max_volume'     => ['group' => 'kiosk',  'type' => 'boolean'],
        'kiosk_enforce_home_launcher' => ['group' => 'kiosk',  'type' => 'boolean'],
        'kiosk_block_clipboard'      => ['group' => 'kiosk',  'type' => 'boolean'],
        'kiosk_overlay_guard_enabled' => ['group' => 'kiosk',  'type' => 'boolean'],
        'kiosk_min_app_version'       => ['group' => 'kiosk',  'type' => 'string'],
        'kiosk_root_strictness'        => ['group' => 'kiosk',  'type' => 'string'],
    ];

    public function __construct()
    {
        $this->settingModel = new SettingModel();
    }

    public function index()
    {
        $groupedSettings = $this->settingModel->getGroupedSettings();

        foreach (['general', 'logo', 'security', 'exam', 'kiosk'] as $g) {
            if (!isset($groupedSettings[$g])) {
                $groupedSettings[$g] = [];
            }
        }

        return view('admin/settings/index', ['groupedSettings' => $groupedSettings]);
    }

    public function update()
    {
        $settings = $this->request->getPost('settings');

        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                if ($key === 'installer_locked') {
                    $this->updateEnv('INSTALLER_LOCKED', $value === '1' ? 'true' : 'false');
                    continue;
                }

                $meta = self::KEY_META[$key] ?? null;
                $type = $meta['type'] ?? 'string';
                $group = $meta['group'] ?? 'general';

                if ($type === 'boolean') {
                    $value = ($value === 'on' || $value === '1') ? '1' : '0';
                }

                $existing = $this->settingModel->where('key', $key)->first();
                if ($existing) {
                    $this->settingModel->update($existing['id'], [
                        'value' => $value,
                        'type'  => $type,
                        'group' => $group,
                    ]);
                } else {
                    $this->settingModel->setValue($key, $value, $type, $group);
                }
            }
        }

        foreach (self::BOOLEAN_KEYS as $boolKey) {
            if (!isset($settings[$boolKey])) {
                $existing = $this->settingModel->where('key', $boolKey)->first();
                if ($existing) {
                    $this->settingModel->update($existing['id'], ['value' => '0']);
                }
            }
        }

        if (!isset($settings['installer_locked'])) {
            $this->updateEnv('INSTALLER_LOCKED', 'false');
        }

        $this->handleFileUpload('app_logo', 'logo', 'is_image[app_logo]|ext_in[app_logo,png,jpg,jpeg,svg]|max_size[app_logo,2048]', 'Format logo tidak valid. Harus berupa gambar (PNG/JPG) maksimal 2MB.');
        $this->handleFileUpload('app_favicon', 'logo', 'is_image[app_favicon]|ext_in[app_favicon,png,jpg,jpeg,ico,svg]|max_size[app_favicon,2048]', 'Format favicon tidak valid. Harus berupa gambar/ico (PNG/JPG/ICO/SVG) maksimal 2MB.');
        $this->handleFileUpload('login_background', 'logo', 'is_image[login_background]|ext_in[login_background,png,jpg,jpeg]|max_size[login_background,5120]', 'Format background tidak valid. Harus berupa gambar maksimal 5MB.');
        $this->handleFileUpload('anti_cheat_logo', 'security', 'ext_in[anti_cheat_logo,svg]|max_size[anti_cheat_logo,1024]', 'Format logo peringatan tidak valid. Harus berupa SVG maksimal 1MB.');

        return redirect()->to('/admin/settings')->with('success', 'Pengaturan berhasil diperbarui.');
    }

    public function getSystemInfo()
    {
        $dbConnected = false;
        try {
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            $dbConnected = true;
        } catch (\Exception $e) {
            // DB unreachable
        }

        $redisConnected = false;
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->ping();
                $redisConnected = true;
            }
        } catch (\Exception $e) {
            // Redis unreachable
        }

        $activeSessions = 0;
        if ($redisConnected) {
            try {
                $keys = $redis->keys('ci_session:*');
                $activeSessions = is_array($keys) ? count($keys) : 0;
            } catch (\Exception $e) {
                $activeSessions = '?';
            }
        } else {
            try {
                $db = \Config\Database::connect();
                if ($dbConnected) {
                    $activeSessions = (int) $db->table('ci_sessions')->countAllResults();
                }
            } catch (\Exception $e) {
                $activeSessions = '?';
            }
        }

        $diskFree = disk_free_space(FCPATH);
        $diskTotal = disk_total_space(FCPATH);
        $diskPercent = $diskTotal > 0 ? round((($diskTotal - $diskFree) / $diskTotal) * 100) : 0;

        return $this->response->setJSON([
            'php_version'      => PHP_VERSION,
            'ci_version'       => \CodeIgniter\CodeIgniter::CI_VERSION,
            'db_connected'     => $dbConnected,
            'redis_connected'  => $redisConnected,
            'active_sessions'  => (string) $activeSessions,
            'disk_usage'       => $diskPercent . '%',
        ]);
    }

    public function clearCache()
    {
        try {
            service('cache')->clean();
        } catch (\Exception $e) {
            // ignore
        }

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->flushDB();
            }
        } catch (\Exception $e) {
            // Redis not available, skip
        }

        return $this->response->setJSON([
            'status'  => 'success',
            'message' => 'Cache berhasil dibersihkan.',
        ]);
    }

    public function resetSettings()
    {
        $db = \Config\Database::connect();
        $db->table('settings')->truncate();

        $defaults = [
            ['key' => 'app_name',            'value' => 'Sistem Ujian',    'type' => 'string',  'group' => 'general'],
            ['key' => 'app_version',         'value' => '1.0.0',           'type' => 'string',  'group' => 'general'],
            ['key' => 'app_description',     'value' => 'Aplikasi Ujian Berbasis Komputer (CBT)', 'type' => 'string', 'group' => 'general'],
            ['key' => 'site_author',         'value' => 'Sekolah/Lembaga', 'type' => 'string',  'group' => 'general'],
            ['key' => 'timezone',            'value' => 'Asia/Jakarta',    'type' => 'string',  'group' => 'general'],
            ['key' => 'primary_color',       'value' => '#0d6efd',         'type' => 'string',  'group' => 'logo'],
            ['key' => 'secondary_color',     'value' => '#f4f6f9',         'type' => 'string',  'group' => 'logo'],
            ['key' => 'text_color',          'value' => '#212529',         'type' => 'string',  'group' => 'logo'],
            ['key' => 'font_family',         'value' => 'Inter',           'type' => 'string',  'group' => 'logo'],
            ['key' => 'border_radius',       'value' => '8',               'type' => 'string',  'group' => 'logo'],
            ['key' => 'anti_cheat_enabled',  'value' => '1',               'type' => 'boolean', 'group' => 'security'],
            ['key' => 'prevent_multi_login',  'value' => '1',              'type' => 'boolean', 'group' => 'security'],
            ['key' => 'max_cheat_strikes',   'value' => '2',               'type' => 'integer', 'group' => 'security'],
            ['key' => 'suspend_timer_seconds','value' => '180',            'type' => 'integer', 'group' => 'security'],
            ['key' => 'max_concurrent_connections', 'value' => '1000',     'type' => 'integer', 'group' => 'security'],
            ['key' => 'default_duration',    'value' => '90',              'type' => 'integer', 'group' => 'exam'],
            ['key' => 'default_passing_grade','value' => '75',             'type' => 'integer', 'group' => 'exam'],
            ['key' => 'default_score_right', 'value' => '1',               'type' => 'integer', 'group' => 'exam'],
            ['key' => 'default_score_wrong', 'value' => '0',               'type' => 'integer', 'group' => 'exam'],
            ['key' => 'default_score_unanswered', 'value' => '0',          'type' => 'integer', 'group' => 'exam'],
            ['key' => 'auto_submit',         'value' => '0',               'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'mcma_partial_score',  'value' => '0',               'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'default_random_questions', 'value' => '1',          'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'default_random_answers', 'value' => '1',            'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'show_score_after_exam','value' => '1',              'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'show_correct_answers', 'value' => '0',              'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'allow_review',        'value' => '1',               'type' => 'boolean', 'group' => 'exam'],
            ['key' => 'maintenance_mode',    'value' => '0',               'type' => 'boolean', 'group' => 'security'],
            ['key' => 'kiosk_exit_password',        'value' => '123456',  'type' => 'string',  'group' => 'kiosk'],
            ['key' => 'kiosk_siren_enabled',        'value' => '1',       'type' => 'boolean', 'group' => 'kiosk'],
            ['key' => 'kiosk_siren_max_volume',     'value' => '1',       'type' => 'boolean', 'group' => 'kiosk'],
            ['key' => 'kiosk_enforce_home_launcher', 'value' => '1',       'type' => 'boolean', 'group' => 'kiosk'],
            ['key' => 'kiosk_block_clipboard',       'value' => '1',       'type' => 'boolean', 'group' => 'kiosk'],
            ['key' => 'kiosk_overlay_guard_enabled', 'value' => '1',       'type' => 'boolean', 'group' => 'kiosk'],
            ['key' => 'kiosk_min_app_version',       'value' => '1.0.0',   'type' => 'string',  'group' => 'kiosk'],
            ['key' => 'kiosk_root_strictness',       'value' => 'warning', 'type' => 'string',  'group' => 'kiosk'],
        ];

        $now = date('Y-m-d H:i:s');
        foreach ($defaults as $d) {
            $d['created_at'] = $now;
            $d['updated_at'] = $now;
            $db->table('settings')->insert($d);
        }

        return $this->response->setJSON([
            'status'  => 'success',
            'message' => 'Semua pengaturan berhasil direset ke default.',
        ]);
    }

    private function handleFileUpload(string $fieldName, string $group, string $rules, string $errorMsg)
    {
        $file = $this->request->getFile($fieldName);
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return;
        }

        if (!$this->validate([$fieldName => $rules])) {
            session()->setFlashdata('error', $errorMsg);
            return;
        }

        $newName = $file->getRandomName();
        $targetPath = FCPATH . 'uploads/' . $newName;
        $file->move(FCPATH . 'uploads', $newName);

        // Sanitize SVG to prevent Stored XSS
        if (strtolower($file->getClientExtension()) === 'svg' || $file->getClientMimeType() === 'image/svg+xml') {
            $this->sanitizeSvg($targetPath);
        }

        $this->settingModel->setValue($fieldName, 'uploads/' . $newName, 'string', $group);
    }

    private function sanitizeSvg(string $filePath)
    {
        if (!file_exists($filePath)) {
            return;
        }
        $content = file_get_contents($filePath);

        // Use DOMDocument for proper XML parsing instead of regex
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($content, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();

        if (!$dom->documentElement) {
            // Invalid SVG — delete entirely
            @unlink($filePath);
            return;
        }

        $xpath = new \DOMXPath($dom);
        
        // Dangerous elements to remove completely
        $dangerousTags = [
            'script', 'foreignObject', 'set', 'animate', 'animateTransform',
            'animateMotion', 'iframe', 'embed', 'object', 'applet',
            'math', 'handler', 'listener',
        ];
        
        foreach ($dangerousTags as $tag) {
            $nodes = $xpath->query('//*[local-name()="' . $tag . '"]');
            foreach ($nodes as $node) {
                $node->parentNode->removeChild($node);
            }
        }
        
        // Remove ALL event handler attributes (on*)
        $allElements = $xpath->query('//*');
        foreach ($allElements as $element) {
            $attrsToRemove = [];
            foreach ($element->attributes as $attr) {
                $attrName = strtolower($attr->localName);
                // Remove event handlers
                if (str_starts_with($attrName, 'on')) {
                    $attrsToRemove[] = $attr->nodeName;
                }
                // Remove javascript:/data: URIs from any href-like attribute
                $val = strtolower(trim(preg_replace('/[\x00-\x1f\x7f]+/', '', $attr->value)));
                if (in_array($attrName, ['href', 'xlink:href', 'src', 'action', 'formaction']) 
                    && (str_starts_with($val, 'javascript:') || str_starts_with($val, 'data:text'))) {
                    $attrsToRemove[] = $attr->nodeName;
                }
            }
            foreach ($attrsToRemove as $name) {
                $element->removeAttribute($name);
            }
        }
        
        // Remove <style> elements (CSS-based XSS via @import, expression(), etc.)
        $styleNodes = $xpath->query('//*[local-name()="style"]');
        foreach ($styleNodes as $node) {
            $node->parentNode->removeChild($node);
        }

        // Remove comments
        $comments = $xpath->query('//comment()');
        foreach ($comments as $comment) {
            $comment->parentNode->removeChild($comment);
        }
        
        file_put_contents($filePath, $dom->saveXML());
    }

    private function updateEnv($key, $value)
    {
        $path = FCPATH . '../.env';
        if (!file_exists($path)) {
            return;
        }
        if (!is_writable($path)) {
            session()->setFlashdata('warning', "Pengaturan berhasil disimpan, tetapi gagal memperbarui $key di .env karena masalah permission.");
            return;
        }
        $contents = file_get_contents($path);
        $quotedKey = preg_quote($key, '/');
        if (preg_match("/^{$quotedKey}\s*=/m", $contents)) {
            $contents = preg_replace("/^{$quotedKey}\s*=.*/m", "{$key} = {$value}", $contents);
        } else {
            $contents .= "\n{$key} = {$value}\n";
        }
        if (@file_put_contents($path, $contents) === false) {
            session()->setFlashdata('warning', "Gagal menulis ke file .env. Silakan ubah $key = $value secara manual.");
        }
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'id';
    protected $allowedFields = ['key', 'value', 'type', 'group', 'description'];
    protected $useTimestamps = true;

    protected $beforeUpdate = ['clearCacheBeforeUpdate'];
    protected $beforeDelete = ['clearCacheBeforeDelete'];
    protected $afterInsert  = ['clearCacheAfterInsert'];

    /**
     * Get a setting value by key
     */
    public function getValue(string $key, $default = null)
    {

        $cache = service('cache');
        $cacheKey = "setting_{$key}";
        $value = $cache->get($cacheKey);

        if ($value === null) {
            $setting = $this->where('key', $key)->first();
            if (!$setting) {
                try {
                    $cache->save($cacheKey, $default, 3600);
                } catch (\Exception $e) {}
                return $default;
            }

            $value = null;
            switch ($setting['type']) {
                case 'integer':
                    $value = (int) $setting['value'];
                    break;
                case 'boolean':
                    $value = filter_var($setting['value'], FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'json':
                    $value = json_decode($setting['value'], true);
                    break;
                default:
                    $value = $setting['value'];
                    break;
            }

            try {
                $cache->save($cacheKey, $value, 3600);
            } catch (\Exception $e) {}
        }

        // Auto-correction for websocket_url if it still uses localhost on a remote server
        if ($key === 'websocket_url' && (empty($value) || strpos($value, 'localhost') !== false)) {
            $parsed = parse_url(base_url());
            $host = $parsed['host'] ?? 'localhost';
            if ($host !== 'localhost' && $host !== '127.0.0.1') {
                $scheme = (isset($parsed['scheme']) && $parsed['scheme'] === 'https') ? 'wss' : 'ws';
                $value = $scheme . '://' . $host . '/ws/';
            }
        }

        return $value;
    }

    /**
     * Update or insert a setting
     */
    public function setValue(string $key, $value, string $type = 'string', string $group = 'general')
    {
        $this->clearCacheByKey($key);

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            $type = 'json';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
            $type = 'boolean';
        }

        $db = \Config\Database::connect();
        $db->transStart();
        
        $existing = $this->where('key', $key)->first();
        if ($existing) {
            $result = $this->update($existing['id'], ['value' => $value]);
        } else {
            $result = $this->insert([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'group' => $group
            ]);
        }
        
        $db->transComplete();
        return $result;
    }

    protected function clearCacheBeforeUpdate(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            if (!empty($ids)) {
                $settings = $this->select('key')->whereIn('id', $ids)->findAll();
                foreach ($settings as $setting) {
                    $this->clearCacheByKey($setting['key']);
                }
            }
        }
        return $data;
    }

    protected function clearCacheBeforeDelete(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            if (!empty($ids)) {
                $settings = $this->select('key')->whereIn('id', $ids)->findAll();
                foreach ($settings as $setting) {
                    $this->clearCacheByKey($setting['key']);
                }
            }
        }
        return $data;
    }

    protected function clearCacheAfterInsert(array $data)
    {
        if (isset($data['data']['key'])) {
            $this->clearCacheByKey($data['data']['key']);
        }
        return $data;
    }

    private function clearCacheByKey(string $key)
    {
        try {
            service('cache')->delete("setting_{$key}");
        } catch (\Exception $e) {
            // Ignore cache delete failures if cache driver is down
        }
    }

    /**
     * Get settings grouped by their group name
     */
    public function getGroupedSettings()
    {
        $settings = $this->findAll();
        $grouped = [];
        foreach ($settings as $setting) {
            $grouped[$setting['group']][$setting['key']] = $setting;
        }
        return $grouped;
    }
}

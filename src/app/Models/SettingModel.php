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
     * Penanda "baris setelan ini memang tidak ada di database".
     *
     * Wajib ada karena cache dipakai bersama oleh pemanggil yang meneruskan
     * default BERBEDA untuk kunci yang sama. Menyimpan default milik pemanggil
     * pertama — seperti yang dilakukan sebelumnya — membuat pemanggil kedua
     * menerima default milik orang lain selama satu jam penuh, jadi perilaku
     * sistem bergantung pada halaman mana yang kebetulan dibuka lebih dulu.
     * Yang boleh di-cache hanyalah fakta "barisnya tidak ada"; defaultnya
     * ditentukan ulang oleh setiap pemanggil.
     */
    private const MISSING = '__cbt_setting_missing__';

    /**
     * Get a setting value by key
     */
    public function getValue(string $key, $default = null)
    {

        $cache = service('cache');
        $cacheKey = "setting_{$key}";
        $value = $cache->get($cacheKey);

        if ($value === self::MISSING) {
            return $default;
        }

        if ($value === null) {
            $setting = $this->where('key', $key)->first();
            if (!$setting) {
                try {
                    $cache->save($cacheKey, self::MISSING, 3600);
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

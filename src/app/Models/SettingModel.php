<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'id';
    protected $allowedFields = ['key', 'value', 'type', 'group', 'description'];
    protected $useTimestamps = true;

    private static array $cache = [];

    /**
     * Get a setting value by key
     */
    public function getValue(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $setting = $this->where('key', $key)->first();
        if (!$setting) {
            self::$cache[$key] = $default;
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

        self::$cache[$key] = $value;
        return $value;
    }

    /**
     * Update or insert a setting
     */
    public function setValue(string $key, $value, string $type = 'string', string $group = 'general')
    {
        if (array_key_exists($key, self::$cache)) {
            unset(self::$cache[$key]);
        }
        try {
            service('cache')->delete("setting_{$key}");
        } catch (\Exception $e) {
            // Ignore cache delete failures if cache driver is down
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            $type = 'json';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
            $type = 'boolean';
        }

        $existing = $this->where('key', $key)->first();
        if ($existing) {
            return $this->update($existing['id'], ['value' => $value]);
        }

        return $this->insert([
            'key' => $key,
            'value' => $value,
            'type' => $type,
            'group' => $group
        ]);
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

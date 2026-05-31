<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'id';
    protected $allowedFields = ['key', 'value', 'type', 'group', 'description'];
    protected $useTimestamps = true;

    /**
     * Get a setting value by key
     */
    public function getValue(string $key, $default = null)
    {
        $setting = $this->where('key', $key)->first();
        if (!$setting) {
            return $default;
        }

        switch ($setting['type']) {
            case 'integer':
                return (int) $setting['value'];
            case 'boolean':
                return filter_var($setting['value'], FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($setting['value'], true);
            default:
                return $setting['value'];
        }
    }

    /**
     * Update or insert a setting
     */
    public function setValue(string $key, $value, string $type = 'string', string $group = 'general')
    {
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

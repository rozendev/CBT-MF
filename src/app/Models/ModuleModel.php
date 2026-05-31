<?php

namespace App\Models;

use CodeIgniter\Model;

class ModuleModel extends Model
{
    protected $table            = 'modules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;

    protected $allowedFields = [
        'name', 'is_enabled', 'user_id'
    ];

    protected $validationRules = [
        'name' => 'required|max_length[255]|is_unique[modules.name,id,{id}]',
    ];

    /**
     * Get module with author details and subject count
     */
    public function getModulesWithDetails()
    {
        return $this->select('modules.*, users.firstname as author_name, COUNT(subjects.id) as subject_count')
                    ->join('users', 'users.id = modules.user_id', 'left')
                    ->join('subjects', 'subjects.module_id = modules.id AND subjects.deleted_at IS NULL', 'left')
                    ->groupBy('modules.id')
                    ->orderBy('modules.name', 'ASC');
    }
}

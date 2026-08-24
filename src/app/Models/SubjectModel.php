<?php

namespace App\Models;

use CodeIgniter\Model;

class SubjectModel extends Model
{
    protected $table            = 'subjects';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;

    protected $allowedFields = [
        'module_id', 'name', 'description', 'is_enabled', 'user_id'
    ];

    protected $validationRules = [
        'module_id' => 'required|is_natural_no_zero',
        'name'      => 'required|max_length[255]',
    ];

    /**
     * Get subjects with module name and author details
     */
    public function getSubjectsWithDetails(?int $moduleId = null)
    {
        $builder = $this->select('subjects.*, modules.name as module_name, users.firstname as author_name')
                        ->join('modules', 'modules.id = subjects.module_id')
                        ->join('users', 'users.id = subjects.user_id', 'left');

        if ($moduleId) {
            $builder->where('subjects.module_id', $moduleId);
        }

        return $builder->orderBy('modules.name', 'ASC')
                       ->orderBy('subjects.name', 'ASC');
    }

    /**
     * Restore and update a soft-deleted subject
     */
    public function reuseDeletedSubject($id, $data)
    {
        $data['deleted_at'] = null;
        return $this->db->table($this->table)->where('id', $id)->update($data);
    }
}

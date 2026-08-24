<?php

namespace App\Models;

use CodeIgniter\Model;

class TopicModel extends Model
{
    protected $table            = 'topics';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'subject_id', 'name', 'description'
    ];

    protected $validationRules = [
        'subject_id' => 'required|is_natural_no_zero',
        'name'       => 'required|max_length[255]',
    ];

    protected $validationMessages = [
        'subject_id' => [
            'required' => 'Subjek wajib dipilih.',
        ],
        'name' => [
            'required' => 'Nama topik wajib diisi.',
        ],
    ];

    /**
     * Get topics with subject and module info, optionally filtered by subject
     */
    public function getTopicsWithDetails(?int $subjectId = null)
    {
        $builder = $this->select('topics.*, subjects.name as subject_name, modules.name as module_name')
                        ->join('subjects', 'subjects.id = topics.subject_id')
                        ->join('modules', 'modules.id = subjects.module_id');

        if ($subjectId) {
            $builder->where('topics.subject_id', $subjectId);
        }

        return $builder->orderBy('subjects.name', 'ASC')
                       ->orderBy('topics.name', 'ASC');
    }

    /**
     * Get active (non-deleted) topics for a subject, for dropdowns
     */
    public function getTopicsBySubject(int $subjectId)
    {
        return $this->where('subject_id', $subjectId)
                    ->orderBy('name', 'ASC')
                    ->findAll();
    }

    /**
     * Restore and update a soft-deleted topic (prevents duplicate key exception)
     */
    public function reuseDeletedTopic($id, $data)
    {
        $data['deleted_at'] = null;
        return $this->db->table($this->table)->where('id', $id)->update($data);
    }
}

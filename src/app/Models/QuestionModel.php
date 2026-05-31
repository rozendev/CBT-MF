<?php

namespace App\Models;

use CodeIgniter\Model;

class QuestionModel extends Model
{
    protected $table            = 'questions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'subject_id', 'description', 'explanation', 'type', 
        'difficulty', 'is_enabled', 'position', 'timer', 
        'is_fullscreen', 'inline_answers', 'auto_next'
    ];

    protected $validationRules = [
        'subject_id'  => 'required|is_natural_no_zero',
        'description' => 'required',
        'type'        => 'required|in_list[1,2,3,4]',
        'difficulty'  => 'required|in_list[1,2,3,4,5,6,7,8,9,10]',
    ];

    protected $validationMessages = [
        'subject_id'  => [
            'required' => 'Subjek wajib dipilih.',
        ],
        'description' => [
            'required' => 'Pertanyaan/Soal wajib diisi.',
        ],
    ];

    /**
     * Get questions with module and subject info
     */
    public function getQuestionsWithDetails(int $subjectId = 0)
    {
        $builder = $this->select('questions.*, subjects.name as subject_name, modules.name as module_name')
                        ->join('subjects', 'subjects.id = questions.subject_id')
                        ->join('modules', 'modules.id = subjects.module_id');
        
        if ($subjectId > 0) {
            $builder->where('questions.subject_id', $subjectId);
        }

        return $builder->orderBy('questions.id', 'DESC');
    }

    /**
     * Delete question completely along with its answers (force delete)
     * Because tests rely on it, we might prefer soft delete.
     * But if we really want to delete it from admin:
     */
    public function forceDeleteWithAnswers(int $id)
    {
        $db = \Config\Database::connect();
        $db->transStart();
        
        // Answers will be deleted via CASCADE FK or we can delete manually
        // But CI4 migrations for answers have CASCADE
        $this->delete($id, true);
        
        $db->transComplete();
        return $db->transStatus();
    }
}

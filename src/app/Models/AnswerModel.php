<?php

namespace App\Models;

use CodeIgniter\Model;

class AnswerModel extends Model
{
    protected $table            = 'answers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false; // Answers are completely deleted when deleted, since they depend on questions
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'question_id', 'description', 'explanation', 'is_correct', 
        'is_enabled', 'position', 'weight'
    ];

    protected $validationRules = [
        'question_id' => 'required|is_natural_no_zero',
        'description' => 'required',
    ];

    /**
     * Get answers for a specific question
     */
    public function getAnswersByQuestion(int $questionId)
    {
        return $this->where('question_id', $questionId)
                    ->orderBy('position', 'ASC')
                    ->orderBy('id', 'ASC')
                    ->findAll();
    }
}

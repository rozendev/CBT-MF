<?php

namespace App\Models;

use CodeIgniter\Model;

class TestModel extends Model
{
    protected $table            = 'tests';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';
    protected $deletedField     = 'deleted_at';

    protected $allowedFields = [
        'name', 'description', 'begin_time', 'end_time', 'duration_minutes', 
        'ip_range', 'password', 'results_visible', 'report_visible', 
        'score_right', 'score_wrong', 'score_unanswered', 'max_score', 
        'passing_score', 'random_questions', 'random_answers', 'show_menu', 
        'allow_noanswer', 'mcma_partial_score', 'is_repeatable', 
        'auto_logout_on_timeout', 'is_enabled', 'user_id'
    ];

    protected $validationRules = [
        'name'             => 'required|max_length[255]|is_unique[tests.name,id,{id}]',
        'duration_minutes' => 'required|is_natural',
        'max_score'        => 'required|numeric',
    ];

    protected $validationMessages = [
        'name' => [
            'is_unique' => 'Nama ujian ini sudah digunakan, silakan pilih nama lain.'
        ]
    ];

    /**
     * Delete test subject sets and associations before true delete
     * (Normally CASCADE handles this if foreign keys are set up correctly)
     */
}

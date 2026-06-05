<?php

namespace App\Models;

use CodeIgniter\Model;

class TestLogModel extends Model
{
    protected $table            = 'test_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'test_attempt_id', 'question_id', 'question_text', 'question_type', 'question_difficulty',
        'answer_text', 'score', 'display_order', 'num_answers', 'user_ip', 
        'reaction_time_ms', 'comment', 'displayed_at', 'answered_at'
    ];
}

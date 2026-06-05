<?php

namespace App\Models;

use CodeIgniter\Model;

class TestLogAnswerModel extends Model
{
    protected $table            = 'test_log_answers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'test_log_id', 'answer_id', 'answer_text', 'is_correct', 
        'is_selected', 'display_order', 'position'
    ];
}

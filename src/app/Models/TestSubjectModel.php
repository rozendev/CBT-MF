<?php

namespace App\Models;

use CodeIgniter\Model;

class TestSubjectModel extends Model
{
    protected $table            = 'test_subjects';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'test_subject_set_id', 'subject_id'
    ];
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class TestGroupModel extends Model
{
    protected $table            = 'test_groups';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'test_id', 'group_id'
    ];

    public function getTestGroups(int $testId)
    {
        return $this->select('groups.*')
                    ->join('groups', 'groups.id = test_groups.group_id')
                    ->where('test_groups.test_id', $testId)
                    ->findAll();
    }
}

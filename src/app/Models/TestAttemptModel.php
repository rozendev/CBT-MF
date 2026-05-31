<?php

namespace App\Models;

use CodeIgniter\Model;

class TestAttemptModel extends Model
{
    protected $table            = 'test_attempts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    protected $allowedFields = [
        'test_id', 'user_id', 'status', 'cheat_strikes', 'score', 'comment', 
        'started_at', 'finished_at'
    ];

    /**
     * Get active or uncompleted attempt for a user and test
     */
    public function getActiveAttempt(int $testId, int $userId)
    {
        return $this->where('test_id', $testId)
                    ->where('user_id', $userId)
                    ->whereIn('status', [0, 1, 2]) // Not completed or locked
                    ->orderBy('id', 'DESC')
                    ->first();
    }
}

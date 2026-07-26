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

    protected $afterInsert = ['clearAttemptCache'];
    protected $beforeUpdate = ['clearAttemptCacheBeforeUpdate'];
    protected $beforeDelete = ['clearAttemptCacheBeforeDelete'];

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

    /**
     * Get cached active or uncompleted attempt for a user and test
     */
    public function getActiveAttemptCached(int $testId, int $userId)
    {
        $cache = service('cache');
        $cacheKey = "active_attempt_{$testId}_{$userId}";
        $attempt = $cache->get($cacheKey);
        if ($attempt === null) {
            $attempt = $this->getActiveAttempt($testId, $userId);
            try {
                $cache->save($cacheKey, $attempt ?: false, 300); // 5 minutes
            } catch (\Exception $e) {}
        }
        return $attempt === false ? null : $attempt;
    }

    /**
     * Find an attempt by ID and cache it
     */
    public function findCached(int $id)
    {
        $cache = service('cache');
        $cacheKey = "attempt_{$id}";
        $attempt = $cache->get($cacheKey);
        if ($attempt === null) {
            $attempt = $this->find($id);
            if ($attempt) {
                try {
                    $cache->save($cacheKey, $attempt, 3600); // 1 hour
                } catch (\Exception $e) {}
            }
        }
        return $attempt;
    }

    protected function clearAttemptCache(array $data)
    {
        if (isset($data['data']['test_id']) && isset($data['data']['user_id'])) {
            $testId = $data['data']['test_id'];
            $userId = $data['data']['user_id'];
            $this->clearCacheForAttempt($data['id'] ?? null, $testId, $userId);
        }
        return $data;
    }

    protected function clearAttemptCacheBeforeUpdate(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            foreach ($ids as $id) {
                $attempt = $this->db->table($this->table)->select('test_id, user_id')->where('id', $id)->get()->getRow();
                if ($attempt) {
                    $this->clearCacheForAttempt($id, $attempt->test_id, $attempt->user_id);
                }
            }
        }
        return $data;
    }

    protected function clearAttemptCacheBeforeDelete(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            foreach ($ids as $id) {
                $attempt = $this->db->table($this->table)->select('test_id, user_id')->where('id', $id)->get()->getRow();
                if ($attempt) {
                    $this->clearCacheForAttempt($id, $attempt->test_id, $attempt->user_id);
                }
            }
        }
        return $data;
    }

    public function clearCacheForAttempt($attemptId, $testId, $userId)
    {
        $cache = service('cache');
        try {
            if ($testId && $userId) {
                $cache->delete("active_attempt_{$testId}_{$userId}");
                $cache->delete("attempt_active_{$testId}_{$userId}");
            }
            if ($attemptId) {
                $cache->delete("attempt_{$attemptId}");
                $cache->delete("attempt_questions_{$attemptId}");
                $cache->delete("attempt_answers_{$attemptId}");
            }
        } catch (\Exception $e) {
            // Ignore cache driver issues
        }
    }
}

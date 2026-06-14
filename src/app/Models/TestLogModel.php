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

    protected $afterInsert = ['clearCache'];
    protected $afterUpdate = ['clearCache'];
    protected $afterDelete = ['clearCache'];

    /**
     * Find a test log by ID and cache it
     */
    public function findCached(int $id)
    {
        $cache = service('cache');
        $cacheKey = "test_log_{$id}";
        $log = $cache->get($cacheKey);
        if ($log === null) {
            $log = $this->find($id);
            if ($log) {
                try {
                    $cache->save($cacheKey, $log, 3600); // 1 hour
                } catch (\Exception $e) {}
            }
        }
        return $log;
    }

    protected function clearCache(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            $cache = service('cache');
            foreach ($ids as $id) {
                try {
                    $cache->delete("test_log_{$id}");
                } catch (\Exception $e) {}
            }
        }
        return $data;
    }
}

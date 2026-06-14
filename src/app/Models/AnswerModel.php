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

    protected $afterInsert = ['clearCache'];
    protected $beforeUpdate = ['clearCacheBeforeUpdate'];
    protected $beforeDelete = ['clearCacheBeforeDelete'];

    /**
     * Get answers for a specific question
     */
    public function getAnswersByQuestion(int $questionId)
    {
        $cache = service('cache');
        $cacheKey = "question_answers_{$questionId}";
        $answers = $cache->get($cacheKey);
        if ($answers === null) {
            $answers = $this->where('question_id', $questionId)
                            ->where('is_enabled', 1)
                            ->orderBy('position', 'ASC')
                            ->orderBy('id', 'ASC')
                            ->findAll();
            try {
                $cache->save($cacheKey, $answers, 3600); // 1 hour
            } catch (\Exception $e) {}
        }
        return $answers;
    }

    protected function clearCache(array $data)
    {
        if (isset($data['data']['question_id'])) {
            $questionId = (int)$data['data']['question_id'];
            try {
                service('cache')->delete("question_answers_{$questionId}");
            } catch (\Exception $e) {}
            $this->clearRelatedAttemptCacheForQuestion($questionId);
        }
        return $data;
    }

    protected function clearCacheBeforeUpdate(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            foreach ($ids as $id) {
                $ans = $this->db->table($this->table)->select('question_id')->where('id', $id)->get()->getRow();
                if ($ans) {
                    $questionId = (int)$ans->question_id;
                    try {
                        service('cache')->delete("question_answers_{$questionId}");
                    } catch (\Exception $e) {}
                    $this->clearRelatedAttemptCacheForQuestion($questionId);
                }
            }
        }
        return $data;
    }

    protected function clearCacheBeforeDelete(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            foreach ($ids as $id) {
                $ans = $this->db->table($this->table)->select('question_id')->where('id', $id)->get()->getRow();
                if ($ans) {
                    $questionId = (int)$ans->question_id;
                    try {
                        service('cache')->delete("question_answers_{$questionId}");
                    } catch (\Exception $e) {}
                    $this->clearRelatedAttemptCacheForQuestion($questionId);
                }
            }
        }
        return $data;
    }

    private function clearRelatedAttemptCacheForQuestion(int $questionId)
    {
        $db = \Config\Database::connect();
        $attempts = $db->table('test_logs')
                      ->select('test_attempt_id')
                      ->where('question_id', $questionId)
                      ->distinct()
                      ->get()
                      ->getResult();
        
        $cache = service('cache');
        foreach ($attempts as $att) {
            try {
                $cache->delete("attempt_questions_{$att->test_attempt_id}");
                $cache->delete("attempt_answers_{$att->test_attempt_id}");
            } catch (\Exception $e) {}
        }
    }
}

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
        'show_score_after_exam', 'show_correct_answers', 'allow_review',
        'score_right', 'score_wrong', 'score_unanswered', 'max_score',
        'passing_score', 'random_questions', 'random_answers', 'show_menu',
        'allow_noanswer', 'mcma_partial_score', 'is_repeatable',
        'auto_logout_on_timeout', 'auto_submit_on_cheat', 'require_kiosk', 'is_enabled', 'user_id',
        'exam_mode', 'static_page_path', 'static_generated_at'
    ];

    protected $validationRules = [
        'name'             => 'required|max_length[255]|is_unique[tests.name,id,{id}]',
        'begin_time'       => 'required|valid_date',
        'duration_minutes' => 'required|is_natural',
        'max_score'        => 'required|numeric',
    ];

    protected $validationMessages = [
        'name' => [
            'is_unique' => 'Nama ujian ini sudah digunakan, silakan pilih nama lain.'
        ]
    ];

    protected $afterUpdate = ['clearCache'];
    protected $afterDelete = ['clearCache'];

    /**
     * Find a test by ID and cache it
     */
    public function findCached(int $id)
    {
        $cache = service('cache');
        $cacheKey = "test_details_{$id}";
        $test = $cache->get($cacheKey);
        if ($test === null) {
            $test = $this->find($id);
            if ($test) {
                try {
                    $cache->save($cacheKey, $test, 300); // 5 minutes
                } catch (\Exception $e) {}
            }
        }
        return $test;
    }

    protected function clearCache(array $data)
    {
        if (isset($data['id'])) {
            $ids = is_array($data['id']) ? $data['id'] : [$data['id']];
            foreach ($ids as $id) {
                try {
                    service('cache')->delete("test_details_{$id}");
                } catch (\Exception $e) {}
            }
        }
        return $data;
    }
}

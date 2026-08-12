<?php

namespace App\Models;

use CodeIgniter\Model;

class TestSubjectSetModel extends Model
{
    protected $table            = 'test_subject_sets';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = ''; // Table only has created_at

    protected $allowedFields = [
        'test_id', 'question_type', 'difficulty', 'quantity', 'num_answers'
    ];

    /**
     * Get subject sets for a test with their mapped subjects
     */
    public function getSetsByTest(int $testId)
    {
        $sets = $this->where('test_id', $testId)->findAll();
        $db = \Config\Database::connect();
        
        foreach ($sets as &$set) {
            $subjects = $db->table('test_subjects')
                           ->select('subjects.id, subjects.name, modules.name as module_name')
                           ->join('subjects', 'subjects.id = test_subjects.subject_id')
                           ->join('modules', 'modules.id = subjects.module_id')
                           ->where('test_subject_set_id', $set->id)
                           ->get()->getResult();
            $set->subjects = $subjects;

            $set->topic = !empty($set->topic_id)
                ? $db->table('topics')->where('id', $set->topic_id)->get()->getRow()
                : null;
        }

        return $sets;
    }
}

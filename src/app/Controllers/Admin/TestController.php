<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\ActivityLogModel;

class TestController extends BaseController
{
    protected TestModel $testModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->testModel   = new TestModel();
        $this->activityLog = new ActivityLogModel();
    }

    public function index()
    {
        $tests = $this->testModel->orderBy('id', 'DESC')->paginate(15);
        $pager = $this->testModel->pager;

        return view('admin/tests/index', [
            'tests' => $tests,
            'pager' => $pager
        ]);
    }

    public function create()
    {
        $settingModel = new \App\Models\SettingModel();

        return view('admin/tests/form', [
            'test' => null,
            'defaultDuration' => (int) $settingModel->getValue('default_duration', 90),
            'defaultPassingGrade' => (float) $settingModel->getValue('default_passing_grade', 0),
        ]);
    }

    public function store()
    {
        if (!$this->validate($this->testModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        
        // Handle checkboxes (boolean fields)
        $checkboxFields = [
            'results_visible', 'report_visible', 'random_questions', 
            'random_answers', 'show_menu', 'allow_noanswer', 
            'mcma_partial_score', 'is_repeatable', 'auto_logout_on_timeout', 
            'auto_submit_on_cheat', 'require_kiosk', 'is_enabled'
        ];

        foreach ($checkboxFields as $field) {
            $data[$field] = $this->request->getPost($field) ? 1 : 0;
        }

        $triStateFields = ['show_score_after_exam', 'show_correct_answers', 'allow_review'];
        foreach ($triStateFields as $field) {
            $val = $this->request->getPost($field);
            $data[$field] = ($val === '' || $val === 'default' || $val === null) ? null : (int) $val;
        }

        if (array_key_exists('show_score_after_exam', $data) && $data['show_score_after_exam'] !== null) {
            $data['results_visible'] = (int)$data['show_score_after_exam'];
        }

        // Auto-calculate end_time based on begin_time and duration_minutes (Hardcap logic)
        if (!empty($data['begin_time']) && !empty($data['duration_minutes'])) {
            $data['end_time'] = date('Y-m-d H:i:s', strtotime($data['begin_time'] . " + {$data['duration_minutes']} minutes"));
        } else {
            $data['end_time'] = null;
        }

        if (empty($data['password'])) $data['password'] = null;

        $data['user_id'] = session('user_id');

        if ($this->testModel->skipValidation(true)->insert($data)) {
            $insertId = $this->testModel->getInsertID();
            $this->activityLog->log('create', session('user_id'), 'test', $insertId, "Membuat ujian: {$data['name']}");
            
            // Redirect to test configuration page where they can add groups and subjects
            return redirect()->to("/admin/tests/config/{$insertId}")->with('success', 'Ujian berhasil dibuat. Silakan atur soal dan grup peserta.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menyimpan ujian.');
    }

    public function edit($id)
    {
        $test = $this->testModel->find($id);
        if (!$test) {
            return redirect()->to('/admin/tests')->with('error', 'Ujian tidak ditemukan.');
        }

        return view('admin/tests/form', ['test' => $test]);
    }

    public function update($id)
    {
        $test = $this->testModel->find($id);
        if (!$test) {
            return redirect()->to('/admin/tests')->with('error', 'Ujian tidak ditemukan.');
        }

        $rules = $this->testModel->getValidationRules();
        $rules['name'] = "required|max_length[255]|is_unique[tests.name,id,{$id}]";

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        
        $checkboxFields = [
            'results_visible', 'report_visible', 'random_questions', 
            'random_answers', 'show_menu', 'allow_noanswer', 
            'mcma_partial_score', 'is_repeatable', 'auto_logout_on_timeout', 
            'auto_submit_on_cheat', 'require_kiosk', 'is_enabled'
        ];

        foreach ($checkboxFields as $field) {
            $data[$field] = $this->request->getPost($field) ? 1 : 0;
        }

        $triStateFields = ['show_score_after_exam', 'show_correct_answers', 'allow_review'];
        foreach ($triStateFields as $field) {
            $val = $this->request->getPost($field);
            $data[$field] = ($val === '' || $val === 'default' || $val === null) ? null : (int) $val;
        }

        if (array_key_exists('show_score_after_exam', $data) && $data['show_score_after_exam'] !== null) {
            $data['results_visible'] = (int)$data['show_score_after_exam'];
        }

        // Auto-calculate end_time based on begin_time and duration_minutes (Hardcap logic)
        if (!empty($data['begin_time']) && !empty($data['duration_minutes'])) {
            $data['end_time'] = date('Y-m-d H:i:s', strtotime($data['begin_time'] . " + {$data['duration_minutes']} minutes"));
        } else {
            $data['end_time'] = null;
        }

        if (empty($data['password'])) $data['password'] = null;

        if ($this->testModel->skipValidation(true)->update($id, $data)) {
            $this->activityLog->log('update', session('user_id'), 'test', $id, "Mengupdate ujian: {$data['name']}");
            return redirect()->to('/admin/tests')->with('success', 'Pengaturan ujian berhasil diperbarui.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui ujian.');
    }

    public function delete($id)
    {
        $test = $this->testModel->find($id);
        if (!$test) {
            return redirect()->back()->with('error', 'Ujian tidak ditemukan.');
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // 1. Clean up all attempts, logs, and answers for this test
        $attempts = $db->table('test_attempts')->where('test_id', $id)->get()->getResult();
        foreach ($attempts as $attempt) {
            $logIds = $db->table('test_logs')
                ->where('test_attempt_id', $attempt->id)
                ->select('id')
                ->get()->getResultArray();
            $logIds = array_column($logIds, 'id');

            if (!empty($logIds)) {
                $db->table('test_log_answers')->whereIn('test_log_id', $logIds)->delete();
            }
            $db->table('test_logs')->where('test_attempt_id', $attempt->id)->delete();

            // Clear Redis cache
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->del("exam_answers:{$attempt->id}");
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis error in TestController::delete: ' . $e->getMessage());
            }
        }
        $db->table('test_attempts')->where('test_id', $id)->delete();

        // 2. Delete test itself (soft delete via model)
        $this->testModel->delete($id);

        $db->transComplete();

        if ($db->transStatus() !== false) {
            $this->activityLog->log('delete', session('user_id'), 'test', $id, "Menghapus ujian: {$test->name}");
            return redirect()->back()->with('success', 'Ujian beserta seluruh sesi siswa berhasil dihapus.');
        }

        return redirect()->back()->with('error', 'Gagal menghapus ujian.');
    }

    public function extendTime($id)
    {
        $test = $this->testModel->find($id);
        if (!$test) {
            return redirect()->back()->with('error', 'Ujian tidak ditemukan.');
        }

        $extraMinutes = (int) $this->request->getPost('minutes');
        if ($extraMinutes <= 0) {
            return redirect()->back()->with('error', 'Waktu tambahan tidak valid.');
        }

        $newDuration = $test->duration_minutes + $extraMinutes;
        $newEndTime = date('Y-m-d H:i:s', strtotime($test->begin_time . " + {$newDuration} minutes"));

        if ($this->testModel->skipValidation(true)->update($id, [
            'duration_minutes' => $newDuration,
            'end_time' => $newEndTime
        ])) {
            $this->activityLog->log('update', session('user_id'), 'test', $id, "Menambahkan waktu {$extraMinutes} menit ke ujian: {$test->name}");

            // Notify clients via SSE if running
            try {
                $redis = \App\Libraries\RedisClient::getInstance();
                if ($redis) {
                    $redis->publish('exam_events', json_encode([
                        'event' => 'extend_time',
                        'test_id' => $id,
                        'duration_minutes' => $newDuration
                    ]));
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis publish error in TestController::extendTime: ' . $e->getMessage());
            }

            return redirect()->back()->with('success', "Berhasil menambahkan waktu {$extraMinutes} menit untuk semua peserta.");
        }

        return redirect()->back()->with('error', 'Gagal menambahkan waktu.');
    }

    // ─── Ujian Configuration (Peserta & Set Soal) ───────────────

    public function config($id)
    {
        $test = $this->testModel->find($id);
        if (!$test) {
            return redirect()->to('/admin/tests')->with('error', 'Ujian tidak ditemukan.');
        }

        $groupModel = new \App\Models\GroupModel();
        $testGroupModel = new \App\Models\TestGroupModel();
        $subjectModel = new \App\Models\SubjectModel();
        $testSubjectSetModel = new \App\Models\TestSubjectSetModel();

        $allGroups = $groupModel->where('is_active', 1)->findAll();
        $testGroups = array_column($testGroupModel->getTestGroups($id), 'id');

        $dbSubjects = $subjectModel->select('subjects.*, modules.name as module_name')
                                   ->join('modules', 'modules.id = subjects.module_id')
                                   ->where('subjects.is_enabled', 1)
                                   ->orderBy('modules.name', 'ASC')
                                   ->orderBy('subjects.name', 'ASC')
                                   ->findAll();
        
        $subjectsByModule = [];
        foreach ($dbSubjects as $sub) {
            $subjectsByModule[$sub->module_name][] = $sub;
        }

        $subjectSets = $testSubjectSetModel->getSetsByTest($id);

        return view('admin/tests/config', [
            'test' => $test,
            'allGroups' => $allGroups,
            'testGroups' => $testGroups,
            'subjectsByModule' => $subjectsByModule,
            'subjectSets' => $subjectSets
        ]);
    }

    public function updateGroups($id)
    {
        $testGroupModel = new \App\Models\TestGroupModel();
        
        // Clear existing
        $testGroupModel->where('test_id', $id)->delete();

        $groups = $this->request->getPost('groups') ?? [];
        foreach ($groups as $groupId) {
            $testGroupModel->insert([
                'test_id' => $id,
                'group_id' => $groupId
            ]);
        }

        $this->activityLog->log('update', session('user_id'), 'test_groups', $id, "Mengupdate grup peserta untuk ujian ID: {$id}");
        return redirect()->back()->with('success', 'Pengaturan peserta grup berhasil disimpan.');
    }

    public function addSubjectSet($id)
    {
        $rules = [
            'subjects'      => 'required',
            'topic_id'      => 'permit_empty|is_natural_no_zero',
            'question_type' => 'required|in_list[0,1,2,3,4]',
            'difficulty'    => 'required|is_natural',
            'quantity'      => 'required|is_natural_no_zero',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $testSubjectSetModel = new \App\Models\TestSubjectSetModel();
        $testSubjectModel = new \App\Models\TestSubjectModel();

        $setData = [
            'test_id'       => $id,
            'topic_id'      => $this->request->getPost('topic_id') ?: null,
            'question_type' => $this->request->getPost('question_type'),
            'difficulty'    => $this->request->getPost('difficulty'),
            'quantity'      => $this->request->getPost('quantity'),
            'num_answers'   => $this->request->getPost('num_answers') ?: 0,
        ];

        if ($testSubjectSetModel->insert($setData)) {
            $setId = $testSubjectSetModel->getInsertID();
            $subjects = $this->request->getPost('subjects') ?? [];
            
            foreach ($subjects as $subjectId) {
                $testSubjectModel->insert([
                    'test_subject_set_id' => $setId,
                    'subject_id'          => $subjectId
                ]);
            }

            return redirect()->back()->with('success', 'Set penarikan soal berhasil ditambahkan.');
        }

        return redirect()->back()->with('error', 'Gagal menambahkan set penarikan soal.');
    }

    public function deleteSubjectSet($setId)
    {
        $testSubjectSetModel = new \App\Models\TestSubjectSetModel();
        $testSubjectModel = new \App\Models\TestSubjectModel();
        
        $set = $testSubjectSetModel->find($setId);
        if ($set) {
            $testSubjectModel->where('test_subject_set_id', $setId)->delete();
            $testSubjectSetModel->delete($setId);
            return redirect()->back()->with('success', 'Set penarikan soal berhasil dihapus.');
        }
        
        return redirect()->back()->with('error', 'Gagal menghapus set penarikan soal.');
    }
}

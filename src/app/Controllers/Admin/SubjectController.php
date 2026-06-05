<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SubjectModel;
use App\Models\ModuleModel;
use App\Models\ActivityLogModel;

class SubjectController extends BaseController
{
    protected SubjectModel $subjectModel;
    protected ModuleModel $moduleModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->subjectModel = new SubjectModel();
        $this->moduleModel  = new ModuleModel();
        $this->activityLog  = new ActivityLogModel();
    }

    public function index()
    {
        $moduleId = $this->request->getGet('module_id');
        
        $subjects = $this->subjectModel->getSubjectsWithDetails($moduleId)->paginate(15);
        $pager    = $this->subjectModel->pager;
        $modules  = $this->moduleModel->where('is_enabled', 1)->orderBy('name', 'ASC')->findAll();

        return view('admin/subjects/index', [
            'subjects' => $subjects,
            'pager'    => $pager,
            'modules'  => $modules,
            'moduleId' => $moduleId,
        ]);
    }

    public function create()
    {
        return view('admin/subjects/form', [
            'subject' => null,
            'modules' => $this->moduleModel->where('is_enabled', 1)->orderBy('name', 'ASC')->findAll()
        ]);
    }

    public function store()
    {
        if (!$this->validate($this->subjectModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        // Check unique constraint (module_id + name)
        $moduleId = $this->request->getPost('module_id');
        $name     = $this->request->getPost('name');
        
        $exists = $this->subjectModel->where('module_id', $moduleId)->where('name', $name)->first();
        if ($exists) {
            return redirect()->back()->withInput()->with('error', 'Subjek dengan nama yang sama sudah ada di modul ini.');
        }

        $data = [
            'module_id'   => $moduleId,
            'name'        => $name,
            'description' => $this->request->getPost('description'),
            'is_enabled'  => $this->request->getPost('is_enabled') ? 1 : 0,
            'user_id'     => session('user_id'),
        ];

        if ($this->subjectModel->skipValidation(true)->insert($data)) {
            $insertId = $this->subjectModel->getInsertID();
            $this->activityLog->log('create', session('user_id'), 'subject', $insertId, "Membuat subjek: {$data['name']}");
            return redirect()->to('/admin/subjects')->with('success', 'Subjek berhasil ditambahkan.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menambahkan subjek.');
    }

    public function edit($id)
    {
        $subject = $this->subjectModel->find($id);
        if (!$subject) {
            return redirect()->to('/admin/subjects')->with('error', 'Subjek tidak ditemukan.');
        }

        return view('admin/subjects/form', [
            'subject' => $subject,
            'modules' => $this->moduleModel->where('is_enabled', 1)->orderBy('name', 'ASC')->findAll()
        ]);
    }

    public function update($id)
    {
        $subject = $this->subjectModel->find($id);
        if (!$subject) {
            return redirect()->to('/admin/subjects')->with('error', 'Subjek tidak ditemukan.');
        }

        if (!$this->validate($this->subjectModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $moduleId = $this->request->getPost('module_id');
        $name     = $this->request->getPost('name');
        
        $exists = $this->subjectModel->where('module_id', $moduleId)
                                     ->where('name', $name)
                                     ->where('id !=', $id)
                                     ->first();
        if ($exists) {
            return redirect()->back()->withInput()->with('error', 'Subjek dengan nama yang sama sudah ada di modul ini.');
        }

        $data = [
            'module_id'   => $moduleId,
            'name'        => $name,
            'description' => $this->request->getPost('description'),
            'is_enabled'  => $this->request->getPost('is_enabled') ? 1 : 0,
        ];

        if ($this->subjectModel->skipValidation(true)->update($id, $data)) {
            $this->activityLog->log('update', session('user_id'), 'subject', $id, "Mengupdate subjek: {$data['name']}");
            return redirect()->to('/admin/subjects')->with('success', 'Subjek berhasil diperbarui.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui subjek.');
    }

    public function delete($id)
    {
        $subject = $this->subjectModel->find($id);
        if (!$subject) {
            return redirect()->back()->with('error', 'Subjek tidak ditemukan.');
        }

        if ($this->subjectModel->delete($id)) {
            // Cascade delete questions
            $questionModel = new \App\Models\QuestionModel();
            $questions = $questionModel->where('subject_id', $id)->findAll();
            if (!empty($questions)) {
                $qIds = array_column($questions, 'id');
                $questionModel->delete($qIds);
            }
            $this->activityLog->log('delete', session('user_id'), 'subject', $id, "Menghapus subjek: {$subject->name}");
            return redirect()->to('/admin/subjects')->with('success', 'Subjek beserta semua soal di dalamnya berhasil dihapus.');
        }

        return redirect()->back()->with('error', 'Gagal menghapus subjek.');
    }
}

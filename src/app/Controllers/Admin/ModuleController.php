<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ModuleModel;
use App\Models\ActivityLogModel;

class ModuleController extends BaseController
{
    protected ModuleModel $moduleModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->moduleModel = new ModuleModel();
        $this->activityLog = new ActivityLogModel();
    }

    public function index()
    {
        $modules = $this->moduleModel->getModulesWithDetails()->paginate(10);
        $pager   = $this->moduleModel->pager;

        return view('admin/modules/index', [
            'modules' => $modules,
            'pager'   => $pager
        ]);
    }

    public function create()
    {
        return view('admin/modules/form', ['module' => null]);
    }

    public function store()
    {
        if (!$this->validate($this->moduleModel->getValidationRules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'name'       => $this->request->getPost('name'),
            'is_enabled' => $this->request->getPost('is_enabled') ? 1 : 0,
            'user_id'    => session('user_id'),
        ];

        if ($this->moduleModel->skipValidation(true)->insert($data)) {
            $insertId = $this->moduleModel->getInsertID();
            $this->activityLog->log('create', session('user_id'), 'module', $insertId, "Membuat modul: {$data['name']}");
            return redirect()->to('/admin/modules')->with('success', 'Modul berhasil ditambahkan.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menambahkan modul.');
    }

    public function edit($id)
    {
        $module = $this->moduleModel->find($id);
        if (!$module) {
            return redirect()->to('/admin/modules')->with('error', 'Modul tidak ditemukan.');
        }

        return view('admin/modules/form', ['module' => $module]);
    }

    public function update($id)
    {
        $module = $this->moduleModel->find($id);
        if (!$module) {
            return redirect()->to('/admin/modules')->with('error', 'Modul tidak ditemukan.');
        }

        $rules = [
            'name' => "required|max_length[255]|is_unique[modules.name,id,{$id}]",
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = [
            'name'       => $this->request->getPost('name'),
            'is_enabled' => $this->request->getPost('is_enabled') ? 1 : 0,
        ];

        if ($this->moduleModel->skipValidation(true)->update($id, $data)) {
            $this->activityLog->log('update', session('user_id'), 'module', $id, "Mengupdate modul: {$data['name']}");
            return redirect()->to('/admin/modules')->with('success', 'Modul berhasil diperbarui.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui modul.');
    }

    public function delete($id)
    {
        $module = $this->moduleModel->find($id);
        if (!$module) {
            return redirect()->back()->with('error', 'Modul tidak ditemukan.');
        }

        if ($id == 1) {
            return redirect()->back()->with('error', 'Modul Default tidak dapat dihapus.');
        }

        if ($this->moduleModel->delete($id)) {
            $this->activityLog->log('delete', session('user_id'), 'module', $id, "Menghapus modul: {$module->name}");
            return redirect()->to('/admin/modules')->with('success', 'Modul berhasil dihapus.');
        }

        return redirect()->back()->with('error', 'Gagal menghapus modul.');
    }
}

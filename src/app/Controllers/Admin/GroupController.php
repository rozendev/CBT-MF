<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\GroupModel;
use App\Models\ActivityLogModel;

class GroupController extends BaseController
{
    protected GroupModel $groupModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->groupModel = new GroupModel();
        $this->activityLog = new ActivityLogModel();
    }

    public function index()
    {
        // Simple pagination (10 per page)
        $groups = $this->groupModel->orderBy('name', 'ASC')->paginate(10);
        $pager  = $this->groupModel->pager;

        // Enhance with member count
        foreach ($groups as $group) {
            $group->member_count = $this->groupModel->getMemberCount($group->id);
        }

        return view('admin/groups/index', [
            'groups' => $groups,
            'pager'  => $pager
        ]);
    }

    public function create()
    {
        return view('admin/groups/form', ['group' => null]);
    }

    public function store()
    {
        $rules = [
            'name' => 'required|max_length[255]|is_unique[groups.name]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        $data = [
            'name'        => $this->request->getPost('name'),
            'description' => $this->request->getPost('description'),
            'is_active'   => $this->request->getPost('is_active') ? 1 : 0,
        ];

        if ($this->groupModel->skipValidation(true)->insert($data)) {
            $insertId = $this->groupModel->getInsertID();
            $this->activityLog->log('create', session('user_id'), 'group', $insertId, "Membuat grup: {$data['name']}");
            
            return redirect()->to('/admin/groups')->with('success', 'Grup berhasil ditambahkan.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menambahkan grup.');
    }

    public function edit($id)
    {
        $group = $this->groupModel->find($id);
        
        if (!$group) {
            return redirect()->to('/admin/groups')->with('error', 'Grup tidak ditemukan.');
        }

        return view('admin/groups/form', ['group' => $group]);
    }

    public function update($id)
    {
        $group = $this->groupModel->find($id);
        if (!$group) {
            return redirect()->to('/admin/groups')->with('error', 'Grup tidak ditemukan.');
        }

        $rules = [
            'name' => "required|max_length[255]|is_unique[groups.name,id,{$id}]",
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors());
        }

        $data = [
            'name'        => $this->request->getPost('name'),
            'description' => $this->request->getPost('description'),
            'is_active'   => $this->request->getPost('is_active') ? 1 : 0,
        ];

        if ($this->groupModel->skipValidation(true)->update($id, $data)) {
            $this->activityLog->log('update', session('user_id'), 'group', $id, "Mengupdate grup: {$data['name']}");
            return redirect()->to('/admin/groups')->with('success', 'Grup berhasil diperbarui.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui grup.');
    }

    public function delete($id)
    {
        $group = $this->groupModel->find($id);
        if (!$group) {
            return redirect()->back()->with('error', 'Grup tidak ditemukan.');
        }

        // Default group (ID 1) cannot be deleted
        if ($id == 1) {
            return redirect()->back()->with('error', 'Grup Default tidak dapat dihapus.');
        }

        if ($this->groupModel->delete($id)) {
            $this->activityLog->log('delete', session('user_id'), 'group', $id, "Menghapus grup: {$group->name}");
            return redirect()->to('/admin/groups')->with('success', 'Grup berhasil dihapus.');
        }

        return redirect()->back()->with('error', 'Gagal menghapus grup.');
    }
}

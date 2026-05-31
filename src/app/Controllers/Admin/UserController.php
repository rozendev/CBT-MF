<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\GroupModel;
use App\Models\ActivityLogModel;

class UserController extends BaseController
{
    protected UserModel $userModel;
    protected GroupModel $groupModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->userModel   = new UserModel();
        $this->groupModel  = new GroupModel();
        $this->activityLog = new ActivityLogModel();
    }

    public function index()
    {
        $search = $this->request->getGet('search');
        $role   = $this->request->getGet('role');

        $query = $this->userModel;

        if ($search) {
            $query = $query->groupStart()
                           ->like('username', $search)
                           ->orLike('firstname', $search)
                           ->orLike('lastname', $search)
                           ->orLike('email', $search)
                           ->groupEnd();
        }

        if ($role) {
            $query = $query->where('role', $role);
        }

        $users = $query->orderBy('id', 'DESC')->paginate(15);
        $pager = $this->userModel->pager;

        // Fetch groups for each user
        foreach ($users as $user) {
            $user->groups = $this->groupModel->getUserGroups($user->id);
            $user->is_locked = $this->userModel->isLocked($user);
        }

        return view('admin/users/index', [
            'users'  => $users,
            'pager'  => $pager,
            'search' => $search,
            'role'   => $role,
        ]);
    }

    public function create()
    {
        return view('admin/users/form', [
            'user'       => null,
            'userGroups' => [],
            'allGroups'  => $this->groupModel->where('is_active', 1)->findAll()
        ]);
    }

    public function store()
    {
        $rules = $this->userModel->getValidationRules();
        $rules['password'] = 'required|min_length[6]';

        if (!$this->validate($rules, $this->userModel->getValidationMessages())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        $data['is_active'] = $this->request->getPost('is_active') ? 1 : 0;
        
        // Convert empty strings to null for unique nullable fields
        $nullableFields = ['birthdate', 'birthplace', 'registration_number', 'ssn'];
        foreach ($nullableFields as $field) {
            if (empty($data[$field])) {
                $data[$field] = null;
            }
        }

        // Remove empty password so it isn't hashed as empty string if somehow submitted
        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($this->userModel->skipValidation(true)->insert($data)) {
            $userId = $this->userModel->getInsertID();

            // Handle groups
            $groups = $this->request->getPost('groups') ?? [];
            foreach ($groups as $groupId) {
                $this->groupModel->addUserToGroup($userId, $groupId);
            }

            $this->activityLog->log('create', session('user_id'), 'user', $userId, "Membuat pengguna: {$data['username']}");
            return redirect()->to('/admin/users')->with('success', 'Pengguna berhasil ditambahkan.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menambahkan pengguna.');
    }

    public function edit($id)
    {
        $user = $this->userModel->find($id);
        if (!$user) {
            return redirect()->to('/admin/users')->with('error', 'Pengguna tidak ditemukan.');
        }

        // Get user groups as simple array of IDs
        $userGroups = array_column($this->groupModel->getUserGroups($id), 'id');

        return view('admin/users/form', [
            'user'       => $user,
            'userGroups' => $userGroups,
            'allGroups'  => $this->groupModel->where('is_active', 1)->findAll()
        ]);
    }

    public function update($id)
    {
        $user = $this->userModel->find($id);
        if (!$user) {
            return redirect()->to('/admin/users')->with('error', 'Pengguna tidak ditemukan.');
        }

        $rules = $this->userModel->getValidationRules();
        $rules['username'] = "required|min_length[3]|max_length[100]|is_unique[users.username,id,{$id}]";
        
        // Password is optional on update
        if ($this->request->getPost('password')) {
            $rules['password'] = 'min_length[6]';
        } else {
            unset($rules['password']);
        }

        if (!$this->validate($rules, $this->userModel->getValidationMessages())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        $data['is_active'] = $this->request->getPost('is_active') ? 1 : 0;

        // Convert empty strings to null for unique nullable fields
        $nullableFields = ['birthdate', 'birthplace', 'registration_number', 'ssn'];
        foreach ($nullableFields as $field) {
            if (empty($data[$field])) {
                $data[$field] = null;
            }
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        if ($this->userModel->skipValidation(true)->update($id, $data)) {
            // Update groups
            $newGroups = $this->request->getPost('groups') ?? [];
            $oldGroups = array_column($this->groupModel->getUserGroups($id), 'id');

            // Add new groups
            $toAdd = array_diff($newGroups, $oldGroups);
            foreach ($toAdd as $groupId) {
                $this->groupModel->addUserToGroup($id, $groupId);
            }

            // Remove old groups
            $toRemove = array_diff($oldGroups, $newGroups);
            foreach ($toRemove as $groupId) {
                $this->groupModel->removeUserFromGroup($id, $groupId);
            }

            $this->activityLog->log('update', session('user_id'), 'user', $id, "Mengupdate pengguna: {$user->username}");
            return redirect()->to('/admin/users')->with('success', 'Pengguna berhasil diperbarui.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui pengguna.');
    }

    public function delete($id)
    {
        // Prevent deleting self or default admin
        if ($id == 1 || $id == session('user_id')) {
            return redirect()->back()->with('error', 'Anda tidak dapat menghapus akun ini.');
        }

        $user = $this->userModel->find($id);
        if (!$user) {
            return redirect()->back()->with('error', 'Pengguna tidak ditemukan.');
        }

        if ($this->userModel->delete($id)) {
            $this->activityLog->log('delete', session('user_id'), 'user', $id, "Menghapus pengguna: {$user->username}");
            return redirect()->to('/admin/users')->with('success', 'Pengguna berhasil dihapus.');
        }

        return redirect()->back()->with('error', 'Gagal menghapus pengguna.');
    }

    public function unlock($id)
    {
        $user = $this->userModel->find($id);
        if (!$user) {
            return redirect()->back()->with('error', 'Pengguna tidak ditemukan.');
        }

        $this->userModel->resetLoginAttempts($id);
        $this->activityLog->log('unlock', session('user_id'), 'user', $id, "Membuka kunci akun: {$user->username}");
        
        return redirect()->back()->with('success', 'Akun berhasil dibuka kuncinya.');
    }
}

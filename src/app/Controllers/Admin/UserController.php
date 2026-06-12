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
            'users'     => $users,
            'pager'     => $pager,
            'search'    => $search,
            'role'      => $role,
            'allGroups' => $this->groupModel->where('is_active', 1)->findAll()
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
        $nullableFields = ['birthdate', 'birthplace', 'registration_number', 'ssn', 'email'];
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
        $nullableFields = ['birthdate', 'birthplace', 'registration_number', 'ssn', 'email'];
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

    public function bulkDelete()
    {
        $userIds = $this->request->getPost('user_ids');
        
        if (empty($userIds) || !is_array($userIds)) {
            return redirect()->back()->with('error', 'Tidak ada pengguna yang dipilih untuk dihapus.');
        }

        $successCount = 0;
        $skipCount = 0;

        foreach ($userIds as $id) {
            // Prevent deleting self or default admin
            if ($id == 1 || $id == session('user_id')) {
                $skipCount++;
                continue;
            }

            $user = $this->userModel->find($id);
            if ($user) {
                if ($this->userModel->delete($id)) {
                    $this->activityLog->log('delete', session('user_id'), 'user', $id, "Menghapus pengguna (bulk): {$user->username}");
                    $successCount++;
                }
            }
        }

        $msg = "Berhasil menghapus $successCount pengguna.";
        if ($skipCount > 0) {
            $msg .= " ($skipCount pengguna dilewati demi keamanan).";
        }

        if ($successCount > 0) {
            return redirect()->to('/admin/users')->with('success', $msg);
        } else {
            return redirect()->to('/admin/users')->with('error', 'Gagal menghapus pengguna. Pastikan Anda tidak memilih akun Anda sendiri.');
        }
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

    public function template()
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $sheet->setCellValue('A1', 'Username');
        $sheet->setCellValue('B1', 'Password');
        $sheet->setCellValue('C1', 'Nama Depan');
        $sheet->setCellValue('D1', 'Nama Belakang');
        $sheet->setCellValue('E1', 'Email');

        // Styles
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);
        
        // Auto-size columns
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'Template_Import_Siswa.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="'. $filename .'"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit();
    }

    public function import()
    {
        $file = $this->request->getFile('excel_file');
        $groupId = $this->request->getPost('group_id');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'File tidak valid atau gagal diunggah.');
        }

        $ext = $file->getClientExtension();
        if (!in_array(strtolower($ext), ['xls', 'xlsx'])) {
            return redirect()->back()->with('error', 'Format file harus .xls atau .xlsx');
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getTempName());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Skip header (row 0)
            array_shift($rows);

            $successCount = 0;
            $duplicateCount = 0;
            
            $db = \Config\Database::connect();
            $db->transStart();

            foreach ($rows as $row) {
                $username  = trim((string)($row[0] ?? ''));
                $password  = trim((string)($row[1] ?? ''));
                $firstname = trim((string)($row[2] ?? ''));
                $lastname  = trim((string)($row[3] ?? ''));
                $email     = trim((string)($row[4] ?? ''));

                if (empty($username) || empty($password) || empty($firstname)) {
                    continue; // Skip invalid row
                }

                // Check duplicate username (termasuk yang sudah di-soft delete)
                if ($this->userModel->withDeleted()->where('username', $username)->first()) {
                    $duplicateCount++;
                    continue;
                }
                
                // Check duplicate email if provided (termasuk yang sudah di-soft delete)
                if (!empty($email) && $this->userModel->withDeleted()->where('email', $email)->first()) {
                    $duplicateCount++;
                    continue;
                }

                $userData = [
                    'username'  => $username,
                    'password'  => $password, // Model will hash it
                    'firstname' => $firstname,
                    'lastname'  => $lastname,
                    'email'     => empty($email) ? null : $email,
                    'role'      => 'siswa',
                    'is_active' => 1
                ];

                try {
                    if ($this->userModel->skipValidation(true)->insert($userData)) {
                        $userId = $this->userModel->getInsertID();
                        if (!empty($groupId)) {
                            $this->groupModel->addUserToGroup($userId, $groupId);
                        }
                        $successCount++;
                    }
                } catch (\Exception $e) {
                    // Ignore DB exception for this specific row (e.g. constraints) and let it continue
                    // You could log it here if necessary
                }
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return redirect()->back()->with('error', 'Gagal memproses transaksi database saat import. Pastikan file sesuai format.');
            }

            $this->activityLog->log('import', session('user_id'), 'user', 0, "Mengimport $successCount siswa baru.");

            $msg = "Berhasil mengimport $successCount siswa.";
            if ($duplicateCount > 0) {
                $msg .= " ($duplicateCount siswa dilewati karena username/email sudah pernah digunakan/duplikat).";
            }
            return redirect()->back()->with('success', $msg);

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal memproses file Excel: ' . $e->getMessage());
        }
    }
}

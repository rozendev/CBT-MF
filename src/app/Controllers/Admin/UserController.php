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

        // Fetch groups for all users in a single query to avoid N+1 queries
        $userIds = array_column($users, 'id');
        $userGroupsMap = [];
        if (!empty($userIds)) {
            $rawGroups = $this->groupModel->getUsersGroups($userIds);
            foreach ($rawGroups as $group) {
                $userId = (int) $group->user_id;
                if (!isset($userGroupsMap[$userId])) {
                    $userGroupsMap[$userId] = [];
                }
                $userGroupsMap[$userId][] = $group;
            }
        }

        foreach ($users as $user) {
            $user->groups = $userGroupsMap[$user->id] ?? [];
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

        // Check if there is a soft-deleted user with the same username to reuse
        $deletedUser = $this->userModel->findDeletedByUsername($data['username']);
        if ($deletedUser) {
            if ($this->userModel->reuseDeletedUser($deletedUser->id, $data)) {
                $userId = $deletedUser->id;

                // Clear old groups, then add new
                $db = \Config\Database::connect();
                $db->table('user_groups')->where('user_id', $userId)->delete();

                $groups = $this->request->getPost('groups') ?? [];
                foreach ($groups as $groupId) {
                    $this->groupModel->addUserToGroup($userId, $groupId);
                }

                $this->activityLog->log('create', session('user_id'), 'user', $userId, "Membuat pengguna (reuse): {$data['username']}");
                return redirect()->to('/admin/users')->with('success', 'Pengguna berhasil ditambahkan (mengaktifkan kembali data lama).');
            }
        } else {
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
        $rules['username'] = "required|min_length[3]|max_length[100]|is_unique[users.active_username,id,{$id}]";
        
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
        $user = $this->userModel->find($id);
        if (!$user) {
            return redirect()->back()->with('error', 'Pengguna tidak ditemukan.');
        }

        // Prevent deleting self or other admins (role-independent protection)
        if ($id == session('user_id') || $user->role === 'admin') {
            return redirect()->back()->with('error', 'Anda tidak dapat menghapus akun ini.');
        }

        // Clean up user pointers/relations before soft deleting
        $this->userModel->cleanPointers($id);

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
            $user = $this->userModel->find($id);
            if (!$user) {
                continue;
            }

            // Prevent deleting self or other admins (role-independent protection)
            if ($id == session('user_id') || $user->role === 'admin') {
                $skipCount++;
                continue;
            }

            // Clean up user pointers/relations before soft deleting
            $this->userModel->cleanPointers($id);

            if ($this->userModel->delete($id)) {
                $this->activityLog->log('delete', session('user_id'), 'user', $id, "Menghapus pengguna (bulk): {$user->username}");
                $successCount++;
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

        // Clear IP-level rate limit from Redis if a failed IP was logged
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $failedIp = $redis->get("last_failed_login_ip:{$id}");
                if ($failedIp) {
                    $redis->del("login_attempts_ip:{$failedIp}");
                    $redis->del("last_failed_login_ip:{$id}");
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Failed to clear IP rate limit for user ' . $user->username . ': ' . $e->getMessage());
        }

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

        $fileMime = $file->getMimeType();
        $allowedMimes = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/wps-office.xls',
            'application/wps-office.xlsx'
        ];
        
        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['xls', 'xlsx']) || !in_array($fileMime, $allowedMimes)) {
            log_message('warning', "Import file validation failed. Extension: {$ext}, Mime: {$fileMime}");
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

                // Check duplicate username (including soft deleted)
                $existingUser = $this->userModel->withDeleted()->where('username', $username)->first();
                
                $userData = [
                    'username'  => $username,
                    'password'  => $password, // reuseDeletedUser will hash it
                    'firstname' => $firstname,
                    'lastname'  => $lastname,
                    'email'     => empty($email) ? null : $email,
                    'role'      => 'siswa',
                    'is_active' => 1
                ];

                if ($existingUser) {
                    if (empty($existingUser->deleted_at)) {
                        // User is active: real duplicate
                        $duplicateCount++;
                        continue;
                    } else {
                        // User is soft deleted: reuse/override this row!
                        try {
                            if ($this->userModel->reuseDeletedUser($existingUser->id, $userData)) {
                                if (!empty($groupId)) {
                                    $db->table('user_groups')->where('user_id', $existingUser->id)->delete();
                                    $this->groupModel->addUserToGroup($existingUser->id, $groupId);
                                }
                                $successCount++;
                            }
                        } catch (\Exception $e) {
                            // Ignore exception for this specific row and continue
                        }
                        continue;
                    }
                }

                // If not exists at all, insert new user
                try {
                    if ($this->userModel->skipValidation(true)->insert($userData)) {
                        $userId = $this->userModel->getInsertID();
                        if (!empty($groupId)) {
                            $this->groupModel->addUserToGroup($userId, $groupId);
                        }
                        $successCount++;
                    }
                } catch (\Exception $e) {
                    // Ignore exception for this specific row and continue
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
    public function printCardsIndex()
    {
        $data = [
            'title' => 'Cetak Kartu Ujian (Excel)',
            'groups' => $this->groupModel->findAll()
        ];
        return view('admin/users/print_cards_form', $data);
    }

    public function printCardsProcess()
    {
        $file = $this->request->getFile('excel_file');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return redirect()->back()->with('error', 'File tidak valid atau gagal diunggah.');
        }

        $fileMime = $file->getMimeType();
        $allowedMimes = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/wps-office.xls',
            'application/wps-office.xlsx'
        ];
        
        $ext = strtolower($file->getClientExtension());
        if (!in_array($ext, ['xls', 'xlsx']) || !in_array($fileMime, $allowedMimes)) {
            return redirect()->back()->with('error', 'Format file harus .xls atau .xlsx');
        }

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getTempName());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            // Skip header (row 0)
            array_shift($rows);

            $students = [];
            foreach ($rows as $row) {
                $username  = trim((string)($row[0] ?? ''));
                $password  = trim((string)($row[1] ?? ''));
                $firstname = trim((string)($row[2] ?? ''));
                $lastname  = trim((string)($row[3] ?? ''));
                $groupName = trim((string)($row[5] ?? '')); // Group column if they added it, but not strictly necessary for print

                if (empty($username) || empty($password) || empty($firstname)) {
                    continue; // Skip invalid row
                }

                $students[] = [
                    'username' => $username,
                    'password' => $password,
                    'name'     => trim($firstname . ' ' . $lastname)
                ];
            }

            if (empty($students)) {
                return redirect()->back()->with('error', 'Tidak ada data siswa yang valid di dalam file Excel.');
            }

            $settingModel = new \App\Models\SettingModel();
            
            $data = [
                'students'   => $students,
                'appName'    => $settingModel->getValue('app_name', 'Sistem Ujian'),
                'schoolName' => $settingModel->getValue('school_name', 'Sekolah Kita'),
                'appLogo'    => $settingModel->getValue('app_logo', '')
            ];

            return view('admin/users/print_cards_layout', $data);

        } catch (\Exception $e) {
            log_message('error', '[PrintCards] ' . $e->getMessage());
            return redirect()->back()->with('error', 'Terjadi kesalahan saat membaca file Excel.');
        }
    }
}

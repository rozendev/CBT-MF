<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Manajemen Pengguna<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Manajemen Pengguna</div>
        <h1>Pengguna</h1>
        <p class="sub">Kelola akun admin, guru, dan siswa — filter, impor massal, atau cetak kartu ujian.</p>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-ghost btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="bi bi-file-earmark-excel me-1"></i> Import
        </button>
        <a href="<?= base_url('/admin/users/print-cards') ?>" class="btn btn-ghost btn-sm">
            <i class="bi bi-printer me-1"></i> Cetak Kartu
        </a>
        <a href="<?= base_url('/admin/users/create') ?>" class="btn btn-accent btn-sm">
            <i class="bi bi-person-plus me-1"></i> Tambah Pengguna
        </a>
    </div>
</div>

<div class="card mb-4 rise" style="--d:60ms">
    <div class="card-body py-3">
        <form action="<?= base_url('/admin/users') ?>" method="GET" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="search" value="<?= esc($search ?? '') ?>" placeholder="Cari username, nama, email..." style="border-left: 0;">
                </div>
            </div>
            <div class="col-md-3">
                <select name="role" class="form-select">
                    <option value="">Semua Role</option>
                    <option value="admin" <?= ($role ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="guru" <?= ($role ?? '') === 'guru' ? 'selected' : '' ?>>Guru</option>
                    <option value="siswa" <?= ($role ?? '') === 'siswa' ? 'selected' : '' ?>>Siswa</option>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-accent btn-sm px-3"><i class="bi bi-funnel me-1"></i> Filter</button>
                <?php if (!empty($search) || !empty($role)): ?>
                    <a href="<?= base_url('/admin/users') ?>" class="btn btn-ghost btn-sm px-3">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<form action="<?= base_url('/admin/users/bulk-delete') ?>" method="POST" id="bulkDeleteForm">
    <?= csrf_field() ?>
    <div class="card rise" style="--d:120ms">
        <div class="card-body py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="m-0 fw-bold" style="letter-spacing:-0.01em;">Daftar Pengguna</h6>
                <span class="chip ghost num"><?= $pager ? $pager->getTotal() : count($users) ?></span>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-danger-soft d-none" id="btnBulkDelete" onclick="confirmBulkDelete()">
                    <i class="bi bi-trash me-1"></i> Hapus Terpilih (<span id="bulkCount">0</span>)
                </button>
            </div>
        </div>
    
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 40px;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="checkAll">
                            </div>
                        </th>
                        <th class="ps-2">Pengguna</th>
                        <th>Role</th>
                        <th>Grup</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <div class="empty-icon"><i class="bi bi-person-x"></i></div>
                                    <h6>Tidak ada pengguna ditemukan</h6>
                                    <p>Coba ubah kata kunci pencarian atau filter role, atau buat akun pengguna baru.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if ($user->id != 1 && $user->id != session('user_id')): ?>
                                        <div class="form-check">
                                            <input class="form-check-input user-checkbox" type="checkbox" name="user_ids[]" value="<?= $user->id ?>">
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="ps-2">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="avatar-tile ink">
                                            <?= strtoupper(substr($user->firstname ?? $user->username, 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold" style="color: var(--text-primary);">
                                                <?= esc($user->firstname . ' ' . $user->lastname) ?>
                                                <span class="mono" style="font-size:0.72rem; color: var(--text-tertiary); font-weight:400;">(@<?= esc($user->username) ?>)</span>
                                            </div>
                                            <div class="small" style="color: var(--text-secondary);"><?= esc($user->email ?? '-') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $roleClass = match($user->role) {
                                            'admin' => 'chip danger',
                                            'guru'  => 'chip info',
                                            default => 'chip ghost',
                                        };
                                    ?>
                                    <span class="<?= $roleClass ?>">
                                        <?= ucfirst(esc($user->role)) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($user->groups)): ?>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach (array_slice($user->groups, 0, 2) as $g): ?>
                                                <span class="chip ghost" style="font-size:0.7rem; padding:0.22rem 0.6rem;">
                                                    <?= esc($g->name) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($user->groups) > 2): ?>
                                                <span class="chip ghost num" style="font-size:0.7rem; padding:0.22rem 0.6rem;">
                                                    +<?= count($user->groups) - 2 ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="row-meta">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($user->is_locked): ?>
                                        <span class="chip warn" title="Terkunci hingga <?= date('H:i', strtotime($user->locked_until)) ?>">
                                            <i class="bi bi-lock-fill"></i> Terkunci
                                        </span>
                                    <?php elseif ($user->is_active): ?>
                                        <span class="chip ok"><span class="dot breathe"></span> Aktif</span>
                                    <?php else: ?>
                                        <span class="chip danger"><i class="bi bi-slash-circle"></i> Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if ($user->is_locked): ?>
                                        <form action="<?= base_url('/admin/users/unlock/' . $user->id) ?>" method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Buka Kunci">
                                                <i class="bi bi-unlock"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <a href="<?= base_url('/admin/users/edit/' . $user->id) ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <?php if ($user->id != 1 && $user->id != session('user_id')): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus"
                                            onclick="confirmDelete(<?= $user->id ?>, '<?= esc(addslashes($user->username)) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pager): ?>
    <div class="card-footer py-3 border-top d-flex justify-content-end">
        <?= $pager->links('default', 'bootstrap_pagination') ?>
    </div>
    <?php endif; ?>
</div>
</form>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="mb-0">Apakah Anda yakin ingin menghapus pengguna <br><strong id="deleteUserName" class="fs-5 mt-2 d-block"></strong></p>
                <p class="text-muted small mt-2">Data nilai ujian dan log aktivitas pengguna ini juga akan terhapus secara permanen.</p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Batal</button>
                <form id="deleteForm" method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-danger px-4">Ya, Hapus</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Bulk Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="mb-0">Apakah Anda yakin ingin menghapus <strong id="bulkDeleteCountText"></strong> pengguna sekaligus?</p>
                <p class="text-muted small mt-2">Data nilai ujian dan log aktivitas pengguna terpilih juga akan terhapus secara permanen. Tindakan ini tidak bisa dibatalkan.</p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger px-4" onclick="document.getElementById('bulkDeleteForm').submit()">Ya, Hapus Semua</button>
            </div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= base_url('/admin/users/import') ?>" method="POST" enctype="multipart/form-data" id="importForm">
                <?= csrf_field() ?>
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-success"><i class="bi bi-file-earmark-excel me-2"></i>Import Siswa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body py-4">
                    <div id="importFormInputs">
                        <div class="mb-4 text-center">
                            <a href="<?= base_url('/admin/users/template') ?>" class="btn btn-outline-primary btn-sm rounded-pill">
                                <i class="bi bi-download me-1"></i> Unduh Template Excel
                            </a>
                            <p class="text-muted small mt-2 mb-0">Gunakan template ini untuk mengisi data siswa yang akan diimport.</p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Pilih Grup / Kelas (Opsional)</label>
                            <select name="group_id" class="form-select">
                                <option value="">-- Tidak dimasukkan ke grup --</option>
                                <?php foreach ($allGroups as $g): ?>
                                    <option value="<?= $g->id ?>"><?= esc($g->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small text-muted">Semua siswa yang diimport akan otomatis dimasukkan ke grup ini.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">File Excel (.xlsx)</label>
                            <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls" required>
                        </div>
                    </div>
                    
                    <div id="importProgressContainer" class="d-none text-center">
                        <h6 class="fw-bold mb-3">Sedang Memproses...</h6>
                        <div class="progress mb-2" style="height: 20px;">
                            <div id="importProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                        <p id="importProgressText" class="text-muted small">Menginisialisasi import...</p>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 justify-content-center">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal" id="btnImportCancel">Batal</button>
                    <button type="submit" class="btn btn-success px-4" id="btnImportSubmit"><i class="bi bi-upload me-2"></i>Mulai Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    function confirmDelete(id, username) {
        document.getElementById('deleteUserName').textContent = username;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/users/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    // Bulk Delete Logic
    const checkAll = document.getElementById('checkAll');
    const userCheckboxes = document.querySelectorAll('.user-checkbox');
    const btnBulkDelete = document.getElementById('btnBulkDelete');
    const bulkCount = document.getElementById('bulkCount');

    function updateBulkDeleteButton() {
        if (!checkAll) return;
        const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
        bulkCount.textContent = checkedCount;
        
        if (checkedCount > 0) {
            btnBulkDelete.classList.remove('d-none');
        } else {
            btnBulkDelete.classList.add('d-none');
            checkAll.checked = false;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function() {
            userCheckboxes.forEach(cb => cb.checked = this.checked);
            updateBulkDeleteButton();
        });

        userCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                const allChecked = document.querySelectorAll('.user-checkbox:checked').length === userCheckboxes.length;
                checkAll.checked = allChecked;
                updateBulkDeleteButton();
            });
        });
    }

    function confirmBulkDelete() {
        const count = document.querySelectorAll('.user-checkbox:checked').length;
        if (count === 0) return;
        document.getElementById('bulkDeleteCountText').textContent = count;
        new bootstrap.Modal(document.getElementById('bulkDeleteModal')).show();
    }

    // Batch Import Logic
    const importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btnSubmit = document.getElementById('btnImportSubmit');
            const btnCancel = document.getElementById('btnImportCancel');
            const inputsDiv = document.getElementById('importFormInputs');
            const progressDiv = document.getElementById('importProgressContainer');
            const progressBar = document.getElementById('importProgressBar');
            const progressText = document.getElementById('importProgressText');
            
            btnSubmit.disabled = true;
            btnCancel.disabled = true;
            inputsDiv.classList.add('d-none');
            progressDiv.classList.remove('d-none');
            
            try {
                const formData = new FormData(this);
                const initRes = await fetch(this.action, { method: 'POST', body: formData });
                const initData = await initRes.json();
                
                if (initData.status !== 'success') {
                    throw new Error(initData.message || 'Gagal memulai import.');
                }
                
                const jobId = initData.job_id;
                const total = initData.total;
                let remaining = total;
                let timeoutCount = 0;
                let totalSuccess = 0;
                let totalDuplicate = 0;
                
                while (remaining > 0) {
                    try {
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout
                        
                        let csrfHeader = '<?= csrf_header() ?>';
                        let csrfValue = document.querySelector('input[name="<?= csrf_token() ?>"]').value;
                        
                        const batchRes = await fetch('<?= base_url('/admin/users/import-batch') ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                [csrfHeader]: csrfValue
                            },
                            body: JSON.stringify({ job_id: jobId }),
                            signal: controller.signal
                        });
                        
                        clearTimeout(timeoutId);
                        
                        if (!batchRes.ok) throw new Error('Server batch error');
                        const batchData = await batchRes.json();
                        
                        if (batchData.status === 'error') {
                            throw new Error(batchData.message || 'Error saat memproses batch');
                        }
                        
                        timeoutCount = 0; // reset
                        remaining = batchData.remaining;
                        totalSuccess += batchData.success_count;
                        totalDuplicate += batchData.duplicate_count;
                        
                        const processed = total - remaining;
                        const percent = Math.round((processed / total) * 100);
                        
                        progressBar.style.width = percent + '%';
                        progressBar.textContent = percent + '%';
                        progressText.textContent = `Memproses ${processed} dari ${total} data...`;
                        
                        if (remaining <= 0) break;
                        
                    } catch (err) {
                        timeoutCount++;
                        if (timeoutCount >= 3) {
                            alert('Gagal: Terjadi timeout 3 kali berturut-turut. Proses dihentikan.');
                            window.location.reload();
                            return;
                        }
                        // delay 2s before retry
                        await new Promise(r => setTimeout(r, 2000));
                    }
                }
                
                alert(`Import selesai!\nBerhasil: ${totalSuccess}\nDilewati (Duplikat): ${totalDuplicate}`);
                window.location.reload();
                
            } catch (err) {
                alert(err.message || 'Terjadi kesalahan sistem.');
                window.location.reload();
            }
        });
    }
</script>
<?= $this->endSection() ?>

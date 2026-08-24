<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Suspend & Blokir<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .danger-row > td { background: var(--danger-soft) !important; }
    .danger-row:hover > td { background: rgba(214, 69, 80, 0.16) !important; }
    .strike-chip {
        display: inline-flex; align-items: center; gap: 0.3rem;
        font-family: var(--mono); font-size: 0.72rem; font-weight: 600;
        padding: 0.22rem 0.6rem; border-radius: 999px;
        background: var(--danger-soft); color: var(--danger);
    }
    .strike-chip.zero { background: var(--bg-soft); color: var(--text-tertiary); }
    .toolbar-strip {
        display: flex; justify-content: space-between; align-items: center;
        gap: 1rem; flex-wrap: wrap;
        padding: 1.1rem 1.4rem;
        border-bottom: 1px solid var(--border-color);
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Keamanan · Kontrol Akses</div>
        <h1>Manajemen Akses Siswa</h1>
        <p class="sub">Daftar seluruh siswa terdaftar. Admin dapat mem-ban, me-release, atau mereset seluruh sesi ujian siswa.</p>
    </div>
</div>

<div class="card rise" style="--d:80ms">
    <div class="toolbar-strip">
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <select id="bulkActionSelect" class="form-select form-select-sm" style="width: auto;">
                <option value="">-- Aksi Massal --</option>
                <option value="ban">Ban (Suspend)</option>
                <option value="unban">Release (Unban)</option>
                <option value="reset_login">Reset Sesi Login</option>
            </select>
            <button type="button" class="btn btn-accent btn-sm" onclick="submitBulkAction()"><i class="bi bi-lightning-charge-fill me-1"></i>Terapkan</button>
        </div>

        <!-- Search Form -->
        <form action="<?= base_url('/admin/suspend') ?>" method="GET" class="m-0">
            <div class="input-group input-group-sm" style="max-width: 320px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Cari nama/username..." value="<?= esc($search ?? '') ?>" style="border-left: 0;">
                <button class="btn btn-ghost" type="submit">Cari</button>
                <?php if(!empty($search)): ?>
                    <a href="<?= base_url('/admin/suspend') ?>" class="btn btn-danger-soft" title="Reset Pencarian"><i class="bi bi-x"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <form id="bulkForm" action="<?= base_url('/admin/suspend/bulk-action') ?>" method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="bulkActionHidden">

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 3%; padding-left: 1.4rem;"><input type="checkbox" id="checkAll"></th>
                    <th style="width: 4%;">No</th>
                    <th>Siswa</th>
                    <th>Status Akun</th>
                    <th>Ujian Aktif</th>
                    <th>Total Ujian</th>
                    <th>Total Strikes</th>
                    <th style="width: 30%;" class="text-end pe-4">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <div class="empty-icon"><i class="bi bi-person-slash"></i></div>
                            <h6>Belum ada siswa terdaftar</h6>
                            <p>Data siswa muncul di sini setelah akun dibuat atau diimpor melalui menu Pengguna.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                    <?php $no = 1 + (int)($pager->getCurrentPage('users') - 1) * $pager->getPerPage('users'); foreach ($users as $u): ?>
                    <tr class="<?= !$u->is_active ? 'danger-row' : '' ?>">
                        <td style="padding-left: 1.4rem;"><input type="checkbox" name="user_ids[]" value="<?= $u->id ?>" class="checkItem"></td>
                        <td class="mono" style="color: var(--text-tertiary);"><?= $no++ ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar-tile ink"><?= esc(strtoupper(substr($u->firstname, 0, 1))) ?></div>
                                <div>
                                    <div class="fw-semibold" style="color: var(--text-primary);"><?= esc($u->firstname . ' ' . $u->lastname) ?></div>
                                    <div class="mono" style="font-size: 0.72rem; color: var(--text-tertiary);">@<?= esc($u->username) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($u->is_active): ?>
                                <span class="chip ok"><span class="dot breathe"></span> Aktif</span>
                            <?php else: ?>
                                <span class="chip danger"><i class="bi bi-slash-circle"></i> Banned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u->active_attempts > 0): ?>
                                <span class="chip warn"><i class="bi bi-broadcast"></i> <?= $u->active_attempts ?> sesi</span>
                            <?php else: ?>
                                <span class="row-meta">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="chip ghost num"><?= $u->total_attempts ?></span></td>
                        <td>
                            <?php if ($u->total_strikes > 0): ?>
                                <span class="strike-chip"><i class="bi bi-flag-fill"></i> <?= $u->total_strikes ?> strike</span>
                            <?php else: ?>
                                <span class="strike-chip zero">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <div class="d-flex gap-1 flex-wrap justify-content-end">
                                <?php if ($u->is_active): ?>
                                    <button type="button" onclick="singleAction('<?= base_url('/admin/suspend/ban/' . $u->id) ?>', 'Konfirmasi', 'BAN user <?= esc(addslashes($u->username)) ?>? User tidak akan bisa login.', 'Ya, Ban', '#dc3545')" class="btn btn-sm btn-danger-soft fw-semibold" title="Ban Akun">
                                        <i class="bi bi-ban me-1"></i> Ban
                                    </button>

                                    <button type="button" onclick="singleAction('<?= base_url('/admin/suspend/reset-login/' . $u->id) ?>', 'Konfirmasi Reset Sesi', 'Hapus sesi login <?= esc(addslashes($u->username)) ?>?', 'Ya, Reset', '#0d6efd')" class="btn btn-sm btn-ghost fw-semibold" title="Reset Sesi Login (Multi-Login)">
                                        <i class="bi bi-box-arrow-right me-1"></i> Reset Sesi
                                    </button>
                                <?php else: ?>
                                    <button type="button" onclick="singleAction('<?= base_url('/admin/suspend/release/' . $u->id) ?>', 'Konfirmasi', 'RELEASE user <?= esc(addslashes($u->username)) ?>?', 'Ya, Release', '#198754')" class="btn btn-sm btn-outline-success fw-semibold" title="Lepas Ban">
                                        <i class="bi bi-unlock-fill me-1"></i> Release
                                    </button>
                                <?php endif; ?>

                                <?php if ($u->total_attempts > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger fw-semibold" onclick="showResetModal(<?= $u->id ?>, '<?= esc($u->username) ?>')">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Ujian
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="py-3 d-flex justify-content-center border-top" style="border-color: var(--border-color) !important;">
        <?= $pager->links('users', 'bootstrap_pagination') ?>
    </div>
    </form>
</div>

<!-- Modal Reset Ujian -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-labelledby="resetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-danger" id="resetModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Reset Progress Ujian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Pilih progress ujian dari user <strong id="resetUserName"></strong> yang ingin dihapus. Tindakan ini <b>tidak dapat dibatalkan</b>.</p>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nama Ujian</th>
                                <th>Status</th>
                                <th>Skor</th>
                                <th>Waktu Mulai</th>
                                <th width="15%">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="attemptsTableBody">
                            <tr>
                                <td colspan="5" class="text-center py-3">
                                    <div class="spinner-border text-primary spinner-border-sm" role="status"></div> Memuat data...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between bg-light">
                <form id="formResetAll" action="" method="POST" onsubmit="event.preventDefault(); confirmResetAll();">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-danger fw-bold">
                        <i class="bi bi-trash-fill me-1"></i> Hapus Semua Progress
                    </button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    function singleAction(url, confirmTitle, confirmText, confirmBtnText, btnColor) {
        Swal.fire({
            title: confirmTitle,
            text: confirmText,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: confirmBtnText,
            cancelButtonText: 'Batal',
            confirmButtonColor: btnColor || '#0e8a6b'
        }).then((res) => {
            if (res.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = url;
                
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '<?= csrf_token() ?>';
                csrfInput.value = '<?= csrf_hash() ?>';
                form.appendChild(csrfInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    let currentUserId = null;
    let currentUsername = '';

    document.getElementById('checkAll')?.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.checkItem');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });

    function submitBulkAction() {
        const select = document.getElementById('bulkActionSelect').value;
        const checked = document.querySelectorAll('.checkItem:checked');
        
        if (!select) {
            Swal.fire('Peringatan', 'Silakan pilih aksi massal terlebih dahulu.', 'warning');
            return;
        }
        
        if (checked.length === 0) {
            Swal.fire('Peringatan', 'Pilih minimal satu siswa.', 'warning');
            return;
        }

        const actionText = document.querySelector('#bulkActionSelect option:checked').text;
        
        Swal.fire({
            title: 'Konfirmasi Aksi Massal',
            text: `Anda akan menerapkan aksi "${actionText}" pada ${checked.length} siswa. Lanjutkan?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, Lanjutkan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#0e8a6b'
        }).then((res) => {
            if (res.isConfirmed) {
                document.getElementById('bulkActionHidden').value = select;
                document.getElementById('bulkForm').submit();
            }
        });
    }

    function getStatusBadge(status) {
        switch(parseInt(status)) {
            case 0: return '<span class="badge bg-secondary">Dibuat</span>';
            case 1: return '<span class="badge bg-primary">Aktif</span>';
            case 2: return '<span class="badge bg-warning text-dark">Paused</span>';
            case 3: return '<span class="badge bg-success">Selesai</span>';
            case 4: return '<span class="badge bg-danger">Terkunci</span>';
            default: return '<span class="badge bg-secondary">Unknown</span>';
        }
    }

    function showResetModal(userId, username) {
        currentUserId = userId;
        currentUsername = username;
        document.getElementById('resetUserName').textContent = '@' + username;
        document.getElementById('formResetAll').setAttribute('action', '<?= base_url('/admin/suspend/reset/') ?>' + userId);
        
        const tbody = document.getElementById('attemptsTableBody');
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3"><div class="spinner-border text-primary spinner-border-sm"></div> Memuat data...</td></tr>';
        
        const modal = new bootstrap.Modal(document.getElementById('resetModal'));
        modal.show();

        fetchAttempts();
    }

    function fetchAttempts() {
        fetch('<?= base_url('/admin/suspend/user-attempts/') ?>' + currentUserId)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                const tbody = document.getElementById('attemptsTableBody');
                tbody.innerHTML = '';
                
                if (data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Tidak ada progress ujian.</td></tr>';
                    setTimeout(() => window.location.reload(), 1500);
                    return;
                }

                data.forEach(attempt => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="fw-bold">${attempt.title}</td>
                        <td>${getStatusBadge(attempt.status)}</td>
                        <td>${attempt.score !== null ? parseFloat(attempt.score).toFixed(2) : '-'}</td>
                        <td class="small text-muted">${attempt.started_at || '-'}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="deleteAttempt(${attempt.id})">
                                <i class="bi bi-trash"></i> Hapus
                            </button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(error => {
                document.getElementById('attemptsTableBody').innerHTML = '<tr><td colspan="5" class="text-center py-3 text-danger">Gagal memuat data.</td></tr>';
            });
    }

    function deleteAttempt(attemptId) {
        Swal.fire({
            title: 'Hapus Progress Ini?',
            text: 'Progress, jawaban, dan skor untuk ujian ini akan dihapus permanen!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#d64550'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

                fetch('<?= base_url('/admin/suspend/reset-attempt/') ?>' + attemptId, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(res => {
                    if (res.status === 'success') {
                        Swal.fire({
                            title: 'Berhasil', 
                            text: res.message, 
                            icon: 'success',
                            toast: true,
                            position: 'top-end',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        fetchAttempts();
                    } else {
                        Swal.fire('Gagal', res.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Terjadi kesalahan pada server.', 'error');
                });
            }
        });
    }

    function confirmResetAll() {
        Swal.fire({
            title: 'Konfirmasi Reset Semua',
            text: 'RESET semua ujian ' + currentUsername + '? Semua skor dan jawaban akan DIHAPUS PERMANEN!',
            icon: 'error',
            showCancelButton: true,
            confirmButtonText: 'Ya, Hapus Permanen',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#d64550'
        }).then((res) => {
            if(res.isConfirmed) {
                document.getElementById('formResetAll').submit();
            }
        });
    }
</script>
<?= $this->endSection() ?>

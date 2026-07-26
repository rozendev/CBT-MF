<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Suspend & Blokir<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h5 class="fw-bold text-primary mb-0"><i class="bi bi-shield-lock me-2"></i>Manajemen Akses Siswa</h5>
        <p class="text-muted small mt-1">Daftar seluruh siswa terdaftar. Admin dapat mem-ban, me-release, atau mereset seluruh sesi ujian siswa.</p>
    </div>
    <div class="card-body">
        <form id="bulkForm" action="<?= base_url('/admin/suspend/bulk-action') ?>" method="POST">
        <?= csrf_field() ?>
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex gap-2 align-items-center">
                <select id="bulkActionSelect" name="action" class="form-select form-select-sm" style="width: auto;">
                    <option value="">-- Aksi Massal --</option>
                    <option value="ban">Ban (Suspend)</option>
                    <option value="unban">Release (Unban)</option>
                    <option value="reset_login">Reset Sesi Login</option>
                </select>
                <button type="button" class="btn btn-sm btn-primary" onclick="submitBulkAction()">Terapkan</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th width="3%"><input type="checkbox" id="checkAll"></th>
                        <th width="5%">No</th>
                        <th>Siswa</th>
                        <th>Status Akun</th>
                        <th>Ujian Aktif</th>
                        <th>Total Ujian</th>
                        <th>Total Strikes</th>
                        <th width="28%">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="bi bi-people fs-3 d-block mb-2"></i>
                            Belum ada siswa terdaftar.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php $no = 1 + (int)($pager->getCurrentPage('users') - 1) * $pager->getPerPage('users'); foreach ($users as $u): ?>
                        <tr class="<?= !$u->is_active ? 'table-danger bg-opacity-10' : '' ?>">
                            <td><input type="checkbox" name="user_ids[]" value="<?= $u->id ?>" class="checkItem"></td>
                            <td><?= $no++ ?></td>
                            <td>
                                <div class="fw-bold"><?= esc($u->firstname . ' ' . $u->lastname) ?></div>
                                <div class="small text-muted">@<?= esc($u->username) ?></div>
                            </td>
                            <td>
                                <?php if ($u->is_active): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Aktif</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>Banned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u->active_attempts > 0): ?>
                                    <span class="badge bg-warning text-dark"><?= $u->active_attempts ?> sesi</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= $u->total_attempts ?></span></td>
                            <td>
                                <?php if ($u->total_strikes > 0): ?>
                                    <span class="badge bg-danger rounded-pill"><?= $u->total_strikes ?> strike</span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if ($u->is_active): ?>
                                        <form action="<?= base_url('/admin/suspend/ban/' . $u->id) ?>" method="POST" class="d-inline" onsubmit="event.preventDefault(); Swal.fire({title: 'Konfirmasi', text: 'BAN user <?= esc($u->username) ?>? User tidak akan bisa login.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, Ban', cancelButtonText: 'Batal', confirmButtonColor: '#dc3545'}).then((res) => { if(res.isConfirmed) this.submit(); });">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-danger fw-bold" title="Ban Akun">
                                                <i class="bi bi-ban"></i> Ban
                                            </button>
                                        </form>

                                        <form action="<?= base_url('/admin/suspend/reset-login/' . $u->id) ?>" method="POST" class="d-inline" onsubmit="event.preventDefault(); Swal.fire({title: 'Konfirmasi Reset Sesi', text: 'Hapus sesi login <?= esc($u->username) ?>? Jika diblokir karena multi-login, ini akan mengizinkannya login lagi.', icon: 'info', showCancelButton: true, confirmButtonText: 'Ya, Reset', cancelButtonText: 'Batal', confirmButtonColor: '#0d6efd'}).then((res) => { if(res.isConfirmed) this.submit(); });">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-info text-white fw-bold" title="Reset Sesi Login (Multi-Login)">
                                                <i class="bi bi-box-arrow-right"></i> Reset Sesi
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form action="<?= base_url('/admin/suspend/release/' . $u->id) ?>" method="POST" class="d-inline" onsubmit="event.preventDefault(); Swal.fire({title: 'Konfirmasi', text: 'RELEASE user <?= esc($u->username) ?>?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, Release', cancelButtonText: 'Batal'}).then((res) => { if(res.isConfirmed) this.submit(); });">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm btn-success fw-bold" title="Lepas Ban">
                                                <i class="bi bi-unlock-fill"></i> Release
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($u->total_attempts > 0): ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger fw-bold" onclick="showResetModal(<?= $u->id ?>, '<?= esc($u->username) ?>')">
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
        <div class="mt-3 d-flex justify-content-center">
            <?= $pager->links('users', 'bootstrap_pagination') ?>
        </div>
        </form>
    </div>
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
            confirmButtonColor: '#0d6efd'
        }).then((res) => {
            if (res.isConfirmed) {
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
            confirmButtonColor: '#dc3545'
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
            confirmButtonColor: '#dc3545'
        }).then((res) => {
            if(res.isConfirmed) {
                document.getElementById('formResetAll').submit();
            }
        });
    }
</script>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Suspend & Blokir<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h5 class="fw-bold text-primary mb-0"><i class="bi bi-shield-lock me-2"></i>Manajemen Akses Siswa</h5>
        <p class="text-muted small mt-1">Daftar seluruh siswa terdaftar. Admin dapat mem-ban, me-release, atau mereset seluruh sesi ujian siswa.</p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
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
                        <td colspan="7" class="text-center py-4 text-muted">
                            <i class="bi bi-people fs-3 d-block mb-2"></i>
                            Belum ada siswa terdaftar.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($users as $u): ?>
                        <tr class="<?= !$u->is_active ? 'table-danger bg-opacity-10' : '' ?>">
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
                                        <form action="<?= base_url('/admin/suspend/ban/' . $u->id) ?>" method="POST" class="d-inline" onsubmit="return confirm('BAN user <?= esc($u->username) ?>? User tidak akan bisa login.');">
                                            <button type="submit" class="btn btn-sm btn-danger fw-bold">
                                                <i class="bi bi-ban me-1"></i> Ban
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form action="<?= base_url('/admin/suspend/release/' . $u->id) ?>" method="POST" class="d-inline" onsubmit="return confirm('RELEASE user <?= esc($u->username) ?>?');">
                                            <button type="submit" class="btn btn-sm btn-success fw-bold">
                                                <i class="bi bi-unlock-fill me-1"></i> Release
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($u->total_attempts > 0): ?>
                                        <form action="<?= base_url('/admin/suspend/reset/' . $u->id) ?>" method="POST" class="d-inline" onsubmit="return confirm('RESET semua ujian <?= esc($u->username) ?>? Semua skor dan jawaban akan DIHAPUS PERMANEN!');">
                                            <button type="submit" class="btn btn-sm btn-outline-danger fw-bold">
                                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Ujian
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

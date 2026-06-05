<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Dashboard<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="mb-4">
    <h4 class="fw-bold">Selamat datang, <?= esc(session()->get('firstname') ?? 'Admin') ?>! 👋</h4>
    <p class="text-muted mb-0">Berikut ringkasan sistem ujian Anda.</p>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100" style="border-left:4px solid #4f46e5;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(79,70,229,0.1);">
                    <i class="bi bi-people-fill" style="font-size:1.4rem;color:#4f46e5;"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Pengguna</div>
                    <div class="fw-bold fs-4"><?= esc($stats['users'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100" style="border-left:4px solid #059669;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(5,150,105,0.1);">
                    <i class="bi bi-clipboard-check" style="font-size:1.4rem;color:#059669;"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Ujian</div>
                    <div class="fw-bold fs-4"><?= esc($stats['tests'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100" style="border-left:4px solid #d97706;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(217,119,6,0.1);">
                    <i class="bi bi-question-circle-fill" style="font-size:1.4rem;color:#d97706;"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Soal</div>
                    <div class="fw-bold fs-4"><?= esc($stats['questions'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100" style="border-left:4px solid #0891b2;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(8,145,178,0.1);">
                    <i class="bi bi-play-circle-fill" style="font-size:1.4rem;color:#0891b2;"></i>
                </div>
                <div>
                    <div class="text-muted small">Ujian Aktif</div>
                    <div class="fw-bold fs-4"><?= esc($stats['active_tests'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mb-4">
    <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-lightning-charge me-1"></i> Aksi Cepat</h6>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= base_url('/admin/tests') ?>" class="btn btn-primary btn-sm rounded-pill">
                <i class="bi bi-plus-circle me-1"></i> Buat Ujian Baru
            </a>
            <a href="<?= base_url('/admin/users') ?>" class="btn btn-outline-primary btn-sm rounded-pill">
                <i class="bi bi-person-plus me-1"></i> Tambah Pengguna
            </a>
            <a href="<?= base_url('/admin/questions') ?>" class="btn btn-outline-primary btn-sm rounded-pill">
                <i class="bi bi-plus-square me-1"></i> Tambah Soal
            </a>
            <a href="<?= base_url('/admin/results') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="bi bi-graph-up me-1"></i> Lihat Hasil
            </a>
        </div>
    </div>
</div>

<!-- Bottom Section -->
<div class="row g-4">
    <!-- Recent Activity -->
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-1"></i> Aktivitas Terakhir</h6>
                <?php if (!empty($activities)): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($activities as $act): ?>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-start border-light">
                            <div>
                                <span class="fw-semibold small text-primary"><?= esc($act->firstname ?? $act->username ?? 'System') ?></span>
                                <span class="text-muted small ms-1"><?= esc($act->description ?? $act->action) ?></span>
                            </div>
                            <small class="text-muted" style="font-size: 0.75rem;"><?= esc(date('d/m/Y H:i', strtotime($act->created_at))) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">Belum ada aktivitas tercatat.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Online Users -->
    <div class="col-md-5">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="bi bi-broadcast text-success me-1"></i> User Online (Real-Time)</h6>
                    <span class="badge bg-success rounded-pill px-3 py-2"><?= count($onlineUsers ?? []) ?> Aktif</span>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($onlineUsers)): ?>
                    <div class="list-group list-group-flush" style="max-height: 350px; overflow-y: auto;">
                        <?php foreach ($onlineUsers as $ou): ?>
                        <div class="list-group-item px-0 d-flex justify-content-between align-items-center border-light">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center text-primary fw-bold me-3" style="width: 35px; height: 35px; font-size: 0.9rem;">
                                    <?= esc(strtoupper(substr($ou['firstname'] ?? $ou['username'], 0, 1))) ?>
                                </div>
                                <div>
                                    <h6 class="mb-0 fs-6 fw-semibold text-dark"><?= esc($ou['firstname'] ?? $ou['username']) ?></h6>
                                    <small class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-person-badge"></i> <?= esc(ucfirst($ou['role'])) ?></small>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="spinner-grow spinner-grow-sm text-success" role="status" style="width: 0.5rem; height: 0.5rem;"></span>
                                <div class="text-muted mt-1" style="font-size: 0.7rem;"><?= date('H:i', strtotime($ou['last_active_at'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-moon-stars text-muted fs-1 mb-3 d-block opacity-50"></i>
                        <p class="text-muted small mb-0">Tidak ada user yang sedang online saat ini.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

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

<!-- Recent Activity -->
<div class="card">
    <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-1"></i> Aktivitas Terakhir</h6>
        <?php if (!empty($activities)): ?>
            <div class="list-group list-group-flush">
                <?php foreach ($activities as $act): ?>
                <div class="list-group-item px-0 d-flex justify-content-between align-items-start">
                    <div>
                        <span class="fw-semibold small"><?= esc($act->firstname ?? $act->username ?? 'System') ?></span>
                        <span class="text-muted small ms-1"><?= esc($act->description ?? $act->action) ?></span>
                    </div>
                    <small class="text-muted"><?= esc($act->created_at) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-0">Belum ada aktivitas tercatat.</p>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>

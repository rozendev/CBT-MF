<?= $this->extend('layouts/exam') ?>

<?= $this->section('page_title') ?>Daftar Ujian<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="mt-3">
    <h4 class="fw-bold mb-1">📋 Daftar Ujian Tersedia</h4>
    <p class="text-muted">Pilih ujian yang ingin Anda kerjakan.</p>

    <?php if (!empty($tests)): ?>
        <div class="row g-3 mt-2">
            <?php foreach ($tests as $test): ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="fw-bold"><?= esc($test->name) ?></h6>
                        <p class="text-muted small mb-2"><?= esc($test->description) ?></p>
                        <div class="d-flex gap-3 text-muted small mb-3">
                            <span><i class="bi bi-clock me-1"></i><?= esc($test->duration_minutes) ?> menit</span>
                        </div>
                        <a href="#" class="btn btn-primary btn-sm rounded-pill">
                            <i class="bi bi-play-fill me-1"></i> Mulai Ujian
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card mt-3">
            <div class="card-body text-center py-5">
                <i class="bi bi-inbox" style="font-size:3rem;color:#cbd5e1;"></i>
                <p class="text-muted mt-2 mb-0">Belum ada ujian yang tersedia saat ini.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

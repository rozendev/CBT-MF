<?= $this->extend('layouts/proctor') ?>

<?= $this->section('title') ?>Dashboard Pengawas<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold">Pilih Ujian Aktif</h3>
        <p class="text-muted">Pilih ujian di bawah ini untuk memulai pengawasan secara live (real-time).</p>
    </div>
</div>

<div class="row">
    <?php if (empty($activeExams)): ?>
        <div class="col-12 text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="text-muted">Tidak ada ujian aktif saat ini.</h5>
        </div>
    <?php else: ?>
        <?php foreach ($activeExams as $exam): ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title fw-bold text-primary mb-3">
                            <i class="bi bi-journal-check me-2"></i><?= esc($exam->name) ?>
                        </h5>
                        <p class="card-text text-muted mb-2"><i class="bi bi-info-circle me-1"></i> <?= esc($exam->description ?? '-') ?></p>
                        <p class="card-text mb-4">
                            <span class="badge bg-success">Status: Aktif</span>
                        </p>
                        <a href="<?= base_url('proctor/live/' . $exam->id) ?>" class="btn btn-primary w-100 fw-bold">
                            <i class="bi bi-broadcast me-2"></i> Masuk Live Proctor
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

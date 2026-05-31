<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Dashboard Siswa<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold text-dark mb-1">Selamat Datang, <?= esc(session('firstname')) ?>!</h3>
        <p class="text-muted">Berikut adalah daftar ujian yang tersedia untuk Anda ikuti saat ini.</p>
    </div>
</div>

<?php if (session()->has('error')): ?>
    <div class="alert alert-danger shadow-sm rounded-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= session('error') ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <?php if (empty($availableTests)): ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 py-5 text-center">
                <div class="card-body">
                    <i class="bi bi-calendar-x fs-1 text-muted mb-3 d-block"></i>
                    <h5 class="fw-bold text-secondary">Tidak Ada Ujian Aktif</h5>
                    <p class="text-muted mb-0">Belum ada ujian yang dijadwalkan untuk grup/kelas Anda saat ini.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($availableTests as $test): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 position-relative overflow-hidden">
                    <div class="position-absolute top-0 start-0 w-100 bg-primary" style="height: 4px;"></div>
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5 class="fw-bold text-dark mb-0"><?= esc($test->name) ?></h5>
                            <?php if ($test->duration_minutes > 0): ?>
                                <span class="badge bg-light text-primary border"><i class="bi bi-clock me-1"></i> <?= $test->duration_minutes ?> Menit</span>
                            <?php endif; ?>
                        </div>
                        
                        <p class="text-muted small mb-4" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= strip_tags($test->description) ?: 'Tidak ada deskripsi.' ?>
                        </p>
                        
                        <div class="mb-4">
                            <?php if ($test->begin_time || $test->end_time): ?>
                                <div class="small text-muted mb-1"><i class="bi bi-calendar-event me-2"></i> Jadwal:</div>
                                <div class="small fw-semibold ms-4 text-dark">
                                    <?= $test->begin_time ? date('d/m/Y H:i', strtotime($test->begin_time)) : 'Sekarang' ?> 
                                    - 
                                    <?= $test->end_time ? date('d/m/Y H:i', strtotime($test->end_time)) : 'Selesai' ?>
                                </div>
                            <?php else: ?>
                                <div class="small text-muted"><i class="bi bi-calendar-event me-2"></i> Tersedia kapan saja</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-footer bg-white border-top-0 p-4 pt-0">
                        <?php 
                            $status = $test->attempt_status ?? -1; 
                            // 0=created, 1=active, 2=paused, 3=completed, 4=locked
                        ?>
                        
                        <?php if ($status == 3): ?>
                            <?php if (isset($test->results_visible) && $test->results_visible == 1): ?>
                                <a href="<?= base_url('/student/results/view/' . $test->id) ?>" class="btn btn-outline-success w-100 fw-bold">
                                    <i class="bi bi-award-fill me-1"></i> Lihat Nilai
                                </a>
                            <?php else: ?>
                                <button class="btn btn-light w-100 text-success fw-bold disabled">
                                    <i class="bi bi-check-circle-fill me-1"></i> Selesai Dikerjakan
                                </button>
                            <?php endif; ?>
                        <?php elseif ($status == 1 || $status == 2): ?>
                            <a href="<?= base_url('/student/exam/take/' . $test->id) ?>" class="btn btn-warning w-100 fw-bold">
                                <i class="bi bi-play-fill me-1"></i> Lanjutkan Ujian
                            </a>
                        <?php else: ?>
                            <a href="<?= base_url('/student/exam/prepare/' . $test->id) ?>" class="btn btn-primary w-100 fw-bold">
                                <i class="bi bi-pencil-square me-1"></i> Mulai Ujian
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>

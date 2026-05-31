<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Persiapan Ujian: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8 mt-4">
        <div class="card shadow border-0 rounded-4 overflow-hidden">
            <div class="bg-primary text-white p-4 text-center">
                <i class="bi bi-file-earmark-text display-4 mb-3 d-block opacity-75"></i>
                <h3 class="fw-bold mb-1"><?= esc($test->name) ?></h3>
                <p class="mb-0 text-white-50">Sistem Ujian Online</p>
            </div>
            
            <div class="card-body p-5">
                <?php if (session()->has('error')): ?>
                    <div class="alert alert-danger rounded-3 mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= session('error') ?>
                    </div>
                <?php endif; ?>

                <div class="row mb-4 g-4 text-center">
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3">
                            <i class="bi bi-clock fs-3 text-primary mb-2 d-block"></i>
                            <div class="small text-muted mb-1">Durasi</div>
                            <div class="fw-bold"><?= $test->duration_minutes > 0 ? $test->duration_minutes . ' Menit' : 'Tanpa Batas' ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3">
                            <i class="bi bi-journal-check fs-3 text-success mb-2 d-block"></i>
                            <div class="small text-muted mb-1">Batas Lulus</div>
                            <div class="fw-bold"><?= $test->passing_score ?> / <?= $test->max_score ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3">
                            <i class="bi bi-arrow-repeat fs-3 text-warning mb-2 d-block"></i>
                            <div class="small text-muted mb-1">Pengulangan</div>
                            <div class="fw-bold"><?= $test->is_repeatable ? 'Diizinkan' : 'Satu Kali' ?></div>
                        </div>
                    </div>
                </div>

                <div class="mb-5">
                    <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Petunjuk Ujian</h5>
                    <div class="text-muted" style="line-height: 1.7;">
                        <?= empty($test->description) ? '<p>Tidak ada petunjuk khusus untuk ujian ini.</p>' : $test->description ?>
                    </div>
                </div>

                <form action="<?= base_url('/student/exam/start/' . $test->id) ?>" method="POST" id="startForm">
                    <?= csrf_field() ?>
                    
                    <?php if (!empty($test->password)): ?>
                        <div class="mb-4 bg-light p-3 rounded-3 border border-warning">
                            <label class="form-label fw-bold text-dark"><i class="bi bi-lock-fill me-1 text-warning"></i> Masukkan Password Ujian</label>
                            <input type="password" class="form-control form-control-lg" name="password" required placeholder="Ketik password ujian...">
                            <div class="form-text mt-2 text-muted">Ujian ini dilindungi oleh password. Tanyakan kepada pengawas jika Anda belum mengetahuinya.</div>
                        </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold" id="btnStart" onclick="this.innerHTML='<i class=\'bi bi-hourglass-split me-2\'></i>Mempersiapkan Soal...'; this.classList.add('disabled'); document.getElementById('startForm').submit();">
                            <i class="bi bi-play-circle-fill me-2"></i> Mulai Kerjakan Ujian Sekarang
                        </button>
                        <a href="<?= base_url('/student/dashboard') ?>" class="btn btn-light rounded-pill mt-2">Batal dan Kembali</a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

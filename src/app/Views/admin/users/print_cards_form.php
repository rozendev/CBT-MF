<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>
Cetak Kartu Ujian (via Excel)
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0 fw-bold text-primary">Cetak Kartu Ujian (via Excel)</h3>
        <a href="<?= base_url('admin/users') ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Data Pengguna
        </a>
    </div>

    <?php if(session()->getFlashdata('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc(session()->getFlashdata('error')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-secondary">
                        <i class="bi bi-printer me-2"></i> Form Cetak Kartu
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form action="<?= base_url('admin/users/print-cards/process') ?>" method="POST" enctype="multipart/form-data" target="_blank">
                        <?= csrf_field() ?>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">File Excel Data Siswa (.xls / .xlsx)</label>
                            <input type="file" name="excel_file" class="form-control form-control-lg" accept=".xls,.xlsx" required>
                            <div class="form-text">Gunakan file Excel yang sama persis seperti saat melakukan Import Siswa. Sistem akan mengekstrak Username dan Password asli.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1 py-2 fw-bold" id="btnSubmit">
                                <i class="bi bi-printer me-2"></i> Proses & Cetak Kartu
                            </button>
                            <a href="<?= base_url('admin/users/template') ?>" class="btn btn-outline-secondary py-2 fw-bold" title="Download Template Excel">
                                <i class="bi bi-download"></i> Template
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card bg-light border-0 shadow-sm rounded-3">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3 text-secondary">
                        <i class="bi bi-info-circle me-2"></i> Informasi
                    </h5>
                    <p class="mb-3">Fitur ini bersifat <strong>stateless</strong> (tidak menyimpan ke database). Fitur ini membaca file Excel Anda dan langsung merender Kartu Ujian yang siap dicetak.</p>
                    <ul>
                        <li>Pastikan kolom ke-1 adalah <strong>Username/NISN</strong>.</li>
                        <li>Pastikan kolom ke-2 adalah <strong>Password</strong>.</li>
                        <li>Pastikan kolom ke-3 dan ke-4 adalah <strong>Nama Depan & Belakang</strong>.</li>
                    </ul>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle"></i> Jika halaman cetak terbuka, tekan <code>Ctrl + P</code> pada keyboard Anda untuk mencetak.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

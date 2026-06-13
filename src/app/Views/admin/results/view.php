<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Nilai Siswa: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card shadow-sm mb-4">
    <div class="card-body p-4 bg-light rounded-3">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="fw-bold text-dark mb-1"><?= esc($test->name) ?></h5>
                <p class="text-muted mb-0">Batas Lulus: <?= $test->passing_score ?> / <?= $test->max_score ?></p>
            </div>
            <div class="col-md-4 text-end">
                <a href="<?= base_url('/admin/results') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-people-fill me-2"></i>Daftar Nilai Siswa</h6>
        <div class="d-flex gap-2">
            <form action="<?= base_url('/admin/reports/export') ?>" method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="report_type" value="test">
                <input type="hidden" name="test_id" value="<?= $test->id ?>">
                <button type="submit" class="btn btn-sm btn-success rounded-3">
                    <i class="bi bi-file-earmark-spreadsheet-fill me-1"></i>Export Nilai
                </button>
            </form>
            <form action="<?= base_url('/admin/reports/export') ?>" method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="report_type" value="test_detail">
                <input type="hidden" name="test_id" value="<?= $test->id ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger rounded-3">
                    <i class="bi bi-list-check me-1"></i>Export Detail Soal
                </button>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">No</th>
                        <th>Nama Lengkap</th>
                        <th>NIS/Nomor Induk</th>
                        <th>Waktu Selesai</th>
                        <th>Nilai Akhir</th>
                        <th>Status Kelulusan</th>
                        <th class="text-end pe-4">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($attempts)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Belum ada siswa yang menyelesaikan ujian ini.</td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php $i = 1; foreach ($attempts as $attempt): ?>
                        <tr>
                            <td class="ps-4 text-muted"><?= $i++ ?></td>
                            <td class="fw-bold text-dark"><?= esc($attempt->firstname . ' ' . $attempt->lastname) ?></td>
                            <td><?= esc($attempt->registration_number ?: $attempt->username) ?></td>
                            <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($attempt->finished_at)) ?></td>
                            <td>
                                <span class="fs-5 fw-bold <?= $attempt->score >= $test->passing_score ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format($attempt->score, 2) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($attempt->score >= $test->passing_score): ?>
                                    <span class="badge bg-success rounded-pill px-3">LULUS</span>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill px-3">TIDAK LULUS</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-1">
                                    <a href="<?= base_url('/admin/results/detail/' . $attempt->id) ?>" class="btn btn-sm btn-outline-primary" title="Lihat Jawaban">
                                        <i class="bi bi-card-checklist"></i> Rincian
                                    </a>
                                    <form action="<?= base_url('/admin/results/delete-attempt/' . $attempt->id) ?>" method="POST" class="d-inline" onsubmit="event.preventDefault(); Swal.fire({title: 'Hapus Hasil Ujian?', text: 'Apakah Anda yakin ingin menghapus hasil ujian siswa ini? Data tidak dapat dikembalikan!', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, Hapus', cancelButtonText: 'Batal', confirmButtonColor: '#dc3545'}).then((res) => { if(res.isConfirmed) this.submit(); });">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Hasil">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

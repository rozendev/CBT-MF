<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Laporan Nilai Ujian<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card shadow-sm">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-primary"><i class="bi bi-bar-chart-fill me-2"></i>Rekapitulasi Nilai Ujian</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nama Ujian</th>
                        <th>Target Skala (Max)</th>
                        <th>Jumlah Peserta Selesai</th>
                        <th>Rata-rata Nilai</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($tests)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">Belum ada data ujian.</td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php foreach ($tests as $test): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-dark"><?= esc($test->name) ?></td>
                            <td><?= $test->max_score ?></td>
                            <td>
                                <span class="badge bg-info text-dark rounded-pill px-3"><?= $test->total_attempts ?> Siswa</span>
                            </td>
                            <td>
                                <?php if ($test->total_attempts > 0): ?>
                                    <span class="fw-bold text-success"><?= number_format($test->average_score, 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="<?= base_url('/admin/results/view/' . $test->id) ?>" class="btn btn-sm btn-primary">
                                    <i class="bi bi-eye"></i> Lihat Nilai Siswa
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

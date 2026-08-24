<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Laporan Nilai Ujian<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Evaluasi</div>
        <h1>Rekapitulasi Nilai</h1>
        <p class="sub">Ringkasan pencapaian peserta per ujian — rata-rata nilai dan jumlah yang menyelesaikan.</p>
    </div>
</div>

<div class="card rise" style="--d:80ms">
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
                            <td colspan="5">
                                <div class="empty">
                                    <div class="empty-icon"><i class="bi bi-bar-chart"></i></div>
                                    <h6>Belum ada data ujian</h6>
                                    <p>Setelah ujian dijalankan, rekapitulasi nilai akan tampil di sini.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($tests as $test): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="avatar-tile moss"><i class="bi bi-clipboard-check"></i></div>
                                    <div class="fw-bold" style="color: var(--text-primary);"><?= esc($test->name) ?></div>
                                </div>
                            </td>
                            <td><span class="chip ghost num"><?= $test->max_score ?></span></td>
                            <td>
                                <span class="chip info"><i class="bi bi-person-check"></i> <span class="num"><?= $test->total_attempts ?></span> Siswa</span>
                            </td>
                            <td>
                                <?php if ($test->total_attempts > 0): ?>
                                    <span class="fw-bold num" style="color: var(--ok);"><?= number_format($test->average_score, 2) ?></span>
                                <?php else: ?>
                                    <span class="row-meta">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="<?= base_url('/admin/results/view/' . $test->id) ?>" class="btn btn-sm btn-accent">
                                    <i class="bi bi-eye me-1"></i> Lihat Nilai Siswa
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

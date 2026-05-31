<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Manajemen Ujian<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card mb-4">
    <div class="card-body py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold text-secondary"><i class="bi bi-info-circle me-1"></i> Atur definisi ujian dan pengaturan pelaksanaannya.</h6>
        <a href="<?= base_url('/admin/tests/create') ?>" class="btn btn-primary btn-sm rounded-pill px-3">
            <i class="bi bi-plus-circle me-1"></i> Buat Ujian Baru
        </a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h6 class="m-0 fw-bold"><i class="bi bi-clipboard-check me-1"></i> Daftar Ujian</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nama Ujian</th>
                        <th>Jadwal Pelaksanaan</th>
                        <th>Durasi</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tests)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x fs-2 d-block mb-2 text-light"></i>
                                Belum ada ujian yang dibuat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tests as $t): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?= esc($t->name) ?></div>
                                </td>
                                <td>
                                    <?php if ($t->begin_time || $t->end_time): ?>
                                        <div class="small">
                                            <span class="text-success"><i class="bi bi-play-circle"></i> <?= $t->begin_time ? date('d/m/Y H:i', strtotime($t->begin_time)) : 'Kapan saja' ?></span><br>
                                            <span class="text-danger"><i class="bi bi-stop-circle"></i> <?= $t->end_time ? date('d/m/Y H:i', strtotime($t->end_time)) : 'Tanpa batas' ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border">Kapan saja</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($t->duration_minutes > 0): ?>
                                        <span class="badge bg-info text-white"><?= $t->duration_minutes ?> Menit</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Tanpa Batas</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($t->is_enabled): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="<?= base_url('/admin/tests/config/' . $t->id) ?>" class="btn btn-sm btn-outline-info" title="Konfigurasi Soal & Peserta">
                                        <i class="bi bi-gear"></i> Konfigurasi
                                    </a>
                                    <a href="<?= base_url('/admin/tests/edit/' . $t->id) ?>" class="btn btn-sm btn-outline-primary" title="Edit Pengaturan Dasar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus" 
                                            onclick="confirmDelete(<?= $t->id ?>, '<?= esc(addslashes($t->name)) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pager): ?>
    <div class="card-footer bg-white py-3 border-top d-flex justify-content-end">
        <?= $pager->links('default', 'bootstrap_pagination') ?>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="mb-0">Apakah Anda yakin ingin menghapus ujian <br><strong id="deleteTestName" class="fs-5 mt-2 d-block"></strong></p>
                <p class="text-muted small mt-2">Data nilai ujian dan log ujian untuk peserta akan ikut terhapus.</p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Batal</button>
                <form id="deleteForm" method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-danger px-4">Ya, Hapus</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    function confirmDelete(id, name) {
        document.getElementById('deleteTestName').textContent = name;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/tests/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>
<?= $this->endSection() ?>

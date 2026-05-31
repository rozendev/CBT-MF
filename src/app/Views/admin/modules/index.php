<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Manajemen Modul<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 fw-bold"><i class="bi bi-folder2 me-1"></i> Daftar Modul</h6>
        <a href="<?= base_url('/admin/modules/create') ?>" class="btn btn-primary btn-sm rounded-pill">
            <i class="bi bi-plus-circle me-1"></i> Tambah Modul
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nama Modul</th>
                        <th>Jumlah Subjek</th>
                        <th>Pembuat</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($modules)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">Belum ada modul yang ditambahkan.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($modules as $module): ?>
                            <tr>
                                <td class="ps-4 fw-medium text-dark">
                                    <i class="bi bi-folder text-warning me-2 fs-5"></i>
                                    <?= esc($module->name) ?>
                                </td>
                                <td>
                                    <a href="<?= base_url('/admin/subjects?module_id=' . $module->id) ?>" class="text-decoration-none">
                                        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-3">
                                            <?= $module->subject_count ?> Subjek
                                        </span>
                                    </a>
                                </td>
                                <td class="text-muted small"><?= esc($module->author_name ?? 'System') ?></td>
                                <td>
                                    <?php if ($module->is_enabled): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="<?= base_url('/admin/modules/edit/' . $module->id) ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($module->id != 1): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus" 
                                            onclick="confirmDelete(<?= $module->id ?>, '<?= esc(addslashes($module->name)) ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
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
                <p class="mb-0">Apakah Anda yakin ingin menghapus modul <br><strong id="deleteModuleName" class="fs-5 mt-2 d-block"></strong></p>
                <p class="text-muted small mt-2">Semua subjek dan soal di dalam modul ini juga akan ikut terhapus.</p>
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
        document.getElementById('deleteModuleName').textContent = name;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/modules/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>
<?= $this->endSection() ?>

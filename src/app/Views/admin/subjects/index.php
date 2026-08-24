<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Manajemen Subjek Topik<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Struktur Kurikulum</div>
        <h1>Subjek Topik</h1>
        <p class="sub">Daftar subjek atau topik mata pelajaran di dalam modul.</p>
    </div>
    <div class="actions">
        <a href="<?= base_url('/admin/subjects/create') ?><?= $moduleId ? '?module_id='.$moduleId : '' ?>" class="btn btn-accent btn-sm">
            <i class="bi bi-plus-circle me-1"></i> Tambah Subjek
        </a>
    </div>
</div>

<div class="card mb-4 rise" style="--d:60ms">
    <div class="card-body py-3">
        <form action="<?= base_url('/admin/subjects') ?>" method="GET" class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-funnel"></i></span>
                    <select name="module_id" class="form-select" onchange="this.form.submit()" style="border-left: 0;">
                        <option value="">Semua Modul</option>
                        <?php foreach ($modules as $mod): ?>
                            <option value="<?= $mod->id ?>" <?= ($moduleId == $mod->id) ? 'selected' : '' ?>>
                                <?= esc($mod->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card rise" style="--d:120ms">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Modul</th>
                        <th>Subjek Topik</th>
                        <th>Deskripsi</th>
                        <th>Pembuat</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subjects)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <div class="empty-icon"><i class="bi bi-bookmark-x"></i></div>
                                    <h6>Tidak ada subjek ditemukan</h6>
                                    <p>Ubah filter modul atau tambahkan subjek topik baru.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="chip ghost">
                                        <?= esc($subject->module_name) ?>
                                    </span>
                                </td>
                                <td class="fw-medium" style="color: var(--text-primary);"><?= esc($subject->name) ?></td>
                                <td class="small text-truncate" style="max-width: 200px; color: var(--text-secondary);" title="<?= esc($subject->description) ?>">
                                    <?= esc($subject->description ?? '-') ?>
                                </td>
                                <td class="small" style="color: var(--text-secondary);"><?= esc($subject->author_name ?? 'System') ?></td>
                                <td>
                                    <?php if ($subject->is_enabled): ?>
                                        <span class="chip ok"><span class="dot breathe"></span> Aktif</span>
                                    <?php else: ?>
                                        <span class="chip danger"><i class="bi bi-slash-circle"></i> Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="<?= base_url('/admin/questions?subject_id=' . $subject->id) ?>" class="btn btn-sm btn-outline-info" title="Lihat Soal">
                                        <i class="bi bi-card-list"></i>
                                    </a>
                                    <a href="<?= base_url('/admin/subjects/edit/' . $subject->id) ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus" 
                                            onclick="confirmDelete(<?= $subject->id ?>, '<?= esc(addslashes($subject->name)) ?>')">
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
    <div class="card-footer py-3 border-top d-flex justify-content-end">
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
                <p class="mb-0">Apakah Anda yakin ingin menghapus subjek <br><strong id="deleteSubjectName" class="fs-5 mt-2 d-block"></strong></p>
                <p class="text-muted small mt-2">Semua soal di dalam subjek ini juga akan ikut terhapus.</p>
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
        document.getElementById('deleteSubjectName').textContent = name;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/subjects/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>
<?= $this->endSection() ?>

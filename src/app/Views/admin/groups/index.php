<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Manajemen Grup<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Organisasi Peserta</div>
        <h1>Manajemen Grup</h1>
        <p class="sub">Kelompokkan siswa ke dalam kelas atau rombel untuk penugasan ujian dan laporan.</p>
    </div>
    <div class="actions">
        <a href="<?= base_url('/admin/groups/create') ?>" class="btn btn-accent btn-sm">
            <i class="bi bi-plus-circle me-1"></i> Tambah Grup
        </a>
    </div>
</div>

<div class="card rise" style="--d:80ms">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nama Grup</th>
                        <th>Deskripsi</th>
                        <th>Jumlah Anggota</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($groups)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <div class="empty-icon"><i class="bi bi-collection"></i></div>
                                    <h6>Belum ada grup</h6>
                                    <p>Buat grup pertama — misalnya kelas atau rombel — untuk menata peserta ujian.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($groups as $group): ?>
                            <tr>
                                <td class="ps-4 fw-medium" style="color: var(--text-primary);"><?= esc($group->name) ?></td>
                                <td class="small" style="color: var(--text-secondary);"><?= esc($group->description ?? '-') ?></td>
                                <td>
                                    <span class="chip info"><i class="bi bi-people"></i> <span class="num"><?= $group->member_count ?></span></span>
                                </td>
                                <td>
                                    <?php if ($group->is_active): ?>
                                        <span class="chip ok"><span class="dot breathe"></span> Aktif</span>
                                    <?php else: ?>
                                        <span class="chip danger"><i class="bi bi-slash-circle"></i> Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="<?= base_url('/admin/groups/edit/' . $group->id) ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <?php if ($group->id != 1): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus" 
                                            onclick="confirmDelete(<?= $group->id ?>, '<?= esc(addslashes($group->name)) ?>')">
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
    <div class="card-footer py-3 border-top d-flex justify-content-end">
        <?= $pager->links('default', 'bootstrap_pagination') ?>
    </div>
    <?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="mb-0">Apakah Anda yakin ingin menghapus grup <br><strong id="deleteGroupName" class="fs-5 mt-2 d-block"></strong></p>
                <p class="text-muted small mt-2">Semua data yang terkait dengan grup ini (termasuk akses ujian) akan terpengaruh.</p>
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
        document.getElementById('deleteGroupName').textContent = name;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/groups/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>
<?= $this->endSection() ?>

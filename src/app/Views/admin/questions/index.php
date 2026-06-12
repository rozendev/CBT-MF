<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Bank Soal<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card mb-4">
    <div class="card-body py-3">
        <form action="<?= base_url('/admin/questions') ?>" method="GET" class="row g-3 align-items-center">
            <div class="col-md-5">
                <select name="subject_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Semua Subjek</option>
                    <?php foreach ($subjectsByModule as $moduleName => $subjects): ?>
                        <optgroup label="<?= esc($moduleName) ?>">
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub->id ?>" <?= ($subjectId == $sub->id) ? 'selected' : '' ?>>
                                    <?= esc($sub->name) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-7 d-flex justify-content-end">
                <a href="<?= base_url('admin/questions/word-import') ?>" class="btn btn-outline-success btn-sm rounded-pill px-3 me-2">
                    <i class="bi bi-file-earmark-word me-1"></i> Import Word
                </a>
                <a href="<?= base_url('/admin/questions/create') ?><?= $subjectId ? '?subject_id='.$subjectId : '' ?>" class="btn btn-primary btn-sm rounded-pill px-3">
                    <i class="bi bi-plus-circle me-1"></i> Tambah Soal
                </a>
            </div>
        </form>
    </div>
</div>

<form action="<?= base_url('/admin/questions/bulk-delete') ?>" method="POST" id="bulkDeleteForm">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold"><i class="bi bi-question-circle me-1"></i> Daftar Soal</h6>
            <button type="button" class="btn btn-sm btn-danger d-none" id="btnBulkDelete" onclick="confirmBulkDelete()">
                <i class="bi bi-trash me-1"></i> Hapus Terpilih (<span id="bulkCount">0</span>)
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width: 40px;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="checkAll">
                                </div>
                            </th>
                            <th style="width: 45%;">Pertanyaan</th>
                        <th>Subjek (Modul)</th>
                        <th>Tipe</th>
                        <th>Level</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($questions)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-light"></i>
                                Tidak ada soal yang ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $types = [
                            1 => 'Pilihan Ganda (1 Jawaban)',
                            2 => 'Pilihan Ganda (Banyak Jawaban)',
                            3 => 'Esai / Teks Singkat',
                            4 => 'Mengurutkan'
                        ];
                        foreach ($questions as $q): 
                        ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="form-check">
                                        <input class="form-check-input question-checkbox" type="checkbox" name="question_ids[]" value="<?= $q->id ?>">
                                    </div>
                                </td>
                                <td>
                                    <div class="text-dark mb-1" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                        <?= strip_tags($q->description) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border d-block text-start mb-1" style="width: max-content;">
                                        <?= esc($q->subject_name) ?>
                                    </span>
                                    <small class="text-muted"><?= esc($q->module_name) ?></small>
                                </td>
                                <td class="small text-muted"><?= $types[$q->type] ?? 'Unknown' ?></td>
                                <td>
                                    <span class="badge bg-secondary rounded-pill"><?= $q->difficulty ?></span>
                                </td>
                                <td>
                                    <?php if ($q->is_enabled): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-2">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-2">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-outline-info" title="Preview" 
                                            onclick="previewQuestion(<?= $q->id ?>)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <a href="<?= base_url('/admin/questions/edit/' . $q->id) ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Hapus" 
                                            onclick="confirmDelete(<?= $q->id ?>)">
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
</form>

<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="mb-0">Apakah Anda yakin ingin menghapus soal ini?</p>
                <p class="text-muted small mt-2">Semua pilihan jawaban untuk soal ini juga akan terhapus secara permanen.</p>
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

<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Konfirmasi Bulk Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 text-center">
                <p class="mb-0">Apakah Anda yakin ingin menghapus <strong id="bulkDeleteCountText"></strong> soal sekaligus?</p>
                <p class="text-muted small mt-2">Semua pilihan jawaban untuk soal-soal ini juga akan terhapus secara permanen. Tindakan ini tidak bisa dibatalkan.</p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger px-4" onclick="document.getElementById('bulkDeleteForm').submit()">Ya, Hapus Semua</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-eye text-primary me-2"></i>Preview Soal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4 px-4" id="previewModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-end">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    function previewQuestion(id) {
        const modalBody = document.getElementById('previewModalBody');
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
        
        // Prevent creating multiple backdrops by using getOrCreateInstance
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal'));
        modal.show();

        fetch('<?= base_url('/admin/questions/preview/') ?>' + id)
            .then(response => response.text())
            .then(html => {
                modalBody.innerHTML = html;
            })
            .catch(error => {
                console.error(error);
                modalBody.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Terjadi kesalahan saat memuat preview soal.</div>';
            });
    }

    function confirmDelete(id) {
        document.getElementById('deleteForm').action = '<?= base_url('/admin/questions/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function confirmBulkDelete() {
        new bootstrap.Modal(document.getElementById('bulkDeleteModal')).show();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const checkAll = document.getElementById('checkAll');
        const checkboxes = document.querySelectorAll('.question-checkbox');
        const btnBulkDelete = document.getElementById('btnBulkDelete');
        const bulkCount = document.getElementById('bulkCount');
        const bulkDeleteCountText = document.getElementById('bulkDeleteCountText');

        function updateBulkDeleteButton() {
            const checkedBoxes = document.querySelectorAll('.question-checkbox:checked');
            const count = checkedBoxes.length;
            bulkCount.textContent = count;
            bulkDeleteCountText.textContent = count;
            
            if (count > 0) {
                btnBulkDelete.classList.remove('d-none');
            } else {
                btnBulkDelete.classList.add('d-none');
            }
            
            checkAll.checked = (count > 0 && count === checkboxes.length);
        }

        if (checkAll) {
            checkAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBulkDeleteButton();
            });
        }

        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateBulkDeleteButton);
        });
    });
</script>
<?= $this->endSection() ?>

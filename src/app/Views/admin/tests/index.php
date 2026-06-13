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
                                    <br>
                                    <?php if ($t->exam_mode == 'static'): ?>
                                        <span class="badge bg-primary mt-1" title="Digenerate: <?= $t->static_generated_at ?>"><i class="bi bi-lightning-charge-fill"></i> Static Mode</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary mt-1">Normal Mode</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group">
                                        <a href="<?= base_url('/admin/tests/config/' . $t->id) ?>" class="btn btn-sm btn-outline-info" title="Konfigurasi Soal & Peserta">
                                            <i class="bi bi-gear"></i> Konfig
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle-split"
                                            data-bs-toggle="offcanvas" data-bs-target="#actionSheet"
                                            data-id="<?= $t->id ?>"
                                            data-name="<?= esc($t->name) ?>"
                                            data-mode="<?= $t->exam_mode ?>"
                                            data-static-url="<?= $t->exam_mode == 'static' ? base_url($t->static_page_path) : '' ?>"
                                            title="Aksi Lainnya">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                    </div>
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

<!-- Offcanvas Action Sheet (outside card to avoid overflow clipping) -->
<div class="offcanvas offcanvas-bottom" tabindex="-1" id="actionSheet" style="height: auto; max-height: 75vh; border-top-left-radius: 20px; border-top-right-radius: 20px;">
    <div class="mx-auto mt-2 mb-1" style="width:40px;height:4px;background:#dee2e6;border-radius:4px;"></div>
    <div class="offcanvas-header border-bottom pb-2">
        <div>
            <div class="small text-muted">Aksi untuk ujian</div>
            <h5 class="offcanvas-title fw-bold mb-0" id="actionSheetTitle">—</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-3">
        <div class="row g-2" style="max-width: 600px; margin: 0 auto;">
            <!-- Edit -->
            <div class="col-12">
                <a id="actionEdit" href="#" class="btn btn-light w-100 text-start py-3 px-3 d-flex align-items-center gap-3 border-0 rounded-3">
                    <span class="d-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary" style="width:44px;height:44px;flex-shrink:0;">
                        <i class="bi bi-pencil-square fs-5"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-semibold">Edit Pengaturan Dasar</div>
                        <small class="text-muted">Ubah nama, waktu, password, dll</small>
                    </div>
                </a>
            </div>

            <!-- Static Section -->
            <div class="col-12 mt-2"><small class="text-muted fw-semibold text-uppercase" style="letter-spacing:0.5px;">Mode Statis</small></div>

            <div class="col-6" id="actionStaticOpen">
                <a href="#" target="_blank" class="btn btn-light w-100 text-start py-3 px-3 d-flex align-items-center gap-3 border-0 rounded-3 h-100">
                    <span class="d-flex align-items-center justify-content-center rounded-3 bg-info bg-opacity-10 text-info" style="width:44px;height:44px;flex-shrink:0;">
                        <i class="bi bi-box-arrow-up-right fs-5"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-semibold">Buka Halaman</div>
                        <small class="text-muted">Tab baru</small>
                    </div>
                </a>
            </div>
            <div class="col-6" id="actionStaticGenerate">
                <button type="button" onclick="doStaticAction('generate')" class="btn btn-light w-100 text-start py-3 px-3 d-flex align-items-center gap-3 border-0 rounded-3 h-100">
                    <span class="d-flex align-items-center justify-content-center rounded-3 bg-success bg-opacity-10 text-success" style="width:44px;height:44px;flex-shrink:0;">
                        <i class="bi bi-lightning-charge-fill fs-5" id="staticGenerateIcon"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-semibold" id="staticGenerateLabel">Generate</div>
                        <small class="text-muted" id="staticGenerateDesc">Buat HTML statis</small>
                    </div>
                </button>
            </div>
            <div class="col-12" id="actionStaticDelete">
                <button type="button" onclick="doStaticAction('delete')" class="btn btn-light w-100 text-start py-3 px-3 d-flex align-items-center gap-3 border-0 rounded-3">
                    <span class="d-flex align-items-center justify-content-center rounded-3 bg-warning bg-opacity-10 text-warning" style="width:44px;height:44px;flex-shrink:0;">
                        <i class="bi bi-x-circle fs-5"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-semibold">Matikan Mode Statis</div>
                        <small class="text-muted">Hapus file HTML, kembali ke Normal</small>
                    </div>
                </button>
            </div>

            <!-- Other Actions -->
            <div class="col-12 mt-2"><small class="text-muted fw-semibold text-uppercase" style="letter-spacing:0.5px;">Lainnya</small></div>

            <div class="col-6">
                <button type="button" onclick="doExtendTime()" class="btn btn-light w-100 text-start py-3 px-3 d-flex align-items-center gap-3 border-0 rounded-3 h-100">
                    <span class="d-flex align-items-center justify-content-center rounded-3 bg-success bg-opacity-10 text-success" style="width:44px;height:44px;flex-shrink:0;">
                        <i class="bi bi-clock-history fs-5"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-semibold">Tambah Waktu</div>
                        <small class="text-muted">Perpanjang durasi</small>
                    </div>
                </button>
            </div>
            <div class="col-6">
                <button type="button" onclick="doDelete()" class="btn btn-light w-100 text-start py-3 px-3 d-flex align-items-center gap-3 border-0 rounded-3 h-100">
                    <span class="d-flex align-items-center justify-content-center rounded-3 bg-danger bg-opacity-10 text-danger" style="width:44px;height:44px;flex-shrink:0;">
                        <i class="bi bi-trash fs-5"></i>
                    </span>
                    <div class="text-start">
                        <div class="fw-semibold">Hapus Ujian</div>
                        <small class="text-muted">Hapus permanen</small>
                    </div>
                </button>
            </div>
        </div>
    </div>
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

<form id="generateStaticForm" method="POST" class="d-none">
    <?= csrf_field() ?>
</form>

<form id="deleteStaticForm" method="POST" class="d-none">
    <?= csrf_field() ?>
</form>
<form id="extendTimeForm" method="POST" class="d-none">
    <?= csrf_field() ?>
    <input type="hidden" name="minutes" id="extendTimeMinutes">
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    let ctx = { id: null, name: '', mode: 'normal', staticUrl: '' };

    const actionSheet = document.getElementById('actionSheet');
    actionSheet.addEventListener('show.bs.offcanvas', function (e) {
        const btn = e.relatedTarget;
        ctx.id = btn.dataset.id;
        ctx.name = btn.dataset.name;
        ctx.mode = btn.dataset.mode;
        ctx.staticUrl = btn.dataset.staticUrl;

        document.getElementById('actionSheetTitle').textContent = ctx.name;
        document.getElementById('actionEdit').href = '<?= base_url('/admin/tests/edit/') ?>/' + ctx.id;

        const isStatic = ctx.mode === 'static';
        document.getElementById('actionStaticOpen').style.display = isStatic ? '' : 'none';
        document.getElementById('actionStaticDelete').style.display = isStatic ? '' : 'none';
        if (isStatic) {
            document.getElementById('actionStaticOpen').querySelector('a').href = ctx.staticUrl;
        }

        const genIcon = document.getElementById('staticGenerateIcon');
        const genLabel = document.getElementById('staticGenerateLabel');
        const genDesc = document.getElementById('staticGenerateDesc');
        if (isStatic) {
            genIcon.className = 'bi bi-arrow-repeat fs-5';
            genLabel.textContent = 'Update HTML';
            genDesc.textContent = 'Generate ulang';
        } else {
            genIcon.className = 'bi bi-lightning-charge-fill fs-5';
            genLabel.textContent = 'Generate Statis';
            genDesc.textContent = 'Buat HTML statis';
        }
    });

    function closeSheet() {
        bootstrap.Offcanvas.getInstance(actionSheet)?.hide();
    }

    function doStaticAction(action) {
        closeSheet();
        const url = action === 'generate'
            ? '<?= base_url('/admin/tests/static/generate/') ?>/' + ctx.id
            : '<?= base_url('/admin/tests/static/delete/') ?>/' + ctx.id;
        Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const formId = action === 'generate' ? 'generateStaticForm' : 'deleteStaticForm';
        document.getElementById(formId).action = url;
        document.getElementById(formId).submit();
    }

    function doExtendTime() {
        closeSheet();
        extendTime(ctx.id, ctx.name);
    }

    function doDelete() {
        closeSheet();
        confirmDelete(ctx.id, ctx.name);
    }

    function extendTime(id, name) {
        Swal.fire({
            title: 'Tambah Waktu Ujian',
            html: `Tambahkan waktu ekstra untuk ujian <b>${name}</b>.<br><br>Pilih jumlah tambahan waktu:
                   <select id="swalExtendTime" class="form-select mt-3">
                       <option value="5">+5 Menit</option>
                       <option value="10" selected>+10 Menit</option>
                       <option value="15">+15 Menit</option>
                       <option value="30">+30 Menit</option>
                       <option value="60">+60 Menit</option>
                   </select>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Tambahkan',
            cancelButtonText: 'Batal',
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('extendTimeMinutes').value = document.getElementById('swalExtendTime').value;
                const form = document.getElementById('extendTimeForm');
                form.action = '<?= base_url('/admin/tests/extend-time/') ?>/' + id;
                form.submit();
            }
        });
    }

    function confirmDelete(id, name) {
        document.getElementById('deleteTestName').textContent = name;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/tests/delete/') ?>/' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function submitStaticAction(url, action) {
        Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const formId = action === 'generate' ? 'generateStaticForm' : 'deleteStaticForm';
        document.getElementById(formId).action = url;
        document.getElementById(formId).submit();
    }
</script>
<?= $this->endSection() ?>

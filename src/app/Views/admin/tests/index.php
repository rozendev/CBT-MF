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
                                        <button type="button" class="btn btn-sm <?= $t->exam_mode == 'static' ? 'btn-primary' : 'btn-outline-primary' ?>" title="Pengaturan Ujian Statis" onclick="openStaticMenu(<?= $t->id ?>, <?= $t->exam_mode == 'static' ? 'true' : 'false' ?>, '<?= $t->exam_mode == 'static' ? base_url($t->static_page_path) : '' ?>')">
                                            <i class="bi bi-lightning-charge"></i> Static
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span class="visually-hidden">Toggle Dropdown</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size: 14px;">
                                            <li><a class="dropdown-item py-2" href="<?= base_url('/admin/tests/edit/' . $t->id) ?>"><i class="bi bi-pencil me-2 text-primary"></i> Edit Pengaturan Dasar</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item py-2 text-success" onclick="extendTime(<?= $t->id ?>, '<?= esc(addslashes($t->name)) ?>')">
                                                    <i class="bi bi-clock-history me-2"></i> Tambah Waktu
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item py-2 text-danger" onclick="confirmDelete(<?= $t->id ?>, '<?= esc(addslashes($t->name)) ?>')">
                                                    <i class="bi bi-trash me-2"></i> Hapus Ujian
                                                </button>
                                            </li>
                                        </ul>
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
    function extendTime(id, name) {
        Swal.fire({
            title: `Tambah Waktu Ujian`,
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
                const minutes = document.getElementById('swalExtendTime').value;
                document.getElementById('extendTimeMinutes').value = minutes;
                const form = document.getElementById('extendTimeForm');
                form.action = '<?= base_url('/admin/tests/extend-time/') ?>' + id;
                form.submit();
            }
        });
    }

    function confirmDelete(id, name) {
        document.getElementById('deleteTestName').textContent = name;
        document.getElementById('deleteForm').action = '<?= base_url('/admin/tests/delete/') ?>' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function openStaticMenu(id, isStatic, staticUrl) {
        if (isStatic) {
            Swal.fire({
                title: 'Menu Ujian Statis',
                html: `
                    <div class="d-grid gap-3 mt-4">
                        <button class="btn btn-outline-info p-3 d-flex align-items-center text-start w-100" onclick="window.open('${staticUrl}', '_blank'); Swal.close();">
                            <i class="bi bi-box-arrow-up-right me-3 fs-3"></i>
                            <div><strong class="d-block mb-1">Buka Halaman Statis</strong><small class="text-muted">Akses tautan ujian di CDN</small></div>
                        </button>
                        <button class="btn btn-outline-success p-3 d-flex align-items-center text-start w-100" onclick="submitStaticAction('<?= base_url('/admin/tests/static/generate/') ?>${id}', 'generate')">
                            <i class="bi bi-arrow-repeat me-3 fs-3"></i>
                            <div><strong class="d-block mb-1">Update Halaman HTML</strong><small class="text-muted">Generate ulang jika ada soal yang berubah</small></div>
                        </button>
                        <button class="btn btn-outline-danger p-3 d-flex align-items-center text-start w-100" onclick="submitStaticAction('<?= base_url('/admin/tests/static/delete/') ?>${id}', 'delete')">
                            <i class="bi bi-x-circle me-3 fs-3"></i>
                            <div><strong class="d-block mb-1">Matikan Mode Statis</strong><small class="text-muted">Hapus file dan kembali ke mode normal</small></div>
                        </button>
                    </div>
                `,
                showConfirmButton: false,
                showCloseButton: true,
            });
        } else {
            Swal.fire({
                title: 'Aktifkan Mode Statis?',
                text: 'Mode ini akan men-generate halaman HTML ujian secara statis untuk performa maksimal di Cloudflare / CDN.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-lightning-charge-fill me-1"></i> Ya, Generate!',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#0d6efd'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitStaticAction('<?= base_url('/admin/tests/static/generate/') ?>' + id, 'generate');
                }
            });
        }
    }

    function submitStaticAction(url, action) {
        Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const formId = action === 'generate' ? 'generateStaticForm' : 'deleteStaticForm';
        const form = document.getElementById(formId);
        form.action = url;
        form.submit();
    }
</script>
<?= $this->endSection() ?>

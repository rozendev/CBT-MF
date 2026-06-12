<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>
Export Laporan
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<style>
    .select2-container--bootstrap-5 .select2-selection {
        border-color: var(--border-color);
        background-color: var(--bg-body);
        color: var(--text-primary);
        min-height: 42px;
        border-radius: 8px;
    }
    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
        color: var(--text-primary);
        line-height: 2.2;
    }
    [data-theme="dark"] .select2-container--bootstrap-5 .select2-dropdown {
        background-color: var(--bg-surface);
        border-color: var(--border-color);
    }
    [data-theme="dark"] .select2-container--bootstrap-5 .select2-results__option {
        color: var(--text-primary);
    }
    [data-theme="dark"] .select2-container--bootstrap-5 .select2-results__option--highlighted {
        background-color: var(--sidebar-active-bg);
        color: var(--sidebar-active-text);
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex align-items-center mb-4">
    <div class="icon-box bg-success text-white rounded-3 d-flex align-items-center justify-content-center me-3 shadow-sm" style="width:48px;height:48px;font-size:1.5rem;">
        <i class="bi bi-file-earmark-spreadsheet-fill"></i>
    </div>
    <div>
        <h2 class="h4 mb-1 fw-bold">Export Laporan</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Generate dan download rekap nilai dalam bentuk Excel (.xlsx)</p>
    </div>
</div>

<div class="row">
    <!-- Laporan Per Siswa -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-transparent border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-person-lines-fill me-2"></i>Laporan Per Siswa</h5>
                <p class="text-muted small mt-1">Export seluruh nilai ujian untuk satu siswa tertentu.</p>
            </div>
            <div class="card-body p-4">
                <form action="<?= base_url('/admin/reports/export') ?>" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_type" value="student">
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Pilih Siswa <span class="text-danger">*</span></label>
                        <select name="user_id" class="form-select select2" required data-placeholder="Cari siswa...">
                            <option value=""></option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= $student->id ?>"><?= esc($student->firstname . ' ' . $student->lastname) ?> (<?= esc($student->username) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-success w-100 py-2 fw-semibold rounded-3">
                        <i class="bi bi-download me-2"></i>Download Laporan Siswa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Laporan Per Grup (Matrix) -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-transparent border-bottom-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0 text-info"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Laporan Per Grup (Rekap Matrix)</h5>
                <p class="text-muted small mt-1">Export nilai seluruh siswa di dalam satu grup, diurutkan per mata ujian.</p>
            </div>
            <div class="card-body p-4">
                <form action="<?= base_url('/admin/reports/export') ?>" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="report_type" value="group">
                    
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Pilih Grup / Kelas <span class="text-danger">*</span></label>
                        <select name="group_id" class="form-select select2" required data-placeholder="Cari grup...">
                            <option value=""></option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= $group->id ?>"><?= esc($group->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="alert alert-info border-0 rounded-3 mb-4">
                        <i class="bi bi-info-circle-fill me-2"></i> Laporan berupa matrix (kolom = ujian, baris = siswa).
                    </div>

                    <button type="submit" class="btn btn-info text-white w-100 py-2 fw-semibold rounded-3">
                        <i class="bi bi-download me-2"></i>Download Laporan Grup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%'
        });
    });
</script>
<?= $this->endSection() ?>

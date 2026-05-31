<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?><?= $module ? 'Edit Modul' : 'Tambah Modul Baru' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-<?= $module ? 'pencil-square' : 'plus-circle' ?> me-1"></i> 
                    <?= $module ? 'Edit Data Modul' : 'Form Tambah Modul' ?>
                </h6>
            </div>
            
            <form action="<?= base_url('/admin/modules/' . ($module ? 'update/'.$module->id : 'store')) ?>" method="POST">
                <?= csrf_field() ?>
                
                <div class="card-body p-4">
                    <?php if (session()->has('errors')): ?>
                        <div class="alert alert-danger rounded-3">
                            <ul class="mb-0 ps-3">
                            <?php foreach (session('errors') as $error): ?>
                                <li><?= esc($error) ?></li>
                            <?php endforeach ?>
                            </ul>
                        </div>
                    <?php endif ?>

                    <div class="mb-4">
                        <label for="name" class="form-label fw-semibold">Nama Modul <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg fs-6" id="name" name="name" 
                               value="<?= old('name', $module->name ?? '') ?>" required
                               placeholder="Contoh: Matematika, Bahasa Indonesia">
                    </div>

                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" style="width: 2.5rem; height: 1.25rem;"
                                   <?= old('is_enabled', $module->is_enabled ?? '1') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold ms-2 mt-1" for="is_enabled">Modul Aktif</label>
                        </div>
                        <div class="form-text mt-2 text-muted">Jika dinonaktifkan, seluruh subjek dan soal dalam modul ini tidak akan muncul dalam ujian.</div>
                    </div>
                </div>

                <div class="card-footer bg-light py-3 border-top d-flex justify-content-end gap-2">
                    <a href="<?= base_url('/admin/modules') ?>" class="btn btn-outline-secondary px-4">Batal</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Simpan Modul
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

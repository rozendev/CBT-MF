<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?><?= $subject ? 'Edit Subjek' : 'Tambah Subjek Baru' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-<?= $subject ? 'pencil-square' : 'plus-circle' ?> me-1"></i> 
                    <?= $subject ? 'Edit Data Subjek' : 'Form Tambah Subjek' ?>
                </h6>
            </div>
            
            <form action="<?= base_url('/admin/subjects/' . ($subject ? 'update/'.$subject->id : 'store')) ?>" method="POST">
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
                        <label for="module_id" class="form-label fw-semibold">Pilih Modul Induk <span class="text-danger">*</span></label>
                        <select class="form-select form-select-lg fs-6" id="module_id" name="module_id" required>
                            <option value="">-- Pilih Modul --</option>
                            <?php 
                                $selectedModule = old('module_id', $subject->module_id ?? request()->getGet('module_id') ?? '');
                            ?>
                            <?php foreach ($modules as $mod): ?>
                                <option value="<?= $mod->id ?>" <?= $selectedModule == $mod->id ? 'selected' : '' ?>>
                                    <?= esc($mod->name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="name" class="form-label fw-semibold">Nama Subjek / Topik <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= old('name', $subject->name ?? '') ?>" required
                               placeholder="Contoh: Aljabar, Trigonometri">
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label fw-semibold">Deskripsi</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  placeholder="Keterangan opsional tentang subjek ini"><?= old('description', $subject->description ?? '') ?></textarea>
                    </div>

                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1" style="width: 2.5rem; height: 1.25rem;"
                                   <?= old('is_enabled', $subject->is_enabled ?? '1') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold ms-2 mt-1" for="is_enabled">Subjek Aktif</label>
                        </div>
                        <div class="form-text mt-2 text-muted">Jika dinonaktifkan, subjek ini dan semua soal di dalamnya tidak akan muncul dalam ujian.</div>
                    </div>
                </div>

                <div class="card-footer bg-light py-3 border-top d-flex justify-content-end gap-2">
                    <a href="<?= base_url('/admin/subjects') ?>" class="btn btn-outline-secondary px-4">Batal</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Simpan Subjek
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

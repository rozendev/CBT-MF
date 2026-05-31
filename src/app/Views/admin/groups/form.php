<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?><?= $group ? 'Edit Grup' : 'Tambah Grup Baru' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-<?= $group ? 'pencil-square' : 'plus-circle' ?> me-1"></i> 
                    <?= $group ? 'Edit Data Grup' : 'Form Tambah Grup' ?>
                </h6>
            </div>
            
            <form action="<?= base_url('/admin/groups/' . ($group ? 'update/'.$group->id : 'store')) ?>" method="POST">
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
                        <label for="name" class="form-label fw-semibold">Nama Grup <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg fs-6" id="name" name="name" 
                               value="<?= old('name', $group->name ?? '') ?>" required
                               placeholder="Contoh: Kelas X IPA 1, Guru Matematika">
                    </div>

                    <div class="mb-4">
                        <label for="description" class="form-label fw-semibold">Deskripsi</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  placeholder="Keterangan opsional mengenai grup ini"><?= old('description', $group->description ?? '') ?></textarea>
                    </div>

                    <div class="mb-2">
                        <div class="form-check form-switch form-check-lg">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                                   <?= old('is_active', $group->is_active ?? '1') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold ms-2" for="is_active">Grup Aktif</label>
                        </div>
                        <div class="form-text mt-2 text-muted">Jika dinonaktifkan, grup ini tidak akan muncul dalam opsi ujian.</div>
                    </div>
                </div>

                <div class="card-footer bg-light py-3 border-top d-flex justify-content-end gap-2">
                    <a href="<?= base_url('/admin/groups') ?>" class="btn btn-outline-secondary px-4">Batal</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Simpan Grup
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .form-check-lg .form-check-input {
        width: 3rem;
        height: 1.5rem;
    }
    .form-check-lg .form-check-label {
        padding-top: 0.2rem;
    }
</style>
<?= $this->endSection() ?>

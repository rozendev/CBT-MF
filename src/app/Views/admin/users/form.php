<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?><?= $user ? 'Edit Pengguna' : 'Tambah Pengguna Baru' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<form action="<?= base_url('/admin/users/' . ($user ? 'update/'.$user->id : 'store')) ?>" method="POST">
    <?= csrf_field() ?>
    
    <div class="row g-4">
        <!-- Main Form Info -->
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold"><i class="bi bi-person-lines-fill me-1"></i> Informasi Profil</h6>
                </div>
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

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="firstname" class="form-label fw-semibold">Nama Depan</label>
                            <input type="text" class="form-control" id="firstname" name="firstname" 
                                   value="<?= old('firstname', $user->firstname ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="lastname" class="form-label fw-semibold">Nama Belakang</label>
                            <input type="text" class="form-control" id="lastname" name="lastname" 
                                   value="<?= old('lastname', $user->lastname ?? '') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="birthplace" class="form-label fw-semibold">Tempat Lahir</label>
                            <input type="text" class="form-control" id="birthplace" name="birthplace" 
                                   value="<?= old('birthplace', $user->birthplace ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="birthdate" class="form-label fw-semibold">Tanggal Lahir</label>
                            <input type="date" class="form-control" id="birthdate" name="birthdate" 
                                   value="<?= old('birthdate', $user->birthdate ?? '') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label for="registration_number" class="form-label fw-semibold">Nomor Registrasi / NISN / NIP</label>
                            <input type="text" class="form-control" id="registration_number" name="registration_number" 
                                   value="<?= old('registration_number', $user->registration_number ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="ssn" class="form-label fw-semibold">Nomor Identitas (NIK)</label>
                            <input type="text" class="form-control" id="ssn" name="ssn" 
                                   value="<?= old('ssn', $user->ssn ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Info Sidebar -->
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold"><i class="bi bi-shield-lock me-1"></i> Akun & Keamanan</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" required
                               value="<?= old('username', $user->username ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= old('email', $user->email ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">Password <?= !$user ? '<span class="text-danger">*</span>' : '' ?></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="password" name="password" <?= !$user ? 'required' : '' ?>>
                            <button type="button" class="btn btn-outline-secondary" data-target="password" aria-label="Tampilkan password">
                                <i class="bi bi-eye"></i>
                                <i class="bi bi-eye-slash d-none"></i>
                            </button>
                        </div>
                        <?php if ($user): ?>
                            <div class="form-text text-muted">Kosongkan jika tidak ingin mengubah password.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="siswa" <?= old('role', $user->role ?? 'siswa') === 'siswa' ? 'selected' : '' ?>>Siswa</option>
                            <option value="guru" <?= old('role', $user->role ?? '') === 'guru' ? 'selected' : '' ?>>Guru</option>
                            <option value="admin" <?= old('role', $user->role ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>

                    <div class="mb-2 mt-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                                   <?= old('is_active', $user->is_active ?? '1') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold ms-1" for="is_active">Akun Aktif</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Groups Assignment -->
            <div class="card">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold"><i class="bi bi-collection me-1"></i> Keanggotaan Grup</h6>
                </div>
                <div class="card-body p-4">
                    <div class="form-text mb-3">Pilih grup untuk pengguna ini. Pengguna dapat memilih ujian yang ditugaskan ke grup mereka.</div>
                    
                    <div style="max-height: 200px; overflow-y: auto;" class="border rounded p-3 bg-light">
                        <?php foreach ($allGroups as $group): ?>
                            <?php 
                                $isChecked = false;
                                if (old('groups')) {
                                    $isChecked = in_array($group->id, old('groups'));
                                } else if (!empty($userGroups)) {
                                    $isChecked = in_array($group->id, $userGroups);
                                }
                            ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="groups[]" value="<?= $group->id ?>" id="group_<?= $group->id ?>" <?= $isChecked ? 'checked' : '' ?>>
                                <label class="form-check-label" for="group_<?= $group->id ?>">
                                    <?= esc($group->name) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($allGroups)): ?>
                            <div class="text-muted small">Belum ada grup yang tersedia.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex justify-content-end gap-2 bg-light rounded-3">
                    <a href="<?= base_url('/admin/users') ?>" class="btn btn-outline-secondary px-4">Batal</a>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Simpan Pengguna
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
<script>
document.querySelectorAll('.toggle-password').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(btn.dataset.target);
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        var eye = btn.querySelector('.bi-eye');
        var eyeSlash = btn.querySelector('.bi-eye-slash');
        if (eye) eye.classList.toggle('d-none', show);
        if (eyeSlash) eyeSlash.classList.toggle('d-none', !show);
        btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    });
});
</script>
<?= $this->endSection() ?>

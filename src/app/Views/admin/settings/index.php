<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Pengaturan Sistem<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                    <button class="nav-link text-start active px-4 py-3 border-bottom rounded-0" id="v-pills-website-tab" data-bs-toggle="pill" data-bs-target="#v-pills-website" type="button" role="tab" aria-selected="true">
                        <i class="bi bi-globe me-2"></i> Pengaturan Website
                    </button>
                    <button class="nav-link text-start px-4 py-3 border-bottom rounded-0" id="v-pills-logo-tab" data-bs-toggle="pill" data-bs-target="#v-pills-logo" type="button" role="tab" aria-selected="false">
                        <i class="bi bi-image me-2"></i> Logo & Tampilan
                    </button>
                    <button class="nav-link text-start px-4 py-3 rounded-0" id="v-pills-security-tab" data-bs-toggle="pill" data-bs-target="#v-pills-security" type="button" role="tab" aria-selected="false">
                        <i class="bi bi-shield-lock me-2"></i> Keamanan & Anti-Cheat
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form action="<?= base_url('/admin/settings/update') ?>" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="tab-content" id="v-pills-tabContent">
                        
                        <!-- TAB: WEBSITE -->
                        <div class="tab-pane fade show active" id="v-pills-website" role="tabpanel" aria-labelledby="v-pills-website-tab">
                            <h5 class="fw-bold mb-4 text-primary">Informasi Website</h5>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Nama Aplikasi / Institusi</label>
                                <input type="text" class="form-control" name="settings[app_name]" value="<?= esc(isset($groupedSettings['general']['app_name']) ? $groupedSettings['general']['app_name']['value'] : 'Sistem Ujian') ?>">
                                <div class="form-text">Nama ini akan tampil di bagian atas dan header.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Zona Waktu</label>
                                <select class="form-select" name="settings[timezone]">
                                    <option value="Asia/Jakarta" <?= (isset($groupedSettings['general']['timezone']) && $groupedSettings['general']['timezone']['value'] == 'Asia/Jakarta') ? 'selected' : '' ?>>WIB (Asia/Jakarta)</option>
                                    <option value="Asia/Makassar" <?= (isset($groupedSettings['general']['timezone']) && $groupedSettings['general']['timezone']['value'] == 'Asia/Makassar') ? 'selected' : '' ?>>WITA (Asia/Makassar)</option>
                                    <option value="Asia/Jayapura" <?= (isset($groupedSettings['general']['timezone']) && $groupedSettings['general']['timezone']['value'] == 'Asia/Jayapura') ? 'selected' : '' ?>>WIT (Asia/Jayapura)</option>
                                </select>
                            </div>
                        </div>

                        <!-- TAB: LOGO -->
                        <div class="tab-pane fade" id="v-pills-logo" role="tabpanel" aria-labelledby="v-pills-logo-tab">
                            <h5 class="fw-bold mb-4 text-primary">Pengaturan Logo</h5>
                            
                            <div class="mb-4 text-center">
                                <?php $logoPath = isset($groupedSettings['logo']['app_logo']) ? $groupedSettings['logo']['app_logo']['value'] : ''; ?>
                                <?php if ($logoPath): ?>
                                    <img src="<?= base_url($logoPath) ?>" alt="Logo" class="img-thumbnail" style="max-height: 150px;">
                                <?php else: ?>
                                    <div class="p-5 bg-light rounded text-muted mb-3 d-inline-block">Belum ada logo</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Unggah Logo Baru</label>
                                <input type="file" class="form-control" name="app_logo" accept="image/png, image/jpeg, image/jpg">
                                <div class="form-text">Maksimal 2MB. Resolusi disarankan: Kotak atau rasio 4:3.</div>
                            </div>
                        </div>

                        <!-- TAB: SECURITY & ANTI CHEAT -->
                        <div class="tab-pane fade" id="v-pills-security" role="tabpanel" aria-labelledby="v-pills-security-tab">
                            <h5 class="fw-bold mb-4 text-primary">Keamanan & Ujian</h5>
                            
                            <div class="card bg-light border-0 mb-4">
                                <div class="card-body">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input fs-4" type="checkbox" role="switch" id="antiCheatToggle" name="settings[anti_cheat_enabled]" value="1" <?= (isset($groupedSettings['security']['anti_cheat_enabled']) && $groupedSettings['security']['anti_cheat_enabled']['value'] == '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label fs-5 ms-2 mt-1 fw-bold text-dark" for="antiCheatToggle">Aktifkan Anti-Cheat Sederhana</label>
                                    </div>
                                    <p class="text-muted mb-0 ms-1 small">Jika diaktifkan, sistem akan memblokir siswa sementara jika mereka membuka tab lain atau me-minimize browser. Pelanggaran kedua akan memblokir permanen.</p>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Timer Suspend Sementara (Detik)</label>
                                    <input type="number" class="form-control" name="settings[suspend_timer_seconds]" value="<?= esc(isset($groupedSettings['security']['suspend_timer_seconds']) ? $groupedSettings['security']['suspend_timer_seconds']['value'] : '30') ?>" min="10" max="300">
                                    <div class="form-text">Berapa lama layar dikunci saat pelanggaran pertama.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Toleransi Kecurangan Maksimal</label>
                                    <input type="number" class="form-control" name="settings[max_cheat_strikes]" value="<?= esc(isset($groupedSettings['security']['max_cheat_strikes']) ? $groupedSettings['security']['max_cheat_strikes']['value'] : '2') ?>" min="1" max="10">
                                    <div class="form-text">Siswa diblokir permanen setelah mencapai pelanggaran ini.</div>
                                </div>
                            </div>
                        </div>
                        
                    </div>
                    
                    <hr class="my-4">
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary px-4 fw-bold"><i class="bi bi-save me-2"></i> Simpan Pengaturan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

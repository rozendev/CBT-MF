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
                                <label class="form-label fw-semibold">Nama Aplikasi / Institusi (Site Name)</label>
                                <input type="text" class="form-control" name="settings[app_name]" value="<?= esc(isset($groupedSettings['general']['app_name']) ? $groupedSettings['general']['app_name']['value'] : 'Sistem Ujian') ?>">
                                <div class="form-text">Nama ini akan tampil di bagian header dan tab browser.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Deskripsi Aplikasi (App Description)</label>
                                <input type="text" class="form-control" name="settings[app_description]" value="<?= esc(isset($groupedSettings['general']['app_description']) ? $groupedSettings['general']['app_description']['value'] : 'Aplikasi Ujian Berbasis Komputer (CBT)') ?>">
                                <div class="form-text">Penjelasan singkat tentang sistem (muncul di footer).</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Pemilik / Penyelenggara (Site Author)</label>
                                <input type="text" class="form-control" name="settings[site_author]" value="<?= esc(isset($groupedSettings['general']['site_author']) ? $groupedSettings['general']['site_author']['value'] : 'Sekolah/Lembaga') ?>">
                                <div class="form-text">Nama lembaga penyelenggara ujian untuk hak cipta.</div>
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
                            <h5 class="fw-bold mb-4 text-primary">Pengaturan Tema & Tampilan Siswa</h5>
                            
                            <div class="row">
                                <!-- LEFT COLUMN: CONTROLS -->
                                <div class="col-lg-7">
                                    <h6 class="fw-bold mb-3 text-secondary">A. Pengaturan Gambar</h6>
                                    
                                    <div class="mb-4 text-center border rounded p-3 bg-white shadow-sm">
                                        <p class="text-muted small mb-2">Logo Utama Aplikasi Saat Ini</p>
                                        <?php $logoPath = isset($groupedSettings['logo']['app_logo']) ? $groupedSettings['logo']['app_logo']['value'] : ''; ?>
                                        <?php if ($logoPath): ?>
                                            <img src="<?= base_url($logoPath) ?>" alt="Logo" class="img-thumbnail border-0" style="max-height: 80px;">
                                        <?php else: ?>
                                            <div class="p-3 bg-light rounded text-muted d-inline-block small">Belum ada logo</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label class="form-label fw-semibold">Unggah Logo Utama</label>
                                        <input type="file" class="form-control" name="app_logo" accept="image/png, image/jpeg, image/jpg, image/svg+xml">
                                        <div class="form-text">Akan tampil di pojok kiri atas halaman ujian peserta.</div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label fw-semibold">Latar Belakang Login (Opsional)</label>
                                        <input type="file" class="form-control" name="login_background" accept="image/png, image/jpeg, image/jpg">
                                    </div>

                                    <hr class="my-4">
                                    <h6 class="fw-bold mb-3 text-secondary">B. Kostumisasi Visual (Theme Customizer)</h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-semibold">Jenis Huruf (Font Family)</label>
                                            <select class="form-select" id="customFont" name="settings[font_family]">
                                                <?php $currentFont = isset($groupedSettings['logo']['font_family']) ? $groupedSettings['logo']['font_family']['value'] : 'Inter'; ?>
                                                <option value="Inter" <?= $currentFont=='Inter'?'selected':'' ?>>Inter (Clean)</option>
                                                <option value="Outfit" <?= $currentFont=='Outfit'?'selected':'' ?>>Outfit (Modern)</option>
                                                <option value="Roboto" <?= $currentFont=='Roboto'?'selected':'' ?>>Roboto (Standard)</option>
                                                <option value="Poppins" <?= $currentFont=='Poppins'?'selected':'' ?>>Poppins (Playful)</option>
                                                <option value="Quicksand" <?= $currentFont=='Quicksand'?'selected':'' ?>>Quicksand (Rounded)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-semibold">Tingkat Lengkungan (Border Radius)</label>
                                            <?php $currentRadius = isset($groupedSettings['logo']['border_radius']) ? $groupedSettings['logo']['border_radius']['value'] : '8'; ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <input type="range" class="form-range" id="customRadius" name="settings[border_radius]" min="0" max="24" value="<?= esc($currentRadius) ?>">
                                                <span id="radiusValue" class="badge bg-secondary"><?= esc($currentRadius) ?>px</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <?php
                                            $pColor = isset($groupedSettings['logo']['primary_color']) ? $groupedSettings['logo']['primary_color']['value'] : (isset($groupedSettings['general']['primary_color']) ? $groupedSettings['general']['primary_color']['value'] : '#0d6efd');
                                            $sColor = isset($groupedSettings['logo']['secondary_color']) ? $groupedSettings['logo']['secondary_color']['value'] : (isset($groupedSettings['general']['secondary_color']) ? $groupedSettings['general']['secondary_color']['value'] : '#f4f6f9');
                                            $tColor = isset($groupedSettings['logo']['text_color']) ? $groupedSettings['logo']['text_color']['value'] : (isset($groupedSettings['general']['text_color']) ? $groupedSettings['general']['text_color']['value'] : '#212529');
                                        ?>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-semibold">Warna Utama</label>
                                            <div class="input-group">
                                                <input type="color" id="customPrimary" class="form-control form-control-color border-0 p-1" name="settings[primary_color]" value="<?= esc($pColor) ?>" style="max-width: 50px;">
                                                <input type="text" class="form-control" value="<?= esc($pColor) ?>" disabled>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-semibold">Warna Latar</label>
                                            <div class="input-group">
                                                <input type="color" id="customSecondary" class="form-control form-control-color border-0 p-1" name="settings[secondary_color]" value="<?= esc($sColor) ?>" style="max-width: 50px;">
                                                <input type="text" class="form-control" value="<?= esc($sColor) ?>" disabled>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label fw-semibold">Warna Teks</label>
                                            <div class="input-group">
                                                <input type="color" id="customTextColor" class="form-control form-control-color border-0 p-1" name="settings[text_color]" value="<?= esc($tColor) ?>" style="max-width: 50px;">
                                                <input type="text" class="form-control" value="<?= esc($tColor) ?>" disabled>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- RIGHT COLUMN: LIVE PREVIEW -->
                                <div class="col-lg-5">
                                    <div class="position-sticky" style="top: 100px;">
                                        <h6 class="fw-bold mb-3 text-secondary"><i class="bi bi-eye"></i> Live Preview Tampilan Peserta</h6>
                                        <div id="livePreviewBox" class="border shadow-sm overflow-hidden" style="transition: all 0.3s ease; border-radius: 12px; --bs-primary: #0d6efd; --bs-secondary: #f4f6f9; --custom-font: 'Inter'; --custom-radius: 8px; --custom-text: #212529; color: var(--custom-text);">
                                            <!-- Fake Navbar -->
                                            <div id="previewNavbar" class="d-flex justify-content-between align-items-center px-3 py-3 text-white" style="background: var(--bs-primary); transition: background 0.3s;">
                                                <div class="d-flex align-items-center gap-2 fw-bold" style="font-family: var(--custom-font);">
                                                    <i class="bi bi-mortarboard-fill"></i> Sistem Ujian
                                                </div>
                                                <div class="d-flex align-items-center gap-2" style="font-size: 0.8rem; font-family: var(--custom-font);">
                                                    <i class="bi bi-person-circle"></i> Siswa
                                                </div>
                                            </div>
                                            
                                            <!-- Fake Body -->
                                            <div id="previewBody" class="p-4" style="background: var(--bs-secondary); min-height: 300px; transition: background 0.3s;">
                                                <!-- Fake Card -->
                                                <div id="previewCard" class="bg-white p-4 shadow-sm mb-3" style="border-radius: var(--custom-radius); transition: border-radius 0.3s; background-color: var(--bs-secondary); filter: brightness(1.2);">
                                                    <h6 class="fw-bold mb-2" style="font-family: var(--custom-font); color: var(--custom-text);">Ujian Akhir Semester</h6>
                                                    <p class="small mb-3" style="font-family: var(--custom-font); opacity: 0.8;">Durasi: 90 Menit</p>
                                                    <button type="button" id="previewBtn" class="btn btn-sm text-white w-100 fw-bold border-0" style="background: var(--bs-primary); border-radius: var(--custom-radius); font-family: var(--custom-font); transition: all 0.3s;">Mulai Ujian</button>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="text-muted small mt-3 text-center"><i class="bi bi-info-circle"></i> Ubah pengaturan di sebelah kiri untuk melihat perubahan secara real-time pada UI siswa.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: SECURITY & ANTI CHEAT -->
                        <div class="tab-pane fade" id="v-pills-security" role="tabpanel" aria-labelledby="v-pills-security-tab">
                            <h5 class="fw-bold mb-4 text-primary">Keamanan & Ujian</h5>
                            
                            <div class="card bg-light border-0 mb-4">
                                <div class="card-body">
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input fs-4" type="checkbox" role="switch" id="antiCheatToggle" name="settings[anti_cheat_enabled]" value="1" <?= (isset($groupedSettings['security']['anti_cheat_enabled']) && $groupedSettings['security']['anti_cheat_enabled']['value'] == '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label fs-5 ms-2 mt-1 fw-bold text-dark" for="antiCheatToggle">Simple Cheat Detection</label>
                                    </div>
                                    <p class="text-muted mb-0 ms-1 small">Jika diaktifkan maka halaman ujian akan terkunci jika peserta ujian berpindah ke tab/aplikasi lain, atau keluar dari layar penuh.</p>
                                </div>
                            </div>
                            
                            <div class="card bg-light border-0 mb-4">
                                <div class="card-body">
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input fs-4" type="checkbox" role="switch" id="multiLoginToggle" name="settings[prevent_multi_login]" value="1" <?= (isset($groupedSettings['security']['prevent_multi_login']) && $groupedSettings['security']['prevent_multi_login']['value'] == '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label fs-5 ms-2 mt-1 fw-bold text-dark" for="multiLoginToggle">Cegah Multi-Login</label>
                                    </div>
                                    <p class="text-muted mb-0 ms-1 small">Jika diaktifkan, satu akun hanya bisa login di satu perangkat/browser pada waktu yang sama. Akun tidak akan bisa digunakan login di tempat lain jika masih ada sesi aktif.</p>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            <h6 class="fw-bold mb-3 text-secondary">Akses Installer & Migrasi</h6>
                            
                            <div class="row mb-4">
                                <div class="col-12">
                                    <div class="form-check form-switch mt-2">
                                        <?php 
                                            // Baca dari .env langsung karena ini spesifik
                                            $installerLocked = env('INSTALLER_LOCKED', false);
                                        ?>
                                        <input class="form-check-input fs-4" type="checkbox" role="switch" id="installerLockToggle" name="settings[installer_locked]" value="1" <?= $installerLocked ? 'checked' : '' ?>>
                                        <label class="form-check-label fs-5 ms-2 mt-1 fw-bold text-danger" for="installerLockToggle"><i class="bi bi-lock-fill"></i> Kunci Akses Web Installer (Sangat Disarankan)</label>
                                    </div>
                                    <p class="text-muted mb-0 ms-1 small">Jika diaktifkan, siapapun (termasuk Anda) tidak akan bisa mengakses URL <code>/install</code> demi keamanan. Matikan (buka kunci) hanya jika Anda perlu melakukan rekonfigurasi Database atau Cloudflare via Web Installer.</p>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h6 class="fw-bold mb-3 text-secondary">Pengaturan Slot Ujian</h6>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Maksimal Slot Ujian</label>
                                    <input type="number" class="form-control" name="settings[max_concurrent_connections]" value="<?= esc(isset($groupedSettings['security']['max_concurrent_connections']) ? $groupedSettings['security']['max_concurrent_connections']['value'] : '1000') ?>" min="1" max="10000">
                                    <div class="form-text">Batas jumlah user yang bisa login secara bersamaan (Disarankan: 500 - 1000).</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Pesan Menunggu Slot Kosong dalam Ujian</label>
                                    <input type="text" class="form-control" name="settings[queue_waiting_message]" value="<?= esc(isset($groupedSettings['security']['queue_waiting_message']) ? $groupedSettings['security']['queue_waiting_message']['value'] : 'Server sedang penuh. Anda berada dalam antrean. Mohon tunggu tanpa menutup halaman ini.') ?>">
                                    <div class="form-text">Pesan yang tampil pada layar peserta yang sedang menunggu slot kosong.</div>
                                </div>
                            </div>
                            <hr class="my-4">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Waktu Tunggu Kuncian Halaman Ujian (detik)</label>
                                    <input type="number" class="form-control" name="settings[suspend_timer_seconds]" value="<?= esc(isset($groupedSettings['security']['suspend_timer_seconds']) ? $groupedSettings['security']['suspend_timer_seconds']['value'] : '180') ?>" min="10" max="600">
                                    <div class="form-text">Waktu yang harus ditunggu peserta ujian pada saat halaman ujian terkunci. Isikan dengan angka misal 180.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Toleransi Kecurangan Maksimal</label>
                                    <input type="number" class="form-control" name="settings[max_cheat_strikes]" value="<?= esc(isset($groupedSettings['security']['max_cheat_strikes']) ? $groupedSettings['security']['max_cheat_strikes']['value'] : '2') ?>" min="1" max="10">
                                    <div class="form-text">Siswa diblokir permanen setelah mencapai pelanggaran ini.</div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Judul pesan peringatan</label>
                                <input type="text" class="form-control" name="settings[anti_cheat_title]" value="<?= esc(isset($groupedSettings['security']['anti_cheat_title']) ? $groupedSettings['security']['anti_cheat_title']['value'] : 'Maaf ya ❤️') ?>">
                                <div class="form-text">Ketikkan pesan yang akan tampil sebagai judul peringatan.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Isi pesan peringatan</label>
                                <input type="text" class="form-control" name="settings[anti_cheat_message]" value="<?= esc(isset($groupedSettings['security']['anti_cheat_message']) ? $groupedSettings['security']['anti_cheat_message']['value'] : 'Halaman ujian sementara waktu ditutup karena kami mendeteksi adanya pelanggaran pada akun Anda. Halaman ujian akan kembali terbuka setelah hitungan mundur selesai.') ?>">
                                <div class="form-text">Ketikkan isi pesan yang akan ditampilkan sebagai pesan peringatan.</div>
                            </div>
                            
                            <div class="form-check mb-4 mt-3">
                                <input class="form-check-input" type="checkbox" id="forceLogoutToggle" name="settings[anti_cheat_force_logout]" value="1" <?= (isset($groupedSettings['security']['anti_cheat_force_logout']) && $groupedSettings['security']['anti_cheat_force_logout']['value'] == '1') ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="forceLogoutToggle">Paksa logout dan kunci</label>
                                <div class="form-text mt-1">Jika diaktifkan maka peserta akan dipaksa logout dan dikunci akunnya. Akun yang dikunci memerlukan reset peserta apabila peserta diijinkan kembali mengikuti ujian.</div>
                            </div>
                            
                            <div class="card border mb-3">
                                <div class="card-body">
                                    <h6 class="fw-bold mb-3">Custom Logo Peringatan (.svg)</h6>
                                    
                                    <div class="mb-3">
                                        <?php $cheatLogoPath = isset($groupedSettings['security']['anti_cheat_logo']) ? $groupedSettings['security']['anti_cheat_logo']['value'] : ''; ?>
                                        <?php if ($cheatLogoPath): ?>
                                            <div class="p-3 bg-dark rounded d-inline-block text-center mb-2">
                                                <img src="<?= base_url($cheatLogoPath) ?>" alt="Cheat Logo" style="max-height: 80px;">
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted small">Belum ada logo khusus (kosong).</p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <input type="file" class="form-control" name="anti_cheat_logo" accept="image/svg+xml">
                                    <div class="form-text">Hanya menerima format SVG. Logo akan tampil di atas pesan peringatan. Biarkan kosong jika tidak ingin memakai logo.</div>
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
<?= $this->section('scripts') ?>
<script>
    document.querySelectorAll('input[type="color"]').forEach(function(picker) {
        picker.addEventListener('input', function() {
            this.nextElementSibling.value = this.value;
        });
    });

    // Theme Customizer Live Preview Logic
    const previewBox = document.getElementById('livePreviewBox');
    const fontSelect = document.getElementById('customFont');
    const radiusInput = document.getElementById('customRadius');
    const radiusValue = document.getElementById('radiusValue');
    const primaryColorInput = document.getElementById('customPrimary');
    const secondaryColorInput = document.getElementById('customSecondary');
    const textColorInput = document.getElementById('customTextColor');

    function updatePreview() {
        if(!previewBox) return;
        
        // Font
        const font = fontSelect.value;
        previewBox.style.setProperty('--custom-font', font + ', sans-serif');
        
        // Load Google Font dynamically for preview
        if (!document.getElementById('font-' + font)) {
            const link = document.createElement('link');
            link.id = 'font-' + font;
            link.rel = 'stylesheet';
            link.href = 'https://fonts.googleapis.com/css2?family=' + font + ':wght@300;400;500;600;700&display=swap';
            document.head.appendChild(link);
        }

        // Radius
        const radius = radiusInput.value + 'px';
        previewBox.style.setProperty('--custom-radius', radius);
        if(radiusValue) radiusValue.textContent = radius;

        // Colors
        previewBox.style.setProperty('--bs-primary', primaryColorInput.value);
        previewBox.style.setProperty('--bs-secondary', secondaryColorInput.value);
        previewBox.style.setProperty('--custom-text', textColorInput.value);
    }

    if(previewBox) {
        fontSelect.addEventListener('change', updatePreview);
        radiusInput.addEventListener('input', updatePreview);
        primaryColorInput.addEventListener('input', updatePreview);
        secondaryColorInput.addEventListener('input', updatePreview);
        if(textColorInput) textColorInput.addEventListener('input', updatePreview);
        // Initialize
        updatePreview();
    }
</script>
<?= $this->endSection() ?>

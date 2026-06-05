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
                            <h5 class="fw-bold mb-4 text-primary">Pengaturan Logo</h5>
                            
                            <div class="mb-3 text-secondary small">
                                <strong>Ketentuan Logo:</strong>
                                <ul>
                                    <li>Format / ekstensi yang didukung: .png, .jpg, .jpeg, .svg</li>
                                    <li>Resolusi disarankan: Kotak atau rasio 4:3 (Maksimal tinggi 150px pada tampilan).</li>
                                    <li>Maksimal ukuran file: 2MB.</li>
                                </ul>
                            </div>
                            
                            <div class="mb-4 text-center border rounded p-3 bg-white">
                                <p class="text-muted small mb-2">Logo Utama Aplikasi Saat Ini</p>
                                <?php $logoPath = isset($groupedSettings['logo']['app_logo']) ? $groupedSettings['logo']['app_logo']['value'] : ''; ?>
                                <?php if ($logoPath): ?>
                                    <img src="<?= base_url($logoPath) ?>" alt="Logo" class="img-thumbnail border-0" style="max-height: 120px;">
                                <?php else: ?>
                                    <div class="p-4 bg-light rounded text-muted d-inline-block">Belum ada logo</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Unggah Logo Utama</label>
                                <input type="file" class="form-control" name="app_logo" accept="image/png, image/jpeg, image/jpg, image/svg+xml">
                            </div>

                            <hr>
                            <h6 class="fw-bold mb-3 mt-4 text-secondary">Latar Belakang Login (Login Background)</h6>
                            <div class="mb-4 text-center border rounded p-3 bg-white">
                                <p class="text-muted small mb-2">Gambar Latar Saat Ini</p>
                                <?php $bgPath = isset($groupedSettings['logo']['login_background']) ? $groupedSettings['logo']['login_background']['value'] : ''; ?>
                                <?php if ($bgPath): ?>
                                    <img src="<?= base_url($bgPath) ?>" alt="Background" class="img-thumbnail border-0" style="max-height: 150px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="p-4 bg-light rounded text-muted d-inline-block">Belum ada gambar background (menggunakan warna default)</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Unggah Gambar Background Baru</label>
                                <input type="file" class="form-control" name="login_background" accept="image/png, image/jpeg, image/jpg">
                                <div class="form-text">Gambar akan menjadi latar full-screen di halaman login. (Resolusi disarankan: 1920x1080)</div>
                            </div>

                            <hr>
                            <h6 class="fw-bold mb-3 mt-4 text-secondary">Palet Warna Utama (Tema)</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Warna Utama (Primary / Tombol)</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color border-0 shadow-sm p-1" name="settings[primary_color]" value="<?= esc(isset($groupedSettings['logo']['primary_color']) ? $groupedSettings['logo']['primary_color']['value'] : '#0d6efd') ?>" style="max-width: 60px; cursor: pointer;">
                                        <input type="text" class="form-control" value="<?= esc(isset($groupedSettings['logo']['primary_color']) ? $groupedSettings['logo']['primary_color']['value'] : '#0d6efd') ?>" disabled>
                                    </div>
                                    <div class="form-text">Digunakan untuk Tombol Utama dan Aksen.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Warna Latar Belakang (Secondary/Background)</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color border-0 shadow-sm p-1" name="settings[secondary_color]" value="<?= esc(isset($groupedSettings['logo']['secondary_color']) ? $groupedSettings['logo']['secondary_color']['value'] : '#f4f6f9') ?>" style="max-width: 60px; cursor: pointer;">
                                        <input type="text" class="form-control" value="<?= esc(isset($groupedSettings['logo']['secondary_color']) ? $groupedSettings['logo']['secondary_color']['value'] : '#f4f6f9') ?>" disabled>
                                    </div>
                                    <div class="form-text">Digunakan untuk warna latar belakang aplikasi ujian.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Warna Navbar (Header)</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color border-0 shadow-sm p-1" name="settings[navbar_color]" value="<?= esc(isset($groupedSettings['logo']['navbar_color']) ? $groupedSettings['logo']['navbar_color']['value'] : '#ffffff') ?>" style="max-width: 60px; cursor: pointer;">
                                        <input type="text" class="form-control" value="<?= esc(isset($groupedSettings['logo']['navbar_color']) ? $groupedSettings['logo']['navbar_color']['value'] : '#ffffff') ?>" disabled>
                                    </div>
                                    <div class="form-text">Digunakan untuk baris atas (Navbar) aplikasi.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-semibold">Warna Teks Utama</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color border-0 shadow-sm p-1" name="settings[text_color]" value="<?= esc(isset($groupedSettings['logo']['text_color']) ? $groupedSettings['logo']['text_color']['value'] : '#212529') ?>" style="max-width: 60px; cursor: pointer;">
                                        <input type="text" class="form-control" value="<?= esc(isset($groupedSettings['logo']['text_color']) ? $groupedSettings['logo']['text_color']['value'] : '#212529') ?>" disabled>
                                    </div>
                                    <div class="form-text">Digunakan untuk warna teks default.</div>
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
</script>
<?= $this->endSection() ?>

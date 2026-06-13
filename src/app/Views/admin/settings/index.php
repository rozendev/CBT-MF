<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Pengaturan Sistem<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    :root {
        --setting-blue: #0d6efd;
        --setting-purple: #6f42c1;
        --setting-green: #198754;
        --setting-red: #dc3545;
        --setting-gray: #6c757d;
    }

    .settings-nav {
        position: sticky;
        top: calc(var(--topbar-height) + 2rem);
    }

    .settings-nav .nav-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.85rem 1.25rem;
        color: var(--text-secondary);
        font-weight: 500;
        font-size: 0.9rem;
        border-radius: 12px;
        margin-bottom: 0.25rem;
        transition: all 0.2s ease;
        position: relative;
    }
    .settings-nav .nav-link:hover {
        background: var(--bg-body);
        color: var(--text-primary);
    }
    .settings-nav .nav-link.active {
        background: var(--brand-color);
        color: #fff;
        font-weight: 600;
        box-shadow: 0 4px 15px rgba(67, 24, 255, 0.25);
    }
    .settings-nav .nav-link .nav-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .settings-nav .nav-link:not(.active) .nav-icon {
        background: var(--bg-body);
    }
    .settings-nav .nav-link.active .nav-icon {
        background: rgba(255,255,255,0.2);
    }
    .settings-nav .badge-new {
        font-size: 0.65rem;
        padding: 0.15rem 0.5rem;
        border-radius: 20px;
        background: var(--setting-green);
        color: white;
        margin-left: auto;
    }
    .settings-nav .nav-link.active .badge-new {
        background: rgba(255,255,255,0.3);
    }

    .setting-card {
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1.25rem 1.5rem;
        margin-bottom: 1rem;
        background: var(--bg-surface);
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .setting-card:hover {
        border-color: var(--brand-color);
        box-shadow: 0 2px 12px rgba(67, 24, 255, 0.06);
    }
    .setting-card.accent-blue { border-left: 3px solid var(--setting-blue); }
    .setting-card.accent-purple { border-left: 3px solid var(--setting-purple); }
    .setting-card.accent-green { border-left: 3px solid var(--setting-green); }
    .setting-card.accent-red { border-left: 3px solid var(--setting-red); }
    .setting-card.accent-gray { border-left: 3px solid var(--setting-gray); }

    .setting-card-header {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
    }
    .setting-card-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .setting-card-icon.bg-blue { background: rgba(13,110,253,0.1); color: var(--setting-blue); }
    .setting-card-icon.bg-purple { background: rgba(111,66,193,0.1); color: var(--setting-purple); }
    .setting-card-icon.bg-green { background: rgba(25,135,84,0.1); color: var(--setting-green); }
    .setting-card-icon.bg-red { background: rgba(220,53,69,0.1); color: var(--setting-red); }
    .setting-card-icon.bg-gray { background: rgba(108,117,125,0.1); color: var(--setting-gray); }
    .setting-card-icon.bg-orange { background: rgba(253,126,20,0.1); color: #fd7e14; }
    .setting-card-icon.bg-yellow { background: rgba(255,193,7,0.1); color: #ffc107; }
    .setting-card-icon.bg-teal { background: rgba(32,201,151,0.1); color: #20c997; }
    .setting-card-icon.bg-cyan { background: rgba(13,202,240,0.1); color: #0dcaf0; }

    .setting-card-info { flex: 1; min-width: 0; }
    .setting-card-info label {
        font-weight: 600;
        font-size: 0.95rem;
        color: var(--text-primary);
        margin-bottom: 0.15rem;
        display: block;
        cursor: pointer;
    }
    .setting-card-info .desc {
        font-size: 0.82rem;
        color: var(--text-secondary);
        margin-bottom: 0;
        line-height: 1.45;
    }
    .setting-card-control {
        flex-shrink: 0;
        display: flex;
        align-items: center;
    }

    .form-switch .form-check-input {
        width: 3rem;
        height: 1.5rem;
        cursor: pointer;
        border: none;
        background-color: var(--border-color);
    }
    .form-switch .form-check-input:checked {
        background-color: var(--brand-color);
    }
    .form-switch .form-check-input:focus {
        box-shadow: 0 0 0 0.25rem rgba(67, 24, 255, 0.2);
    }

    .section-header {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }
    .section-header h6 {
        margin: 0;
        font-weight: 700;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-secondary);
    }
    .section-header i {
        font-size: 1rem;
        color: var(--text-secondary);
    }

    .sys-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
    }
    .sys-info-item {
        background: var(--bg-body);
        border-radius: 12px;
        padding: 1.25rem;
        text-align: center;
        transition: transform 0.2s;
    }
    .sys-info-item:hover { transform: translateY(-2px); }
    .sys-info-item .info-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }
    .sys-info-item .info-label {
        font-size: 0.78rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 6px;
    }
    .status-dot.online { background: var(--setting-green); box-shadow: 0 0 6px rgba(25,135,84,0.4); }
    .status-dot.offline { background: var(--setting-red); box-shadow: 0 0 6px rgba(220,53,69,0.4); }
    .status-dot.loading { background: var(--text-secondary); animation: pulse 1s infinite; }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.4; }
    }

    .skeleton-block {
        background: var(--bg-body);
        border-radius: 8px;
        height: 60px;
        animation: pulse 1.5s infinite;
    }

    .tab-pane { animation: fadeInUp 0.3s ease; }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .preview-badge {
        display: inline-block;
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-size: 0.82rem;
        color: var(--text-secondary);
    }

    @media (max-width: 991.98px) {
        .settings-nav { position: static; margin-bottom: 1.5rem; }
        .settings-nav .nav-link { padding: 0.6rem 1rem; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
    function settingVal($grouped, $group, $key, $default = '') {
        return isset($grouped[$group][$key]) ? $grouped[$group][$key]['value'] : $default;
    }
    function settingChecked($grouped, $group, $key) {
        return isset($grouped[$group][$key]) && $grouped[$group][$key]['value'] == '1' ? 'checked' : '';
    }
?>

<form action="<?= base_url('/admin/settings/update') ?>" method="POST" enctype="multipart/form-data" id="settingsForm">
    <?= csrf_field() ?>

<div class="row g-4">
    <!-- ── Sidebar Navigation ── -->
    <div class="col-lg-3">
        <div class="settings-nav card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 px-3 py-2 mb-2">
                    <i class="bi bi-sliders" style="color: var(--brand-color);"></i>
                    <span class="fw-bold" style="font-size: 0.9rem;">Pengaturan</span>
                </div>
                <nav class="nav flex-column">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-website" type="button">
                        <span class="nav-icon"><i class="bi bi-globe2"></i></span>
                        Website
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-appearance" type="button">
                        <span class="nav-icon"><i class="bi bi-palette2"></i></span>
                        Logo & Tampilan
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-exam" type="button">
                        <span class="nav-icon"><i class="bi bi-card-checklist"></i></span>
                        Ujian
                        <span class="badge-new">Baru</span>
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-security" type="button">
                        <span class="nav-icon"><i class="bi bi-shield-lock"></i></span>
                        Keamanan
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-system" type="button">
                        <span class="nav-icon"><i class="bi bi-cpu"></i></span>
                        Sistem
                        <span class="badge-new">Baru</span>
                    </button>
                </nav>
            </div>
        </div>
    </div>

    <!-- ── Tab Content ── -->
    <div class="col-lg-9">
        <div class="tab-content">

            <!-- ════════════════════════════════════════════ -->
            <!-- TAB 1: WEBSITE                              -->
            <!-- ════════════════════════════════════════════ -->
            <div class="tab-pane fade show active" id="tab-website">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">Pengaturan Website</h4>
                        <p class="text-muted mb-0 small">Informasi dasar aplikasi dan institusi Anda</p>
                    </div>
                    <button type="submit" class="btn btn-primary fw-semibold px-4"><i class="bi bi-check2 me-1"></i> Simpan</button>
                </div>

                <div class="section-header">
                    <i class="bi bi-info-circle"></i>
                    <h6>Informasi Aplikasi</h6>
                </div>

                <div class="setting-card accent-blue">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-blue"><i class="bi bi-app-indicator"></i></div>
                        <div class="setting-card-info">
                            <label for="appName">Nama Aplikasi</label>
                            <p class="desc">Tampil di header halaman, tab browser, dan logo sidebar.</p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <input type="text" class="form-control" id="appName" name="settings[app_name]" value="<?= esc(settingVal($groupedSettings, 'general', 'app_name', 'Sistem Ujian')) ?>">
                    </div>
                </div>

                <div class="setting-card accent-blue">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-blue"><i class="bi bi-text-paragraph"></i></div>
                        <div class="setting-card-info">
                            <label for="appDesc">Deskripsi Aplikasi</label>
                            <p class="desc">Penjelasan singkat tentang sistem, muncul di footer halaman.</p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <input type="text" class="form-control" id="appDesc" name="settings[app_description]" value="<?= esc(settingVal($groupedSettings, 'general', 'app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)')) ?>">
                    </div>
                </div>

                <div class="setting-card accent-blue">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-blue"><i class="bi bi-building"></i></div>
                        <div class="setting-card-info">
                            <label for="siteAuthor">Nama Institusi / Penyelenggara</label>
                            <p class="desc">Nama lembaga penyelenggara untuk hak cipta di footer.</p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <input type="text" class="form-control" id="siteAuthor" name="settings[site_author]" value="<?= esc(settingVal($groupedSettings, 'general', 'site_author', 'Sekolah/Lembaga')) ?>">
                    </div>
                </div>

                <div class="setting-card accent-blue">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-teal"><i class="bi bi-clock"></i></div>
                        <div class="setting-card-info">
                            <label for="timezone">Zona Waktu</label>
                            <p class="desc">Zona waktu yang digunakan untuk semua timestamp di sistem.</p>
                        </div>
                        <div class="setting-card-control">
                            <select class="form-select" id="timezone" name="settings[timezone]" style="min-width: 200px;">
                                <option value="Asia/Jakarta" <?= settingVal($groupedSettings, 'general', 'timezone') == 'Asia/Jakarta' ? 'selected' : '' ?>>WIB (Asia/Jakarta)</option>
                                <option value="Asia/Makassar" <?= settingVal($groupedSettings, 'general', 'timezone') == 'Asia/Makassar' ? 'selected' : '' ?>>WITA (Asia/Makassar)</option>
                                <option value="Asia/Jayapura" <?= settingVal($groupedSettings, 'general', 'timezone') == 'Asia/Jayapura' ? 'selected' : '' ?>>WIT (Asia/Jayapura)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════ -->
            <!-- TAB 2: LOGO & TAMPILAN                     -->
            <!-- ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-appearance">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">Logo & Tampilan</h4>
                        <p class="text-muted mb-0 small">Kustomisasi visual untuk halaman peserta ujian</p>
                    </div>
                    <button type="submit" class="btn btn-primary fw-semibold px-4"><i class="bi bi-check2 me-1"></i> Simpan</button>
                </div>

                <div class="section-header">
                    <i class="bi bi-image"></i>
                    <h6>Gambar & Logo</h6>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="setting-card accent-purple">
                            <div class="setting-card-header">
                                <div class="setting-card-icon bg-purple"><i class="bi bi-patch-check"></i></div>
                                <div class="setting-card-info">
                                    <label>Logo Utama</label>
                                    <p class="desc">Tampil di pojok kiri atas halaman ujian peserta.</p>
                                </div>
                            </div>
                            <div class="mt-3 text-center">
                                <?php $logoPath = settingVal($groupedSettings, 'logo', 'app_logo'); ?>
                                <?php if ($logoPath): ?>
                                    <div class="mb-3">
                                        <img src="<?= base_url($logoPath) ?>" alt="Logo" class="img-thumbnail border-0 shadow-sm" style="max-height: 70px;">
                                    </div>
                                <?php else: ?>
                                    <div class="preview-badge mb-3"><i class="bi bi-image me-1"></i> Belum ada logo</div>
                                <?php endif; ?>
                                <input type="file" class="form-control form-control-sm" name="app_logo" accept="image/png, image/jpeg, image/jpg, image/svg+xml">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card accent-purple">
                            <div class="setting-card-header">
                                <div class="setting-card-icon bg-purple"><i class="bi bi-card-image"></i></div>
                                <div class="setting-card-info">
                                    <label>Background Login</label>
                                    <p class="desc">Gambar latar belakang halaman login (opsional).</p>
                                </div>
                            </div>
                            <div class="mt-3 text-center">
                                <?php $bgPath = settingVal($groupedSettings, 'logo', 'login_background'); ?>
                                <?php if ($bgPath): ?>
                                    <div class="mb-3">
                                        <img src="<?= base_url($bgPath) ?>" alt="BG" class="img-thumbnail border-0 shadow-sm" style="max-height: 70px;">
                                    </div>
                                <?php else: ?>
                                    <div class="preview-badge mb-3"><i class="bi bi-image me-1"></i> Belum ada background</div>
                                <?php endif; ?>
                                <input type="file" class="form-control form-control-sm" name="login_background" accept="image/png, image/jpeg, image/jpg">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header">
                    <i class="bi bi-palette"></i>
                    <h6>Theme Customizer</h6>
                </div>

                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="setting-card accent-purple">
                            <div class="setting-card-header">
                                <div class="setting-card-icon bg-purple"><i class="bi bi-fonts"></i></div>
                                <div class="setting-card-info">
                                    <label for="customFont">Jenis Huruf</label>
                                    <p class="desc">Font yang digunakan di halaman ujian peserta.</p>
                                </div>
                                <div class="setting-card-control">
                                    <select class="form-select" id="customFont" name="settings[font_family]" style="min-width: 160px;">
                                        <?php $currentFont = settingVal($groupedSettings, 'logo', 'font_family', 'Inter'); ?>
                                        <option value="Inter" <?= $currentFont=='Inter'?'selected':'' ?>>Inter (Clean)</option>
                                        <option value="Outfit" <?= $currentFont=='Outfit'?'selected':'' ?>>Outfit (Modern)</option>
                                        <option value="Roboto" <?= $currentFont=='Roboto'?'selected':'' ?>>Roboto (Standard)</option>
                                        <option value="Poppins" <?= $currentFont=='Poppins'?'selected':'' ?>>Poppins (Playful)</option>
                                        <option value="Quicksand" <?= $currentFont=='Quicksand'?'selected':'' ?>>Quicksand (Rounded)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="setting-card accent-purple">
                            <div class="setting-card-header">
                                <div class="setting-card-icon bg-purple"><i class="bi bi-border-style"></i></div>
                                <div class="setting-card-info">
                                    <label for="customRadius">Lengkungan Sudut (Border Radius)</label>
                                    <p class="desc">Mengatur seberapa bulat sudut elemen UI. Geser slider untuk melihat preview.</p>
                                </div>
                                <div class="setting-card-control">
                                    <span id="radiusValue" class="badge bg-secondary" style="min-width: 45px;"><?= esc(settingVal($groupedSettings, 'logo', 'border_radius', '8')) ?>px</span>
                                </div>
                            </div>
                            <div class="mt-3">
                                <input type="range" class="form-range" id="customRadius" name="settings[border_radius]" min="0" max="24" value="<?= esc(settingVal($groupedSettings, 'logo', 'border_radius', '8')) ?>">
                            </div>
                        </div>

                        <div class="row g-3">
                            <?php
                                $pColor = settingVal($groupedSettings, 'logo', 'primary_color', '#0d6efd');
                                $sColor = settingVal($groupedSettings, 'logo', 'secondary_color', '#f4f6f9');
                                $tColor = settingVal($groupedSettings, 'logo', 'text_color', '#212529');
                            ?>
                            <div class="col-md-4">
                                <div class="setting-card accent-purple">
                                    <label class="fw-semibold small mb-2">Warna Utama</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="color" id="customPrimary" class="form-control form-control-color border-0 p-0" name="settings[primary_color]" value="<?= esc($pColor) ?>" style="width:40px; height:40px; border-radius:8px;">
                                        <input type="text" class="form-control form-control-sm" id="primaryHex" value="<?= esc($pColor) ?>" disabled>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="setting-card accent-purple">
                                    <label class="fw-semibold small mb-2">Warna Latar</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="color" id="customSecondary" class="form-control form-control-color border-0 p-0" name="settings[secondary_color]" value="<?= esc($sColor) ?>" style="width:40px; height:40px; border-radius:8px;">
                                        <input type="text" class="form-control form-control-sm" id="secondaryHex" value="<?= esc($sColor) ?>" disabled>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="setting-card accent-purple">
                                    <label class="fw-semibold small mb-2">Warna Teks</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="color" id="customTextColor" class="form-control form-control-color border-0 p-0" name="settings[text_color]" value="<?= esc($tColor) ?>" style="width:40px; height:40px; border-radius:8px;">
                                        <input type="text" class="form-control form-control-sm" id="textHex" value="<?= esc($tColor) ?>" disabled>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Live Preview -->
                    <div class="col-lg-5">
                        <div class="position-sticky" style="top: calc(var(--topbar-height) + 2rem);">
                            <div class="card border-0 shadow-sm">
                                <div class="card-header bg-transparent border-bottom-0 pb-0">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-eye" style="color: var(--brand-color);"></i>
                                        <span class="fw-bold small">Live Preview</span>
                                    </div>
                                </div>
                                <div class="card-body pt-2">
                                    <div id="livePreviewBox" class="border overflow-hidden" style="border-radius: 12px; --bs-primary: <?= esc($pColor) ?>; --bs-secondary: <?= esc($sColor) ?>; --custom-font: '<?= esc($currentFont) ?>'; --custom-radius: <?= esc(settingVal($groupedSettings, 'logo', 'border_radius', '8')) ?>px; --custom-text: <?= esc($tColor) ?>; color: var(--custom-text);">
                                        <div id="previewNavbar" class="d-flex justify-content-between align-items-center px-3 py-2 text-white" style="background: var(--bs-primary); transition: background 0.3s;">
                                            <div class="d-flex align-items-center gap-2 fw-bold" style="font-size:0.85rem; font-family: var(--custom-font);">
                                                <i class="bi bi-mortarboard-fill"></i> Sistem Ujian
                                            </div>
                                            <div style="font-size: 0.75rem; font-family: var(--custom-font);">
                                                <i class="bi bi-person-circle"></i> Siswa
                                            </div>
                                        </div>
                                        <div id="previewBody" class="p-3" style="background: var(--bs-secondary); min-height: 200px; transition: background 0.3s;">
                                            <div class="bg-white p-3 shadow-sm mb-2" style="border-radius: var(--custom-radius); transition: border-radius 0.3s;">
                                                <h6 class="fw-bold mb-1" style="font-size:0.8rem; font-family: var(--custom-font); color: var(--custom-text);">Ujian Akhir Semester</h6>
                                                <p class="small mb-2" style="font-family: var(--custom-font); opacity: 0.7; font-size:0.7rem;">Durasi: 90 Menit</p>
                                                <button type="button" class="btn btn-sm text-white w-100 fw-bold border-0" style="background: var(--bs-primary); border-radius: var(--custom-radius); font-family: var(--custom-font); font-size:0.75rem;">Mulai Ujian</button>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-muted small mt-2 mb-0 text-center"><i class="bi bi-info-circle me-1"></i>Perubahan tampil secara real-time</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════ -->
            <!-- TAB 3: UJIAN (BARU)                        -->
            <!-- ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-exam">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">Pengaturan Ujian</h4>
                        <p class="text-muted mb-0 small">Konfigurasi default untuk semua ujian yang dibuat</p>
                    </div>
                    <button type="submit" class="btn btn-primary fw-semibold px-4"><i class="bi bi-check2 me-1"></i> Simpan</button>
                </div>

                <div class="section-header">
                    <i class="bi bi-clock-history"></i>
                    <h6>Durasi & Waktu</h6>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-green"><i class="bi bi-hourglass-split"></i></div>
                        <div class="setting-card-info">
                            <label for="defaultDuration">Durasi Default Ujian</label>
                            <p class="desc">Durasi ujian default jika tidak diatur manual saat membuat ujian baru.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="input-group" style="max-width: 160px;">
                                <input type="number" class="form-control text-center" id="defaultDuration" name="settings[default_duration]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_duration', '90')) ?>" min="5" max="600">
                                <span class="input-group-text">menit</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-green"><i class="bi bi-send-check"></i></div>
                        <div class="setting-card-info">
                            <label for="autoSubmit">Auto-Submit Saat Waktu Habis</label>
                            <p class="desc">Otomatis kumpulkan ujian ketika timer mencapai nol. Jawaban yang sudah tersimpan akan dinilai.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="autoSubmit" name="settings[auto_submit]" value="1" <?= settingChecked($groupedSettings, 'exam', 'auto_submit') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-star"></i>
                    <h6>Skor & Penilaian</h6>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-orange"><i class="bi bi-award"></i></div>
                        <div class="setting-card-info">
                            <label for="passingGrade">Passing Grade (KKM) Default</label>
                            <p class="desc">Nilai minimum untuk dinyatakan lulus. Ujian bisa override nilai ini.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="input-group" style="max-width: 130px;">
                                <input type="number" class="form-control text-center" id="passingGrade" name="settings[default_passing_grade]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_passing_grade', '75')) ?>" min="0" max="100">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="setting-card accent-green">
                            <div class="setting-card-icon bg-green mb-2"><i class="bi bi-check-circle"></i></div>
                            <label for="scoreRight" class="fw-semibold small">Poin Jawaban Benar</label>
                            <p class="desc mb-2">Poin untuk setiap jawaban yang benar.</p>
                            <input type="number" class="form-control" id="scoreRight" name="settings[default_score_right]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_score_right', '1')) ?>" min="0" max="100" step="0.5">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="setting-card accent-green">
                            <div class="setting-card-icon bg-red mb-2"><i class="bi bi-x-circle"></i></div>
                            <label for="scoreWrong" class="fw-semibold small">Poin Jawaban Salah</label>
                            <p class="desc mb-2">Poin untuk jawaban salah (bisa negatif untuk denda).</p>
                            <input type="number" class="form-control" id="scoreWrong" name="settings[default_score_wrong]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_score_wrong', '0')) ?>" min="-100" max="0" step="0.5">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="setting-card accent-green">
                            <div class="setting-card-icon bg-yellow mb-2"><i class="bi bi-dash-circle"></i></div>
                            <label for="scoreUnanswered" class="fw-semibold small">Poin Tidak Dijawab</label>
                            <p class="desc mb-2">Poin untuk soal yang tidak dijawab sama sekali.</p>
                            <input type="number" class="form-control" id="scoreUnanswered" name="settings[default_score_unanswered]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_score_unanswered', '0')) ?>" min="-100" max="0" step="0.5">
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-green"><i class="bi bi-list-check"></i></div>
                        <div class="setting-card-info">
                            <label for="partialScore">Skor Parsial (Pilihan Ganda Kompleks)</label>
                            <p class="desc">Jika aktif, siswa mendapat poin sebagian untuk setiap opsi benar yang dipilih pada soal multiple-correct. Jika nonaktif, harus memilih semua jawaban benar untuk mendapat poin penuh.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="partialScore" name="settings[mcma_partial_score]" value="1" <?= settingChecked($groupedSettings, 'exam', 'mcma_partial_score') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-arrow-left-right"></i>
                    <h6>Pengacakan Soal</h6>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-green"><i class="bi bi-shuffle"></i></div>
                        <div class="setting-card-info">
                            <label for="randomQuestions">Acak Urutan Soal Secara Default</label>
                            <p class="desc">Setiap siswa mendapat urutan soal yang berbeda. Bisa di-override per ujian.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="randomQuestions" name="settings[default_random_questions]" value="1" <?= settingChecked($groupedSettings, 'exam', 'default_random_questions') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-green"><i class="bi bi-arrow-down-up"></i></div>
                        <div class="setting-card-info">
                            <label for="randomAnswers">Acak Urutan Jawaban Secara Default</label>
                            <p class="desc">Opsi jawaban pilihan ganda diacak urutannya. Bisa di-override per ujian.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="randomAnswers" name="settings[default_random_answers]" value="1" <?= settingChecked($groupedSettings, 'exam', 'default_random_answers') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-eye"></i>
                    <h6>Setelah Ujian Selesai</h6>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-cyan"><i class="bi bi-graph-up"></i></div>
                        <div class="setting-card-info">
                            <label for="showScore">Tampilkan Skor Setelah Selesai</label>
                            <p class="desc">Siswa langsung melihat skor mereka setelah mengumpulkan ujian.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="showScore" name="settings[show_score_after_exam]" value="1" <?= settingChecked($groupedSettings, 'exam', 'show_score_after_exam') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-cyan"><i class="bi bi-clipboard-check"></i></div>
                        <div class="setting-card-info">
                            <label for="showCorrect">Tampilkan Kunci Jawaban</label>
                            <p class="desc">Siswa bisa melihat jawaban yang benar saat review hasil ujian.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="showCorrect" name="settings[show_correct_answers]" value="1" <?= settingChecked($groupedSettings, 'exam', 'show_correct_answers') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-green">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-cyan"><i class="bi bi-journal-text"></i></div>
                        <div class="setting-card-info">
                            <label for="allowReview">Izinkan Review Ujian</label>
                            <p class="desc">Siswa bisa membuka kembali halaman review untuk melihat soal dan jawaban mereka.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="allowReview" name="settings[allow_review]" value="1" <?= settingChecked($groupedSettings, 'exam', 'allow_review') ?>>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════ -->
            <!-- TAB 4: KEAMANAN & ANTI-CHEAT               -->
            <!-- ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-security">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h4 class="fw-bold mb-1">Keamanan & Anti-Cheat</h4>
                        <p class="text-muted mb-0 small">Proteksi ujian, anti-kecurangan, dan kontrol akses</p>
                    </div>
                    <button type="submit" class="btn btn-primary fw-semibold px-4"><i class="bi bi-check2 me-1"></i> Simpan</button>
                </div>

                <div class="section-header">
                    <i class="bi bi-shield-check"></i>
                    <h6>Proteksi Dasar</h6>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-red"><i class="bi bi-eye-slash"></i></div>
                        <div class="setting-card-info">
                            <label for="antiCheatToggle">Deteksi Kecurangan Sederhana</label>
                            <p class="desc">Kunci halaman ujian jika peserta berpindah tab/aplikasi lain atau keluar dari mode fullscreen. Peringatan dan penalti berlaku sesuai konfigurasi di bawah.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="antiCheatToggle" name="settings[anti_cheat_enabled]" value="1" <?= settingChecked($groupedSettings, 'security', 'anti_cheat_enabled') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-red"><i class="bi bi-person-x"></i></div>
                        <div class="setting-card-info">
                            <label for="multiLoginToggle">Cegah Multi-Login</label>
                            <p class="desc">Satu akun hanya bisa login di satu perangkat. Login di tempat lain akan ditolak selama sesi aktif.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="multiLoginToggle" name="settings[prevent_multi_login]" value="1" <?= settingChecked($groupedSettings, 'security', 'prevent_multi_login') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-orange"><i class="bi bi-box-arrow-right"></i></div>
                        <div class="setting-card-info">
                            <label for="forceLogoutToggle">Paksa Logout & Kunci Akun</label>
                            <p class="desc">Jika aktif, pelanggaran langsung menyebabkan logout paksa dan akun dikunci. Memerlukan reset manual oleh admin.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="forceLogoutToggle" name="settings[anti_cheat_force_logout]" value="1" <?= settingChecked($groupedSettings, 'security', 'anti_cheat_force_logout') ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-sliders2"></i>
                    <h6>Konfigurasi Anti-Cheat</h6>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="setting-card accent-red">
                            <div class="setting-card-icon bg-red mb-2"><i class="bi bi-exclamation-triangle"></i></div>
                            <label for="maxStrikes" class="fw-semibold small">Toleransi Pelanggaran</label>
                            <p class="desc mb-2">Jumlah pelanggaran sebelum akun diblokir permanen.</p>
                            <input type="number" class="form-control" id="maxStrikes" name="settings[max_cheat_strikes]" value="<?= esc(settingVal($groupedSettings, 'security', 'max_cheat_strikes', '2')) ?>" min="1" max="10">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="setting-card accent-red">
                            <div class="setting-card-icon bg-orange mb-2"><i class="bi bi-timer"></i></div>
                            <label for="suspendTimer" class="fw-semibold small">Waktu Suspend (detik)</label>
                            <p class="desc mb-2">Durasi halaman terkunci saat pelanggaran ringan.</p>
                            <input type="number" class="form-control" id="suspendTimer" name="settings[suspend_timer_seconds]" value="<?= esc(settingVal($groupedSettings, 'security', 'suspend_timer_seconds', '180')) ?>" min="10" max="600">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="setting-card accent-red">
                            <div class="setting-card-icon bg-yellow mb-2"><i class="bi bi-people"></i></div>
                            <label for="maxConcurrent" class="fw-semibold small">Maksimal Slot Ujian</label>
                            <p class="desc mb-2">Batas jumlah user login bersamaan.</p>
                            <input type="number" class="form-control" id="maxConcurrent" name="settings[max_concurrent_connections]" value="<?= esc(settingVal($groupedSettings, 'security', 'max_concurrent_connections', '1000')) ?>" min="1" max="10000">
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-icon bg-red mb-2"><i class="bi bi-chat-quote"></i></div>
                    <label class="fw-semibold small mb-2">Pesan Peringatan Anti-Cheat</label>
                    <p class="desc mb-3">Pesan yang tampil saat halaman ujian terkunci karena pelanggaran.</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted">Judul</label>
                            <input type="text" class="form-control" name="settings[anti_cheat_title]" value="<?= esc(settingVal($groupedSettings, 'security', 'anti_cheat_title', 'Maaf ya')) ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small text-muted">Isi Pesan</label>
                            <input type="text" class="form-control" name="settings[anti_cheat_message]" value="<?= esc(settingVal($groupedSettings, 'security', 'anti_cheat_message', 'Halaman ujian sementara waktu ditutup karena kami mendeteksi adanya pelanggaran pada akun Anda. Halaman ujian akan kembali terbuka setelah hitungan mundur selesai.')) ?>">
                        </div>
                    </div>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-red"><i class="bi bi-chat-left-quote"></i></div>
                        <div class="setting-card-info">
                            <label>Pesan Antrean Slot</label>
                            <p class="desc">Pesan yang tampil pada peserta yang sedang menunggu slot kosong.</p>
                        </div>
                    </div>
                    <div class="mt-3">
                        <input type="text" class="form-control" name="settings[queue_waiting_message]" value="<?= esc(settingVal($groupedSettings, 'security', 'queue_waiting_message', 'Server sedang penuh. Anda berada dalam antrean. Mohon tunggu tanpa menutup halaman ini.')) ?>">
                    </div>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-icon bg-purple mb-2"><i class="bi bi-filetype-svg"></i></div>
                    <label class="fw-semibold small mb-2">Logo Peringatan Kustom (SVG)</label>
                    <p class="desc mb-3">Logo SVG yang tampil di atas pesan peringatan kecurangan.</p>
                    <div class="d-flex align-items-center gap-3">
                        <?php $cheatLogoPath = settingVal($groupedSettings, 'security', 'anti_cheat_logo'); ?>
                        <?php if ($cheatLogoPath): ?>
                            <div class="p-2 rounded shadow-sm" style="background: var(--bg-body);">
                                <img src="<?= base_url($cheatLogoPath) ?>" alt="Cheat Logo" style="max-height: 50px;">
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control form-control-sm" name="anti_cheat_logo" accept="image/svg+xml" style="max-width: 300px;">
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-tools"></i>
                    <h6>Akses Sistem</h6>
                </div>

                <div class="setting-card accent-red">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-red"><i class="bi bi-lock-fill"></i></div>
                        <div class="setting-card-info">
                            <label for="installerLockToggle">Kunci Akses Web Installer</label>
                            <p class="desc">Cegah akses ke <code>/install</code> untuk keamanan. Nonaktifkan hanya saat perlu rekonfigurasi database.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <?php $installerLocked = env('INSTALLER_LOCKED', false); ?>
                                <input class="form-check-input" type="checkbox" role="switch" id="installerLockToggle" name="settings[installer_locked]" value="1" <?= $installerLocked ? 'checked' : '' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-cone-striped"></i>
                    <h6>Mode Maintenance</h6>
                </div>

                <div class="setting-card accent-red" style="border-left-color: #fd7e14;">
                    <div class="setting-card-header">
                        <div class="setting-card-icon bg-orange"><i class="bi bi-cone-striped"></i></div>
                        <div class="setting-card-info">
                            <label for="maintenanceToggle">Aktifkan Mode Maintenance</label>
                            <p class="desc">Semua siswa akan dialihkan ke halaman maintenance. Admin tetap bisa login normal. Gunakan saat melakukan pemeliharaan server.</p>
                        </div>
                        <div class="setting-card-control">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="maintenanceToggle" name="settings[maintenance_mode]" value="1" <?= settingChecked($groupedSettings, 'security', 'maintenance_mode') ?>>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small text-muted">Pesan Maintenance</label>
                        <textarea class="form-control" name="settings[maintenance_message]" rows="2" placeholder="Sistem sedang dalam pemeliharaan. Silakan coba lagi nanti."><?= esc(settingVal($groupedSettings, 'security', 'maintenance_message', 'Sistem sedang dalam pemeliharaan. Silakan coba lagi beberapa saat lagi.')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════ -->
            <!-- TAB 5: SISTEM & MAINTENANCE (BARU)         -->
            <!-- ════════════════════════════════════════════ -->
            <div class="tab-pane fade" id="tab-system">
                <div class="mb-4">
                    <h4 class="fw-bold mb-1">Sistem & Maintenance</h4>
                    <p class="text-muted mb-0 small">Informasi server, status layanan, dan alat pemeliharaan</p>
                </div>

                <div class="section-header">
                    <i class="bi bi-speedometer2"></i>
                    <h6>Status Layanan</h6>
                </div>

                <div class="sys-info-grid mb-4" id="sysInfoGrid">
                    <div class="sys-info-item">
                        <div class="info-value" id="infoPhp"><span class="status-dot loading"></span>...</div>
                        <div class="info-label">PHP Version</div>
                    </div>
                    <div class="sys-info-item">
                        <div class="info-value" id="infoCi"><span class="status-dot loading"></span>...</div>
                        <div class="info-label">CodeIgniter</div>
                    </div>
                    <div class="sys-info-item">
                        <div class="info-value" id="infoDb"><span class="status-dot loading"></span>...</div>
                        <div class="info-label">Database</div>
                    </div>
                    <div class="sys-info-item">
                        <div class="info-value" id="infoRedis"><span class="status-dot loading"></span>...</div>
                        <div class="info-label">Redis</div>
                    </div>
                    <div class="sys-info-item">
                        <div class="info-value" id="infoSessions"><span class="status-dot loading"></span>...</div>
                        <div class="info-label">Sesi Aktif</div>
                    </div>
                    <div class="sys-info-item">
                        <div class="info-value" id="infoDisk"><span class="status-dot loading"></span>...</div>
                        <div class="info-label">Disk Usage</div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-wrench"></i>
                    <h6>Aksi Sistem</h6>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="setting-card accent-gray">
                            <div class="setting-card-header">
                                <div class="setting-card-icon bg-gray"><i class="bi bi-trash3"></i></div>
                                <div class="setting-card-info">
                                    <label>Bersihkan Cache</label>
                                    <p class="desc">Hapus semua cache CI dan Redis. Berguna setelah update atau perubahan konfigurasi.</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnClearCache">
                                    <i class="bi bi-trash3 me-1"></i> Bersihkan Cache
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="setting-card accent-gray">
                            <div class="setting-card-header">
                                <div class="setting-card-icon bg-gray"><i class="bi bi-arrow-counterclockwise"></i></div>
                                <div class="setting-card-info">
                                    <label>Reset ke Pengaturan Default</label>
                                    <p class="desc">Kembalikan semua pengaturan ke nilai awal instalasi. Tindakan ini tidak bisa dibatalkan.</p>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-danger fw-semibold" id="btnResetSettings">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Semua
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-header mt-4">
                    <i class="bi bi-info-circle"></i>
                    <h6>Tentang Aplikasi</h6>
                </div>

                <div class="setting-card accent-gray">
                    <div class="d-flex align-items-center gap-3">
                        <div class="setting-card-icon bg-purple" style="width:50px; height:50px; font-size:1.5rem;">
                            <i class="bi bi-hexagon-fill"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1">Sistem Ujian CBT</h6>
                            <p class="desc mb-1">Versi <?= esc(settingVal($groupedSettings, 'general', 'app_version', '1.0.0')) ?> &middot; PHP <?= PHP_VERSION ?> &middot; CodeIgniter <?= \CodeIgniter\CodeIgniter::CI_VERSION ?></p>
                            <p class="desc mb-0">Aplikasi ujian berbasis komputer dengan fitur bank soal, anti-cheat, dan scoring otomatis.</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function() {
    // ── Color Picker Sync ──
    document.querySelectorAll('input[type="color"]').forEach(function(picker) {
        picker.addEventListener('input', function() {
            const hexInput = this.parentElement.querySelector('input[type="text"]');
            if (hexInput) hexInput.value = this.value;
            updatePreview();
        });
    });

    // ── Radius Slider ──
    const radiusInput = document.getElementById('customRadius');
    const radiusValue = document.getElementById('radiusValue');
    if (radiusInput && radiusValue) {
        radiusInput.addEventListener('input', function() {
            radiusValue.textContent = this.value + 'px';
            updatePreview();
        });
    }

    // ── Font Selector ──
    const fontSelect = document.getElementById('customFont');
    if (fontSelect) {
        fontSelect.addEventListener('change', updatePreview);
    }

    // ── Live Preview ──
    const previewBox = document.getElementById('livePreviewBox');
    function updatePreview() {
        if (!previewBox) return;
        const font = fontSelect ? fontSelect.value : 'Inter';
        previewBox.style.setProperty('--custom-font', font + ', sans-serif');

        if (fontSelect && !document.getElementById('font-' + font)) {
            const link = document.createElement('link');
            link.id = 'font-' + font;
            link.rel = 'stylesheet';
            link.href = 'https://fonts.googleapis.com/css2?family=' + font + ':wght@300;400;500;600;700&display=swap';
            document.head.appendChild(link);
        }

        const radius = radiusInput ? radiusInput.value + 'px' : '8px';
        previewBox.style.setProperty('--custom-radius', radius);

        const primary = document.getElementById('customPrimary');
        const secondary = document.getElementById('customSecondary');
        const textColor = document.getElementById('customTextColor');
        if (primary) previewBox.style.setProperty('--bs-primary', primary.value);
        if (secondary) previewBox.style.setProperty('--bs-secondary', secondary.value);
        if (textColor) previewBox.style.setProperty('--custom-text', textColor.value);
    }

    // ── System Info (AJAX) ──
    let sysLoaded = false;
    document.querySelector('[data-bs-target="#tab-system"]')?.addEventListener('shown.bs.tab', function() {
        if (sysLoaded) return;
        sysLoaded = true;
        fetch('<?= base_url('/admin/settings/system-info') ?>', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('infoPhp').innerHTML = '<span class="status-dot online"></span>' + data.php_version;
            document.getElementById('infoCi').innerHTML = '<span class="status-dot online"></span>' + data.ci_version;
            document.getElementById('infoDb').innerHTML = '<span class="status-dot ' + (data.db_connected ? 'online' : 'offline') + '"></span>' + (data.db_connected ? 'Connected' : 'Error');
            document.getElementById('infoRedis').innerHTML = '<span class="status-dot ' + (data.redis_connected ? 'online' : 'offline') + '"></span>' + (data.redis_connected ? 'Connected' : 'Offline');
            document.getElementById('infoSessions').innerHTML = '<span class="status-dot online"></span>' + data.active_sessions;
            document.getElementById('infoDisk').innerHTML = '<span class="status-dot online"></span>' + data.disk_usage;
        })
        .catch(() => {
            document.querySelectorAll('#sysInfoGrid .info-value').forEach(el => {
                el.innerHTML = '<span class="status-dot offline"></span>Error';
            });
        });
    });

    // ── Clear Cache ──
    document.getElementById('btnClearCache')?.addEventListener('click', function() {
        Swal.fire({
            title: 'Bersihkan Cache?',
            text: 'Semua cache CI dan Redis akan dihapus.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, bersihkan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#4318ff'
        }).then(result => {
            if (result.isConfirmed) {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Membersihkan...';
                fetch('<?= base_url('/admin/settings/clear-cache') ?>', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('input[name="<?= csrf_token() ?>"]').value
                    }
                })
                .then(r => r.json())
                .then(data => {
                    Swal.fire({
                        icon: data.status === 'success' ? 'success' : 'error',
                        title: data.status === 'success' ? 'Berhasil' : 'Gagal',
                        text: data.message,
                        confirmButtonColor: '#4318ff'
                    });
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-trash3 me-1"></i> Bersihkan Cache';
                });
            }
        });
    });

    // ── Reset Settings ──
    document.getElementById('btnResetSettings')?.addEventListener('click', function() {
        Swal.fire({
            title: 'Reset Semua Pengaturan?',
            html: 'Semua pengaturan akan dikembalikan ke nilai default.<br><strong class="text-danger">Tindakan ini tidak bisa dibatalkan.</strong>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, reset semua',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#dc3545'
        }).then(result => {
            if (result.isConfirmed) {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mereset...';
                fetch('<?= base_url('/admin/settings/reset') ?>', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('input[name="<?= csrf_token() ?>"]').value
                    }
                })
                .then(r => r.json())
                .then(data => {
                    Swal.fire({
                        icon: data.status === 'success' ? 'success' : 'error',
                        title: data.status === 'success' ? 'Berhasil' : 'Gagal',
                        text: data.message,
                        confirmButtonColor: '#4318ff'
                    }).then(() => {
                        if (data.status === 'success') window.location.reload();
                    });
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i> Reset Semua';
                });
            }
        });
    });

    // Initialize preview on load
    updatePreview();
})();
</script>
<?= $this->endSection() ?>

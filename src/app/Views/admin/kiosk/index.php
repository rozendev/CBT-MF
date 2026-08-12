<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>Aplikasi Kiosk Android<?= $this->endSection() ?>

<?php
if (!function_exists('kioskSettingVal')) {
    function kioskSettingVal(array $settings, string $key, string $default = ''): string {
        return isset($settings[$key]) ? (string)$settings[$key]['value'] : $default;
    }
}
if (!function_exists('kioskSettingChecked')) {
    function kioskSettingChecked(array $settings, string $key, bool $default = true): bool {
        if (!isset($settings[$key])) return $default;
        $val = (string)$settings[$key]['value'];
        return $val === '1' || strtolower($val) === 'true';
    }
}
?>

<?= $this->section('content') ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
    <div>
        <div class="pane-kicker text-primary fw-bold text-uppercase fs-7 tracking-wider mb-1">
            <i class="bi bi-phone me-1"></i> Client Encapsulation &amp; Anti-Cheat
        </div>
        <h3 class="mb-1 fw-extrabold text-dark">Aplikasi Kiosk Android</h3>
        <p class="text-muted mb-0">Manajemen konfigurasi enkapsulasi, sirine alarm, dan kata sandi pengawas untuk aplikasi pengunci layar HP siswa (BYOD).</p>
    </div>
</div>

<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?= session()->getFlashdata('success') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<form action="<?= base_url('/admin/kiosk/update') ?>" method="post" id="kioskSettingsForm">
    <?= csrf_field() ?>

    <div class="row g-4">

        <!-- PANEL 1: SIRINE ALARM & KEAMANAN PENGAWAS -->
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-danger bg-opacity-10 p-2 text-danger">
                        <i class="bi bi-bell-fill fs-5"></i>
                    </div>
                    <div>
                        <h6 class="m-0 fw-bold text-dark">Sirine Alarm Darurat &amp; Password Pengawas</h6>
                        <small class="text-muted">Konfigurasi tindakan pencegahan saat siswa memaksa keluar aplikasi.</small>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark" for="kioskExitPassword">
                                Password Keluar Kiosk App <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control form-control-lg" id="kioskExitPassword" 
                                   name="settings[kiosk_exit_password]" 
                                   value="<?= esc(kioskSettingVal($kioskSettings, 'kiosk_exit_password', '123456')) ?>" 
                                   maxlength="32" required placeholder="Contoh: 123456">
                            <div class="form-text mt-2">Password rahasia bagi pengawas/proctor untuk melepaskan Kiosk Mode pada HP siswa.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark" for="kioskMinVersion">
                                Versi Minimum Aplikasi Android
                            </label>
                            <input type="text" class="form-control form-control-lg text-center" id="kioskMinVersion" 
                                   name="settings[kiosk_min_app_version]" 
                                   value="<?= esc(kioskSettingVal($kioskSettings, 'kiosk_min_app_version', '1.0.0')) ?>" 
                                   placeholder="1.0.0">
                            <div class="form-text mt-2">Pesan peringatan jika versi app Android siswa di bawah nilai ini.</div>
                        </div>

                        <div class="col-12"><hr class="my-2 text-muted opacity-25"></div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border bg-light">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark">Aktifkan Sirine Alarm Darurat</h6>
                                    <p class="text-muted fs-7 mb-0">Membunyikan suara sirine keras jika siswa memaksa keluar atau salah password.</p>
                                </div>
                                <div class="form-check form-switch m-0 ms-3 fs-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kioskSirenEnabled" 
                                           name="settings[kiosk_siren_enabled]" value="1" 
                                           <?= kioskSettingChecked($kioskSettings, 'kiosk_siren_enabled') ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border bg-light">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark">Paksa Volume Maksimum (100%)</h6>
                                    <p class="text-muted fs-7 mb-0">Mengunci dan menaikkan volume media/alarm HP ke 100% saat sirine berbunyi.</p>
                                </div>
                                <div class="form-check form-switch m-0 ms-3 fs-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kioskSirenMaxVolume" 
                                           name="settings[kiosk_siren_max_volume]" value="1" 
                                           <?= kioskSettingChecked($kioskSettings, 'kiosk_siren_max_volume') ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PANEL 2: ENKAPSULASI NATIVE ANDROID -->
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex align-items-center gap-3">
                    <div class="rounded-3 bg-primary bg-opacity-10 p-2 text-primary">
                        <i class="bi bi-shield-lock-fill fs-5"></i>
                    </div>
                    <div>
                        <h6 class="m-0 fw-bold text-dark">Fitur Penguncian &amp; Anti-Cheat Native</h6>
                        <small class="text-muted">Parameter proteksi sistem operasi Android saat ujian berlangsung.</small>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark">Paksa Home Launcher (Layer 2)</h6>
                                    <p class="text-muted fs-7 mb-0">Mengarahkan siswa kembali ke app ujian jika menekan tombol Home.</p>
                                </div>
                                <div class="form-check form-switch m-0 ms-3 fs-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kioskEnforceHome" 
                                           name="settings[kiosk_enforce_home_launcher]" value="1" 
                                           <?= kioskSettingChecked($kioskSettings, 'kiosk_enforce_home_launcher') ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark">Blokir Clipboard (Copy-Paste)</h6>
                                    <p class="text-muted fs-7 mb-0">Membersihkan papan klip otomatis saat ujian dan mengunci penempelan teks.</p>
                                </div>
                                <div class="form-check form-switch m-0 ms-3 fs-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kioskBlockClipboard" 
                                           name="settings[kiosk_block_clipboard]" value="1" 
                                           <?= kioskSettingChecked($kioskSettings, 'kiosk_block_clipboard') ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex align-items-center justify-content-between p-3 rounded-3 border">
                                <div>
                                    <h6 class="fw-bold mb-1 text-dark">Aktifkan Overlay Guard (Layer 4)</h6>
                                    <p class="text-muted fs-7 mb-0">Menampilkan overlay full-screen saat aplikasi terdeteksi di-background.</p>
                                </div>
                                <div class="form-check form-switch m-0 ms-3 fs-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="kioskOverlayGuard" 
                                           name="settings[kiosk_overlay_guard_enabled]" value="1" 
                                           <?= kioskSettingChecked($kioskSettings, 'kiosk_overlay_guard_enabled') ? 'checked' : '' ?>>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border">
                                <label class="form-label fw-bold text-dark mb-1" for="kioskRootStrictness">
                                    Tingkat Penanganan Root / Emulator
                                </label>
                                <select class="form-select" id="kioskRootStrictness" name="settings[kiosk_root_strictness]">
                                    <option value="warning" <?= kioskSettingVal($kioskSettings, 'kiosk_root_strictness', 'warning') === 'warning' ? 'selected' : '' ?>>
                                        Peringatan &amp; Log (Rekomendasi BYOD)
                                    </option>
                                    <option value="strict_block" <?= kioskSettingVal($kioskSettings, 'kiosk_root_strictness') === 'strict_block' ? 'selected' : '' ?>>
                                        Blokir Total Akses Ujian
                                    </option>
                                </select>
                                <div class="form-text mt-1">Tindakan jika aplikasi mendeteksi perangkat ter-root atau emulator.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ACTION BUTTON -->
    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm fw-bold">
            <i class="bi bi-check2 me-2"></i> Simpan Perubahan Kiosk
        </button>
    </div>
</form>

<?= $this->endSection() ?>

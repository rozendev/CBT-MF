<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?><?= $test ? 'Edit Ujian' : 'Buat Ujian Baru' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<form action="<?= base_url('/admin/tests/' . ($test ? 'update/'.$test->id : 'store')) ?>" method="POST">
    <?= csrf_field() ?>
    
    <div class="row g-4">
        <div class="col-lg-8">
            <?php if (session()->has('errors')): ?>
                <div class="alert alert-danger rounded-3">
                    <ul class="mb-0 ps-3">
                    <?php foreach (session('errors') as $error): ?>
                        <li><?= esc($error) ?></li>
                    <?php endforeach ?>
                    </ul>
                </div>
            <?php endif ?>

            <!-- Informasi Dasar -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-info-square me-2"></i>Informasi Dasar Ujian</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Ujian <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" name="name" 
                               value="<?= old('name', $test->name ?? '') ?>" required
                               placeholder="Contoh: Ujian Tengah Semester Ganjil 2026">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Deskripsi / Petunjuk Ujian</label>
                        <textarea class="form-control" name="description" rows="4"
                                  placeholder="Petunjuk yang akan dibaca siswa sebelum memulai ujian..."><?= old('description', $test->description ?? '') ?></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Waktu Mulai (Hardcap) <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="begin_time" 
                                   value="<?= old('begin_time', $test->begin_time ?? '') ?>" required>
                            <div class="form-text">Waktu ini menjadi patokan awal hitung mundur ujian bagi seluruh siswa.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Durasi (Menit) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="duration_minutes" min="1" 
                                   value="<?= old('duration_minutes', $test->duration_minutes ?? 90) ?>" required>
                            <div class="form-text">Waktu selesai otomatis: <strong class="text-danger">Waktu Mulai + Durasi</strong>.</div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password (Opsional)</label>
                            <input type="text" class="form-control" name="password" 
                                   value="<?= old('password', $test->password ?? '') ?>" placeholder="Kosongkan jika bebas">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">IP Range</label>
                            <input type="text" class="form-control" name="ip_range" 
                                   value="<?= old('ip_range', $test->ip_range ?? '*') ?>">
                            <div class="form-text">Gunakan * untuk semua IP.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pengaturan Nilai -->
            <div class="card shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold text-success"><i class="bi bi-calculator me-2"></i>Sistem Penilaian</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Skor Benar</label>
                            <input type="number" step="0.01" class="form-control" name="score_right" 
                                   value="<?= old('score_right', $test->score_right ?? 1.00) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Skor Salah</label>
                            <input type="number" step="0.01" class="form-control" name="score_wrong" 
                                   value="<?= old('score_wrong', $test->score_wrong ?? 0.00) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Skor Kosong</label>
                            <input type="number" step="0.01" class="form-control" name="score_unanswered" 
                                   value="<?= old('score_unanswered', $test->score_unanswered ?? 0.00) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Batas Lulus</label>
                            <input type="number" step="0.01" class="form-control" name="passing_score" 
                                   value="<?= old('passing_score', $test->passing_score ?? 0.00) ?>" required>
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Max Skor (Target Skala) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="max_score" 
                                   value="<?= old('max_score', $test->max_score ?? 100.00) ?>" required>
                            <div class="form-text">Berapa skor maksimal/skala nilai? (misal: 10 atau 100). Sistem akan mengonversi otomatis ke skala ini.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kolom Kanan: Pengaturan Pelaksanaan, Hasil, & Status -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 80px;">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-sliders me-2"></i>Opsi Pelaksanaan & Hasil</h6>
                </div>
                <div class="card-body p-4">
                    
                    <!-- Master Switch Status Ujian -->
                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <label class="form-label fw-bold d-block text-dark mb-1">Status Ujian</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input fs-4" type="checkbox" id="isEnabledToggle" name="is_enabled" value="1"
                                   <?= old('is_enabled', $test->is_enabled ?? '0') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label mt-1 ms-2 fw-semibold">Aktif (Dapat Dikerjakan)</label>
                        </div>
                    </div>

                    <!-- Container Opsi Pelaksanaan & Hasil (Bisa Di-disable via Master Switch) -->
                    <div id="executionOptionsGroup">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-toggles me-2 text-primary"></i>Pengaturan Pelaksanaan</h6>

                        <div class="mb-4">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="random_questions" value="1" 
                                       <?= old('random_questions', $test->random_questions ?? '1') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label">Acak Urutan Soal</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="random_answers" value="1" 
                                       <?= old('random_answers', $test->random_answers ?? '1') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label">Acak Urutan Jawaban (PG)</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="show_menu" value="1" 
                                       <?= old('show_menu', $test->show_menu ?? '1') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label">Tampilkan Menu Navigasi Soal</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="allow_noanswer" value="1" 
                                       <?= old('allow_noanswer', $test->allow_noanswer ?? '1') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label">Izinkan Kosong (Tidak Dijawab)</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input bg-info border-info" type="checkbox" name="mcma_partial_score" value="1" 
                                       <?= old('mcma_partial_score', $test->mcma_partial_score ?? '1') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold text-info">Gunakan Sistem Bobot (PG Kompleks)</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="isRepeatableToggle" name="is_repeatable" value="1" 
                                       <?= old('is_repeatable', $test->is_repeatable ?? '0') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label text-danger">Boleh Diulang</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input bg-danger border-danger" type="checkbox" id="autoSubmitToggle" name="auto_submit_on_cheat" value="1" 
                                       <?= old('auto_submit_on_cheat', $test->auto_submit_on_cheat ?? '0') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label text-danger fw-bold">
                                    <i class="bi bi-shield-exclamation me-1"></i>Auto-Submit saat Kecurangan
                                </label>
                            </div>
                            <div class="form-text text-danger-emphasis small mb-2" style="margin-top:-4px;">
                                Ujian akan otomatis dikumpulkan saat curang. Saling mengunci dengan opsi "Boleh Diulang".
                            </div>
                        </div>

                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-clipboard2-check me-2 text-info"></i>Setelah Ujian Selesai</h6>

                        <?php
                            $settingModel = new \App\Models\SettingModel();
                            $globalShowScore = $settingModel->getValue('show_score_after_exam', false) ? 'Ya' : 'Tidak';
                            $globalShowCorrect = $settingModel->getValue('show_correct_answers', false) ? 'Ya' : 'Tidak';
                            $globalAllowReview = $settingModel->getValue('allow_review', false) ? 'Ya' : 'Tidak';
                        ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Tampilkan Skor</label>
                            <select class="form-select form-select-sm" name="show_score_after_exam">
                                <option value="default" <?= old('show_score_after_exam', $test->show_score_after_exam ?? null) === null ? 'selected' : '' ?>>Default (<?= $globalShowScore ?>)</option>
                                <option value="1" <?= old('show_score_after_exam', $test->show_score_after_exam ?? null) === '1' ? 'selected' : '' ?>>Ya</option>
                                <option value="0" <?= old('show_score_after_exam', $test->show_score_after_exam ?? null) === '0' ? 'selected' : '' ?>>Tidak</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Tampilkan Kunci Jawaban</label>
                            <select class="form-select form-select-sm" name="show_correct_answers">
                                <option value="default" <?= old('show_correct_answers', $test->show_correct_answers ?? null) === null ? 'selected' : '' ?>>Default (<?= $globalShowCorrect ?>)</option>
                                <option value="1" <?= old('show_correct_answers', $test->show_correct_answers ?? null) === '1' ? 'selected' : '' ?>>Ya</option>
                                <option value="0" <?= old('show_correct_answers', $test->show_correct_answers ?? null) === '0' ? 'selected' : '' ?>>Tidak</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Izinkan Review Soal</label>
                            <select class="form-select form-select-sm" name="allow_review">
                                <option value="default" <?= old('allow_review', $test->allow_review ?? null) === null ? 'selected' : '' ?>>Default (<?= $globalAllowReview ?>)</option>
                                <option value="1" <?= old('allow_review', $test->allow_review ?? null) === '1' ? 'selected' : '' ?>>Ya</option>
                                <option value="0" <?= old('allow_review', $test->allow_review ?? null) === '0' ? 'selected' : '' ?>>Tidak</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold">
                            <i class="bi bi-save me-1"></i> Simpan Pengaturan
                        </button>
                        <a href="<?= base_url('/admin/tests') ?>" class="btn btn-light border">Batal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const elAutoSubmit = document.getElementById('autoSubmitToggle');
    const elRepeatable = document.getElementById('isRepeatableToggle');
    const elIsEnabled = document.getElementById('isEnabledToggle');
    const elOptionsGroup = document.getElementById('executionOptionsGroup');

    function syncMutualExclusion() {
        if (!elAutoSubmit || !elRepeatable) return;
        
        if (elAutoSubmit.checked) {
            elRepeatable.checked = false;
            elRepeatable.disabled = true;
        } else {
            elRepeatable.disabled = false;
        }

        if (elRepeatable.checked) {
            elAutoSubmit.checked = false;
            elAutoSubmit.disabled = true;
        } else {
            elAutoSubmit.disabled = false;
        }
    }

    function syncIsEnabled() {
        if (!elIsEnabled || !elOptionsGroup) return;
        const isEnabled = elIsEnabled.checked;
        const inputs = elOptionsGroup.querySelectorAll('input, select');
        inputs.forEach(input => {
            input.disabled = !isEnabled;
        });
        if (isEnabled) {
            syncMutualExclusion();
        }
    }

    if (elAutoSubmit) elAutoSubmit.addEventListener('change', syncMutualExclusion);
    if (elRepeatable) elRepeatable.addEventListener('change', syncMutualExclusion);
    if (elIsEnabled) elIsEnabled.addEventListener('change', syncIsEnabled);

    // Run initial state setup
    syncIsEnabled();
});
</script>
<?= $this->endSection() ?>

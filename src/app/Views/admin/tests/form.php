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
                            <label class="form-label fw-semibold">Waktu Mulai (Opsional)</label>
                            <input type="datetime-local" class="form-control" name="begin_time" 
                                   value="<?= old('begin_time', $test->begin_time ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Waktu Selesai (Opsional)</label>
                            <input type="datetime-local" class="form-control" name="end_time" 
                                   value="<?= old('end_time', $test->end_time ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-text mb-3">Kosongkan jadwal jika ujian bisa diakses kapan saja.</div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Durasi (Menit) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="duration_minutes" min="0" 
                                   value="<?= old('duration_minutes', $test->duration_minutes ?? 0) ?>" required>
                            <div class="form-text">0 = Tanpa batas waktu</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Password (Opsional)</label>
                            <input type="text" class="form-control" name="password" 
                                   value="<?= old('password', $test->password ?? '') ?>" placeholder="Kosongkan jika bebas">
                        </div>
                        <div class="col-md-4">
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

        <!-- Kolom Kanan: Pengaturan Navigasi & Status -->
        <div class="col-lg-4">
            <div class="card shadow-sm sticky-top" style="top: 80px;">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="m-0 fw-bold"><i class="bi bi-toggles me-2"></i>Opsi Pelaksanaan</h6>
                </div>
                <div class="card-body p-4">
                    
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
                            <input class="form-check-input" type="checkbox" name="results_visible" value="1" 
                                   <?= old('results_visible', $test->results_visible ?? '0') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label">Tampilkan Nilai ke Siswa</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="is_repeatable" value="1" 
                                   <?= old('is_repeatable', $test->is_repeatable ?? '0') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label text-danger">Boleh Diulang</label>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-4">
                        <label class="form-label fw-bold">Status Ujian</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input fs-4" type="checkbox" name="is_enabled" value="1" 
                                   <?= old('is_enabled', $test->is_enabled ?? '0') == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label mt-1 ms-2">Aktif (Dapat Dikerjakan)</label>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-save me-1"></i> Simpan Pengaturan
                        </button>
                        <a href="<?= base_url('/admin/tests') ?>" class="btn btn-light">Batal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>

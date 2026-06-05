<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Konfigurasi Ujian: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <!-- Kolom Kiri: Penarikan Soal -->
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 fw-bold text-primary"><i class="bi bi-collection me-2"></i>Set Penarikan Soal</h6>
            </div>
            <div class="card-body p-0">
                <?php if (session()->has('errors')): ?>
                    <div class="alert alert-danger m-3 rounded-3">
                        <ul class="mb-0 ps-3">
                        <?php foreach (session('errors') as $error): ?>
                            <li><?= esc($error) ?></li>
                        <?php endforeach ?>
                        </ul>
                    </div>
                <?php endif ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Sumber Subjek</th>
                                <th>Tipe</th>
                                <th>Level</th>
                                <th>Jumlah</th>
                                <th class="text-end pe-4">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($subjectSets)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">
                                        Belum ada set soal. Siswa tidak akan mendapatkan soal apapun.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $types = [
                                    0 => 'Semua Tipe',
                                    1 => 'Pilihan Ganda',
                                    2 => 'Pilihan Ganda (Banyak)',
                                    3 => 'Esai / Teks',
                                    4 => 'Mengurutkan'
                                ];
                                foreach ($subjectSets as $set): 
                                ?>
                                    <tr>
                                        <td class="ps-4">
                                            <?php foreach ($set->subjects as $sub): ?>
                                                <span class="badge bg-light text-dark border mb-1"><?= esc($sub->name) ?></span><br>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="small text-muted"><?= $types[$set->question_type] ?? 'Unknown' ?></td>
                                        <td><span class="badge bg-secondary"><?= $set->difficulty == 0 ? 'Semua Level' : $set->difficulty ?></span></td>
                                        <td class="fw-bold text-primary"><?= $set->quantity ?> Soal</td>
                                        <td class="text-end pe-4">
                                            <form action="<?= base_url('/admin/tests/config/subjects/' . $set->id) ?>" method="POST" class="d-inline" onsubmit="event.preventDefault(); Swal.fire({title: 'Konfirmasi', text: 'Hapus set soal ini?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, Hapus', cancelButtonText: 'Batal', confirmButtonColor: '#dc3545'}).then((res) => { if(res.isConfirmed) this.submit(); });">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="_method" value="DELETE">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Set">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card-footer bg-light p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-plus-circle me-1"></i> Tambah Set Soal Baru</h6>
                <form action="<?= base_url('/admin/tests/config/' . $test->id . '/subjects') ?>" method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Pilih Subjek (Bisa lebih dari 1) <span class="text-danger">*</span></label>
                        <select class="form-select" name="subjects[]" multiple required style="height: 120px;">
                            <?php foreach ($subjectsByModule as $moduleName => $subjects): ?>
                                <optgroup label="<?= esc($moduleName) ?>">
                                    <?php foreach ($subjects as $sub): ?>
                                        <option value="<?= $sub->id ?>"><?= esc($sub->name) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Tekan CTRL (atau CMD) untuk memilih banyak subjek. Sistem akan mengacak soal dari subjek-subjek ini.</div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Tipe Soal</label>
                            <select class="form-select" name="question_type" required>
                                <option value="0" class="fw-bold text-primary">Semua Tipe Acak</option>
                                <option value="1">Pilihan Ganda</option>
                                <option value="2">Pilihan Ganda (Banyak)</option>
                                <option value="3">Esai / Teks</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Level Kesulitan</label>
                            <input type="number" class="form-control" name="difficulty" value="0" min="0" max="10" required>
                            <div class="form-text small">0 = Bebas Semua Level</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Jumlah Soal ditarik</label>
                            <input type="number" class="form-control" name="quantity" value="5" min="1" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i> Tambahkan Set</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Kolom Kanan: Peserta Ujian -->
    <div class="col-lg-5">
        <div class="card shadow-sm sticky-top" style="top: 80px;">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="m-0 fw-bold text-success"><i class="bi bi-people me-2"></i>Target Peserta Ujian (Grup)</h6>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-3">Pilih grup/kelas mana saja yang berhak mengikuti ujian ini.</p>
                
                <form action="<?= base_url('/admin/tests/config/' . $test->id . '/groups') ?>" method="POST">
                    <?= csrf_field() ?>
                    <div class="list-group mb-4">
                        <?php foreach ($allGroups as $group): ?>
                            <label class="list-group-item d-flex gap-2">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="groups[]" value="<?= $group->id ?>" 
                                       <?= in_array($group->id, $testGroups) ? 'checked' : '' ?>>
                                <span>
                                    <?= esc($group->name) ?>
                                    <small class="d-block text-muted"><?= esc($group->description) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="submit" class="btn btn-success w-100 btn-lg"><i class="bi bi-check2-circle me-1"></i> Simpan Akses Peserta</button>
                </form>
            </div>
            <div class="card-footer bg-white border-top text-center py-3">
                <a href="<?= base_url('/admin/tests') ?>" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Ujian</a>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

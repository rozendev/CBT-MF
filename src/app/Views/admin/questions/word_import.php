<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>
Import Soal dari Word
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0 fw-bold text-primary">Import Soal dari Word (.docx)</h3>
        <a href="<?= base_url('admin/questions') ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Bank Soal
        </a>
    </div>

    <?php if(session()->getFlashdata('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc(session()->getFlashdata('error')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Form Import -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-secondary">
                        <i class="bi bi-cloud-upload me-2"></i> Form Import Soal
                    </h5>
                </div>
                <div class="card-body p-4">
                    <form action="<?= base_url('admin/questions/word-import/process') ?>" method="POST" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Pilih Modul</label>
                            <select name="module_id" class="form-select" id="moduleSelect" required>
                                <option value="">-- Pilih Modul --</option>
                                <option value="new" class="fw-bold text-primary">+ Buat Modul Baru</option>
                                <?php foreach($modules as $m): ?>
                                    <option value="<?= $m->id ?>"><?= esc($m->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="newModuleGroup" style="display: none;">
                            <label class="form-label fw-semibold text-primary">Nama Modul Baru</label>
                            <input type="text" name="new_module_name" class="form-control border-primary" placeholder="Misal: Ujian Akhir Semester">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Nama Subjek Baru</label>
                            <input type="text" name="subject_name" class="form-control" placeholder="Misal: Matematika Kelas X" required>
                            <div class="form-text">Soal-soal dari dokumen Word akan dimasukkan ke dalam Subjek baru ini.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">File Dokumen Soal (.docx)</label>
                            <input type="file" name="word_file" class="form-control form-control-lg" accept=".docx" required>
                            <div class="form-text">Maksimal ukuran file: 5MB. Hanya mendukung file .docx.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1 py-2 fw-bold" id="btnSubmit">
                                <i class="bi bi-upload me-2"></i> Proses Import Soal
                            </button>
                            <a href="<?= base_url('admin/questions/word-import/template') ?>" class="btn btn-outline-secondary py-2 fw-bold" title="Download Template Format">
                                <i class="bi bi-download"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Panduan Format -->
        <div class="col-lg-6 mb-4">
            <div class="card bg-light border-0 shadow-sm rounded-3">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3 text-secondary">
                        <i class="bi bi-info-circle me-2"></i> Panduan Format Penulisan Word
                    </h5>
                    <p class="mb-3">Agar sistem dapat membaca soal dengan presisi, <strong>pastikan penulisan di dalam file Word persis seperti format di bawah ini</strong>:</p>
                    
                    <div class="bg-white p-3 border rounded font-monospace small mb-3" style="max-height: 250px; overflow-y: auto;">
Q:1) Siapa penemu bola lampu?<br>
A:) Albert Einstein<br>
B:) Thomas Alva Edison<br>
C:) Isaac Newton<br>
RIGHT:B<br>
<br>
Q:2) Apa nama ibukota Indonesia saat ini?<br>
TYPE:ESSAY<br>
RIGHT:Jakarta<br>
<br>
Q:3) Pasangkan negara dengan ibukotanya!<br>
TYPE:MATCHING<br>
MATCH:Jepang|::|Tokyo<br>
MATCH:Korea|::|Seoul<br>
<br>
Q:4) Tentukan benar salah pernyataan ini!<br>
TYPE:TRUEFALSE<br>
MATCH:Bumi itu bulat|::|Benar<br>
MATCH:Matahari terbit dari barat|::|Salah
                    </div>
                    
                    <ul class="text-muted small">
                        <li class="mb-1">Teks soal diawali <code>Q:1) </code>, <code>Q:2) </code> dst.</li>
                        <li class="mb-1"><strong>Pilihan Ganda:</strong> Pilihan jawaban wajib diawali <code>A:) </code>, <code>B:) </code> dst. Kunci ditulis <code>RIGHT:A</code> atau <code>RIGHT:A,B</code>.</li>
                        <li class="mb-1"><strong>Esai:</strong> Tambahkan <code>TYPE:ESSAY</code> di bawah soal. Kunci ditulis <code>RIGHT:Teks Jawaban</code>.</li>
                        <li class="mb-1"><strong>Menjodohkan:</strong> Tambahkan <code>TYPE:MATCHING</code> di bawah soal. Pasangan ditulis <code>MATCH:Premis Kiri|::|Jawaban Kanan</code>.</li>
                        <li class="mb-1"><strong>Benar/Salah:</strong> Tambahkan <code>TYPE:TRUEFALSE</code>. Pernyataan ditulis <code>MATCH:Pernyataan|::|Benar</code> atau <code>Salah</code>.</li>
                        <li class="mb-1">Unduh Template format di bawah untuk melihat contoh lengkapnya.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const moduleSelect = document.getElementById('moduleSelect');
    const newModuleGroup = document.getElementById('newModuleGroup');
    const newModuleInput = document.querySelector('input[name="new_module_name"]');

    moduleSelect.addEventListener('change', function() {
        if (this.value === 'new') {
            newModuleGroup.style.display = 'block';
            newModuleInput.setAttribute('required', 'required');
        } else {
            newModuleGroup.style.display = 'none';
            newModuleInput.removeAttribute('required');
        }
    });

    document.querySelector('form').addEventListener('submit', function() {
        const btn = document.getElementById('btnSubmit');
        setTimeout(function() {
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Sedang Memproses...';
            btn.disabled = true;
        }, 10);
    });
});
</script>
<?= $this->endSection() ?>

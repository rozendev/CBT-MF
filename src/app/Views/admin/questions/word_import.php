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
                    <form id="importForm" action="<?= base_url('admin/questions/word-import/process') ?>" method="POST" enctype="multipart/form-data" novalidate>
                        <?= csrf_field() ?>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Pilih Modul</label>
                            <select name="module_id" class="form-select" id="moduleSelect">
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
                            <input type="text" name="subject_name" class="form-control" placeholder="Misal: Matematika Kelas X">
                            <div class="form-text">Soal-soal dari dokumen Word akan dimasukkan ke dalam Subjek baru ini.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">File Dokumen Soal (.docx)</label>
                            <input type="file" name="word_file" class="form-control form-control-lg" accept=".docx">
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
                    <p class="mb-3">Format sekarang jauh lebih natural — tidak perlu hafal kode "Q:", "RIGHT:", dsb. Contoh:</p>

                    <div class="bg-white p-3 border rounded font-monospace small mb-3" style="max-height: 280px; overflow-y: auto;">
1. Siapa penemu bola lampu?<br>
A. Albert Einstein<br>
*B. Thomas Alva Edison<br>
C. Isaac Newton<br>
<br>
2. Siapa nama presiden pertama Republik Indonesia?<br>
Jawaban: Ir. Soekarno<br>
<br>
3. Pasangkan negara berikut dengan ibukotanya!<br>
Tipe: Menjodohkan<br>
[Tabel 2 kolom: Negara | Ibukota]<br>
<br>
4. Tentukan benar atau salah pernyataan berikut!<br>
Tipe: Benar/Salah<br>
[Tabel 2 kolom: Pernyataan | Benar/Salah]
                    </div>

                    <ul class="text-muted small">
                        <li class="mb-1">Soal baru diawali angka polos, mis. <code>1.</code> atau <code>1)</code> — atau pakai fitur List/Numbering bawaan Word (tidak perlu mengetik angka sama sekali).</li>
                        <li class="mb-1"><strong>Pilihan Ganda:</strong> opsi diawali huruf polos, mis. <code>A.</code> atau <code>A)</code>. Tandai jawaban benar dengan <code>*</code> tepat di depan huruf, mis. <code>*B. Thomas Alva Edison</code>. Boleh lebih dari satu opsi ber-<code>*</code> (otomatis jadi Pilihan Ganda Kompleks).</li>
                        <li class="mb-1"><strong>Esai:</strong> cukup tulis soalnya saja. Baris <code>Jawaban: ...</code> di bawahnya sifatnya opsional, hanya untuk referensi.</li>
                        <li class="mb-1"><strong>Menjodohkan:</strong> tambahkan baris <code>Tipe: Menjodohkan</code> di bawah soal, lalu buat tabel Word 2 kolom tepat di bawahnya — baris pertama tabel jadi judul kolom (dilewati), baris berikutnya jadi pasangan.</li>
                        <li class="mb-1"><strong>Benar/Salah:</strong> sama seperti Menjodohkan, tapi tulis <code>Tipe: Benar/Salah</code> dan isi kolom kanan tabel dengan "Benar" atau "Salah".</li>
                        <li class="mb-1">Tabel biasa (tanpa <code>Tipe:</code> di atasnya) tetap bisa dipakai sebagai data pendukung soal, seperti sebelumnya.</li>
                        <li class="mb-1">Mendukung teks multi-baris, gambar yang disisipkan di dalam dokumen, dan opsi/soal lewat list bawaan Word.</li>
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
    const importForm = document.getElementById('importForm');

    moduleSelect.addEventListener('change', function() {
        if (this.value === 'new') {
            newModuleGroup.style.display = 'block';
        } else {
            newModuleGroup.style.display = 'none';
        }
    });

    const BANK_SOAL_URL = '<?= base_url('admin/questions') ?>';

    function statCard(count, label, tone, icon) {
        return `
            <div class="col-4">
                <div class="border border-${tone}-subtle bg-${tone}-subtle rounded-3 py-3 px-2 h-100">
                    <div class="text-${tone} fs-3 fw-bold lh-1">${count}</div>
                    <div class="small text-muted mt-1"><i class="bi ${icon} me-1"></i>${label}</div>
                </div>
            </div>`;
    }

    // Teks soal dari server sudah lolos htmlspecialchars waktu dokumen dibaca,
    // jadi aman ditempel apa adanya -- meng-escape ulang malah bikin tanda
    // kurang dari dan sejenisnya tampil sebagai entitas mentah.
    function listSection(title, rows, more, tone, renderReason) {
        if (!rows || rows.length === 0) {
            return '';
        }

        let html = `<div class="mt-3 text-start">
            <div class="fw-semibold text-${tone} mb-1"><i class="bi bi-list-ul me-1"></i>${title}</div>
            <ul class="list-group list-group-flush small" style="max-height: 220px; overflow-y: auto;">`;

        rows.forEach(row => {
            html += `<li class="list-group-item px-2 py-2">
                <div class="fw-semibold">${row.soal}</div>
                <div class="text-muted">${renderReason(row)}</div>
            </li>`;
        });

        html += '</ul>';
        if (more > 0) {
            html += `<div class="small text-muted mt-1">...dan ${more} soal lainnya.</div>`;
        }

        return html + '</div>';
    }

    function showImportSummary(res) {
        const s = res.summary || { total: 0, masuk: 0, duplikat: 0, ditolak: 0 };
        const masuk = s.masuk || 0;

        let html = `<p class="text-muted mb-3">${s.total} soal terbaca dari dokumen untuk Subjek
            <strong>${res.subject || ''}</strong>.</p>
            <div class="row g-2 text-center">
                ${statCard(masuk, 'Masuk', 'success', 'bi-check-circle')}
                ${statCard(s.duplikat || 0, 'Duplikat', 'warning', 'bi-files')}
                ${statCard(s.ditolak || 0, 'Ditolak', 'danger', 'bi-x-circle')}
            </div>`;

        html += listSection(
            'Duplikat (tidak disimpan)', res.duplicates, res.duplicates_more, 'warning',
            row => row.alasan
        );
        html += listSection(
            'Ditolak (perlu diperbaiki)', res.rejected, res.rejected_more, 'danger',
            row => (row.alasan || []).join('<br>')
        );

        Swal.fire({
            icon: masuk > 0 ? 'success' : 'warning',
            title: masuk > 0 ? 'Import Selesai' : 'Tidak Ada Soal yang Masuk',
            html: html,
            width: 720,
            showCancelButton: masuk > 0,
            confirmButtonText: masuk > 0 ? 'Ke Bank Soal' : 'Perbaiki Dokumen',
            cancelButtonText: 'Tetap di Sini',
            customClass: { popup: 'rounded-4' }
        }).then(result => {
            if (masuk > 0 && result.isConfirmed) {
                window.location.href = BANK_SOAL_URL;
            }
        });
    }

    importForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const moduleId = moduleSelect.value;
        const newModuleName = newModuleInput.value.trim();
        const subjectInput = document.querySelector('input[name="subject_name"]');
        const subjectName = subjectInput.value.trim();
        const fileInput = document.querySelector('input[name="word_file"]');

        // SweetAlert2 Client Validation Enforcements
        if (!moduleId) {
            Swal.fire({
                icon: 'warning',
                title: 'Modul Belum Dipilih',
                text: 'Silakan pilih Modul terlebih dahulu.',
                customClass: { popup: 'rounded-4' }
            });
            return;
        }

        if (moduleId === 'new' && !newModuleName) {
            Swal.fire({
                icon: 'warning',
                title: 'Nama Modul Kosong',
                text: 'Silakan isi nama untuk modul baru.',
                customClass: { popup: 'rounded-4' }
            });
            return;
        }

        if (!subjectName) {
            Swal.fire({
                icon: 'warning',
                title: 'Nama Subjek Kosong',
                text: 'Silakan isi nama Subjek terlebih dahulu.',
                customClass: { popup: 'rounded-4' }
            });
            return;
        }

        if (!fileInput.files || fileInput.files.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'File Belum Dipilih',
                text: 'Silakan pilih file dokumen Word (.docx) untuk diimport.',
                customClass: { popup: 'rounded-4' }
            });
            return;
        }

        const fileName = fileInput.files[0].name;
        if (!fileName.toLowerCase().endsWith('.docx')) {
            Swal.fire({
                icon: 'error',
                title: 'Format File Salah',
                text: 'Hanya file dokumen dengan ekstensi .docx yang didukung.',
                customClass: { popup: 'rounded-4' }
            });
            return;
        }

        const btn = document.getElementById('btnSubmit');
        const originalBtnHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Memproses & Memvalidasi...';
        btn.disabled = true;

        const formData = new FormData(importForm);

        fetch(importForm.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(res => {
            btn.innerHTML = originalBtnHtml;
            btn.disabled = false;

            if (res.status === 'validation_error') {
                let errorHtml = '<div class="text-start small mt-2"><ul class="text-danger ps-3 mb-0" style="max-height: 250px; overflow-y: auto;">';
                (res.errors || []).forEach(err => {
                    errorHtml += `<li class="mb-1">${err}</li>`;
                });
                errorHtml += '</ul></div>';

                Swal.fire({
                    icon: 'error',
                    title: 'Validasi Format Gagal!',
                    html: `<p class="mb-2 text-muted">Ditemukan ${res.errors.length} kesalahan pada dokumen Word Anda. Tidak ada data yang disimpan ke database:</p>` + errorHtml,
                    confirmButtonText: 'Perbaiki Dokumen',
                    customClass: { popup: 'rounded-4' }
                });
            } else if (res.status === 'error') {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Import',
                    text: res.message || 'Terjadi kesalahan pada server.',
                    customClass: { popup: 'rounded-4' }
                });
            } else if (res.status === 'success') {
                showImportSummary(res);
            }
        })
        .catch(err => {
            btn.innerHTML = originalBtnHtml;
            btn.disabled = false;
            Swal.fire({
                icon: 'error',
                title: 'Kesalahan Sistem',
                text: 'Gagal berkomunikasi dengan server. Silakan coba lagi.',
                customClass: { popup: 'rounded-4' }
            });
        });
    });
});
</script>
<?= $this->endSection() ?>

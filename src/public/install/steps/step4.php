<h5 class="fw-bold mb-4">Langkah 4: Setup Akun Admin Utama</h5>
<p class="text-muted mb-4">Buat akun untuk Super Admin. Akun ini memiliki akses penuh ke seluruh fitur sistem ujian.</p>

<form id="installForm">
    <input type="hidden" name="action" value="install">
    
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <label class="form-label">Username Admin</label>
            <input type="text" name="admin_user" class="form-control" value="admin" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" name="admin_pass" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Nama Depan</label>
            <input type="text" name="admin_firstname" class="form-control" value="Super" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Nama Belakang</label>
            <input type="text" name="admin_lastname" class="form-control" value="Admin">
        </div>
        <div class="col-md-12">
            <label class="form-label">Email (Opsional)</label>
            <input type="email" name="admin_email" class="form-control">
        </div>
    </div>

    <div id="installResult" class="mb-3"></div>

    <div class="d-flex justify-content-between mt-4">
        <a href="?step=3" class="btn btn-outline-secondary" id="btnBack"><i class="bi bi-arrow-left"></i> Kembali</a>
        <button type="submit" id="btnInstall" class="btn btn-success px-4 fw-bold"><i class="bi bi-rocket-takeoff"></i> Mulai Instalasi</button>
    </div>
</form>

<?php ob_start(); ?>
<script>
$(document).ready(function() {
    $('#installForm').submit(function(e) {
        e.preventDefault();
        
        const btn = $('#btnInstall');
        const btnBack = $('#btnBack');
        
        btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sedang menginstal... (Jangan tutup halaman ini)');
        btn.prop('disabled', true);
        btnBack.hide();
        $('#installResult').html('<div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i> Memproses instalasi database dan konfigurasi. Mohon tunggu...</div>');
        
        $.post('index.php', $(this).serialize(), function(res) {
            try {
                const data = typeof res === 'string' ? JSON.parse(res) : res;
                if (data.status === 'success') {
                    $('#installForm .row').hide();
                    $('#installResult').html(`
                        <div class="alert alert-success p-4 text-center">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                            <h4 class="fw-bold mt-3">Instalasi Berhasil!</h4>
                            <p class="mb-0">Sistem Ujian CBT telah berhasil dikonfigurasi dan siap digunakan.</p>
                        </div>
                    `);
                    btn.parent().html(`<a href="/" class="btn btn-primary px-5 fw-bold">Masuk ke Aplikasi <i class="bi bi-box-arrow-in-right"></i></a>`);
                } else {
                    $('#installResult').html('<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> ' + data.message + '</div>');
                    btn.html('<i class="bi bi-rocket-takeoff"></i> Mulai Instalasi');
                    btn.prop('disabled', false);
                    btnBack.show();
                }
            } catch (e) {
                $('#installResult').html('<div class="alert alert-danger py-2">Invalid response from server. Check PHP error logs.</div>');
                btn.html('<i class="bi bi-rocket-takeoff"></i> Mulai Instalasi');
                btn.prop('disabled', false);
                btnBack.show();
            }
        }).fail(function() {
            btn.html('<i class="bi bi-rocket-takeoff"></i> Mulai Instalasi');
            btn.prop('disabled', false);
            btnBack.show();
            $('#installResult').html('<div class="alert alert-danger py-2">Gagal memproses instalasi.</div>');
        });
    });
});
</script>
<?php $extraScripts = ob_get_clean(); ?>

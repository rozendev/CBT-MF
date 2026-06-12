<h5 class="fw-bold mb-4">Langkah 3: Konfigurasi Database & Redis</h5>
<p class="text-muted mb-4">Masukkan detail koneksi database MySQL dan Redis Anda. Pastikan database MySQL sudah dibuat kosong.</p>

<form id="dbForm">
    <input type="hidden" name="action" value="test_db">
    
    <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-database me-2"></i>MySQL Database</h6>
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <label class="form-label">Database Host</label>
            <input type="text" name="db_host" class="form-control" value="localhost" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Port</label>
            <input type="number" name="db_port" class="form-control" value="3306" required>
        </div>
        <div class="col-md-12">
            <label class="form-label">Database Name</label>
            <input type="text" name="db_name" class="form-control" value="sistem_ujian" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Username</label>
            <input type="text" name="db_user" class="form-control" value="root" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Password</label>
            <input type="password" name="db_pass" class="form-control">
        </div>
    </div>

    <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-server me-2"></i>Redis Server</h6>
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <label class="form-label">Redis Host</label>
            <input type="text" name="redis_host" class="form-control" value="127.0.0.1" required>
            <div class="form-text">Bisa menggunakan IP, 'localhost', atau nama kontainer 'redis'.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Port</label>
            <input type="number" name="redis_port" class="form-control" value="6379" required>
        </div>
    </div>

    <div id="testResult" class="mb-3"></div>

    <div class="d-flex justify-content-between mt-4">
        <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
        <div>
            <button type="button" id="btnTest" class="btn btn-info text-white me-2"><i class="bi bi-plug"></i> Test Koneksi</button>
            <button type="submit" id="btnNext" class="btn btn-primary px-4" disabled>Lanjut Langkah 4 <i class="bi bi-arrow-right"></i></button>
        </div>
    </div>
</form>

<?php ob_start(); ?>
<script>
$(document).ready(function() {
    $('#btnTest').click(function() {
        const btn = $(this);
        btn.html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Mengetes...');
        btn.prop('disabled', true);
        $('#btnNext').prop('disabled', true);
        $('#testResult').html('');
        
        $.post('index.php', $('#dbForm').serialize(), function(res) {
            btn.html('<i class="bi bi-plug"></i> Test Koneksi');
            btn.prop('disabled', false);
            
            try {
                const data = typeof res === 'string' ? JSON.parse(res) : res;
                if (data.status === 'success') {
                    $('#testResult').html('<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i> ' + data.message + '</div>');
                    $('#btnNext').prop('disabled', false);
                } else {
                    $('#testResult').html('<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i> ' + data.message + '</div>');
                }
            } catch (e) {
                $('#testResult').html('<div class="alert alert-danger py-2">Invalid response from server</div>');
            }
        }).fail(function() {
            btn.html('<i class="bi bi-plug"></i> Test Koneksi');
            btn.prop('disabled', false);
            $('#testResult').html('<div class="alert alert-danger py-2">Gagal menghubungi server.</div>');
        });
    });

    $('#dbForm').submit(function(e) {
        e.preventDefault();
        // Save to temporary session then redirect
        $.post('index.php', $(this).serialize() + '&action=save_db', function() {
            window.location.href = '?step=4';
        });
    });
});
</script>
<?php $extraScripts = ob_get_clean(); ?>

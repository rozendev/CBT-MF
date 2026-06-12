<h5 class="fw-bold mb-4">Langkah 2: Web Server & URL Aplikasi</h5>
<p class="text-muted mb-4">Pilih jenis Web Server yang Anda gunakan dan tentukan URL utama untuk aplikasi ini.</p>

<form id="serverForm">
    <input type="hidden" name="action" value="save_server">
    
    <div class="mb-3">
        <label class="form-label fw-bold">Pilih Web Server <span class="text-danger">*</span></label>
        <select name="server_type" class="form-select" required>
            <option value="apache" selected>Apache (Default, menggunakan .htaccess)</option>
            <option value="nginx">Nginx (Membutuhkan Nginx server block khusus)</option>
            <option value="litespeed">OpenLiteSpeed / LiteSpeed (Menggunakan .htaccess)</option>
        </select>
        <div class="form-text">Secara default CodeIgniter 4 bekerja paling baik dengan Apache karena sudah menyertakan .htaccess.</div>
    </div>
    
    <div class="mb-4">
        <label class="form-label fw-bold">URL Aplikasi (APP_URL) <span class="text-danger">*</span></label>
        <input type="url" name="app_url" class="form-control" id="app_url" required placeholder="Contoh: https://ujian.sekolah.sch.id/" value="">
        <div class="form-text">URL utama tempat aplikasi ini dapat diakses. Wajib diakhiri dengan garis miring (/).</div>
    </div>
    
    <div class="d-flex justify-content-between">
        <a href="?step=1" class="btn btn-outline-secondary px-4"><i class="bi bi-arrow-left"></i> Kembali</a>
        <button type="submit" class="btn btn-primary px-4" id="btnNextServer">Lanjut <i class="bi bi-arrow-right"></i></button>
    </div>
</form>

<?php ob_start(); ?>
<script>
$(document).ready(function() {
    // Auto detect current URL
    let currentUrl = window.location.href;
    // Remove the /install/.* part
    let baseUrl = currentUrl.split('/install/')[0] + '/';
    $('#app_url').val(baseUrl);

    $('#serverForm').on('submit', function(e) {
        e.preventDefault();
        
        let urlVal = $('#app_url').val();
        if(!urlVal.endsWith('/')) {
            urlVal += '/';
            $('#app_url').val(urlVal);
        }

        let btn = $('#btnNextServer');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Menyimpan...');
        
        $.ajax({
            url: 'index.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    window.location.href = '?step=3';
                } else {
                    alert('Error: ' + res.message);
                    btn.prop('disabled', false).html('Lanjut <i class="bi bi-arrow-right"></i>');
                }
            },
            error: function() {
                alert('Terjadi kesalahan saat menghubungi server.');
                btn.prop('disabled', false).html('Lanjut <i class="bi bi-arrow-right"></i>');
            }
        });
    });
});
</script>
<?php
$extraScripts = ob_get_clean();
?>

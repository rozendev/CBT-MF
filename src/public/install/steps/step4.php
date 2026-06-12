<h5 class="fw-bold mb-4">Langkah 4: Integrasi Cloudflare</h5>
<p class="text-muted mb-4">Konfigurasikan integrasi Cloudflare untuk memastikan keamanan dan pelacakan IP berjalan dengan baik jika Anda menggunakan layanan proksi Cloudflare.</p>

<form method="POST" action="index.php?step=5">
    <input type="hidden" name="action" value="save_cf">
    
    <div class="card border-0 bg-white shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" style="width: 3em; height: 1.5em;" type="checkbox" role="switch" id="cf_real_ip" name="cf_real_ip" value="1" checked>
                <label class="form-check-label ms-3 mt-1 fw-bold text-dark" for="cf_real_ip">Aktifkan Cloudflare Real IP (Wajib)</label>
            </div>
            <p class="text-muted small mb-0 ms-1">
                Jika diaktifkan, Sistem Ujian akan membaca IP pengunjung dari header <code>HTTP_CF_CONNECTING_IP</code>. Hal ini memastikan bahwa fitur <strong>Banned IP</strong> dan pelacakan login mendeteksi IP asli peserta ujian, bukan IP dari server proksi Cloudflare.
            </p>
        </div>
    </div>

    <div class="d-flex justify-content-between mt-4">
        <a href="?step=3" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
        <button type="submit" class="btn btn-primary px-4">Lanjut Langkah 5 <i class="bi bi-arrow-right"></i></button>
    </div>
</form>

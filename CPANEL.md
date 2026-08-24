# THIS APP CAN NOT RUN WITHOUT REDIS!

> [!CAUTION]
> **WARNING: APLIKASI INI TIDAK DAPAT BERJALAN TANPA REDIS!**  
> CBT-MF membutuhkan Redis secara mutlak untuk *Write-Behind Cache* penyimpanan jawaban ujian siswa, WebSocket token, dan sesi login real-time. Mendeploy aplikasi ini di Shared Hosting/cPanel tanpa server Redis akan menyebabkan **gagal menyimpan jawaban (HTTP 503)** dan kecurangan data. VPS + Docker adalah arsitektur yang **wajib** digunakan.

---

# Panduan Deploy CodeIgniter 4 + Redis ke cPanel / Shared Hosting

Aplikasi ini aslinya dirancang untuk arsitektur VPS / Docker Environment dengan dukungan penuh memori cache (Redis) dan *background daemon* (untuk socket dan finalisasi otomatis). Namun, jika Anda **TERPAKSA** harus mendeploynya ke Shared Hosting/cPanel standar, harap perhatikan hal-hal krusial berikut:

## 1. Keterbatasan & Trade-off
- **Real-Time WebSocket (Ratchet):** Layanan WebSocket tidak akan berjalan tanpa akses CLI / background daemon secara konsisten. **Solusi:** Fitur real-time ujian akan secara otomatis *fallback* ke mode koneksi HTTP standar (jika didukung aplikasi) atau sebagian fitur *live monitoring* siswa tidak akan tampil secara real-time.
- **Cache & Session via Redis:** Jika hosting Anda tidak menyediakan server Redis (port default 6379), kecepatan pengambilan soal secara paralel akan turun drastis karena semua beban menumpuk ke MySQL. Anda akan mendapati pesan peringatan performa di Dashboard Admin. Session kemungkinan juga harus difallback ke berbasis File.
- **Auto Finalize Cron:** Auto-finalizer membutuhkan eksekusi terminal (CLI). Anda harus mengatur **Cron Job** cPanel secara manual untuk mengeksekusi perintah berikut setiap menit:
  ```bash
  /usr/local/bin/php /home/username/public_html/spark finalize:expired
  ```
  *(Pastikan path PHP dan project disesuaikan dengan direktori cPanel Anda).*

## 2. Mendeteksi Jenis Web Server Hosting Anda
Sangat penting untuk mengetahui jenis web server yang digunakan oleh penyedia Shared Hosting Anda (Apache, LiteSpeed, atau Nginx). CodeIgniter 4 sangat bergantung pada aturan *routing URI* yang benar.

Cara mengecek web server:
1. Buat file `info.php` di dalam folder `public/`:
   ```php
   <?php phpinfo(); ?>
   ```
2. Buka di browser: `https://domain-ujian-anda.com/info.php`
3. Cari informasi **`$_SERVER['SERVER_SOFTWARE']`**.
   - Jika tertulis `Apache` atau `LiteSpeed`, Anda **wajib** menyesuaikan / memastikan file `.htaccess` berjalan semestinya.
   - Jika tertulis `Nginx`, Anda memerlukan konfigurasi *location blocks* khusus (biasanya Shared Hosting jarang memakai Nginx sebagai handler utama tanpa proxy, jika ya, hubungi CS Hosting Anda).

Alternatif cepat cek via Terminal SSH di cPanel (jika fitur ini diberikan):
```bash
php -r "echo $_SERVER['SERVER_SOFTWARE'];"
```

## 3. Konfigurasi Dasar .htaccess (Apache / LiteSpeed)
Jika Anda tidak bisa mengarahkan Document Root domain langsung ke folder `public/`, dan harus meletakkan isinya di root `public_html/`, pastikan file `.htaccess` ini ada di dalam direktori utama untuk mengaktifkan RewriteEngine:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php/$1 [L]
</IfModule>
```
> **Peringatan:** Pastikan path di dalam `index.php` (yang mengarah ke `../app/Config/Paths.php`) telah Anda perbaiki sesuai struktur folder cPanel Anda yang baru, agar CodeIgniter bisa di-*bootstrap* dengan benar.

## Kesimpulan
Sangat disarankan menggunakan layanan **VPS (Virtual Private Server)** dengan Docker demi jaminan keandalan performa ujian dan keamanan sistem secara penuh. Penerapan di cPanel/Shared Hosting hanya direkomendasikan jika jumlah *traffic* atau siswa peserta sangat minim.

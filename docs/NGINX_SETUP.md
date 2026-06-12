# Panduan Konfigurasi Nginx untuk Sistem Ujian CBT

Secara default, CodeIgniter 4 sudah menyediakan file `.htaccess` yang sangat cocok untuk Apache. Namun, jika Anda memilih untuk menggunakan **Nginx** sebagai web server Anda, Anda memerlukan konfigurasi *Server Block* (Virtual Host) khusus agar sistem *routing* CodeIgniter 4 (dan penghapusan `index.php` pada URL) dapat berjalan dengan lancar.

## Persyaratan Nginx

1.  Pastikan `nginx` dan `php-fpm` (misalnya `php8.2-fpm`) sudah terinstal dan berjalan.
2.  Pastikan root directory Anda mengarah ke direktori `public/` di dalam folder `src/` Sistem Ujian.

## Contoh Konfigurasi Server Block Nginx

Buat sebuah file konfigurasi baru di `/etc/nginx/sites-available/sistem_ujian` (pada Ubuntu/Debian) dan isikan konfigurasi berikut. Sesuaikan `server_name` dan `root` dengan path server Anda:

```nginx
server {
    listen 80;
    listen [::]:80;
    
    # Ganti dengan domain atau IP server Anda
    server_name ujian.sekolah.sch.id;

    # Arahkan root ke folder "public" di dalam instalasi Anda
    root /var/www/sistem_ujian/src/public;
    index index.php index.html index.htm;

    # Log akses dan error (Opsional tapi disarankan)
    access_log /var/log/nginx/sistem_ujian_access.log;
    error_log /var/log/nginx/sistem_ujian_error.log;

    # Blokir akses ke file .env dan file tersembunyi lainnya
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # Aturan Routing Utama (Sangat Penting untuk CodeIgniter 4)
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    # Konfigurasi PHP-FPM
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        
        # Sesuaikan versi PHP-FPM Anda (misal: php8.2-fpm.sock)
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        
        # Jika menggunakan Docker/TCP, gunakan baris berikut dan nonaktifkan baris unix socket di atas:
        # fastcgi_pass 127.0.0.1:9000;
        
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Optimalisasi Caching untuk Static Assets
    location ~* \.(ico|css|js|gif|jpeg|jpg|png|woff|ttf|otf|svg|woff2|eot)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public";
    }
}
```

## Langkah Selanjutnya

1.  **Aktifkan Konfigurasi**: Buat symbolic link dari `sites-available` ke `sites-enabled`.
    ```bash
    sudo ln -s /etc/nginx/sites-available/sistem_ujian /etc/nginx/sites-enabled/
    ```
2.  **Uji Konfigurasi Nginx**: Pastikan tidak ada kesalahan ketik (typo).
    ```bash
    sudo nginx -t
    ```
3.  **Restart Nginx**: Terapkan konfigurasi.
    ```bash
    sudo systemctl restart nginx
    ```

## Pertimbangan untuk Cloudflare
Jika Anda juga menggunakan proxy dari Cloudflare (sebagaimana opsi pada Langkah 4 di Web Installer), server Nginx Anda akan selalu melihat IP Cloudflare alih-alih IP asli klien. Meskipun installer Sistem Ujian sudah bisa menangani `HTTP_CF_CONNECTING_IP` via PHP, Anda juga bisa menambahkan aturan Nginx (set_real_ip_from) untuk keamanan berlapis pada tingkat Nginx. Namun hal tersebut opsional selama sakelar Cloudflare di installer telah Anda aktifkan.

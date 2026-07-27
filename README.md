# Sistem Ujian (CBT)

Aplikasi Computer-Based Test (CBT) menggunakan CodeIgniter 4 (PHP 8.5), dirancang untuk skalabilitas tinggi dengan Docker, Nginx, MariaDB, dan Redis.

Aplikasi ini menggunakan teknologi **WebSocket (ReactPHP/Ratchet)** untuk deteksi kecurangan dan manajemen sesi secara *real-time* (seperti Ban, Kick, Tambah Waktu, dan Sinkronisasi Mode Statis) guna menghindari habisnya *PHP-FPM worker* pada saat beban tinggi.

## Fitur Utama
- **Ujian Berbasis Waktu**: Ujian dengan batas waktu yang bisa ditambah oleh admin secara *real-time*.
- **Anti-Cheat Terintegrasi**: Peringatan otomatis ketika siswa keluar dari mode *fullscreen* atau beralih tab.
- **Static Exam Generator**: Menghasilkan file statis HTML yang membuat ujian tahan terhadap lonjakan akses ribuan peserta sekaligus.
- **WebSocket Daemon**: Daemon independen dengan single-thread ReactPHP & Redis Pub/Sub untuk komunikasi server-ke-klien dengan CPU dan Memory footprint yang sangat kecil.
- **Cloudflare Tunnel Ready**: Konfigurasi telah disesuaikan agar berjalan lancar di belakang proksi dan Cloudflare.

## Instalasi & Menjalankan Aplikasi

Aplikasi ini sepenuhnya berjalan di dalam Docker. Semua perintah dieksekusi menggunakan *wrapper* script `./scripts/cmd.sh`.

### 1. Persiapan Environment
1. Salin file environment untuk Docker Compose di root direktori:
   ```bash
   cp .env.example .env
   # Sesuaikan kredensial database & Redis jika diperlukan di .env
   ```
2. Salin file environment untuk CodeIgniter di dalam folder `src/`:
   ```bash
   cp src/env src/.env
   # Sesuaikan app.baseURL dan database di src/.env
   ```

Jika menggunakan Cloudflare Tunnel, atur variabel lingkungan `CF_TUNNEL_TOKEN` di file `.env` root atau export di shell:
```bash
export CF_TUNNEL_TOKEN="token_anda_disini"
```

### 2. Membangun & Menjalankan Service
```bash
./scripts/cmd.sh up -d
```
Service yang berjalan:
- **App (Nginx/PHP)**: `http://localhost:8080`
- **phpMyAdmin**: `http://localhost:8081`
- **MariaDB**: `localhost:3306`
- **Redis**: `localhost:6379`
- **WebSocket**: Port `8060` (di-proxy secara transparan oleh Nginx via `/ws/`)

### 3. Composer & Migrasi Database (Saat Pertama Kali Clone)
Saat pertama kali melakukan clone repositori, Anda harus menginstal semua dependensi Composer di dalam kontainer PHP sebelum dapat menjalankan migrasi database.

Anda dapat masuk ke shell kontainer PHP dan menjalankan perintah Composer secara interaktif:
```bash
./scripts/cmd.sh shell                      # Masuk ke dalam bash container PHP
composer install                            # Jalankan composer install di dalam container
exit                                        # Keluar dari shell container
```

Atau jalankan perintah langsung dari host menggunakan wrapper script:
```bash
./scripts/cmd.sh composer install          # Instalasi dependency
```

Setelah Composer selesai menginstal dependensi, jalankan migrasi database dan seeder data awal:
```bash
./scripts/cmd.sh php spark migrate         # Jalankan migrasi database
./scripts/cmd.sh php spark db:seed MainSeeder # (Opsional) Seeder data awal
```

## Pemecahan Masalah (Troubleshooting)

### 1. WebSocket Terputus / Tidak Konek (ERR_CONNECTION_REFUSED atau Code 1006)
- **Gejala**: Siswa masuk ke halaman ujian tetapi *alert* "Reconnecting WebSocket..." muncul terus menerus.
- **Solusi**:
  1. Pastikan container `ujian_websocket` berjalan: `docker ps | grep ujian_websocket`.
  2. Jika statusnya *restarting/exited*, periksa log: `docker logs ujian_websocket`. 
  3. Pastikan konfigurasi proxy Nginx terbaru sudah dimuat. Jalankan: `docker restart ujian_nginx`.
  4. Jika masalah terjadi di halaman ujian **Mode Statis**, hapus halaman statis tersebut dari menu Admin dan **Generate Ulang**. Halaman lama mungkin tidak membawa parameter `user_id` pada URL WebSocket-nya.

### 2. Pesan `Could not find a matching version of package` saat Composer Update
- **Solusi**: Pastikan nama *package* benar (misalnya `clue/redis-react`, bukan `clue/reactphp-redis`). Selalu gunakan `./scripts/cmd.sh composer require <nama-paket>` dari *root* direktori.

### 3. Koneksi WebSocket Terputus Secara Periodik oleh Cloudflare
- **Gejala**: Tidak ada error, namun Cloudflare Tunnel memutus WebSocket setelah ± 100 detik jika tidak ada aktivitas.
- **Solusi**: Sistem sudah dilengkapi dengan mekanisme **Heartbeat** interval 30 detik (daemon akan melakukan ping ke semua klien). Jika masih sering terputus, periksa apakah loop timer pada `WebSocketServe.php` tereksekusi dengan benar tanpa diblokir oleh pemrosesan sinkronous (seperti koneksi ke database yang memblokir EventLoop). Jangan gunakan PDO MariaDB sinkronous di dalam WebSocket daemon.

### 4. Progress Jawaban Tidak Tersimpan (Autosave Error)
- **Solusi**: Pastikan Redis menyala, karena semua *autosave* ditulis sementara ke Redis (`exam_answers:attemptId`) sebelum di-*flush* ke MariaDB secara massal di akhir ujian. Cek log `docker logs ujian_redis`.

### 5. Sesi Cepat Berakhir atau Session Menumpuk
- **Solusi**: Pastikan *session handler* di file `src/.env` tidak ter-*override* ke *file* biasa. Untuk performa terbaik di Docker, atur `app.sessionDriver = 'CodeIgniter\Session\Handlers\RedisHandler'` dan pastikan `app.sessionSavePath` mengarah ke Redis server Anda (`tcp://redis:6379`).

### 6. Error Permission / Gagal Generate Static atau Upload Gambar (Could not move file)
- **Gejala**: Muncul pesan error tidak dapat menulis file HTML saat melakukan *Generate* halaman statis, atau error seperti `Could not move file "..." to "/var/www/html/public/uploads/"` saat mengunggah gambar di editor.
- **Solusi**: Pastikan folder `src/writable/`, `src/public/static/`, dan `src/public/uploads/` dapat ditulis oleh kontainer web server (PHP-FPM). Sesuai *best practice* keamanan, hindari penggunaan `chmod 777`.

Lakukan pengaturan kepemilikan ke grup web server (`www-data`, atau GID 33 di Docker) dan beri izin akses `775` (rwxrwxr-x):

**Di Linux Host:**
```bash
# Buat direktori jika belum ada
mkdir -p src/writable src/public/static src/public/uploads

# Ubah kepemilikan grup ke GID 33 (www-data) dan atur permission ke 775
sudo chown -R :33 src/writable src/public/static src/public/uploads
chmod -R 775 src/writable src/public/static src/public/uploads
```

**Atau langsung dari dalam kontainer PHP:**
```bash
./scripts/cmd.sh shell
chown -R www-data:www-data writable public/static public/uploads
chmod -R 775 writable public/static public/uploads
exit
```

### 7. PHP-FPM Resource Exhausted / Server Terasa Lambat
- **Solusi**: Jika Anda baru saja beralih dari versi lawas (yang masih menggunakan Server-Sent Events / EventSource), pastikan Anda telah sepenuhnya mendeploy Daemon WebSocket. Cara termudahnya adalah periksa *Network tab* di *DevTools* Browser. Anda harusnya melihat aktivitas ke `wss://domain.com/ws/` dengan status kode `101 Switching Protocols`, bukan request yang terus-menerus "*Pending*" (itu adalah sisa SSE FPM).

## Struktur Repositori
- `src/` - Kode aplikasi CodeIgniter 4
- `docker/` - Dockerfiles (Nginx, PHP-FPM, MariaDB init scripts)
- `scripts/` - Script eksekusi Docker `cmd.sh` dan utilitas lainnya
- `docker-compose.yml` - Orkestrasi Container

## Keamanan & Deployment Produksi

Sebelum merilis aplikasi ini ke lingkungan produksi (production), pastikan Anda melakukan langkah-langkah keamanan berikut:

1. **Hapus Folder Installer**: Setelah proses instalasi selesai, hapus atau pindahkan folder `src/public/install/` untuk mencegah penyalahgunaan installer secara berkala.
2. **Hapus Berkas Pengujian**: Hapus berkas pengujian `src/public/install/test.php` dan `src/test_api.php` jika masih ada.
3. **Konfigurasi phpMyAdmin**:
   - Di file `docker-compose.yml`, matikan service `phpmyadmin` atau hapus konfigurasi `PMA_USER` dan `PMA_PASSWORD` yang melakukan auto-login.
   - Jangan pernah mengekspos port phpMyAdmin (`8081`) ke internet publik.
4. **Batasi Akses Port Nginx**: Pastikan port Nginx (`8080`) di `docker-compose.yml` hanya mendengarkan ke localhost (`127.0.0.1:8080:80`) jika Anda menggunakan Cloudflare Tunnel atau reverse proxy terpisah untuk menangani lalu lintas HTTPS eksternal.
5. **Ganti Kredensial Default**: Segera ganti password default admin (`admin123`) di menu administrator setelah login pertama kali.
6. **Set Environment**: Pastikan `CI_ENVIRONMENT` pada `src/.env` diatur ke `production` agar fitur debugging dinonaktifkan.

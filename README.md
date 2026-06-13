# Sistem Ujian (CBT)

Aplikasi Computer-Based Test (CBT) menggunakan **CodeIgniter 4 (PHP 8.5)**, dirancang untuk skalabilitas tinggi dengan **Docker**, **Nginx**, **MariaDB**, dan **Redis**.

Aplikasi ini menggunakan teknologi **WebSocket (ReactPHP/Ratchet)** untuk deteksi kecurangan dan manajemen sesi secara *real-time* (seperti Ban, Kick, Tambah Waktu, dan Sinkronisasi Mode Statis) guna menghindari habisnya *PHP-FPM worker* pada saat beban tinggi.

## 🚀 Fitur Utama
- **Ujian Berbasis Waktu**: Ujian dengan batas waktu yang bisa ditambah oleh admin secara *real-time*.
- **Anti-Cheat Terintegrasi**: Peringatan otomatis ketika siswa keluar dari mode *fullscreen* atau beralih tab.
- **Static Exam Generator**: Menghasilkan file statis HTML yang membuat ujian tahan terhadap lonjakan akses ribuan peserta sekaligus.
- **WebSocket Daemon**: Daemon independen dengan single-thread ReactPHP & Redis Pub/Sub untuk komunikasi server-ke-klien dengan CPU dan Memory footprint yang sangat kecil.
- **Cloudflare Tunnel Ready**: Konfigurasi telah disesuaikan agar berjalan lancar di belakang proksi dan Cloudflare.

## 🛠 Instalasi & Menjalankan Aplikasi

Aplikasi ini sepenuhnya berjalan di dalam Docker. Semua perintah dieksekusi menggunakan *wrapper* script `./scripts/cmd.sh`.

### 1. Persiapan Environment
```bash
cp src/env src/.env
# Sesuaikan app.baseURL dan database di src/.env
```

Jika menggunakan Cloudflare Tunnel:
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

### 3. Migrasi Database & Composer
Semua perintah CLI dapat dijalankan melalui script utilitas yang disediakan:
```bash
./scripts/cmd.sh composer install          # Instalasi dependency
./scripts/cmd.sh php spark migrate         # Migrasi database
./scripts/cmd.sh php spark db:seed MainSeeder # (Opsional) Seeder data awal
```

## 🐛 Pemecahan Masalah (Troubleshooting)

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

### 6. Error Permission / Gagal Generate Static Exam
- **Gejala**: Muncul pesan error tidak dapat menulis file HTML saat melakukan *Generate* halaman statis, atau gambar/file tidak bisa diunggah.
- **Solusi**: Pastikan folder `src/writable/` dan `src/public/static/` memiliki izin tulis (write access). Jika folder belum ada, buat terlebih dahulu:
  ```bash
  mkdir -p src/writable src/public/static
  chmod -R 777 src/writable src/public/static
  ```

### 7. PHP-FPM Resource Exhausted / Server Terasa Lambat
- **Solusi**: Jika Anda baru saja beralih dari versi lawas (yang masih menggunakan Server-Sent Events / EventSource), pastikan Anda telah sepenuhnya mendeploy Daemon WebSocket. Cara termudahnya adalah periksa *Network tab* di *DevTools* Browser. Anda harusnya melihat aktivitas ke `wss://domain.com/ws/` dengan status kode `101 Switching Protocols`, bukan request yang terus-menerus "*Pending*" (itu adalah sisa SSE FPM).

## 🗂 Struktur Repositori
- `src/` - Kode aplikasi CodeIgniter 4
- `docker/` - Dockerfiles (Nginx, PHP-FPM, MariaDB init scripts)
- `scripts/` - Script eksekusi Docker `cmd.sh` dan utilitas lainnya
- `docker-compose.yml` - Orkestrasi Container

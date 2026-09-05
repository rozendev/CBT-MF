# Deploy ke Railway (lingkungan uji)

Panduan menaikkan CBT-MF ke Railway untuk keperluan **pengujian**: mencoba fitur
di lingkungan yang bisa dibuka dari mana saja, tanpa menyiapkan VPS.

> **Bukan untuk produksi.** php-fpm dijalankan sebagai proses latar tanpa
> supervisor, seluruh peran ditumpuk dalam satu container, dan seeder membuat
> akun dengan kata sandi yang sudah diketahui umum. Untuk pemasangan sungguhan,
> pakai `docker-compose.yml` di VPS (lihat `README.md`) atau `CPANEL.md`.

## Kenapa docker-compose.yml tidak bisa dipakai langsung

Railway tidak menjalankan Compose: satu service berarti satu container, dan
tidak ada bind mount. Tiga akibatnya:

- **nginx dan php-fpm harus menyatu.** Di Compose keduanya container terpisah
  yang berbagi `./src`. Di Railway tidak ada filesystem bersama, jadi keduanya
  hidup dalam satu image — itulah `Dockerfile.railway`.
- **Kode harus masuk ke dalam image.** `docker/php-fpm/Dockerfile` sengaja tidak
  menyalin kode karena Compose me-mount `./src`. Image Railway menyalinnya dan
  menjalankan `composer install` saat build.
- **cloudflared tidak diperlukan.** Railway sudah memberi domain publik.

## Arsitektur di Railway

| Service | Sumber | Perlu untuk uji impor soal? |
|---|---|---|
| **web** | repo ini, `Dockerfile.railway` | ya |
| **MySQL** | template bawaan Railway | ya |
| **Redis** | template bawaan Railway | **ya** — driver session aplikasi ini `RedisHandler`, tanpa cadangan. Tanpa Redis, login gagal. |
| websocket | repo ini, perintah `php spark websocket:serve` | tidak — hanya untuk pemantauan ujian realtime |
| cron | repo ini, perintah `php spark finalize:expired` dkk | tidak — hanya untuk auto-finalize ujian |

## Langkah

1. **Buat project** di Railway, lalu tambahkan service **MySQL** dan **Redis**
   dari katalog template.

2. **Tambahkan service dari repo ini**, arahkan ke branch yang ingin diuji.
   Railway membaca `railway.json` dan memakai `Dockerfile.railway`.

3. **Isi variabel** pada service web. Pakai sintaks referensi Railway supaya
   nilainya ikut berubah kalau database dibuat ulang:

   ```
   DB_HOST=${{MySQL.MYSQLHOST}}
   DB_PORT=${{MySQL.MYSQLPORT}}
   DB_DATABASE=${{MySQL.MYSQLDATABASE}}
   DB_USERNAME=${{MySQL.MYSQLUSER}}
   DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

   REDIS_HOST=${{Redis.REDISHOST}}
   REDIS_PORT=${{Redis.REDISPORT}}
   REDIS_PASSWORD=${{Redis.REDISPASSWORD}}
   ```

   Nama variabel di sisi kanan mengikuti template Railway yang Anda pakai —
   pastikan di tab **Variables** service database, karena penamaannya pernah
   berbeda antar template.

4. **Terbitkan domain** lewat Settings → Networking → Generate Domain. Entrypoint
   memakai `RAILWAY_PUBLIC_DOMAIN` sebagai `app.baseURL`. Kalau memakai domain
   sendiri, setel `APP_BASE_URL` (harus berakhiran `/`).

5. **Deploy.** Saat start, entrypoint merender `src/.env`, menunggu database
   siap, lalu menjalankan migrasi.

6. **Isi data awal sekali saja**: setel `RUN_SEEDER=true`, tunggu satu deploy
   selesai, lalu **hapus variabel itu lagi**. Seeder membuat akun
   **`admin` / `admin123`** — ganti kata sandinya begitu bisa masuk.

## Variabel yang dikenali

| Variabel | Bawaan | Keterangan |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | dari `MYSQL*` | wajib |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | dari `REDIS*` | wajib |
| `APP_BASE_URL` | `https://$RAILWAY_PUBLIC_DOMAIN/` | akhiri dengan `/` |
| `CI_ENVIRONMENT` | `production` | isi `development` untuk melihat detail error |
| `RUN_MIGRATIONS` | `true` | `false` untuk melewati migrasi |
| `RUN_SEEDER` | `false` | `true` sekali saja |
| `MIGRATION_MAX_ATTEMPTS` | `10` | percobaan ulang selagi database belum siap |
| `ENCRYPTION_KEY` | kosong | isi bila memakai layanan Encryption |
| `PORT` | disuntik Railway | dipakai nginx |

## Hal yang perlu diketahui

**HTTPS.** `Config\App::$forceGlobalSecureRequests` bernilai `true`, sedangkan
`$proxyIPs` hanya mempercayai `172.16.0.0/12` — rentang bridge Docker, bukan
proxy Railway. Dibiarkan menyala, CI4 melihat permintaan sebagai HTTP dan
mengalihkannya ke HTTPS tanpa henti. Karena itu entrypoint menuliskan
`app.forceGlobalSecureRequests = false` ke `.env`. TLS tetap ditutup di edge
Railway, dan karena `baseURL` berskema `https`, flag `secure` pada cookie dan
session tetap menyala.

**Unggahan hilang tiap deploy.** Filesystem container bersifat sementara. Gambar
hasil impor Word disimpan di `public/uploads/questions/`, jadi pasang **Volume**
ke `/var/www/html/public/uploads` kalau ingin gambarnya bertahan.

**Batas ukuran unggahan.** `docker/php-fpm/security.ini` tidak menyetel
`upload_max_filesize`, sehingga bawaan PHP 2 MB berlaku — padahal aturan
aplikasi mengizinkan 5 MB. Image Railway menambahkan
`docker/railway/php-uploads.ini` yang menaikkannya ke 8 MB.

**WebSocket.** Konfigurasi nginx di sini tidak memuat proxy `/ws/`. Untuk menguji
pemantauan realtime, tambahkan service kedua dari repo yang sama dengan perintah
`php spark websocket:serve`, lalu tambahkan blok proxy yang mengarah ke alamat
internal service tersebut.

**Log.** nginx menulis ke stdout/stderr agar terbaca di panel log Railway.

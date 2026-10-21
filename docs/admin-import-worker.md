# Worker impor admin (development)

Impor siswa dan soal sekarang memakai satu antrean server. Request upload hanya
memvalidasi batas file dan menyimpan job; parsing, hashing, serta penulisan data
dijalankan PHP CLI terpisah. Browser membaca progres dengan GET setiap 2–6 detik.
Endpoint `users/import-batch` lama hanya membaca status, tidak mengeksekusi batch.

Tidak ada migrasi, tabel baru, atau perubahan model inti, algoritma hash,
komponen ujian, WebSocket, maupun konfigurasi Redis. Impor tetap menulis data ke
tabel yang sudah ada sesuai fungsinya.

## Menjalankan di dev

```bash
docker compose build import-worker
docker compose up -d --no-deps import-worker
docker compose logs -f import-worker
```

Worker memiliki batas default 0,5 core CPU, RAM 256 MiB, tanpa swap tambahan,
32 PID/thread, dan PHP memory_limit 224 MiB. Ubah `IMPORT_WORKER_CPUS` dan
`IMPORT_WORKER_MEMORY` pada environment Compose bila pengujian membutuhkan
budget berbeda. Worker berjalan sebagai UID/GID 33:33; semua instance harus
berbagi filesystem `src/writable/` yang sama. `flock` memastikan hanya satu job
diproses secara global, meski beberapa worker atau tab admin dijalankan.
Supervisor menjalankan satu proses PHP baru per job. Koneksi database dan
alokasi parser dari job sebelumnya tidak terbawa ke job berikutnya. Perintah
`spark imports:work --once` memproses paling banyak satu job siap jalan.

Antrean privat berada di `writable/imports/`, di luar document root. Direktori
bermode 0700; file input dan metadata 0600. `IMPORT_QUEUE_PATH` dapat mengganti
lokasinya, tetapi web dan worker harus menggunakan lokasi yang sama. Deployment
multi-host memerlukan storage/antrean bersama dan berada di luar cakupan ini.

## Batas dan pemulihan

- Maksimal 10 job belum selesai, mencakup kedua jenis impor. Antrean penuh
  membalas 429 dengan `Retry-After`, tanpa memulai pemrosesan.
- Excel: maksimal 10 MiB, 10.000 baris data, dan 100 kolom per lembar. Nilai
  terformat dan formula tetap dibaca seperti alur sebelumnya. Word: maksimal
  5 MiB. Arsip Office dibatasi 64 MiB setelah diekstrak dan 4.000 entry.
- Password di-hash memakai model yang sama, sebelum transaksi penulisan siswa.
  Transaksi tetap singkat. Ada jeda 50 ms antarbaris di luar transaksi.
- File-backed checkpoint menyimpan posisi baris dan progres. Job yang terhenti
  dicoba ulang maksimal tiga kali setelah percobaan awal, dengan jeda
  eksponensial dan jitter deterministik. Error validasi tidak diulang.
- Penulisan soal tetap atomik. Receipt privat disimpan sebelum commit agar
  replay dapat mengenali hasil yang sudah tersimpan, termasuk soal gambar.
  Status transaksi direset pada batas pekerjaan baru setelah error SQL.
- ID submission disimpan di browser sebelum POST. Bila respons hilang, browser
  dapat mencari job memakai ID tersebut tanpa upload ulang. Setiap submission
  memiliki record tersendiri, sehingga tab lain tidak menghapus progresnya.
- Status dan lookup dibatasi pemilik job, memakai rute admin/guru yang ada.
  Status tidak mengirim file, password, path internal, atau receipt privat.
- Input dihapus setelah selesai/gagal permanen; metadata disimpan maksimal
  24 jam. Upload/temp file yang tertinggal akibat crash juga dibersihkan setelah
  24 jam. Metadata rusak dikarantina agar tidak menghalangi job sehat.

Tidak ada transaksi atomik lintas database dan filesystem. Jika worker terhenti
tepat setelah commit siswa tetapi sebelum checkpoint, baris terakhir dapat
terbaca sebagai duplikat pada replay; data tidak digandakan, tetapi hitungan
berhasil/duplikat dapat berbeda satu baris. File spool harus dipertahankan agar
job dapat dipulihkan. Kehilangan seluruh disk spool tidak ditangani oleh ini.

## Pengujian

```bash
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T php vendor/bin/phpunit -c phpunit.import.xml.dist
docker compose exec -T php vendor/bin/phpunit -c phpunit.throttling.xml.dist
node --test --test-isolation=none src/tests/Import/admin-import.test.cjs
docker compose run --rm --no-deps -e IMPORT_QUEUE_PATH=/tmp/import-smoke import-worker php -d memory_limit=224M spark imports:work --once
```

Suite integrasi memakai skema dev yang sudah ada; setiap fixture dan perubahan
datanya berada dalam transaksi yang di-rollback, tanpa menjalankan migrasi.
`tests/Import/health-smoke.php` membaca 20 request `/health` untuk membandingkan
respons PHP sebelum/selama worker diuji. Ini smoke test layanan, bukan benchmark
latensi autosave ujian penuh. Skenario k6 ujian + impor simultan tetap diperlukan
untuk menentukan kapasitas jumlah siswa dan budget worker pada mesin tujuan.

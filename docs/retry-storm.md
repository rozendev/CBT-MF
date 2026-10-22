# Perlindungan retry storm pada ujian

Request ujian memakai satu request awal dan maksimal **3 retry**. Jeda dasar
bertambah eksponensial dengan jitter deterministik berdasarkan ID attempt,
operasi/soal, dan nomor retry: sekitar 1–2, 2–4, lalu 4–8 detik. Siswa yang
terputus bersamaan mendapat jadwal berbeda tanpa `Math.random()`.

Retry berlaku untuk kegagalan jaringan, timeout, HTTP 408, 429, dan 5xx.
Kesalahan permanen dan penolakan aplikasi tidak diulang. Respons HTTP 200 baru
dianggap menyimpan jawaban bila berisi `status: success`.

Untuk HTTP 429, klien menghormati `Retry-After` (detik atau HTTP-date) dan
menambahkan jitter. Header yang hilang/tidak valid memakai jeda 10 detik.
Cooldown juga berlaku bagi operasi berikutnya sehingga antrean tidak langsung
menghabiskan kuota lagi. Server tetap membatasi 30 request per 10 detik per
siswa; siswa yang berbagi IP tetap mempunyai kuota sendiri, termasuk pada
jalur cache cadangan saat Redis bermasalah. Jendela cadangan tidak diperpanjang
oleh request yang diblokir. Header waktu tunggu dapat dibaca WebView melalui CORS.

Autosave berjalan berurutan. Setelah retry habis, pengiriman otomatis berhenti,
ujian dibekukan, jawaban tetap dipertahankan, dan penyelesaian ujian ditahan.
Tombol **Coba Simpan Lagi** memulai pemulihan manual dengan batas retry yang
sama. Klik berulang tidak membuat pemulihan paralel. Semua jawaban tertunda
harus berhasil tersimpan sebelum ujian dilanjutkan. Tidak ada loop storage
kedua yang memulai ulang anggaran retry secara otomatis.

Kebijakan berlaku pada halaman dinamis, engine statis/kiosk, dan renderer
cadangan tanpa Alpine/jQuery. WebSocket mencoba reconnect maksimal tiga kali
berturut-turut dengan jitter. Shim kiosk menerapkan timeout dan mempertahankan
status HTTP/header bahkan bila server mengembalikan HTML.

## Fallback WebSocket

Halaman dinamis dan engine statis/kiosk otomatis beralih ke polling status
ketika koneksi WebSocket error/tertutup, konfigurasi tidak tersedia, handshake
melewati 15 detik (timeout request), atau heartbeat tidak diterima selama
45 detik. Pada halaman aktif, poll pertama diberi variasi deterministik 0–1 detik;
setelah itu berjeda 10–11 detik setelah respons selesai, tanpa request paralel.
Halaman terlihat yang idle selama dua menit melambat menjadi 30–31 detik,
tab tersembunyi 180–210 detik, dan halaman ditutup berhenti polling.
Autosave tetap dipicu perubahan jawaban; sync database hanya dilakukan setelah
perubahan tersimpan. Lihat [jadwal per tugas dan lifecycle](activity-polling.md).

GET `/api/exam/status/{attempt_id}` hanya membaca satu snapshot milik siswa:
status akun/attempt, deadline, dan mode ujian. Endpoint tidak memuat soal,
tidak menyimpan jawaban, dan tidak memicu flush Redis ke database. Status
dikeluarkan tetap mempertahankan layar terkunci pada kiosk. Tambahan waktu
memakai deadline server sehingga tidak menambahkan durasi penuh dari waktu
polling. Respons bersifat privat dan tidak boleh dicache.

WebSocket tetap mencoba reconnect maksimal tiga kali. Setelah server mengirim
acknowledgement `connected` untuk attempt yang benar, polling berhenti otomatis.
Transport yang terbuka lalu ditolak saat validasi token tidak mereset anggaran
retry. Error HTTP sementara pada polling mendapat maksimal
tiga retry dengan jeda deterministik dan `Retry-After`; setelah habis polling
berhenti agar tidak menambah beban. Pemulihan WebSocket yang berhasil membuka
anggaran baru; kegagalan HTTP tidak diulang tanpa batas.

## Verifikasi

```bash
node src/tests/Resilience/exam-retry.test.cjs
node src/tests/Resilience/fallback-retry.test.cjs
node src/tests/Resilience/kiosk-ajax.test.cjs
node src/tests/Resilience/websocket-polling.test.cjs
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T php vendor/bin/phpunit -c phpunit.throttling.xml.dist
docker compose exec -T php vendor/bin/phpunit -c phpunit.realtime.xml.dist
```

Setelah deploy, bangun ulang bundle kiosk dengan
`docker compose exec -T php php spark cbt:build-ui-bundle`. Halaman ujian statis
yang sudah digenerate harus digenerate ulang lewat panel admin agar renderer
cadangan dan tombol pemulihan memakai template terbaru. Lakukan sebelum ujian
berlangsung karena regenerasi membuat snapshot soal baru.

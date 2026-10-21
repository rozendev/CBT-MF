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

## Verifikasi

```bash
node src/tests/Resilience/exam-retry.test.cjs
node src/tests/Resilience/fallback-retry.test.cjs
node src/tests/Resilience/kiosk-ajax.test.cjs
docker compose exec -T php vendor/bin/phpunit
docker compose exec -T php vendor/bin/phpunit -c phpunit.throttling.xml.dist
```

Setelah deploy, bangun ulang bundle kiosk dengan
`docker compose exec -T php php spark cbt:build-ui-bundle`. Halaman ujian statis
yang sudah digenerate harus digenerate ulang lewat panel admin agar renderer
cadangan dan tombol pemulihan memakai template terbaru. Lakukan sebelum ujian
berlangsung karena regenerasi membuat snapshot soal baru.

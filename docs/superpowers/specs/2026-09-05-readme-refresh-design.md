# Pembaruan README

Tanggal: 2026-09-05

## Masalah

`README.md` menyimpang jauh dari keadaan repositori. Penyimpangannya bukan
kosmetik: seorang pemasang baru yang mengikutinya akan tersesat.

- Seluruh contoh perintah memakai `./scripts/cmd.sh`. Berkas itu tidak ada.
  Entry point yang sebenarnya adalah `scripts/cbt.sh`.
- README mendokumentasikan phpMyAdmin di port 8081. Service itu sudah tidak
  ada di `docker-compose.yml`, termasuk saran keamanan produksi tentangnya.
- README menyebut MariaDB di `localhost:3306` dan Redis di `localhost:6379`.
  Baris `ports:` kedua service itu dikomentari; keduanya tidak dipublikasikan
  ke host. WebSocket terikat `127.0.0.1:8060`, bukan terbuka.
- Saran keamanan menyuruh menghapus `src/public/install/` dan
  `src/test_api.php`. Keduanya sudah tidak ada di repositori.
- Saran keamanan menyuruh mengganti password admin bawaan `admin123`.
  `scripts/cbt.sh install` meminta password admin dari pemasang; tidak ada
  password bawaan pada jalur itu.
- Fitur yang sudah ada tapi tidak disebut sama sekali: EXAMBRO (aplikasi
  kiosk Android di `cbt-kiosk-app/`), live proctoring, import soal dari Word,
  deteksi intruder, analytics, dwibahasa ID/EN, throttling login per-IP,
  antrean slot, service `cron` dan `cloudflared`.
- Tidak ada satu pun tangkapan layar, padahal nilai jual sistem ini visual.

## Keputusan

**Pembaca.** Operator sekolah lebih dulu, developer menyusul di bagian bawah
README yang sama. Satu berkas mengalir, tanpa duplikasi: operator berhenti
membaca di pertengahan dan sudah memperoleh semua yang dibutuhkannya.

**Struktur.** Etalase dulu, mesin belakangan. Ditolak: pemisahan tegas
"Untuk Operator" / "Untuk Developer" (membuat instalasi muncul dua kali) dan
README ramping dengan semua detail dipindah ke `docs/` (terlalu tipis untuk
developer, padahal developer termasuk pembaca sasaran).

**Tangkapan layar.** Satu hero (Dashboard admin) lebar penuh, lalu galeri
tabel dua kolom berisi enam tampilan. Sepuluh berkas sisanya tidak dipakai.

**Troubleshooting.** Tiga kasus yang paling sering menimpa pemasang baru
tetap di README; sisanya pindah ke `Troubleshooting.md`.

**Bagian opsional makeareadme.com.** Dipakai: badge, berkontribusi, dukungan,
status proyek. Tidak dipakai: roadmap (belum ada rencana yang diputuskan).

**Tanpa emoji** di sepanjang README.

**`install.sh`.** Dibiarkan apa adanya dan tidak disinggung di README.
Berkas itu rusak — pada langkah `[3/4]` ia memanggil `./scripts/cmd.sh up`
yang tidak ada — dan isinya usang (phpMyAdmin, `admin123`). Memperbaiki atau
menghapusnya di luar lingkup pekerjaan ini. README hanya mendokumentasikan
`scripts/cbt.sh install`.

## Rancangan

### Berkas yang disentuh

| Berkas | Tindakan |
| --- | --- |
| `README.md` | Ditulis ulang |
| `Troubleshooting.md` | Ditambah bagian baru "Masalah Umum & Solusi" |
| `docs/images/*.png` | Tujuh berkas baru |

`Troubleshooting.md` berformat log insiden bertanggal. Kasus yang dipindah
dari README berformat panduan how-to, jadi ia masuk sebagai bagian baru yang
terpisah, bukan dicampur ke dalam log.

### Tangkapan layar

Disalin dari `/home/rozen/Pictures/Screenshots/Preview/` ke `docs/images/`
dengan nama deskriptif.

| Berkas asal | Nama baru | Peran |
| --- | --- | --- |
| `Screenshot_20260905_103749.png` | `admin-dashboard.png` | Hero |
| `Screenshot_20260905_103804.png` | `daftar-ujian.png` | Galeri |
| `Screenshot_20260905_103910.png` | `import-word.png` | Galeri |
| `Screenshot_20260905_103945.png` | `akses-siswa.png` | Galeri |
| `Screenshot_20260905_103954.png` | `exambro.png` | Galeri |
| `Screenshot_20260905_104138.png` | `ujian-desktop.png` | Galeri |
| `Screenshot_20260905_104214.png` | `ujian-mobile.png` | Galeri |

Enam berkas galeri dipilih agar tiap gambar menunjukkan hal yang berbeda:
manajemen ujian, import soal, kontrol pengawas, anti-cheat kiosk, sisi siswa
desktop, sisi siswa ponsel.

### Kerangka README

1. **Judul dan badge** — `# CBT-MF`, badge statis shields.io: PHP 8.2+,
   CodeIgniter 4.7, Docker, GPL-3.0. Tanpa badge CI atau coverage karena
   repositori ini belum punya workflow GitHub Actions.
2. **Deskripsi** — satu paragraf yang menjawab "ini apa dan kenapa berbeda":
   ujian berbasis komputer untuk sekolah dan madrasah, dengan daemon
   WebSocket terpisah agar peserta serentak tidak menghabiskan worker
   PHP-FPM, dan mode ujian statis yang tahan lonjakan akses.
3. **Hero** — `docs/images/admin-dashboard.png` lebar penuh.
4. **Fitur Utama** — delapan poin, tiap poin satu frasa tebal dan satu
   kalimat penjelas: bank soal enam tipe; import dari Word; mode ujian
   statis; live proctoring dan kontrol sesi real-time; EXAMBRO; deteksi
   kecurangan browser; antrean slot dan throttling login ramah CGNAT; audit
   trail, analytics, dan export `.xls`.
5. **Tampilan** — galeri tabel dua kolom, enam gambar berketerangan.
6. **Instalasi** — prasyarat (Docker dan Compose V2, host Linux, akses root
   karena installer melakukan `chown` ke GID 33), tiga baris clone dan
   `sudo bash scripts/cbt.sh install`, daftar yang ditanyakan installer, dan
   pernyataan bahwa installer sekalian menyalakan container, membetulkan
   permission, `composer install`, migrasi, serta membuat akun admin.
   Tabel layanan menyatakan dengan tepat mana yang terekspos: Nginx `8080`
   ke host; WebSocket `127.0.0.1:8060` dan diproksi Nginx lewat `/ws/`;
   MariaDB dan Redis hanya di dalam jaringan Docker.
7. **Penggunaan** — `scripts/cbt.sh` tanpa argumen membuka menu interaktif,
   atau dipanggil langsung sebagai subperintah. Tabel delapan grup (`docker`,
   `app`, `db`, `redis`, `bundle`, `data`, `migrate`, `tune`) dan perintah
   tingkat atas (`backup`, `log-rotate`, `reset-install`, `test-k6`, `help`),
   dengan catatan bahwa perintah perusak data menuntut pengetikan ulang nama
   perintah. Lima contoh harian, termasuk pengingat bahwa bundle UI kiosk
   wajib dibangun ulang setelah UI ujian berubah — kalau tidak, perangkat
   menerima artefak basi tanpa pesan galat.
8. **Arsitektur** — diagram Mermaid (Browser dan EXAMBRO ke Nginx; Nginx ke
   PHP-FPM dan ke daemon WebSocket; Redis untuk sesi, cache, autosave, dan
   Pub/Sub; MariaDB untuk persistensi), lalu tiga keputusan desain beserta
   alasannya, lalu daftar tujuh service compose termasuk `cron` dan
   `cloudflared`.
9. **Struktur repositori** — `src/`, `docker/`, `scripts/`,
   `cbt-kiosk-app/`, `docs/`, `docker-compose.yml`.
10. **Keamanan produksi** — empat poin yang masih faktual: `INTRUDER_TOKEN`
    diisi `openssl rand -hex 32` (kalau kosong, semua pemasangan berbagi
    token yang tertulis di dalam source), `KIOSK_APP_SECRET`,
    `CI_ENVIRONMENT=production`, dan mengikat port 8080 ke `127.0.0.1` bila
    berada di belakang Tunnel atau reverse proxy.
11. **Pemecahan masalah** — tiga kasus dengan tautan ke `Troubleshooting.md`.
12. **Berkontribusi** — `composer test` untuk suite utama, dan
    `phpunit -c phpunit.throttling.xml.dist` terpisah untuk suite Throttling
    yang memakai `tests/bootstrap_ci.php`; alur branch dan PR; pengingat
    rebuild bundle.
13. **Dukungan** — GitHub Issues.
14. **Status proyek** — aktif dikembangkan, versi aplikasi 1.30.
15. **Lisensi** — GPL-3.0, menunjuk ke `LICENSE`.

### Bagian baru di `Troubleshooting.md`

Judul "Masalah Umum & Solusi", ditempatkan sebelum log bertanggal, memuat
lima kasus yang dipindah dari README: paket Composer tidak ditemukan;
WebSocket diputus periodik oleh Cloudflare; autosave gagal karena Redis mati;
sesi cepat berakhir karena session handler bukan Redis; dan PHP-FPM kehabisan
sumber daya karena sisa Server-Sent Events. Semua rujukan `./scripts/cmd.sh`
diganti `scripts/cbt.sh`.

## Yang sengaja tidak dilakukan

- `install.sh` tidak diperbaiki, tidak dihapus, dan tidak disebut.
- Tidak ada bagian Roadmap.
- Tidak ada badge CI atau coverage.
- README tetap berbahasa Indonesia.

## Verifikasi

- Setiap perintah yang ditulis di README ada di `scripts/cbt.sh` — dicocokkan
  dengan senarai `reg` pada berkas itu.
- Setiap port yang disebut dicocokkan dengan `docker-compose.yml`.
- Setiap berkas dan direktori yang disebut benar-benar ada.
- Setiap tautan gambar menunjuk berkas yang ada di `docs/images/`.
- README dirender dan dibaca ulang sebelum di-commit.

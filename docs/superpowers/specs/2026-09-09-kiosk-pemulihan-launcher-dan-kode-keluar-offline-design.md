---
title: Pemulihan Home Launcher & Kode Keluar Offline untuk Kiosk CBT-MF
date: 2026-09-09
status: draft
approach: Prompt pemulihan launcher + kode harian turunan password dengan amplop hash lambat
target: Android 9+ (API 28+), BYOD; server CodeIgniter 4
tech: Kotlin (app), PHP 8 (server)
---

# Pemulihan Home Launcher & Kode Keluar Offline

## 1. Overview & Goals

### 1.1 Deskripsi Singkat

Dokumen ini mendesain dua penambahan pada jalur **keluar** dari kiosk CBT-MF yang
saling melengkapi:

1. **Prompt pemulihan Home launcher** — mengembalikan layar utama perangkat siswa
   sesudah kiosk dilepas.
2. **Kode keluar offline harian** — jalan keluar sah saat server tidak terjangkau.

### 1.2 Latar Belakang

Jalur keluar kiosk saat ini punya dua lubang yang keduanya baru terasa persis pada
saat paling buruk: ujian sudah selesai, atau server sedang mati.

**Lubang pertama — launcher tidak pernah dikembalikan.** `KioskManager.stopKiosk()`
hanya memanggil `stopLockTask()` lalu menampilkan layar setup. Aplikasi tetap
terdaftar `CATEGORY_HOME` (`AndroidManifest.xml:29`) dan tetap menjadi Home app
pilihan siswa, sehingga menekan tombol Home mengembalikan siswa ke aplikasi ujian.
Spesifikasi awal sebenarnya mensyaratkan perilaku ini
(`docs/superpowers/specs/2026-08-12-android-kiosk-webview-design.md:130`: "*Saat
ujian selesai (`stopKiosk()`), aplikasi menampilkan panduan prompt untuk
mengembalikan Home Launcher bawaan siswa*") tetapi tidak pernah diimplementasi —
tidak ada satu pun rujukan `RoleManager` atau `ACTION_HOME_SETTINGS` di seluruh
kode aplikasi. Akibatnya siswa pulang dengan HP yang masih "disandera", dan
pemulihannya menjadi pekerjaan manual operator satu per satu.

Sisi server malah sudah siap: setting `kiosk_enforce_home_launcher` ada di panel
admin dan sudah dikirim di `/api/kiosk/config` (`KioskController.php:58`), tetapi
aplikasi tidak pernah membacanya. Dari seluruh blok `features`, hanya
`siren_enabled` yang benar-benar dikonsumsi (`MainActivity.kt:840`).

**Lubang kedua — keluar mustahil tanpa server.** Kedua jalur keluar yang ada
(`/api/kiosk/verify-exit` untuk password pengawas, `/api/kiosk/can-exit` untuk
ujian selesai) menuntut server terjangkau. Ketika jaringan putus,
`verifyExitPassword` menangkap exception dan mengembalikan `allowed = false`
(`MainActivity.kt:412`). Tidak ada fallback offline. Yang tersisa hanyalah melepas
screen pinning secara paksa (tahan Back + Recents), yang memicu sirene
`KioskGuardService` dan tercatat sebagai pelolosan — perlakuan yang salah untuk
pengawas yang sedang menjalankan tugasnya.

### 1.3 Sasaran

1. Sesudah kiosk dilepas secara sah, siswa dituntun mengembalikan Home launcher
   bawaan tanpa perlu bantuan operator.
2. Pengawas dapat membuka kiosk saat server tidak terjangkau, memakai kode yang
   diperolehnya sebelum ujian, tanpa memicu sirene atau tercatat sebagai
   pelolosan.
3. Setiap pemakaian jalur offline meninggalkan jejak yang sampai ke server begitu
   jaringan pulih.
4. Sekolah yang tidak membutuhkan jalur offline tidak menanggung risikonya.

### 1.4 Non-Goals

- Tidak mengubah jalur keluar normal (`verify-exit` / `can-exit`).
- Tidak menjadikan aplikasi Device Owner. Screen pinning tetap tidak terkelola,
  dan tetap bisa dilepas paksa dengan Back + Recents.
- Tidak menghapus pendaftaran `CATEGORY_HOME`. Pengambilalihan launcher tetap
  menjadi lapis pertahanan; yang ditambahkan hanya jalan pulangnya.

---

## 2. Keputusan Desain

Keputusan berikut diambil bersama pemilik produk pada sesi brainstorming
2026-09-09. Dicatat lengkap dengan alasannya supaya pembaca berikutnya tidak
membongkar ulang pertimbangan yang sama.

| # | Keputusan | Pilihan | Alasan |
|---|-----------|---------|--------|
| 1 | Bentuk kredensial offline | Kode harian yang berubah tiap hari | Operasional paling ringan untuk sekolah; kebocoran terbatas pada satu hari |
| 2 | Cakupan kode | Global per sekolah | Pengawas cukup memegang satu angka; tidak perlu mencocokkan HP dengan daftar device |
| 3 | Kapan kode diterima | Selalu, sejajar password | Pengawas yang panik tidak perlu memicu kondisi tersembunyi lebih dulu |
| 4 | Sumber turunan kode | `kiosk_exit_password` | Tidak ada rahasia baru yang harus didistribusikan atau dirotasi |
| 5 | Syarat kekuatan password | Peringatan, tidak memblokir | Sekolah yang paham risikonya tetap bisa jalan |
| 6 | Pengiriman ke device | Amplop hash lambat (lihat §4.2) | Konsekuensi turunan dari #4; **belum ditinjau pemilik produk** |

Keputusan #6 tidak dibahas pada sesi brainstorming dan diputuskan saat penulisan
spec ini. Rasionalnya ada di §4.2. Ini titik pertama yang perlu ditinjau.

### 2.1 Risiko yang Diterima Secara Sadar

Keputusan #3 dan #5 sama-sama melonggarkan keamanan demi kemudahan operasional.
Konsekuensinya dicatat di sini supaya tidak hilang:

- **Kode offline setara password pengawas dan berlaku walau server sehat.** Ia
  bukan sekadar jaring pengaman; ia jalur kedua yang penuh. Mitigasinya adalah
  audit (§4.6) dan toggle mati-secara-default (§4.8), bukan pembatasan kapan ia
  boleh dipakai.
- **Kekuatan skema sepenuhnya bertumpu pada entropi `kiosk_exit_password`.**
  Algoritmanya ada di dalam APK yang bisa dibongkar siapa pun. Dengan password
  default `123456` (`KioskController.php:140`), siswa yang membongkar APK dan
  amplopnya dapat memulihkan password melalui serangan kamus, lalu menghitung
  kode untuk tanggal berapa pun. Amplop PBKDF2 memperlambat serangan ini secara
  signifikan (§4.2) tetapi tidak menghapusnya. Peringatan admin (§4.7) adalah
  satu-satunya penghalang, dan ia bisa diabaikan.

---

## 3. Bagian A — Prompt Pemulihan Home Launcher

### 3.1 Ringkas

Tidak ada perubahan server. Seluruh pekerjaan ada di aplikasi: membaca setting
yang sudah lama dikirim, mendeteksi apakah aplikasi masih menjadi Home app, dan
menuntun pengguna ke layar pengaturan yang tepat.

### 3.2 Konsumsi Setting

`fetchServerKioskConfig` (`MainActivity.kt:837`) ikut membaca
`features.enforce_home_launcher` dan menyimpannya ke prefs `cbt_kiosk_prefs`
dengan kunci `kiosk_enforce_home_launcher`, mengikuti pola yang sudah dipakai
`siren_enabled` (`MainActivity.kt:840-864`). Nilai tersimpan dipakai saat config
belum sempat diambil. Default bila tidak ada: `true`, selaras dengan default
server.

### 3.3 Deteksi Home App

```kotlin
fun resolveHomePackage(pm: PackageManager): String? {
    val intent = Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_HOME)
    return pm.resolveActivity(intent, PackageManager.MATCH_DEFAULT_ONLY)
        ?.activityInfo?.packageName
}

// Fungsi murni, dapat diuji tanpa perangkat:
fun isSelfTheHomeApp(resolved: String?, own: String): Boolean =
    resolved != null && resolved == own
```

Pemisahan menjadi fungsi murni disengaja: `PackageManager` tidak dapat dipakai di
unit test JVM, sementara logika perbandingannya justru bagian yang perlu diuji
(termasuk kasus `resolved == null` saat sistem menampilkan pemilih launcher dan
belum ada yang dipilih sebagai default).

### 3.4 Prompt

`promptRestoreHomeLauncher()` dipanggil di dua tempat:

1. Sesudah `stopKiosk()` berhasil pada **kedua** jalur keluar — dialog password
   (`MainActivity.kt:318`) dan `handleKioskExitRequest` (`MainActivity.kt:441`).
2. Setiap kali `showSetupScreen()` (`MainActivity.kt:914`) menampilkan layar
   setup.

Prompt dilewati diam-diam bila salah satu benar:

- `kiosk_enforce_home_launcher` bernilai `false`; atau
- aplikasi bukan Home app terpilih — tidak ada yang perlu dikembalikan.

Karena syarat kedua dievaluasi ulang tiap kali, dialog berhenti muncul dengan
sendirinya begitu launcher dikembalikan. Tidak diperlukan flag "sudah pernah
ditampilkan" yang bisa basi atau salah-tebak.

Dialog berisi dua tombol: **Buka Pengaturan** dan **Nanti**. Memilih "Nanti" tidak
menekan prompt selamanya — ia akan muncul lagi di layar setup berikutnya. Ini
disengaja: perangkat yang masih tersandera adalah kondisi yang memang harus
mengganggu sampai dibereskan.

### 3.5 Membuka Layar Pengaturan

ROM Android sangat beragam dalam menyediakan layar pemilihan Home app. Intent
dicoba berjenjang, masing-masing dibungkus `try`/`catch ActivityNotFoundException`:

1. `Settings.ACTION_HOME_SETTINGS`
2. `Settings.ACTION_MANAGE_DEFAULT_APPS_SETTINGS`
3. `Settings.ACTION_SETTINGS`

Bila ketiganya gagal, dialog menampilkan instruksi manual sebagai teks (jalur
Settings → Apps → Default apps → Home app) alih-alih gagal senyap.

### 3.6 String Baru

`app/src/main/res/values/strings.xml`:

| Kunci | Isi (ringkas) |
|-------|---------------|
| `restore_home_title` | Kembalikan Layar Utama |
| `restore_home_message` | Perangkat ini masih memakai aplikasi ujian sebagai layar utama. Buka pengaturan untuk mengembalikan launcher bawaan. |
| `restore_home_open` | Buka Pengaturan |
| `restore_home_later` | Nanti |
| `restore_home_manual` | Buka Pengaturan → Aplikasi → Aplikasi default → Aplikasi Beranda, lalu pilih launcher bawaan. |

---

## 4. Bagian B — Kode Keluar Offline Harian

### 4.1 Algoritma Kode

Kode dihasilkan **hanya di server**; perangkat tidak pernah menghitungnya sendiri,
melainkan mencocokkan masukan dengan amplop (§4.2). Spesifikasinya tetap dikunci
di sini karena kode yang tampil di dashboard dan hash di dalam amplop harus lahir
dari perhitungan yang sama persis — kalau tidak, kode yang dibacakan pengawas tidak
akan pernah diterima perangkat.

```
tanggal = tanggal hari itu di zona Asia/Jakarta, format YYYY-MM-DD
pesan   = "cbtmf-offline-exit:" + tanggal
mac     = HMAC-SHA256(key = UTF-8(kiosk_exit_password), msg = UTF-8(pesan))   // 32 byte

offset  = mac[31] & 0x0F                                  // truncation dinamis RFC 4226
binary  = ((mac[offset]     & 0x7F) << 24)
        | ((mac[offset + 1] & 0xFF) << 16)
        | ((mac[offset + 2] & 0xFF) <<  8)
        |  (mac[offset + 3] & 0xFF)

kode    = (binary mod 100000000), diisi nol di depan hingga 8 digit
```

`offset` bernilai maksimum 15, sehingga `offset + 3` maksimum 18 — selalu di dalam
32 byte. Truncation dinamis dipakai alih-alih "ambil 4 byte pertama" karena ia
standar, sudah teruji luas, dan menghilangkan pertanyaan byte mana yang diambil.

**Zona waktu.** Tanggal dihitung di `Asia/Jakarta` di kedua sisi, **bukan** zona
lokal perangkat. Kalau memakai zona perangkat, siswa cukup mengubah setelan zona
untuk menggeser kode yang berlaku. Aplikasi tetap membaca jam sistem perangkat,
hanya interpretasi zonanya yang dipaku.

### 4.2 Amplop Hash di Perangkat

Keputusan #4 menetapkan kode diturunkan dari `kiosk_exit_password`. Konsekuensi
yang tidak sempat dibahas: **untuk menghitung kodenya sendiri, perangkat harus
memegang passwordnya.**

Mengirim password ke perangkat membatalkan properti yang dijaga eksplisit di
`KioskController.php:87` ("*Password NEVER leaves the device as a deliverable: it
is only sent here from the native app when the proctor types it*"). Satu HP yang
dibongkar tidak lagi membocorkan kode satu hari, melainkan password pengawas itu
sendiri — dan dengannya seluruh kode di masa depan sekaligus jalur keluar normal.

Karena itu perangkat tidak menerima password maupun kodenya, melainkan **amplop
berisi hash lambat dari kode 7 hari ke depan**:

```json
"offline_exit": {
  "enabled": true,
  "iterations": 120000,
  "days": [
    { "day": "2026-09-09", "salt": "<32 hex>", "hash": "<64 hex>" },
    { "day": "2026-09-10", "salt": "<32 hex>", "hash": "<64 hex>" }
  ]
}
```

dengan `hash = PBKDF2-HMAC-SHA256(password = kode 8 digit, salt = salt, c =
iterations, dkLen = 32)`. Salt diacak per hari per pembangkitan.

Verifikasi di perangkat: untuk masukan `X` dan hari kandidat `d`, hitung
`PBKDF2(X, salt_d, iterations)` lalu bandingkan dengan `hash_d` secara
constant-time.

Pilihan ini menjaga seluruh keputusan #4 tetap utuh — kode tetap turunan password,
tidak ada rahasia baru yang perlu dirotasi, dan mengganti password otomatis
mengganti semua kode — sambil menahan password tetap di server.

**Mengapa KDF lambat, bukan SHA-256 biasa.** Ruang kode hanya 10⁸. Dengan SHA-256
polos, penyerang yang memegang amplop memulihkan kode dalam hitungan detik.
PBKDF2 120.000 iterasi membuat satu tebakan berbiaya ~100 ms di perangkat kelas
menengah, sehingga menyisir seluruh ruang menjadi tidak praktis, sementara satu
verifikasi yang sah tetap terasa seketika bagi pengawas.

**Batas yang diterima.** Jalur offline hanya bekerja untuk hari yang tercakup
amplop dari config fetch terakhir. Perangkat yang lebih dari 7 hari tidak pernah
menghubungi server kehilangan jalur ini. Dalam praktik ini tidak mengikat:
mengambil config adalah syarat memulai ujian, sehingga amplop selalu segar pada
hari ujian. Jumlah hari (7) dipilih agar mencakup jeda akhir pekan panjang tanpa
memperbesar jendela kebocoran secara berlebihan.

### 4.3 Alur Verifikasi

`verifyExitPassword` (`MainActivity.kt:395`) berganti nama menjadi
`verifyExitCredential` dan menjalankan urutan berikut:

1. Bila jalur offline aktif dan tidak sedang terkunci (§4.5): cocokkan masukan
   dengan amplop untuk hari kandidat (§4.4). Cocok → izinkan seketika, catat ke
   antrian audit (§4.6), selesai.
2. Bila tidak cocok: jalankan verifikasi server seperti sekarang
   (`POST /api/kiosk/verify-exit`).
3. Bila server menolak atau gagal dihubungi: tambah penghitung kegagalan lokal,
   tampilkan pesan seperti perilaku sekarang.

**Urutan lokal lebih dulu disengaja.** Skenario pemakaian jalur ini adalah server
tidak terjangkau; mendahulukan server berarti pengawas menunggu dua kali timeout
8 detik (`MainActivity.kt` `postJson`) sebelum kode yang benar diterima — persis
pada momen paling menegangkan. Biayanya satu PBKDF2 (~100 ms × jumlah hari
kandidat) pada setiap percobaan password normal, yang tidak terasa.

Pemeriksaan dijalankan di thread latar yang sudah ada (`KioskVerifyPassword`),
tidak pernah di UI thread.

### 4.4 Hari Kandidat & Anti Mundurkan Jam

Kode dicocokkan terhadap tiga hari: **H-1, H, H+1** menurut jam perangkat di zona
Asia/Jakarta. Jendela ini menoleransi jam perangkat yang melenceng dan perbedaan
waktu di sekitar tengah malam.

Jendela tersebut sekaligus membuka celah: siswa yang pernah melihat kode kemarin
dapat memundurkan jam perangkat untuk memakainya kembali. Penutupnya adalah
**jangkar waktu server**:

- Setiap respons sukses dari config fetch maupun heartbeat, aplikasi membaca
  header HTTP `Date`, mengonversinya ke tanggal Asia/Jakarta, dan menyimpannya di
  prefs sebagai `kiosk_server_day_anchor`.
- Jangkar hanya boleh maju, tidak pernah mundur.
- Hari kandidat yang lebih tua dari jangkar dibuang sebelum verifikasi.

Memajukan jam tidak ditutup dan tidak perlu ditutup: untuk memanfaatkannya
penyerang harus sudah mengetahui kode hari depan, yang persis sama sulitnya dengan
menebak kode hari ini.

### 4.5 Rate Limit Lokal

Tanpa server, tidak ada yang membatasi percobaan. Aplikasi menyimpan di prefs:

- `offline_exit_fails` — penghitung kegagalan
- `offline_exit_lock_until` — epoch milidetik berakhirnya kuncian

Lima kegagalan berturut-turut mengunci **jalur offline** selama 600 detik, mencermin
`MAX_EXIT_FAILS` dan `EXIT_LOCKOUT_SECONDS` di `KioskController.php:12-14`.
Keduanya di prefs, bukan di memori, supaya menutup-buka dialog tidak
mengatur ulang penghitung.

Saat terkunci, **hanya jalur offline yang ditolak**; verifikasi server tetap
berjalan normal. Sekolah dengan server sehat tidak boleh ikut terkunci hanya
karena ada siswa yang mengetuk-ngetuk angka. Verifikasi yang berhasil — lewat jalur
mana pun — mengatur ulang penghitung ke nol.

### 4.6 Audit

Karena keputusan #3 membuat jalur offline berlaku bahkan saat server sehat, tanpa
audit ia menjadi pintu yang tidak meninggalkan jejak apa pun. Antrian audit
menutup itu.

Prefs menyimpan `pending_offline_exits`: array JSON, maksimum 20 entri, terlama
dibuang saat penuh. Tiap entri:

```json
{ "device_id": "...", "at": 1757400000, "code_day": "2026-09-09", "app_version": "1.0.0" }
```

Antrian dikirim ke endpoint baru saat config fetch berikutnya berhasil:

```
POST /api/kiosk/offline-exit-log
{ "device_id": "...", "events": [ ... ] }
```

Server menuliskannya ke `ActivityLogModel` sehingga muncul di log aktivitas admin.
Entri hanya dihapus dari antrian setelah server membalas 2xx.

**Pengerasan endpoint.** Rute ini tidak terautentikasi, sama seperti rute kiosk
lain, sehingga menjadi jalur tulis ke log aktivitas. Pengamanannya: maksimum 20
event per permintaan, dan throttle cache per `device_id` (maksimum 10 permintaan
per 10 menit) mengikuti pola yang sudah dipakai `verifyExit`. Event dengan
`device_id` tidak valid ditolak lewat `DeviceBan::isValidDeviceId`.

### 4.7 Sisi Admin

Halaman **Admin → Kiosk** (`src/app/Views/admin/kiosk/index.php`) mendapat panel
baru **Kode Keluar Offline**:

- Tabel kode hari ini sampai 6 hari ke depan beserta tanggalnya, dirancang agar
  layak dicetak untuk dibawa pengawas.
- Toggle `kiosk_offline_exit_enabled`.
- Peringatan mencolok bila `kiosk_exit_password` lemah — masih `123456`, lebih
  pendek dari 12 karakter, atau ada di daftar kecil password umum. Peringatan
  menjelaskan bahwa seluruh kekuatan kode bertumpu pada password tersebut.
  **Peringatan tidak memblokir penyimpanan** (keputusan #5).
- Panel tidak ditampilkan sama sekali bila toggle mati, kecuali toggle itu
  sendiri.

Kode berubah otomatis begitu password diganti; tidak ada langkah rotasi terpisah.

### 4.8 Toggle & Default

`kiosk_offline_exit_enabled`, boolean, **default `false`**. Ditambahkan ke
`ALLOWED_KEYS` dan `KEY_META` di `KioskSettingsController.php`, serta ke daftar
key dan seed default di `SettingController.php`.

Saat mati, `/api/kiosk/config` mengirim `offline_exit.enabled = false` **tanpa**
blok `days`, dan aplikasi melewati jalur lokal sepenuhnya serta menghapus amplop
yang tersimpan. Mematikan toggle karenanya mencabut jalur offline dari seluruh
perangkat pada config fetch berikutnya.

---

## 5. Perubahan Berkas

### 5.1 Server

| Berkas | Perubahan |
|--------|-----------|
| `src/app/Libraries/KioskOfflineCode.php` | **Baru.** Pembangkit kode (§4.1), pembangun amplop (§4.2), pemeriksa kelemahan password |
| `src/app/Controllers/Api/KioskController.php` | Kirim `offline_exit` di config; method `offlineExitLog` |
| `src/app/Config/Routes.php` | Rute `kiosk/offline-exit-log` |
| `src/app/Config/Filters.php` | Bebaskan rute baru dari CSRF, sejajar `kiosk/verify-exit` (baris 99-100) |
| `src/app/Controllers/Admin/KioskSettingsController.php` | Key `kiosk_offline_exit_enabled` |
| `src/app/Controllers/Admin/SettingController.php` | Key + seed default |
| `src/app/Views/admin/kiosk/index.php` | Panel kode, toggle, peringatan password |

### 5.2 Aplikasi

| Berkas | Perubahan |
|--------|-----------|
| `.../kiosk/security/OfflineExitCode.kt` | **Baru.** Penyimpanan amplop, hari kandidat, jangkar waktu, verifikasi PBKDF2, rate limit |
| `.../kiosk/MainActivity.kt` | Konsumsi `features`, `verifyExitCredential`, prompt launcher, antrian audit, jangkar `Date` |
| `.../kiosk/kiosk/HeartbeatManager.kt` | Perbarui jangkar waktu dari header `Date` |
| `app/src/main/res/values/strings.xml` | String prompt launcher & kode offline |

Tidak ada perubahan pada `exam-app.js` atau template ujian, sehingga **rebuild UI
bundle tidak diperlukan** untuk pekerjaan ini.

---

## 6. Rencana Pengujian

### 6.1 Vektor Uji Bersama — Prioritas Tertinggi

Pembangkitan kode ada di PHP; verifikasinya di Kotlin. Keduanya harus sepakat,
dan ketidaksepakatan sekecil apa pun baru ketahuan saat ujian berlangsung — persis
saat jalur ini dibutuhkan.

Karena itu satu himpunan vektor uji tetap (password, tanggal, kode yang diharapkan)
disimpan sebagai fixture dan dijalankan di **kedua** sisi:

- PHPUnit: password + tanggal → kode.
- Unit test JVM: kode + salt + iterasi (dari fixture yang sama) → verifikasi
  PBKDF2 cocok, dan masukan yang salah ditolak.

Vektor mencakup password ASCII biasa, password dengan karakter non-ASCII (menguji
UTF-8 di kedua bahasa), dan tanggal di sekitar pergantian bulan.

### 6.2 Uji Lain

**PHP**
- Amplop berisi tepat 7 hari, salt berbeda tiap hari, `enabled=false` tidak
  menyertakan `days`.
- Pemeriksa kelemahan password: `123456`, string pendek, password kuat.
- `offlineExitLog`: menolak >20 event, menolak `device_id` tidak valid, throttle
  aktif pada permintaan ke-11.

**Kotlin**
- `isSelfTheHomeApp` untuk kasus cocok, tidak cocok, dan `resolved == null`.
- Hari kandidat: menghasilkan H-1/H/H+1; membuang hari yang lebih tua dari
  jangkar; jangkar tidak pernah mundur.
- Rate limit: terkunci pada kegagalan ke-5, bertahan melewati pembuatan ulang
  objek, direset oleh keberhasilan.
- Antrian audit: dibatasi 20 entri, membuang yang terlama, tidak dihapus sebelum
  balasan 2xx.

**Manual di perangkat** (mengikuti pola pengujian yang sudah berlaku di proyek —
instruksi bernomor untuk dijalankan pemilik produk di HP):
1. Selesaikan ujian, pastikan dialog pemulihan launcher muncul dan tombolnya
   membuka layar Home settings.
2. Kembalikan launcher, buka lagi aplikasi, pastikan dialog tidak muncul lagi.
3. Matikan WiFi, masukkan kode hari itu dari dashboard, pastikan kiosk terbuka
   tanpa sirene.
4. Nyalakan WiFi, pastikan entri muncul di log aktivitas admin.

---

## 7. Rujukan

- Spec kiosk awal: `docs/superpowers/specs/2026-08-12-android-kiosk-webview-design.md`
  (§ Layer 2 mensyaratkan prompt pemulihan launcher yang belum diimplementasi)
- Jalur keluar saat ini: `src/app/Controllers/Api/KioskController.php:85-250`,
  `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt:296-470`
- Kontrak kiosk-JS: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt`

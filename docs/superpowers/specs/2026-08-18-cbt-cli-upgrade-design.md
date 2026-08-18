# Rancangan: Upgrade `scripts/cbt.sh`

Tanggal: 2026-08-18
Status: disetujui, siap direncanakan

## Masalah

`scripts/cbt.sh` adalah pembantu CLI untuk mengelola CBT-MF. Dua keluhan yang
dipilih pemilik proyek:

1. **Ketinggalan perintah baru.** Utilitas yang lahir belakangan
   (`cbt:build-ui-bundle`, `cbt:extract-inline-images`, `finalize:expired`,
   `kiosk:prune`, bahkan `migrate`) tidak pernah masuk ke skrip, jadi setiap
   pemakaian menuntut masuk container dan mengetik manual.
2. **Rapuh dan tidak aman.** Skrip berjalan tanpa mode ketat, membocorkan
   password lewat argumen proses, dan memuat `.env` dengan cara yang pecah pada
   nilai bermakna spasi.

Akar keluhan pertama adalah strukturnya: skrip punya dua permukaan — menu
interaktif dan parser argumen — dan setiap perintah harus ditulis di keduanya.
Kelalaian itu sudah terjadi: `reset-password` dan `shell` hanya ada di menu,
tanpa padanan CLI.

### Bukti melenceng dari sistem sekarang

- Default container `ujian_php` / `ujian_mariadb`; yang berjalan `ex_php` /
  `ex_mariadb`. Selamat hanya karena `.env` selalu ada.
- Menu Docker mengiklankan phpMyAdmin di `localhost:8081`; service-nya sudah
  tidak ada di `docker-compose.yml`, meski `CONTAINER_PHPMYADMIN` masih ada di
  `.env.example`.
- `.env.example` belum memuat `REDIS_PASSWORD` (dipakai `run_backup`),
  `PHP_FPM_MAX_CHILDREN`, dan `DB_BUFFER_POOL`.

## Keputusan yang mengikat

| Keputusan | Pilihan |
|---|---|
| Sumber kebenaran perintah | Senarai deklaratif; menu dan `help` dirender darinya |
| Berkas | Tetap satu (`scripts/cbt.sh`), tidak dipecah |
| Installer | Tetap di dalam, isinya tidak diubah |
| Gerbang root | Tetap wajib untuk semua perintah |
| Logo kiosk | Setelan terpisah `kiosk_logo`, bukan menimpa `app_logo` |

## Arsitektur

Satu berkas, tujuh bagian bertanda:

```
1. Mode ketat + warna
2. load_env()          — pembaca .env yang aman
3. Resolusi container  — malas, gagal dengan pesan yang menunjuk installer
4. Senarai perintah    — CMD+=("grup|nama|fungsi|bahaya|deskripsi")
5. Fungsi perintah     — satu fungsi per perintah
6. Dispatcher + menu   — keduanya membaca senarai
7. run_install()       — dilonggarkan, tidak diubah
```

### Senarai perintah

```bash
CMD+=("bundle|build|do_bundle_build|0|Bangun ulang bundle UI kiosk")
CMD+=("redis|flush|do_redis_flush|1|Hapus seluruh isi Redis")
```

Kolom `bahaya` bernilai 1 memaksa konfirmasi ketik-ulang nama perintah dan
mewarnai barisnya merah di menu.

### Mode ketat dan installer

`set -euo pipefail` berlaku se-berkas. `run_install` melonggarkannya di awal dan
memulihkan di akhir, sehingga perilakunya persis seperti sekarang:

```bash
run_install() {
    set +e +u +o pipefail
    ... isi lama, tidak diubah ...
    set -euo pipefail
}
```

Dua baris itu adalah satu-satunya sentuhan pada installer. Menghapusnya nanti
cukup untuk membuat installer ikut ketat, ketika ada waktu mengauditnya.

### Pembaca `.env`

Mengganti `export $(grep -v '^#' "$ENV_FILE" | xargs)`, yang pecah pada nilai
bermakna spasi dan menelan tanda kutip. Penggantinya membaca baris demi baris,
memecah pada `=` pertama, dan melepas kutip di ujung.

Sengaja **bukan** `source .env`: itu mengeksekusi isinya sebagai bash, sehingga
backtick di dalam `.env` akan berjalan sebagai perintah.

Bila `.env` maupun `.env.example` tidak ada, skrip **memperingatkan** lalu jalan
terus — bukan berhenti seperti sekarang. Instalasi baru yang bersih memang belum
punya berkas itu; installer yang bertugas membuatnya.

### Resolusi container yang malas

Nama container dicari saat dibutuhkan, bukan saat skrip dimuat:

```bash
php_container() {
    [ -n "${CONTAINER_PHP:-}" ] || die "CONTAINER_PHP belum ada di .env.
Jalankan installer dulu:  sudo ./scripts/cbt.sh install"
    echo "$CONTAINER_PHP"
}
```

Tanpa kemalasan ini, gagal-keras saat pemuatan akan mengunci `install` sendiri
pada situasi yang paling membutuhkannya. `install`, `help`, dan menu wajib tetap
jalan di atas `.env` kosong.

`require_container` dipanggil sebelum perintah yang butuh container hidup, agar
pesannya menunjuk jalan keluar alih-alih memuntahkan galat docker mentah.

## Permukaan perintah

| Grup | Perintah | Catatan |
|---|---|---|
| `docker` | `up` `down` `restart` `logs` `status` | pesan phpMyAdmin dibuang |
| `app` | `shell` `php` `composer` | `shell` naik dari menu-saja |
| `db` | `shell` `root` `export` `import` `reset-password` | `reset-password` naik dari menu-saja; `import` bahaya |
| `redis` | `shell` `flush` | `flush` bahaya |
| `bundle` | `build` `status` | baru |
| `data` | `images` `optimize` `cache-clear` `finalize` `prune-kiosk` | baru; `optimize` bahaya |

| `migrate` | `up` `status` `rollback` | baru; `rollback` bahaya |
| `tune` | `show` `set` | baru |
| — | `backup` `log-rotate` `reset-install` `test-k6` `install` `help` | `reset-install` bahaya |

### `bundle status`

Membandingkan tiga nilai sekaligus:

1. versi manifest bundle lokal di server,
2. versi yang dilaporkan `/api/kiosk/config`,
3. versi manifest **di dalam zip yang benar-benar diunduh** dari URL publik.

Ketiganya berbeda ketika CDN masih menahan berkas lama — kegagalan yang pernah
memakan waktu lama untuk didiagnosis karena tidak ada satu pun cara melihatnya
sekaligus.

Nilai 2 dan 3 memerlukan server publik terjangkau. Bila tidak, keduanya
dilaporkan "tidak terjangkau" dan nilai 1 tetap ditampilkan; perintah tidak
gagal.

### `bundle build --logo <path>`

Path yang ditempel ada di host, sedangkan `spark` berjalan di dalam container,
jadi berkasnya harus masuk repo lebih dulu:

```
host  : cek berkas ada, benar-benar gambar, tipe dan ukuran wajar
      → salin ke src/public/uploads/kiosk/<hash>.png
spark : cbt:build-ui-bundle --logo uploads/kiosk/<hash>.png
      → simpan setelan kiosk_logo lewat SettingModel
      → kecilkan ke 128px, tanam ke bundle, bangun ulang
```

Setelan ditulis lewat PHP, bukan `UPDATE` langsung: `SettingModel` menyimpan
cache di Redis dan berkas, sehingga tulisan mentah meninggalkan nilai basi.

`UiBundleBuilder::schoolIdentity()` membaca `kiosk_logo` lebih dulu, lalu jatuh
ke `app_logo` bila kosong — instalasi yang sudah berjalan tidak kehilangan
logonya tanpa menyetel apa pun.

### `data optimize`

`OPTIMIZE TABLE` pada `test_logs`, `test_log_answers`, `questions`, dan
`answers` — empat tabel yang membengkak oleh arsitektur snapshot. InnoDB
menerjemahkannya menjadi recreate + analyze, yang **mengunci tabel** selama
berjalan; karena itu ditandai bahaya dan pesannya mengingatkan agar tidak
dijalankan saat ujian berlangsung.

### `tune show` dan `tune set`

`show` menampilkan nilai efektif, bukan nilai yang diniatkan: `pm.max_children`
dibaca dari `php-fpm -tt`, `innodb_buffer_pool_size` dari MariaDB, handler cache
dari config yang dimuat, plus jumlah core.

`set` menulis `PHP_FPM_MAX_CHILDREN` atau `DB_BUFFER_POOL` ke `.env` lewat
penulis `key=value` yang benar, lalu **memberi tahu** perintah penerapannya
(`docker compose build php`, `docker compose up -d mariadb`) tanpa menjalankan
sendiri. Menyalakan ulang layanan harus keputusan sadar.

Konsekuensi yang diterima: `tune set` memakai penulis `.env` yang benar
sementara `run_install` tetap memakai `sed`, jadi ada dua cara menulis `.env` di
satu berkas. Penulis yang benar sudah tersedia bila installer nanti dibereskan.

## Keamanan

- **Password berhenti lewat argumen.** `-p$DB_PASS` terbaca siapa pun lewat
  `ps`. Diganti `docker exec -e MYSQL_PWD=...`, pola yang sudah dipakai
  `run_backup` di berkas yang sama.
- **Perintah bahaya minta ketik ulang** nama perintahnya, bukan `y`.
- **Semua ekspansi variabel dikutip.** Sekarang `$sql_file` dan sejenisnya
  telanjang, sehingga path bermakna spasi pecah.
- **`db reset-password` berhenti menyisipkan hash lewat interpolasi string.**

## Pengujian

Bash tanpa kerangka uji, jadi gerbangnya:

- `shellcheck` bersih tanpa peringatan.
- `help` dan render menu diuji pada tiga keadaan: `.env` lengkap, `.env` kosong,
  dan tanpa `.env` sama sekali. Ini yang menjaga jalur ayam-telur installer.
- Setiap perintah baru dijalankan sekali terhadap sistem dev yang berjalan.
- Perintah bahaya diuji sampai prompt lalu dibatalkan, lalu sekali dijalankan
  penuh — lingkungan ini lokal dan pemiliknya mengizinkan datanya dihapus.
- `run_install` diuji dari nol, sesudah `reset-install`.

### Pengaman sebelum uji merusak

Data boleh hilang; tiga hal berikut bukan sekadar data dan diamankan lebih dulu:

1. **`CF_TUNNEL_TOKEN`.** Installer menanyakannya ulang; membiarkannya kosong
   mematikan tunnel. `.env` dan `src/.env` dicadangkan, dan nilai yang sama
   dimasukkan ulang — termasuk prefix `ex`, agar nama container tidak berubah
   dan container lama tidak menjadi yatim.
2. **`composer.lock`.** Installer menjalankan `composer update --no-dev`, yang
   dapat mengubahnya. Lock file adalah codebase, bukan data; bila berubah,
   dikembalikan lewat `git checkout`.
3. **Dump database** diambil sebelum pengujian merusak dimulai.

## Di luar cakupan

- Memecah skrip menjadi beberapa berkas.
- Memperbaiki isi `run_install` (hanya dilonggarkan mode ketatnya).
- Perintah diagnostik menyeluruh (`doctor`) — pemilik proyek tidak memilihnya.
- Melonggarkan gerbang root.

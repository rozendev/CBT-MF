# Rancangan: Login throttling yang CGNAT-safe + break-glass

Tanggal: 2026-09-01
Status: rancangan (disetujui; siap direncanakan)

## Masalah

`LoginRateLimitFilter` mengunci **per-IP**: 20 percobaan POST `/login` per 15 menit,
lalu semua orang di balik IP itu ditolak sampai jendela habis. Di sekolah dengan
**CGNAT** — ratusan siswa keluar lewat satu IP publik — 20 percobaan habis dalam
hitungan detik saat ujian dimulai. Rem yang dimaksudkan menahan brute force satu
penyerang malah **mengunci satu sekolah**.

Bentuk sebenarnya bukan "throttle-nya salah", melainkan **dua dimensi ancaman yang
digabung ke satu rem**:

| Dimensi | Ancaman | Tempat yang benar |
|---|---|---|
| Volumetrik | Flood request membakar CPU (bcrypt per POST) | Cloudflare, sebelum request tiba |
| Per-akun | Brute force password satu akun | Lockout DB per-user (sudah ada, benar) |
| Per-IP | — | Di tengah: menghukum ribuan orang untuk dosa satu orang |

Rem per-IP berdiri di posisi yang paling merugikan: ia tidak menahan flood
(request tetap sampai ke PHP) dan tidak menghentikan brute force lebih baik dari
lockout akun, tetapi ia satu-satunya lapisan yang **menjatuhkan korban massal**.

### Bukti yang dikumpulkan dari kode berjalan

- `src/app/Filters/LoginRateLimitFilter.php`: ambang `20` dan jendela `900` detik
  **hardcoded**. Tidak ada jalan mengubahnya tanpa deploy ulang.
- Blok `catch` filter itu **fail-closed**: setiap kegagalan Redis saat POST
  `/login` mengembalikan `503` dengan badan "Sistem keamanan tidak dapat
  diinisialisasi". Satu blip Redis = **tidak ada seorang pun bisa login**,
  padahal lockout per-akun tidak butuh Redis.
- IP klien dibaca dengan `$request->getIPAddress()` bawaan CI4 di tiga tempat
  yang harus sepakat: INCR kunci di filter, `last_failed_login_ip:{id}` di
  `src/app/Controllers/Auth/AuthController.php:97`, dan `recordLogin()` di
  `src/app/Controllers/Auth/AuthController.php:250`. Di belakang cloudflared,
  `getIPAddress()` mengembalikan **IP proxy**, bukan IP siswa — sehingga seluruh
  sekolah tampak sebagai satu IP dan throttle memang mustahil dibedakan per orang.
- Lockout per-akun sudah ada dan sudah admin-tunable: `max_login_attempts`
  (default 5) dan `lockout_duration` (15 mnt) di
  `src/app/Database/Seeds/InitialSeeder.php:56-57`, diizinkan di
  `SettingController::INTEGER_KEYS`. Lapisan ini yang benar-benar menahan brute
  force, dan ia berbasis DB — hidup tanpa Redis.
- Tidak ada jalan keluar darurat. Jika sebuah IP terblokir salah, satu-satunya
  cara adalah menunggu 15 menit atau `redis-cli DEL` manual di server. Tidak ada
  perintah, tidak ada tombol.

## Keputusan yang mengikat

| Keputusan | Pilihan | Alasan |
|---|---|---|
| Peran rem per-IP | Dari pemblokir → pengaman pemaaf | Flood di-offload ke CF; per-IP hanya menahan burst kasar |
| Sumber IP klien | Satu resolver terpusat `ClientIp` | Filter dan pencatatan AuthController harus memakai string IP yang persis sama |
| IP di belakang proxy | `CF-Connecting-IP`, hanya jika peer tepercaya | Tanpa guard proxy, klien bisa memalsukan header dan mengelabui throttle |
| Ambang IP | Setting `login_ip_max_attempts`, dapat diputar dari admin | Sekolah beda ukuran; hardcode 20 adalah akar masalah |
| Reset-on-success | AuthController DEL kunci IP saat login sukses | Selama ada yang berhasil login, counter dibersihkan → tak menumpuk ke blokir massal |
| Jendela waktu | Tetap 900 detik, konstan | Menjaga scope; knob utama adalah jumlah, bukan durasi |
| Lockout per-akun | Tidak disentuh | Sudah benar; ini pertahanan brute force yang sesungguhnya |
| Break-glass shell | `php spark auth:unblock` | Harus jalan saat web terkunci total |
| Break-glass web | Tombol unblock IP untuk admin | Insiden umum tak boleh butuh SSH |
| Cloudflare | Runbook di spec, bukan kode | Lapisan volumetrik hidup di edge, bukan di repo |
| **A — Fail-mode Redis (lapisan IP)** | **Fail-open + alert** | Lockout akun (DB) tetap menahan brute force tanpa Redis; CF menahan flood. Satu blip Redis tak lagi melumpuhkan ujian. Risiko diterima: burst kasar per-IP tak tertahan di app selama Redis down — porsi CF. |
| **B — Rumah tombol unblock web** | **Halaman Suspend** | Bersebelahan dengan "reset login" per-user yang sudah ada; semua tindakan batalkan-blokir di satu tempat. |

## Arsitektur

### Komponen 1 — `App\Libraries\ClientIp`

Satu sumber kebenaran IP klien di seluruh jalur login.

- `ClientIp::get(RequestInterface $request): string`.
- Logika: bila **peer langsung** (`REMOTE_ADDR`, yaitu cloudflared) ada di
  allowlist proxy tepercaya **dan** `CF-Connecting-IP` berisi IP valid → pakai itu.
  Selain itu → fallback `getIPAddress()` bawaan.
- Allowlist dari env `TRUSTED_PROXY_IPS` (daftar CIDR; default loopback +
  rentang bridge Docker). Guard inilah yang mencegah klien memalsukan
  `CF-Connecting-IP` untuk memanipulasi throttle.
- **Dipakai konsisten** di `LoginRateLimitFilter` dan di pencatatan IP
  `AuthController` (baris 97 & 250). Kunci `login_attempts_ip:{ip}` yang di-INCR
  filter, yang di-DEL saat sukses, dan `last_failed_login_ip` **wajib** memakai
  string IP yang sama persis — kalau tidak, reset-nya meleset dan blokir tak
  pernah bersih.

### Komponen 2 — `LoginRateLimitFilter` dirombak

- Kunci pakai `ClientIp::get()`, bukan `getIPAddress()`.
- Ambang dibaca dari `login_ip_max_attempts` lewat helper ber-cache (~60s) agar
  tidak query DB tiap POST.
- **Reset-on-success:** filter tetap INCR tiap POST (gate murah **sebelum**
  bcrypt — melindungi CPU), tetapi AuthController **DEL kunci IP saat login
  berhasil**. Efeknya: selama ada satu orang berhasil login di balik CGNAT,
  counter terus dibersihkan sehingga tak pernah menumpuk ke blokir massal. Login
  gagal tetap dihitung sebagai strike.
- Fail-mode Redis **fail-open + log** (keputusan A): `catch` tidak lagi
  mengembalikan `503`; ia mencatat peringatan dan `return` kosong sehingga login
  diteruskan ke AuthController.
- Jendela tetap 900 detik.

### Komponen 3 — Setting `login_ip_max_attempts`

- Di-seed di `InitialSeeder` + default `SettingController::resetSettings`, group
  `security`, type integer, default **50**.
- Ditambahkan ke `SettingController::INTEGER_KEYS` (baris 23) + `KEY_META`, dan
  satu field di view settings grup Keamanan.

### Komponen 4 — Command `php spark auth:unblock`

Jalan dari shell walau web terkunci total.

- `--ip=A.B.C.D` → hapus blokir satu IP (`DEL login_attempts_ip:{ip}`).
- `--user=USERNAME` → reset lockout akun (login_attempts=0, locked_until=null)
  + hapus kunci IP terakhirnya.
- `--all` → hapus **semua** `login_attempts_ip:*` (dengan konfirmasi).
- tanpa argumen → tampilkan daftar IP yang sedang terblokir + hitungannya
  (diagnostik, tidak mengubah apa pun).

### Komponen 5 — Tombol unblock web

Sesuai **keputusan B** (rekomendasi: halaman Suspend).

- `SuspendController::unblockIp` (POST) + route `admin/suspend/unblock-ip`,
  dijaga role admin/superadmin.
- Panel: daftar `login_attempts_ip:*` aktif + hitungan, tombol **Unblock** per-IP,
  dan **Unblock semua**. Melengkapi tombol "reset login" per-user yang sudah ada.

### Penanganan galat

| Kondisi | Perilaku |
|---|---|
| Redis mati saat POST /login | Fail-open + log peringatan; login diteruskan (keputusan A) |
| `CF-Connecting-IP` ada tapi peer tak tepercaya | Abaikan header, pakai `getIPAddress()` |
| `CF-Connecting-IP` berisi IP tak valid | Fallback `getIPAddress()` |
| `login_ip_max_attempts` tak terbaca / non-integer | Pakai default aman (50), jangan blokir |
| `auth:unblock` tanpa argumen | Diagnostik saja, tak mengubah state |
| `auth:unblock --user` tak ditemukan | `die` dengan pesan, tak menyentuh Redis |
| Unblock web oleh non-admin | Ditolak filter role sebelum controller |

## Lampiran ops — Lapisan Cloudflare (bukan task kode)

Didokumentasikan di runbook, tidak di-code:

- **CF Rate Limiting Rule** pada `POST /login` — inilah yang benar-benar mematikan
  risiko CPU: request flood ditolak di edge, bcrypt tak pernah jalan.
- **Turnstile / Managed Challenge** pada `/login` — ramah-CGNAT karena memberi
  *tantangan*, bukan *ban*: siswa sah lolos, bot tidak.

Tanpa lapisan ini, rem aplikasi menanggung beban volumetrik yang bukan porsinya.

## Pengujian

Unit test:

- `ClientIp`: peer tepercaya + header valid → IP siswa; peer tak tepercaya +
  header → diabaikan; header palsu/invalid → fallback; tanpa proxy → `getIPAddress()`.
- `LoginRateLimitFilter`: ambang dibaca dari setting (bukan 20 hardcoded);
  INCR menaik; over-limit → tertahan; fail-mode Redis sesuai keputusan A.
- Reset-on-success: login sukses meng-DEL kunci IP; login gagal tidak.
- `auth:unblock`: tiap mode (`--ip`, `--user`, `--all`, tanpa argumen) terhadap
  Redis sekali-pakai.

## Di luar cakupan

- Mengubah lockout per-akun (`max_login_attempts` / `lockout_duration`). Sudah benar.
- Mengonfigurasi Cloudflare lewat kode. Itu urusan edge, didokumentasikan saja.
- Membuat jendela waktu (900s) dapat diatur. Knob utama adalah jumlah percobaan.
- Rate limiting jalur non-login (`ApiRateLimitFilter` dan lainnya).

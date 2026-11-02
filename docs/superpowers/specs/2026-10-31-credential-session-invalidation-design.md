---
title: Invalidasi Sesi Berbasis Peran saat Perubahan Kredensial & Proteksi Role Ujian
date: 2026-10-31
status: approved
approach: DB Auth/Credential Versioning + Redis State Cache + Global Guard Filter + WebSocket/Polling Grace Timer
target: Server CBT-MF (CodeIgniter 4, Redis, Ratchet WebSocket) & Klien Ujian (Web, Static, Android Kiosk Bundle)
---

# Invalidasi Sesi saat Perubahan Kredensial & Proteksi Role Ujian

## 1. Tujuan & Aturan Bisnis

Menutup celah sesi yang tetap aktif setelah perubahan kredensial atau hak akses (`AUTH-01`), dengan memisahkan perlakuan berdasarkan peran pengguna dan jenis field yang diubah:

1. **Perubahan profil non-kredensial (`firstname`, `lastname`, `email`, `birthdate`, `birthplace`, `registration_number`, `ssn`, `groups`):**
   - Tidak memutus sesi dan tidak mengirim peringatan apa pun.
2. **Larangan ubah `role` pada siswa yang sedang mengerjakan ujian:**
   - Jika akun ber-role `siswa` memiliki minimal satu baris di `test_attempts` dengan `status IN (1, 2)` (definisi *Sedang Mengerjakan* pada pie chart `Admin\DashboardController`), perubahan `role` ke role apa pun ditolak sebelum menyentuh database atau Redis.
3. **Akun `admin` dan `guru` (serta non-siswa seperti `proctor`) saat `username` atau `password` diganti:**
   - Seluruh sesi aktif langsung tidak berlaku **seketika tanpa TTL (0 detik)**, termasuk sesi browser admin yang melakukan perubahan sendiri (wajib login ulang).
4. **Perubahan `role` (saat diizinkan) atau penonaktifan akun (`is_active: 1 -> 0`):**
   - Berlaku pada semua role: sesi langsung diputus **seketika tanpa TTL (0 detik)**.
5. **Akun `siswa` aktif saat hanya `username` atau `password` yang diganti:**
   - Diberikan masa tenggang (**TTL 10 menit / 600 detik**) untuk sesi yang dibuat sebelum perubahan kredensial.
   - Server mengirim peringatan real-time via WebSocket (`cred_updated`) ke koneksi milik `user_id` tersebut (dan menyertakan `cred_grace_until_ms` pada endpoint fallback polling `/api/exam/status/{attemptId}`).
   - Klien ujian menampilkan peringatan *non-blocking*, melakukan *pre-flush* jawaban tertunda 5 detik sebelum masa tenggang berakhir, lalu mengarahkan ke `'login.html'` (di dalam Android Kiosk Bundle agar *Lock Task* tetap terkunci) atau `/login` (di browser web).
   - Bila siswa sudah logout dan login ulang sebelum 10 menit habis, sesi barunya membawa versi terbaru sehingga tidak ikut terputus saat menit ke-10 tiba.

---

## 2. Arsitektur & Komponen

### 2.1 Skema Database (`users`)
Migrasi `2026-10-31-100000_AddAuthVersioningToUsers` menambahkan tiga kolom pada tabel `users`:
- `auth_version INT UNSIGNED NOT NULL DEFAULT 1` — pencabutan seketika (0s).
- `cred_version INT UNSIGNED NOT NULL DEFAULT 1` — pergantian kredensial siswa dengan masa tenggang.
- `cred_grace_until DATETIME NULL DEFAULT NULL` — batas waktu masa tenggang `cred_version`.

Pemisahan `auth_version` dari `cred_version` menjamin sesi pra-ban atau pra-perubahan-role (`session.auth_version < user.auth_version`) selalu ditolak seketika walau setelah itu akun diaktifkan kembali dan password-nya diganti.

### 2.2 Logika Keputusan Murni (`App\Libraries\SessionInvalidation`)
Kelas `final` murni tanpa I/O (mengikuti pola `SessionTakeover`) yang memegang:
- `classifyUserUpdate(object $oldUser, array $newData): string` — mengklasifikasikan edit menjadi `none`, `refresh_only`, `immediate_revoke`, atau `student_grace`.
- `evaluate(?int $sessionAuthVer, ?int $sessionCredVer, array $state, int $nowTs): array` — mengembalikan status `valid`, `grace`, atau `revoked` beserta alasan (`inactive`, `revoked`, `grace_expired`). Nilai versi sesi `null` (sesi lama saat deploy) dinormalisasi menjadi `1`.
- `buildCredUpdatedPayload(int $userId, int $credVersion, int $graceUntilTs, int $nowTs): array` — merakit payload WebSocket `cred_updated`.

### 2.3 Koordinasi State & Redis (`App\Libraries\UserAuthState`)
- Menyimpan snapshot `{auth_version, cred_version, cred_grace_until_ts, is_active, role}` di Redis key `user_auth_state:{userId}` ber-TTL `7200` detik.
- `resolve(int $userId)` membaca Redis lebih dulu (0 query DB saat normal); bila *cache miss* atau Redis gagal, *lazy fallback* membaca 1 baris PK dari `users` lalu mengisi ulang cache. Jika Redis dan DB gagal bersamaan, mengembalikan `null` (*fail-open* satu request agar gangguan sesaat tidak menjatuhkan ujian massal).
- `clearSessionTrackingIfOwned(int $userId, ?string $sessionLoginToken)` melakukan *compare-and-delete* pada `user_login_token:{userId}` dan `user_login_device:{userId}` hanya bila token di Redis sama dengan `login_token` sesi yang dicabut (memakai `hash_equals`), sehingga siswa browser desktop tidak terhadang `SessionTakeover::BUSY` palsu saat hendak login kembali setelah 10 menit, dan sentinel `'BANNED'` maupun sesi baru tidak pernah ikut terhapus.

### 2.4 Penegakan Filter Global (`App\Filters\SessionGuardFilter`)
Dipasang pada `Config\Filters::$globals['before']` sebelum `multilogin` (mengecualikan `login`, `logout`, `maintenance`, `health`, `api/kiosk/*`, `api/intruder/report`), sehingga mencakup `/admin/*`, `/student/*`, `/proctor/*`, `/api/*` (termasuk `stream`), dan `/queue*`.

### 2.5 Kanal Real-Time & Klien Ujian
- `WebSocketServerHandler::broadcastEvent`: mengecualikan `'cred_updated'` dari `broadcastToProctors()` agar tidak membanjiri ruang pengawas.
- `ExamStatusController::show`: menyertakan `cred_grace_until_ms` bila `u.cred_version > session('cred_version')`.
- `public/assets/exam-app.js`, `app/Views/student/exam/take.php`, dan fallback `app/Views/admin/static/static_exam_template.php`: menangani peringatan sekali-tampil, koreksi offset waktu server, *pre-flush* jawaban 5 detik sebelum kedaluwarsa, dan pemisahan target login Kiosk (`'login.html'`) vs Web (`/login`).

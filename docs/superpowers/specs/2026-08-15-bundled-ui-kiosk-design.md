# Bundled UI Kiosk — Design Spec

Tanggal: 2026-08-15
Status: Approved by user (design discussion + 8 technical decisions)

## Problem Statement

Siswa mayoritas ujian lewat HP Android dengan kiosk app. Kondisi jaringan buruk
(60kbps, ping tinggi) membuat load halaman web dari server lambat/berisiko gagal,
dan pekerjaan optimasi static exam/fallback di web menambah kompleksitas tanpa
menyelesaikan masalah inti: UI masih bergantung jaringan untuk setap kali tampil.

## Decision

**UI siswa (login → dashboard → exam → results → review) dipindah ke dalam
perangkat.** App WebView menampilkan halaman dari bundle lokal (bukan dari server).
Server hanya menyediakan **data JSON** (config, daftar ujian, soal, jawaban, results,
review) + **satu paket UI** yang di-download sekali lalu di-cache.

- Update UI = regenerate bundle di server (tanpa rilis APK baru).
- Web path (Windows) tetap apa adanya: halaman server asli tidak diubah.
- **Sideload (ShareIt/Bluetooth) adalah jalur distribusi PRIMER untuk
  first-install**; download network di jaringan buruk (60kbps) hanya untuk update
  kecil/incremental — bukan beban utama first-load.

## Architecture

```
┌─ CBT-MF (server) ──────────────────────────────┐
│ 1. cbt:build-ui-bundle (command)               │
│    → writes writable/ui-bundle/ (zip + files)  │
│      login.html dashboard.html exam.html       │
│      results.html review.html assets/          │
│      manifest.json {version: sha256}           │
│ 2. /api/kiosk/config + ui_bundle{version,url}  │
│ 3. Serve zip sebagai STATIC file via Nginx     │
│    (Range request → resume gratis)             │
│ 4. API data (semua cookie session):            │
│    POST /login (CORS+JSON, tanpa CSRF)         │
│    api/student/exams (new, +active_attempt)    │
│    api/exam/start (new)                        │
│    api/exam/init | autosave | auto-sync |      │
│    finish | check-score | report-cheat (ada)   │
│    api/student/results + review (new)          │
│ 5. Halaman server asli TIDAK diubah            │
└────────────────────────────────────────────────┘
        ▲ zip bundle (sekali) + JSON (sering)
┌─ cbt-kiosk-app (Android) ──────────────────────┐
│ UiBundleManager:                               │
│   config → hash sama? pakai cache :            │
│   DownloadManager (resume native) → verify     │
│   sha256 vs config → extract ke dir baru →     │
│   atomic rename. Import Bundle (sideload)      │
│   lewat pipeline verify-extract yang SAMA.     │
│   refreshBundle() hanya di cold start /        │
│   tanpa attempt aktif.                         │
│ WebView via WebViewAssetLoader:                │
│   https://appassets.androidplatform.net/...    │
│ CookieManager tetap simpan cookie domain       │
│ (SameSite=None, Secure)                        │
│ kiosk-integration.js script PERTAMA tiap page  │
│ KioskGuard/Heartbeat/Security/CommsBridge:     │
│   TIDAK diubah                                 │
└────────────────────────────────────────────────┘
```

## Keamanan & Cookie (keputusan teknis)

- **Cookie session global**: `Config\Cookie` CI4 → `samesite = 'None'`,
  `secure = true`. Aman dipasang global karena setup screen kiosk app sudah
  enforce HTTPS — tidak ada risiko bocor lewat HTTP plain.
- **CORS**: `Access-Control-Allow-Origin` harus **origin spesifik**
  (`https://appassets.androidplatform.net`), bukan wildcard — wildcard +
  `Allow-Credentials: true` ditolak browser. `Access-Control-Allow-Credentials:
  true` wajib.
- **Fetch di JS bundle**: semua request ke server wajib `credentials: 'include'`
  (bukan default/same-origin), supaya cookie session terkirim lintas origin.
- **CSRF di jalur JSON login**: TIDAK dipasang. Mitigasi = CORS origin allowlist
  yang ketat (origin `appassets.androidplatform.net` hanya bisa dipanggil dari
  dalam kiosk app itu sendiri). Token CSRF classic TETAP dipertahankan di jalur
  form-POST web (Windows, tidak diubah).
- Consistency: proteksi disesuaikan threat model aktual (ujian sekolah, bukan
  high-security) — tanpa signing/HMAC bundle.

## Components & Data Flow

### 1. Generator bundle (server)

Command `cbt:build-ui-bundle` (ikut pola `RegenStaticTemp`) merender 5 halaman
dari view yang ada dengan transformasi:

- Semua URL absolut terhadap server base (`appBaseUrl`) — karena halaman dibuka
  dari origin lokal.
- `kiosk-integration.js` disuntik sebagai `<script>` **pertama** di setiap halaman
  (wajib ada sejak login.html).
- halaman exam = varian static-exam template (render soal dari JSON + Alpine)
  dengan JSON diisi runtime dari `POST /api/exam/init`.
- Manifest `{version: sha256(bundle), generated_at, pages[]}`; seluruh isi di-zip.

### 2. API baru (server)

| Endpoint | Method | Fungsi |
|---|---|---|
| `/login` (rute ada) | POST | + CORS headers + response JSON; tanpa CSRF token; tetap set session cookie |
| `/api/student/exams` | GET | daftar ujian tersedia utk user + field `active_attempt` (ada attempt aktif utk test mana pun) |
| `/api/exam/start` | POST | buat attempt (migrasi logic prepare/start dari `Student\ExamController`) |
| `/api/student/results` | GET | data halaman hasil siswa |
| `/api/student/review` | GET | data review per-soal (sesuai kebijakan kunci jawaban) |

Catatan `/api/exam/start`: replika persis `ExamController::start` (password check
jika ujian berpassword, cek attempt aktif, cek `is_repeatable`), panggil
`ExamService::generateAttempt()` (mulai `started_at` — timer mulai dari sini),
balikin `{status, attempt_id | message}`. Tidak ada redirect.

Flow: dashboard → `exam/start` (password inline di exam.html jika perlu) →
`exam/init` → render soal. `/api/exam/init` tetap return `need_prepare` bila
attempt belum dibuat — bundle harus memanggil `start` dulu.

### 3. Distribusi & Download Bundle

- **Serve zip sebagai static file dari Nginx** (`location ^~ /ui-bundle/` +
  lokasi cache immutable), bukan lewat route PHP → Range requests native →
  resume gratis.
- **App pakai `DownloadManager` bawaan Android** (resume + retry ditangani
  OS sendiri); app cukup listen completion → verify → extract.
- **Sideload = jalur distribusi PRIMER first-install.** Scoped storage membuat
  ShareIt tidak bisa menulis langsung ke `filesDir` app → alur "Import Bundle"
  eksplisit: intent filter mime `application/zip` / file picker di setup screen →
  copy ke storage privat app → pipeline verify-extract yang SAMA PERSIS dengan
  jalur network. Tidak ada jalur trust khusus untuk bundle manual.
- **Verifikasi (network & sideload, satu code path)**: app hitung sha256 dari
  bytes yang benar-benar diterima, bandingkan dengan `ui_bundle.version` dari
  `/api/kiosk/config` (payload kecil, murah di-fetch meski koneksi jelek).
  TIDAK bergantung header HTTP `Content-SHA256` (bisa hilang/berubah via
  Cloudflare/proxy). TIDAK redownload zip penuh hanya untuk verifikasi.

### 4. App Android — UiBundleManager

- `refreshBundle()`: GET `/api/kiosk/config` → baca `ui_bundle.version` →
  bandingkan hash tersimpan di `filesDir/ui-bundle/manifest.json` →
  sama → pakai lokal; beda → download (DownloadManager) → verify sha256 vs
  config → extract.
- **Gate `refreshBundle()`**: hanya boleh jalan di dua state — (a) cold start
  sebelum login, (b) resume app tanpa attempt aktif (cek flag lokal
  `attempt_in_progress`). Tidak pernah jalan saat ujian berjalan.
- **Ekstrak antar-state**: extract ke direktori baru → verify → **atomic rename**
  menggantikan direktori lama. Tidak ada delete-in-place → tidak ada window di
  mana WebView membaca file yang sedang dihapus.
- Kegagalan download: retry exponential (max 3, backoff), lalu screen error
  informatif. Hash korup: hapus + re-download.
- WebView: `WebViewAssetLoader` + `assetsPathHandler` menunjuk
  `filesDir/ui-bundle`; navigasi antar halaman bundle via link internal
  (`/login.html` dst). CookieManager tetap aktif untuk domain server.

### 5. Resume setelah 401 (sesi kedaluwarsa)

- App menyimpan `attempt_id` aktif di app-level storage (`SharedPreferences`),
  BUKAN hanya di session server — supaya resume tidak kehilangan konteks.
- `/api/student/exams` (atau endpoint ringan) mengembalikan info ada attempt
  aktif + `attempt_id`-nya.
- Flow re-login sukses: bila ada attempt aktif → auto-redirect ke `exam.html`
  dengan attempt_id itu → panggil `exam/init` lagi (idempotent; jawaban sudah
  ter-autosave di server) — BUKAN balik ke `dashboard.html`.
- Sesi benar-benar kadaluwarsa (401) → login.html; bila tidak ada attempt →
  dashboard.html normal.

### 6. Alur runtime

1. Setup screen (existing): input server URL → enforce HTTPS → simpan prefs.
2. `fetchServerKioskConfig` → validasi min app version + root policy (existing).
3. `UiBundleManager.refreshBundle()` (hanya cold start / tanpa attempt aktif) →
   splash/loading; first-install via Import Bundle (sideload) bila ada.
4. WebView load `https://appassets.androidplatform.net/login.html` →
   `kiosk-integration.js` load pertama (bridge aktif sejak awal).
5. Login form POST ke server (CORS + `credentials: 'include'`, tanpa CSRF) →
   cookie session di-set → bila attempt aktif: langsung `exam.html`; bila tidak:
   navigasi internal ke `dashboard.html`.
6. Dashboard fetch `/api/student/exams` → tampil daftar → pilih → `exam/start`.
7. `exam.html` fetch `/api/exam/init` → render soal (Alpine, pola static exam) →
   autosave hanya saat jawaban berubah (`scheduleAutoSync`/change-driven) →
   `finish` → `results.html` → `review.html`. Setelah finish/keluar: reset
   `attempt_in_progress`.

## Bundle Size Budget

Target eksplisit: **< 300KB gzip** untuk seluruh bundle.

- No webfont (pakai system font).
- Icon SVG inline minimal — tidak ada gambar di shell UI.
- Satu vendor file Alpine yang sudah minified (reuse dari `vendor/alpinejs`).
- Tidak ada gambar besar di shell UI.

Size diukur saat build bundle (CLI) dan dijadikan **gate**: kalau lewat budget,
block sebelum lanjut (tidak boleh commit build baru).

## Error Handling

| Scenario | Behavior |
|---|---|
| Bundle belum ada + jaringan gagal saat download | Retry exponential (DownloadManager) → error screen; tidak ada fallback web |
| Hash manifest beda | Re-download bundle (jalur network) — via jalur import utk sideload |
| Zip korup / ekstrak gagal | Hapus dir baru → re-download / minta import ulang |
| Server unreachable padahal bundle sudah ada | Halaman tampil; data fetch gagal → error panel per-halaman dgn tombol retry |
| Sesi kedaluwarsa (API 401) | Login.html; bila ada attempt aktif → setelah login sukses auto-redirect ke exam.html (resume) |
| Update bundle saat attempt berjalan | Tidak mungkin: `refreshBundle()` di-gate (cold start / tanpa attempt) |

## Anti-negatif (hal yang TIDAK dikerjakan)

- Web views asli tidak diubah (jalur Windows tetap).
- Tidak ada fallback web di app (UI bundle adalah satu-satunya sumber UI).
- Tidak mengubah kiosk guard/heartbeat/security/comms bridge.
- Autosave-per-navigasi TIDAK berlaku: hanya saat jawaban berubah.
- TIDAK ada signing/HMAC bundle (kalibrasi security: konteks ujian sekolah;
  verifikasi cukup manifest sha256 dari config).
- TIDAK ada header `Content-SHA256` di response zip (via proxy bisa hilang).

## Testing

1. Lint PHP via docker `ex_php` + `node --check` semua JS bundle.
2. Build bundle via CLI → ukur size → **gate <300KB gzip**; buka halaman-
   halamannya di browser dengan origin test (CORS mock biar bisa verifikasi
   `credentials: 'include'` + cookie) utk verifikasi alur data + render.
3. `gradle assembleDebug` di `cbt-kiosk-app` (SDK ada di `/opt/android-sdk`).
4. Manual di device: first load (download), update bundle (hash berubah), offline
   (bundle ter-cache), kiosk lock, exit flow, Import Bundle via ShareIt/file
   picker, resume mid-attempt setelah 401 / app di-kill.
5. Verifikasi cookie: SameSite=None + Secure terpasang di response login;
   CORS preflight dari origin appassets.androidplatform.net diterima; fetch
   tanpa `credentials: 'include'` gagal (negative test).

## Open Items (resolved)

- Format verifikasi hash → **manifest sha256 via `/api/kiosk/config`** (keputusan
  teknis #8).
- Resumable download → **Nginx static + DownloadManager Android** (keputusan #2).
- Sideload trust → **tidak ada jalur trust khusus; pipeline verify-extract sama;
  import eksplisit** (keputusan #4).
- Refresh mid-attempt → **gate 2 state + atomic rename** (keputusan #5).
- CSRF login JSON → **tidak dipasang; CORS allowlist ketat** (keputusan #7).
- Copy cookie → **SameSite=None + Secure global; origin-specific CORS;
  credentials 'include'** (keputusan #1).
- Resume 401 → **attempt_id di app storage + active_attempt di exams API**
  (keputusan #6).
- Bundle size → **budget <300KB gzip + gate** (keputusan #3).
- Kunci jawaban di review: ikut kebijakan web yang sudah ada (tidak ada keputusan
  baru).
- Retry/backoff download: konstanta di app (belum perlu setting server).
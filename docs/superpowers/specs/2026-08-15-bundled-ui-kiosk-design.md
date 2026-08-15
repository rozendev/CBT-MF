# Bundled UI Kiosk — Design Spec

Tanggal: 2026-08-15
Status: Approved by user (design discussion)

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
- Distribusi bundle/APK antar perangkat bisa lewat aplikasi sharing offline
  (Bluetooth/ShareIt) — tradeoff jaringan saat first-load jadi manageable.
- Web path (Windows) tetap apa adanya: halaman server asli tidak diubah.

## Architecture

```
┌─ CBT-MF (server) ──────────────────────────────┐
│ 1. cbt:build-ui-bundle (command)               │
│    → writes writable/ui-bundle/ (zip + files)  │
│      login.html dashboard.html exam.html       │
│      results.html review.html assets/          │
│      manifest.json {version: sha256}           │
│ 2. /api/kiosk/config + ui_bundle{version,url}  │
│ 3. API data (semua cookie session):            │
│    POST /login (CORS+JSON)                     │
│    api/student/exams (new)                     │
│    api/exam/start (new)                        │
│    api/exam/init | autosave | auto-sync |      │
│    finish | check-score | report-cheat (ada)   │
│    api/student/results + review (new)          │
│ 4. Halaman server asli TIDAK diubah            │
└────────────────────────────────────────────────┘
        ▲ zip bundle (sekali) + JSON (sering)
┌─ cbt-kiosk-app (Android) ──────────────────────┐
│ UiBundleManager:                               │
│   GET config → hash sama? pakai cache :        │
│   download zip → verify sha256 → extract       │
│ WebView via WebViewAssetLoader:                │
│   https://appassets.androidplatform.net/...    │
│ CookieManager tetap simpan cookie domain       │
│ kiosk-integration.js script PERTAMA tiap page  │
│ KioskGuard/Heartbeat/Security/CommsBridge:     │
│   TIDAK diubah                                 │
└────────────────────────────────────────────────┘
```

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
| `/login` (rute ada) | POST | + CORS headers + response JSON; tetap set session cookie |
| `/api/student/exams` | GET | daftar ujian tersedia utk user (dipindah dari render halaman dashboard) |
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

### 3. App Android — UiBundleManager

- `refreshBundle()`: GET `/api/kiosk/config` → baca `ui_bundle.version` →
  bandingkan hash tersimpan di `filesDir/ui-bundle/manifest.json` →
  sama → pakai lokal; beda → download → verifikasi sha256 (header/`Content-SHA256`)
  → ekstrak → tulis.
- Kegagalan download: retry exponential (config: max 3 percobaan, backoff),
  lalu screen error informatif. Hash korup: hapus + re-download.
- WebView: `WebViewAssetLoader` + `assetsPathHandler` menunjuk
  `filesDir/ui-bundle`; navigasi antar halaman bundle via link internal
  (`/login.html` dst). CookieManager tetap aktif untuk domain server.

### 4. Alur runtime

1. Setup screen (existing): input server URL → enforce HTTPS → simpan prefs.
2. `fetchServerKioskConfig` → validasi min app version + root policy (existing).
3. `UiBundleManager.refreshBundle()` → splash/loading dengan progress.
4. WebView load `https://appassets.androidplatform.net/login.html` →
   `kiosk-integration.js` load pertama (bridge aktif sejak awal).
5. Login form POST ke server (CORS+JSON) → cookie session di-set → navigasi
   internal ke `dashboard.html`.
6. Dashboard fetch `/api/student/exams` → tampil daftar → pilih → `exam/start`.
7. `exam.html` fetch `/api/exam/init` → render soal (Alpine, pola static exam) →
   autosave hanya saat jawaban berubah (`scheduleAutoSync`/change-driven) →
   `finish` → `results.html` → `review.html`.

## Error Handling

| Scenario | Behavior |
|---|---|
| Bundle belum ada + jaringan gagal saat download | Retry exponential → error screen; tidak ada fallback web |
| Hash manifest beda | Re-download bundle |
| Zip korup / ekstrak gagal | Hapus dir → re-download |
| Server unreachable padahal bundle sudah ada | Halaman tampil; data fetch gagal → error panel per-halaman dgn tombol retry |
| Sesi kedaluwarsa (API 401) | Redirect internal ke login.html |

## Anti-negatif (hal yang TIDAK dikerjakan)

- Web views asli tidak diubah (jalur Windows tetap).
- Tidak ada fallback web di app (UI bundle adalah satu-satunya sumber UI).
- Tidak mengubah kiosk guard/heartbeat/security/comms bridge.
- Autosave-per-navigasi TIDAK berlaku: hanya saat jawaban berubah.

## Testing

1. Lint PHP via docker `ex_php` + `node --check` semua JS bundle.
2. Build bundle via CLI → buka halaman-halamannya di browser (python http.server
   + CORS) untuk verifikasi alur data + render.
3. `gradle assembleDebug` di `cbt-kiosk-app` (SDK ada di `/opt/android-sdk`).
4. Manual di device: first load (download), update bundle (hash berubah), offline
   (bundle ter-cache), kiosk lock, exit flow.

## Open Items / Keputusan Lanjut

- Format verifikasi hash zip (header `Content-SHA256` vs hash di manifest) —
  ikut pola download+verify yang paling sederhana saat implementasi.
- Kunci jawaban di review: ikut kebijakan web yang sudah ada (tidak ada keputusan
  baru).
- Retry/backoff config: konstanta di app (belum perlu setting server).
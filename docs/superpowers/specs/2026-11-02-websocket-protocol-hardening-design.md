---
title: Pengerasan Protokol, Isolasi Event, Antrean Audit Async, & Siklus Soket WebSocket (PR 1)
date: 2026-11-02
status: approved
approach: Test-Room & Proctor-Only Routing + Async Redis Audit Queue (Drained by kiosk:prune) + Unified Token Indexing + Server-Side Socket Revocation
target: Server CBT-MF (CodeIgniter 4, Ratchet WebSocket, Clue React Redis, Cron KioskPrune) & Klien (Exam Web/Static/Kiosk Bundle, Proctor Live)
---

# Pengerasan Protokol, Isolasi Event, & Siklus Soket WebSocket (PR 1)

## 1. Tujuan & Cakupan

Menutup enam celah keandalan, kebocoran data, dan konsistensi sesi pada lapisan aplikasi WebSocket (`WS-01`, `WS-03`, `WS-04`, `WS-06`, `WS-07`, `WS-09`) tanpa mengubah skema database maupun *build image* Docker (perubahan level container `WS-02`, `WS-05`, `WS-08`, `WS-10` dipisahkan ke PR 2):

1. **`WS-01` — Isolasi `sync_mode` & `extend_time` per `test_id`:**
   - Menghentikan penyiaran `sync_mode` lintas ujian agar pembuatan/penghapusan halaman statis pada satu ujian tidak pernah mengalihkan peserta di ujian lain yang sedang berjalan bersamaan.
   - Menyeragamkan perhitungan batas waktu baru pada `extend_time` di `public/assets/exam-app.js` agar dihitung dari `this.beginTimeMs` yang disimpan saat `startTimer()` (bukan `Date.now()`).
2. **`WS-03` — Penghapusan Query MariaDB Sinkronus dari Event Loop WebSocket:**
   - Mencabut seluruh pemanggilan `\Config\Database::connect()` dari `WebSocketServerHandler::onMessage()`.
   - Membatasi ukuran payload masuk, memverifikasi peran soket pengirim (`student` terautentikasi), menerapkan *rate-limit* per koneksi, lalu mengantrekan catatan `kiosk_event` / `kiosk_status` secara async ke Redis (`kiosk_event_queue`) untuk dikuras secara batch (`insertBatch`) oleh `php spark kiosk:prune` tiap 60 detik.
3. **`WS-04` — Isolasi `proctor_alert` & `proctor_report_alert` dari Klien Siswa + Sanitasi XSS:**
   - Memastikan event internal pengawas tidak pernah dikirim ke `$this->clients` (siswa) dan hanya diteruskan ke ruang pengawas terkait (`$proctorRooms[$testId]`) tanpa pembungkusan berlapis ganda yang sebelumnya menghambat notifikasi `auto_submit`.
   - Menutup injeksi HTML/XSS pada modal laporan pengawas di `app/Views/proctor/live.php`.
4. **`WS-06` — Penyatuan Penerbitan & Pencabutan Token WebSocket:**
   - Menyatukan pencetakan `ws_student_token` di `ExamController::take()` dan `ExamApiController::init()` agar selalu menjaga invarian 1 token aktif per `attempt_id` (`attempt_ws_token:{attemptId}`), menyertakan `auth_version`, serta mencabut token attempt aktif saat akun dikunci (`ProctorAction::lockAccount()`).
5. **`WS-07` — Pemutusan Paksa Soket dari Server (`$conn->close()`) & Indeks `user_id` Pengawas:**
   - Memverifikasi `user_auth_state:{userId}` (`is_active` & `auth_version`) saat `onOpen()` bila tersedia di Redis, mengindeks koneksi pengawas per `user_id` (`$proctorUserConnections[$userId]`), dan memutus soket secara aktif dari sisi server ketika menerima event terminal (`kick`, `ban`, `ejected`).
6. **`WS-09` — Penutupan *Race Condition* Async `onOpen()` vs `onClose()`:**
   - Mencegah soket yang sudah ditutup sebelum pembacaan token async Redis selesai tetap terdaftar permanen di indeks ruangan/pengguna atau memicu `student_connected` palsu.

---

## 2. Arsitektur & Komponen

### 2.1 Manajemen Token Terpadu (`App\Libraries\WebSocketToken`)
Kelas koordinasi token WebSocket di Redis (TTL `14400` detik = 4 jam):
- `issueStudentToken(int $userId, int $attemptId, int $testId, int $authVersion = 1): string`
  - Membaca `attempt_ws_token:{attemptId}`; bila ada token sebelumnya, menghapus `ws_student_token:{oldToken}`.
  - Menyimpan `ws_student_token:{token}` berisi JSON `{user_id, attempt_id, test_id, auth_version}` dan memperbarui indeks balik `attempt_ws_token:{attemptId}`.
- `issueProctorToken(int $userId, int $testId, int $authVersion = 1): string`
  - Menyimpan `ws_proctor_token:{token}` berisi JSON `{user_id, test_id, auth_version}`.
- `revokeAttemptToken(int $attemptId): void`
  - Menghapus `ws_student_token:{token}` berdasarkan `attempt_ws_token:{attemptId}` beserta kunci indeksnya.
- `revokeUserAttemptTokens(int $userId, array $attemptIds): void`
  - Mencabut token WebSocket seluruh attempt milik pengguna saat akun dikunci (`ProctorAction::lockAccount()`).
- `isTokenAllowedByAuthState(array $tokenData, ?array $authState): bool` (fungsi murni tanpa I/O)
  - Jika `$authState === null` (cache belum terisi di Redis), mengembalikan `true` (mengandalkan keabsahan kunci token di Redis tanpa menyentuh MariaDB sinkronus).
  - Jika `$authState` tersedia, wajib `(int)($authState['is_active'] ?? 0) === 1` dan `normalizeVersion($tokenData['auth_version'] ?? 1) >= normalizeVersion($authState['auth_version'] ?? 1)`.

### 2.2 Antrean Audit Kiosk (`App\Libraries\KioskEventQueue`)
Memisahkan validasi pesan masuk dan pengurasan batch ke tabel `exam_kiosk_events` (0 perubahan skema DB):
- Konstanta batas:
  - `QUEUE_KEY = 'kiosk_event_queue'`
  - `QUEUE_MAX_LEN = 5000`
  - `QUEUE_TTL_SECONDS = 7200`
  - `MAX_INCOMING_BYTES = 4096`
  - `MAX_DETAILS_BYTES = 2048`
  - `RATE_WINDOW_SECONDS = 10`
  - `RATE_MAX_EVENTS = 10`
  - `DRAIN_BATCH_SIZE = 500`
- `prepareIncomingEvent(array $payload, int $testId, int $studentId, int $nowTs): ?array` (murni tanpa I/O):
  - Hanya menerima `action` bernilai `'kiosk_event'` atau `'kiosk_status'`.
  - Menentukan `event_type` (dipotong maksimal 50 karakter UTF-8 utuh memakai `mb_substr` sesuai `VARCHAR(50)`).
  - Menserialisasi `data` menjadi JSON; menolak (`null`) bila gagal di-encode atau melebihi `MAX_DETAILS_BYTES` (menjaga kolom `JSON` MariaDB bebas dari nilai tidak valid maupun payload bengkak).
  - Mengembalikan struktur siap-simpan `{exam_session_id, student_id, event_type, event_details, created_at}` menggunakan `date('Y-m-d H:i:s', $nowTs)` asli saat kejadian diterima.
- `drainToDatabase(?\Redis $redis = null, int $maxItems = self::DRAIN_BATCH_SIZE): int`:
  - Dipanggil dari `App\Commands\KioskPrune::run()`.
  - Menarik hingga `$maxItems` elemen dari kiri list Redis (`lPop`), memvalidasi strukturnya, lalu menulis sekaligus via `$db->table('exam_kiosk_events')->insertBatch($rows)` dalam blok `try/catch` (kegagalan DB dicatat ke `log_message('error', ...)` tanpa menggagalkan proses pemangkasan kunci `kiosk_live:*`).

### 2.3 Handler Daemon WebSocket (`App\Libraries\WebSocketServerHandler`)
1. **Struktur Indeks Koneksi di Memori:**
   - `$clients` (`\SplObjectStorage`): seluruh soket terbuka.
   - `$userConnections[$userId]`: daftar soket ujian siswa milik `$userId`.
   - `$testConnections[$testId]`: daftar soket ujian siswa yang sedang mengerjakan ujian `$testId`.
   - `$proctorRooms[$testId]`: daftar soket pengawas untuk ujian `$testId`.
   - `$proctorUserConnections[$userId]`: daftar soket pengawas milik akun `$userId` (lintas tab/ujian).
2. **Alur Handshake Async & Penjaga Race (`onOpen` & `onClose`):**
   - `onOpen()` menyetel `$conn->closed = false`. `onClose()` menyetel `$conn->closed = true` dan melepas `$conn` dari `$clients` serta seluruh indeks yang sesuai (`$userConnections`, `$testConnections`, `$proctorRooms`, `$proctorUserConnections`).
   - Baik cabang `proctor_token` maupun `ws_token` membaca token dari `$this->asyncRedis->get(...)`, lalu (bila `user_id > 0`) memeriksa `user_auth_state:{userId}` via `$this->asyncRedis->get(...)` menggunakan `WebSocketToken::isTokenAllowedByAuthState()`.
   - Di awal setiap callback async `.then()`, handler memeriksa `if (!empty($conn->closed) || !$this->clients->contains($conn)) { return; }`. Bila lolos, barulah properti `$conn->role` (`'proctor'` / `'student'`), `$conn->userId`, `$conn->testId`, `$conn->attemptId` disetel dan `$conn` dimasukkan ke indeks terkait.
3. **Penanganan Pesan Masuk (`onMessage`):**
   - Mengabaikan frame dengan `strlen((string) $msg) > KioskEventQueue::MAX_INCOMING_BYTES` sebelum `json_decode`.
   - Menangani `event === 'pong'` dengan memperbarui `$from->lastPong = time()`.
   - Untuk `action === 'kiosk_event' | 'kiosk_status'`:
     - Wajib `($from->role ?? '') === 'student'`, `!empty($from->userId)`, dan `!empty($from->testId)`.
     - Memeriksa penghitung jendela waktu pada properti koneksi (`$from->kioskRateWindowStart`, `$from->kioskRateCount`): maksimal `10` event per `10` detik per koneksi; kelebihannya dijatuhkan tanpa menyentuh Redis atau ruang pengawas.
     - Memvalidasi lewat `KioskEventQueue::prepareIncomingEvent(...)`. Bila sah, mendorong ke Redis secara async (`rpush`, `ltrim`, `expire` pada `$this->asyncRedis` dengan penangkap error non-fatal) dan meneruskan ke `$this->broadcastToProctors(..., $from->testId)`.
4. **Routing & Pemutusan Soket Aktif (`broadcastEvent`):**
   - **Event khusus pengawas (`proctor_alert`, `proctor_report_alert`):**
     - Tidak pernah dikirim ke soket siswa.
     - Mengekstrak payload inti (`$inner = isset($eventData['data']) && is_array($eventData['data']) ? $eventData['data'] : $eventData`) dan `$tId = $eventData['test_id'] ?? ($inner['test_id'] ?? null)`.
     - Untuk `proctor_report_alert`, mempertahankan nama event `'proctor_report_alert'` di dalam data siaran pengawas agar cabang handler di `proctor/live.php` menerima struktur tunggal yang konsisten; begitu pula `ban`, `auto_submit`, dan `modified_browser`.
   - **Event tingkat pengguna / attempt (`kick`, `ban`, `ejected`, `cred_updated`, `finished`, dll.):**
     - Mengirim frame ke soket siswa target (`$userConnections[$userId]` atau pencocokan `$attemptId`).
     - Untuk event terminal (`in_array($event, ['kick', 'ban', 'ejected'], true)`):
       - Menutup soket siswa yang ditargetkan (`$conn->close()`) setelah mengirim frame pesan.
       - Bila terdapat `$userId` pada `'kick'` atau `'ban'` (pencabutan sesi/akun staf maupun pengguna), turut mengirim frame `kick` dan memanggil `$conn->close()` pada seluruh soket di `$proctorUserConnections[$userId]`.
     - Meneruskan ke `broadcastToProctors()` kecuali untuk daftar eksklusi (`connected`, `heartbeat`, `student_connected`, `student_disconnected`, `cred_updated`, dan `kick` pada akun yang tidak terkait `test_id` siswa bila tidak relevan — catatan: `kick`/`ban`/`ejected` peserta tetap diteruskan sesuai perilaku saat ini).
   - **Event tingkat ujian (`sync_mode`, `extend_time`):**
     - Dikirim **hanya** ke `$this->testConnections[(int) $testId]` apabila `test_id` valid (`> 0`). Event tanpa `user_id`, `attempt_id`, maupun `test_id` tidak lagi disiarkan buta ke seluruh `$this->clients` (dicatat sebagai peringatan log).

### 2.4 Perubahan Produsen & Klien
- **`StaticExamController::generate()` & `delete()`:** menyertakan `'test_id' => (int) $testId` pada payload `sync_mode`.
- **`ExamController::take()` & `ExamApiController::init()`:** memakai `WebSocketToken::issueStudentToken()` dengan `auth_version` pengguna saat ini.
- **`Proctor\LiveController::monitor()`:** memakai `WebSocketToken::issueProctorToken()` dengan `auth_version` pengguna saat ini.
- **`ProctorAction::lockAccount()`:** mengambil daftar `id` dari `test_attempts` berstatus `[0, 1, 2]` milik `$userId` sebelum dikunci, lalu memanggil `WebSocketToken::revokeUserAttemptTokens()`.
- **`public/assets/exam-app.js` & `app/Views/student/exam/take.php`:**
  - Pada `sync_mode`, wajib memverifikasi `Number(d.test_id) === Number(EXAM_CONFIG.testId)` (dan padanannya di `take.php`) sebelum berpindah halaman.
  - Pada `extend_time` di `exam-app.js`, menyimpan `this.beginTimeMs` saat `startTimer()` lalu menghitung `this.endTimeMs = this.beginTimeMs + (this.durationMinutes * 60 * 1000)` bila `this.beginTimeMs > 0` (dengan fallback `window.__examData?.beginTimeMs`), sejajar dengan `take.php`.
- **`app/Views/proctor/live.php`:**
  - Menambahkan helper `escapeHtml()` untuk seluruh field yang disisipkan ke `Swal.fire({ html: ... })` pada `handleProctorReport()`.
  - Menangani `event === 'kick'` atau kegagalan koneksi beruntun (mis. > 5 kali tutup tanpa pernah menerima `'connected'`) dengan menghentikan timer rekoneksi dan mengarahkan ke `base_url('login')`.
  - Memastikan handler `proctor_alert` mengenali struktur datar maupun bersarang untuk `ban`, `auto_submit`, `modified_browser`, dan `proctor_report_alert`.

---

## 3. Analisis Dampak Lintas Fitur & Evaluasi Trade-off

### Dampak Lintas Fitur
- **Database (`exam_kiosk_events` & `test_attempts`):** Tidak ada perubahan skema/migrasi. Koneksi MariaDB dari proses daemon `websocket` dihilangkan sepenuhnya; penulisan tabel `exam_kiosk_events` berpindah ke `insertBatch` di `KioskPrune` (container `cron`).
- **Redis:** Menambah kunci list sementara `kiosk_event_queue` (dibatasi `LTRIM` maks 5.000 item dan TTL 7200 detik), menyeragamkan `attempt_ws_token:{attemptId}` untuk mode ujian dinamis maupun statis/bundle, serta membaca `user_auth_state:{userId}` secara async saat handshake WS.
- **Klien Ujian & Bundle Kiosk:** Menyentuh `src/public/assets/exam-app.js`, sehingga setelah merge diperlukan `php spark cbt:build-ui-bundle` (dan regenerasi berkas ujian statis bila ada yang sedang dipakai).

### Batas Perlindungan & Trade-off
1. **Antrean Audit Async (`kiosk_event_queue`) vs Tulis Langsung:**
   - *Trade-off:* Event loop WebSocket terlindungi dari pembekuan I/O MariaDB dan lonjakan kueri tunggal diubah menjadi `insertBatch`, dengan konsekuensi baris baru muncul di tabel SQL `exam_kiosk_events` paling lambat 60 detik kemudian, serta potensi kehilangan log pada jendela <60 detik bila Redis berhenti mendadak sebelum sempat dikuras `cron` (sedangkan peringatan langsung di layar pengawas tetap terkirim 0 detik).
2. **Pemeriksaan `user_auth_state:{userId}` di `onOpen()` Hanya dari Redis:**
   - *Batas & Trade-off:* Demi menjaga pantangan kueri DB sinkronus di daemon WebSocket, `onOpen()` hanya menolak token berdasarkan `auth_version` / `is_active` selama snapshot `user_auth_state:{userId}` ada di Redis (yang selalu ditulis saat login maupun saat pencabutan di `UserController`/`ProctorAction`). Jika cache tersebut ter-evict dari Redis sebelum TTL token 4 jam habis, penolakan tetap dijaga oleh penghapusan eksplisit `ws_student_token` pada `eject` dan `lockAccount`.
3. **Batas Ukuran Frame di PR 1 vs PR 2:**
   - *Batas:* Di PR 1, `onMessage()` menjatuhkan pesan > 4 KB sebelum `json_decode()`, namun alokasi penyangga frame di lapisan RFC6455 Ratchet (`MessageBuffer`) masih mengikuti `memory_limit` proses PHP CLI yang baru akan dibatasi keras pada PR 2 (`Dockerfile` / `docker-compose.yml` / `WebSocketServe`).

---

## 4. Rencana Pengujian

1. **PHPUnit (`src/tests/Realtime/`):**
   - Unit test murni `KioskEventQueueTest`: batas ukuran, pemotongan `event_type` 50 karakter, validasi JSON `event_details`, dan pengurasan batch dengan mock/stub.
   - Unit test `WebSocketTokenTest`: rotasi 1 token per attempt, pencabutan banyak attempt saat lock akun, dan evaluasi `isTokenAllowedByAuthState()`.
   - Unit test `WebSocketServerHandlerTest` (memakai double `ConnectionInterface` & fake promise Redis):
     - Isolasi `sync_mode` & `extend_time` hanya ke `$testConnections[$testId]` (`WS-01`).
     - Isolasi `proctor_alert` & `proctor_report_alert` agar 0 frame bocor ke soket siswa dan tiba utuh di `$proctorRooms[$testId]` (`WS-04`).
     - Penolakan `kiosk_event` dari soket belum terverifikasi / soket proctor, pembatasan laju 10 event / 10 detik, serta antrean async tanpa koneksi DB (`WS-03`).
     - Pemutusan `$conn->close()` pada soket siswa & soket pengawas saat menerima `kick`/`ban`/`ejected` (`WS-07`).
     - Simulasi `onClose()` sebelum promise Redis `onOpen()` selesai memastikan 0 koneksi bocor di `$userConnections`, `$testConnections`, dan `$proctorRooms` (`WS-09`).
2. **Node Test (`src/tests/Resilience/`):**
   - Pengujian klien `exam-app.js` dan `take.php` memastikan `sync_mode` dari `test_id` berbeda diabaikan, `sync_mode` dengan `test_id` cocok dijalankan, dan `extend_time` di `exam-app.js` memakai `beginTimeMs`.
   - Pengujian script `proctor/live.php` memastikan sanitasi karakter HTML pada `handleProctorReport()`, penanganan `auto_submit` / `proctor_alert`, serta penghentian rekoneksi saat menerima `kick`.

# Rancangan: Kendali Real-Time Kiosk (URL WebSocket, Kanal Bundle, Aksi Pengawas)

Tanggal: 2026-08-24
Status: disetujui, siap direncanakan

## Masalah

Tiga masalah bertumpuk, dan yang ketiga tidak mungkin dikerjakan tanpa dua yang pertama.

**1. Routing WebSocket tersebar dan tidak bisa dikonfigurasi.**
Ada mekanisme setting `websocket_url`, tapi barisnya tidak pernah ada di tabel
`settings`, sehingga tidak pernah terpakai. Sebagai gantinya, cara menurunkan URL
ditulis ulang di banyak tempat: path `/ws` di `docker/nginx/default.conf:152`,
`FrontendConfig.php:88,91`, `SettingModel.php:64`, `exam-app.js:494`, dan
`take.php:1057`; port `8060` di `WebSocketServe.php:109`, `docker-compose.yml:67`,
`nginx default.conf:154`, `Proctor/LiveController.php:69`, plus pemetaan
`:8080`→`:8060` di `exam-app.js:492` dan `take.php:1055`. Sekolah dengan domain
atau reverse-proxy berbeda harus mengedit kode.

Ada juga bug berlapis di `SettingModel::getValue()`: saat baris setting tidak ada,
method `return $default` di baris 34 — **sebelum** blok auto-correction websocket di
baris 63. Panggilan pertama setelah cache 1 jam kedaluwarsa mengembalikan nilai
mentah tanpa koreksi, lalu meng-cache default milik pemanggil pertama untuk semua
pemanggil berikutnya.

**2. Bundle kiosk tidak pernah membuka WebSocket sama sekali.**
`initWebSocket()` dipanggil di `exam-app.js:421`, di dalam listener
`document.addEventListener('exam-data-loaded', ...)` (baris 385-423). Event itu
hanya di-dispatch di jalur non-bundle (`exam-app.js:198`). Bundle memakai
`kiosk_config_ready` dan mereplikasi sendiri sebagian isi listener di baris 369-383
— hanya nama siswa dan timer. Yang ikut tidak pernah jalan di Exambro:
`initWebSocket()`, `restoreLocalBackup()`, dan restore posisi soal terakhir.

Turunannya, `sendKioskWsEvent()` di `kiosk-integration.js:92` membaca
`window.examWebSocket || window.ws` — dua global yang **tidak pernah di-assign di
mana pun**, karena exam-app.js menyimpan socket di `this.ws` milik komponen Alpine.
Telemetri kiosk (`kiosk_started`, `exit_attempt`, `exit_denied`, `security_alert`,
`kiosk_failed`) diam-diam dibuang, di mode bundle maupun static.

**3. `/admin/kiosk/live` hanya bisa menonton.**
Halaman itu cuma punya `index` + `data`. Satu-satunya tindakan keras yang tersedia
ada di halaman lain (`/admin/suspend`, aksi ban). Pengawas yang melihat siswa
mencurigakan di monitoring harus pindah halaman, mencari siswanya, lalu mem-ban —
dan ban pun tidak menghentikan siswa mengerjakan (lihat bagian berikutnya).

**Prasyarat tersembunyi: status attempt `2` tidak ditegakkan.**
`TestAttemptModel:35` masih menganggap status 2 sebagai attempt aktif, dan
`ExamApiController:409-412` hanya menolak status `3` dan `4`. Siswa yang di-ban hari
ini masih bisa autosave. Tanpa menambal ini, tombol kick apa pun hanya kosmetik.

## Keputusan desain

| Pertanyaan | Keputusan |
|---|---|
| Lingkup perbaikan URL | Satu helper PHP + setting yang bisa diubah admin; path/port tetap punya default tapi hanya tertulis sekali |
| Efek kick di perangkat | Siswa dikeluarkan dari ujian, **lock task Android TIDAK dilepas** — keluar hanya lewat password pengawas |
| Perlu rebuild APK? | Tidak. Semua yang dibutuhkan sudah ada di sisi web/bundle |
| Bentuk UI pengawas | Satu menu "Aksi" per baris siswa berisi tiga pilihan: Keluarkan dari ujian, Kunci akun, Keluarkan & Kunci |
| Jalur penegakan kick | Server yang jadi otoritas (token dicabut + gerbang status 2). WebSocket hanya mempercepat perubahan layar |
| Kalau WebSocket mati | Jawaban ditolak sejak detik pertama; layar berubah paling lambat pada auto-sync berikutnya (≤60 detik) |

### Kenapa kick tidak melepas kunci perangkat

Melepas lock task pada siswa yang baru saja ketahuan curang justru memberinya
perangkat bebas. Ini juga bertentangan dengan aturan yang sudah berlaku di
`KioskController::canExit`: sekali ujian dimulai, satu-satunya jalan keluar adalah
password pengawas. Karena itu `eject` sengaja **mencabut** `ws_student_token`, yang
membuat `can-exit` menolak dan heartbeat native menjawab 401 — perangkat justru
makin terkunci, bukan terlepas.

### Kenapa `ejected` adalah event baru, bukan menumpang `kick`

Handler `kick` yang ada (`exam-app.js:520`) memanggil
`logoutAndRedirect(API + '/login')`. Di kiosk itu menendang WebView keluar dari
bundle menuju halaman login online — persis kebalikan dari yang diinginkan. Event
`ejected` dipisah supaya bundle bisa menampilkan overlay tanpa navigasi.

### Kenapa tiga pilihan aksi, bukan satu

Tiga skenario nyata: siswa ketahuan curang (keluarkan & kunci), perangkat macet dan
perlu di-restart (keluarkan saja — siswa masih bisa login lagi), akun bermasalah
(kunci saja). Kalau kick selalu ikut mengunci, pengawas kehilangan cara me-restart
siswa yang tidak bersalah.

## Arsitektur

### Bagian 1 — `App\Libraries\WebSocketUrl`

Satu-satunya tempat yang tahu cara menentukan URL WebSocket.

```
WebSocketUrl::resolve(): string
  1. baca setting `websocket_url`
  2. kosong atau mengandung 'localhost' -> turunkan dari base_url():
       skema  : https -> wss:, selain itu ws:
       host   : sama dengan base_url; host ber-':8080' dipetakan ke ':8060'
       path   : konstanta DEFAULT_PATH = '/ws'
  3. buang trailing slash, kembalikan
```

Konstanta `DEFAULT_PATH` dan `DEV_PORT_MAP` hidup di kelas ini saja.

**Konsumen yang dialihkan:** `FrontendConfig::websocketUrl()`,
`Student\ExamController:276`, `Admin\StaticExamController:176`,
`Proctor\LiveController:69`.

**Yang dicabut:** blok auto-correction websocket di `SettingModel:63-70`. Model
setting generik tidak seharusnya tahu soal URL WebSocket; mencabutnya sekaligus
menutup bug early-return di baris 34, karena tidak ada lagi koreksi yang bisa
terlewat.

**Derivation duplikat di klien dihapus:** `exam-app.js:487-495`,
`take.php:1051-1058`, `proctor/live.php:168-172`. Klien memakai nilai dari server
apa adanya.

**Bundle yang harus tetap jalan offline** mendapat dua lapis:
- `UiBundleBuilder` memanggang `wsUrl` ke `bundle/_head.php` sebagai
  `window.KIOSK_WS_URL` — default saat perangkat offline.
- `/api/exam/init` menambah field `ws_url` — override saat online, sehingga bundle
  yang sudah terpasang ikut perubahan setting tanpa rebuild.
- `bundle/exam.php:191` berhenti menulis `wsUrl: ''`, jadi
  `wsUrl: j.ws_url || window.KIOSK_WS_URL || ''`.

**Migration + UI:** migration menyisipkan baris `websocket_url` bernilai kosong
(kosong berarti auto). `SettingController` menambah entri
`'websocket_url' => ['group' => 'system', 'type' => 'string']`, dan tab Sistem di
`admin/settings/index.php` mendapat field teks dengan hint berisi nilai
auto-derive saat ini.

### Bagian 2 — Kanal WebSocket bundle

Isi listener `exam-data-loaded` (exam-app.js:385-423) diekstrak menjadi
`applyExamData(data)`:

- jalur non-bundle: listener memanggil `applyExamData()` — perilaku tidak berubah
- jalur bundle: `init()` memanggil `applyExamData()` setelah `__bundleConfigPromise`
  selesai, menggantikan replikasi parsial di baris 369-383

Ini memulihkan tiga hal sekaligus di kiosk: WebSocket, `restoreLocalBackup()`, dan
restore posisi soal terakhir.

`connectWebSocket()` menyetel `window.examWebSocket = this.ws` (dan
membersihkannya di `onclose`), sehingga `sendKioskWsEvent()` berhenti jadi no-op.
Ini memperbaiki telemetri di mode static juga, bukan hanya bundle.

### Bagian 3 — Penegakan status attempt `2`

Penolakan eksplisit ditambahkan di gerbang tulis:
`ExamApiController::autosave`, `autoSync`, `finish`, `checkScore`, `init`, dan
padanannya di `Student\ExamController`.

- respon gerbang tulis: `{status:'kicked', reason:'locked', message:...}` — klien
  sudah menangani `kicked`
- respon `init` untuk attempt status 2: `{status:'error', reason:'ejected'}`
  sehingga bundle menampilkan layar dikeluarkan, bukan soal

Semantik status 2 tidak berubah: `SuspendController::_doRelease()` tetap
mengembalikan attempt ber-`cheat_strikes > 0` ke keadaan semula. Yang berubah hanya
bahwa status itu sekarang benar-benar ditegakkan.

### Bagian 4 — `App\Libraries\ProctorAction`

Dua primitif, dipakai bersama oleh `SuspendController` dan kiosk live.

```
eject(int $testId, int $userId, int $actorId, string $reason): array
  - attempt aktif milik user pada test itu -> status 2
  - cabut ws_student_token milik attempt tersebut
  - publish 'exam_events': {event:'ejected', user_id, attempt_id, test_id, message}
  - audit: activity_logs + exam_kiosk_events (event_type='proctor_eject')

lockAccount(int $userId, int $actorId): void
  - logika SuspendController::_doBan dipindahkan ke sini apa adanya
  - SuspendController::_doBan menjadi pemanggil, tidak ada dua salinan
```

**Pencabutan token butuh indeks balik.** Sekarang Redis hanya memetakan
`ws_student_token:{token}` -> data. `ExamApiController::init` mulai menulis
`attempt_ws_token:{attemptId}` -> token dengan TTL yang sama saat mencetak token,
sehingga `eject` bisa menghapus keduanya. Efeknya disengaja: heartbeat native
menjawab 401 dan `can-exit` menolak, jadi perangkat tetap terkunci.

Indeks balik itu saja belum cukup. `init` mencetak token BARU setiap kali dipanggil,
dan token lama tetap sah sampai TTL 4 jam habis — siswa yang halamannya sempat
dimuat ulang beberapa kali meninggalkan beberapa token hidup, dan `eject` hanya akan
mencabut yang terakhir. Karena itu `init` harus menegakkan invarian **satu token per
attempt**: sebelum menyimpan token baru, hapus token yang sedang ditunjuk
`attempt_ws_token:{attemptId}`. Tanpa ini, kick bisa dilewati hanya dengan memakai
tab atau sesi lama.

**Endpoint:** `POST admin/kiosk/live/action`, body `{test_id, user_id, action}`
dengan `action` salah satu dari `eject`, `lock`, `eject_lock`. Filter admin dan
CSRF mengikuti rute admin lain.

**UI `/admin/kiosk/live`:** kolom Aksi berisi dropdown tiga pilihan; konfirmasi
memuat nama siswa; hasil ditampilkan sebagai toast; baris di-refresh dari
`live-data` setelah aksi.

**Sisi siswa — event `ejected` di `exam-app.js`:**
- tutup WebSocket, hentikan timer, batalkan antrean autosave yang tertunda
- mode bundle: tampilkan overlay penuh "DIKELUARKAN", **tanpa** memanggil
  `logoutAndRedirect()` dan **tanpa** menyentuh `CBTKioskRequestExit()` — lock task
  tetap aktif
- mode web non-kiosk: seperti handler `kick` yang ada (Swal lalu redirect ke login)

## Alur data

```
Pengawas menekan "Keluarkan & Kunci" di /admin/kiosk/live
        |
        v
POST admin/kiosk/live/action {test_id, user_id, action:'eject_lock'}
        |
        +-> ProctorAction::eject()
        |     - test_attempts.status = 2
        |     - DEL ws_student_token:{token}, attempt_ws_token:{attemptId}
        |     - PUBLISH exam_events {event:'ejected', ...}
        |     - audit
        |
        +-> ProctorAction::lockAccount()
              - users.is_active = 0, attempt terkunci
              - user_login_token = BANNED, sesi Redis dihapus
              - PUBLISH exam_events {event:'ban', ...}
        |
        v
websocket:8060 (subscriber Redis) -> broadcastEvent -> koneksi siswa
        |
        v
exam-app.js handler 'ejected' -> overlay DIKELUARKAN, kiosk tetap terkunci

Jalur cadangan bila WebSocket mati:
  autosave/auto-sync berikutnya -> gerbang status 2 -> {status:'kicked'}
  -> klien menampilkan layar yang sama (<=60 detik)
```

## Penanganan kegagalan

| Kegagalan | Perilaku |
|---|---|
| Redis mati saat eject | Penegakan inti (menulis `status = 2` ke DB) dijalankan LEBIH DULU dan tetap berlaku. Pencabutan token dan publish adalah usaha terbaik: kegagalannya dicatat ke log dan dilaporkan ke pengawas sebagai peringatan ("siswa sudah dikunci, tetapi perintah real-time gagal terkirim"), bukan membatalkan aksi. Aksi tidak pernah dilaporkan sukses penuh kalau Redis gagal |
| Siswa tidak punya attempt aktif | `eject` tidak mengubah apa pun dan mengembalikan pesan bahwa tidak ada ujian berjalan; `lock` tetap bisa dijalankan sendiri |
| WebSocket mati / WebView hang | Jawaban ditolak sejak detik pertama oleh gerbang status 2; layar berubah pada auto-sync berikutnya |
| Perangkat offline total | Tidak ada jawaban yang sampai ke server; saat online kembali, gerbang menolak dan layar berubah |
| Token sudah dicabut lalu siswa reload | `init` mengembalikan `reason:'ejected'`; bundle menampilkan overlay, bukan soal |
| Pengawas salah menekan | `_doRelease()` yang ada memulihkan akun dan attempt; tidak ada jalur pemulihan baru yang dibuat |

## Pengujian

- **PHPUnit (suite baru di `phpunit.xml.dist`):** `WebSocketUrl::resolve()` untuk
  matriks setting-kosong / setting-terisi / localhost / host ber-`:8080` / https vs
  http; `ProctorAction` untuk efek pada status attempt, pencabutan token, dan audit.
- **Harness browser** seperti yang dipakai saat membedah bug bank soal: bundle asli
  dilayani lokal dengan stub `/api/exam/init`. Assert `ws.readyState === OPEN` dan
  `window.examWebSocket` terisi di mode bundle; assert overlay `ejected` muncul dan
  `location.href` tidak berubah.
- **Tes gerbang status 2:** autosave pada attempt status 2 harus menjawab `kicked`.
- **Tes perangkat manual** oleh pemilik repo, dengan instruksi bernomor.

## Urutan pengerjaan

1. Commit perbaikan `qKey` yang masih menggantung di working tree (terpisah, sudah terverifikasi)
2. Bagian 1 — verifikasi URL hasil `resolve()` identik dengan perilaku lama di keempat konsumen
3. Bagian 2 — harness browser membuktikan WebSocket terbuka di bundle
4. Bagian 3 — gerbang status 2
5. Bagian 4 — aksi pengawas, end-to-end

## Di luar lingkup

- Jalur perintah lewat response heartbeat native (butuh rebuild APK). Kontraknya
  tidak didefinisikan sekarang; kalau nanti dibutuhkan, itu pekerjaan terpisah.
- Membuat path dan port WebSocket ikut dibaca oleh `nginx.conf` dan
  `docker-compose.yml` dari `.env`.
- Perubahan apa pun pada `cbt-kiosk-app`.

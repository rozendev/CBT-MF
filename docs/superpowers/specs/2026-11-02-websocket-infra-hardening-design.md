---
title: Pengerasan Infrastruktur WebSocket Compose/VPS (PR 2)
date: 2026-11-02
status: awaiting-spec-review
branch: fix/websocket-infra-hardening
base: main@05fca1d
scope: WS-02, WS-05, WS-08, WS-10
approach: Ratchet dengan adapter pembatas payload, ext-ev, resolver Redis bersama, dan proxy Nginx
---

# Pengerasan Infrastruktur WebSocket Compose/VPS (PR 2)

## 1. Keputusan, tujuan, dan batas cakupan

User menyetujui pendekatan konfigurasi container ditambah adapter Ratchet di kode aplikasi. User kemudian mengeluarkan Railway sepenuhnya dari cakupan: deployment tersebut hanya eksperimen dan bukan use-case asli. Spec ini menargetkan Docker Compose pada VPS, termasuk akses LAN dan akses melalui Cloudflare Tunnel yang sudah tersedia.

Keputusan jalur koneksi sudah final: klien browser/kiosk memakai port aplikasi dan path `/ws`; Nginx meneruskan koneksi ke daemon `websocket:8060` di jaringan internal `ujian_network`. Tidak diperlukan keputusan port baru.

Tujuannya adalah membatasi alokasi payload masuk sebelum autentikasi Redis selesai, menghapus ketergantungan loop bawaan pada `stream_select`, menyamakan resolusi endpoint Redis penerbit/penerima token, dan menjalankan daemon sebagai UID/GID `33:33`.

Yang masuk cakupan:

- WS-02: batas memori PHP/container dan batas frame/pesan masuk pada parser RFC6455.
- WS-05: pemasangan `ext-ev`, visibilitas loop aktif, serta descriptor dan slot koneksi Nginx.
- WS-08: helper konfigurasi Redis bersama dan penanganan error client async.
- WS-10: identitas daemon, entrypoint Compose, URL/proxy klien, dan validasi Origin handshake yang kompatibel dengan kiosk Exambro.

Yang di luar cakupan:

- Semua perubahan, build, deployment, dan verifikasi Railway; jangan menyunting [Dockerfile.railway](/home/rozen/ARGON/CBT-MF/Dockerfile.railway), [docker/railway/](/home/rozen/ARGON/CBT-MF/docker/railway/), [RAILWAY.md](/home/rozen/ARGON/CBT-MF/RAILWAY.md), atau [railway.json](/home/rozen/ARGON/CBT-MF/railway.json) pada PR ini.
- Penggantian/fork stack WebSocket, perubahan berkas [src/vendor/](/home/rozen/ARGON/CBT-MF/src/vendor/), skema DB, format event, TTL token, atau perubahan antrean audit PR 1.
- Sisa roadmap AUTH, perubahan filter CORS/CSRF HTTP, pergantian identitas seluruh service, dan perbaikan massal kepemilikan volume.
- Pembatasan jumlah koneksi, antrean output klien lambat, dan penanganan seluruh bentuk kehabisan sumber daya.

Trade-off pendekatan: adapter aplikasi mempertahankan stack dan protokol yang sudah dipakai, tetapi membutuhkan pengujian integrasi dengan versi Ratchet dalam lockfile. Konfigurasi saja lebih sederhana tetapi masih membiarkan frame sekitar 64 MB ketika `memory_limit=256M`; fork vendor memberi kendali lebih besar dengan beban pemeliharaan lebih tinggi.

## 2. WS-02: memori proses, container, dan parser

### 2.1 Anggaran memori

- Command service `websocket`: `php -d memory_limit=256M spark websocket:serve`, dalam bentuk argv agar PHP menjadi proses utama tanpa shell tambahan.
- [WebSocketServe::run()](/home/rozen/ARGON/CBT-MF/src/app/Commands/WebSocketServe.php:22) memeriksa batas PHP sebelum membuat loop, client Redis, maupun server HTTP. Jika `ini_get('memory_limit')` bernilai `-1`, coba menetapkan `256M`, baca ulang hasilnya, dan hentikan startup dengan pesan jelas jika masih tidak terbatas atau perubahan gagal. Nilai finite eksplisit dari operator tidak ditimpa.
- Compose menetapkan `mem_limit: ${WEBSOCKET_MEMORY:-320m}` dan `memswap_limit` dengan nilai yang sama. Dokumentasikan bahwa override container harus menyisakan ruang di atas batas PHP untuk alokasi native/runtime.
- Batas PHP mengatur alokasi yang dihitung PHP; batas container meliputi proses beserta alokasi lain yang dibebankan ke cgroup. Keduanya tidak identik.

Trade-off: lonjakan alokasi dibatasi dengan konsekuensi daemon dapat berhenti/restart ketika anggaran habis. Batas ini tidak menjanjikan jumlah siswa tertentu dan tidak menghilangkan kebutuhan mengukur memori total VPS.

### 2.2 Adapter `BoundedWsServer`

Tambahkan kelas aplikasi yang mewarisi [Ratchet\WebSocket\WsServer](/home/rozen/ARGON/CBT-MF/src/vendor/cboden/ratchet/src/Ratchet/WebSocket/WsServer.php:26); tidak menyalin seluruh parser dan tidak memakai reflection untuk mengubah properti private vendor.

Kontrak adapter:

1. Handshake, framing, masking, control frames, penutupan, dan pembersihan koneksi tetap memakai Ratchet yang terpasang.
2. Setelah `parent::onOpen()` berhasil melakukan upgrade dan context koneksi masih terdaftar, ganti [ConnContext::$buffer](/home/rozen/ARGON/CBT-MF/src/vendor/cboden/ratchet/src/Ratchet/WebSocket/ConnContext.php:16) melalui storage protected yang tersedia dengan [MessageBuffer](/home/rozen/ARGON/CBT-MF/src/vendor/ratchet/rfc6455/src/Messaging/MessageBuffer.php:6) baru berparameter eksplisit: maksimum frame `65536` byte dan maksimum pesan `65536` byte.
3. Callback pesan meneruskan payload string kepada [WebSocketServerHandler](/home/rozen/ARGON/CBT-MF/src/app/Libraries/WebSocketServerHandler.php:8); callback control frame meneruskan ke [onControlFrame()](/home/rozen/ARGON/CBT-MF/src/vendor/cboden/ratchet/src/Ratchet/WebSocket/WsServer.php:182) Ratchet. Pertahankan kewajiban mask pada frame klien.
4. Buffer terbatas harus sudah terpasang sebelum event loop memproses data masuk berikutnya, termasuk ketika promise autentikasi Redis masih pending. Jangan mengganti buffer kembali setelah parsing dimulai.
5. Guard keberadaan context menangani handshake ditolak maupun koneksi yang ditutup oleh handler saat `onOpen()`; jangan membuat ulang context atau mendaftarkan ulang koneksi yang ditutup.
6. Batas total pesan mencakup seluruh continuation frame. Banyak pesan kecil yang datang dalam satu chunk TCP tetap diterima; jangan memakai `strlen($chunk)` sebagai batas ukuran pesan.
7. Pesan/frame berlebih ditutup melalui close code `1009` parser Ratchet. Pengujian harus membuktikan penolakan dari prefix frame yang cukup untuk parser vendor, tanpa harus mengirim seluruh payload besar.

Ukuran tersebut khusus payload masuk, bukan batas memori total per koneksi. Overhead parser, HTTP handshake, objek koneksi, native buffers, dan output tetap ada. Parser aplikasi PR 1 tetap mengabaikan pesan lebih dari `4096` byte sebelum JSON decode; adapter tidak mengubah batas bisnis ini maupun membatasi pesan server-ke-klien menjadi 4 KB.

Pilihan kelas dan cara memasang context di atas adalah keputusan agent berdasarkan API vendor lokal saat ini. Builder boleh memilih susunan internal yang setara jika seluruh kontrak diuji dan tidak menambah parser RFC6455 kedua.

## 3. WS-05: event loop dan kapasitas proxy

- Pasang dan aktifkan `ext-ev` beserta kebutuhan build-nya hanya pada [docker/php-fpm/Dockerfile](/home/rozen/ARGON/CBT-MF/docker/php-fpm/Dockerfile). Pastikan extension tetap dapat dimuat setelah cleanup dependensi build.
- [Loop::get()](/home/rozen/ARGON/CBT-MF/src/vendor/react/event-loop/src/Loop.php) tetap menjadi sumber loop bersama. Catat nama kelas loop dan batas memori efektif saat daemon startup, tanpa kredensial.
- Image hasil build harus menggunakan `React\EventLoop\ExtEvLoop`; log loop lain adalah indikasi deployment belum sesuai spec, bukan bukti kapasitas setara.
- Pertahankan `websocket.ulimits.nofile` soft/hard `65536`.
- Tambahkan konfigurasi utama Nginx Compose dengan `worker_processes auto`, `worker_connections 8192`, dan `worker_rlimit_nofile 65536`. Mount konfigurasi tersebut read-only dan pertahankan include `mime.types`, `conf.d/*.conf`, serta pengaturan HTTP umum yang dibutuhkan konfigurasi sekarang.
- Tambahkan `nginx.ulimits.nofile` soft/hard `65536` agar batas proses sesuai konfigurasi worker.

Setiap WebSocket melalui proxy memakai slot koneksi untuk sisi klien dan upstream. Angka `8192` bukan kapasitas 8192 siswa: HTTP lain, descriptor tambahan, RAM, dan CPU memakai sumber daya yang sama.

Trade-off: ekstensi native mengurangi ketergantungan pada plafon `stream_select` dengan tambahan dependensi build. Slot/descriptors yang lebih tinggi memberi ruang koneksi, bukan peningkatan RAM atau throughput secara otomatis. Image PHP ini juga dipakai FPM, import-worker, dan cron, sehingga smoke test ketiga peran wajib masuk verifikasi image.

## 4. WS-08: resolusi dan kegagalan Redis

### 4.1 Helper konfigurasi bersama

Tambahkan helper murni, misalnya `App\Libraries\RedisConnectionConfig`, dengan input konfigurasi yang dapat diberikan langsung dalam unit test. Integrasi pembacaan `env()` dilakukan secara konsisten oleh kedua pemanggil.

| Nilai | Prioritas pertama | Fallback | Bawaan |
|---|---|---|---|
| host | `redis.host` | `REDIS_HOST` | `redis` |
| port | `redis.port` | `REDIS_PORT` | `6379` |
| password | `redis.password` | `REDIS_PASSWORD` | string kosong |

Nilai tidak ada atau string kosong beralih ke fallback; password literal `"0"` tetap password yang valid. Jangan trim/mengubah isi password. Validasi port sebagai bilangan bulat `1..65535`; konfigurasi eksplisit yang invalid menghasilkan error yang dapat ditelusuri tanpa kredensial, bukan diam-diam memakai endpoint lain. Host tidak boleh berisi userinfo atau komponen URI yang dapat menyisipkan kredensial/path; dukungan IPv6 harus konsisten pada bentuk host mentah untuk phpredis dan bentuk authority berkurung untuk URI.

- [RedisClient::getInstance()](/home/rozen/ARGON/CBT-MF/src/app/Libraries/RedisClient.php:28) memakai hasil helper untuk koneksi sinkron dan AUTH. Pertahankan timeout connect `2` detik, read `3` detik, singleton/reset, serta kontrak kegagalan `null` yang dipakai caller.
- [WebSocketServe](/home/rozen/ARGON/CBT-MF/src/app/Commands/WebSocketServe.php) memakai konfigurasi yang sama untuk lazy client validasi token dan koneksi Pub/Sub. Password pada URI `redis://` memakai `rawurlencode()`; password yang diberikan ke `Redis::auth()` tetap mentah.
- Installer tetap menyuntikkan `REDIS_*` dari `.env` akar. Jangan menambahkan salinan Redis ke [src/.env](/home/rozen/ARGON/CBT-MF/src/.env).
- Konfigurasi `session.redis.*`, `cache.redis.*`, atau `session.savePath` eksplisit tidak dipindahkan ke helper. Operator tetap bertanggung jawab menjaga endpoint override yang sengaja berbeda; beri peringatan dokumentasi agar `redis.*` yang tertinggal tidak mengalahkan `REDIS_*` tanpa disadari.

Trade-off: resolver bersama mencegah penerbit token dan daemon memilih endpoint berbeda karena aturan fallback yang berbeda. Prioritas baru `redis.password` dapat mengubah kredensial yang dipakai instalasi yang sudah memiliki override tersebut; kondisi ini wajib diuji dan didokumentasikan.

### 4.2 Error async

- Pasang listener `error` pada lazy client segera setelah dibuat.
- Pertahankan penolakan autentikasi ketika Redis gagal dan lifecycle reconnect Pub/Sub yang sudah ada; jangan menambahkan fallback DB sinkron di event loop.
- Jalur error lazy client, koneksi/subscription Pub/Sub, dan promise handshake tidak boleh mencetak URI/password atau query token. Bila exception mengandung rahasia, redaksi sebelum pencatatan.
- Listener error tidak menggantikan penanganan rejection promise; keduanya harus tercakup pengujian.

Trade-off: pencatatan tanpa rahasia mengurangi detail diagnosis mentah; log tetap menyertakan konteks operasi dan kategori kegagalan yang diperlukan.

## 5. WS-10: identitas proses dan jalur koneksi

### 5.1 Daemon non-root dan entrypoint

- Service `websocket`: `user: "33:33"`, `entrypoint: []`, command argv PHP pada bagian 2, dan mount [security.ini:ro](/home/rozen/ARGON/CBT-MF/docker/php-fpm/security.ini). Direktori kerja dan volume kode tetap sesuai service saat ini.
- Entry point PHP bersama hanya menghitung tuning dan merender [zzz-tuning.conf](/home/rozen/ARGON/CBT-MF/docker/php-fpm/zzz-tuning.conf) ketika perintah utama adalah `php-fpm`. Untuk perintah CLI/sh, teruskan ke `docker-php-entrypoint` tanpa menyentuh berkas konfigurasi FPM.
- Sebelum menulis konfigurasi FPM, periksa target writable; kegagalan pada jalur FPM harus jelas, bukan error redirection yang membingungkan atau startup yang tampak sukses dengan tuning yang tidak diterapkan.
- Jangan mengubah user FPM, import-worker, atau cron pada PR ini. Jangan menjalankan recursive chown otomatis saat setiap daemon start.

Batas operasional: perubahan ini hanya menghentikan daemon WebSocket membuat berkas sebagai root. `cron` dan perintah CLI operator masih dapat menulis ke direktori log yang sama sebagai root. Berkas log/artefak lama milik root tidak otomatis diperbaiki. Pemeriksaan writable dan ownership UID 33 pada direktori serta daily log aktif adalah prasyarat rollout; jika gagal, laporkan dan selesaikan izin deployment secara eksplisit sebelum mengganti daemon. Uji juga pergantian daily log; jangan mengklaim seluruh writer sudah diseragamkan.

Trade-off: hak proses daemon dipersempit dengan konsekuensi ketergantungan pada izin bind mount yang sebelumnya dapat tertutup oleh eksekusi root. Masalah writer root lain dicatat sebagai batas yang perlu diuji, bukan diasumsikan hilang.

### 5.2 URL dan Nginx

- Hapus pemetaan `8080 -> 8060` dari [WebSocketUrl](/home/rozen/ARGON/CBT-MF/src/app/Libraries/WebSocketUrl.php:21); `derive('http://localhost:8080')` menghasilkan `ws://localhost:8080/ws`.
- Hapus publikasi `ports` host `8060` pada service WebSocket. Listener internal tetap `0.0.0.0:8060` dan service tetap terpisah dari Nginx.
- Nginx menerima `/ws` dan `/ws/` tanpa redirect, mempertahankan query `ws_token`/`proctor_token`, memakai HTTP/1.1 serta header Upgrade/Connection, dan mempertahankan timeout proxy saat ini.
- Teruskan authority klien dengan port (`Host` dari `$http_host`, dengan fallback tervalidasi bila diperlukan), bukan `$host` yang membuang port eksplisit.
- Nginx menimpa `X-Forwarded-Proto` untuk upstream WebSocket dengan `$scheme`. Jangan meneruskan nilai mentah yang bebas dipasok klien sebagai otoritas skema.
- Pada TLS yang ditutup Cloudflare Tunnel, origin HTTPS kanonis diambil dari `app.baseURL` pada allowlist bagian 6; alias HTTPS tambahan perlu didaftarkan. Ini menghindari perluasan trust header edge pada PR ini.
- Jangan log query token WebSocket pada access/error log yang dapat dikendalikan konfigurasi aplikasi/proxy. Jika access log khusus diperlukan, log path tanpa query. Pastikan pesan penolakan tidak mencetak request URI lengkap.
- Pertahankan perilaku override `websocket_url` eksplisit. Pada rollout, operator harus memeriksa `cbt:ws-url` dan mengosongkan/mengoreksi nilai lama yang langsung menunjuk port `8060`, sehingga nilai efektif menuju proxy yang diputuskan. Jangan menulis ulang setting database secara massal.

Trade-off: satu jalur klien menyederhanakan deployment dan asal koneksi, tetapi semua koneksi kini bergantung pada Nginx dan memakai dua sisi descriptor proxy. Penghapusan port host membatasi akses langsung dari host/jaringan luar; service lain di jaringan Docker yang sama masih dapat mencapai listener internal.

## 6. Middleware Origin handshake

Pasang middleware aplikasi `WebSocketOriginGuard` yang mengimplementasikan [HttpServerInterface](/home/rozen/ARGON/CBT-MF/src/vendor/cboden/ratchet/src/Ratchet/Http/HttpServerInterface.php) di antara `HttpServer` dan `BoundedWsServer`:

`IoServer -> HttpServer -> WebSocketOriginGuard -> BoundedWsServer -> WebSocketServerHandler`

Jangan memakai [Ratchet\Http\OriginCheck](/home/rozen/ARGON/CBT-MF/src/vendor/cboden/ratchet/src/Ratchet/Http/OriginCheck.php) bawaan: implementasi lokal mengakses elemen pertama header tanpa memeriksa keberadaannya dan hanya membandingkan host.

Kebijakan yang disetujui:

- Tepat satu Origin harus ada. Origin kosong, `null`, ganda, malformed, mengandung userinfo, path/query/fragment, atau daftar gabungan ditolak dengan HTTP `403` sebelum upgrade dan sebelum akses Redis.
- Origin dibandingkan melalui tuple skema, host, port. Hanya `http`/`https`, normalisasi huruf kecil untuk skema/host dan port default `80`/`443`; port non-default tetap signifikan. Jangan memakai prefix/suffix hostname atau wildcard.
- Sumber yang diizinkan: same-origin dari authority Host dan skema proxy internal yang ditetapkan Nginx; origin hasil ekstraksi `app.baseURL`; origin `KIOSK_CORS_ORIGIN` dengan fallback `https://appassets.androidplatform.net`; serta daftar comma-separated `CORS_ALLOWED_ORIGINS` yang valid.
- Base URL aplikasi boleh memiliki subpath; hanya tuple origin yang masuk daftar. Entri konfigurasi invalid tidak boleh berubah menjadi wildcard. Laporkan konfigurasi invalid tanpa rahasia dan tolak startup agar kesalahan terlihat sebelum deployment.
- Origin kiosk tambahan tidak menghapus kewajiban token; siswa maupun pengawas tetap melewati pemeriksaan token/auth-state PR 1.
- Middleware meneruskan `onMessage`, `onClose`, dan `onError` hanya untuk koneksi yang sudah diterima/didelegasikan. Koneksi yang ditolak tidak boleh memicu pembersihan state yang belum dibuat atau tertinggal dalam indeks middleware.

Trade-off dan batas perlindungan: browser membatasi nilai Origin yang dikirim JavaScript, sehingga pemeriksaan ini membatasi penggunaan koneksi dari situs lain. Klien non-browser dapat memalsukan Origin; autentikasi tetap bertumpu pada token acak 128-bit yang tidak bocor. Klien diagnostik non-browser wajib mengirim Origin yang diizinkan. Source Android tidak berada dalam cakupan pembacaan repo ini, sehingga kompatibilitas perangkat nyata tetap perlu diuji.

## 7. Analisis dampak lintas fitur

Peta ini berasal dari pencarian caller pada `src/app`, `src/tests`, konfigurasi Docker, dan installer. Caller tidak seluruhnya dieksekusi pada tahap desain; daftar berikut bukan klaim verifikasi runtime.

| Jalur berubah | Caller/modul yang teridentifikasi | Fitur dan state terkait |
|---|---|---|
| [WebSocketServe::run()](/home/rozen/ARGON/CBT-MF/src/app/Commands/WebSocketServe.php:22) | service Compose `websocket`; [WebSocketServerHandler](/home/rozen/ARGON/CBT-MF/src/app/Libraries/WebSocketServerHandler.php:8) | token `ws_student_token:*`, `ws_proctor_token:*`, auth-state `user_auth_state:*`, channel `exam_events`, heartbeat, reconnect dan indeks koneksi |
| [WebSocketUrl::derive/resolve/pick()](/home/rozen/ARGON/CBT-MF/src/app/Libraries/WebSocketUrl.php:21) | [FrontendConfig](/home/rozen/ARGON/CBT-MF/src/app/Libraries/FrontendConfig.php), [UiBundleBuilder](/home/rozen/ARGON/CBT-MF/src/app/Libraries/UiBundleBuilder.php), [Student/ExamController::take()](/home/rozen/ARGON/CBT-MF/src/app/Controllers/Student/ExamController.php:138), [Api/ExamApiController::init()](/home/rozen/ARGON/CBT-MF/src/app/Controllers/Api/ExamApiController.php:31), [Proctor/LiveController::monitor()](/home/rozen/ARGON/CBT-MF/src/app/Controllers/Proctor/LiveController.php:24), [Admin/StaticExamController::generate()](/home/rozen/ARGON/CBT-MF/src/app/Controllers/Admin/StaticExamController.php:22), [WsUrlProbe::run()](/home/rozen/ARGON/CBT-MF/src/app/Commands/WsUrlProbe.php:20), view pengaturan admin | ujian dinamis/statis, kiosk bundle, API inisialisasi ujian, live pengawas, setting `websocket_url`; artefak statis lama dapat menyimpan URL lama |
| [RedisClient::getInstance()](/home/rozen/ARGON/CBT-MF/src/app/Libraries/RedisClient.php:28) | filter [AuthFilter](/home/rozen/ARGON/CBT-MF/src/app/Filters/AuthFilter.php), [MultiLoginFilter](/home/rozen/ARGON/CBT-MF/src/app/Filters/MultiLoginFilter.php), [ApiRateLimitFilter](/home/rozen/ARGON/CBT-MF/src/app/Filters/ApiRateLimitFilter.php); [UserAuthState](/home/rozen/ARGON/CBT-MF/src/app/Libraries/UserAuthState.php), [WebSocketToken](/home/rozen/ARGON/CBT-MF/src/app/Libraries/WebSocketToken.php), [LoginThrottle](/home/rozen/ARGON/CBT-MF/src/app/Libraries/LoginThrottle.php), [DeviceBan](/home/rozen/ARGON/CBT-MF/src/app/Libraries/DeviceBan.php), [ProctorAction](/home/rozen/ARGON/CBT-MF/src/app/Libraries/ProctorAction.php), [ExamService](/home/rozen/ARGON/CBT-MF/src/app/Libraries/ExamService.php), [KioskEventQueue](/home/rozen/ARGON/CBT-MF/src/app/Libraries/KioskEventQueue.php), [KioskPresence](/home/rozen/ARGON/CBT-MF/src/app/Libraries/KioskPresence.php), [DependencyHealth](/home/rozen/ARGON/CBT-MF/src/app/Libraries/DependencyHealth.php); controller Auth/Admin/Api/Student/Proctor; [UserModel](/home/rozen/ARGON/CBT-MF/src/app/Models/UserModel.php), [TestAttemptModel](/home/rozen/ARGON/CBT-MF/src/app/Models/TestAttemptModel.php); command [KioskPrune](/home/rozen/ARGON/CBT-MF/src/app/Commands/KioskPrune.php), [FinalizeExams](/home/rozen/ARGON/CBT-MF/src/app/Commands/FinalizeExams.php), [AuthUnblock](/home/rozen/ARGON/CBT-MF/src/app/Commands/AuthUnblock.php) | login dan pencabutan sesi, antrean login, cache/status ujian, pembatas API, kiosk, audit, admin, finalisasi; endpoint Redis dan kredensial bersifat shared |
| image PHP dan entrypoint | service `php`, `websocket`, `import-worker`, `cron` | modul PHP terpasang, startup FPM/CLI, koneksi DB/Redis, kepemilikan berkas `writable/`; import-worker sudah melewati entrypoint |
| konfigurasi Nginx utama dan route WS | seluruh request aplikasi; Cloudflare Tunnel sebagai jalur masuk opsional | HTTP siswa/admin/guru, maintenance, static assets, CSP, Upgrade, jaringan `ujian_network`, descriptor dan access log |

Tidak ada perubahan format/nama/TTL key Redis atau channel yang direncanakan. Session Redis dan cache framework memakai konfigurasi terpisah; resolver baru tidak boleh tanpa sengaja mengalihkan keduanya. Skema DB, payload event PR 1, dan alur HTTP CORS/CSRF tidak diubah.

Keterbatasan visibilitas: isi [src/writable/imports/](/home/rozen/ARGON/CBT-MF/src/writable/imports/) tidak dapat dibaca oleh user host dan bukan target patch. Konfigurasi edge/proxy eksternal, permission aktual VPS, custom command operator, APK Exambro, serta artefak bundle/statis yang sudah terpasang belum diverifikasi.

## 8. Pengujian dan bukti penerimaan

### 8.1 Unit dan integrasi PHP

- `RedisConnectionConfigTest`: prioritas seluruh input, nilai kosong/tidak ada, password `"0"`, karakter `@ : # / % & + =` tanpa mengubah password mentah, IPv6, dan port/host invalid.
- Tes Redis sinkron membuktikan timeout tetap terpasang sebelum AUTH, fallback `REDIS_*`, serta kegagalan tetap mengembalikan `null`.
- `BoundedWsServerTest`: handshake sah; frame masked kecil; banyak frame dalam satu chunk; frame/pesan terfragmentasi tepat `65536` dan melebihi batas; control frame di sela fragmentasi; prefix frame besar sebelum autentikasi Redis resolve; handshake gagal/handler menutup koneksi tidak membuat state baru. Periksa close `1009`, callback, dan cleanup runtime.
- `WebSocketOriginGuardTest`: web lokal `http://localhost:8080`, HTTPS aplikasi, subpath baseURL, port beda, port default, kiosk bawaan/kustom, daftar tambahan, Origin absen/`null`/ganda/malformed, hostname mirip, serta lifecycle koneksi ditolak. Penolakan tidak memanggil Redis atau handler.
- [WebSocketUrlTest](/home/rozen/ARGON/CBT-MF/src/tests/Realtime/WebSocketUrlTest.php): port `8080` dipertahankan, derivasi port lain dan pengabaian subpath tetap konsisten, override eksplisit tetap konsisten.
- Regresi suite Realtime dan pengujian Resilience/Exam/Kiosk/Import yang relevan dengan helper Redis bersama. Pengujian baru harus menguji perilaku runtime; pencarian teks sumber saja tidak membuktikan integrasi PHP.

### 8.2 Node, shell, dan konfigurasi

- Jalankan `node --test src/tests/Resilience/*.test.cjs src/tests/Import/*.test.cjs`; update mock URL WS lama yang relevan, bukan melemahkan assertion regresi.
- Jalankan `node --check src/public/assets/exam-app.js` bila berkas disentuh.
- Jalankan `sh -n docker/php-fpm/entrypoint.sh` dan uji dispatch entrypoint dengan executable/config fixture: CLI tidak menulis FPM, FPM writable merender, FPM read-only gagal jelas.
- Jalankan `docker compose config --quiet` tanpa mencetak nilai konfigurasi lengkap yang dapat memuat rahasia; periksa render Nginx dengan `nginx -t` dalam container.
- Pemeriksaan statis/diff memastikan berkas vendor dan seluruh berkas Railway tidak diubah.

### 8.3 Runtime container dan rollout VPS

1. Build image Compose dan periksa `extension_loaded('ev')`, kelas [Loop::get()](/home/rozen/ARGON/CBT-MF/src/vendor/react/event-loop/src/Loop.php), serta smoke startup FPM/import-worker/cron. Rebuild/recreate peran yang memakai image bila dibutuhkan; koordinasikan restart dengan jendela operasional ujian.
2. Jalankan suite PHP pada container dengan dependensi dev yang tersedia, misalnya `docker compose exec -T -u 33:33 php php vendor/bin/phpunit --testsuite Realtime` dan suite regresi terkait. Jangan menganggap image yang tanpa PHPUnit sudah menjalankan tes.
3. Verifikasi PID daemon, UID/GID `33:33`, batas PHP efektif `256M`, memory/swap cgroup, limit descriptor Nginx/daemon, dan kelas loop dari log.
4. Periksa UID/GID serta writability daily log aktif oleh UID 33, termasuk ketika cron yang membuat berkas lebih dulu dan ketika tanggal log berganti. Jika tidak writable, rollout non-root tertahan sampai izin deployment ditangani; jangan menutupi masalah dengan mengembalikan daemon ke root.
5. Buktikan handshake `101` lewat port aplikasi pada `/ws` dan `/ws/`, tanpa redirect, dengan token sah untuk siswa/pengawas. Buktikan Origin ditolak mendapat `403`, token invalid tidak terdaftar, dan akses host port `8060` tidak dipublikasikan lagi.
6. Periksa `cbt:ws-url` dan setting eksplisit sebelum restart. Regenerasi halaman ujian statis serta rebuild bundle kiosk yang menyimpan URL lama setelah perubahan URL efektif; validasi perangkat Android Exambro dan live pengawas.
7. Uji pemulihan Redis dan pub/sub menggunakan endpoint/credential helper yang sama; tidak ada password/token pada log. Uji koneksi paralel bertahap di lingkungan uji sampai melewati plafon lama jika sumber daya memungkinkan, sambil mengukur RAM/CPU/error; jangan menjalankan load test pada ujian aktif.

Saat handoff, host tidak menyediakan PHP/Composer. Keberadaan client Docker atau socket saja tidak membuktikan daemon/image/test dapat dijalankan. Periksa kapabilitas saat implementasi, laporkan perintah yang benar-benar dieksekusi beserta hasilnya, dan tandai pengujian container/perangkat yang belum dilakukan.

Jika menggunakan `php -l`, nyatakan bahwa pemeriksaan tersebut hanya memvalidasi syntax parser-level dan tidak memeriksa logic error, type error, undefined symbol, race condition, maupun celah injeksi. Kelulusan satu tool bukan bukti kode bebas bug.

## 9. Langkah setelah review spec

Spec ini ditulis pada branch fitur dari `main`; pendekatan sudah disetujui, tetapi dokumen ini belum memperoleh persetujuan user. Builder check oleh reviewer terpisah sudah dilakukan pada 2026-11-02 dengan hasil `nothing important left`; hasil tersebut adalah review kelengkapan desain, bukan validasi runtime. Commit spec dengan identitas Git yang ditetapkan handoff dan minta review user sebelum implementasi/TDD.

Setelah implementasi diizinkan: verifikasi sesuai bukti yang tersedia, push branch, buka satu PR ke `main` dengan cakupan aktual, kemudian berhenti dan menunggu user melakukan merge. Jangan merge sendiri. Jika user meminta langsung implementasi sesuai spec tanpa dokumen implementation plan terpisah, ikuti instruksi tersebut.

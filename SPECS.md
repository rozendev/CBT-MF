# WebSocket PR 2 — disetujui

- Cakupan: WS-02, WS-05, WS-08, WS-10 pada Compose/VPS; Railway dikecualikan.
- Klien memakai Nginx `/ws` atau `/ws/` pada port aplikasi, tanpa redirect; daemon tetap internal `8060` tanpa publikasi port host.
- PHP 256 MB, container 320 MB tanpa tambahan swap; frame dan total pesan masuk maksimal 64 KB sebelum autentikasi selesai; batas event aplikasi tetap 4 KB.
- Pertahankan Ratchet/vendor; tambahkan adapter pembatas buffer dan guard Origin utuh (skema, host, port). Origin kosong/null/invalid ditolak; web aplikasi, kiosk Exambro, dan daftar tambahan diterima. Token tetap wajib.
- Pasang ext-ev; catat loop aktif. Nginx memakai 8192 slot/worker dan nofile 65536. Kapasitas tetap perlu diukur.
- Satukan resolusi Redis sinkron/async: redis.* → REDIS_* → default; encode password URI, pertahankan timeout dan reconnect, hindari rahasia dalam log.
- Daemon berjalan sebagai 33:33, melewati entrypoint FPM. Entrypoint hanya merender tuning untuk FPM. Izin log bersama/berkas root lama tetap prasyarat rollout.
- Uji frame nyata, fragmentasi, Origin, konfigurasi Redis, dispatch entrypoint, proxy, serta regresi PHP/Node. Laporkan pemeriksaan perangkat/load yang belum dilakukan.
- Rincian desain yang telah disetujui: [spec sebelumnya](/home/rozen/ARGON/CBT-MF/docs/superpowers/specs/2026-11-02-websocket-infra-hardening-design.md).

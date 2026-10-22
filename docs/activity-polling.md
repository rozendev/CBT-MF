# Volume polling per tugas dan halaman

Jadwal dimiliki halaman yang membutuhkannya, tanpa service worker. Semua angka
memiliki jitter deterministik per client/tugas supaya request tidak serentak.

| Tugas | Halaman aktif | Terlihat tetapi idle ≥2 menit | Tab tersembunyi |
| --- | --- | --- | --- |
| Presence dashboard (`/student`, `/admin`, alias `/dashboard`) | 10–11 detik | 60–61 detik | 180–210 detik |
| Presence halaman lain | 60–61 detik | 60–61 detik | 180–210 detik |
| Laporan pengawas | Dashboard 10–11 detik; halaman lain 30–31 detik | 60–61 detik | 180–210 detik |
| Data monitoring kiosk | 10–11 detik, hanya bila ujian dipilih | 30–31 detik | 180–210 detik |
| Pemeriksaan gangguan layanan pada monitoring | 60–61 detik | 60–61 detik | 180–210 detik |
| Status ujian ketika WebSocket gagal | Awal 0–1 detik, selanjutnya 10–11 detik | 30–31 detik | 180–210 detik |
| Progres import | Berjalan 3–3,3 detik; antre/retry 10–11 detik | 15–16 detik | 180–210 detik |
| Autosave jawaban | Dipicu perubahan jawaban, debounce dan antrean | Tidak ada request tanpa perubahan | Perubahan yang sudah dipicu tetap diamankan |
| Sync jawaban ke database | Hanya setelah autosave berhasil; jeda normal 60 detik antar sync | Tidak ada flush berulang tanpa perubahan | Sync tertunda 180–210 detik |

Interval dihitung setelah pekerjaan sebelumnya selesai, sehingga request lambat
tidak menghasilkan polling paralel. Halaman berbeda tidak memulai tugas monitoring
yang tidak dimilikinya. Native heartbeat keamanan kiosk dan heartbeat protokol
WebSocket tetap memakai jadwal yang ada agar pemeriksaan penguncian tidak rusak.

`pagehide` membatalkan pembacaan, menutup socket halaman ujian, dan menghentikan
timer polling. Tab/client yang sudah ditutup mengirim **nol polling**, bukan satu
request tiap beberapa menit. Jadwal menit berlaku untuk tab yang masih ada tetapi
tersembunyi. Server tidak membuat loop polling per client yang sudah pergi;
metadata presence dibersihkan melalui mekanisme expiry yang sudah ada.

Offline menghentikan pembacaan; kembali online, fokus, terlihat, atau pemulihan
BFCache menjadwalkan ulang sesuai tugas. Jawaban yang batal dikirim saat berpindah
halaman tetap dicadangkan, ditandai belum tersimpan, dan diulang saat halaman ujian
kembali. Sync yang dibatalkan tidak mengunci antrean simpan.

Polling, autosave, dan sync berbagi jeda mulai request minimal 500 ms per halaman.
Rate limiter server per siswa tetap berlaku lintas tab. `Retry-After` menjadi
cooldown bersama, termasuk HTTP 503, dengan maksimal tiga retry. Timeout jaringan
baru dimulai setelah menunggu antrean/cooldown. Job yang gagal permanen tidak
dimulai ulang hanya karena visibility berubah. Worker import tetap memproses job
yang diterima ketika halaman admin ditutup; yang berhenti adalah pembacaan progres.

Tes lifecycle dan volume memakai waktu virtual dan transport simulasi. Jalankan
suite PHP yang ada serta:

```bash
node --test --test-isolation=none src/tests/Resilience/*.test.cjs src/tests/Import/admin-import.test.cjs
```

Build bundle kiosk perlu diulang agar aset penjadwal ikut tersedia. Ini bukan benchmark beban
ujian serentak dan tidak mengklaim kapasitas siswa atau penghematan CPU terukur.

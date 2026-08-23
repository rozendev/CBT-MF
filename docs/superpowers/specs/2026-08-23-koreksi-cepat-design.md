# Rancangan: Layar Koreksi Cepat

Tanggal: 2026-08-23
Status: disetujui, siap direncanakan

## Masalah

Koreksi manual hari ini berjalan per-siswa dengan penuh muatan ulang halaman:
guru membuka `admin/results/detail/{attemptId}`, mengisi form kecil di bawah
tiap soal (`update-score`, POST biasa), halaman di-reload, lalu pindah siswa
berikutnya dan mengulang dari awal. Satu kelas 30 siswa dengan 5 esai berarti
150 kali muat-ulang untuk menilai satu pertanyaan yang sama berulang-ulang.

Sketsa fiturnya sudah disepakati sejak spec esai tanpa kunci: navigasi antar
siswa untuk satu soal, `↑` nilai penuh, `↓` nol, `1-9` parsial, `←/→` pindah
siswa, `u` urungkan.

## Keputusan desain

| Pertanyaan | Keputusan |
|---|---|
| Titik masuk | Tombol "Koreksi Cepat" di daftar attempt per ujian (`admin/results/view/{testId}`) |
| Waktu simpan | Instan via AJAX tiap aksi, otomatis lanjut ke siswa berikutnya |
| Arti tombol `1-9` | Persen dari poin maksimum soal (tekan `7` = 70% × score_right) |
| Tombol `u` | Undo satu langkah: simpanan terakhir dikembalikan ke kondisi semula |
| Bentuk implementasi | Halaman admin khusus + endpoint AJAX JSON (pola Alpine.js seperti kiosk/live) |

## Alur & layar

Tombol "Koreksi Cepat" hanya tampil bila ujian punya soal mode manual. Klik →
`GET admin/results/grade/{testId}` → server mengarahkan ke soal manual pertama
yang masih punya nilai kosong; kalau semua sudah terkoreksi, tetap masuk ke
soal pertama dengan banner "Semua esai sudah dikoreksi" — bukan bounce-back,
supaya guru tetap bisa menyesuaikan nilai lama dari layar yang sama. Layar
grading sendiri:

```
┌──────────────────────────────────────────────────────┐
│ [← Daftar Nilai]  Ujian: SUMATIF AKHIR SEMESTER      │
│ Soal: [▼ Jelaskan pendapatmu tentang hutan...]   7/30│
├──────────────────────────────────────────────────────┤
│ Siswa: Budi Santoso (NIS 2024-017)     ‹ 2 dari 30 › │
│ ┌─ Jawaban ────────────────────────────────────────┐ │
│ │ Hutan penting karena ...                         │ │
│ └──────────────────────────────────────────────────┘ │
│ Kunci: Menjaga kelestarian hutan ...                 │
│                                                      │
│              Nilai: 7.5  / 10                        │
│  ↑ penuh · ↓ nol · 1–9 parsial · ←→ siswa · u undo  │
└──────────────────────────────────────────────────────┘
```

- **Pemilih soal** di header: dropdown semua soal manual ujian itu + counter
  belum-dikoreksi per soal; pindah soal tanpa kehilangan posisi.
- **Kartu siswa**: nama + NIS, jawaban siswa, kunci referensi, nilai saat ini.
  Jawaban dirender teks polos (`x-text` Alpine, escape otomatis — setara
  `nl2br(esc(...))` yang dipakai detail.php hari ini).
- **Auto-lanjut**: setelah simpan, kursor melompat ke siswa berikutnya yang
  nilainya masih `NULL` (bukan sekadar index+1); kalau tak ada lagi, lanjut
  index+1 biasa supaya peninjauan tetap bisa berurutan. Siswa terakhir →
  kartu ringkasan "Selesai".
- Siswa yang tidak menjawab tetap muncul ("Tidak diisi") agar bisa dinilai 0
  eksplisit.
- Tombol prev/next mouse tetap ada sebagai cadangan; shortcut keyboard
  diabaikan saat fokus ada di dropdown (`e.target.tagName` guard).

## Arsitektur

Semua di `Admin\ResultController`. Tanpa tabel baru, tanpa migration.

### Route (grup admin, filter `role:admin`)

| Method | Route | Fungsi |
|---|---|---|
| GET | `admin/results/grade/(:num)` | `gradeRedirect($testId)` — cari soal manual pertama yang masih ada nilai NULL |
| GET | `admin/results/grade/(:num)/(:num)` | `grade($testId, $questionId)` — validasi + render shell |
| GET | `admin/results/grade-data/(:num)/(:num)` | `gradeData(...)` — JSON daftar siswa |
| POST | `admin/results/grade-save` | `gradeSave()` — simpan satu nilai |

### Kontrak JSON

`grade-data`:

```json
{
  "question": { "id": 5, "text": "Jelaskan ...", "max_points": 10 },
  "counts":   { "total": 30, "graded": 7 },
  "students": [
    { "log_id": 123, "name": "Budi Santoso", "nis": "2024-017",
      "answer": "...", "score": null }
  ]
}
```

Query roster: attempt `status = 3` ujian tersebut INNER JOIN `test_logs`
soal tersebut. Attempt tanpa baris log (misal soal ditambah setelah siswa
selesai) dikeluarkan — tidak ada tempat menaruh nilainya, dan `total`
menghitung hanya yang punya log supaya counter jujur. Jawaban siswa dari
`test_logs.answer_text`; kunci referensi dari `test_log_answers` baris
`is_correct = 1`.

`grade-save`: POST `log_id` + `score`; string kosong berarti `NULL`
(dipakai undo untuk mengembalikan "belum dikoreksi"). Respons:

```json
{ "attempt_score": 78.5, "remaining": 22 }
```

Validasi: log harus milik soal tipe 3 mode manual; selain itu respons JSON
403/404.

### Refactor kecil

Blok kalkulasi ulang di `updateManualScore()` (±baris 136–166) diekstrak jadi
private `recalcAttemptScore(int $attemptId): float` — dipakai bersama oleh
`updateManualScore()` (form lama tetap berfungsi sebagai cadangan) dan
`gradeSave()`. Rumusnya tak berubah: `SUM(score) / (COUNT(score IS NOT NULL) ×
score_right) × max_score`.

### Keamanan

- Semua route di balik filter `role:admin`.
- CSRF: hash di meta tag view, dikirim sebagai header pada tiap fetch.
- Konten jawaban siswa tidak pernah masuk sebagai HTML — `x-text` saja.

## Kasus tepi

| Keadaan | Perilaku |
|---|---|
| Semua soal sudah terkoreksi | `gradeRedirect` tetap masuk ke soal pertama dengan banner "Semua esai sudah dikoreksi" — guru bisa langsung menyesuaikan nilai lama |
| `score_right <= 0` | Tombol nilai keyboard dinonaktifkan + peringatan kecil |
| Fokus di dropdown pemilih soal | Shortcut diabaikan |
| Dua admin menilai bersamaan | Last-write-wins, sama seperti form lama |
| Undo tanpa aksi terakhir | Diabaikan diam-diam |
| Simpan gagal (jaringan) | Kartu berubah kuning + galat; kursor TIDAK maju; guru mengulang aksinya |

## Pengujian

Controller membutuhkan DB dan sesi CI4 — konsisten dengan konvensi repo,
tidak ada tes unit untuknya. Verifikasi: `php -l` tiap file ubah, suite
`WordImport` tetap hijau, lalu checklist manual bernomor (ujian 2 esai,
3 siswa, satu tanpa jawaban): entri point, ↑/↓/1–9, pindah soal, undo,
kalkulasi ulang nilai akhir, penilaian siswa tanpa jawaban.

## Di luar lingkup

- Koreksi multi-soal dalam satu layar (tetap satu soal per layar).
- Antrian koreksi lintas ujian.
- Undo multi-langkah.

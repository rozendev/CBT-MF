# Rancangan: Analisis Butir Soal

Tanggal: 2026-09-04
Status: disetujui, siap direncanakan

## Masalah

Setelah ujian selesai, satu-satunya alat evaluasi kualitas soal yang dipunya
sistem adalah sheet "Analisis Soal" di dalam export `test_detail`
(`ReportController::buildQuestionAnalysisSheet()`). Isinya hanya cacah:
benar, salah, tidak dijawab, dan persentase ketuntasan. Angka itu menjawab
"berapa banyak yang bisa" — bukan "apakah soalnya layak dipakai lagi".

Tiga hal yang guru butuhkan tapi belum ada sama sekali:

1. **Tingkat kesukaran (P)** sebagai angka empiris. Kolom `difficulty` di
   tabel `questions` adalah label yang diketik manusia saat menulis soal
   (Level 1/2/3), bukan hasil pengukuran. Soal yang dilabeli "sukar" bisa
   saja dijawab benar oleh 95% peserta, dan tidak ada yang memberi tahu.
2. **Daya beda (D)** — apakah soal ini memisahkan siswa yang menguasai materi
   dari yang tidak. Soal dengan daya beda negatif (siswa kelompok atas justru
   lebih banyak salah) hampir selalu berarti **kunci jawabannya salah**, dan
   hari ini kesalahan seperti itu tidak terdeteksi sampai ada siswa protes.
3. **Efektivitas pengecoh** — opsi PG yang tidak pernah dipilih siapa pun
   adalah opsi mati; soal 5 opsi dengan 3 pengecoh mati efektifnya soal 2
   opsi, dan siswa bisa menebak dengan peluang 50%.

Sistem sudah menyimpan semua bahan mentahnya (`test_logs.score` per butir
per attempt, `test_log_answers.is_selected` + `is_correct` per opsi), jadi ini
murni pekerjaan menghitung dan menyajikan — tanpa tabel baru.

## Cacat yang sekalian dibetulkan: penjajaran butir lewat `display_order`

Export yang ada menjajarkan soal antar-siswa memakai `display_order`
(`ReportController::exportTestDetailReport()`, baris ~684: template soal
diambil dari attempt pertama, lalu `scoreMatrix[attempt][display_order]`).

Itu benar hanya selama urutan soal sama untuk semua peserta. Padahal
`ExamService::generateAttemptQuestions()` mengacak `display_order` per attempt
saat `tests.random_questions` menyala:

```php
if ($test->random_questions) {
    $logs = $this->testLogModel->where('test_attempt_id', $attemptId)->findAll();
    ... shuffle($logs); ...   // display_order ditulis ulang 1..n per attempt
}
```

Kolam soalnya sendiri **deterministik** per ujian (seed `test->id * 100000 +
set->id`), jadi semua peserta memang mengerjakan himpunan soal yang sama —
yang berbeda hanya nomor urutnya. Akibatnya "Soal 3" milik Budi dan "Soal 3"
milik Ani bisa dua soal yang berlainan, dan kolom analisis lama mencampur
statistik dua soal berbeda menjadi satu baris.

Fitur baru ini menjajarkan dengan `question_id`, bukan `display_order`.
Perbaikan export lama berada **di luar cakupan** rancangan ini (disebut di
sini supaya cacatnya tercatat, bukan supaya diam-diam ikut diubah).

## Keputusan desain

| Pertanyaan | Keputusan |
|---|---|
| Titik masuk | Tombol "Analisis Butir" di `admin/results/view/{testId}`, sebelah "Koreksi Cepat" |
| Hak akses | admin + guru (setara halaman Hasil, bukan blok admin-only) |
| Penjajaran butir | `question_id` (lihat bagian di atas) |
| Tempat hitung | Library murni `App\Libraries\ItemAnalysis` — tanpa DB, tanpa session, bisa diuji unit |
| Tempat query | `Admin\ItemAnalysisController` — SQL di controller, matematika di library |
| Bentuk halaman | Shell PHP + Alpine.js, data lewat endpoint JSON (pola `results/grade`) |
| Ekspor | CSV (`text/csv`) langsung dari controller — bukan xlsx, agar tidak menyeret PhpSpreadsheet ke jalur baru |
| Attempt yang dihitung | Hanya `test_attempts.status = 3` (selesai), sama seperti laporan lain |
| Skala butir | `tests.score_right` sebagai poin maksimum tiap butir — mengikuti `ScoringEngine`, yang menambah `score_right` ke `maxPossiblePoints` untuk **semua** tipe soal |

## Aturan data: butir mana yang ikut dihitung

Statistik butir (khususnya alpha dan korelasi butir-total) menuntut matriks
lengkap: tiap peserta punya skor angka untuk tiap butir. Karena itu:

> **Satu butir ikut dianalisis hanya bila butir itu punya skor non-NULL untuk
> SEMUA attempt selesai yang ikut dihitung.**

Satu aturan itu menutup dua kasus sekaligus:

- **Esai belum dikoreksi.** `ScoringEngine` sengaja menulis `score = NULL`
  (bukan 0) untuk esai mode manual, supaya "belum dinilai" tak tertukar
  dengan "dijawab salah". Butir yang masih punya satu saja NULL dikeluarkan
  dengan alasan "belum selesai dikoreksi" — bukan dihitung sebagai nol, yang
  akan memalsukan tingkat kesukaran menjadi 0.00.
- **Soal yang ditambahkan belakangan.** Attempt lama tidak punya baris
  `test_logs` untuk soal itu; butirnya dikeluarkan dengan alasan "tidak
  dikerjakan semua peserta".

Butir yang dikeluarkan **tetap ditampilkan** di daftar terpisah lengkap dengan
alasannya. Menyembunyikannya akan membuat guru mengira soal itu tidak ada.

Normalisasi skor butir ke rentang 0–1: `norm = clamp(score / score_right, 0, 1)`.
Penjepitan bawah diperlukan karena `score_wrong` dan `score_unanswered` boleh
negatif (penalti tebak); tanpa itu tingkat kesukaran bisa keluar dari 0–1 dan
kehilangan makna "proporsi peserta yang menguasai". Tradeoff yang diterima:
selisih antara "salah" dan "tidak menjawab" hilang dari statistik butir —
keduanya jadi 0. Selisih itu tetap disajikan sebagai cacah benar/salah/kosong
di kolom terpisah, jadi tidak hilang dari layar, hanya tidak ikut ke rumus.

## Metrik yang dihitung

Notasi: `N` peserta, `k` butir, `x_ij` skor ternormalisasi peserta *j* pada
butir *i*, `T_j = Σ_i x_ij` total peserta *j*.

**Per butir**

- **Tingkat kesukaran** `P_i = mean_j(x_ij)`.
  Label: `P < 0.30` sukar · `0.30 ≤ P ≤ 0.70` sedang · `P > 0.70` mudah.
- **Daya beda** `D_i = P_atas − P_bawah`, kelompok 27% atas dan 27% bawah
  setelah peserta diurutkan menurun berdasarkan `T_j`.
  Ukuran kelompok `g = max(1, round(0.27 × N))`.
  Label: `D ≥ 0.40` sangat baik · `0.30–0.39` baik · `0.20–0.29` cukup ·
  `0.00–0.19` buruk · `D < 0` sangat buruk.
- **Korelasi butir-total terkoreksi** `r_pb` = korelasi Pearson antara `x_ij`
  dan `T_j − x_ij`. Butirnya dikeluarkan dari total pembanding; tanpa koreksi
  itu setiap butir ikut berkorelasi dengan dirinya sendiri dan angkanya
  menggelembung, paling parah pada tes pendek.
- **Cacah** benar penuh / sebagian / salah / kosong, memakai bendera
  "menjawab" dari `test_logs` (`num_answers > 0` atau `answer_text` terisi).
- **Rekomendasi**: Tolak bila `D < 0.20`, Revisi bila `D < 0.30`, Revisi bila
  `D` memadai tapi `P < 0.20` atau `P > 0.90` (nyaris semua salah / nyaris
  semua benar — tidak ada informasi yang dipanen), selain itu Terima.
  `D < 0` mendapat alasan khusus: **"periksa kunci jawaban"**, karena pola
  "kelompok atas lebih banyak salah" jauh lebih sering berarti kuncinya
  keliru daripada berarti siswa pandai kebetulan salah serempak.

**Per opsi (hanya tipe 1 PG dan tipe 2 PG kompleks)**

- Cacah dan proporsi pemilih, ditandai mana yang kunci.
- Pemilih di kelompok atas vs kelompok bawah.
- `pengecoh mati`: opsi bukan-kunci dengan pemilih `< 5%` peserta.
- `menjebak kelompok atas`: opsi bukan-kunci yang lebih sering dipilih
  kelompok atas daripada kelompok bawah — indikasi soal ambigu atau kunci
  yang perlu ditinjau ulang.

**Seluruh tes**

- Rata-rata dan simpangan baku `T` (sampel, pembagi `N−1`).
- **Cronbach's alpha** `α = k/(k−1) × (1 − Σ var(x_i) / var(T))`.
  Untuk tes yang semua butirnya dikotomis (benar/salah tanpa nilai
  sebagian), α identik dengan KR-20 — jadi satu rumus ini melayani keduanya
  dan tidak ada nilai tambah dari menghitung KR-20 terpisah.
  Label: `≥0.90` sangat tinggi · `0.80–0.89` tinggi · `0.70–0.79` memadai ·
  `0.60–0.69` marginal · `<0.60` rendah.
- **SEM** `= sd(T) × sqrt(1 − α)`, dihitung hanya bila `0 ≤ α ≤ 1`.

## Batas keberlakuan yang ditampilkan di layar

Angka-angka ini adalah statistik sampel, dan sampelnya adalah satu kelas.
Halaman menampilkan peringatan eksplisit, bukan menyembunyikan angkanya:

- `N < 10` → daya beda dan alpha tidak ditampilkan sama sekali; kelompok 27%
  dari 9 peserta berisi 2 orang, dan selisih dua orang bukan pengukuran.
  Tingkat kesukaran dan cacah tetap tampil.
- `10 ≤ N < 30` → semua angka tampil dengan pita peringatan bahwa nilainya
  indikatif; pergeseran satu-dua siswa bisa memindahkan butir antar kategori.
- `k < 2` → alpha tidak terdefinisi (butuh minimal dua butir), sel dikosongkan
  dengan alasan tertulis.
- `var(T) = 0` (semua peserta bernilai sama persis) → alpha tidak terdefinisi;
  rumusnya membagi nol.

Batas 27% dipakai karena itu konvensi yang paling luas dipakai di pengukuran
pendidikan (Kelley), bukan karena optimal untuk tiap distribusi. Saat ada
skor seri tepat di batas kelompok, pemotongan menjadi arbitrer — konsekuensi
yang melekat pada metode ini dan disebutkan di catatan kaki halaman.

## Arsitektur

```
Admin\ItemAnalysisController
  show($testId)     → view shell
  data($testId)     → JSON  (SQL → ItemAnalysis → array)
  export($testId)   → CSV   (jalur data yang sama, penyaji berbeda)
        │
        └── App\Libraries\ItemAnalysis   ← murni: array masuk, array keluar
```

Library tidak menyentuh `\Config\Database`, session, cache, maupun helper
CodeIgniter apa pun, supaya bisa diuji dengan PHPUnit polos seperti
`ProctorActionTest` dan `WebSocketUrlTest` yang sudah ada — tanpa fixture DB.

Tanpa migration. Tanpa tabel baru. Tanpa kolom baru.

## Dampak lintas fitur

Yang **dibaca** (hanya SELECT): `tests`, `test_attempts`, `test_logs`,
`test_log_answers`, `users`. Tidak ada penulisan sama sekali di seluruh jalur
fitur ini — `show`, `data`, dan `export` semuanya read-only.

Yang **ditambah**: satu controller baru, satu library baru, satu view baru,
tiga rute baru di dalam grup `admin` (filter `role:admin,guru` yang sudah
ada), satu tombol di `admin/results/view.php`, satu berkas uji.

Yang **diubah** dari kode lama: `ResultController::view()` menambah satu
variabel `$hasAnalysis` untuk memutuskan tombol tampil atau tidak, dan
`admin/results/view.php` menampilkan tombolnya. `ResultController::view()`
dipanggil dari rute `admin/results/view/(:num)` saja (terverifikasi lewat
pencarian `results/view` di seluruh repo), dan view-nya tidak di-`include`
dari view lain, jadi penambahan variabel tidak mengubah pemanggil lain.

Yang **tidak disentuh**: `ScoringEngine`, `ExamService`, jalur ujian siswa,
WebSocket, kiosk, dan export `ReportController` yang lama — termasuk cacat
`display_order` yang dijelaskan di atas, yang sengaja dibiarkan apa adanya
dalam rancangan ini.

Shared state yang tersentuh: tidak ada. Tidak ada cache key baru, tidak ada
kunci session baru, tidak ada setting baru, tidak ada event/hook.

## Yang tidak dikerjakan

- Membetulkan penjajaran `display_order` di export `test_detail` yang lama.
- Analisis lintas-ujian atau tren butir antar semester.
- Menyimpan hasil analisis ke database (dihitung ulang tiap dibuka; dengan
  N ratusan dan k puluhan, biayanya dua query dan aritmetika O(N×k) —
  menyimpannya hanya menambah masalah basi tanpa imbalan yang sepadan).
- Menandai otomatis soal "Tolak" sebagai `is_enabled = 0` di bank soal.
  Keputusan membuang soal ada di tangan guru, bukan di tangan ambang angka.

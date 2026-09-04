# Analisis Butir Soal Implementation Plan

**Goal:** Memberi guru alat menilai kualitas soal sesudah ujian — tingkat
kesukaran, daya beda, korelasi butir-total, efektivitas pengecoh, dan
reliabilitas — dalam satu layar admin, dengan ekspor CSV.

**Architecture:** Aritmetika di `App\Libraries\ItemAnalysis` (murni, tanpa DB).
Penyusunan matriks dari baris database di `App\Libraries\ItemAnalysisDataset`
(juga murni). Penyajian CSV di `App\Libraries\ItemAnalysisCsv`.
`Admin\ItemAnalysisController` tinggal menjalankan dua query dan merangkai
ketiganya. Semua butir dijajarkan dengan `question_id`, bukan `display_order`.

**Tech Stack:** CodeIgniter 4.7 (PHP 8.4), Alpine.js 3, MariaDB, PHPUnit 10.5.

**Referensi spec:** `docs/superpowers/specs/2026-09-04-analisis-butir-soal-design.md`

---

## Task 1 — Library perhitungan

- [x] `app/Libraries/ItemAnalysis.php`
  - [x] Penyaringan butir: hanya butir dengan skor non-NULL untuk semua peserta
  - [x] Normalisasi `clamp(score / score_right, 0, 1)`
  - [x] Tingkat kesukaran `P` + label sukar/sedang/mudah
  - [x] Daya beda `D` kelompok 27% + label lima kategori
  - [x] Korelasi butir-total terkoreksi (butir dikeluarkan dari pembanding)
  - [x] Cacah benar / sebagian / salah / kosong
  - [x] Efektivitas pengecoh: pengecoh mati (<5%) dan penjebak kelompok atas
  - [x] Cronbach alpha, SEM, dan alasan tertulis saat tidak terhitung
  - [x] Rekomendasi Terima / Revisi / Tolak, dengan alasan "periksa kunci"
        khusus untuk `D < 0`
  - [x] Gerbang `N < 10` (D & alpha ditahan) dan pita indikatif `N < 30`

## Task 2 — Penyusun masukan dari baris database

- [x] `app/Libraries/ItemAnalysisDataset.php`
  - [x] Matriks skor dijajarkan menurut `question_id`
  - [x] Bendera "siswa mengisi": `answer_text` untuk esai/menjodohkan,
        `is_selected` untuk PG (`num_answers` sengaja tidak dipakai — kolom itu
        berisi jumlah opsi yang ditampilkan, bukan jumlah jawaban yang diisi)
  - [x] Penomoran butir: pakai `display_order` hanya bila seragam dan tidak
        bertabrakan; kalau tidak, nomor urut sendiri + catatan di layar
  - [x] Opsi diurutkan menurut `answer_id` supaya stabil walau tampilannya diacak

## Task 3 — Penyaji CSV

- [x] `app/Libraries/ItemAnalysisCsv.php` — ringkasan, tabel butir, blok
      pengecoh, butir yang dikeluarkan, catatan; BOM UTF-8 untuk Excel

## Task 4 — Controller & rute

- [x] `app/Controllers/Admin/ItemAnalysisController.php` (`show`, `data`, `export`)
- [x] Tiga rute GET di grup `admin` (filter `role:admin,guru` yang sudah ada)
- [x] Verifikasi: filter CSRF bawaan hanya memeriksa POST/PUT/DELETE/PATCH,
      jadi ketiga rute GET ini lolos tanpa perlu pengecualian

## Task 5 — Halaman

- [x] `app/Views/admin/results/analysis.php` — kartu ringkasan, tabel butir
      (filter rekomendasi + urutan), baris pengecoh yang bisa dibuka,
      daftar butir yang dikeluarkan, pita catatan
- [x] Tombol "Analisis Butir" di `admin/results/view.php`, muncul hanya bila
      sudah ada attempt selesai

## Task 6 — Perbaikan yang ditemukan sambil jalan

- [x] `layouts/admin` tidak pernah memuat Alpine.js, padahal
      `admin/results/grade.php` (Koreksi Cepat) memakai `x-data`. Halaman itu
      mati sejak dirilis. Kedua halaman kini memuat `vendor/alpinejs/alpine.min.js`
      sendiri — berkas lokal, bukan CDN, agar tetap hidup di server sekolah yang
      jaringan luarnya diblokir saat ujian.
- [x] `[x-cloak]` diberi aturan CSS di halaman baru; layout tidak menyediakannya.

## Task 7 — Uji

- [x] `tests/Analytics/ItemAnalysisTest.php` — 34 uji, angka harapan dihitung
      tangan lebih dulu
- [x] `tests/Analytics/ItemAnalysisDatasetTest.php` — 14 uji, termasuk kasus
      dua peserta melihat soal sama di nomor berbeda
- [x] `tests/Analytics/ItemAnalysisPipelineTest.php` — 15 uji jalur penuh atas
      kelas simulasi 30 peserta berisi satu soal berkunci terbalik, dua
      pengecoh mati, dan satu esai yang belum dikoreksi
- [x] Testsuite `Analytics` didaftarkan di `phpunit.xml.dist`
- [x] Seluruh suite hijau: 105 uji, 269 asersi

## Batas verifikasi

Yang **sudah dijalankan dan diverifikasi runtime**: seluruh aritmetika,
penyusunan matriks, penomoran butir, dan pembentukan CSV — lewat 63 uji baru
yang benar-benar dieksekusi, ditambah satu jalur simulasi yang keluarannya
diperiksa angka per angka (soal berkunci terbalik keluar `D = -1,000`,
`r = -0,873`, alpha kelas 0,802).

Yang **belum diverifikasi runtime**: dua query SQL di
`ItemAnalysisController::hitung()` dan rendering halaman Alpine, karena
lingkungan kerja ini tidak punya MariaDB maupun daemon Docker. Keduanya lolos
`php -l`, tapi `php -l` hanya memeriksa syntax parser — tidak membuktikan
query cocok dengan skema, tidak menangkap salah nama kolom, dan tidak
menjalankan satu baris pun kode. Sebelum dipakai di kelas sungguhan, jalankan:

```bash
./scripts/cmd.sh up -d
# buka /admin/results/view/{testId} pada ujian yang sudah ada attempt selesai,
# klik "Analisis Butir", lalu bandingkan cacah benar/salah dengan
# sheet "Analisis Soal" pada export "Detail Soal" untuk ujian yang
# random_questions-nya MATI (kalau menyala, angkanya memang harus berbeda —
# lihat bagian cacat display_order di spec).
```

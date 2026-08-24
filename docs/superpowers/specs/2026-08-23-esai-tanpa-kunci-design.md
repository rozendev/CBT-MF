# Rancangan: Soal tipe 3 tanpa kunci jawaban

Tanggal: 2026-08-23
Status: disetujui, siap direncanakan

## Masalah

Soal tipe 3 (isian singkat / esai) yang tidak punya kunci jawaban tetap
tersimpan, lalu dinilai mesin dengan mencocokkan jawaban siswa ke kunci yang
kosong. Hasilnya nol untuk setiap siswa, dan nol itu ikut jadi pembagi sehingga
menekan nilai akhir turun. Tidak ada galat, tidak ada peringatan, tidak ada
tanda apa pun di layar guru.

Ditemukan saat memvalidasi impor Word: dua soal esai asli dalam dokumen contoh
("Jelaskan pendapatmu tentang pentingnya menjaga kelestarian hutan…" dan
"Uraikan menurut pendapatmu dampak perkembangan teknologi informasi…") masuk
dengan `answer_mode = exact` dan kunci kosong.

### Bukti

Rantai lengkapnya sudah ditelusuri dan tiap langkah dikonfirmasi di kode:

| Langkah | Yang terjadi | Lokasi |
|---|---|---|
| Parser | tanpa penanda `Tipe: Esai` → `answer_mode = exact`, kunci `''` | `WordQuestionParser.php:213` |
| Validator | tipe 3 sengaja diloloskan tanpa aturan wajib | `WordImportValidator.php:40` |
| Insert | baris jawaban kosong tetap ditulis (`skipValidation(true)`) | `WordImportController.php:160` |
| Penilaian | `exact` + kunci kosong → `return 0` | `ScoringEngine.php:331` |
| Pembagi | soal tetap dihitung: `maxPossiblePoints += score_right` | `ScoringEngine.php:96` |

Diuji langsung lewat reflection pada `ScoringEngine::evaluateEssay()`:

```
jawaban siswa panjang & bagus, kunci KOSONG  -> skor = 0 (dari maks 10)
jawaban siswa benar, kunci terisi            -> skor = 10
jawaban siswa salah, kunci terisi            -> skor = 0
```

### Bukan hanya impor Word

Form manual punya default yang sama. `QuestionController.php:125` dan `:225`
menjatuhkan apa pun yang bukan `'manual'` ke `'exact'`, dan `form.php:133`
memasang `'exact'` sebagai nilai awal dropdown. Guru yang membuat soal tipe 3
tanpa menyentuh dropdown itu akan kena hal yang sama.

Kedua jalur meninggalkan jejak yang **berbeda**, dan ini penting untuk
rancangannya:

- **Impor Word** menyisipkan baris jawaban dengan `description` kosong.
- **Form manual** melewati jawaban kosong (`_saveAnswers`:
  `if (trim($answerText) === '') continue;`), sehingga tidak ada baris jawaban
  sama sekali.

### Yang sudah benar dan tidak perlu dibangun

Jalur `manual` sudah lengkap dan berfungsi:

- Skor `NULL`, bukan 0, sehingga "belum dikoreksi" tidak tertukar dengan
  "dijawab salah" (`ScoringEngine.php:88-93`)
- Dikeluarkan dari pembagi, sehingga nilai yang tampil adalah nilai saat itu
  dan naik sendiri setelah dikoreksi (`ResultController.php:150-155`)
- Ditampilkan sebagai "X soal esai belum dikoreksi" (`detail.php:14-20`)
- Bisa dikoreksi lewat `results/update-score` →
  `ResultController::updateManualScore`, yang menerima skor pecahan bebas

Jadi tujuan perbaikan ini bukan membangun mekanisme baru, melainkan
**mengarahkan soal ke jalur yang sudah bekerja dengan benar**.

## Aturan inti

> Soal tipe 3 dinilai mesin **hanya jika** ada kunci jawaban yang benar-benar
> berisi. Tanpa itu, soal masuk koreksi manual.

Aturan ini sengaja tidak menyebut "esai" atau "isian singkat". Pembedaan itulah
yang selama ini jadi sumber tebakan — dan tebakan berbasis heuristik persis
yang menghasilkan bug 42-soal di impor Word. Yang menentukan bukan jenis
soalnya, melainkan ada tidaknya kunci: fakta yang bisa diperiksa, bukan
ditafsirkan.

## Perubahan

### 1. Impor Word — `WordQuestionParser.php:213`

Kunci kosong ikut berarti `manual`, sejajar dengan penanda `Tipe: Esai` yang
sudah ada.

### 2. Form manual — `QuestionController.php:125` dan `:225`

Selain nilai dropdown `answer_mode`, ikut memeriksa apakah POST `answers`
mengandung teks non-kosong. Tidak ada → `manual`.

### 3. Pagar penilaian — `ScoringEngine.php:86` dan `:213`

Dua situs, keduanya harus diubah:

```php
if ($log->answer_mode === 'manual' || !$this->hasUsableKey($logAnswers)) {
```

dengan satu helper:

```php
private function hasUsableKey(array $answers): bool
{
    $key = reset($answers);
    return $key && trim((string) ($key->answer_text ?? '')) !== '';
}
```

Helper ini menangani kedua jejak sekaligus: `reset()` mengembalikan `false`
kalau tidak ada baris (jalur form manual), dan `trim()` menangkap baris kosong
(jalur impor Word).

Pagar ini yang membuat rancangan ini dipilih dibanding sekadar memperbaiki
default di sumber. Alasannya dua:

1. **Menyembuhkan data lama secara otomatis.** Soal tipe 3 berkunci kosong yang
   sudah terlanjur tersimpan — termasuk yang ikut masuk ke subjek "SUMATIF
   AKHIR SEMESTER" — tidak perlu di-`UPDATE`. Penilaian berjalan saat ujian
   difinalisasi, jadi soal-soal itu langsung berpindah ke antrean koreksi tanpa
   migration.
2. **Tidak bisa dibobol dari jalur mana pun.** Mau soal masuk lewat Word, form
   manual, impor Excel di masa depan, atau `INSERT` langsung ke database,
   hasilnya tetap benar. Aturan ditegakkan di titik pemakaian, bukan hanya di
   titik pembuatan.

Lebih dalam dari itu: `exact` dengan kunci kosong adalah **keadaan yang tidak
masuk akal** — mesin diminta mencocokkan persis dengan "tidak ada apa-apa".
Menolak keadaan itu di titik penilaian lebih benar daripada sekadar mencegahnya
terbentuk.

## Perilaku yang dihasilkan

| Keadaan soal | Sekarang | Sesudah |
|---|---|---|
| Tipe 3, kunci terisi | skor otomatis | tidak berubah |
| Tipe 3, ditandai `Tipe: Esai` | `NULL`, antre koreksi | tidak berubah |
| Tipe 3, kunci kosong (impor Word) | **0 diam-diam** | `NULL`, antre koreksi |
| Tipe 3, kunci kosong (form manual) | **0 diam-diam** | `NULL`, antre koreksi |
| Soal lama yang sudah tersimpan dengan kunci kosong | **0 diam-diam** | `NULL`, antre koreksi |

## Pengujian

- **Parser**: tipe 3 dengan kunci kosong menghasilkan `answer_mode = manual`;
  tipe 3 dengan `Jawaban:` terisi tetap `exact`; penanda `Tipe: Esai` tetap
  `manual`.
- **`hasUsableKey`**: tiga keadaan — tidak ada baris jawaban, baris jawaban
  kosong, baris jawaban berisi.
- **`ScoringEngine`** diuji lewat reflection, karena kelas itu butuh koneksi
  database. Pola ini sudah dipakai saat membuktikan bug-nya.

Semuanya masuk suite yang sudah ada di `src/tests/WordImport/`. `phpunit`
sekarang terpasang di container (sebelumnya `composer install --no-dev`), jadi
suite bisa dijalankan penuh.

## Di luar lingkup

- **Layar koreksi cepat** — navigasi antar siswa untuk satu soal, `↑` nilai
  penuh, `↓` nol, `1-9` parsial, `←`/`→` pindah siswa, `u` urungkan. Disepakati
  jadi spec kedua tersendiri, supaya perbaikan defect ini tidak tertahan oleh
  perancangan UX fitur baru.
- **Perintah CLI perbaikan data** yang meng-`UPDATE` soal lama menjadi
  `answer_mode = manual`. Dengan pagar penilaian, perilakunya sudah benar tanpa
  itu; nilainya tinggal kerapian data saat dilihat langsung di database.

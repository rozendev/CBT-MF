# Format Import Soal dari Word yang Lebih Humane

Status: Approved (brainstorming), belum diimplementasikan.

## Latar belakang

`WordImportController` (`src/app/Controllers/Admin/WordImportController.php`) mengimport soal dari `.docx` dengan format bersyntax eksplisit ala kode: `Q:1)`, `A:)`, `RIGHT:B`, `TYPE:ESSAY`, `MATCH:Kiri|::|Kanan`. Guru non-teknis harus hafal prefix ini persis; salah ketik sedikit → soal gagal terparsing atau ke-skip diam-diam, dan pesan error validasi menyebut "Soal No. #3" tanpa konteks yang mudah dicari di dokumen Word aslinya.

Empat masalah yang ingin diselesaikan:
1. Prefix terlalu kaku, guru sering typo/lupa.
2. Guru terbiasa menulis soal ala Word biasa (numbering/bullet bawaan Word), bukan ngetik prefix manual.
3. Pesan error validasi kurang membantu (tidak jelas baris mana di dokumen yang salah).
4. Field `TYPE:`/`RIGHT:`/`MATCH:` terasa seperti coding, bukan menulis soal.

Kompatibilitas ke belakang **tidak** diperlukan — format lama boleh diganti total, tidak perlu dipertahankan sebagai fallback.

## Format baru

### Soal & opsi Pilihan Ganda

```
1. Siapa penemu bola lampu?
A. Albert Einstein
*B. Thomas Alva Edison
C. Isaac Newton
D. Nikola Tesla

2. Pilihlah semua jawaban yang merupakan nama benua:
*A. Asia
B. Pasifik
*C. Eropa
D. Hindia
*E. Afrika
```

Aturan:
- **Soal baru** dikenali dari salah satu:
  - baris diawali angka + titik/kurung, mis. `1.` atau `1)`, diikuti spasi lalu teks soal; atau
  - paragraf tsb adalah item pertama pada list bernomor/bullet **bawaan Word** (level 0), terdeteksi lewat metadata `ListItemRun` yang dibaca PhpWord — guru tidak perlu mengetik angka apa pun kalau pakai tombol Numbering/Bullet di Word.
- **Opsi** dikenali dari salah satu:
  - baris diawali huruf + titik/kurung, mis. `A.` atau `A)` (dengan `*` opsional tepat di depan huruf); atau
  - item list bawaan Word level 1+ di bawah soal tsb.
  - Huruf opsi (A, B, C, …) **selalu di-assign otomatis** berurutan sesuai urutan kemunculan di dalam soal — simbol asli yang dirender Word (angka/huruf/bullet apa pun) diabaikan sepenuhnya, sistem tidak bergantung padanya.
- **Jawaban benar** ditandai dengan `*` tepat di awal opsi:
  - ditulis manual: `*B. Teks opsi` (bintang sebelum huruf).
  - lewat list bawaan Word (tanpa huruf yang diketik): bintang jadi karakter pertama teks item, mis. teks list item `*Thomas Alva Edison`.
  - Boleh lebih dari satu opsi ber-`*` dalam satu soal → otomatis jadi PG Kompleks. Kalau cuma satu → PG Tunggal.
- Teks soal/opsi tetap boleh multi-baris, memuat gambar, dan tabel referensi (bukan tabel pasangan) — perilaku ini tidak berubah dari sekarang.

### Esai

```
5. Siapa nama presiden pertama Republik Indonesia?
Jawaban: Ir. Soekarno
```

- Terdeteksi otomatis kalau soal **tidak punya opsi berlabel** dan **tidak diikuti tabel pasangan bertipe Menjodohkan/Benar-Salah** — tidak perlu penanda `TYPE:ESSAY` sama sekali.
- Baris `Jawaban: ...` **opsional**. Kalau diisi, disimpan sebagai referensi jawaban (konsisten dengan field "Kunci Jawaban" di form tambah soal manual). Kalau kosong, tetap valid — sesuai kenyataan bahwa kunci esai saat ini tidak ditampilkan di layar koreksi jawaban siswa (`admin/results/detail.php`), jadi tidak wajib diisi.

### Menjodohkan & Benar/Salah

Satu-satunya dua tipe yang masih butuh penanda eksplisit — karena bentuknya (tabel di bawah soal) bisa disalahartikan sebagai tabel data referensi biasa (seperti contoh "Soal dengan Tabel" di template lama, yang tetap harus tetap berfungsi sebagai tabel referensi, bukan tabel pasangan).

```
3. Pasangkan negara berikut dengan ibukotanya!
Tipe: Menjodohkan
| Negara     | Ibukota |
|------------|---------|
| Indonesia  | Jakarta |
| Jepang     | Tokyo   |
| Korea Sel. | Seoul   |

4. Tentukan benar atau salah pernyataan berikut!
Tipe: Benar/Salah
| Pernyataan                  | Jawaban |
|------------------------------|---------|
| Matahari terbit dari timur  | Benar   |
| Bumi itu berbentuk datar    | Salah   |
```

Aturan:
- Penanda `Tipe: Menjodohkan` atau `Tipe: Benar/Salah` (case-insensitive, slash opsional) ditulis sebagai baris tersendiri di bawah soal.
- Tabel Word harus persis di bawah baris `Tipe:` tsb, sebelum soal berikutnya dimulai. Tabel di tempat lain (tanpa `Tipe:` mendahuluinya) tetap diperlakukan sebagai tabel referensi biasa (perilaku existing, tidak berubah).
- **Baris pertama tabel selalu dilewati** sebagai judul kolom (guru bebas isi apa saja, termasuk kosong).
- Baris berikutnya: kolom kiri = premis, kolom kanan = jawaban.
  - Menjodohkan: kolom kanan bebas teks apa saja.
  - Benar/Salah: kolom kanan harus berisi teks "Benar" atau "Salah" (tidak case-sensitive, boleh ada spasi di pinggir) — nilai lain dianggap error validasi.
- Baris dengan salah satu sel kosong → error validasi (dikutip isi barisnya).
- Format penyimpanan internal ke DB **tidak berubah**: tetap disimpan sebagai `left|::|right` per baris jawaban (`type` 4/5), supaya halaman koreksi jawaban siswa dan bagian lain yang sudah membaca format ini tidak perlu disentuh.

### Ringkasan deteksi tipe

| Kondisi terdeteksi | Tipe |
|---|---|
| Ada opsi berlabel A/B/C dengan tepat 1 opsi ber-`*` | PG Tunggal |
| Ada opsi berlabel A/B/C dengan >1 opsi ber-`*` | PG Kompleks |
| Tidak ada opsi, tidak ada tabel pasangan | Esai (kunci opsional via `Jawaban:`) |
| Ada `Tipe: Menjodohkan` + tabel 2 kolom | Menjodohkan |
| Ada `Tipe: Benar/Salah` + tabel 2 kolom (kolom kanan Benar/Salah) | Benar-Salah |

## Pesan error yang lebih manusiawi

Setiap pesan error validasi menyertakan **kutipan cuplikan teks soal** (bukan cuma nomor urut soal), supaya guru bisa Ctrl+F di Word untuk menemukan baris yang salah. Contoh:

- PG tanpa opsi ber-`*`: `Soal "Pilihlah semua jawaban yang merupakan nama benua..." belum ada opsi yang ditandai (*) sebagai jawaban benar.`
- PG kurang dari 2 opsi: `Soal "..." harus punya minimal 2 pilihan jawaban.`
- Menjodohkan tanpa tabel: `Soal "..." bertipe Menjodohkan tapi tidak ditemukan tabel pasangan di bawahnya.`
- Benar/Salah dengan nilai kolom kanan tidak valid: `Soal "..." baris "Bumi itu berbentuk datar" → "Salahh" bukan "Benar"/"Salah" yang valid.`
- Baris tabel pasangan dengan sel kosong: `Soal "..." baris pasangan "Jepang" → (kosong) tidak lengkap.`

Cuplikan diambil dari `strip_tags()` teks soal, dipotong ~50-60 karakter dengan `...` kalau lebih panjang.

## Perubahan arsitektur implementasi

1. **Struktur `$blocks`**: dari `string[]` polos menjadi array entri terstruktur. Tiap entri membawa:
   - untuk paragraf teks: teks + `is_list_item` (bool) + `list_depth` (int, dari `ListItemRun::getDepth()` — PhpWord reader sudah otomatis mengubah paragraf ber-`numPr` di `.docx` jadi elemen `ListItemRun` saat load, jadi list bawaan Word bisa dideteksi tanpa parsing XML manual tambahan).
   - untuk tabel: HTML (dipakai kalau ternyata tabel referensi biasa, sama seperti sekarang) **plus** data mentah per-baris/per-sel dalam bentuk array teks (dipakai kalau ternyata tabel pasangan Menjodohkan/Benar-Salah).
2. **`parseBlocks()` ditulis ulang** sebagai state machine yang membaca blok terstruktur ini (bukan string mentah), menerapkan aturan soal-baru/opsi-baru/`*`/`Tipe:`/`Jawaban:` di atas. Ini menggantikan total logika `explicit_type`/`RIGHT:`/`MATCH:` yang lama.
3. **Penentuan `type` & pembuatan jawaban** di `process()` disesuaikan dengan hasil parsing baru (deteksi otomatis dari jumlah opsi ber-`*`, keberadaan tabel pasangan, dst) — tapi bentuk data yang di-insert ke `QuestionModel`/`AnswerModel` **tidak berubah** dari sekarang.
4. **`validateParsedQuestions()` ditulis ulang** mengikuti aturan & pesan error baru di atas.
5. **`downloadTemplate()` digambar ulang total** memakai `addListItem`/tabel PhpWord, mengikuti format baru, supaya jadi contoh yang valid kalau diimport ulang (dogfooding).
6. **Panduan format** di view `admin/questions/word_import.php` ditulis ulang mengikuti contoh-contoh di atas.

Tidak ada perubahan skema database maupun `QuestionModel`/`AnswerModel`.

## Testing

Karena logika baru bergantung pada bagaimana PhpWord membaca numbering/table asli dari `.docx` (bukan cuma parsing string), rencana implementasi perlu menyiapkan fixture `.docx` uji (dibangun lewat PhpWord writer, mirip pendekatan `downloadTemplate()`) untuk menguji jalur native-list dan tabel-pasangan, di samping unit test parsing string biasa untuk jalur angka/huruf polos dan pesan error.

## Trade-off yang disadari

- Regex angka polos (`1.`, `1)`) di awal paragraf berpotensi salah tangkap kalau ada paragraf body/tabel yang kebetulan diawali pola serupa (mis. "10. lantai gedung..." sebagai lanjutan kalimat). Ini risiko yang sama seperti regex huruf-opsi (`A.`) yang sudah diterima di format lama — dianggap trade-off yang wajar demi kesederhanaan, bukan bug yang perlu dicegah dengan aturan tambahan.
- Huruf opsi hasil list bawaan Word tidak dijamin visually match dengan simbol asli yang dirender Word (mis. guru pakai list gaya "a, b, c" tapi sistem tetap label internal "A, B, C") — kosmetik saja, tidak berdampak ke soal yang ditampilkan ke siswa karena UI ujian me-render label opsinya sendiri, independen dari dokumen sumber.

## Di luar cakupan

- Deteksi jawaban benar lewat Bold/Highlight formatting (dipertimbangkan, tidak dipilih — asterisk lebih andal untuk diparsing).
- Baris pasangan bergaya `Kiri = Kanan` sebagai alternatif tabel untuk Menjodohkan/Benar-Salah (dipertimbangkan, tidak dipilih — tabel dipilih sebagai satu-satunya cara).
- Pembersihan file gambar orphan yang tersimpan ke disk saat validasi gagal (masalah lama yang sudah ada, di luar scope perubahan format ini).

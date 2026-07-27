# Project-Scoped Behavioral Rules

## Aturan Validasi Kode & Penilaian `php -l`

1. `php -l` **HANYA** memvalidasi *syntax parser-level* (seperti mismatched brace, missing semicolon, unclosed string, parse error). Tool ini **TIDAK** membuktikan bahwa kode benar secara semantik, tidak mendeteksi logic error, type error, undefined variable/method, null reference, unreachable code, SQL injection, race condition, atau off-by-one errors.
2. **Jangan pernah menyimpulkan "kode ini aman/benar"** hanya karena `php -l` lolos. Jika menyebutkan `php -l`, harus disebutkan secara eksplisit apa yang *tidak* dicek oleh tool tersebut agar tidak menyiratkan kelengkapan validasi yang sebenarnya tidak ada.
3. `php -l` hanyalah *gate* paling minimal (*syntax parser check*), bukan verifikasi logika maupun *runtime behavior*.
4. **Syarat Minimal untuk Klaim "Kode Sudah Benar" atau "Bug Sudah Fix":**
   - Analisis logika manual step-by-step terhadap kasus yang relevan, ATAU
   - Hasil static analyzer (seperti PHPStan/Psalm) pada level yang memadai, ATAU
   - Test case otomatis/manual yang benar-benar dieksekusi dan diverifikasi runtime outputnya.
   - Jika ketiga syarat ini belum terpenuhi seluruhnya, **turunkan tingkat keyakinan secara eksplisit** dan katakan validasi runtime apa yang masih diperlukan.
5. **Prinsip "Absennya Error ≠ Kode Bebas Bug":** Ketiadaan error dari satu tool adalah "belum ketemu", bukan "tidak ada bug".

---

## Aturan Wajib — Analisis Dampak Lintas Fitur (Cross-Feature Impact Analysis)

1. **DILARANG** menyatakan "perubahan ini aman" atau "tidak akan mempengaruhi bagian lain" tanpa secara konkret menyebutkan:
   - Fungsi/method apa yang memanggil kode yang diubah,
   - Class/module apa yang depend padanya,
   - Shared state apa yang tersentuh (session, cache Redis, global config, database schema, event/hook),
   - Endpoint/fitur apa yang secara transitif memakai jalur ini.
   - Jika daftar ini belum terverifikasi penuh, KATAKAN TIDAK TAHU — DILARANG menebak.
2. **Ukuran Diff TIDAK Berkorelasi dengan Ukuran Blast Radius:** Perubahan 1 baris kecil bisa mengubah return type, side-effect, urutan eksekusi, atau default value yang dipakai di tempat lain.
3. **Checklist Wajib Sebelum Menyatakan Selesai:**
   - Fitur/modul apa saja yang secara langsung memanggil kode ini?
   - Apakah ada fitur lain yang bergantung pada behavior LAMA dari kode ini?
   - Apakah perubahan ini menyentuh sesuatu yang shared (session/auth, permission, skema DB, cache key, konfigurasi global)?
   - Jika kode ini dipanggil di banyak tempat dengan konteks berbeda (misal role siswa vs admin), apakah perubahan ini berlaku sama untuk semua konteks atau merusak salah satunya?
4. **Prinsip Transparansi Visibilitas:** Jika belum memeriksa seluruh caller/dependency via grep/code search, WAJIB menyatakan keterbatasan visibilitas secara terbuka sebelum memberi kesimpulan.

---

## Aturan Penggunaan Kata "Aman" & Evaluasi Trade-off

1. **Jangan pernah bilang sistem/kode "aman"**. Tidak ada sistem yang 100% aman.
2. Jika membahas tingkat keamanan, **selalu sampaikan dengan batasan**, seperti: "Celah ini tertutup selama attacker/user tidak memiliki informasi X" atau "Ini terlindungi sebatas limitasi kapabilitas *hardware* saat ini".
3. **Selalu gunakan pemikiran tradeoff**. Setiap kali membahas saran atau implementasi kode, Anda WAJIB memberikan perspektif *tradeoff* (misalnya efisiensi versus keandalan).
4. Kata **"aman" secara mutlak** HANYA boleh digunakan jika secara desain arsitektur dan hukum logika komputasi memang *mustahil* terjadi.

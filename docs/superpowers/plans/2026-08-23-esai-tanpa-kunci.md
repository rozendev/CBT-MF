# Esai Tanpa Kunci Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Soal tipe 3 tanpa kunci jawaban yang benar-benar berisi tidak lagi dinilai 0 diam-diam — ia masuk antrean koreksi manual (skor `NULL`, keluar dari pembagi).

**Architecture:** Tiga lapis sesuai spec `docs/superpowers/specs/2026-08-23-esai-tanpa-kunci-design.md`: (1) pagar penilaian di `ScoringEngine` menolak `exact` dengan kunci kosong di titik pemakaian — ini juga menyembuhkan data lama tanpa migration; (2) parser impor Word memilih `manual` saat kunci kosong; (3) form manual melakukan hal sama saat POST tidak membawa teks kunci. Pagar dikerjakan lebih dulu karena dialah inti perbaikannya.

**Tech Stack:** PHP 8.5 / CodeIgniter 4.7, PHPUnit 10 (suite `WordImport`), Docker Compose (service `php`, mount `./src` → `/var/www/html`).

---

## Catatan lingkungan (WAJIB dibaca sebelum mengerjakan)

- **Jangan pakai git worktree.** Container `php` hanya me-mount repo utama (`./src:/var/www/html`); menjalankan tes dari worktree akan diam-diam menguji salinan lain. Semua pekerjaan langsung di checkout utama, branch `feature/cbt-cli-upgrade` (identik dengan `origin/development` saat rencana ini ditulis).
- **Semua PHP/phpunit dijalankan lewat container** (service bernama `php`, bukan `ex_*`; vendor sudah ada di host dan ter-mount):
  ```bash
  # seluruh suite
  docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
  # satu file
  docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit tests/WordImport/WordQuestionParserTest.php'
  # lint satu file
  docker compose exec -T php php -l app/Controllers/Admin/QuestionController.php
  ```
- Keluaran sukses phpunit: `OK (N tests, M assertions)`.

### Task 0: Rapikan sisa kerja sebelumnya

**Files:**
- Modify: `src/tests/WordImport/WordQuestionParserTest.php` (sudah berubah di working tree, belum di-commit)

Working tree masih memuat penambahan tes bullet (pasangan dari commit `50f1389` yang kemarin terlanjur dipush tanpa tesnya). Commit dulu supaya commit-commit berikutnya bersih.

- [ ] **Step 1: Pastikan hanya file ini yang mengganggu**

```bash
git status --porcelain
```
Expected: hanya ` M src/tests/WordImport/WordQuestionParserTest.php`.

- [ ] **Step 2: Commit tes bullet**

```bash
git add src/tests/WordImport/WordQuestionParserTest.php
git commit -m "test(word-import): lengkapi tes bullet depth 0 untuk perbaikan parser" -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 1: Pagar penilaian — `ScoringEngine::hasUsableKey()` + dua situs gate

**Files:**
- Create: `src/tests/WordImport/ScoringEngineKeyGateTest.php`
- Modify: `src/app/Libraries/ScoringEngine.php:86` (site 1, `calculateAndSaveScore`)
- Modify: `src/app/Libraries/ScoringEngine.php:213` (site 2, `calculateScorePreview`)
- Modify: `src/app/Libraries/ScoringEngine.php` (helper baru sebelum `evaluateEssay()`, ±baris 323)

Latar: `evaluateEssay()` (`ScoringEngine.php:326`) mengembalikan `0` kalau kunci kosong, dan soal tetap masuk pembagi (`maxPossiblePoints += score_right`). Kunci bisa kosong lewat dua jejak: impor Word menyimpan baris jawaban ber-`description` kosong, form manual tidak menyimpan baris sama sekali. Helper `reset()` menangkap jejak kedua, `trim()` menangkap jejak pertama.

- [ ] **Step 1: Tulis tes yang gagal (reflection, pola yang sudah dipakai saat membuktikan bug)**

Buat `src/tests/WordImport/ScoringEngineKeyGateTest.php`:

```php
<?php

namespace Tests\WordImport;

use App\Libraries\ScoringEngine;
use PHPUnit\Framework\TestCase;

class ScoringEngineKeyGateTest extends TestCase
{
    private function invokeHasUsableKey(array $answers): bool
    {
        // ScoringEngine menyentuh database hanya di method publiknya;
        // kelasnya sendiri bisa dibuat tanpa koneksi.
        $method = new \ReflectionMethod(ScoringEngine::class, 'hasUsableKey');
        $method->setAccessible(true);

        return $method->invoke(new ScoringEngine(), $answers);
    }

    public function testNoAnswerRowsIsNotUsable(): void
    {
        // Jejak form manual: tidak ada baris jawaban tersimpan.
        $this->assertFalse($this->invokeHasUsableKey([]));
    }

    public function testBlankAnswerRowIsNotUsable(): void
    {
        // Jejak impor Word: baris ada, tapi description-nya kosong.
        $this->assertFalse($this->invokeHasUsableKey([
            (object) ['answer_text' => ''],
            (object) ['answer_text' => '   '],
            (object) ['answer_text' => null],
        ]));
    }

    public function testFilledAnswerRowIsUsable(): void
    {
        $this->assertTrue($this->invokeHasUsableKey([
            (object) ['answer_text' => 'Ir. Soekarno'],
        ]));
    }
}
```

- [ ] **Step 2: Jalankan tes, pastikan gagal karena method belum ada**

```bash
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit tests/WordImport/ScoringEngineKeyGateTest.php'
```
Expected: ERROR — `ReflectionException: Method App\Libraries\ScoringEngine::hasUsableKey() does not exist`.

- [ ] **Step 3: Pasang helper + dua pagar di `ScoringEngine.php`**

(a) Site 1, dalam `calculateAndSaveScore()` — ganti baris 86:

```php
            } elseif ($log->question_type == 3) {
                if ($log->answer_mode === 'manual' || !$this->hasUsableKey($logAnswers)) {
                    // Esai: mesin tidak menilai. Skornya NULL, bukan 0, supaya
                    // "belum dikoreksi" tidak tertukar dengan "dijawab salah".
                    // Ikut dikeluarkan dari pembagi agar nilai sementara tidak
                    // tertekan turun oleh soal yang memang belum dinilai.
                    // Tanpa kunci terpakai pun diperlakukan sama — pagar ini
                    // menyembuhkan data lama berkunci kosong tanpa migration.
                    $logsUpdateBatch[] = ['id' => $log->log_id, 'score' => null];
                    continue;
                }
```

(b) Site 2, dalam `calculateScorePreview()` — ganti baris 213:

```php
            } elseif ($log->question_type == 3) {
                if ($log->answer_mode === 'manual' || !$this->hasUsableKey($logAnswers)) {
                    continue; // Esai/kunci kosong menunggu koreksi guru; lihat calculateAndSaveScore().
                }
```

(c) Helper baru, tepat sebelum `evaluateEssay()` di akhir kelas:

```php
    /**
     * Kunci jawaban tipe 3 layak dinilai mesin hanya bila benar-benar berisi.
     * Dua jejak data harus tertangani: form manual tidak menyimpan baris
     * jawaban sama sekali (reset() == false), impor Word menyimpan baris
     * ber-teks kosong (trim()). `exact` tanpa kunci adalah keadaan yang tidak
     * masuk akal — mesin diminta mencocokkan persis dengan "tidak ada apa-apa".
     */
    private function hasUsableKey(array $answers): bool
    {
        $key = reset($answers);

        return $key && trim((string) ($key->answer_text ?? '')) !== '';
    }
```

Jangan ubah `evaluateEssay()` — cabang kosongnya kini tak terjangkau lewat dua gate, tetap dipertahankan sebagai pertahanan kedua.

- [ ] **Step 4: Jalankan tes, pastikan lulus**

```bash
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
```
Expected: `OK` (semua tes, termasuk 3 tes baru).

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/ScoringEngine.php src/tests/WordImport/ScoringEngineKeyGateTest.php
git commit -m "fix(scoring): soal tipe 3 tanpa kunci terpakai masuk antrean koreksi manual" -m "Pagar di titik pemakaian (calculateAndSaveScore + calculateScorePreview): exact dengan kunci kosong diperlakukan seperti manual — skor NULL dan keluar dari pembagi. Data lama ikut sembuh tanpa migration." -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 2: Parser impor Word — kunci kosong berarti `manual`

**Files:**
- Modify: `src/tests/WordImport/WordQuestionParserTest.php` (tambah assertion + 1 tes)
- Modify: `src/app/Libraries/WordImport/WordQuestionParser.php:213` (`finalize()`)

- [ ] **Step 1: Tulis assertion yang gagal**

Di `WordQuestionParserTest.php`, perkuat dua tes lama dan tambah satu tes baru:

`testQuestionWithoutOptionsDefaultsToEssay` — tambah di akhir method:

```php
        $this->assertSame('manual', $questions[0]['answer_mode']);
```

`testJawabanLineIsStoredAsOptionalAnswerKey` — tambah di akhir method:

```php
        $this->assertSame('exact', $questions[0]['answer_mode']);
```

Tes baru (letakkan setelah `testJawabanLineIsStoredAsOptionalAnswerKey`):

```php
    public function testDeclaredEsaiMarkerStaysManualEvenWithEmptyKey(): void
    {
        $blocks = [
            $this->line('5. Jelaskan pendapatmu tentang pentingnya menjaga kelestarian hutan.'),
            $this->line('Tipe: Esai'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('manual', $questions[0]['answer_mode']);
    }
```

- [ ] **Step 2: Jalankan, pastikan tepat satu assertion gagal**

```bash
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit tests/WordImport/WordQuestionParserTest.php'
```
Expected: FAIL pada `testQuestionWithoutOptionsDefaultsToEssay` — `Failed asserting that two strings are identical. '-expected: manual' '+actual: exact'`. Dua kasus lain sudah lulus (perilaku lama sudah benar untuk keduanya).

- [ ] **Step 3: Ubah `finalize()` — ganti baris 213**

```php
        $q['answer_mode'] = 'exact';
        if ($q['type'] === 3) {
            // Sejajar dengan penanda "Tipe: Esai": tanpa kunci yang benar-benar
            // berisi, satu-satunya penilaian yang jujur adalah koreksi guru.
            // Mengizinkan exact berkunci kosong berarti mesin mencocokkan
            // persis dengan "tidak ada apa-apa" dan selalu menghasilkan 0.
            $hasKey = trim($q['answer_key']) !== '';
            $q['answer_mode'] = ($q['declared_answer_mode'] === 'manual' || !$hasKey) ? 'manual' : 'exact';
        }
```

(Tipe 1/2/4/5 tetap `'exact'` — kuncinya hidup di opsi/pasangan, bukan di `answer_key`.)

- [ ] **Step 4: Jalankan seluruh suite, pastikan lulus**

```bash
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
```
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/WordImport/WordQuestionParser.php src/tests/WordImport/WordQuestionParserTest.php
git commit -m "fix(word-import): tipe 3 berkunci kosong memilih jalur koreksi manual" -m "Sejajar dengan penanda Tipe: Esai — kunci kosong tidak boleh berarti exact, karena mesin hanya akan menghasilkan nol." -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 3: Form manual — `QuestionController` menegakkan aturan yang sama

**Files:**
- Modify: `src/app/Controllers/Admin/QuestionController.php:125-127` (`store()`)
- Modify: `src/app/Controllers/Admin/QuestionController.php:225-227` (`update()`)
- Modify: `src/app/Controllers/Admin/QuestionController.php` (helper privat dekat `_saveAnswers()`, ±baris 395)

Catatan: tidak ada tes unit untuk controller ini (butuh DB + session CI4; konsisten dengan batas suite yang ada). Yang menjaga kebenarannya adalah pagar Task 1 — perubahan di sini hanya kerapian di titik pembuatan.

- [ ] **Step 1: Ganti ternary di `store()` (baris 125-127)**

```php
            // Hanya bermakna untuk tipe 3. Tipe lain dikunci ke 'exact' supaya
            // nilainya tidak menyesatkan kalau tipenya diubah belakangan.
            // Dropdown default 'exact'; bila guru tidak mengisi teks kunci,
            // paksa 'manual' agar soalnya antre koreksi, bukan dinilai 0.
            'answer_mode'    => $type === 3
                ? (($this->request->getPost('answer_mode') === 'manual' || !$this->_postHasAnswerKey()) ? 'manual' : 'exact')
                : 'exact',
```

- [ ] **Step 2: Ganti ternary yang identik di `update()` (baris 225-227) dengan blok yang sama persis**

```php
            // Hanya bermakna untuk tipe 3. Tipe lain dikunci ke 'exact' supaya
            // nilainya tidak menyesatkan kalau tipenya diubah belakangan.
            // Dropdown default 'exact'; bila guru tidak mengisi teks kunci,
            // paksa 'manual' agar soalnya antre koreksi, bukan dinilai 0.
            'answer_mode'    => $type === 3
                ? (($this->request->getPost('answer_mode') === 'manual' || !$this->_postHasAnswerKey()) ? 'manual' : 'exact')
                : 'exact',
```

- [ ] **Step 3: Tambah helper privat setelah `_saveAnswers()` (sebelum kurung tutup kelas)**

```php
    /**
     * True bila POST answers memuat minimal satu teks kunci non-kosong —
     * kriteria yang sama dengan _saveAnswers() saat memutuskan baris mana
     * yang ditulis. Bentuk field: array teks ber-key huruf/indeks.
     */
    private function _postHasAnswerKey(): bool
    {
        $answers = $this->request->getPost('answers') ?? [];

        if (!is_array($answers)) {
            return trim((string) $answers) !== '';
        }

        foreach ($answers as $text) {
            if (trim((string) $text) !== '') {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Lint + pastikan suite tetap hijau**

```bash
docker compose exec -T php php -l app/Controllers/Admin/QuestionController.php
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
```
Expected: `No syntax errors detected`, lalu `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Admin/QuestionController.php
git commit -m "fix(question): form tanpa teks kunci menyimpan answer_mode manual" -m "Dropdown default 'exact' membuat soal tipe 3 yang dilewatkan begitu saja jatuh ke penilaian mesin berkunci kosong; kini ketiadaan teks kunci memaksa jalur manual." -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Verifikasi akhir (setelah semua task)

- [ ] Suite penuh hijau: `docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'`
- [ ] `git log --oneline development..HEAD` memuat 5 commit baru (1 rapikan tes + 4 fitur/fix)
- [ ] Perilaku ujung-ke-ujung cocok tabel spec bagian "Perilaku yang dihasilkan" (verifikasi manual opsional: finalisasi attempt dengan soal tipe 3 berkunci kosong → skornya `NULL` di `test_logs`, nilai akhir naik setelah dikoreksi)

## Di luar lingkup (sesuai spec)

- Layar koreksi cepat (navigasi siswa, shortcut keyboard) — spec terpisah.
- Perintah CLI perbaikan data lama — pagar Task 1 sudah membuat perilakunya benar tanpa migration.

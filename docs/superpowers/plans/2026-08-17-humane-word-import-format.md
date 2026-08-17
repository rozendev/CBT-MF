# Format Import Soal Word yang Lebih Humane — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti total format penulisan soal `.docx` untuk fitur Import Soal dari Word — dari syntax ala-kode (`Q:`, `RIGHT:`, `TYPE:`, `MATCH:...|::|...`) menjadi format natural (angka/huruf polos atau list bawaan Word, tanda `*` untuk jawaban benar, tabel 2 kolom untuk Menjodohkan/Benar-Salah) — sesuai spec di `docs/superpowers/specs/2026-08-17-humane-word-import-format-design.md`.

**Architecture:** Pecah logika `WordImportController` yang sekarang jadi 3 class kecil yang bisa diuji terpisah tanpa CodeIgniter (`App\Libraries\WordImport\WordBlockExtractor`, `WordQuestionParser`, `WordImportValidator`), lalu controller jadi orkestrator tipis: load `.docx` → extract blocks → parse jadi soal → validasi → insert ke DB (bentuk insert DB tidak berubah). `downloadTemplate()` dan panduan format di view digambar ulang mengikuti format baru.

**Tech Stack:** PHP 8.2+ / CodeIgniter 4, PhpOffice/PhpWord ^1.4 (sudah terpasang), PHPUnit 10.5 (perlu diaktifkan — saat ini vendor di container ter-install `--no-dev` jadi PHPUnit belum ada).

---

## Sebelum mulai — catatan penting

- **Environment ini pakai `sudo bash scripts/cbt.sh <cmd>` sebagai satu-satunya cara menjalankan `composer`/`php` di container `ex_php`** (bukan `docker compose exec` langsung) — ini konvensi proyek, ikuti persis.
- Vendor `src/vendor` saat ini hasil `composer install --no-dev`, jadi `phpunit` belum ada. Task 1 menjalankan `composer install` (tanpa flag `--no-dev`) untuk menambahkan dev-dependencies **sesuai versi yang sudah dikunci di `composer.lock`** (bukan `composer update` — jadi deterministik, tidak menarik versi baru). Ini akan menulis ke `src/vendor` yang di-mount ke container yang sedang berjalan (`ex_php`, `ex_nginx`, dst tampak live dengan `cloudflared` — kemungkinan stack ini diakses dari luar). Perubahan ini aman & reversibel (`composer install --no-dev` mengembalikan seperti semula), tapi **beri tahu user sebelum menjalankan Task 1** kalau plan ini dieksekusi di sesi baru tanpa konteks percakapan ini.
- `src/.gitignore` meng-ignore `/phpunit.xml` (konvensi CodeIgniter4) — karena itu file config PHPUnit di plan ini bernama **`phpunit.xml.dist`** (ter-track git), bukan `phpunit.xml`. PHPUnit otomatis memakai `phpunit.xml.dist` kalau `phpunit.xml` tidak ada.
- Semua class baru (`WordBlockExtractor`, `WordQuestionParser`, `WordImportValidator`) sengaja dibuat **tanpa dependency ke CodeIgniter** (tidak butuh DB/session/FCPATH langsung) supaya bisa diuji dengan PHPUnit murni tanpa bootstrap CI4 yang berat — ini kenapa Task 1 cukup ringan (cuma `require vendor/autoload.php`).
- Konfirmasi kunci dari source code PhpWord (`src/vendor/phpoffice/phpword/src/PhpWord/Reader/Word2007/AbstractPart.php:284-289`): saat membaca `.docx`, PhpWord otomatis mengubah paragraf yang punya `w:numPr` (list/numbering bawaan Word) jadi elemen `ListItemRun` dengan `getDepth()` yang benar. Ini fondasi deteksi list bawaan Word di Task 3 — **diverifikasi lewat test round-trip di Task 3, bukan diasumsikan begitu saja.**

---

### Task 1: Aktifkan PHPUnit di project

**Files:**
- Create: `src/phpunit.xml.dist`
- Create: `src/tests/bootstrap.php`
- Create: `src/tests/WordImport/SmokeTest.php`

- [ ] **Step 1: Install dev-dependencies (termasuk PHPUnit) sesuai composer.lock**

Run: `sudo bash scripts/cbt.sh composer install`

Expected: output composer menampilkan `Installing phpunit/phpunit (...)` di antara paket-paket dev lain, diakhiri `Generating optimized autoload files`.

- [ ] **Step 2: Verifikasi PHPUnit sudah bisa dijalankan**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit --version`

Expected: `PHPUnit 10.5.x by Sebastian Bergmann and contributors.`

- [ ] **Step 3: Buat bootstrap test minimal**

Buat `src/tests/bootstrap.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 4: Buat konfigurasi PHPUnit**

Buat `src/phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory="writable/.phpunit.cache">
    <testsuites>
        <testsuite name="WordImport">
            <directory>tests/WordImport</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: Tulis smoke test**

Buat `src/tests/WordImport/SmokeTest.php`:

```php
<?php

namespace Tests\WordImport;

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testPhpUnitIsWorking(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 6: Jalankan test suite, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit --testsuite WordImport`

Expected: `OK (1 test, 1 assertion)`

- [ ] **Step 7: Commit**

```bash
git add src/phpunit.xml.dist src/tests/bootstrap.php src/tests/WordImport/SmokeTest.php
git commit -m "test: aktifkan PHPUnit untuk testing WordImport" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Fixture builder untuk dokumen .docx uji

**Files:**
- Create: `src/tests/_support/WordFixtureBuilder.php`
- Test: `src/tests/_support/WordFixtureBuilderTest.php`

`composer.json` sudah punya `"autoload-dev": {"psr-4": {"Tests\\Support\\": "tests/_support"}}` — namespace `Tests\Support` sudah dikonfigurasi, tinggal dipakai.

- [ ] **Step 1: Tulis test untuk fixture builder**

Buat `src/tests/_support/WordFixtureBuilderTest.php`:

```php
<?php

namespace Tests\Support;

use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\TestCase;

class WordFixtureBuilderTest extends TestCase
{
    public function testBuildDocxReturnsLoadablePath(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addText('Halo dunia');
        });

        $this->assertFileExists($path);

        $phpWord = IOFactory::load($path);
        $sections = $phpWord->getSections();
        $this->assertCount(1, $sections);

        unlink($path);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal (class belum ada)**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/_support/WordFixtureBuilderTest.php`

Expected: FAIL — `Class "Tests\Support\WordFixtureBuilder" not found`

- [ ] **Step 3: Implementasikan fixture builder**

Buat `src/tests/_support/WordFixtureBuilder.php`:

```php
<?php

namespace Tests\Support;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Helper untuk bikin file .docx sementara di test, lewat PhpWord writer,
 * lalu dibaca ulang lewat IOFactory::load() — supaya test benar-benar
 * memverifikasi jalur baca (Reader), bukan cuma struktur objek PhpWord.
 */
class WordFixtureBuilder
{
    /**
     * @param callable(\PhpOffice\PhpWord\Element\Section): void $build
     */
    public static function buildDocx(callable $build): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $build($section);

        $path = tempnam(sys_get_temp_dir(), 'wordimport_fixture_') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/_support/WordFixtureBuilderTest.php`

Expected: `OK (1 test, 2 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/tests/_support/WordFixtureBuilder.php src/tests/_support/WordFixtureBuilderTest.php
git commit -m "test: tambah WordFixtureBuilder untuk bikin fixture .docx" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: `WordBlockExtractor` — paragraf, list bawaan Word, gambar, tabel

**Files:**
- Create: `src/app/Libraries/WordImport/WordBlockExtractor.php`
- Test: `src/tests/WordImport/WordBlockExtractorTest.php`

Class ini menggantikan `extractTextUsingPhpWord()`/`processPhpWordElement()` yang lama (`WordImportController.php:305-392`). Output-nya array blok terstruktur:
- `['kind' => 'line', 'text' => string, 'is_list_item' => bool, 'list_depth' => int]`
- `['kind' => 'table', 'html' => string, 'rows' => array<array<string>>]`

- [ ] **Step 1: Test — paragraf teks biasa jadi block `line`**

Buat `src/tests/WordImport/WordBlockExtractorTest.php`:

```php
<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordBlockExtractor;
use PhpOffice\PhpWord\IOFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\WordFixtureBuilder;

class WordBlockExtractorTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/wordimport_uploads_' . uniqid() . '/';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            array_map('unlink', glob($this->uploadDir . '*') ?: []);
            rmdir($this->uploadDir);
        }
    }

    public function testPlainParagraphBecomesLineBlock(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addText('1. Siapa penemu bola lampu?');
            $section->addText('*B. Thomas Alva Edison');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(2, $blocks);
        $this->assertSame('line', $blocks[0]['kind']);
        $this->assertSame('1. Siapa penemu bola lampu?', $blocks[0]['text']);
        $this->assertFalse($blocks[0]['is_list_item']);
        $this->assertSame(0, $blocks[0]['list_depth']);
        $this->assertSame('*B. Thomas Alva Edison', $blocks[1]['text']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: FAIL — `Class "App\Libraries\WordImport\WordBlockExtractor" not found`

- [ ] **Step 3: Implementasi awal — hanya paragraf teks biasa**

Buat `src/app/Libraries/WordImport/WordBlockExtractor.php`:

```php
<?php

namespace App\Libraries\WordImport;

use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\PhpWord;

/**
 * Membaca objek PhpWord hasil IOFactory::load() dan mengubahnya jadi array
 * blok terstruktur (paragraf/tabel) yang siap dibaca WordQuestionParser.
 *
 * Lihat docs/superpowers/specs/2026-08-17-humane-word-import-format-design.md
 * untuk aturan format lengkapnya.
 */
class WordBlockExtractor
{
    private const ALLOWED_IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];

    private string $uploadDir;
    private string $uploadUrlPrefix;

    public function __construct(?string $uploadDir = null, string $uploadUrlPrefix = '/uploads/questions/')
    {
        $this->uploadDir = $uploadDir ?? (defined('FCPATH') ? FCPATH . 'uploads/questions/' : sys_get_temp_dir() . '/uploads/questions/');
        $this->uploadUrlPrefix = $uploadUrlPrefix;
    }

    /** @return array<int, array<string, mixed>> */
    public function extract(PhpWord $phpWord): array
    {
        $blocks = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $blocks = array_merge($blocks, $this->processElement($element));
            }
        }
        return $blocks;
    }

    /** @return array<int, array<string, mixed>> */
    private function processElement($element): array
    {
        if (method_exists($element, 'getElements')) {
            return $this->processRun($element);
        }

        if (method_exists($element, 'getText')) {
            $text = trim($element->getText());
            return $text === '' ? [] : [$this->line(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false, 0)];
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function processRun($element): array
    {
        $paragraphText = '';
        foreach ($element->getElements() as $child) {
            if ($child instanceof TextBreak) {
                $paragraphText .= "\n";
            } elseif (method_exists($child, 'getText')) {
                $paragraphText .= htmlspecialchars($child->getText(), ENT_QUOTES, 'UTF-8');
            }
        }

        $lines = explode("\n", $paragraphText);
        $blocks = [];
        foreach ($lines as $rawLine) {
            $lineText = trim($rawLine);
            if ($lineText === '') {
                continue;
            }
            $blocks[] = $this->line($lineText, false, 0);
        }
        return $blocks;
    }

    private function line(string $text, bool $isListItem, int $depth): array
    {
        return [
            'kind'         => 'line',
            'text'         => $text,
            'is_list_item' => $isListItem,
            'list_depth'   => $depth,
        ];
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: `OK (1 test, 5 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/WordImport/WordBlockExtractor.php src/tests/WordImport/WordBlockExtractorTest.php
git commit -m "feat(word-import): WordBlockExtractor - paragraf teks biasa" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Test — list bawaan Word (numbering/bullet) terdeteksi dengan depth yang benar**

Tambahkan method test ke `src/tests/WordImport/WordBlockExtractorTest.php` (di dalam class yang sama):

```php
    public function testNativeWordListItemsCarryDepthMetadata(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $section->addListItem('Ibukota Jepang adalah?', 0, null, 'listLevel0');
            $section->addListItem('Osaka', 1, null, 'listLevel1');
            $section->addListItem('*Tokyo', 1, null, 'listLevel1');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(3, $blocks);

        $this->assertTrue($blocks[0]['is_list_item']);
        $this->assertSame(0, $blocks[0]['list_depth']);
        $this->assertSame('Ibukota Jepang adalah?', $blocks[0]['text']);

        $this->assertTrue($blocks[1]['is_list_item']);
        $this->assertSame(1, $blocks[1]['list_depth']);
        $this->assertSame('Osaka', $blocks[1]['text']);

        $this->assertTrue($blocks[2]['is_list_item']);
        $this->assertSame(1, $blocks[2]['list_depth']);
        $this->assertSame('*Tokyo', $blocks[2]['text']);
    }
```

- [ ] **Step 7: Jalankan test, pastikan gagal (depth masih selalu 0/false)**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: FAIL pada assertion `assertTrue($blocks[0]['is_list_item'])` — ini membuktikan asumsi "PhpWord reader mengubah numPr jadi ListItemRun" perlu benar-benar disambungkan ke kode, bukan cuma diasumsikan.

- [ ] **Step 8: Tambahkan deteksi ListItemRun/ListItem**

Di `src/app/Libraries/WordImport/WordBlockExtractor.php`, ubah `processElement()` dan `processRun()`:

```php
    private function processElement($element): array
    {
        if (method_exists($element, 'getElements')) {
            return $this->processRun($element);
        }

        if (method_exists($element, 'getText')) {
            $text = trim($element->getText());
            return $text === '' ? [] : [$this->line(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false, 0)];
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function processRun($element): array
    {
        $isListItem = $element instanceof ListItemRun || $element instanceof ListItem;
        $depth = $isListItem ? $element->getDepth() : 0;

        if ($element instanceof ListItem) {
            // ListItem (beda dari ListItemRun) membungkus satu Text tunggal.
            $paragraphText = htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
        } else {
            $paragraphText = '';
            foreach ($element->getElements() as $child) {
                if ($child instanceof TextBreak) {
                    $paragraphText .= "\n";
                } elseif (method_exists($child, 'getText')) {
                    $paragraphText .= htmlspecialchars($child->getText(), ENT_QUOTES, 'UTF-8');
                }
            }
        }

        $lines = explode("\n", $paragraphText);
        $blocks = [];
        $isFirstLine = true;
        foreach ($lines as $rawLine) {
            $lineText = trim($rawLine);
            if ($lineText === '') {
                continue;
            }
            $blocks[] = $this->line($lineText, $isListItem && $isFirstLine, $isFirstLine ? $depth : 0);
            $isFirstLine = false;
        }
        return $blocks;
    }
```

(`ListItem` di sini hampir tidak pernah muncul saat *membaca* `.docx` asli — PhpWord reader selalu memakai `addListItemRun()`, lihat `AbstractPart.php:284-289` — tapi ditangani juga untuk jaga-jaga & konsistensi tipe.)

- [ ] **Step 9: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: `OK (2 tests, 11 assertions)`

- [ ] **Step 10: Commit**

```bash
git add src/app/Libraries/WordImport/WordBlockExtractor.php src/tests/WordImport/WordBlockExtractorTest.php
git commit -m "feat(word-import): deteksi list bawaan Word (ListItemRun) di WordBlockExtractor" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 11: Test — gambar inline & standalone tersimpan ke disk dan jadi tag `<img>`**

Tambahkan ke `src/tests/WordImport/WordBlockExtractorTest.php`:

```php
    public function testStandaloneImageIsSavedAndReferencedAsImgTag(): void
    {
        // 1x1 pixel PNG transparan, base64-encoded.
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $imgPath = tempnam(sys_get_temp_dir(), 'wordimport_img_') . '.png';
        file_put_contents($imgPath, $pngData);

        $path = WordFixtureBuilder::buildDocx(function ($section) use ($imgPath) {
            $section->addImage($imgPath, ['width' => 50, 'height' => 50]);
        });
        unlink($imgPath);

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertSame('line', $blocks[0]['kind']);
        $this->assertStringContainsString('<img src="/uploads/questions/', $blocks[0]['text']);

        $savedFiles = glob($this->uploadDir . '*.png');
        $this->assertCount(1, $savedFiles);
    }
```

- [ ] **Step 12: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: FAIL — block kosong (gambar belum ditangani, hanya elemen yang punya `getElements()`/`getText()` yang diproses).

- [ ] **Step 13: Tambahkan penanganan gambar**

Di `src/app/Libraries/WordImport/WordBlockExtractor.php`, ubah `processElement()` dan `processRun()`, tambahkan `saveImageAndBuildTag()`:

```php
    private function processElement($element): array
    {
        if ($element instanceof Image) {
            $tag = $this->saveImageAndBuildTag($element);
            return $tag === null ? [] : [$this->line($tag, false, 0)];
        }

        if (method_exists($element, 'getElements')) {
            return $this->processRun($element);
        }

        if (method_exists($element, 'getText')) {
            $text = trim($element->getText());
            return $text === '' ? [] : [$this->line(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false, 0)];
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function processRun($element): array
    {
        $isListItem = $element instanceof ListItemRun || $element instanceof ListItem;
        $depth = $isListItem ? $element->getDepth() : 0;

        if ($element instanceof ListItem) {
            $paragraphText = htmlspecialchars($element->getText(), ENT_QUOTES, 'UTF-8');
        } else {
            $paragraphText = '';
            foreach ($element->getElements() as $child) {
                if ($child instanceof TextBreak) {
                    $paragraphText .= "\n";
                } elseif ($child instanceof Image) {
                    $tag = $this->saveImageAndBuildTag($child);
                    if ($tag !== null) {
                        $paragraphText .= '<br>' . $tag . '<br>';
                    }
                } elseif (method_exists($child, 'getText')) {
                    $paragraphText .= htmlspecialchars($child->getText(), ENT_QUOTES, 'UTF-8');
                }
            }
        }

        $lines = explode("\n", $paragraphText);
        $blocks = [];
        $isFirstLine = true;
        foreach ($lines as $rawLine) {
            $lineText = trim($rawLine);
            if ($lineText === '') {
                continue;
            }
            $blocks[] = $this->line($lineText, $isListItem && $isFirstLine, $isFirstLine ? $depth : 0);
            $isFirstLine = false;
        }
        return $blocks;
    }

    private function saveImageAndBuildTag(Image $image): ?string
    {
        $raw = $image->getImageStringData();
        if (!$raw) {
            return null;
        }
        $ext = strtolower($image->getImageExtension());
        if (!in_array($ext, self::ALLOWED_IMAGE_EXTS, true)) {
            return null;
        }
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
        $filename = uniqid('img_') . '.' . $ext;
        @file_put_contents($this->uploadDir . $filename, $raw);
        $src = rtrim($this->uploadUrlPrefix, '/') . '/' . $filename;
        return '<img src="' . $src . '" style="max-width:100%; height:auto; margin:10px 0;" class="img-fluid rounded shadow-sm">';
    }
```

- [ ] **Step 14: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: `OK (3 tests, 14 assertions)`

- [ ] **Step 15: Commit**

```bash
git add src/app/Libraries/WordImport/WordBlockExtractor.php src/tests/WordImport/WordBlockExtractorTest.php
git commit -m "feat(word-import): ekstraksi gambar inline/standalone di WordBlockExtractor" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 16: Test — tabel jadi block `table` dengan `html` dan `rows`**

Tambahkan ke `src/tests/WordImport/WordBlockExtractorTest.php`:

```php
    public function testTableBecomesTableBlockWithHtmlAndRawRows(): void
    {
        $path = WordFixtureBuilder::buildDocx(function ($section) {
            $table = $section->addTable();
            $table->addRow();
            $table->addCell(2000)->addText('Negara');
            $table->addCell(2000)->addText('Ibukota');
            $table->addRow();
            $table->addCell(2000)->addText('Indonesia');
            $table->addCell(2000)->addText('Jakarta');
        });

        $phpWord = IOFactory::load($path);
        $blocks = (new WordBlockExtractor($this->uploadDir))->extract($phpWord);
        unlink($path);

        $this->assertCount(1, $blocks);
        $this->assertSame('table', $blocks[0]['kind']);
        $this->assertStringContainsString('<table', $blocks[0]['html']);
        $this->assertStringContainsString('Jakarta', $blocks[0]['html']);
        $this->assertSame(
            [['Negara', 'Ibukota'], ['Indonesia', 'Jakarta']],
            $blocks[0]['rows']
        );
    }
```

- [ ] **Step 17: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: FAIL — `Table` belum ditangani, jatuh ke cabang `getElements()` yang salah bentuk hasilnya (atau `[]`).

- [ ] **Step 18: Tambahkan penanganan tabel**

Di `src/app/Libraries/WordImport/WordBlockExtractor.php`, tambahkan pengecekan `Table` di awal `processElement()`, dan tambahkan method `processTable()`:

```php
    private function processElement($element): array
    {
        if ($element instanceof Table) {
            return [$this->processTable($element)];
        }

        if ($element instanceof Image) {
            $tag = $this->saveImageAndBuildTag($element);
            return $tag === null ? [] : [$this->line($tag, false, 0)];
        }

        if (method_exists($element, 'getElements')) {
            return $this->processRun($element);
        }

        if (method_exists($element, 'getText')) {
            $text = trim($element->getText());
            return $text === '' ? [] : [$this->line(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false, 0)];
        }

        return [];
    }
```

```php
    private function processTable(Table $table): array
    {
        $htmlRows = [];
        $rawRows = [];

        foreach ($table->getRows() as $row) {
            $htmlCells = [];
            $rawCells = [];
            foreach ($row->getCells() as $cell) {
                $cellBlocks = [];
                foreach ($cell->getElements() as $cellElement) {
                    $cellBlocks = array_merge($cellBlocks, $this->processElement($cellElement));
                }
                $htmlCells[] = implode('<br>', array_map(
                    fn (array $b) => $b['kind'] === 'table' ? $b['html'] : $b['text'],
                    $cellBlocks
                ));
                $rawCells[] = trim(implode(' ', array_map(
                    fn (array $b) => trim(strip_tags($b['kind'] === 'table' ? $b['html'] : $b['text'])),
                    $cellBlocks
                )));
            }
            $htmlRows[] = $htmlCells;
            $rawRows[] = $rawCells;
        }

        $html = '<div class="table-responsive my-3"><table class="table table-bordered table-sm" style="border-collapse: collapse; width: 100%;" border="1">';
        foreach ($htmlRows as $htmlCells) {
            $html .= '<tr>';
            foreach ($htmlCells as $cellHtml) {
                $html .= '<td style="padding: 8px; border: 1px solid #dee2e6;">' . $cellHtml . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></div>';

        return [
            'kind' => 'table',
            'html' => $html,
            'rows' => $rawRows,
        ];
    }
```

- [ ] **Step 19: Jalankan test, pastikan lulus (dan tidak merusak test sebelumnya)**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordBlockExtractorTest.php`

Expected: `OK (4 tests, 18 assertions)`

- [ ] **Step 20: Commit**

```bash
git add src/app/Libraries/WordImport/WordBlockExtractor.php src/tests/WordImport/WordBlockExtractorTest.php
git commit -m "feat(word-import): ekstraksi tabel (html + raw rows) di WordBlockExtractor" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: `WordQuestionParser` — soal, opsi, tanda bintang, tipe PG/PGK/Esai

**Files:**
- Create: `src/app/Libraries/WordImport/WordQuestionParser.php`
- Test: `src/tests/WordImport/WordQuestionParserTest.php`

Class ini menggantikan `parseBlocks()` lama + logika penentuan `type` yang sebelumnya ada di `process()` (`WordImportController.php:126-140`). Input: array block dari `WordBlockExtractor`. Output tiap soal:

```
[
  'question'   => string,          // HTML (boleh ada <br>, <img>, <table>)
  'type'       => int,             // 1=PG Tunggal, 2=PGK, 3=Esai, 4=Menjodohkan, 5=Benar-Salah
  'options'    => ['A' => 'text', 'B' => 'text', ...],
  'correct'    => ['A', 'C', ...], // huruf yang ditandai *
  'answer_key' => string,          // kunci esai opsional, '' kalau tidak diisi
  'matches'    => array|null,      // [['left'=>.., 'right'=>..], ...] untuk type 4/5
]
```

- [ ] **Step 1: Test — satu soal PG Tunggal dengan tanda bintang manual**

Buat `src/tests/WordImport/WordQuestionParserTest.php`:

```php
<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordQuestionParser;
use PHPUnit\Framework\TestCase;

class WordQuestionParserTest extends TestCase
{
    private function line(string $text, bool $isListItem = false, int $depth = 0): array
    {
        return ['kind' => 'line', 'text' => $text, 'is_list_item' => $isListItem, 'list_depth' => $depth];
    }

    public function testSingleChoiceQuestionWithStarredOption(): void
    {
        $blocks = [
            $this->line('1. Siapa penemu bola lampu?'),
            $this->line('A. Albert Einstein'),
            $this->line('*B. Thomas Alva Edison'),
            $this->line('C. Isaac Newton'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $q = $questions[0];
        $this->assertSame('Siapa penemu bola lampu?', $q['question']);
        $this->assertSame(1, $q['type']);
        $this->assertSame(
            ['A' => 'Albert Einstein', 'B' => 'Thomas Alva Edison', 'C' => 'Isaac Newton'],
            $q['options']
        );
        $this->assertSame(['B'], $q['correct']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: FAIL — `Class "App\Libraries\WordImport\WordQuestionParser" not found`

- [ ] **Step 3: Implementasi inti — soal, opsi, bintang, resolusi tipe PG/PGK/Esai**

Buat `src/app/Libraries/WordImport/WordQuestionParser.php`:

```php
<?php

namespace App\Libraries\WordImport;

/**
 * Mengubah array blok terstruktur (dari WordBlockExtractor) jadi daftar soal
 * siap divalidasi & disimpan.
 *
 * Lihat docs/superpowers/specs/2026-08-17-humane-word-import-format-design.md
 * untuk aturan format lengkapnya.
 */
class WordQuestionParser
{
    private const QUESTION_NUMBER_RE = '/^\d+\s*[.\-):]+\s*(.*)$/';
    private const OPTION_LETTER_RE   = '/^(\*?)([A-Za-z])\s*[.\-):]+\s*(.*)$/';

    /** @return array<int, array<string, mixed>> */
    public function parse(array $blocks): array
    {
        $questions = [];
        $current = null;
        $section = 'none'; // 'question' | 'option' | 'none'
        $lastOptionLetter = null;

        foreach ($blocks as $block) {
            if ($block['kind'] === 'table') {
                $current = $this->handleTable($current, $block);
                $section = 'none';
                continue;
            }

            $text = trim($block['text']);
            if ($text === '') {
                continue;
            }

            $questionText = $this->matchQuestionBoundary($block, $text);
            if ($questionText !== null) {
                if ($current !== null && $current['question'] !== '') {
                    $questions[] = $this->finalize($current);
                }
                $current = $this->emptyQuestion();
                $current['question'] = $questionText;
                $section = 'question';
                $lastOptionLetter = null;
                continue;
            }

            if ($current === null) {
                continue;
            }

            $option = $this->matchOptionBoundary($block, $text);
            if ($option !== null) {
                $letter = $option['letter'] ?? $this->nextLetter($current['options']);
                $current['options'][$letter] = $option['text'];
                if ($option['is_correct']) {
                    $current['correct'][] = $letter;
                }
                $section = 'option';
                $lastOptionLetter = $letter;
                continue;
            }

            if ($section === 'question') {
                $current['question'] .= ($current['question'] !== '' ? '<br>' : '') . $text;
            } elseif ($section === 'option' && $lastOptionLetter !== null) {
                $current['options'][$lastOptionLetter] .= '<br>' . $text;
            }
        }

        if ($current !== null && $current['question'] !== '') {
            $questions[] = $this->finalize($current);
        }

        return $questions;
    }

    private function handleTable(?array $current, array $block): ?array
    {
        if ($current === null) {
            return $current;
        }
        // Tanpa "Tipe: Menjodohkan/Benar-Salah" (Task 6), tabel selalu dianggap
        // tabel referensi biasa dan ditempel ke body soal.
        $current['question'] .= ($current['question'] !== '' ? '<br>' : '') . $block['html'];
        return $current;
    }

    private function matchQuestionBoundary(array $block, string $text): ?string
    {
        if ($block['is_list_item'] && $block['list_depth'] === 0) {
            return $text;
        }
        if (preg_match(self::QUESTION_NUMBER_RE, $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** @return array{letter: ?string, text: string, is_correct: bool}|null */
    private function matchOptionBoundary(array $block, string $text): ?array
    {
        if (preg_match(self::OPTION_LETTER_RE, $text, $m)) {
            return [
                'letter'     => strtoupper($m[2]),
                'text'       => trim($m[3]),
                'is_correct' => $m[1] === '*',
            ];
        }
        if ($block['is_list_item'] && $block['list_depth'] >= 1) {
            $isCorrect = str_starts_with($text, '*');
            return [
                'letter'     => null,
                'text'       => $isCorrect ? trim(substr($text, 1)) : $text,
                'is_correct' => $isCorrect,
            ];
        }
        return null;
    }

    private function nextLetter(array $options): string
    {
        return chr(65 + count($options));
    }

    private function emptyQuestion(): array
    {
        return [
            'question'   => '',
            'options'    => [],
            'correct'    => [],
            'answer_key' => '',
            'matches'    => null,
        ];
    }

    private function finalize(array $q): array
    {
        if (!empty($q['options'])) {
            $q['type'] = count($q['correct']) > 1 ? 2 : 1;
        } else {
            $q['type'] = 3; // Esai: tidak ada opsi berlabel.
        }
        return $q;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: `OK (1 test, 4 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/WordImport/WordQuestionParser.php src/tests/WordImport/WordQuestionParserTest.php
git commit -m "feat(word-import): WordQuestionParser - PG dengan tanda bintang" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Test tambahan — PGK (multi-bintang), soal/opsi lewat list bawaan Word, dan esai default (tanpa opsi)**

Tambahkan ke `src/tests/WordImport/WordQuestionParserTest.php`:

```php
    public function testMultipleStarredOptionsBecomeComplexMultipleChoice(): void
    {
        $blocks = [
            $this->line('2. Pilihlah semua jawaban yang merupakan nama benua:'),
            $this->line('*A. Asia'),
            $this->line('B. Pasifik'),
            $this->line('*C. Eropa'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(2, $questions[0]['type']);
        $this->assertSame(['A', 'C'], $questions[0]['correct']);
    }

    public function testQuestionAndOptionsFromNativeWordList(): void
    {
        $blocks = [
            $this->line('Ibukota Jepang adalah?', true, 0),
            $this->line('Osaka', true, 1),
            $this->line('*Tokyo', true, 1),
            $this->line('Kyoto', true, 1),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertCount(1, $questions);
        $q = $questions[0];
        $this->assertSame('Ibukota Jepang adalah?', $q['question']);
        $this->assertSame(
            ['A' => 'Osaka', 'B' => 'Tokyo', 'C' => 'Kyoto'],
            $q['options']
        );
        $this->assertSame(['B'], $q['correct']);
        $this->assertSame(1, $q['type']);
    }

    public function testQuestionWithoutOptionsDefaultsToEssay(): void
    {
        $blocks = [
            $this->line('5. Jelaskan pendapatmu tentang lingkungan.'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('', $questions[0]['answer_key']);
    }
```

- [ ] **Step 7: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: `OK (4 tests, 15 assertions)` — semua lulus tanpa perubahan kode implementasi (langkah ini murni memverifikasi perilaku yang sudah ada dari Step 3, termasuk jalur list bawaan Word).

- [ ] **Step 8: Commit**

```bash
git add src/tests/WordImport/WordQuestionParserTest.php
git commit -m "test(word-import): cakupan PGK, list bawaan Word, dan default esai" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: `WordQuestionParser` — kunci jawaban esai opsional (`Jawaban:`)

**Files:**
- Modify: `src/app/Libraries/WordImport/WordQuestionParser.php`
- Modify: `src/tests/WordImport/WordQuestionParserTest.php`

- [ ] **Step 1: Test — baris `Jawaban:` disimpan sebagai `answer_key`, tidak dianggap opsi**

Tambahkan ke `src/tests/WordImport/WordQuestionParserTest.php`:

```php
    public function testJawabanLineIsStoredAsOptionalAnswerKey(): void
    {
        $blocks = [
            $this->line('4. Siapa nama presiden pertama Republik Indonesia?'),
            $this->line('Jawaban: Ir. Soekarno'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(3, $questions[0]['type']);
        $this->assertSame('Ir. Soekarno', $questions[0]['answer_key']);
        $this->assertSame([], $questions[0]['options']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: FAIL — `answer_key` masih `''` karena baris `Jawaban: Ir. Soekarno` saat ini ikut ke-parse sebagai teks lanjutan soal (bukan opsi, bukan boundary baru — jatuh ke cabang continuation `$section === 'question'`).

- [ ] **Step 3: Tambahkan penanganan `Jawaban:`**

Di `src/app/Libraries/WordImport/WordQuestionParser.php`, tambahkan konstanta dan pengecekan di awal `parse()` (sebelum pengecekan question boundary):

```php
    private const QUESTION_NUMBER_RE = '/^\d+\s*[.\-):]+\s*(.*)$/';
    private const OPTION_LETTER_RE   = '/^(\*?)([A-Za-z])\s*[.\-):]+\s*(.*)$/';
    private const JAWABAN_RE         = '/^Jawaban\s*:\s*(.*)$/i';
```

Di dalam loop `parse()`, tepat setelah blok `if ($text === '') { continue; }` dan sebelum `$questionText = $this->matchQuestionBoundary(...)`:

```php
            if (preg_match(self::JAWABAN_RE, $text, $m)) {
                if ($current !== null) {
                    $current['answer_key'] = trim($m[1]);
                }
                $section = 'none';
                continue;
            }

```

- [ ] **Step 4: Jalankan test, pastikan lulus (dan tidak merusak test lain)**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: `OK (5 tests, 18 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/WordImport/WordQuestionParser.php src/tests/WordImport/WordQuestionParserTest.php
git commit -m "feat(word-import): kunci jawaban esai opsional lewat baris Jawaban:" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: `WordQuestionParser` — Menjodohkan & Benar/Salah lewat tabel

**Files:**
- Modify: `src/app/Libraries/WordImport/WordQuestionParser.php`
- Modify: `src/tests/WordImport/WordQuestionParserTest.php`

- [ ] **Step 1: Test — `Tipe: Menjodohkan` + tabel jadi pasangan (baris pertama dilewati sebagai judul)**

Tambahkan ke `src/tests/WordImport/WordQuestionParserTest.php`:

```php
    private function table(array $rows): array
    {
        return ['kind' => 'table', 'html' => '<table></table>', 'rows' => $rows];
    }

    public function testMatchingTypeConsumesTableAsPairsSkippingHeaderRow(): void
    {
        $blocks = [
            $this->line('3. Pasangkan negara berikut dengan ibukotanya!'),
            $this->line('Tipe: Menjodohkan'),
            $this->table([
                ['Negara', 'Ibukota'],
                ['Indonesia', 'Jakarta'],
                ['Jepang', 'Tokyo'],
            ]),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(4, $questions[0]['type']);
        $this->assertSame(
            [
                ['left' => 'Indonesia', 'right' => 'Jakarta'],
                ['left' => 'Jepang', 'right' => 'Tokyo'],
            ],
            $questions[0]['matches']
        );
    }

    public function testTrueFalseTypeUsesSameTableMechanism(): void
    {
        $blocks = [
            $this->line('4. Tentukan benar atau salah pernyataan berikut!'),
            $this->line('Tipe: Benar/Salah'),
            $this->table([
                ['Pernyataan', 'Jawaban'],
                ['Matahari terbit dari timur', 'Benar'],
                ['Bumi itu berbentuk datar', 'Salah'],
            ]),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(5, $questions[0]['type']);
        $this->assertSame('Benar', $questions[0]['matches'][0]['right']);
    }

    public function testDeclaredPairTypeWithoutTableStillResolvesToThatType(): void
    {
        $blocks = [
            $this->line('3. Pasangkan negara berikut dengan ibukotanya!'),
            $this->line('Tipe: Menjodohkan'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(4, $questions[0]['type']);
        $this->assertSame([], $questions[0]['matches']);
    }

    public function testPlainTableWithoutTipeMarkerStaysAsReferenceTable(): void
    {
        $blocks = [
            $this->line('7. Soal dengan tabel data:'),
            $this->table([
                ['Nama', 'Usia'],
                ['Andi', '15 Tahun'],
            ]),
            $this->line('Berapa usia Andi?'),
            $this->line('*A. 15 Tahun'),
        ];

        $questions = (new WordQuestionParser())->parse($blocks);

        $this->assertSame(1, $questions[0]['type']);
        $this->assertNull($questions[0]['matches']);
        $this->assertStringContainsString('<table></table>', $questions[0]['question']);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: FAIL pada 3 test pertama — `Tipe: Menjodohkan`/`Tipe: Benar/Salah` saat ini masih ikut jadi teks lanjutan soal, dan tabel selalu ditempel sebagai HTML referensi (test ke-4 `testPlainTableWithoutTipeMarkerStaysAsReferenceTable` sudah lulus dari awal).

- [ ] **Step 3: Tambahkan deteksi `Tipe:` dan penanganan tabel-pasangan**

Di `src/app/Libraries/WordImport/WordQuestionParser.php`, tambahkan konstanta:

```php
    private const TIPE_RE = '/^Tipe\s*:\s*(Menjodohkan|Benar\s*\/?\s*Salah)/i';
```

Tambahkan pengecekan `Tipe:` di `parse()`, tepat sebelum pengecekan `JAWABAN_RE`:

```php
            if (preg_match(self::TIPE_RE, $text, $m)) {
                if ($current !== null) {
                    $current['declared_pair_type'] = stripos($m[1], 'Menjodohkan') !== false ? 'MENJODOHKAN' : 'BENARSALAH';
                }
                $section = 'none';
                continue;
            }

```

Ubah `emptyQuestion()`, tambahkan key baru:

```php
    private function emptyQuestion(): array
    {
        return [
            'question'           => '',
            'options'            => [],
            'correct'            => [],
            'answer_key'         => '',
            'matches'            => null,
            'declared_pair_type' => null,
        ];
    }
```

Ubah `handleTable()`:

```php
    private function handleTable(?array $current, array $block): ?array
    {
        if ($current === null) {
            return $current;
        }
        if (($current['declared_pair_type'] ?? null) !== null && $current['matches'] === null) {
            $current['matches'] = $this->rowsToMatches($block['rows']);
            return $current;
        }
        $current['question'] .= ($current['question'] !== '' ? '<br>' : '') . $block['html'];
        return $current;
    }

    private function rowsToMatches(array $rows): array
    {
        $pairs = [];
        foreach (array_slice($rows, 1) as $row) {
            $left = trim($row[0] ?? '');
            $right = trim($row[1] ?? '');
            if ($left === '' && $right === '') {
                continue;
            }
            $pairs[] = ['left' => $left, 'right' => $right];
        }
        return $pairs;
    }
```

Ubah `finalize()`:

```php
    private function finalize(array $q): array
    {
        if ($q['declared_pair_type'] !== null) {
            $q['type'] = $q['declared_pair_type'] === 'BENARSALAH' ? 5 : 4;
            $q['matches'] = $q['matches'] ?? [];
        } elseif (!empty($q['options'])) {
            $q['type'] = count($q['correct']) > 1 ? 2 : 1;
        } else {
            $q['type'] = 3;
        }
        unset($q['declared_pair_type']);
        return $q;
    }
```

- [ ] **Step 4: Jalankan test, pastikan semua lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordQuestionParserTest.php`

Expected: `OK (9 tests, 30 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/WordImport/WordQuestionParser.php src/tests/WordImport/WordQuestionParserTest.php
git commit -m "feat(word-import): Menjodohkan & Benar/Salah lewat tabel (Tipe: ...)" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: `WordImportValidator` — validasi PG/PGK & pesan error dengan cuplikan soal

**Files:**
- Create: `src/app/Libraries/WordImport/WordImportValidator.php`
- Test: `src/tests/WordImport/WordImportValidatorTest.php`

Class ini menggantikan `validateParsedQuestions()` lama (`WordImportController.php:495-556`).

- [ ] **Step 1: Test — dokumen kosong, PG kurang opsi, PG tanpa bintang, esai selalu valid**

Buat `src/tests/WordImport/WordImportValidatorTest.php`:

```php
<?php

namespace Tests\WordImport;

use App\Libraries\WordImport\WordImportValidator;
use PHPUnit\Framework\TestCase;

class WordImportValidatorTest extends TestCase
{
    private function question(array $overrides = []): array
    {
        return array_merge([
            'question'   => 'Siapa penemu bola lampu?',
            'type'       => 1,
            'options'    => ['A' => 'Einstein', 'B' => 'Edison'],
            'correct'    => ['B'],
            'answer_key' => '',
            'matches'    => null,
        ], $overrides);
    }

    public function testEmptyDocumentProducesOneError(): void
    {
        $errors = (new WordImportValidator())->validate([]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Tidak ada soal', $errors[0]);
    }

    public function testValidMultipleChoiceQuestionProducesNoErrors(): void
    {
        $errors = (new WordImportValidator())->validate([$this->question()]);

        $this->assertSame([], $errors);
    }

    public function testMultipleChoiceWithFewerThanTwoOptionsIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['options' => ['A' => 'Einstein'], 'correct' => ['A']]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('minimal 2 pilihan jawaban', $errors[0]);
        $this->assertStringContainsString('Siapa penemu bola lampu?', $errors[0]);
    }

    public function testMultipleChoiceWithoutStarredOptionIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['correct' => []]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('belum ada opsi yang ditandai (*)', $errors[0]);
    }

    public function testEssayQuestionIsAlwaysValidRegardlessOfAnswerKey(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['type' => 3, 'options' => [], 'correct' => [], 'answer_key' => '']),
        ]);

        $this->assertSame([], $errors);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordImportValidatorTest.php`

Expected: FAIL — `Class "App\Libraries\WordImport\WordImportValidator" not found`

- [ ] **Step 3: Implementasi**

Buat `src/app/Libraries/WordImport/WordImportValidator.php`:

```php
<?php

namespace App\Libraries\WordImport;

/**
 * Validasi hasil WordQuestionParser::parse() dan menghasilkan pesan error
 * berbahasa manusia yang mengutip cuplikan soal, bukan cuma nomor urut.
 */
class WordImportValidator
{
    private const SNIPPET_LENGTH = 55;

    /** @return string[] */
    public function validate(array $questions): array
    {
        if (empty($questions)) {
            return ['Tidak ada soal yang terdeteksi. Pastikan format dokumen sesuai (contoh: "1. Teks Soal").'];
        }

        $errors = [];
        foreach ($questions as $q) {
            $errors = array_merge($errors, $this->validateQuestion($q));
        }
        return $errors;
    }

    /** @return string[] */
    private function validateQuestion(array $q): array
    {
        $snippet = $this->snippet($q['question']);

        if ($q['type'] === 1 || $q['type'] === 2) {
            return $this->validateMultipleChoice($q, $snippet);
        }

        return []; // Esai (type 3): tidak ada aturan wajib. Menjodohkan/Benar-Salah: Task 8.
    }

    private function validateMultipleChoice(array $q, string $snippet): array
    {
        $errors = [];
        if (count($q['options']) < 2) {
            $errors[] = "Soal \"{$snippet}\" harus punya minimal 2 pilihan jawaban.";
        }
        if (empty($q['correct'])) {
            $errors[] = "Soal \"{$snippet}\" belum ada opsi yang ditandai (*) sebagai jawaban benar.";
        }
        return $errors;
    }

    private function snippet(string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if (mb_strlen($text) > self::SNIPPET_LENGTH) {
            $text = mb_substr($text, 0, self::SNIPPET_LENGTH) . '...';
        }
        return $text;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordImportValidatorTest.php`

Expected: `OK (5 tests, 8 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/WordImport/WordImportValidator.php src/tests/WordImport/WordImportValidatorTest.php
git commit -m "feat(word-import): WordImportValidator - validasi PG/PGK dengan cuplikan soal" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: `WordImportValidator` — validasi Menjodohkan & Benar/Salah

**Files:**
- Modify: `src/app/Libraries/WordImport/WordImportValidator.php`
- Modify: `src/tests/WordImport/WordImportValidatorTest.php`

- [ ] **Step 1: Test — tabel pasangan hilang, sel kosong, nilai Benar/Salah tidak valid**

Tambahkan ke `src/tests/WordImport/WordImportValidatorTest.php`:

```php
    public function testMatchingWithoutTableIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question(['type' => 4, 'options' => [], 'correct' => [], 'matches' => []]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bertipe Menjodohkan', $errors[0]);
        $this->assertStringContainsString('tidak ditemukan tabel pasangan', $errors[0]);
    }

    public function testMatchingPairWithEmptyCellIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'type' => 4, 'options' => [], 'correct' => [],
                'matches' => [['left' => 'Jepang', 'right' => '']],
            ]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('tidak lengkap', $errors[0]);
    }

    public function testTrueFalseWithInvalidValueIsRejected(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'type' => 5, 'options' => [], 'correct' => [],
                'matches' => [['left' => 'Bumi itu berbentuk datar', 'right' => 'Salahh']],
            ]),
        ]);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bukan "Benar"/"Salah" yang valid', $errors[0]);
    }

    public function testTrueFalseWithValidValuesProducesNoErrors(): void
    {
        $errors = (new WordImportValidator())->validate([
            $this->question([
                'type' => 5, 'options' => [], 'correct' => [],
                'matches' => [
                    ['left' => 'Matahari terbit dari timur', 'right' => 'Benar'],
                    ['left' => 'Bumi itu berbentuk datar', 'right' => 'salah'],
                ],
            ]),
        ]);

        $this->assertSame([], $errors);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordImportValidatorTest.php`

Expected: FAIL — `validateQuestion()` mengembalikan `[]` untuk type 4/5 (belum ditangani).

- [ ] **Step 3: Tambahkan validasi Menjodohkan/Benar-Salah**

Di `src/app/Libraries/WordImport/WordImportValidator.php`, ubah `validateQuestion()`:

```php
    private function validateQuestion(array $q): array
    {
        $snippet = $this->snippet($q['question']);

        if ($q['type'] === 1 || $q['type'] === 2) {
            return $this->validateMultipleChoice($q, $snippet);
        }

        if ($q['type'] === 4 || $q['type'] === 5) {
            return $this->validatePairs($q, $snippet);
        }

        return []; // Esai (type 3): tidak ada aturan wajib.
    }
```

Tambahkan method baru:

```php
    private function validatePairs(array $q, string $snippet): array
    {
        $label = $q['type'] === 5 ? 'Benar/Salah' : 'Menjodohkan';

        if (empty($q['matches'])) {
            return ["Soal \"{$snippet}\" bertipe {$label} tapi tidak ditemukan tabel pasangan di bawahnya."];
        }

        $errors = [];
        foreach ($q['matches'] as $pair) {
            if ($pair['left'] === '' || $pair['right'] === '') {
                $errors[] = "Soal \"{$snippet}\" baris pasangan \"{$pair['left']}\" → \"{$pair['right']}\" tidak lengkap.";
                continue;
            }
            if ($q['type'] === 5 && !in_array(mb_strtolower($pair['right']), ['benar', 'salah'], true)) {
                $errors[] = "Soal \"{$snippet}\" baris \"{$pair['left']}\" → \"{$pair['right']}\" bukan \"Benar\"/\"Salah\" yang valid.";
            }
        }
        return $errors;
    }
```

- [ ] **Step 4: Jalankan test, pastikan semua lulus**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit src/tests/WordImport/WordImportValidatorTest.php`

Expected: `OK (9 tests, 16 assertions)`

- [ ] **Step 5: Jalankan seluruh test suite WordImport sebagai jaring pengaman terakhir**

Run: `sudo bash scripts/cbt.sh php vendor/bin/phpunit --testsuite WordImport`

Expected: semua test (Task 1-8) lulus, tidak ada FAIL/ERROR.

- [ ] **Step 6: Commit**

```bash
git add src/app/Libraries/WordImport/WordImportValidator.php src/tests/WordImport/WordImportValidatorTest.php
git commit -m "feat(word-import): validasi Menjodohkan & Benar/Salah" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: Sambungkan `WordImportController` ke pipeline baru

**Files:**
- Modify: `src/app/Controllers/Admin/WordImportController.php:1-222` (bagian `use`, `process()`)
- Modify: `src/app/Controllers/Admin/WordImportController.php:305-556` (hapus 4 method lama)

Tidak ada test otomatis untuk task ini (controller ini bergantung ke DB/session CodeIgniter yang belum di-setup test harness-nya di proyek ini — sama seperti seluruh controller lain di proyek, lihat catatan di spec). Diverifikasi manual di Task 11.

- [ ] **Step 1: Tambahkan `use` statement untuk class baru**

Di `src/app/Controllers/Admin/WordImportController.php`, ubah bagian atas file:

```php
<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ModuleModel;
use App\Models\SubjectModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Libraries\WordImport\WordBlockExtractor;
use App\Libraries\WordImport\WordQuestionParser;
use App\Libraries\WordImport\WordImportValidator;
use PhpOffice\PhpWord\IOFactory;
```

- [ ] **Step 2: Ganti isi `try` block di `process()`**

Ganti seluruh isi method `process()` dari baris `try {` (baris 105) sampai `catch (\Exception $e) { ... }` (baris 216-221) — **tetap pertahankan** blok `catch` yang sudah ada persis sama — dengan:

```php
        try {
            $phpWord = IOFactory::load($filepath);

            $blocks = (new WordBlockExtractor())->extract($phpWord);
            $parsedQuestions = (new WordQuestionParser())->parse($blocks);

            // ─── DRY-RUN VALIDATION ───
            $validationErrors = (new WordImportValidator())->validate($parsedQuestions);
            if (!empty($validationErrors)) {
                return $this->response->setJSON([
                    'status' => 'validation_error',
                    'errors' => $validationErrors
                ]);
            }

            $db = \Config\Database::connect();
            $db->transStart();

            $insertedCount = 0;
            foreach ($parsedQuestions as $q) {
                $questionId = $this->questionModel->insert([
                    'subject_id'  => $subjectId,
                    'type'        => $q['type'],
                    'description' => $q['question'],
                    'difficulty'  => 1,
                    'is_enabled'  => 1
                ]);

                if ($questionId === false) {
                    $db->transRollback();
                    $errors = implode(', ', $this->questionModel->errors());
                    return $this->response->setJSON([
                        'status'  => 'error',
                        'message' => 'Gagal menyimpan soal ke database: ' . $errors
                    ]);
                }

                $position = 1;

                if ($q['type'] == 1 || $q['type'] == 2) {
                    foreach ($q['options'] as $letter => $text) {
                        $isCorrect = in_array($letter, $q['correct'], true) ? 1 : 0;
                        $this->answerModel->skipValidation(true)->insert([
                            'question_id' => $questionId,
                            'description' => $text,
                            'is_correct'  => $isCorrect,
                            'is_enabled'  => 1,
                            'position'    => $position
                        ]);
                        $position++;
                    }
                } elseif ($q['type'] == 3) {
                    $this->answerModel->skipValidation(true)->insert([
                        'question_id' => $questionId,
                        'description' => $q['answer_key'],
                        'is_correct'  => 1,
                        'is_enabled'  => 1,
                        'position'    => 1
                    ]);
                } elseif ($q['type'] == 4 || $q['type'] == 5) {
                    foreach ($q['matches'] as $pair) {
                        $this->answerModel->skipValidation(true)->insert([
                            'question_id' => $questionId,
                            'description' => $pair['left'] . '|::|' . $pair['right'],
                            'is_correct'  => 1,
                            'is_enabled'  => 1,
                            'position'    => $position
                        ]);
                        $position++;
                    }
                }

                $insertedCount++;
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON([
                    'status'  => 'error',
                    'message' => 'Terjadi kesalahan saat menyimpan ke database.'
                ]);
            }

            return $this->response->setJSON([
                'status'  => 'success',
                'message' => "$insertedCount soal berhasil diimport ke Subjek '$subjectName'."
            ]);

        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Gagal memproses file Word: ' . $e->getMessage()
            ]);
        }
```

- [ ] **Step 3: Hapus method lama yang sudah digantikan**

Hapus 4 method private berikut dari `WordImportController.php` (sekarang jadi dead code, sudah dipindah ke `App\Libraries\WordImport\*`):
- `extractTextUsingPhpWord()`
- `processPhpWordElement()`
- `parseBlocks()`
- `validateParsedQuestions()`

Method `downloadTemplate()` **tetap dipertahankan** (akan diganti isinya di Task 10) dan tetap ada sebelum method-method yang dihapus.

- [ ] **Step 4: Verifikasi tidak ada syntax error**

Run: `sudo bash scripts/cbt.sh php -l app/Controllers/Admin/WordImportController.php`

Expected: `No syntax errors detected in app/Controllers/Admin/WordImportController.php`

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Admin/WordImportController.php
git commit -m "refactor(word-import): controller pakai WordBlockExtractor/Parser/Validator" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 10: Gambar ulang `downloadTemplate()` sesuai format baru

**Files:**
- Modify: `src/app/Controllers/Admin/WordImportController.php` (method `downloadTemplate()`)

- [ ] **Step 1: Ganti isi method `downloadTemplate()`**

Ganti seluruh isi method `downloadTemplate()` (dari `$phpWord = new \PhpOffice\PhpWord\PhpWord();` sampai sebelum `return $this->response->download(...)`) dengan:

```php
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();

        $fontTitle  = ['bold' => true, 'size' => 14];
        $fontNormal = ['size' => 12];
        $tableStyle = ['borderSize' => 6, 'borderColor' => '999999'];

        $section->addText('TEMPLATE IMPORT SOAL (FORMAT BARU)', $fontTitle);
        $section->addTextBreak(1);

        // 1) PG Tunggal - angka & huruf polos, jawaban ditandai *
        $section->addText('1. Siapa penemu bola lampu?', $fontNormal);
        $section->addText('A. Albert Einstein', $fontNormal);
        $section->addText('*B. Thomas Alva Edison', $fontNormal);
        $section->addText('C. Isaac Newton', $fontNormal);
        $section->addText('D. Nikola Tesla', $fontNormal);
        $section->addTextBreak(1);

        // 2) PG Kompleks - lebih dari satu opsi ber-bintang
        $section->addText('2. Pilihlah semua jawaban yang merupakan nama benua:', $fontNormal);
        $section->addText('*A. Asia', $fontNormal);
        $section->addText('B. Pasifik', $fontNormal);
        $section->addText('*C. Eropa', $fontNormal);
        $section->addText('D. Hindia', $fontNormal);
        $section->addText('*E. Afrika', $fontNormal);
        $section->addTextBreak(1);

        // 3) Soal & opsi lewat fitur List/Numbering bawaan Word
        $section->addText('Contoh soal ditulis lewat fitur List/Numbering bawaan Word (tanpa mengetik angka/huruf):', $fontNormal);
        $section->addListItem('Ibukota Jepang adalah?', 0, $fontNormal, 'templateListLevel0');
        $section->addListItem('Osaka', 1, $fontNormal, 'templateListLevel1');
        $section->addListItem('*Tokyo', 1, $fontNormal, 'templateListLevel1');
        $section->addListItem('Kyoto', 1, $fontNormal, 'templateListLevel1');
        $section->addTextBreak(1);

        // 4) Esai dengan kunci jawaban opsional
        $section->addText('4. Siapa nama presiden pertama Republik Indonesia?', $fontNormal);
        $section->addText('Jawaban: Ir. Soekarno', $fontNormal);
        $section->addTextBreak(1);

        // 5) Esai tanpa kunci sama sekali - tetap valid
        $section->addText('5. Jelaskan pendapatmu tentang pentingnya menjaga lingkungan.', $fontNormal);
        $section->addTextBreak(1);

        // 6) Menjodohkan lewat tabel
        $section->addText('6. Pasangkan negara berikut dengan ibukotanya!', $fontNormal);
        $section->addText('Tipe: Menjodohkan', $fontNormal);
        $table1 = $section->addTable($tableStyle);
        $table1->addRow();
        $table1->addCell(2500)->addText('Negara', $fontTitle);
        $table1->addCell(2500)->addText('Ibukota', $fontTitle);
        $table1->addRow();
        $table1->addCell(2500)->addText('Indonesia', $fontNormal);
        $table1->addCell(2500)->addText('Jakarta', $fontNormal);
        $table1->addRow();
        $table1->addCell(2500)->addText('Jepang', $fontNormal);
        $table1->addCell(2500)->addText('Tokyo', $fontNormal);
        $table1->addRow();
        $table1->addCell(2500)->addText('Korea Selatan', $fontNormal);
        $table1->addCell(2500)->addText('Seoul', $fontNormal);
        $section->addTextBreak(1);

        // 7) Benar/Salah lewat tabel
        $section->addText('7. Tentukan benar atau salah untuk pernyataan berikut!', $fontNormal);
        $section->addText('Tipe: Benar/Salah', $fontNormal);
        $table2 = $section->addTable($tableStyle);
        $table2->addRow();
        $table2->addCell(4000)->addText('Pernyataan', $fontTitle);
        $table2->addCell(2000)->addText('Jawaban', $fontTitle);
        $table2->addRow();
        $table2->addCell(4000)->addText('Matahari terbit dari timur', $fontNormal);
        $table2->addCell(2000)->addText('Benar', $fontNormal);
        $table2->addRow();
        $table2->addCell(4000)->addText('Bumi itu berbentuk datar', $fontNormal);
        $table2->addCell(2000)->addText('Salah', $fontNormal);
        $section->addTextBreak(1);

        // 8) Soal dengan tabel data referensi biasa (BUKAN tabel pasangan, tanpa "Tipe:")
        $section->addText('8. Soal dengan tabel data:', $fontNormal);
        $table3 = $section->addTable($tableStyle);
        $table3->addRow();
        $table3->addCell(2000)->addText('Nama', $fontTitle);
        $table3->addCell(2000)->addText('Usia', $fontTitle);
        $table3->addRow();
        $table3->addCell(2000)->addText('Andi', $fontNormal);
        $table3->addCell(2000)->addText('15 Tahun', $fontNormal);
        $section->addText('Berdasarkan tabel di atas, berapakah usia Andi?', $fontNormal);
        $section->addText('A. 10 Tahun', $fontNormal);
        $section->addText('*B. 15 Tahun', $fontNormal);
        $section->addText('C. 20 Tahun', $fontNormal);

        $fileName = 'Template_Import_Soal_CBT.docx';
        $tempFile = tempnam(sys_get_temp_dir(), 'phpword');

        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tempFile);
```

- [ ] **Step 2: Verifikasi tidak ada syntax error**

Run: `sudo bash scripts/cbt.sh php -l app/Controllers/Admin/WordImportController.php`

Expected: `No syntax errors detected in app/Controllers/Admin/WordImportController.php`

- [ ] **Step 3: Commit**

```bash
git add src/app/Controllers/Admin/WordImportController.php
git commit -m "feat(word-import): downloadTemplate() memakai format baru" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 11: Perbarui panduan format di halaman Import Soal

**Files:**
- Modify: `src/app/Views/admin/questions/word_import.php:84-115`

- [ ] **Step 1: Ganti isi kotak contoh & daftar aturan**

Di `src/app/Views/admin/questions/word_import.php`, ganti dari `<p class="mb-3">Agar sistem dapat membaca soal dengan presisi...` (baris 84) sampai sebelum penutup `</div>` kartu panduan (baris 115) dengan:

```html
                    <p class="mb-3">Format sekarang jauh lebih natural — tidak perlu hafal kode "Q:", "RIGHT:", dsb. Contoh:</p>

                    <div class="bg-white p-3 border rounded font-monospace small mb-3" style="max-height: 280px; overflow-y: auto;">
1. Siapa penemu bola lampu?<br>
A. Albert Einstein<br>
*B. Thomas Alva Edison<br>
C. Isaac Newton<br>
<br>
2. Siapa nama presiden pertama Republik Indonesia?<br>
Jawaban: Ir. Soekarno<br>
<br>
3. Pasangkan negara berikut dengan ibukotanya!<br>
Tipe: Menjodohkan<br>
[Tabel 2 kolom: Negara | Ibukota]<br>
<br>
4. Tentukan benar atau salah pernyataan berikut!<br>
Tipe: Benar/Salah<br>
[Tabel 2 kolom: Pernyataan | Benar/Salah]
                    </div>

                    <ul class="text-muted small">
                        <li class="mb-1">Soal baru diawali angka polos, mis. <code>1.</code> atau <code>1)</code> — atau pakai fitur List/Numbering bawaan Word (tidak perlu mengetik angka sama sekali).</li>
                        <li class="mb-1"><strong>Pilihan Ganda:</strong> opsi diawali huruf polos, mis. <code>A.</code> atau <code>A)</code>. Tandai jawaban benar dengan <code>*</code> tepat di depan huruf, mis. <code>*B. Thomas Alva Edison</code>. Boleh lebih dari satu opsi ber-<code>*</code> (otomatis jadi Pilihan Ganda Kompleks).</li>
                        <li class="mb-1"><strong>Esai:</strong> cukup tulis soalnya saja. Baris <code>Jawaban: ...</code> di bawahnya sifatnya opsional, hanya untuk referensi.</li>
                        <li class="mb-1"><strong>Menjodohkan:</strong> tambahkan baris <code>Tipe: Menjodohkan</code> di bawah soal, lalu buat tabel Word 2 kolom tepat di bawahnya — baris pertama tabel jadi judul kolom (dilewati), baris berikutnya jadi pasangan.</li>
                        <li class="mb-1"><strong>Benar/Salah:</strong> sama seperti Menjodohkan, tapi tulis <code>Tipe: Benar/Salah</code> dan isi kolom kanan tabel dengan "Benar" atau "Salah".</li>
                        <li class="mb-1">Tabel biasa (tanpa <code>Tipe:</code> di atasnya) tetap bisa dipakai sebagai data pendukung soal, seperti sebelumnya.</li>
                        <li class="mb-1">Mendukung teks multi-baris, gambar yang disisipkan di dalam dokumen, dan opsi/soal lewat list bawaan Word.</li>
                    </ul>
```

- [ ] **Step 2: Commit**

```bash
git add src/app/Views/admin/questions/word_import.php
git commit -m "docs(word-import): perbarui panduan format di halaman import soal" -m "

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 12: Verifikasi manual end-to-end

Task ini **dijalankan oleh user** (per preferensi yang sudah ada — device/browser testing dikendalikan langsung oleh user, bukan lewat automation), karena melibatkan browser, DB sungguhan, dan admin session yang belum ada test harness-nya di proyek ini.

- [ ] **Step 1: Pastikan container berjalan & aplikasi bisa diakses**

Instruksikan ke user: buka `http://localhost:8080` (atau base URL yang sudah dikonfigurasi), login sebagai admin.

- [ ] **Step 2: Download template baru**

Instruksikan ke user:
1. Buka halaman **Import Soal dari Word** di menu Bank Soal.
2. Klik tombol download template (ikon download).
3. Buka file `.docx` yang terunduh di Word/LibreOffice, cek 8 contoh soal (PG Tunggal, PGK, soal lewat list bawaan Word, esai dengan kunci, esai tanpa kunci, Menjodohkan, Benar/Salah, tabel referensi) tampil wajar (tidak ada teks aneh/rusak).

- [ ] **Step 3: Import ulang template tsb tanpa diubah**

Instruksikan ke user:
1. Di halaman yang sama, pilih/buat Modul & Subjek baru, upload file template hasil Step 2.
2. Klik "Proses Import Soal".
3. Pastikan muncul notifikasi sukses **"8 soal berhasil diimport"** (bukan `validation_error` atau `error`) — ini membuktikan `downloadTemplate()` dan pipeline parsing/validasi saling konsisten (dogfooding).

- [ ] **Step 4: Cek hasil di Bank Soal**

Instruksikan ke user: buka Bank Soal pada Subjek yang baru dibuat, cek satu-satu:
- Soal #1/#2: opsi & kunci jawaban PG/PGK sesuai (B untuk #1; A,C,E untuk #2).
- Soal #3 (list bawaan Word): 3 opsi (Osaka/Tokyo/Kyoto) muncul dengan Tokyo sebagai kunci.
- Soal #4: esai dengan kunci "Ir. Soekarno" tersimpan.
- Soal #5: esai tanpa kunci tetap tersimpan (tidak gagal import).
- Soal #6: Menjodohkan dengan 3 pasangan negara-ibukota.
- Soal #7: Benar/Salah dengan 2 pernyataan.
- Soal #8: tabel "Nama/Usia" muncul sebagai bagian teks soal (bukan opsi jawaban), lalu opsi PG di bawahnya benar (15 Tahun).

- [ ] **Step 5: Uji dokumen dengan kesalahan sengaja (pesan error)**

Instruksikan ke user: buat dokumen `.docx` baru berisi 1 soal PG dengan opsi tapi tanpa tanda `*` sama sekali, lalu import. Pastikan muncul pesan error yang **mengutip cuplikan teks soal** (bukan cuma "Soal No. #1"), sesuai desain di spec.

- [ ] **Step 6: Laporkan hasil ke Claude**

Setelah semua langkah di atas dicoba, sampaikan hasilnya (berhasil semua / ada yang aneh) supaya kalau ada temuan bisa langsung diperbaiki sebelum branch ini di-merge.

---

## Ringkasan Task

| # | Task | Jenis |
|---|------|-------|
| 1 | Aktifkan PHPUnit | Infra |
| 2 | WordFixtureBuilder | Infra test |
| 3 | WordBlockExtractor (paragraf, list Word, gambar, tabel) | TDD |
| 4 | WordQuestionParser (PG/PGK/Esai inti) | TDD |
| 5 | WordQuestionParser (Jawaban: opsional) | TDD |
| 6 | WordQuestionParser (Menjodohkan/Benar-Salah via tabel) | TDD |
| 7 | WordImportValidator (PG/PGK) | TDD |
| 8 | WordImportValidator (Menjodohkan/Benar-Salah) | TDD |
| 9 | Sambungkan controller ke pipeline baru | Refactor |
| 10 | Gambar ulang downloadTemplate() | Feature |
| 11 | Perbarui panduan format di view | Docs |
| 12 | Verifikasi manual end-to-end | Manual (user) |

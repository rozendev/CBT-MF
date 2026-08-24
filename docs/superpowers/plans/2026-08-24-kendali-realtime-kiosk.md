# Kendali Real-Time Kiosk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menjadikan URL WebSocket satu sumber kebenaran yang bisa dikonfigurasi, membuka kanal WebSocket yang selama ini mati di bundle Exambro, dan memberi pengawas tombol keluarkan-dari-ujian serta kunci-akun di `/admin/kiosk/live` yang benar-benar ditegakkan server.

**Architecture:** Satu library `WebSocketUrl` menggantikan lima salinan logika penurunan URL; nilai hasilnya dialirkan ke bundle lewat dua lapis (dipanggang saat build untuk offline, di-override lewat `/api/exam/init` saat online). Isi listener `exam-data-loaded` diekstrak jadi `applyExamData()` supaya jalur bundle memanggilnya juga. Aksi pengawas ditegakkan di server: attempt dikunci ke status `2`, token WebSocket dicabut, dan gerbang tulis API mulai benar-benar menolak status `2` — WebSocket hanya mempercepat perubahan layar.

**Tech Stack:** CodeIgniter 4.7 (PHP 8.3), Alpine.js 3, Ratchet WebSocket, Redis (cache + pub/sub + session), MariaDB, PHPUnit 10.5, Docker Compose.

**Referensi spec:** `docs/superpowers/specs/2026-08-24-kendali-realtime-kiosk-design.md`

**Catatan lingkungan (baca sebelum mulai):**
- Container `php` me-mount `src/` dari repo utama ke `/var/www/html`. Jangan bekerja di worktree lain — lint/test lewat container akan melint berkas repo utama, bukan berkasmu.
- Perintah `docker compose exec` memakai **nama service** (`php`, `mariadb`, `redis`), bukan nama container.
- Path lint relatif terhadap `/var/www/html`, jadi **tanpa** awalan `src/`.
- `base_url()` mengembalikan `https://development.rozendev.my.id` karena `src/.env` menyetel `app.baseURL`. Ini beda dari default `Config\App::$baseURL`.
- **Setiap perubahan pada `src/public/assets/exam-app.js`, `src/public/js/kiosk-integration.js`, atau `src/app/Views/bundle/*.php` WAJIB diikuti `spark cbt:build-ui-bundle`.** Tanpa itu perangkat menerima artifact basi dan gagal senyap.

---

## Struktur berkas

| Berkas | Tanggung jawab |
|---|---|
| `src/app/Libraries/WebSocketUrl.php` (baru) | Satu-satunya tempat yang tahu cara menentukan URL WebSocket: default path/port, penurunan dari base URL, dan aturan "setting menang kecuali kosong/localhost" |
| `src/app/Commands/WsUrlProbe.php` (baru) | Diagnostik ops: cetak URL WS yang akan dipakai klien dan dari mana asalnya |
| `src/app/Libraries/ProctorAction.php` (baru) | Dua primitif tindakan pengawas: `eject()` dan `lockAccount()`, dipakai bersama `SuspendController` dan kiosk live |
| `src/app/Database/Migrations/2026-08-24-000001_SeedWebsocketUrlSetting.php` (baru) | Menyisipkan baris setting `websocket_url` bernilai kosong |
| `src/tests/Realtime/WebSocketUrlTest.php` (baru) | Tes logika murni penurunan URL |
| `src/tests/Realtime/ProctorActionTest.php` (baru) | Tes logika murni validasi aksi dan bentuk payload |
| `src/app/Libraries/FrontendConfig.php` | Delegasi ke `WebSocketUrl`, hapus salinan logika |
| `src/app/Models/SettingModel.php` | Cabut blok auto-correction khusus websocket |
| `src/app/Controllers/Api/ExamApiController.php` | Tambah `ws_url` di init, indeks balik token, gerbang status 2 |
| `src/app/Controllers/Admin/KioskLiveController.php` | Endpoint aksi pengawas |
| `src/app/Views/admin/kiosk/live.php` | Kolom Aksi + dropdown tiga pilihan |
| `src/public/assets/exam-app.js` | `applyExamData()`, `window.examWebSocket`, handler `ejected`, hapus derivation URL |
| `src/app/Views/bundle/exam.php` | Konsumsi `ws_url`, overlay DIKELUARKAN |
| `src/app/Views/bundle/_head.php` | Terima `wsUrl` yang dipanggang |
| `src/app/Libraries/UiBundleBuilder.php` | Kirim `wsUrl` ke view saat build |

---

### Task 1: Commit perbaikan kunci soal yang masih menggantung

Working tree sudah berisi perbaikan `qKey` dari sesi debugging sebelumnya (soal bank tidak render opsi jawaban di Exambro). Perbaikan itu sudah diverifikasi. Commit dulu supaya pekerjaan baru tidak tercampur.

**Files:**
- Commit: `src/public/assets/exam-app.js`, `src/app/Views/bundle/exam.php`

- [ ] **Step 1: Pastikan hanya dua berkas itu yang berubah**

```bash
git status --short
```

Expected: tepat dua baris, `M src/app/Views/bundle/exam.php` dan `M src/public/assets/exam-app.js`. Kalau ada berkas lain, hentikan dan tanya pemilik repo.

- [ ] **Step 2: Jalankan ulang verifikasi cepat**

```bash
node --check src/public/assets/exam-app.js && echo "JS OK"
docker compose exec -T php php -l app/Views/bundle/exam.php
docker compose exec -T php php vendor/bin/phpunit --no-coverage
```

Expected: `JS OK`, `No syntax errors detected`, `OK (41 tests, 117 assertions)`.

- [ ] **Step 3: Commit**

```bash
git add src/public/assets/exam-app.js src/app/Views/bundle/exam.php
git commit -m "fix(kiosk): soal bank tidak render opsi jawaban di bundle Exambro

/api/exam/init mengeluarkan dua bentuk payload: mode static berkunci
question_id, mode normal berkunci log_id tanpa question_id sama sekali.
exam-app.js -- awalnya ditulis hanya untuk halaman static -- selalu mencari
allAnswers[q.question_id], sehingga pada ujian normal lookup jatuh ke
allAnswers[undefined] dan SELURUH pilihan jawaban hilang tanpa error.

Helper qKey() mendahulukan log_id (unik per attempt) dengan fallback
question_id, dan autosave kini mengirim nama field sesuai id yang dipegang
soal. Penjaga mode-drift dipisah ke modeDriftTarget(): bundle merender kedua
mode sehingga hanya perlu memuat ulang saat mode benar-benar berubah -- tanpa
itu setiap auto-sync pada ujian normal memicu reload berulang."
```

- [ ] **Step 4: Verifikasi tree bersih**

```bash
git status --short && git log --oneline -1
```

Expected: tidak ada output dari `git status`, dan commit teratas adalah commit di atas.

---

### Task 2: Library `WebSocketUrl` dengan logika murni yang bisa diuji

**Files:**
- Create: `src/app/Libraries/WebSocketUrl.php`
- Create: `src/tests/Realtime/WebSocketUrlTest.php`
- Modify: `src/phpunit.xml.dist`

- [ ] **Step 1: Daftarkan suite tes baru**

Ganti isi `src/phpunit.xml.dist` menjadi:

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
        <testsuite name="Realtime">
            <directory>tests/Realtime</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Tulis tes yang gagal**

Buat `src/tests/Realtime/WebSocketUrlTest.php`:

```php
<?php

namespace Tests\Realtime;

use App\Libraries\WebSocketUrl;
use PHPUnit\Framework\TestCase;

class WebSocketUrlTest extends TestCase
{
    public function testDeriveUsesWssForHttps(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::derive('https://sekolah.id'));
    }

    public function testDeriveUsesWsForHttp(): void
    {
        $this->assertSame('ws://sekolah.id/ws', WebSocketUrl::derive('http://sekolah.id'));
    }

    public function testDeriveToleratesTrailingSlash(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::derive('https://sekolah.id/'));
    }

    public function testDeriveIgnoresApplicationSubpath(): void
    {
        // Paritas dengan perilaku lama: proxy /ws dipasang di root host,
        // bukan di bawah subpath aplikasi.
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::derive('https://sekolah.id/cbt'));
    }

    public function testDeriveMapsDevPortToWebsocketPort(): void
    {
        // Stack dev: nginx di 8080, server Ratchet dipublikasikan di 8060.
        $this->assertSame('ws://localhost:8060/ws', WebSocketUrl::derive('http://localhost:8080'));
    }

    public function testDeriveKeepsOtherPorts(): void
    {
        $this->assertSame('wss://sekolah.id:8443/ws', WebSocketUrl::derive('https://sekolah.id:8443'));
    }

    public function testPickPrefersConfiguredValue(): void
    {
        $this->assertSame(
            'wss://ws.sekolah.id',
            WebSocketUrl::pick('wss://ws.sekolah.id', 'https://sekolah.id')
        );
    }

    public function testPickTrimsTrailingSlashFromConfiguredValue(): void
    {
        $this->assertSame(
            'wss://ws.sekolah.id/soket',
            WebSocketUrl::pick('wss://ws.sekolah.id/soket///', 'https://sekolah.id')
        );
    }

    public function testPickFallsBackWhenConfiguredIsEmpty(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::pick('', 'https://sekolah.id'));
    }

    public function testPickFallsBackWhenConfiguredIsWhitespace(): void
    {
        $this->assertSame('wss://sekolah.id/ws', WebSocketUrl::pick('   ', 'https://sekolah.id'));
    }

    public function testPickFallsBackWhenConfiguredPointsAtLocalhost(): void
    {
        // Nilai warisan instalasi lama; tidak berguna bagi perangkat siswa.
        $this->assertSame(
            'wss://sekolah.id/ws',
            WebSocketUrl::pick('ws://localhost:8060', 'https://sekolah.id')
        );
    }
}
```

- [ ] **Step 3: Jalankan tes dan pastikan gagal**

```bash
docker compose exec -T php php vendor/bin/phpunit --testsuite Realtime --no-coverage
```

Expected: FAIL dengan `Class "App\Libraries\WebSocketUrl" not found`.

- [ ] **Step 4: Tulis implementasi minimal**

Buat `src/app/Libraries/WebSocketUrl.php`:

```php
<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * Satu sumber kebenaran URL WebSocket untuk SEMUA klien: halaman ujian web,
 * halaman ujian static, bundle kiosk Exambro, dan dashboard pengawas.
 *
 * Sebelum kelas ini ada, cara menurunkan URL ditulis ulang di FrontendConfig,
 * SettingModel, exam-app.js, take.php, dan proctor/live.php -- lima salinan yang
 * bisa saling menyimpang. Path dan port masih punya default, tapi sekarang hanya
 * tertulis di sini.
 *
 * Penurunan sengaja memakai HOST saja, tanpa subpath aplikasi: proxy `/ws`
 * dipasang di root host (lihat docker/nginx/default.conf). Ini mempertahankan
 * perilaku yang sudah berjalan, bukan menambah asumsi baru.
 */
final class WebSocketUrl
{
    /** Path proxy WebSocket. Harus cocok dengan `location /ws/` di nginx. */
    public const DEFAULT_PATH = '/ws';

    /**
     * Pemetaan port khusus stack pengembangan: nginx dipublikasikan di 8080,
     * server Ratchet langsung di 8060 tanpa lewat proxy.
     */
    public const DEV_PORT_MAP = ['8080' => '8060'];

    /**
     * URL final yang harus dipakai klien. Setting menang; kalau kosong atau
     * masih menunjuk localhost, turunkan dari base URL aplikasi.
     */
    public static function resolve(?SettingModel $setting = null): string
    {
        $setting ??= new SettingModel();

        return self::pick(
            (string) $setting->getValue('websocket_url', ''),
            (string) base_url()
        );
    }

    /** Apakah nilai final berasal dari setting admin, bukan diturunkan. */
    public static function isConfigured(?SettingModel $setting = null): bool
    {
        $setting ??= new SettingModel();
        $configured = trim((string) $setting->getValue('websocket_url', ''));

        return $configured !== '' && !str_contains($configured, 'localhost');
    }

    /** Logika murni: setting menang kecuali kosong atau menunjuk localhost. */
    public static function pick(string $configured, string $baseUrl): string
    {
        $configured = trim($configured);

        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            return self::normalize($configured);
        }

        return self::derive($baseUrl);
    }

    /** Logika murni: turunkan URL WebSocket dari base URL aplikasi. */
    public static function derive(string $baseUrl): string
    {
        $parts  = parse_url(trim($baseUrl));
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws';
        $host   = $parts['host'] ?? 'localhost';
        $port   = isset($parts['port']) ? (string) $parts['port'] : '';

        if ($port !== '' && isset(self::DEV_PORT_MAP[$port])) {
            $port = self::DEV_PORT_MAP[$port];
        }

        $authority = $host . ($port !== '' ? ':' . $port : '');

        return $scheme . '://' . $authority . self::DEFAULT_PATH;
    }

    /** Logika murni: buang slash berlebih di ujung. */
    public static function normalize(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
```

- [ ] **Step 5: Jalankan tes dan pastikan lulus**

```bash
docker compose exec -T php php vendor/bin/phpunit --no-coverage
```

Expected: `OK (52 tests, ...)` — 41 tes lama plus 11 tes baru, tanpa kegagalan.

- [ ] **Step 6: Commit**

```bash
git add src/app/Libraries/WebSocketUrl.php src/tests/Realtime/WebSocketUrlTest.php src/phpunit.xml.dist
git commit -m "feat(realtime): WebSocketUrl sebagai satu sumber kebenaran URL WS"
```

---

### Task 3: Alihkan semua konsumen PHP ke `WebSocketUrl`

**Files:**
- Modify: `src/app/Libraries/FrontendConfig.php:32,71-91`
- Modify: `src/app/Models/SettingModel.php:57-66`
- Modify: `src/app/Controllers/Student/ExamController.php:277`
- Modify: `src/app/Controllers/Admin/StaticExamController.php:176`
- Modify: `src/app/Controllers/Proctor/LiveController.php:69`
- Create: `src/app/Commands/WsUrlProbe.php`

- [ ] **Step 1: Delegasikan `FrontendConfig`**

Di `src/app/Libraries/FrontendConfig.php`, ganti baris `'websocket_url' => self::websocketUrl($setting),` menjadi:

```php
            'websocket_url'   => \App\Libraries\WebSocketUrl::resolve($setting),
```

Lalu HAPUS seluruh method privat `websocketUrl()` (dari komentar `/** Tentukan URL WebSocket final ...` sampai kurung tutupnya).

- [ ] **Step 2: Cabut auto-correction websocket dari `SettingModel`**

Di `src/app/Models/SettingModel.php`, hapus blok ini seluruhnya:

```php
        // Auto-correction for websocket_url if it still uses localhost on a remote server
        if ($key === 'websocket_url' && (empty($value) || strpos($value, 'localhost') !== false)) {
            $parsed = parse_url(base_url());
            $host = $parsed['host'] ?? 'localhost';
            if ($host !== 'localhost' && $host !== '127.0.0.1') {
                $scheme = (isset($parsed['scheme']) && $parsed['scheme'] === 'https') ? 'wss' : 'ws';
                $value = $scheme . '://' . $host . '/ws/';
            }
        }

```

Model setting generik tidak seharusnya tahu soal URL WebSocket. Mencabutnya sekaligus menutup bug lama: saat baris setting belum ada, `getValue()` sudah `return $default` di awal method sehingga koreksi ini tidak pernah jalan pada panggilan pertama — dan default milik pemanggil pertama ikut ter-cache untuk semua pemanggil berikutnya.

- [ ] **Step 3: Alihkan tiga controller**

`src/app/Controllers/Student/ExamController.php` — ganti:

```php
            'wsUrl' => $settingModel->getValue('websocket_url', '')
```

menjadi:

```php
            'wsUrl' => \App\Libraries\WebSocketUrl::resolve($settingModel)
```

`src/app/Controllers/Admin/StaticExamController.php` — ganti:

```php
            'wsUrl' => $settingModel->getValue('websocket_url', ''),
```

menjadi:

```php
            'wsUrl' => \App\Libraries\WebSocketUrl::resolve($settingModel),
```

`src/app/Controllers/Proctor/LiveController.php` — ganti:

```php
        $wsUrl = $this->settingModel->getValue('websocket_url', 'ws://localhost:8060');
```

menjadi:

```php
        $wsUrl = \App\Libraries\WebSocketUrl::resolve($this->settingModel);
```

- [ ] **Step 4: Tambah perintah diagnostik**

Buat `src/app/Commands/WsUrlProbe.php`:

```php
<?php

namespace App\Commands;

use App\Libraries\WebSocketUrl;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * "Kenapa siswa tidak dapat real-time?" biasanya berujung pada URL WebSocket
 * yang salah. Perintah ini mencetak URL yang AKAN dipakai klien beserta
 * asalnya, tanpa perlu membuka halaman ujian.
 */
class WsUrlProbe extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:ws-url';
    protected $description = 'Cetak URL WebSocket yang dipakai klien dan dari mana asalnya.';

    public function run(array $params)
    {
        $url = WebSocketUrl::resolve();
        $fromSetting = WebSocketUrl::isConfigured();

        CLI::write('Base URL aplikasi : ' . rtrim((string) base_url(), '/'));
        CLI::write('URL WebSocket     : ' . $url, 'green');
        CLI::write('Sumber            : ' . ($fromSetting
            ? 'setting websocket_url (admin)'
            : 'diturunkan dari base URL (setting kosong)'), $fromSetting ? 'yellow' : 'blue');
        CLI::write('Path default      : ' . WebSocketUrl::DEFAULT_PATH);
    }
}
```

- [ ] **Step 5: Verifikasi tidak ada konsumen tersisa**

```bash
grep -rn "getValue('websocket_url'" src/app --include="*.php"
```

Expected: hanya satu hasil, di dalam `src/app/Libraries/WebSocketUrl.php`.

- [ ] **Step 6: Lint dan jalankan probe**

```bash
docker compose exec -T php php -l app/Libraries/FrontendConfig.php
docker compose exec -T php php -l app/Models/SettingModel.php
docker compose exec -T php php -l app/Controllers/Proctor/LiveController.php
docker compose exec -T php php spark cbt:ws-url
```

Expected: tiga `No syntax errors detected`, lalu probe mencetak
`URL WebSocket     : wss://development.rozendev.my.id/ws` dengan sumber
`diturunkan dari base URL (setting kosong)`. Nilai ini harus SAMA dengan yang
dihasilkan kode lama — kalau berbeda, hentikan dan cari sebabnya sebelum lanjut.

- [ ] **Step 7: Jalankan seluruh tes**

```bash
docker compose exec -T php php vendor/bin/phpunit --no-coverage
```

Expected: semua lulus.

- [ ] **Step 8: Commit**

```bash
git add src/app/Libraries/FrontendConfig.php src/app/Models/SettingModel.php \
        src/app/Controllers/Student/ExamController.php \
        src/app/Controllers/Admin/StaticExamController.php \
        src/app/Controllers/Proctor/LiveController.php \
        src/app/Commands/WsUrlProbe.php
git commit -m "refactor(realtime): semua konsumen URL WS lewat WebSocketUrl

SettingModel tidak lagi menyimpan logika khusus websocket -- blok koreksinya
tak pernah jalan pada panggilan pertama karena getValue() sudah return default
lebih dulu saat baris setting belum ada. Tambah cbt:ws-url untuk diagnosa ops."
```

---

### Task 4: Setting `websocket_url` yang bisa diubah admin

**Files:**
- Create: `src/app/Database/Migrations/2026-08-24-000001_SeedWebsocketUrlSetting.php`
- Modify: `src/app/Controllers/Admin/SettingController.php:70` (akhir array `$allowed`)
- Modify: `src/app/Views/admin/settings/index.php` (pane `#tab-system`)

- [ ] **Step 1: Buat migration**

Buat `src/app/Database/Migrations/2026-08-24-000001_SeedWebsocketUrlSetting.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedWebsocketUrlSetting extends Migration
{
    public function up(): void
    {
        $existing = $this->db->table('settings')->where('key', 'websocket_url')->get()->getRow();
        if ($existing) {
            return;
        }

        $this->db->table('settings')->insert([
            'key'         => 'websocket_url',
            'value'       => '',
            'type'        => 'string',
            'group'       => 'system',
            'description' => 'URL WebSocket untuk klien. Kosongkan agar diturunkan otomatis dari alamat aplikasi.',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $this->db->table('settings')->where('key', 'websocket_url')->delete();
    }
}
```

- [ ] **Step 2: Izinkan setting disimpan**

Di `src/app/Controllers/Admin/SettingController.php`, tambahkan satu baris di akhir array `$allowed`, setelah `'kiosk_root_strictness'`:

```php
        'websocket_url'               => ['group' => 'system', 'type' => 'string'],
```

- [ ] **Step 3: Tambah field di tab Sistem**

Di `src/app/Views/admin/settings/index.php`, di dalam `<div class="tab-pane fade" id="tab-system">`, tambahkan blok berikut sebagai anak pertama pane tersebut:

```html
                <div class="s-item">
                    <label class="s-label" for="websocket_url">URL WebSocket</label>
                    <p class="s-desc">
                        Dipakai halaman ujian, halaman static, bundle EXAMBRO, dan dashboard pengawas.
                        <strong>Kosongkan</strong> agar diturunkan otomatis dari alamat aplikasi —
                        isi hanya kalau server WebSocket berada di host atau path yang berbeda.
                    </p>
                    <input type="text" class="form-control" id="websocket_url" name="settings[websocket_url]"
                           value="<?= esc(settingVal($groupedSettings, 'system', 'websocket_url', '')) ?>"
                           placeholder="<?= esc(\App\Libraries\WebSocketUrl::derive((string) base_url())) ?>">
                    <div class="form-text">
                        Nilai yang dipakai saat ini:
                        <code><?= esc(\App\Libraries\WebSocketUrl::resolve()) ?></code>
                    </div>
                </div>
```

Catatan: kalau pane `#tab-system` di berkas itu tidak memakai kelas `s-item`/`s-label`/`s-desc`, pakai kelas yang sama dengan field lain di pane tetangga (`#tab-security`) supaya tampilannya konsisten. Jangan mengarang kelas baru.

- [ ] **Step 4: Pastikan grup `system` tersedia untuk view**

Di `src/app/Controllers/Admin/SettingController.php`, di method `index()`, ganti:

```php
        foreach (['general', 'logo', 'security', 'exam', 'kiosk'] as $g) {
```

menjadi:

```php
        foreach (['general', 'logo', 'security', 'exam', 'kiosk', 'system'] as $g) {
```

Helper `settingVal()` di view memakai `isset()` sehingga tetap aman tanpa langkah
ini, tapi menyamakan daftar grup menjaga pola yang sudah ada tetap konsisten.

- [ ] **Step 5: Jalankan migration**

```bash
docker compose exec -T php php spark migrate
```

Expected: `Running: App\Database\Migrations\SeedWebsocketUrlSetting` lalu `Migrations complete.`

- [ ] **Step 6: Verifikasi baris masuk dan perilaku tidak berubah**

```bash
bash -c 'DBU=$(grep -E "^DB_USERNAME=" .env | cut -d= -f2); DBP=$(grep -E "^DB_PASSWORD=" .env | cut -d= -f2); DBN=$(grep -E "^DB_DATABASE=" .env | cut -d= -f2); docker compose exec -T mariadb mariadb -u"$DBU" -p"$DBP" "$DBN"' <<'SQL'
SELECT `key`, `value`, `group` FROM settings WHERE `key` = 'websocket_url';
SQL
docker compose exec -T php php spark cache:clear
docker compose exec -T php php spark cbt:ws-url
```

Expected: baris ada dengan value kosong dan group `system`; probe tetap mencetak
`wss://development.rozendev.my.id/ws` dengan sumber `diturunkan dari base URL`.

- [ ] **Step 7: Commit**

```bash
git add src/app/Database/Migrations/2026-08-24-000001_SeedWebsocketUrlSetting.php \
        src/app/Controllers/Admin/SettingController.php \
        src/app/Views/admin/settings/index.php
git commit -m "feat(settings): websocket_url bisa diatur dari halaman pengaturan"
```

---

### Task 5: Alirkan URL ke bundle, hapus derivation di klien

**Files:**
- Modify: `src/app/Controllers/Api/ExamApiController.php` (respons `init`)
- Modify: `src/app/Libraries/UiBundleBuilder.php:53-58`
- Modify: `src/app/Views/bundle/_head.php:20`
- Modify: `src/app/Views/bundle/exam.php` (mapping `EXAM_CONFIG`)
- Modify: `src/public/assets/exam-app.js` (`initWebSocket`)
- Modify: `src/app/Views/student/exam/take.php:1049-1058`
- Modify: `src/app/Views/proctor/live.php:166-172`

- [ ] **Step 1: Kirim `ws_url` dari `/api/exam/init`**

Di `src/app/Controllers/Api/ExamApiController.php`, di dalam respons sukses `init()`, tambahkan satu baris tepat setelah `'ws_token' => $wsToken,`:

```php
            'ws_url' => \App\Libraries\WebSocketUrl::resolve(),
```

- [ ] **Step 2: Panggang URL ke bundle saat build**

Di `src/app/Libraries/UiBundleBuilder.php`, ubah pemanggilan `view()` di dalam loop `$pages` menjadi:

```php
        foreach ($pages as $file => $view) {
            $html = view($view, [
                'baseUrl'      => $baseUrl,
                'assetVersion' => $assetVersion,
                'school'       => $school,
                // Default saat perangkat offline. Saat online, /api/exam/init
                // mengirim ws_url yang menang atas nilai panggangan ini, sehingga
                // bundle lama tetap ikut perubahan setting tanpa rebuild.
                'wsUrl'        => \App\Libraries\WebSocketUrl::resolve(),
            ]);
            file_put_contents("$outDir/$file", $html);
        }
```

- [ ] **Step 3: Ekspos di head bundle**

Di `src/app/Views/bundle/_head.php`, tepat setelah baris `window.KIOSK_BASE_URL = ...;`, tambahkan:

```php
        window.KIOSK_WS_URL = <?= json_encode($wsUrl ?? '') ?>;
```

- [ ] **Step 4: Konsumsi di `bundle/exam.php`**

Di `src/app/Views/bundle/exam.php`, ganti baris `wsUrl: '',` menjadi:

```javascript
                wsUrl: j.ws_url || window.KIOSK_WS_URL || '',
```

- [ ] **Step 5: Hapus derivation di `exam-app.js`**

Ganti blok ini di `initWebSocket()`:

```javascript
                let wsUrl = EXAM_CONFIG.wsUrl;
                if (!wsUrl) wsUrl = APP_CFG.websocket_url || '';
                if (!wsUrl || wsUrl.includes('localhost')) {
                    const urlObj = new URL(API);
                    const protocol = urlObj.protocol === 'https:' ? 'wss:' : 'ws:';
                    const wsHost = urlObj.host;
                    if (wsHost.includes(':8080')) {
                        wsUrl = `${protocol}//${wsHost.replace(':8080', ':8060')}`;
                    } else {
                        wsUrl = `${protocol}//${wsHost}/ws`;
                    }
                }
                wsUrl = wsUrl.replace(/\/+$/, '') + `/?ws_token=${wsToken}`;
```

menjadi:

```javascript
                // URL ditentukan server (App\Libraries\WebSocketUrl). Klien tidak
                // lagi menurunkannya sendiri: dulu logika yang sama hidup di lima
                // tempat dan bisa saling menyimpang.
                const wsBase = EXAM_CONFIG.wsUrl || APP_CFG.websocket_url || window.KIOSK_WS_URL || '';
                if (!wsBase) {
                    console.error('URL WebSocket tidak tersedia dari server');
                    return;
                }
                const wsUrl = wsBase.replace(/\/+$/, '') + `/?ws_token=${wsToken}`;
```

- [ ] **Step 6: Hapus derivation di `take.php`**

Di `src/app/Views/student/exam/take.php`, ganti:

```javascript
                    let wsUrl = '<?= esc($wsUrl ?? '') ?>';
                    if (!wsUrl) wsUrl = APP_CFG.websocket_url || '';
                    if (!wsUrl || wsUrl.includes('localhost')) {
                        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                        const wsHost = window.location.host;
                        if (wsHost.includes(':8080')) {
                            wsUrl = `${protocol}//${wsHost.replace(':8080', ':8060')}`;
                        } else {
                            wsUrl = `${protocol}//${wsHost}/ws`;
                        }
                    }
                    wsUrl = wsUrl.replace(/\/+$/, '') + '/?ws_token=<?= esc($wsToken) ?>';
```

menjadi:

```javascript
                    // URL ditentukan server (App\Libraries\WebSocketUrl).
                    const wsBase = '<?= esc($wsUrl ?? '') ?>' || APP_CFG.websocket_url || '';
                    if (!wsBase) {
                        console.error('URL WebSocket tidak tersedia dari server');
                        return;
                    }
                    const wsUrl = wsBase.replace(/\/+$/, '') + '/?ws_token=<?= esc($wsToken) ?>';
```

- [ ] **Step 7: Hapus derivation di `proctor/live.php`**

Ganti getter `wsUrl` menjadi:

```javascript
        get wsUrl() {
            // URL ditentukan server (App\Libraries\WebSocketUrl).
            const base = '<?= esc($wsUrl ?? '') ?>' || APP_CFG.websocket_url || '';
            return base.replace(/\/+$/, '') + '/?proctor_token=<?= esc($proctorToken) ?>';
        },
```

- [ ] **Step 8: Verifikasi tidak ada derivation tersisa di klien**

```bash
grep -rn "8060" src/public/assets/exam-app.js src/app/Views/student/exam/take.php src/app/Views/proctor/live.php
```

Expected: tidak ada hasil.

- [ ] **Step 9: Lint dan rebuild bundle**

```bash
node --check src/public/assets/exam-app.js && echo "JS OK"
docker compose exec -T php php -l app/Views/bundle/exam.php
docker compose exec -T php php -l app/Libraries/UiBundleBuilder.php
docker compose exec -T php php spark cbt:build-ui-bundle
grep -o "KIOSK_WS_URL = [^;]*;" src/public/ui-bundle/exam.html
```

Expected: lint bersih, build sukses, dan baris terakhir mencetak
`KIOSK_WS_URL = "wss:\/\/development.rozendev.my.id\/ws";`

- [ ] **Step 10: Commit**

```bash
git add src/app/Controllers/Api/ExamApiController.php src/app/Libraries/UiBundleBuilder.php \
        src/app/Views/bundle/_head.php src/app/Views/bundle/exam.php \
        src/public/assets/exam-app.js src/app/Views/student/exam/take.php \
        src/app/Views/proctor/live.php
git commit -m "feat(realtime): URL WS mengalir dari server ke semua klien

Bundle mendapat dua lapis: nilai dipanggang saat build untuk perangkat offline,
dan ws_url dari /api/exam/init menang saat online sehingga bundle yang sudah
terpasang ikut perubahan setting tanpa rebuild."
```

---

### Task 6: Buka kanal WebSocket di bundle

Akar masalah: `initWebSocket()` terkurung di dalam listener `document.addEventListener('exam-data-loaded', ...)`, dan event itu hanya di-dispatch di jalur non-bundle.

**Files:**
- Modify: `src/public/assets/exam-app.js` (init bundle, listener, `connectWebSocket`)

- [ ] **Step 1: Ekstrak isi listener jadi `applyExamData()`**

Ganti seluruh blok `document.addEventListener('exam-data-loaded', () => { ... });` (dari baris `document.addEventListener('exam-data-loaded', () => {` sampai `});` penutupnya) menjadi:

```javascript
                document.addEventListener('exam-data-loaded', () => {
                    this.applyExamData(window.__examData || {});
                });
```

Lalu tambahkan method baru `applyExamData(data)` sebagai anggota komponen, tepat SEBELUM `initWebSocket()`:

```javascript
            /* Dipanggil oleh KEDUA jalur: web/static lewat event
               'exam-data-loaded', dan bundle kiosk langsung dari init().
               Dulu isinya terkurung di listener yang tidak pernah menyala di
               bundle, sehingga di Exambro WebSocket tidak pernah terbuka,
               cadangan lokal tidak pernah dipulihkan, dan posisi soal terakhir
               tidak pernah dikembalikan. */
            applyExamData(data) {
                data = data || {};
                this.questions  = data.questions || this.questions;
                this.allAnswers = data.answers   || this.allAnswers;

                // ─── Per-Attempt Reorder (Anti-Cheat) ───
                if (this.questions && this.questions.length > 0 && this.questions[0].display_order !== undefined) {
                    this.questions.sort((a, b) => (a.display_order || 0) - (b.display_order || 0));
                    this.questions.forEach((q, i) => { q.display_order = i + 1; });
                }
                if (this.allAnswers) {
                    for (const qId in this.allAnswers) {
                        if (Array.isArray(this.allAnswers[qId]) && this.allAnswers[qId].length > 0 && this.allAnswers[qId][0].display_order !== undefined) {
                            this.allAnswers[qId].sort((a, b) => (a.display_order || 0) - (b.display_order || 0));
                        }
                    }
                }

                this.studentName = data.studentName || this.studentName || '';
                this.parseMatching();
                this.restoreLocalBackup();

                if (ATTEMPT_ID) {
                    const savedIndex = localStorage.getItem('current_question_index_' + ATTEMPT_ID);
                    if (savedIndex !== null) {
                        const parsed = parseInt(savedIndex, 10);
                        if (!isNaN(parsed) && parsed >= 0 && parsed < this.questions.length) {
                            this.currentIndex = parsed;
                        }
                    }
                }

                this.initWebSocket();
                this.startTimer(data.beginTimeMs || Date.now(), data.timeOffset || 0);
            },
```

Kalau blok listener lama memuat baris lain di luar yang tersalin di atas, pindahkan juga ke `applyExamData()` — jangan sampai ada perilaku yang hilang.

- [ ] **Step 2: Panggil dari jalur bundle**

Ganti blok `if (window.__KIOSK_BUNDLE__) { ... }` yang berisi replikasi parsial (set `studentName`, `testName`, `durationMinutes`, `startTimer`) menjadi:

```javascript
                if (window.__KIOSK_BUNDLE__) {
                    // CATATAN: factory komponen tidak boleh membaca EXAM_CONFIG
                    // (alpine.min.js bundle auto-start sebelum fetch init selesai);
                    // semua nilai dibaca di sini — setelah __bundleConfigPromise.
                    this.testName = EXAM_CONFIG.testName || '';
                    this.durationMinutes = EXAM_CONFIG.durationMinutes || 0;
                    this.timeLeft = this.durationMinutes > 0 ? this.durationMinutes * 60 * 1000 : 0;

                    // Event 'exam-data-loaded' hanya di-dispatch jalur non-bundle,
                    // jadi bundle memanggil jalur yang sama secara langsung.
                    this.applyExamData(window.__examData || {});
                }
```

`applyExamData()` sudah memanggil `startTimer()`, jadi jangan panggil dua kali.

- [ ] **Step 3: Ekspos socket sebagai global**

Di `connectWebSocket(wsUrl)`, ganti baris pembuka:

```javascript
            connectWebSocket(wsUrl) {
                this.ws = new WebSocket(wsUrl);
```

menjadi:

```javascript
            connectWebSocket(wsUrl) {
                this.ws = new WebSocket(wsUrl);
                // kiosk-integration.js mengirim telemetri lewat
                // window.examWebSocket. Tanpa baris ini global itu tidak pernah
                // terisi dan sendKioskWsEvent() jadi no-op permanen: exit_attempt,
                // security_alert, kiosk_failed semuanya dibuang diam-diam.
                window.examWebSocket = this.ws;
```

Lalu di dalam `this.ws.onclose = ...` (atau tambahkan handler `onclose` bila belum ada, sebelum `reconnectWebSocket` dipanggil), pastikan global dibersihkan:

```javascript
                    if (window.examWebSocket === this.ws) window.examWebSocket = null;
```

- [ ] **Step 4: Rebuild bundle dan siapkan harness**

```bash
node --check src/public/assets/exam-app.js && echo "JS OK"
docker compose exec -T php php spark cbt:build-ui-bundle
```

Expected: lint bersih, build sukses.

- [ ] **Step 5: Verifikasi WebSocket benar-benar terbuka di bundle**

Bangun ulang harness reproduksi (pola yang sama dipakai saat membedah bug bank soal):

1. Salin `src/public/ui-bundle/` ke direktori scratchpad, buang `ui-bundle.zip`.
2. Di salinan `exam.html`, ganti nilai `KIOSK_BASE_URL` dan `KIOSK_WS_URL` menjadi `http://127.0.0.1:8099` dan `ws://127.0.0.1:8099/ws`.
3. Jalankan server Node yang melayani berkas bundle, menjawab `/api/exam/init` dengan payload nyata attempt yang ada, dan menerima koneksi WebSocket di `/ws`.
4. Buka `http://127.0.0.1:8099/exam.html?test_id=2` di browser dan evaluasi:

```javascript
const d = Alpine.$data(document.querySelector('#examContent'));
JSON.stringify({
  adaSocket: !!d.ws,
  readyState: d.ws && d.ws.readyState,          // 1 = OPEN
  globalTerisi: window.examWebSocket === d.ws,
  jumlahSoal: d.questions.length
});
```

Expected: `adaSocket: true`, `readyState: 1`, `globalTerisi: true`, `jumlahSoal: 10`.
Sebelum perubahan ini nilainya `adaSocket: false`. Kalau masih `false`, JANGAN lanjut — `applyExamData()` belum terpanggil di jalur bundle.

- [ ] **Step 6: Commit**

```bash
git add src/public/assets/exam-app.js
git commit -m "fix(kiosk): bundle Exambro tidak pernah membuka WebSocket

initWebSocket() terkurung di listener 'exam-data-loaded' yang hanya di-dispatch
jalur non-bundle. Isinya diekstrak jadi applyExamData() yang dipanggil kedua
jalur, sekaligus memulihkan restoreLocalBackup() dan restore posisi soal
terakhir yang selama ini juga mati di kiosk. window.examWebSocket kini diisi
supaya telemetri kiosk-integration.js berhenti jadi no-op."
```

---

### Task 7: Tegakkan status attempt `2` di gerbang tulis

Tanpa ini, mengunci attempt tidak menghentikan apa pun: `ExamApiController` hanya menolak status `3` dan `4`, sehingga siswa yang di-ban hari ini masih bisa autosave.

**Files:**
- Modify: `src/app/Controllers/Api/ExamApiController.php` (`autosave`, `autoSync`, `finish`, `checkScore`, `init`)
- Modify: `src/app/Controllers/Student/ExamController.php:312-317`

- [ ] **Step 1: Tolak status 2 di `autosave`**

Di `ExamApiController::autosave()`, tepat SEBELUM blok `if ($attempt->status == 3) {`, sisipkan:

```php
        // Status 2 = attempt dikunci (pelanggaran atau tindakan pengawas).
        // Sebelumnya hanya 3 dan 4 yang ditolak, sehingga siswa yang sudah
        // dikunci tetap bisa menyimpan jawaban.
        if ($attempt->status == 2) {
            return $this->response->setJSON([
                'status'  => 'kicked',
                'reason'  => 'locked',
                'message' => 'Ujian Anda dihentikan oleh pengawas.',
            ]);
        }
```

- [ ] **Step 2: Tolak status 2 di `autoSync`, `finish`, dan `checkScore`**

Di `ExamApiController::autoSync()`, setelah blok validasi kepemilikan attempt (`if (!$attempt || (string)$attempt->user_id !== ...)`) dan sebelum `passesKioskGate`, sisipkan blok yang SAMA seperti Step 1.

Di `ExamApiController::checkScore()`, setelah blok validasi kepemilikan attempt, sisipkan blok yang SAMA seperti Step 1.

Di `ExamApiController::finish()`, ganti `if ($attempt) {` menjadi:

```php
        if ($attempt && (int) $attempt->status === 2) {
            return $this->response->setJSON([
                'status'  => 'kicked',
                'reason'  => 'locked',
                'message' => 'Ujian Anda dihentikan oleh pengawas.',
            ]);
        }

        if ($attempt) {
```

- [ ] **Step 3: Beri `init` alasan khusus**

Di `ExamApiController::init()`, tepat setelah baris `$attempt = $this->attemptModel->getActiveAttemptCached($testId, $userId);` dan sebelum pemeriksaan jendela waktu, sisipkan:

```php
        // Attempt terkunci: jangan kirim soal. Bundle memakai reason ini untuk
        // menampilkan layar "dikeluarkan" alih-alih halaman ujian.
        if ($attempt && (int) $attempt->status === 2) {
            return $this->response->setJSON([
                'status'  => 'error',
                'reason'  => 'ejected',
                'message' => 'Ujian Anda dihentikan oleh pengawas. Serahkan perangkat kepada pengawas.',
            ]);
        }
```

- [ ] **Step 4: Samakan jalur web**

Di `src/app/Controllers/Student/ExamController.php`, tepat sebelum `if ($attempt->status == 3) {` di sekitar baris 312, sisipkan:

```php
        if ($attempt->status == 2) {
            return $this->response->setJSON([
                'status'  => 'kicked',
                'reason'  => 'locked',
                'message' => 'Ujian Anda dihentikan oleh pengawas.',
            ]);
        }
```

- [ ] **Step 5: Lint**

```bash
docker compose exec -T php php -l app/Controllers/Api/ExamApiController.php
docker compose exec -T php php -l app/Controllers/Student/ExamController.php
```

Expected: dua `No syntax errors detected`.

- [ ] **Step 6: Verifikasi gerbang benar-benar menolak**

Kunci attempt uji, lalu pastikan autosave ditolak:

```bash
bash -c 'DBU=$(grep -E "^DB_USERNAME=" .env | cut -d= -f2); DBP=$(grep -E "^DB_PASSWORD=" .env | cut -d= -f2); DBN=$(grep -E "^DB_DATABASE=" .env | cut -d= -f2); docker compose exec -T mariadb mariadb -u"$DBU" -p"$DBP" "$DBN"' <<'SQL'
UPDATE test_attempts SET status = 2 WHERE id = 8;
SELECT id, status FROM test_attempts WHERE id = 8;
SQL
docker compose exec -T php php spark cache:clear
```

Lalu di perangkat/harness, satu autosave harus menjawab `{"status":"kicked","reason":"locked",...}`.
Kembalikan keadaan setelah selesai:

```bash
bash -c 'DBU=$(grep -E "^DB_USERNAME=" .env | cut -d= -f2); DBP=$(grep -E "^DB_PASSWORD=" .env | cut -d= -f2); DBN=$(grep -E "^DB_DATABASE=" .env | cut -d= -f2); docker compose exec -T mariadb mariadb -u"$DBU" -p"$DBP" "$DBN"' <<'SQL'
UPDATE test_attempts SET status = 1 WHERE id = 8;
SQL
docker compose exec -T php php spark cache:clear
```

- [ ] **Step 7: Commit**

```bash
git add src/app/Controllers/Api/ExamApiController.php src/app/Controllers/Student/ExamController.php
git commit -m "fix(exam): tegakkan status attempt 2 di gerbang tulis

Hanya status 3 dan 4 yang ditolak, sehingga siswa yang attempt-nya sudah
dikunci -- termasuk lewat ban -- tetap bisa menyimpan jawaban. init kini
menjawab reason 'ejected' supaya klien menampilkan layar dikeluarkan."
```

---

### Task 8: Indeks balik token dan invarian satu token per attempt

`init()` mencetak `ws_student_token` baru setiap kali dipanggil, dan token lama tetap sah sampai TTL 4 jam habis. Tanpa perbaikan ini, `eject` hanya mencabut token terakhir dan kick bisa dilewati dengan sesi lama.

**Files:**
- Modify: `src/app/Controllers/Api/ExamApiController.php` (blok pencetakan `$wsToken`)

- [ ] **Step 1: Ganti blok pencetakan token**

Ganti blok ini:

```php
        $wsToken = bin2hex(random_bytes(16));
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->setex("ws_student_token:{$wsToken}", 14400, json_encode([
                    'user_id' => (int)$userId,
                    'attempt_id' => (int)$attempt->id,
                    'test_id' => (int)$test->id
                ]));
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error generating ws_student_token in API: ' . $e->getMessage());
        }
```

menjadi:

```php
        $wsToken = bin2hex(random_bytes(16));
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                // Invarian SATU token per attempt. init() dipanggil ulang setiap
                // kali halaman dimuat; tanpa mencabut token sebelumnya, token lama
                // tetap sah sampai TTL 4 jam habis dan tindakan pengawas
                // (yang mencabut token) bisa dilewati lewat sesi lama.
                $previous = $redis->get("attempt_ws_token:{$attempt->id}");
                if ($previous) {
                    $redis->del("ws_student_token:{$previous}");
                }

                $redis->setex("ws_student_token:{$wsToken}", 14400, json_encode([
                    'user_id' => (int)$userId,
                    'attempt_id' => (int)$attempt->id,
                    'test_id' => (int)$test->id
                ]));
                // Indeks balik: satu-satunya cara menemukan token milik sebuah
                // attempt saat pengawas ingin mencabutnya.
                $redis->setex("attempt_ws_token:{$attempt->id}", 14400, $wsToken);
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error generating ws_student_token in API: ' . $e->getMessage());
        }
```

- [ ] **Step 2: Lint**

```bash
docker compose exec -T php php -l app/Controllers/Api/ExamApiController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Verifikasi invarian di Redis**

Muat halaman ujian dua kali (lewat perangkat atau harness), lalu:

```bash
bash -c 'RP=$(grep -E "^REDIS_PASSWORD=" .env | cut -d= -f2)
docker compose exec -T redis redis-cli -a "$RP" --no-auth-warning --scan --pattern "ws_student_token:*"
docker compose exec -T redis redis-cli -a "$RP" --no-auth-warning GET "attempt_ws_token:8"'
```

Expected: hanya SATU `ws_student_token:*` untuk attempt itu, dan nilainya sama dengan isi `attempt_ws_token:8`.

- [ ] **Step 4: Commit**

```bash
git add src/app/Controllers/Api/ExamApiController.php
git commit -m "fix(exam): satu ws_student_token per attempt + indeks balik

init() mencetak token baru tiap kali dipanggil dan token lama tetap sah 4 jam.
Tanpa invarian ini, pencabutan token oleh pengawas bisa dilewati lewat sesi lama."
```

---

### Task 9: Library `ProctorAction`

**Files:**
- Create: `src/app/Libraries/ProctorAction.php`
- Create: `src/tests/Realtime/ProctorActionTest.php`
- Modify: `src/app/Controllers/Admin/SuspendController.php` (`_doBan` jadi pemanggil)

- [ ] **Step 1: Tulis tes yang gagal untuk logika murni**

Buat `src/tests/Realtime/ProctorActionTest.php`:

```php
<?php

namespace Tests\Realtime;

use App\Libraries\ProctorAction;
use PHPUnit\Framework\TestCase;

class ProctorActionTest extends TestCase
{
    public function testKnownActionsAreValid(): void
    {
        $this->assertTrue(ProctorAction::isValidAction('eject'));
        $this->assertTrue(ProctorAction::isValidAction('lock'));
        $this->assertTrue(ProctorAction::isValidAction('eject_lock'));
    }

    public function testUnknownActionIsRejected(): void
    {
        $this->assertFalse(ProctorAction::isValidAction('ban'));
        $this->assertFalse(ProctorAction::isValidAction(''));
        $this->assertFalse(ProctorAction::isValidAction('EJECT'));
    }

    public function testEjectPayloadCarriesRoutingFields(): void
    {
        $payload = ProctorAction::buildEjectPayload(3, 8, 2, 'Terindikasi membuka aplikasi lain');

        $this->assertSame('ejected', $payload['event']);
        $this->assertSame(3, $payload['user_id']);
        $this->assertSame(8, $payload['attempt_id']);
        $this->assertSame(2, $payload['test_id']);
        $this->assertStringContainsString('pengawas', $payload['message']);
    }

    public function testEjectPayloadKeepsReasonWhenGiven(): void
    {
        $payload = ProctorAction::buildEjectPayload(3, 8, 2, 'Terindikasi membuka aplikasi lain');
        $this->assertSame('Terindikasi membuka aplikasi lain', $payload['reason']);
    }

    public function testEjectPayloadUsesDefaultReasonWhenBlank(): void
    {
        $payload = ProctorAction::buildEjectPayload(3, 8, 2, '   ');
        $this->assertSame('Dikeluarkan oleh pengawas', $payload['reason']);
    }
}
```

- [ ] **Step 2: Jalankan tes dan pastikan gagal**

```bash
docker compose exec -T php php vendor/bin/phpunit --testsuite Realtime --no-coverage
```

Expected: FAIL dengan `Class "App\Libraries\ProctorAction" not found`.

- [ ] **Step 3: Tulis implementasi**

Buat `src/app/Libraries/ProctorAction.php`:

```php
<?php

namespace App\Libraries;

use App\Models\ActivityLogModel;
use App\Models\TestAttemptModel;
use App\Models\UserModel;

/**
 * Dua tindakan keras yang boleh diambil pengawas terhadap peserta yang sedang
 * ujian. Dipakai bersama oleh KioskLiveController dan SuspendController supaya
 * tidak ada dua salinan logika penguncian.
 *
 * Prinsip: DATABASE dulu, Redis belakangan. Menulis status attempt adalah
 * penegakan intinya dan tidak boleh bergantung pada Redis; pencabutan token dan
 * publish real-time adalah usaha terbaik yang kegagalannya dilaporkan, bukan
 * membatalkan tindakan.
 */
final class ProctorAction
{
    public const ACTIONS = ['eject', 'lock', 'eject_lock'];

    public const DEFAULT_REASON = 'Dikeluarkan oleh pengawas';

    public const STUDENT_MESSAGE = 'Ujian Anda dihentikan oleh pengawas. Serahkan perangkat kepada pengawas.';

    /** Logika murni: apakah nama aksi dikenal. */
    public static function isValidAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /** Logika murni: bentuk pesan yang dipublikasikan ke kanal exam_events. */
    public static function buildEjectPayload(int $userId, int $attemptId, int $testId, string $reason): array
    {
        $reason = trim($reason);

        return [
            'event'      => 'ejected',
            'user_id'    => $userId,
            'attempt_id' => $attemptId,
            'test_id'    => $testId,
            'reason'     => $reason !== '' ? $reason : self::DEFAULT_REASON,
            'message'    => self::STUDENT_MESSAGE,
        ];
    }

    /**
     * Keluarkan peserta dari ujian yang sedang berjalan.
     *
     * Pencabutan token disengaja membuat perangkat MAKIN terkunci, bukan
     * terlepas: heartbeat native menjawab 401 dan /api/kiosk/can-exit menolak,
     * sehingga satu-satunya jalan keluar tetap password pengawas.
     *
     * @return array{ok:bool, message:string, realtime:bool, attempt_id:int}
     */
    public function eject(int $testId, int $userId, int $actorId, string $reason = ''): array
    {
        $db = \Config\Database::connect();

        $attempt = $db->table('test_attempts')
            ->select('id')
            ->where('test_id', $testId)
            ->where('user_id', $userId)
            ->whereIn('status', [0, 1])
            ->orderBy('id', 'DESC')
            ->get()->getRow();

        if (!$attempt) {
            return [
                'ok'         => false,
                'message'    => 'Siswa ini tidak sedang mengerjakan ujian tersebut.',
                'realtime'   => false,
                'attempt_id' => 0,
            ];
        }

        $attemptId = (int) $attempt->id;

        // 1) Penegakan inti — tidak bergantung Redis.
        $db->table('test_attempts')->where('id', $attemptId)->update(['status' => 2]);
        (new TestAttemptModel())->clearCacheForAttempt($attemptId, $testId, $userId);

        // 2) Audit — best effort, kegagalannya tidak boleh membatalkan tindakan.
        try {
            (new ActivityLogModel())->log(
                'proctor_eject',
                $actorId,
                'test',
                $testId,
                "Mengeluarkan user #{$userId} dari ujian (attempt #{$attemptId})"
            );
            $db->table('exam_kiosk_events')->insert([
                'exam_session_id' => $testId,
                'student_id'      => $userId,
                'event_type'      => 'proctor_eject',
                'event_details'   => json_encode(['actor_id' => $actorId, 'reason' => trim($reason)], JSON_UNESCAPED_UNICODE),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction eject audit gagal: ' . $e->getMessage());
        }

        // 3) Cabut token + kabari perangkat — best effort.
        $realtime = false;
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $token = $redis->get("attempt_ws_token:{$attemptId}");
                if ($token) {
                    $redis->del("ws_student_token:{$token}");
                }
                $redis->del("attempt_ws_token:{$attemptId}");

                $redis->publish('exam_events', json_encode(
                    self::buildEjectPayload($userId, $attemptId, $testId, $reason)
                ));
                $realtime = true;
            }
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction eject realtime gagal: ' . $e->getMessage());
        }

        return [
            'ok'         => true,
            'message'    => $realtime
                ? 'Siswa dikeluarkan dari ujian.'
                : 'Siswa sudah dikunci, tetapi perintah real-time gagal terkirim. Layar siswa akan berubah paling lambat 60 detik.',
            'realtime'   => $realtime,
            'attempt_id' => $attemptId,
        ];
    }

    /**
     * Kunci akun seketika: nonaktifkan user, kunci attempt yang berjalan, cabut
     * token login, dan hapus sesi aktifnya.
     *
     * Ini adalah logika yang sebelumnya hidup di SuspendController::_doBan.
     */
    public function lockAccount(int $userId, int $actorId): array
    {
        $db = \Config\Database::connect();
        $db->transStart();

        (new UserModel())->update($userId, ['is_active' => 0]);

        $db->table('test_attempts')
            ->where('user_id', $userId)
            ->whereIn('status', [1, 2])
            ->update(['status' => 2]);

        $db->transComplete();

        try {
            (new ActivityLogModel())->log('proctor_lock', $actorId, 'user', $userId, "Mengunci akun user #{$userId}");
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction lock audit gagal: ' . $e->getMessage());
        }

        $realtime = false;
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $redis->setex("user_login_token:{$userId}", 7200, 'BANNED');
                $redis->setex("ban_signal:{$userId}", 120, '1');
                $redis->publish('exam_events', json_encode([
                    'event'   => 'ban',
                    'user_id' => $userId,
                    'message' => 'Akun Anda telah ditangguhkan/diblokir oleh Admin. Hubungi pengawas ujian.',
                ]));

                $iterator = null;
                do {
                    $keys = $redis->scan($iterator, 'ci_session:*', 100);
                    if ($keys) {
                        foreach ($keys as $key) {
                            $data = $redis->get($key);
                            if ($data && (strpos($data, "user_id|i:{$userId};") !== false ||
                                          strpos($data, "user_id|s:" . strlen((string) $userId) . ":\"{$userId}\";") !== false)) {
                                $redis->del($key);
                            }
                        }
                    }
                } while ($iterator > 0);
                $realtime = true;
            }
        } catch (\Throwable $e) {
            log_message('error', 'ProctorAction lock realtime gagal: ' . $e->getMessage());
        }

        return [
            'ok'       => true,
            'message'  => $realtime
                ? 'Akun dikunci.'
                : 'Akun dikunci di database, tetapi sesi aktif mungkin belum tercabut karena Redis tidak tersedia.',
            'realtime' => $realtime,
        ];
    }
}
```

- [ ] **Step 4: Jalankan tes dan pastikan lulus**

```bash
docker compose exec -T php php vendor/bin/phpunit --no-coverage
```

Expected: semua lulus, termasuk 5 tes `ProctorActionTest` yang baru.

- [ ] **Step 5: Jadikan `_doBan` pemanggil, hapus salinan**

Di `src/app/Controllers/Admin/SuspendController.php`, ganti seluruh isi method `_doBan($userId)` menjadi:

```php
    private function _doBan($userId)
    {
        // Logika penguncian tinggal di App\Libraries\ProctorAction supaya
        // halaman suspend dan monitoring kiosk memakai kode yang sama.
        (new \App\Libraries\ProctorAction())->lockAccount((int) $userId, (int) session('user_id'));
    }
```

- [ ] **Step 6: Lint dan uji regresi halaman suspend**

```bash
docker compose exec -T php php -l app/Libraries/ProctorAction.php
docker compose exec -T php php -l app/Controllers/Admin/SuspendController.php
```

Expected: dua `No syntax errors detected`. Lalu buka `/admin/suspend`, ban satu
user uji, dan pastikan `users.is_active` jadi `0` serta sesinya hilang dari Redis —
perilaku harus sama persis seperti sebelum refactor. Kembalikan dengan tombol
unban setelah selesai.

- [ ] **Step 7: Commit**

```bash
git add src/app/Libraries/ProctorAction.php src/tests/Realtime/ProctorActionTest.php \
        src/app/Controllers/Admin/SuspendController.php
git commit -m "feat(proctor): ProctorAction dengan primitif eject dan lockAccount

Logika ban dipindahkan dari SuspendController agar halaman suspend dan
monitoring kiosk memakai kode yang sama. eject() menulis DB lebih dulu supaya
penegakan tidak bergantung pada Redis."
```

---

### Task 10: Endpoint aksi pengawas

**Files:**
- Modify: `src/app/Controllers/Admin/KioskLiveController.php`
- Modify: `src/app/Config/Routes.php:109` (setelah `kiosk/live-data`)

- [ ] **Step 1: Tambah rute**

Di `src/app/Config/Routes.php`, tepat setelah baris `$routes->get('kiosk/live-data', 'Admin\KioskLiveController::data');`, tambahkan:

```php
        $routes->post('kiosk/live/action', 'Admin\KioskLiveController::action');
```

- [ ] **Step 2: Tambah method `action()`**

Di `src/app/Controllers/Admin/KioskLiveController.php`, tambahkan `use App\Libraries\ProctorAction;` di bagian `use`, lalu tambahkan method berikut setelah `data()`:

```php
    /**
     * Tindakan pengawas terhadap satu peserta.
     * POST { test_id, user_id, action: eject|lock|eject_lock, reason? }
     */
    public function action()
    {
        $body = $this->request->getJSON(true);
        if (!is_array($body)) {
            $body = $this->request->getPost();
        }

        $testId = (int) ($body['test_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);
        $action = (string) ($body['action'] ?? '');
        $reason = (string) ($body['reason'] ?? '');

        if ($testId <= 0 || $userId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error', 'message' => 'test_id dan user_id wajib.',
            ]);
        }

        if (!ProctorAction::isValidAction($action)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error', 'message' => 'Aksi tidak dikenal.',
            ]);
        }

        $actorId  = (int) session('user_id');
        $proctor  = new ProctorAction();
        $messages = [];
        $ok       = true;

        if ($action === 'eject' || $action === 'eject_lock') {
            $result = $proctor->eject($testId, $userId, $actorId, $reason);
            $messages[] = $result['message'];
            // Gagal eject (mis. tidak sedang ujian) tidak membatalkan lock:
            // pengawas yang memilih eject_lock tetap ingin akunnya terkunci.
            if ($action === 'eject') {
                $ok = $result['ok'];
            }
        }

        if ($action === 'lock' || $action === 'eject_lock') {
            $result = $proctor->lockAccount($userId, $actorId);
            $messages[] = $result['message'];
            $ok = $ok && $result['ok'];
        }

        return $this->response->setJSON([
            'status'  => $ok ? 'success' : 'error',
            'message' => implode(' ', $messages),
        ]);
    }
```

- [ ] **Step 3: Lint**

```bash
docker compose exec -T php php -l app/Controllers/Admin/KioskLiveController.php
docker compose exec -T php php -l app/Config/Routes.php
```

Expected: dua `No syntax errors detected`.

- [ ] **Step 4: Verifikasi rute terdaftar**

```bash
docker compose exec -T php php spark routes | grep -i "kiosk/live"
```

Expected: tiga baris, termasuk `POST` untuk `admin/kiosk/live/action`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Admin/KioskLiveController.php src/app/Config/Routes.php
git commit -m "feat(kiosk): endpoint aksi pengawas di monitoring real-time"
```

---

### Task 11: Menu aksi di halaman monitoring

**Files:**
- Modify: `src/app/Views/admin/kiosk/live.php`

- [ ] **Step 1: Tambah kolom header**

Ganti baris header tabel:

```html
                            <th>Status</th><th>Siswa</th><th>Baterai</th><th>Jaringan</th>
                            <th>Versi App</th><th>Device ID</th><th>Terakhir Terlihat</th>
```

menjadi:

```html
                            <th>Status</th><th>Siswa</th><th>Baterai</th><th>Jaringan</th>
                            <th>Versi App</th><th>Device ID</th><th>Terakhir Terlihat</th>
                            <th class="text-end">Aksi</th>
```

Dan ubah `colspan="7"` pada baris kosong menjadi `colspan="8"`.

- [ ] **Step 2: Tambah sel dropdown**

Tepat setelah `<td><span class="text-muted small" x-text="s.last_seen || '—'"></span></td>`, tambahkan:

```html
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-danger dropdown-toggle"
                                                type="button" data-bs-toggle="dropdown" aria-expanded="false"
                                                :disabled="busyUser === s.user_id">
                                            <span x-show="busyUser !== s.user_id">Aksi</span>
                                            <span x-show="busyUser === s.user_id">Memproses…</span>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button class="dropdown-item" type="button" @click="runAction(s, 'eject')">
                                                <i class="bi bi-box-arrow-right me-2"></i>Keluarkan dari ujian</button></li>
                                            <li><button class="dropdown-item" type="button" @click="runAction(s, 'lock')">
                                                <i class="bi bi-lock me-2"></i>Kunci akun</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button class="dropdown-item text-danger fw-semibold" type="button" @click="runAction(s, 'eject_lock')">
                                                <i class="bi bi-shield-exclamation me-2"></i>Keluarkan &amp; Kunci</button></li>
                                        </ul>
                                    </div>
                                </td>
```

- [ ] **Step 3: Tambah state dan method di komponen Alpine**

Di dalam objek yang dikembalikan `kioskLive()`, tambahkan setelah `outageMessage: '',`:

```javascript
        busyUser: null,
        actionMessage: '',
        actionOk: true,
```

Dan tambahkan method berikut setelah `loadData()`:

```javascript
        actionLabel(action) {
            if (action === 'eject') return 'mengeluarkan siswa ini dari ujian';
            if (action === 'lock') return 'mengunci akun siswa ini';
            return 'mengeluarkan siswa ini dari ujian DAN mengunci akunnya';
        },
        runAction(student, action) {
            const nama = (student.firstname + ' ' + student.lastname).trim() + ' (' + student.username + ')';
            if (!window.confirm('Anda akan ' + this.actionLabel(action) + ':\n\n' + nama + '\n\nLanjutkan?')) return;

            this.busyUser = student.user_id;
            fetch('<?= base_url('/admin/kiosk/live/action') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                },
                body: JSON.stringify({
                    test_id: this.selectedTest,
                    user_id: student.user_id,
                    action: action
                })
            })
                .then(r => r.json())
                .then(d => {
                    this.actionOk = d.status === 'success';
                    this.actionMessage = d.message || (this.actionOk ? 'Aksi berhasil.' : 'Aksi gagal.');
                    this.loadData();
                })
                .catch(e => {
                    console.error('kiosk action failed:', e);
                    this.actionOk = false;
                    this.actionMessage = 'Aksi gagal terkirim. Periksa koneksi lalu coba lagi.';
                })
                .finally(() => { this.busyUser = null; });
        },
```

- [ ] **Step 4: Tampilkan hasil aksi**

Tepat setelah blok `<div x-show="outageMessage" ...>`, tambahkan:

```html
    <div x-show="actionMessage" class="alert mb-4" :class="actionOk ? 'alert-success' : 'alert-danger'">
        <i class="bi me-2" :class="actionOk ? 'bi-check-circle-fill' : 'bi-exclamation-octagon-fill'"></i>
        <span x-text="actionMessage"></span>
        <button type="button" class="btn-close float-end" @click="actionMessage = ''"></button>
    </div>
```

- [ ] **Step 5: Lint**

```bash
docker compose exec -T php php -l app/Views/admin/kiosk/live.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6: Verifikasi di browser**

Buka `/admin/kiosk/live`, pilih ujian, pastikan kolom Aksi muncul dengan tiga
pilihan dan konfirmasi memuat nama siswa. Jangan jalankan aksinya dulu — itu
diverifikasi end-to-end di Task 13.

- [ ] **Step 7: Commit**

```bash
git add src/app/Views/admin/kiosk/live.php
git commit -m "feat(kiosk): menu aksi keluarkan/kunci di monitoring real-time"
```

---

### Task 12: Layar "dikeluarkan" di sisi siswa

**Files:**
- Modify: `src/public/assets/exam-app.js` (handler `onmessage`)
- Modify: `src/app/Views/bundle/exam.php` (overlay + penanganan `reason: 'ejected'`)

- [ ] **Step 1: Tambah handler `ejected`**

Di `exam-app.js`, di dalam `this.ws.onmessage`, tepat SETELAH blok `if (eventName === 'ban') { ... }`, sisipkan:

```javascript
                    else if (eventName === 'ejected') {
                        // BEDA dari 'kick': 'kick' memanggil logoutAndRedirect(),
                        // yang di kiosk menendang WebView keluar dari bundle menuju
                        // halaman login online — justru merusak penguncian.
                        // 'ejected' hanya menghentikan ujian; lock task Android
                        // dibiarkan aktif, jadi keluar tetap butuh password pengawas.
                        this.showEjected(d.message || 'Ujian Anda dihentikan oleh pengawas.');
                    }
```

- [ ] **Step 2: Tambah method `showEjected()`**

Tambahkan sebagai anggota komponen, tepat setelah `applyExamData()`:

```javascript
            /* Hentikan ujian dan tampilkan layar dikeluarkan. Sengaja TIDAK
               memanggil logoutAndRedirect() maupun CBTKioskRequestExit(): di
               kiosk, perangkat harus tetap terkunci sampai pengawas membuka
               dengan password. */
            showEjected(message) {
                try { if (this.ws) this.ws.close(); } catch (e) {}
                if (this.timerInterval) clearInterval(this.timerInterval);
                if (this.syncTimeout) clearTimeout(this.syncTimeout);
                Object.keys(this.saveTimers || {}).forEach((k) => {
                    clearTimeout(this.saveTimers[k]);
                    delete this.saveTimers[k];
                });
                this.isSaving = false;

                if (window.__KIOSK_BUNDLE__ && typeof window.showKioskEjected === 'function') {
                    window.showKioskEjected(message);
                    return;
                }

                window.isSubmitting = true;
                Swal.fire({
                    title: 'Ujian Dihentikan',
                    text: message,
                    icon: 'error',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    confirmButtonText: 'OK'
                }).then(() => logoutAndRedirect(API + '/login'));
            },
```

- [ ] **Step 3: Tambah overlay di bundle**

Di `src/app/Views/bundle/exam.php`, tepat setelah blok `<!-- Suspend Overlay (Anti-Cheat) -->`, tambahkan:

```html
<!-- Overlay Dikeluarkan Pengawas -->
<div id="ejectedOverlay" style="display:none;position:fixed;inset:0;z-index:99999;background:var(--kiosk-danger-bg,#fef2f2);color:var(--kiosk-ink,#0f172a);flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center">
    <div style="font-size:64px;line-height:1;margin-bottom:16px">⛔</div>
    <h2 style="font-weight:800;color:var(--kiosk-danger,#b91c1c);margin:0 0 12px">DIKELUARKAN</h2>
    <p id="ejectedMessage" style="font-size:18px;max-width:560px;margin:0 0 20px"></p>
    <p style="font-size:15px;color:var(--kiosk-muted,#475569);max-width:560px;margin:0">
        Perangkat ini masih terkunci. Serahkan kepada pengawas untuk membukanya.
    </p>
</div>
```

- [ ] **Step 4: Tambah fungsi global dan tangani `reason: 'ejected'`**

Di `src/app/Views/bundle/exam.php`, di dalam `<script>` yang mendefinisikan `window.__bundleConfigPromise`, tambahkan fungsi berikut sebelum `var ready = function (j) {`:

```javascript
        window.showKioskEjected = function (message) {
            var ls = document.getElementById('loading-screen');
            if (ls) ls.style.display = 'none';
            var gate = document.getElementById('prepareScreen');
            if (gate) gate.style.display = 'none';
            var content = document.getElementById('examContent');
            if (content) content.style.display = 'none';
            var el = document.getElementById('ejectedOverlay');
            var msg = document.getElementById('ejectedMessage');
            if (msg) msg.textContent = message || 'Ujian Anda dihentikan oleh pengawas.';
            if (el) el.style.display = 'flex';
        };
```

Lalu di dalam `ready`, ganti baris:

```javascript
            if (j.status !== 'success') { throw new Error(j.message || 'Gagal memuat soal'); }
```

menjadi:

```javascript
            // Attempt yang sudah dikunci pengawas: tampilkan layar dikeluarkan,
            // bukan pesan error biasa — dan jangan pernah menavigasi keluar bundle.
            if (j.status !== 'success' && j.reason === 'ejected') {
                window.showKioskEjected(j.message);
                return false;
            }
            if (j.status !== 'success') { throw new Error(j.message || 'Gagal memuat soal'); }
```

Catatan: `initExam()` di `exam-app.js` sudah menangani `__bundleConfigPromise`
yang bernilai falsy dengan `window.location.href = 'login.html'`. Ubah baris itu
supaya tidak menavigasi saat overlay sudah tampil:

```javascript
                const ok = await window.__bundleConfigPromise;
                if (!ok) {
                    // Overlay "dikeluarkan" sudah tampil dan perangkat harus tetap
                    // di halaman ini; hanya kegagalan lain yang kembali ke login.
                    if (!document.getElementById('ejectedOverlay') ||
                        document.getElementById('ejectedOverlay').style.display === 'none') {
                        window.location.href = 'login.html';
                    }
                    return;
                }
```

- [ ] **Step 5: Lint dan rebuild bundle**

```bash
node --check src/public/assets/exam-app.js && echo "JS OK"
docker compose exec -T php php -l app/Views/bundle/exam.php
docker compose exec -T php php spark cbt:build-ui-bundle
```

Expected: lint bersih dan build sukses.

- [ ] **Step 6: Commit**

```bash
git add src/public/assets/exam-app.js src/app/Views/bundle/exam.php
git commit -m "feat(kiosk): layar dikeluarkan tanpa melepas kunci perangkat

Event 'ejected' dipisah dari 'kick' yang memanggil logoutAndRedirect() dan di
kiosk akan menendang WebView keluar dari bundle."
```

---

### Task 13: Verifikasi end-to-end dan serah terima

**Files:** tidak ada perubahan kode; hanya verifikasi.

- [ ] **Step 1: Pastikan seluruh tes dan lint bersih**

```bash
docker compose exec -T php php vendor/bin/phpunit --no-coverage
node --check src/public/assets/exam-app.js && echo "JS OK"
git status --short
```

Expected: semua tes lulus, JS OK, working tree bersih.

- [ ] **Step 2: Pastikan bundle yang disajikan adalah hasil build terakhir**

```bash
docker compose exec -T php php spark cbt:build-ui-bundle
curl -s https://development.rozendev.my.id/api/kiosk/config \
  | python3 -c "import json,sys; b=json.load(sys.stdin)['ui_bundle']; print('version:', b['version'][:16]); print('size:', b['size'])"
```

Expected: versi yang dilaporkan sama dengan `version` di
`src/public/ui-bundle/manifest.json`.

- [ ] **Step 3: Uji perangkat (dijalankan pemilik repo)**

Berikan instruksi bernomor berikut, jangan mengemudikan perangkat sendiri:

1. Buka aplikasi EXAMBRO di HP, tunggu sampai bundle selesai memperbarui diri.
2. Login sebagai siswa uji, masuk ke ujian "Bahasa Indonesia".
3. Pastikan soal DAN pilihan jawabannya muncul, lalu jawab satu soal.
4. Di komputer, buka `/admin/kiosk/live`, pilih ujian itu. Siswa harus tampil `online`.
5. Tekan **Aksi → Keluarkan dari ujian** pada siswa tersebut.
6. Laporkan: berapa detik sampai layar HP berubah jadi "DIKELUARKAN"; apakah HP masih terkunci di aplikasi (coba tekan tombol Home/Recent); apakah masih bisa mengetik jawaban.
7. Minta pengawas membuka dengan password keluar, lalu pastikan HP bisa keluar.
8. Ulangi langkah 2-5 dengan **Aksi → Keluarkan & Kunci**, lalu coba login ulang dengan akun yang sama — harus ditolak.

- [ ] **Step 4: Pulihkan akun uji**

Setelah pengujian, buka `/admin/suspend` dan tekan unban untuk akun uji, lalu
pastikan `is_active` kembali `1`.

- [ ] **Step 5: Tawarkan integrasi cabang**

Gunakan skill `superpowers:finishing-a-development-branch` untuk memutuskan
merge, PR, atau lanjut di cabang ini.

---

## Catatan penyimpangan dari spec

Spec menyebut tes PHPUnit untuk `ProctorAction` mencakup "efek pada status
attempt, pencabutan token, dan audit". Suite tes repo ini murni unit tanpa
database maupun Redis (`tests/bootstrap.php` hanya memuat autoloader), jadi
Task 9 menguji bagian murninya (`isValidAction`, `buildEjectPayload`) lewat
PHPUnit dan memverifikasi efek DB/Redis lewat pemeriksaan langsung pada stack
yang berjalan di Task 9 Step 6 dan Task 13. Menambahkan infrastruktur tes
database adalah pekerjaan tersendiri yang berada di luar lingkup rencana ini.

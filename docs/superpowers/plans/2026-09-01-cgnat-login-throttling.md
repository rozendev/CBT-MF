# CGNAT-Safe Login Throttling + Break-Glass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ubah rem login per-IP dari pemblokir massal (20 hardcoded, fail-closed) menjadi pengaman yang pemaaf, admin-tunable, dan CGNAT-safe, dengan jalan keluar darurat lewat CLI dan web.

**Architecture:** Satu library `LoginThrottle` menjadi sumber kebenaran format kunci + operasi (hit/clear/list). `LoginRateLimitFilter`, `AuthController`, command `auth:unblock`, dan `SuspendController` semuanya memanggilnya — tidak ada logika kunci yang tersalin. IP klien di-resolve lewat mekanisme trusted-proxy bawaan CI4 (`Config\App::$proxyIPs`), diarahkan ke header `CF-Connecting-IP`, sehingga filter dan pencatatan IP AuthController otomatis konsisten karena keduanya memakai `getIPAddress()`.

**Tech Stack:** PHP 8 / CodeIgniter 4, phpredis (`RedisClient`), MariaDB, PHPUnit. Semua lint/test dijalankan di container `php` (mount `./src → /var/www/html`).

---

## Penyimpangan dari spec (perlu persetujuan; intent spec tidak berubah)

Dua hal ditemukan saat membaca kode dan menyimpang dari nama komponen di
`docs/superpowers/specs/2026-09-01-cgnat-login-throttling-design.md`. Keduanya
lebih DRY dan lebih mudah diuji; tujuan spec (CGNAT-safe, IP asli yang dijaga,
break-glass, reset-on-success, ambang admin-tunable) tetap utuh.

1. **Komponen 1 `App\Libraries\ClientIp` → dihapus, ganti konfigurasi
   `Config\App::$proxyIPs`.** CI4 sudah punya "percaya header hanya bila peer
   adalah proxy tepercaya, kalau tidak pakai `REMOTE_ADDR`" — persis guard yang
   diminta spec. Config `$proxyIPs` sudah ada (`app/Config/App.php:183`,
   `172.16.0.0/12 => X-Forwarded-For`) dan sudah dipakai `getIPAddress()` di
   filter **dan** di `AuthController` (baris 97 & 250). Perubahan nyata yang
   berguna: ganti header tepercaya dari `X-Forwarded-For` (bisa ditambahi klien
   → dapat dipalsukan) ke `CF-Connecting-IP` (di-set otoritatif oleh Cloudflare),
   dan jadikan CIDR-nya dari env. Library bespoke hanya akan menduplikasi kode
   framework dan menguji framework.

2. **Tambah `App\Libraries\LoginThrottle`.** Spec menyebar format kunci
   `login_attempts_ip:{ip}` + operasi hapus/daftar ke tiga pemanggil. Satu library
   statis menjadikannya satu sumber kebenaran yang dipakai filter, AuthController,
   command, dan SuspendController — dan bisa diuji langsung.

Jika salah satu ditolak, revert-nya kecil: (1) kembalikan header ke
`X-Forwarded-For` / tulis library `ClientIp`; (2) inline-kan operasi kunci.

## Struktur berkas

| Berkas | Tanggung jawab | Aksi |
|---|---|---|
| `app/Libraries/LoginThrottle.php` | Sumber kebenaran: format kunci, `hit()`, `clearForIp()`, `activeBlocks()`, `clearAll()`, `maxAttempts()` | **Buat** |
| `app/Config/App.php` | `$proxyIPs` dari env, header `CF-Connecting-IP` | **Ubah** (183-185, + constructor) |
| `app/Filters/LoginRateLimitFilter.php` | Ambang dari setting; INCR via `LoginThrottle::hit()`; **fail-open** (keputusan A) | **Ubah** (rombak `before()`) |
| `app/Controllers/Auth/AuthController.php` | Reset-on-success: hapus kunci IP saat login berhasil | **Ubah** (setelah baris 250) |
| `app/Commands/AuthUnblock.php` | Command `auth:unblock` (--ip/--user/--all/daftar) | **Buat** |
| `app/Controllers/Admin/SuspendController.php` | `unblockIp()`; `index()` kirim daftar blokir; `_doResetLogin` pakai `LoginThrottle` | **Ubah** |
| `app/Config/Routes.php` | Route `admin/suspend/unblock-ip` | **Ubah** (dekat baris 84) |
| `app/Views/admin/suspend/index.php` | Panel daftar IP terblokir + tombol unblock | **Ubah** |
| `app/Database/Seeds/InitialSeeder.php` | Seed `login_ip_max_attempts` = 50 | **Ubah** (blok security ~baris 57) |
| `app/Controllers/Admin/SettingController.php` | `KEY_META` + default `resetSettings` untuk kunci baru | **Ubah** (baris 47-49, ~262) |
| `app/Views/admin/settings/index.php` | Field number di panel Kapasitas & Antrean | **Ubah** (~baris 1080) |
| `phpunit.xml.dist` | Daftarkan testsuite `Throttling` | **Ubah** |
| `tests/bootstrap_ci.php` | Bootstrap yang MEMUAT framework CI (dipakai hanya suite Throttling via `--bootstrap`) | **Buat** |
| `tests/Throttling/*.php` | Unit test library, filter, resolusi IP, command | **Buat** |

> **Catatan bootstrap (ditemukan saat eksekusi):** `tests/bootstrap.php` proyek
> ini SENGAJA tidak memuat framework, sehingga suite lain cepat & tanpa container.
> Test Throttling butuh framework (filter, `service('cache')`, `command()`,
> `Config\App`), jadi suite ini dijalankan dengan bootstrap terpisah
> `tests/bootstrap_ci.php` lewat flag `--bootstrap`. Suite lain tidak berubah.

**Perintah standar** (jalankan dari root repo `/home/rozen/conquer/CBT-MF`):

- **Lint:** `docker compose exec php php -l /var/www/html/<path relatif ke src>`
- **Tes:** `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling'`
- **Routes:** `docker compose exec php php spark routes | grep -i unblock`

---

### Task 1: Library `LoginThrottle` + registrasi suite `Throttling`

**Files:**
- Create: `src/app/Libraries/LoginThrottle.php`
- Create: `src/tests/Throttling/LoginThrottleTest.php`
- Modify: `src/phpunit.xml.dist` (tambah testsuite)

- [ ] **Step 1: Daftarkan testsuite `Throttling`**

Di `src/phpunit.xml.dist`, di dalam `<testsuites>`, tambahkan setelah blok
`Resilience` (baris 14-16):

```xml
        <testsuite name="Throttling">
            <directory>tests/Throttling</directory>
        </testsuite>
```

- [ ] **Step 2: Tulis test yang gagal**

Buat `src/tests/Throttling/LoginThrottleTest.php`. Test ini memakai Redis nyata
di container (host `redis`) dengan IP uji yang tidak mungkin bentrok, dan
membersihkan dirinya sendiri.

```php
<?php

namespace Tests\Throttling;

use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use CodeIgniter\Test\CIUnitTestCase;

final class LoginThrottleTest extends CIUnitTestCase
{
    private const IP_A = '203.0.113.201';
    private const IP_B = '203.0.113.202';

    private function redis(): \Redis
    {
        $r = RedisClient::getInstance();
        if ($r === null) {
            $this->markTestSkipped('Redis tidak tersedia di lingkungan test.');
        }
        return $r;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $r = $this->redis();
        $r->del(LoginThrottle::key(self::IP_A));
        $r->del(LoginThrottle::key(self::IP_B));
    }

    protected function tearDown(): void
    {
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP_A));
            $r->del(LoginThrottle::key(self::IP_B));
        }
        RedisClient::reset();
        parent::tearDown();
    }

    public function testKeyFormatIsStable(): void
    {
        $this->assertSame('login_attempts_ip:203.0.113.201', LoginThrottle::key(self::IP_A));
    }

    public function testHitIncrementsAndSetsTtlOnFirstHit(): void
    {
        $this->assertSame(1, LoginThrottle::hit(self::IP_A));
        $this->assertSame(2, LoginThrottle::hit(self::IP_A));
        $ttl = $this->redis()->ttl(LoginThrottle::key(self::IP_A));
        $this->assertGreaterThan(0, $ttl, 'TTL harus dipasang pada hit pertama');
        $this->assertLessThanOrEqual(LoginThrottle::WINDOW_SECONDS, $ttl);
    }

    public function testClearForIpRemovesTheCounter(): void
    {
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::clearForIp(self::IP_A);
        $this->assertSame(0, (int) $this->redis()->exists(LoginThrottle::key(self::IP_A)));
    }

    public function testActiveBlocksReportsCounts(): void
    {
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::hit(self::IP_B);
        $blocks = LoginThrottle::activeBlocks();
        $this->assertSame(2, $blocks[self::IP_A] ?? null);
        $this->assertSame(1, $blocks[self::IP_B] ?? null);
    }

    public function testClearAllRemovesEveryCounter(): void
    {
        LoginThrottle::hit(self::IP_A);
        LoginThrottle::hit(self::IP_B);
        $removed = LoginThrottle::clearAll();
        $this->assertGreaterThanOrEqual(2, $removed);
        $this->assertSame([], LoginThrottle::activeBlocks());
    }
}
```

- [ ] **Step 3: Jalankan test — pastikan gagal**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling'`
Expected: FAIL — `Class "App\Libraries\LoginThrottle" not found`.

- [ ] **Step 4: Tulis library**

Buat `src/app/Libraries/LoginThrottle.php`:

```php
<?php

namespace App\Libraries;

use App\Models\SettingModel;

/**
 * Satu sumber kebenaran untuk rem login per-IP.
 *
 * Format kunci, penambahan hitungan, penghapusan, dan pendaftaran dipakai oleh
 * LoginRateLimitFilter, AuthController (reset-on-success), command auth:unblock,
 * dan SuspendController. Tidak ada salinan kedua yang bisa menyimpang.
 */
class LoginThrottle
{
    /** Jendela hitung ulang, konstan (lihat spec: knob utama adalah jumlah, bukan durasi). */
    public const WINDOW_SECONDS = 900;

    /** Dipakai bila setting login_ip_max_attempts belum ada di DB. */
    public const DEFAULT_MAX_ATTEMPTS = 50;

    private const PREFIX = 'login_attempts_ip:';

    public static function key(string $ip): string
    {
        return self::PREFIX . $ip;
    }

    /**
     * INCR hitungan IP; pasang TTL pada hit pertama; kembalikan hitungan kini.
     * Null bila Redis tak tersedia. Sengaja TIDAK menelan exception: pemanggil
     * (filter) yang memutuskan fail-open, supaya keputusan A hidup di satu tempat.
     */
    public static function hit(string $ip): ?int
    {
        $redis = RedisClient::getInstance();
        if (!$redis) {
            return null;
        }
        $key   = self::key($ip);
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, self::WINDOW_SECONDS);
        }
        return $count;
    }

    public static function maxAttempts(): int
    {
        return (int) (new SettingModel())->getValue('login_ip_max_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    public static function clearForIp(string $ip): void
    {
        try {
            $redis = RedisClient::getInstance();
            if ($redis) {
                $redis->del(self::key($ip));
            }
        } catch (\Throwable $e) {
            log_message('error', 'LoginThrottle::clearForIp gagal: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string,int> ip => hitungan kini, untuk diagnostik admin/CLI.
     */
    public static function activeBlocks(): array
    {
        $out = [];
        try {
            $redis = RedisClient::getInstance();
            if (!$redis) {
                return $out;
            }
            $cursor = null;
            do {
                $keys = $redis->scan($cursor, self::PREFIX . '*', 500);
                if (!is_array($keys)) {
                    break;
                }
                foreach ($keys as $key) {
                    $ip       = substr($key, strlen(self::PREFIX));
                    $out[$ip] = (int) $redis->get($key);
                }
            } while ($cursor !== null && $cursor > 0);
        } catch (\Throwable $e) {
            log_message('error', 'LoginThrottle::activeBlocks gagal: ' . $e->getMessage());
        }
        return $out;
    }

    public static function clearAll(): int
    {
        $removed = 0;
        try {
            $redis = RedisClient::getInstance();
            if (!$redis) {
                return 0;
            }
            $cursor = null;
            do {
                $keys = $redis->scan($cursor, self::PREFIX . '*', 500);
                if (!is_array($keys)) {
                    break;
                }
                foreach ($keys as $key) {
                    $redis->del($key);
                    $removed++;
                }
            } while ($cursor !== null && $cursor > 0);
        } catch (\Throwable $e) {
            log_message('error', 'LoginThrottle::clearAll gagal: ' . $e->getMessage());
        }
        return $removed;
    }
}
```

- [ ] **Step 5: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Libraries/LoginThrottle.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Jalankan test — pastikan lulus**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling'`
Expected: PASS (5 test di `LoginThrottleTest`).

- [ ] **Step 7: Commit**

```bash
git add src/app/Libraries/LoginThrottle.php src/tests/Throttling/LoginThrottleTest.php src/phpunit.xml.dist
git commit -m "feat(login-throttle): library sumber-kebenaran rem login per-IP"
```

---

### Task 2: Resolusi IP klien lewat `CF-Connecting-IP` (Config\App::$proxyIPs)

**Files:**
- Modify: `src/app/Config/App.php:183-185` (+ constructor)
- Create: `src/tests/Throttling/ClientIpResolutionTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `src/tests/Throttling/ClientIpResolutionTest.php`. Test ini mengunci
keputusan "header tepercaya = CF-Connecting-IP, hanya dari peer tepercaya".

```php
<?php

namespace Tests\Throttling;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class ClientIpResolutionTest extends CIUnitTestCase
{
    private function requestWith(array $server): IncomingRequest
    {
        // getServer() dan populateHeaders() membaca service 'superglobals' (snapshot
        // saat boot), bukan $_SERVER langsung — jadi injeksi lewat service SEBELUM
        // request dibangun agar header CF-Connecting-IP ikut terbaca.
        service('superglobals')->setServerArray($server);

        // App() constructor membangun $proxyIPs dari env (default 172.16.0.0/12).
        return new IncomingRequest(new App(), new URI('http://localhost'), null, new UserAgent());
    }

    protected function tearDown(): void
    {
        \Config\Services::resetSingle('superglobals');
        parent::tearDown();
    }

    public function testTrustedPeerUsesCfConnectingIp(): void
    {
        $req = $this->requestWith([
            'REMOTE_ADDR'           => '172.20.0.5',   // bridge docker → tepercaya
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',  // IP publik siswa (dari CF)
        ]);
        $this->assertSame('203.0.113.9', $req->getIPAddress());
    }

    public function testUntrustedPeerIgnoresHeader(): void
    {
        $req = $this->requestWith([
            'REMOTE_ADDR'           => '203.0.113.9',  // bukan proxy tepercaya
            'HTTP_CF_CONNECTING_IP' => '10.0.0.1',     // upaya spoof
        ]);
        $this->assertSame('203.0.113.9', $req->getIPAddress());
    }

    public function testTrustedPeerWithoutHeaderFallsBackToRemoteAddr(): void
    {
        $req = $this->requestWith([
            'REMOTE_ADDR' => '172.20.0.5',             // akses LAN langsung, tanpa CF
        ]);
        $this->assertSame('172.20.0.5', $req->getIPAddress());
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling --filter ClientIpResolutionTest'`
Expected: FAIL pada `testTrustedPeerUsesCfConnectingIp` — `getIPAddress()` masih membaca `X-Forwarded-For`, mengembalikan `172.20.0.5` bukan `203.0.113.9`.

- [ ] **Step 3: Ubah `$proxyIPs` jadi env-driven + header CF-Connecting-IP**

Di `src/app/Config/App.php`, ganti properti (baris 183-185):

```php
    public array $proxyIPs = [
        '172.16.0.0/12' => 'X-Forwarded-For',
    ];
```

menjadi:

```php
    /**
     * Diisi di constructor dari env app.trustedProxyIPs.
     *
     * Header sengaja CF-Connecting-IP, bukan X-Forwarded-For: XFF boleh
     * ditambahi klien sebelum sampai ke Cloudflare, sedangkan CF-Connecting-IP
     * di-set otoritatif oleh edge Cloudflare — tidak dapat dipalsukan lewat
     * header dari klien. Rem login mengunci berdasar IP ini.
     */
    public array $proxyIPs = [];
```

Lalu tambahkan constructor tepat setelah properti-properti config (misal setelah
blok `$CSPEnabled` / sebelum penutup class — letakkan sebagai method pertama):

```php
    public function __construct()
    {
        parent::__construct();

        $raw   = (string) env('app.trustedProxyIPs', '172.16.0.0/12');
        $cidrs = array_filter(array_map('trim', explode(',', $raw)));
        foreach ($cidrs as $cidr) {
            $this->proxyIPs[$cidr] = 'CF-Connecting-IP';
        }
    }
```

- [ ] **Step 4: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Config/App.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling --filter ClientIpResolutionTest'`
Expected: PASS (3 test).

- [ ] **Step 6: Dokumentasikan env (opsional, non-kode)**

Tambahkan ke `src/.env` (dan contoh `.env` bila ada) satu baris:

```
app.trustedProxyIPs = '172.16.0.0/12'
```

Ini opsional — default constructor sudah `172.16.0.0/12`. Verifikasi tidak
mematahkan boot:
Run: `docker compose exec php php spark env`
Expected: perintah selesai tanpa error (environment tercetak).

- [ ] **Step 7: Commit**

```bash
git add src/app/Config/App.php src/tests/Throttling/ClientIpResolutionTest.php
git commit -m "feat(login-throttle): resolve IP klien via CF-Connecting-IP dari peer tepercaya"
```

---

### Task 3: Setting `login_ip_max_attempts`

**Files:**
- Modify: `src/app/Database/Seeds/InitialSeeder.php` (blok security ~baris 57)
- Modify: `src/app/Controllers/Admin/SettingController.php` (`KEY_META` ~baris 49; `resetSettings` ~baris 262)
- Modify: `src/app/Views/admin/settings/index.php` (~baris 1080, panel Kapasitas & Antrean)

- [ ] **Step 1: Seed nilai default di InitialSeeder**

Di `src/app/Database/Seeds/InitialSeeder.php`, setelah baris `lockout_duration`
(baris 57), tambahkan:

```php
            ['key' => 'login_ip_max_attempts', 'value' => '50',            'type' => 'integer', 'group' => 'security', 'description' => 'Maksimal percobaan login gagal per IP dalam 15 menit sebelum diblokir sementara'],
```

- [ ] **Step 2: Daftarkan tipe di KEY_META (SettingController)**

Di `src/app/Controllers/Admin/SettingController.php`, dalam `KEY_META`, setelah
`max_concurrent_connections` (baris 49), tambahkan:

```php
        'login_ip_max_attempts'     => ['group' => 'security', 'type' => 'integer'],
```

(Catatan: `INTEGER_KEYS` di baris 21-26 adalah dead code — didefinisikan, tak
pernah dipakai `update()`. Sengaja tidak disentuh; yang menentukan tipe saat
simpan adalah `KEY_META`.)

- [ ] **Step 3: Tambahkan default di resetSettings**

Di `src/app/Controllers/Admin/SettingController.php`, dalam array `$defaults`
`resetSettings()`, setelah baris `max_concurrent_connections` (~baris 262),
tambahkan:

```php
            ['key' => 'login_ip_max_attempts', 'value' => '50',            'type' => 'integer', 'group' => 'security'],
```

- [ ] **Step 4: Tambahkan field di view settings**

Di `src/app/Views/admin/settings/index.php`, di panel "Kapasitas & Antrean",
tepat setelah `</div>` penutup row `maxConcurrentSlots` (baris 1080), tambahkan
row baru:

```php
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="loginIpMaxAttempts">Batas Percobaan Login per IP</label>
                            <p class="s-desc">Percobaan login gagal per koneksi dalam 15 menit sebelum diblokir sementara. Naikkan untuk sekolah ber-CGNAT (banyak siswa satu IP publik). Default 50.</p>
                        </div>
                        <div class="s-ctrl">
                            <div class="s-unit">
                                <input type="number" class="form-control text-center" id="loginIpMaxAttempts" name="settings[login_ip_max_attempts]" value="<?= esc(settingVal($groupedSettings, 'security', 'login_ip_max_attempts', '50')) ?>" min="5" max="10000" style="width: 120px;">
                                <span class="unit">/ 15 mnt</span>
                            </div>
                        </div>
                    </div>
```

- [ ] **Step 5: Lint ketiga berkas**

Run:
```bash
docker compose exec php php -l /var/www/html/app/Database/Seeds/InitialSeeder.php
docker compose exec php php -l /var/www/html/app/Controllers/Admin/SettingController.php
docker compose exec php php -l /var/www/html/app/Views/admin/settings/index.php
```
Expected: ketiganya `No syntax errors detected`.

- [ ] **Step 6: Verifikasi wiring (grep, bukan runtime)**

Run: `docker compose exec php sh -c "grep -n login_ip_max_attempts /var/www/html/app/Controllers/Admin/SettingController.php /var/www/html/app/Database/Seeds/InitialSeeder.php /var/www/html/app/Views/admin/settings/index.php"`
Expected: 4 baris — KEY_META, resetSettings, seeder, view.

Perilaku instalasi lama: kunci belum ada di DB → `LoginThrottle::maxAttempts()`
mengembalikan default 50 lewat argumen `getValue`. Admin yang menyimpan setting
pertama kali membuat barisnya. Jadi tidak ada migrasi yang diperlukan.

- [ ] **Step 7: Commit**

```bash
git add src/app/Database/Seeds/InitialSeeder.php src/app/Controllers/Admin/SettingController.php src/app/Views/admin/settings/index.php
git commit -m "feat(login-throttle): setting login_ip_max_attempts (default 50, admin-tunable)"
```

---

### Task 4: Rombak `LoginRateLimitFilter` — ambang dari setting + fail-open

**Files:**
- Modify: `src/app/Filters/LoginRateLimitFilter.php` (seluruh `before()`)
- Create: `src/tests/Throttling/LoginRateLimitFilterTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `src/tests/Throttling/LoginRateLimitFilterTest.php`. Dua perilaku diuji:
ambang dibaca dari setting (bukan 20 hardcoded), dan fail-open saat Redis beku
(keputusan A) — dulu `503`, kini login diteruskan. Frozen-Redis stub meniru
pola `tests/Resilience/RedisTimeoutTest.php`.

```php
<?php

namespace Tests\Throttling;

use App\Filters\LoginRateLimitFilter;
use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

final class LoginRateLimitFilterTest extends CIUnitTestCase
{
    private const IP = '203.0.113.150';        // peer tak-tepercaya → getIPAddress = REMOTE_ADDR
    private const FROZEN_PORT = 63997;

    /** @var resource|null */
    private static $frozen;

    public static function setUpBeforeClass(): void
    {
        self::$frozen = @stream_socket_server('tcp://127.0.0.1:' . self::FROZEN_PORT, $errno, $errstr);
        if (self::$frozen === false) {
            self::fail('gagal bind frozen-Redis stub: ' . $errstr);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$frozen)) {
            fclose(self::$frozen);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP));
        }
        // Ambang kecil = 3, ditanam ke cache agar getValue tak menyentuh DB.
        service('cache')->save('setting_login_ip_max_attempts', 3, 120);
    }

    protected function tearDown(): void
    {
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP));
        }
        service('cache')->delete('setting_login_ip_max_attempts');
        RedisClient::reset();
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    private function postRequest(): IncomingRequest
    {
        // getMethod() & getIPAddress() membaca service 'superglobals', bukan $_SERVER.
        service('superglobals')->setServerArray([
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR'    => self::IP,
        ]);
        return new IncomingRequest(new App(), new URI('http://localhost/login'), null, new UserAgent());
    }

    private function failIfItHangs(int $seconds): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            echo "\nHANG: filter tidak pernah kembali\n";
            exit(1);
        });
        pcntl_alarm($seconds);
    }

    public function testThresholdComesFromSettingAndBlocksAboveIt(): void
    {
        $filter = new LoginRateLimitFilter();
        // Ambang 3: percobaan 1,2,3 lolos (null), ke-4 diblokir.
        $this->assertNull($filter->before($this->postRequest()));
        $this->assertNull($filter->before($this->postRequest()));
        $this->assertNull($filter->before($this->postRequest()));
        $blocked = $filter->before($this->postRequest());
        $this->assertInstanceOf(ResponseInterface::class, $blocked);
    }

    public function testFailsOpenWhenRedisFrozen(): void
    {
        $budget = RedisClient::READ_TIMEOUT_SECONDS + 5;
        $this->failIfItHangs($budget);

        $orig = [
            'redis.host'     => $_ENV['redis.host'] ?? null,
            'redis.port'     => $_ENV['redis.port'] ?? null,
            'REDIS_PASSWORD' => $_ENV['REDIS_PASSWORD'] ?? null,
        ];

        // REDIS_PASSWORD='' WAJIB: tanpa ini getInstance mencoba auth() ke stub,
        // gagal, dan mengembalikan null (jalur "unreachable" yang memang sudah
        // fail-open sejak dulu). Dengan auth dilewati, getInstance memberi koneksi
        // hidup ke stub beku → incr() melempar exception, persis jalur yang DULU 503.
        RedisClient::reset();
        $_ENV['redis.host']     = '127.0.0.1';
        $_ENV['redis.port']     = (string) self::FROZEN_PORT;
        $_ENV['REDIS_PASSWORD'] = '';

        try {
            $filter = new LoginRateLimitFilter();
            $result = $filter->before($this->postRequest());
            $this->assertNull($result, 'fail-open: login diteruskan saat perintah Redis error');
        } finally {
            pcntl_alarm(0);
            foreach ($orig as $k => $v) {
                if ($v === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $v;
                }
            }
            RedisClient::reset();
        }
    }
}

<!-- Catatan verifikasi: keabsahan guard fail-open dibuktikan saat eksekusi dengan
     sementara mengembalikan perilaku 503 lama di catch → test ini gagal; lalu
     dikembalikan ke `return;` → test lulus. -->

```

Catatan sensitivitas: test fail-open menganggap `service('cache')` (untuk membaca
ambang) tidak memakai env `redis.host`/`redis.port` yang sama dengan
`RedisClient` — di app ini benar (cache dikonfigurasi terpisah dari `RedisClient`).
Ambang juga sudah ditanam ke cache di `setUp`, sehingga `maxAttempts()` tak
menyentuh DB. Kalau di suatu instalasi cache ikut beku, alarm `failIfItHangs`
akan menyalak dan menandai perlu isolasi lebih lanjut.

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling --filter LoginRateLimitFilterTest'`
Expected: FAIL — `testThresholdComesFromSettingAndBlocksAboveIt` gagal (filter masih pakai ambang 20, jadi ke-4 tetap `null`), dan/atau `testFailsOpenWhenRedisFrozen` gagal (filter lama kembalikan `503`, bukan `null`).

- [ ] **Step 3: Rombak `before()`**

Ganti seluruh isi `src/app/Filters/LoginRateLimitFilter.php` menjadi:

```php
<?php

namespace App\Filters;

use App\Libraries\LoginThrottle;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class LoginRateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Rate limiting hanya berlaku untuk POST /login.
        if (strcasecmp($request->getMethod(), 'post') !== 0) {
            return;
        }

        $ip = $request->getIPAddress();

        try {
            $max   = LoginThrottle::maxAttempts();
            $count = LoginThrottle::hit($ip);

            // Redis tak tersedia (getInstance null) → hit() null → lolos (fail-open).
            if ($count !== null && $count > $max) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Terlalu banyak percobaan login dari koneksi Anda. Silakan coba lagi dalam 15 menit.');
            }
        } catch (\Throwable $e) {
            // Keputusan A — FAIL-OPEN: satu blip/beku Redis tak boleh melumpuhkan
            // login. Lockout per-akun (DB) tetap menahan brute force tanpa Redis,
            // dan Cloudflare menahan flood CPU di edge. Cukup catat peringatan.
            log_message('error', 'LoginRateLimitFilter fail-open (Redis error): ' . $e->getMessage());
            return;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
}
```

- [ ] **Step 4: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Filters/LoginRateLimitFilter.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling --filter LoginRateLimitFilterTest'`
Expected: PASS (2 test). Waktu ~3-4 dtk untuk test frozen.

- [ ] **Step 6: Commit**

```bash
git add src/app/Filters/LoginRateLimitFilter.php src/tests/Throttling/LoginRateLimitFilterTest.php
git commit -m "feat(login-throttle): ambang dari setting + fail-open saat Redis mati (keputusan A)"
```

---

### Task 5: Reset-on-success di AuthController

**Files:**
- Modify: `src/app/Controllers/Auth/AuthController.php` (setelah baris 250)

- [ ] **Step 1: Hapus kunci IP saat login berhasil**

Di `src/app/Controllers/Auth/AuthController.php`, di jalur sukses, tepat setelah
pemanggilan `recordLogin` (baris 250):

```php
            // Login successful — set session
            $this->userModel->recordLogin($user->id, $this->request->getIPAddress());
```

tambahkan baris berikut segera setelahnya:

```php

            // Reset-on-success: selama ada yang berhasil login di balik satu
            // CGNAT, counter per-IP dibersihkan sehingga tak menumpuk ke blokir
            // massal. IP-nya sama persis dengan yang di-INCR filter (keduanya
            // getIPAddress()), jadi penghapusannya tepat sasaran.
            \App\Libraries\LoginThrottle::clearForIp($this->request->getIPAddress());
```

(Penempatan setelah `recordLogin` mencakup jalur normal maupun antrean —
keduanya lewat baris ini sebelum bercabang.)

- [ ] **Step 2: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Controllers/Auth/AuthController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verifikasi pemanggilan tertanam**

Run: `docker compose exec php sh -c "grep -n 'LoginThrottle::clearForIp' /var/www/html/app/Controllers/Auth/AuthController.php"`
Expected: satu baris, di dalam jalur sukses (nomor baris > 250).

- [ ] **Step 4: Regresi — pastikan suite masih hijau**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling'`
Expected: PASS (semua test Task 1-4).

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Auth/AuthController.php
git commit -m "feat(login-throttle): reset counter IP saat login berhasil (CGNAT-pemaaf)"
```

---

### Task 6: Command break-glass `php spark auth:unblock`

**Files:**
- Create: `src/app/Commands/AuthUnblock.php`
- Create: `src/tests/Throttling/AuthUnblockCommandTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `src/tests/Throttling/AuthUnblockCommandTest.php`. Menguji jalur
non-interaktif: `--ip` menghapus kunci, daftar tanpa argumen menampilkan IP, dan
`--user` yang tak ada memberi pesan yang jelas. (`--all` interaktif lewat prompt,
diliput oleh `LoginThrottle::clearAll()` di Task 1.)

```php
<?php

namespace Tests\Throttling;

use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use CodeIgniter\Test\CIUnitTestCase;

final class AuthUnblockCommandTest extends CIUnitTestCase
{
    private const IP = '203.0.113.210';

    protected function setUp(): void
    {
        parent::setUp();
        $r = RedisClient::getInstance();
        if ($r === null) {
            $this->markTestSkipped('Redis tidak tersedia.');
        }
        $r->del(LoginThrottle::key(self::IP));
    }

    protected function tearDown(): void
    {
        $r = RedisClient::getInstance();
        if ($r) {
            $r->del(LoginThrottle::key(self::IP));
        }
        RedisClient::reset();
        parent::tearDown();
    }

    public function testUnblockByIpRemovesTheKey(): void
    {
        LoginThrottle::hit(self::IP);
        $output = command('auth:unblock --ip ' . self::IP);
        $this->assertStringContainsString('dibuka', $output);
        $this->assertSame([], LoginThrottle::activeBlocks());
    }

    public function testInvalidIpIsRejected(): void
    {
        $output = command('auth:unblock --ip not-an-ip');
        $this->assertStringContainsString('tidak valid', $output);
    }

    public function testListWithoutArgsShowsBlockedIp(): void
    {
        LoginThrottle::hit(self::IP);
        $output = command('auth:unblock');
        $this->assertStringContainsString(self::IP, $output);
    }

    public function testUnknownUserReportsClearly(): void
    {
        $output = command('auth:unblock --user __tidak_ada_user__');
        $this->assertStringContainsString('tidak ditemukan', $output);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan gagal**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling --filter AuthUnblockCommandTest'`
Expected: FAIL — command `auth:unblock` belum ada (`Command "auth:unblock" not found`).

- [ ] **Step 3: Tulis command**

Buat `src/app/Commands/AuthUnblock.php`:

```php
<?php

namespace App\Commands;

use App\Libraries\LoginThrottle;
use App\Libraries\RedisClient;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Break-glass: buka blokir rate-limit login dari shell walau web terkunci.
 *
 *   php spark auth:unblock --ip 203.0.113.9   → hapus blokir satu IP
 *   php spark auth:unblock --user budi         → reset lockout akun + IP terakhirnya
 *   php spark auth:unblock --all               → hapus semua blokir IP (konfirmasi)
 *   php spark auth:unblock                      → daftar IP yang sedang terblokir
 */
class AuthUnblock extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'auth:unblock';
    protected $description  = 'Buka blokir rate-limit login: per-IP, per-user, atau semua. Tanpa argumen: daftar IP terblokir.';
    protected $usage       = 'auth:unblock [--ip A.B.C.D] [--user USERNAME] [--all]';
    protected $options     = [
        '--ip'   => 'Hapus blokir satu IP.',
        '--user' => 'Reset lockout akun + blokir IP terakhirnya.',
        '--all'  => 'Hapus SEMUA blokir IP (minta konfirmasi).',
    ];

    public function run(array $params)
    {
        $ip   = CLI::getOption('ip');
        $user = CLI::getOption('user');
        $all  = CLI::getOption('all');

        if ($all) {
            if (CLI::prompt('Hapus SEMUA blokir IP login?', ['y', 'n']) !== 'y') {
                CLI::write('Dibatalkan.', 'yellow');
                return EXIT_SUCCESS;
            }
            $n = LoginThrottle::clearAll();
            CLI::write("Selesai — {$n} blokir IP dihapus.", 'green');
            return EXIT_SUCCESS;
        }

        if (is_string($ip) && $ip !== '') {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                CLI::error("IP tidak valid: {$ip}");
                return EXIT_ERROR;
            }
            LoginThrottle::clearForIp($ip);
            CLI::write("Blokir login untuk IP {$ip} dibuka.", 'green');
            return EXIT_SUCCESS;
        }

        if (is_string($user) && $user !== '') {
            $userModel = new UserModel();
            $row       = $userModel->where('username', $user)->first();
            if (!$row) {
                CLI::error("User tidak ditemukan: {$user}");
                return EXIT_ERROR;
            }

            $userModel->resetLoginAttempts((int) $row->id);

            try {
                $redis = RedisClient::getInstance();
                if ($redis) {
                    $failedIp = $redis->get("last_failed_login_ip:{$row->id}");
                    if ($failedIp) {
                        LoginThrottle::clearForIp((string) $failedIp);
                        $redis->del("last_failed_login_ip:{$row->id}");
                    }
                }
            } catch (\Throwable $e) {
                CLI::write('Peringatan: gagal membersihkan kunci IP Redis: ' . $e->getMessage(), 'yellow');
            }

            CLI::write("Lockout akun '{$user}' direset dan blokir IP terakhirnya dibuka.", 'green');
            return EXIT_SUCCESS;
        }

        // Tanpa argumen: diagnostik.
        $blocks = LoginThrottle::activeBlocks();
        if (empty($blocks)) {
            CLI::write('Tidak ada IP dengan percobaan login aktif.', 'green');
            return EXIT_SUCCESS;
        }

        $max = LoginThrottle::maxAttempts();
        CLI::write("Percobaan login aktif (diblokir bila > {$max}):", 'yellow');
        $rows = [];
        foreach ($blocks as $bip => $count) {
            $rows[] = [$bip, (string) $count, $count > $max ? 'TERBLOKIR' : 'ok'];
        }
        CLI::table($rows, ['IP', 'Percobaan', 'Status']);
        return EXIT_SUCCESS;
    }
}
```

- [ ] **Step 4: Lint + daftar command**

Run:
```bash
docker compose exec php php -l /var/www/html/app/Commands/AuthUnblock.php
docker compose exec php php spark list | grep -i auth:unblock
```
Expected: lint bersih, dan `auth:unblock` muncul di daftar command.

- [ ] **Step 5: Jalankan test — pastikan lulus**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling --filter AuthUnblockCommandTest'`
Expected: PASS (4 test).

- [ ] **Step 6: Uji-tangan cepat**

Run: `docker compose exec php php spark auth:unblock`
Expected: "Tidak ada IP dengan percobaan login aktif." (atau tabel bila ada).

- [ ] **Step 7: Commit**

```bash
git add src/app/Commands/AuthUnblock.php src/tests/Throttling/AuthUnblockCommandTest.php
git commit -m "feat(login-throttle): command break-glass auth:unblock"
```

---

### Task 7: Tombol unblock web di halaman Suspend (keputusan B)

**Files:**
- Modify: `src/app/Controllers/Admin/SuspendController.php` (`index`, `_doResetLogin`, tambah `unblockIp`)
- Modify: `src/app/Config/Routes.php` (dekat baris 84)
- Modify: `src/app/Views/admin/suspend/index.php` (panel baru)

- [ ] **Step 1: DRY-kan `_doResetLogin` ke LoginThrottle**

Di `src/app/Controllers/Admin/SuspendController.php`, di `_doResetLogin` (baris
147-151), ganti:

```php
                $failedIp = $redis->get("last_failed_login_ip:{$userId}");
                if ($failedIp) {
                    $redis->del("login_attempts_ip:{$failedIp}");
                    $redis->del("last_failed_login_ip:{$userId}");
                }
```

menjadi:

```php
                $failedIp = $redis->get("last_failed_login_ip:{$userId}");
                if ($failedIp) {
                    \App\Libraries\LoginThrottle::clearForIp((string) $failedIp);
                    $redis->del("last_failed_login_ip:{$userId}");
                }
```

- [ ] **Step 2: Kirim daftar blokir ke view dari `index()`**

Di `index()` (baris 45-49), ubah pemanggilan `view(...)` agar menyertakan daftar:

```php
        return view('admin/suspend/index', [
            'users' => $users,
            'pager' => $pager,
            'search' => $search,
            'ipBlocks' => \App\Libraries\LoginThrottle::activeBlocks(),
            'ipBlockMax' => \App\Libraries\LoginThrottle::maxAttempts(),
        ]);
```

- [ ] **Step 3: Tambah method `unblockIp()`**

Di `src/app/Controllers/Admin/SuspendController.php`, tambahkan method (mis.
setelah `resetLogin`, baris 168):

```php
    /**
     * Break-glass web: buka blokir rate-limit login per-IP atau semua.
     * Melengkapi resetLogin() (per-user). Dijaga role:admin oleh grup route.
     */
    public function unblockIp()
    {
        if ($this->request->getPost('all') === '1') {
            $n = \App\Libraries\LoginThrottle::clearAll();
            return redirect()->to('/admin/suspend')->with('success', "Semua blokir IP login dibersihkan ({$n} IP).");
        }

        $ip = trim((string) $this->request->getPost('ip'));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return redirect()->to('/admin/suspend')->with('error', 'IP tidak valid.');
        }

        \App\Libraries\LoginThrottle::clearForIp($ip);
        return redirect()->to('/admin/suspend')->with('success', "Blokir login untuk IP {$ip} telah dibuka.");
    }
```

- [ ] **Step 4: Daftarkan route**

Di `src/app/Config/Routes.php`, di dalam grup `role:admin`, tepat setelah
route `suspend/reset-login/(:num)` (baris 84), tambahkan:

```php
        $routes->post('suspend/unblock-ip', 'Admin\SuspendController::unblockIp');
```

- [ ] **Step 5: Tambah panel di view Suspend**

Di `src/app/Views/admin/suspend/index.php`, tepat sebelum penutup form
`#bulkForm` / setelah `</table>` daftar user (baris 152), tambahkan panel:

```php
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Blokir Login per-IP (Rate Limit)</strong>
            <?php if (!empty($ipBlocks)): ?>
                <form action="<?= base_url('/admin/suspend/unblock-ip') ?>" method="POST" class="m-0"
                      onsubmit="return confirm('Buka semua blokir IP login?');">
                    <input type="hidden" name="all" value="1">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Unblock Semua</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($ipBlocks)): ?>
                <p class="text-muted m-3 mb-0">Tidak ada IP dengan percobaan login aktif.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>IP</th><th>Percobaan</th><th>Status</th><th class="text-end">Aksi</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ipBlocks as $bip => $count): ?>
                            <tr>
                                <td><code><?= esc($bip) ?></code></td>
                                <td><?= esc($count) ?></td>
                                <td>
                                    <?php if ($count > $ipBlockMax): ?>
                                        <span class="badge bg-danger">Terblokir (&gt; <?= esc($ipBlockMax) ?>)</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Aktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form action="<?= base_url('/admin/suspend/unblock-ip') ?>" method="POST" class="m-0 d-inline">
                                        <input type="hidden" name="ip" value="<?= esc($bip) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Unblock</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
```

(Panel berdiri sendiri; tombolnya form POST terpisah, jadi aman diletakkan
setelah tabel user tanpa mengganggu `#bulkForm`.)

- [ ] **Step 6: Lint keempat berkas**

Run:
```bash
docker compose exec php php -l /var/www/html/app/Controllers/Admin/SuspendController.php
docker compose exec php php -l /var/www/html/app/Config/Routes.php
docker compose exec php php -l /var/www/html/app/Views/admin/suspend/index.php
```
Expected: semua `No syntax errors detected`.

- [ ] **Step 7: Verifikasi route terdaftar**

Run: `docker compose exec php php spark routes | grep -i "suspend/unblock-ip"`
Expected: satu baris memetakan `admin/suspend/unblock-ip [POST]` → `SuspendController::unblockIp`.

- [ ] **Step 8: Regresi penuh**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --bootstrap tests/bootstrap_ci.php --testsuite Throttling'`
Expected: PASS (seluruh suite Throttling: library, IP, filter, command).

- [ ] **Step 9: Commit**

```bash
git add src/app/Controllers/Admin/SuspendController.php src/app/Config/Routes.php src/app/Views/admin/suspend/index.php
git commit -m "feat(login-throttle): panel unblock IP di halaman Suspend (keputusan B)"
```

---

## Verifikasi manual di perangkat (setelah semua task)

Ikuti langkah bernomor ini (jangan otomatiskan lewat adb — user yang menjalankan
device; lihat catatan tim). Rebuild bundle UI **tidak** diperlukan: tak ada
perubahan `exam-app.js` / view bundle.

1. Di container, set ambang kecil untuk uji: `docker compose exec php php spark auth:unblock` → pastikan kosong.
2. Dari HP, buka halaman login, salah password berkali-kali dari satu koneksi sampai melewati ambang → muncul pesan "Terlalu banyak percobaan login".
3. Login **benar** dari HP lain di IP yang sama (CGNAT) → berhasil; counter IP ter-reset (verifikasi: `php spark auth:unblock` menunjukkan IP hilang/ berkurang).
4. Admin → halaman Suspend → panel "Blokir Login per-IP" menampilkan IP; klik **Unblock** → hilang.
5. Break-glass: `docker compose exec php php spark auth:unblock --ip <ip>` dan `--all` bekerja dari shell.

## Lampiran ops (bukan kode) — lapisan Cloudflare

Didokumentasikan di spec, dikonfigurasi di dashboard Cloudflare, bukan di repo:
**Rate Limiting Rule** pada `POST /login` + **Turnstile/Managed Challenge**.
Inilah lapisan yang mematikan risiko CPU (bcrypt tak jalan saat flood) dan
ramah-CGNAT (tantangan, bukan ban). Rem aplikasi di plan ini adalah jaring
kedua, bukan pertahanan volumetrik.

## Di luar cakupan

- Lockout per-akun (`max_login_attempts` / `lockout_duration`) — sudah benar, tak disentuh.
- Konfigurasi Cloudflare lewat kode.
- Membuat jendela 900 dtk dapat diatur (knob utama = jumlah percobaan).
- Rate limiting jalur non-login (`ApiRateLimitFilter`).

## Catatan self-review

- **Cakupan spec:** Komponen 1 (resolusi IP) → Task 2; Komponen 2 (filter rombak + fail-open A) → Task 4; Komponen 3 (setting) → Task 3; Komponen 4 (command) → Task 6; Komponen 5 (tombol web, B) → Task 7; reset-on-success → Task 5; library bersama → Task 1. Lampiran CF → non-kode.
- **Konsistensi tipe:** `LoginThrottle::key/hit/clearForIp/activeBlocks/clearAll/maxAttempts` dipakai identik di filter, AuthController, command, SuspendController. Kunci Redis `login_attempts_ip:{ip}` dan `last_failed_login_ip:{userId}` konsisten dengan kode yang ada.
- **IP tunggal:** semua pemanggil memakai `$request->getIPAddress()` (filter + AuthController) atau IP dari `last_failed_login_ip` (command + suspend), sehingga penghapusan selalu sasaran yang sama dengan yang di-INCR.

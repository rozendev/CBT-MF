# Pengambilalihan Sesi di Perangkat yang Sama — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Siswa yang aplikasinya mati di tengah ujian bisa masuk kembali sendiri tanpa admin, sementara perangkat lain tetap ditolak seperti sekarang.

**Architecture:** Kunci Redis pendamping `user_login_device:{userId}` menyimpan `device_id` pemegang sesi, ditulis dan dihapus berbarengan dengan `user_login_token`. Gerbang login memakai satu fungsi murni untuk memutuskan boleh-tidaknya mengambil alih, sehingga aturannya bisa diuji tanpa Redis maupun framework. Bentuk nilai `user_login_token` sengaja tidak diubah karena sentinel `'BANNED'` ditulis di tiga tempat lain.

**Tech Stack:** PHP 8.3 / CodeIgniter 4.7, Redis (phpredis), PHPUnit 10, Kotlin (Android minSdk 28).

**Spec:** `docs/superpowers/specs/2026-08-28-same-device-takeover-design.md`

---

## Catatan Lingkungan

Perintah dijalankan dari `/home/rozen/conquer/CBT-MF`. Pakai nama **service** docker compose (`php`, `mariadb`, `redis`), bukan nama container — prefiksnya berubah tiap instalasi.

- Lint: `docker compose exec php php -l /var/www/html/<path relatif ke src>`
- Tes: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit'` — saat ini **66** lulus
- Peringatan `writable/.phpunit.cache: Permission denied` sudah ada sebelumnya dan tidak berbahaya. Abaikan.

**Bundle UI berubah di Task 4**, jadi `spark cbt:build-ui-bundle` wajib dijalankan — dan **sebagai root**, bukan `--user 33:33`, karena `public/ui-bundle/` milik root.

---

## Kenapa tidak memakai skrip Lua

Rancangan awal memakai Lua agar pemeriksaan dan penulisan atomik. Itu dibuang dengan sengaja: Lua akan memuat salinan kedua dari aturan yang sama, dan aturan yang hidup di dua tempat akan menyimpang.

Ternyata tidak diperlukan. Jalur login baru tetap memakai `set nx`, sehingga dua perangkat tidak bisa sama-sama menang saat tidak ada token. Jalur ambil-alih hanya berjalan setelah pemanggilnya membuktikan `device_id`-nya cocok dengan yang tersimpan — dan perangkat lain yang mencoba bersamaan sudah ditolak di pembacaannya sendiri, jadi ia tidak pernah menulis.

---

## Struktur Berkas

| Berkas | Tanggung jawab |
|---|---|
| `src/app/Libraries/SessionTakeover.php` (baru) | Satu-satunya tempat aturan boleh-tidaknya ambil alih. Murni, tanpa I/O. |
| `src/tests/Resilience/SessionTakeoverTest.php` (baru) | Menjaga aturan itu |
| `src/app/Controllers/Auth/AuthController.php` | Memakai aturan; menulis dan menghapus kunci pendamping |
| `src/app/Controllers/Admin/SuspendController.php` | Menghapus kunci pendamping di dua tempat |
| `src/app/Views/bundle/login.php` | Menyertakan `device_id` di POST |
| `cbt-kiosk-app/.../bridge/CommsBridge.kt` | Mengekspor `device_id` ke WebView |

---

## Task 1: Aturan ambil-alih sebagai fungsi murni (TDD)

**Files:**
- Create: `src/app/Libraries/SessionTakeover.php`
- Test: `src/tests/Resilience/SessionTakeoverTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`src/tests/Resilience/SessionTakeoverTest.php`:

```php
<?php

namespace Tests\Resilience;

use App\Libraries\SessionTakeover;
use PHPUnit\Framework\TestCase;

/**
 * Aturan gerbang login. Diuji terpisah dari Redis karena inilah bagian yang
 * salah menyimpulkannya berarti siswa terkunci dari ujiannya sendiri, atau
 * sebaliknya, perlindungan multi-login terlewati.
 */
class SessionTakeoverTest extends TestCase
{
    public function testTanpaTokenSelaluBolehLogin(): void
    {
        $this->assertSame(
            SessionTakeover::FRESH,
            SessionTakeover::decide(null, null, 'perangkat-a')
        );
        // Tanpa token, tidak dikirimnya device_id pun tidak masalah:
        // tidak ada sesi yang bisa direbut dari siapa pun.
        $this->assertSame(
            SessionTakeover::FRESH,
            SessionTakeover::decide(null, null, '')
        );
    }

    public function testPerangkatSamaBolehMerebutSesinyaSendiri(): void
    {
        $this->assertSame(
            SessionTakeover::TAKEOVER,
            SessionTakeover::decide('token-lama', 'perangkat-a', 'perangkat-a')
        );
    }

    public function testPerangkatBerbedaDitolak(): void
    {
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', 'perangkat-a', 'perangkat-b')
        );
        // Dua string yang berbeda tapi "sama" di bawah perbandingan longgar PHP
        // (keduanya diurai sebagai notasi ilmiah bernilai nol). Menegaskan ===,
        // bukan ==, supaya refactor yang melonggarkannya tertangkap di sini.
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', '0e123456789012345678901234567890', '0e999999999999999999999999999999')
        );
    }

    /**
     * Login dari browser biasa tidak mengirim device_id. Kalau ini diloloskan,
     * perlindungan multi-login bisa dilewati hanya dengan TIDAK mengirim field
     * itu — ketiadaan bukti bukan bukti.
     */
    public function testTanpaDeviceIdDitolakSaatTokenAda(): void
    {
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', 'perangkat-a', '')
        );
    }

    /**
     * Kunci pendamping bisa hilang lebih dulu (TTL, flush, versi lama).
     * Tidak tahu siapa pemegangnya berarti tidak boleh merebut.
     */
    public function testPendampingHilangDitolak(): void
    {
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', null, 'perangkat-a')
        );
        $this->assertSame(
            SessionTakeover::BUSY,
            SessionTakeover::decide('token-lama', '', 'perangkat-a')
        );
    }

    /**
     * 'BANNED' bukan sesi. Perilaku lamanya dipertahankan persis: login yang
     * lolos pemeriksaan kredensial menimpanya, dan penegakan ban sesungguhnya
     * ada di pemeriksaan is_active, bukan di sini.
     */
    public function testBannedDitimpaBukanDitolak(): void
    {
        $this->assertSame(
            SessionTakeover::CLEAR_BANNED,
            SessionTakeover::decide('BANNED', null, 'perangkat-a')
        );
        $this->assertSame(
            SessionTakeover::CLEAR_BANNED,
            SessionTakeover::decide('BANNED', 'perangkat-a', '')
        );
    }
}
```

- [ ] **Step 2: Jalankan tes untuk memastikan GAGAL**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite Resilience'`
Expected: FAIL — `Error: Class "App\Libraries\SessionTakeover" not found`

- [ ] **Step 3: Tulis implementasinya**

`src/app/Libraries/SessionTakeover.php`:

```php
<?php

namespace App\Libraries;

/**
 * Boleh atau tidak sebuah login mengambil alih sesi yang sedang tercatat.
 *
 * Masalah yang dipecahkan: gerbang login lama hanya menanyakan "apakah ada
 * token?", tidak pernah "dari perangkat mana?". Token ber-TTL dua jam yang
 * diperpanjang tiap permintaan, jadi setelah aplikasi siswa mati, token
 * peninggalan sesi yang sudah tidak ada itu mengunci pemiliknya sendiri sampai
 * dua jam — dan hanya admin yang bisa melepaskannya.
 *
 * Dengan device_id pemegang sesi ikut dicatat, perangkat yang sama boleh
 * merebut kembali sesinya sendiri, sementara perangkat lain tetap ditolak
 * persis seperti sebelumnya.
 *
 * Murni dan tanpa I/O dengan sengaja: inilah bagian yang salah menyimpulkannya
 * berarti siswa terkunci dari ujiannya sendiri, atau sebaliknya, perlindungan
 * multi-login terlewati. Bagian seperti itu harus bisa diuji tanpa Redis.
 */
final class SessionTakeover
{
    /** Tidak ada sesi tercatat — login biasa. */
    public const FRESH = 'fresh';

    /** Perangkat yang sama merebut kembali sesinya sendiri. */
    public const TAKEOVER = 'takeover';

    /** Ada sesi milik perangkat lain, atau tidak diketahui milik siapa. */
    public const BUSY = 'busy';

    /** Penanda 'BANNED' ditimpa, bukan diperlakukan sebagai sesi. */
    public const CLEAR_BANNED = 'clear_banned';

    /**
     * @param string|null $existingToken Isi user_login_token, null bila tidak ada.
     * @param string|null $storedDevice  Isi user_login_device, null bila tidak ada.
     * @param string      $incomingDevice device_id yang dikirim klien, '' bila tidak ada.
     */
    public static function decide(?string $existingToken, ?string $storedDevice, string $incomingDevice): string
    {
        if ($existingToken === null || $existingToken === '') {
            return self::FRESH;
        }

        // 'BANNED' bukan sesi aktif. Perilaku lama dipertahankan: login yang
        // sudah lolos pemeriksaan kredensial menimpanya. Penegakan ban yang
        // sesungguhnya ada di pemeriksaan is_active, bukan di sini.
        if ($existingToken === 'BANNED') {
            return self::CLEAR_BANNED;
        }

        // Tidak tahu siapa pemegangnya berarti tidak boleh merebut. Kunci
        // pendamping bisa hilang lebih dulu karena TTL, flush, atau sesi yang
        // dibuat versi lama sebelum fitur ini ada.
        if ($storedDevice === null || $storedDevice === '') {
            return self::BUSY;
        }

        // Klien yang tidak mengirim device_id — misalnya browser biasa — tidak
        // pernah boleh merebut. Kalau diloloskan, perlindungan multi-login bisa
        // dilewati hanya dengan tidak mengirim field itu.
        if ($incomingDevice === '') {
            return self::BUSY;
        }

        // hash_equals(), bukan ===, dan ini disengaja.
        //
        // Keputusan ini berjalan SETELAH password terverifikasi, jadi pihak
        // yang sampai ke sini sudah memegang kredensial korban — yang belum ia
        // punya justru device_id korban. Bagi dia, $storedDevice adalah nilai
        // rahasia yang ingin ditebak, dan perbandingan yang keluar lebih cepat
        // saat byte pertama sudah beda memberi sinyal itu.
        //
        // "device_id bukan rahasia karena dikirim terbuka" menilai dari sudut
        // pandang perangkat yang sah, bukan penyerang, jadi bukan alasan yang
        // sah untuk melepas kendali ini.
        return hash_equals($storedDevice, $incomingDevice) ? self::TAKEOVER : self::BUSY;
    }
}
```

- [ ] **Step 4: Jalankan tes untuk memastikan LULUS**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite Resilience'`
Expected: PASS. Jumlah tes naik 6 dari sebelumnya.

- [ ] **Step 5: Commit**

```bash
git add src/app/Libraries/SessionTakeover.php src/tests/Resilience/SessionTakeoverTest.php
git commit -m "feat(session): aturan ambil-alih perangkat yang sama sebagai fungsi murni"
```

---

## Task 2: Gerbang login memakai aturan itu

**Files:**
- Modify: `src/app/Controllers/Auth/AuthController.php`

- [ ] **Step 1: Tambahkan import**

Di bagian atas `src/app/Controllers/Auth/AuthController.php`, tambahkan bersama import lain:

```php
use App\Libraries\SessionTakeover;
```

- [ ] **Step 2: Baca device_id dari permintaan**

Tepat **setelah** baris:

```php
        $loginToken = bin2hex(random_bytes(16));
        $tokenKey = "user_login_token:{$user->id}";
```

sisipkan:

```php
        $deviceKey = "user_login_device:{$user->id}";

        // Hanya diterima dari aplikasi kiosk, yang mengambilnya dari
        // DeviceIdentityStore — sumber yang sama dengan heartbeat dan
        // /api/kiosk/config. Browser biasa tidak mengirimnya, dan itu berarti
        // ia tidak pernah bisa merebut sesi milik perangkat lain.
        $incomingDevice = (string) ($this->request->getPost('device_id') ?? '');
        if (!DeviceBan::isValidDeviceId($incomingDevice)) {
            $incomingDevice = '';
        }
```

Tambahkan juga importnya di bagian atas berkas:

```php
use App\Libraries\DeviceBan;
```

`DeviceBan::isValidDeviceId()` dipakai ulang di sini supaya definisi "device_id yang sah" tetap satu — panjang maksimal 64 dan hanya `[A-Za-z0-9_-]`, dijangkar `\A...\z`.

- [ ] **Step 3: Ganti blok keputusan**

Ganti blok ini seluruhnya:

```php
        if ($preventMultiLogin) {
            try {
                $existingToken = $redis->get($tokenKey);
                // If a token exists and isn't a BANNED marker, they are already logged in elsewhere
                if ($existingToken && $existingToken !== 'BANNED') {
                    return $fail('Akun Anda sedang digunakan di perangkat lain. Silakan ke Administrator jika Anda merasa ini kesalahan.');
                }
                // Set the token atomically to prevent concurrent login bypass
                if ($existingToken === 'BANNED') {
                    $redis->setex($tokenKey, 7200, $loginToken);
                } else {
                    $set = $redis->set($tokenKey, $loginToken, ['nx', 'ex' => 7200]);
                    if (!$set) {
                        return $fail('Akun Anda sedang digunakan di perangkat lain. Silakan ke Administrator jika Anda merasa ini kesalahan.');
                    }
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis multi-login block/reserve error: ' . $e->getMessage());
                return $fail('Layanan sedang tidak tersedia. Coba lagi.');
            }
        } else {
```

menjadi:

```php
        if ($preventMultiLogin) {
            try {
                $existingRaw  = $redis->get($tokenKey);
                $storedRaw    = $redis->get($deviceKey);
                $existingToken = $existingRaw === false ? null : (string) $existingRaw;
                $storedDevice  = $storedRaw === false ? null : (string) $storedRaw;

                $decision = SessionTakeover::decide($existingToken, $storedDevice, $incomingDevice);

                if ($decision === SessionTakeover::BUSY) {
                    return $fail('Akun Anda sedang digunakan di perangkat lain. Silakan ke Administrator jika Anda merasa ini kesalahan.');
                }

                if ($decision === SessionTakeover::FRESH) {
                    // Tetap 'nx': dua perangkat yang login bersamaan saat tidak
                    // ada token tidak boleh sama-sama menang.
                    $set = $redis->set($tokenKey, $loginToken, ['nx', 'ex' => 7200]);
                    if (!$set) {
                        return $fail('Akun Anda sedang digunakan di perangkat lain. Silakan ke Administrator jika Anda merasa ini kesalahan.');
                    }
                } else {
                    // TAKEOVER dan CLEAR_BANNED sama-sama menimpa. Tidak perlu
                    // 'nx': perangkat lain yang mencoba bersamaan sudah ditolak
                    // di pembacaannya sendiri, jadi ia tidak pernah menulis.
                    $redis->setex($tokenKey, 7200, $loginToken);
                }
            } catch (\Exception $e) {
                log_message('error', 'Redis multi-login block/reserve error: ' . $e->getMessage());
                return $fail('Layanan sedang tidak tersedia. Coba lagi.');
            }
        } else {
```

- [ ] **Step 4: Tulis kunci pendamping di kedua jalur**

Cari akhir blok `if ($preventMultiLogin) { ... } else { ... }` — jalur `else` yang menulis token tanpa pemeriksaan:

```php
            // Write it anyway (overwriting)
            try {
                $redis->setex($tokenKey, 7200, $loginToken);
            } catch (\Exception $e) {
                log_message('error', 'Redis session store error: ' . $e->getMessage());
                return $fail('Layanan sedang tidak tersedia. Coba lagi.');
            }
        }
```

menjadi:

```php
            // Write it anyway (overwriting)
            try {
                $redis->setex($tokenKey, 7200, $loginToken);
            } catch (\Exception $e) {
                log_message('error', 'Redis session store error: ' . $e->getMessage());
                return $fail('Layanan sedang tidak tersedia. Coba lagi.');
            }
        }

        // Catat pemegang sesi supaya perangkat yang sama bisa merebutnya
        // kembali nanti. Ditulis di kedua jalur, dengan TTL yang sama persis
        // dengan tokennya — pendamping yang hidup lebih lama dari tokennya akan
        // memberi hak ambil alih kepada perangkat yang sudah tidak relevan.
        //
        // Kegagalan di sini tidak boleh menggagalkan login: akibat terburuknya
        // hanya siswa harus menunggu TTL atau minta admin, persis seperti
        // sebelum fitur ini ada.
        if ($incomingDevice !== '') {
            try {
                $redis->setex($deviceKey, 7200, $incomingDevice);
            } catch (\Exception $e) {
                log_message('warning', 'Gagal menulis penanda perangkat sesi: ' . $e->getMessage());
            }
        } else {
            // Login tanpa device_id (browser). Buang pendamping lama supaya
            // tidak ada perangkat yang mengira masih memegang sesi ini.
            try {
                $redis->del($deviceKey);
            } catch (\Exception $e) {
                log_message('warning', 'Gagal membuang penanda perangkat sesi: ' . $e->getMessage());
            }
        }
```

- [ ] **Step 5: Lint dan pastikan tes lama tetap lulus**

Run:
```bash
docker compose exec php php -l /var/www/html/app/Controllers/Auth/AuthController.php
docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit'
```
Expected: `No syntax errors detected`, lalu jumlah tes = 66 + 6 dari Task 1.

- [ ] **Step 6: Commit**

```bash
git add src/app/Controllers/Auth/AuthController.php
git commit -m "feat(session): perangkat yang sama boleh merebut kembali sesinya sendiri"
```

---

## Task 3: Kunci pendamping ikut dihapus di semua tempat

Pendamping yang hidup lebih lama dari tokennya akan memberi hak ambil alih kepada perangkat yang sudah tidak relevan.

**Files:**
- Modify: `src/app/Controllers/Auth/AuthController.php`
- Modify: `src/app/Controllers/Admin/SuspendController.php`

- [ ] **Step 1: Logout**

Di `src/app/Controllers/Auth/AuthController.php`, ganti:

```php
                    $redis->del("user_login_token:{$userId}");
                    $redis->zRem('active_sessions', $userId);
                    $redis->zRem('login_queue', $userId);
```

menjadi:

```php
                    $redis->del("user_login_token:{$userId}");
                    $redis->del("user_login_device:{$userId}");
                    $redis->zRem('active_sessions', $userId);
                    $redis->zRem('login_queue', $userId);
```

- [ ] **Step 2: Release**

Di `src/app/Controllers/Admin/SuspendController.php`, ganti:

```php
                $redis->del("user_login_token:{$userId}");
                $redis->del("ban_signal:{$userId}");
```

menjadi:

```php
                $redis->del("user_login_token:{$userId}");
                $redis->del("user_login_device:{$userId}");
                $redis->del("ban_signal:{$userId}");
```

- [ ] **Step 3: Reset login**

Di berkas yang sama, ganti:

```php
                $redis->del("user_login_token:{$userId}");
                $redis->zRem('active_sessions', $userId);
                $redis->zRem('login_queue', $userId);
```

menjadi:

```php
                $redis->del("user_login_token:{$userId}");
                $redis->del("user_login_device:{$userId}");
                $redis->zRem('active_sessions', $userId);
                $redis->zRem('login_queue', $userId);
```

- [ ] **Step 4: Pastikan tidak ada penghapusan token yang terlewat**

Run:
```bash
grep -rn 'del("user_login_token' src/app/
```
Expected: tiga baris — logout di `AuthController`, dan dua di `SuspendController`. **Setiap** baris itu harus punya `del("user_login_device` tepat di bawahnya. Periksa satu per satu:

```bash
grep -rn -A1 'del("user_login_token' src/app/
```

- [ ] **Step 5: Lint dan commit**

```bash
docker compose exec php php -l /var/www/html/app/Controllers/Auth/AuthController.php
docker compose exec php php -l /var/www/html/app/Controllers/Admin/SuspendController.php
git add src/app/Controllers/Auth/AuthController.php src/app/Controllers/Admin/SuspendController.php
git commit -m "fix(session): penanda perangkat ikut dihapus bersama token sesi"
```

---

## Task 4: Aplikasi mengirim device_id saat login

**Files:**
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt`
- Modify: `src/app/Views/bundle/login.php`

- [ ] **Step 1: Ekspor device_id ke WebView**

Di `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt`, tambahkan metode baru tepat setelah `getDeviceInfo()`:

```kotlin
    /**
     * Penanda perangkat untuk halaman login.
     *
     * Nilainya diambil dari DeviceIdentityStore — sumber yang sama dengan
     * heartbeat dan /api/kiosk/config. Mengambilnya dari tempat lain pernah
     * membuat dua titik penegakan memeriksa identitas yang berbeda, dan blokir
     * yang dipasang pengawas tidak pernah menggigit.
     */
    @JavascriptInterface
    fun getDeviceId(): String = DeviceIdentityStore.resolve(activity)
```

Tambahkan importnya di bagian atas berkas kalau belum ada:

```kotlin
import id.sch.cbt.kiosk.DeviceIdentityStore
```

- [ ] **Step 2: Sertakan device_id di POST login**

Di `src/app/Views/bundle/login.php`, ganti baris:

```javascript
                body: 'username=' + encodeURIComponent(u) + '&password=' + encodeURIComponent(p)
```

menjadi:

```javascript
                body: 'username=' + encodeURIComponent(u)
                    + '&password=' + encodeURIComponent(p)
                    + '&device_id=' + encodeURIComponent(deviceId())
```

Dan tambahkan fungsi pembantu tepat sebelum handler submit — cari baris `var u = document.getElementById('username').value.trim();` dan sisipkan fungsi ini di atas fungsi yang memuatnya:

```javascript
        /* Diambil dari jembatan native. Di luar aplikasi kiosk jembatannya
           tidak ada, dan string kosong itu benar: server memperlakukan login
           tanpa device_id sebagai tidak boleh merebut sesi perangkat lain. */
        function deviceId() {
            try {
                if (window.CommsBridge && typeof window.CommsBridge.getDeviceId === 'function') {
                    return window.CommsBridge.getDeviceId() || '';
                }
            } catch (e) { /* di luar kiosk */ }
            return '';
        }
```

- [ ] **Step 3: Bangun ulang bundle UI**

Wajib — tanpa ini perangkat menerima bundle lama dan `device_id` tidak pernah terkirim.

Run: `docker compose exec php php spark cbt:build-ui-bundle`

Expected: `Bundle version: <hash>` dan `SIZE BUDGET OK`.

**Jalankan sebagai root, bukan `--user 33:33`** — `public/ui-bundle/` milik root dan build akan gagal dengan `Permission denied`.

- [ ] **Step 4: Bangun APK dan jalankan tes Android**

Run: `cd cbt-kiosk-app && ./gradlew :app:assembleDebug :app:testDebugUnitTest --console=plain`
Expected: `BUILD SUCCESSFUL`

- [ ] **Step 5: Commit**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt src/app/Views/bundle/login.php
git commit -m "feat(session): aplikasi mengirim device_id saat login"
```

---

## Task 5: Verifikasi

**Files:** tidak ada perubahan kode.

- [ ] **Step 1: Seluruh tes**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit'`
Expected: 72 lulus (66 sebelumnya + 6 dari Task 1).

- [ ] **Step 2: Buktikan setiap cabang keputusan berperilaku benar**

Aturannya murni, jadi tidak perlu menyemai apa pun ke Redis — cukup panggil
langsung dengan keenam kombinasi masukan.

Buat berkas sementara `src/app/Commands/TmpTakeoverCheck.php`:

```php
<?php
namespace App\Commands;

use App\Libraries\SessionTakeover;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TmpTakeoverCheck extends BaseCommand
{
    protected $group = 'System';
    protected $name = 'tmp:takeover-check';
    protected $description = 'temporary';

    public function run(array $params)
    {
        $kasus = [
            'tanpa token'       => [null, null, 'perangkat-a'],
            'perangkat sama'    => ['token-uji', 'perangkat-a', 'perangkat-a'],
            'perangkat beda'    => ['token-uji', 'perangkat-a', 'perangkat-b'],
            'tanpa device_id'   => ['token-uji', 'perangkat-a', ''],
            'pendamping hilang' => ['token-uji', null, 'perangkat-a'],
            'BANNED'            => ['BANNED', null, 'perangkat-a'],
        ];
        foreach ($kasus as $label => $a) {
            CLI::write(sprintf('  %-20s => %s', $label, SessionTakeover::decide($a[0], $a[1], $a[2])));
        }
        return EXIT_SUCCESS;
    }
}
```

Run: `docker compose exec --user 33:33 php php spark tmp:takeover-check`

Expected:
```
  tanpa token          => fresh
  perangkat sama       => takeover
  perangkat beda       => busy
  tanpa device_id      => busy
  pendamping hilang    => busy
  BANNED               => clear_banned
```

Bersihkan: `rm -f src/app/Commands/TmpTakeoverCheck.php`

- [ ] **Step 3: Pastikan tidak ada berkas sementara tertinggal**

Run: `ls src/app/Commands/Tmp* 2>/dev/null; git status --short`
Expected: tidak ada berkas `Tmp*`.

- [ ] **Step 4: Verifikasi di perangkat nyata — dijalankan pengguna, bukan agen**

Berikan sebagai instruksi bernomor:

1. Pasang APK baru, buka aplikasi, login sebagai satu siswa, mulai ujian.
2. **Matikan paksa aplikasinya** dari daftar aplikasi terakhir — bukan logout.
3. Buka lagi aplikasinya dan login dengan akun yang sama. **Harus berhasil**, dan ujiannya lanjut dari tempat terakhir. Sebelum perbaikan ini, langkah inilah yang ditolak dengan *"Akun Anda sedang digunakan di perangkat lain"*.
4. Selagi sesi itu hidup, login dengan akun yang **sama** dari perangkat **lain** atau dari browser desktop. **Harus tetap ditolak** dengan pesan yang sama seperti dulu.

Langkah 4 sama pentingnya dengan langkah 3: kalau ia lolos, perlindungan multi-login sudah rusak dan perbaikan ini menciptakan lubang yang lebih besar daripada masalah yang diselesaikannya.

- [ ] **Step 5: Commit apa pun yang tersisa**

```bash
git status --short
```
Bila bersih, tidak ada yang perlu di-commit.

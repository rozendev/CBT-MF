# Device Ban EXAMBRO Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pengawas dapat memblokir satu perangkat dari menjalankan aplikasi ujian sama sekali, dan membukanya kembali lewat tombol Buka Kunci.

**Architecture:** Identitas perangkat diturunkan dari `ANDROID_ID` lalu di-hash sha256 sebelum meninggalkan perangkat, sehingga server hanya bisa membandingkan sama atau tidak. Daftar ban hidup di MariaDB sebagai sumber kebenaran dengan cache Redis per-perangkat ber-TTL 30 detik yang dibatalkan seketika saat menulis. Penegakan di dua titik: `/api/kiosk/config` supaya WebView tidak pernah dimuat, dan `kiosk-heartbeat.php` sebagai jaring untuk ban di tengah sesi dan APK lama.

**Tech Stack:** PHP 8.3 / CodeIgniter 4.7, MariaDB, Redis (phpredis), Alpine.js, Kotlin (Android minSdk 28), PHPUnit 10, JUnit 4.

**Spec:** `docs/superpowers/specs/2026-08-26-device-ban-kiosk-design.md`

---

## Catatan Lingkungan

Perintah dijalankan dari `/home/rozen/conquer/CBT-MF`. Container PHP bernama `tx_php` — konfirmasi dengan `docker compose ps`. Nama container berprefix sesuai instalasi, jadi lebih aman memakai nama service:

```bash
docker compose exec php php spark migrate
```

**Penting:** `spark cbt:build-ui-bundle` **tidak** diperlukan di rencana ini — tidak ada aset bundle yang berubah.

**Lint PHP:** `docker compose exec php php -l /var/www/html/<path relatif ke src>`
**Tes PHP:** `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite Resilience'`

---

## Struktur Berkas

**Baru**

| Berkas | Tanggung jawab |
|---|---|
| `src/app/Database/Migrations/2026-08-26-000001_CreateKioskBannedDevicesTable.php` | Skema tabel ban |
| `src/app/Models/KioskBannedDeviceModel.php` | Akses tabel, kueri ban aktif |
| `src/app/Libraries/DeviceBan.php` | Satu-satunya tempat yang tahu cara memeriksa/menulis ban dan membatalkan cache |
| `src/app/Controllers/Admin/KioskDeviceController.php` | Halaman daftar perangkat terkunci + unlock |
| `src/app/Views/admin/kiosk/devices.php` | Tampilan daftar + tombol Buka Kunci |
| `src/tests/Resilience/DeviceBanTest.php` | Tes pembantu murni `DeviceBan` |
| `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/DeviceIdentity.kt` | Derivasi identitas murni, bisa diuji tanpa Android |
| `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/DeviceIdentityTest.kt` | Tes derivasi |

**Diubah**

| Berkas | Perubahan |
|---|---|
| `src/public/kiosk-heartbeat.php` | Tolak 403 bila perangkat terblokir |
| `src/app/Controllers/Api/KioskController.php` | `config()` menerima `device_id`, balas field `blocked` |
| `src/app/Controllers/Admin/KioskLiveController.php` | Aksi `ban_device` |
| `src/app/Views/admin/kiosk/live.php` | Tombol Blokir Perangkat + lencana jumlah terkunci |
| `src/app/Config/Routes.php` | Rute `/admin/kiosk/devices` dan `/admin/kiosk/devices/unlock` |
| `cbt-kiosk-app/.../MainActivity.kt` | Pakai `DeviceIdentity`, kirim `device_id` ke config, layar terkunci |

---

## Task 1: Tabel dan Model

**Files:**
- Create: `src/app/Database/Migrations/2026-08-26-000001_CreateKioskBannedDevicesTable.php`
- Create: `src/app/Models/KioskBannedDeviceModel.php`

- [ ] **Step 1: Tulis migrasi**

`src/app/Database/Migrations/2026-08-26-000001_CreateKioskBannedDevicesTable.php`:

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateKioskBannedDevicesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            // sha256 heksadesimal = 64 karakter. UUID jalur cadangan = 36.
            'device_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'banned_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'banned_at' => [
                'type' => 'DATETIME',
            ],
            'unlocked_by' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'unlocked_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            // Konteks kejadian, bukan kunci. Ban tidak pernah menyempit ke
            // satu siswa atau satu ujian.
            'last_user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'last_test_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
        ]);

        $this->forge->addKey('id', true);

        // Ban aktif = unlocked_at IS NULL. MariaDB tidak punya indeks unik
        // parsial, jadi jaminan "paling banyak satu ban aktif per perangkat"
        // ditegakkan di DeviceBan::ban() di dalam transaksi. Indeksnya WAJIB
        // non-unik: satu perangkat boleh punya banyak baris riwayat.
        $this->forge->addKey(['device_id', 'unlocked_at'], false, false, 'idx_device_active');

        $this->forge->createTable('kiosk_banned_devices', false, [
            'ENGINE'          => 'InnoDB',
            'DEFAULT CHARSET' => 'utf8mb4',
            // Samakan dengan 19 migrasi lain di repo ini. Collation campur
            // memicu "Illegal mix of collations" saat device_id dibandingkan
            // dengan kolom di tabel lain.
            'COLLATE'         => 'utf8mb4_unicode_ci',
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('kiosk_banned_devices', true);
    }
}
```

- [ ] **Step 2: Tulis model**

`src/app/Models/KioskBannedDeviceModel.php`:

```php
<?php

namespace App\Models;

use CodeIgniter\Model;

class KioskBannedDeviceModel extends Model
{
    protected $table         = 'kiosk_banned_devices';
    protected $primaryKey    = 'id';
    protected $returnType    = 'object';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'device_id',
        'reason',
        'banned_by',
        'banned_at',
        'unlocked_by',
        'unlocked_at',
        'last_user_id',
        'last_test_id',
    ];

    /**
     * Ban yang sedang berlaku untuk satu perangkat, atau null.
     */
    public function activeFor(string $deviceId): ?object
    {
        return $this->where('device_id', $deviceId)
            ->where('unlocked_at', null)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Semua ban yang sedang berlaku, terbaru dulu, untuk halaman admin.
     */
    public function allActive(): array
    {
        return $this->where('unlocked_at', null)
            ->orderBy('banned_at', 'DESC')
            ->findAll();
    }

    public function countActive(): int
    {
        return $this->where('unlocked_at', null)->countAllResults();
    }
}
```

- [ ] **Step 3: Jalankan migrasi**

Run: `docker compose exec php php spark migrate`
Expected: baris `Running: 2026-08-26-000001_App\Database\Migrations\CreateKioskBannedDevicesTable` lalu `Migrations complete.`

- [ ] **Step 4: Verifikasi tabel benar-benar ada**

Run:
```bash
docker compose exec mariadb sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "DESCRIBE kiosk_banned_devices;"'
```
Expected: sembilan kolom — `id, device_id, reason, banned_by, banned_at, unlocked_by, unlocked_at, last_user_id, last_test_id`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Database/Migrations/2026-08-26-000001_CreateKioskBannedDevicesTable.php src/app/Models/KioskBannedDeviceModel.php
git commit -m "feat(device-ban): tabel dan model kiosk_banned_devices"
```

---

## Task 2: Pembantu murni `DeviceBan` (TDD)

Bagian yang bisa diuji tanpa Redis maupun DB. Suite `Resilience` berjalan tanpa framework, jadi hanya metode statis murni yang diuji di sini — pola yang sama dengan `ProctorActionTest` yang menguji `isValidAction()` pada kelas yang juga menyentuh database di tempat lain.

**Files:**
- Create: `src/app/Libraries/DeviceBan.php`
- Test: `src/tests/Resilience/DeviceBanTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

`src/tests/Resilience/DeviceBanTest.php`:

```php
<?php

namespace Tests\Resilience;

use App\Libraries\DeviceBan;
use PHPUnit\Framework\TestCase;

class DeviceBanTest extends TestCase
{
    public function testValidDeviceIdAccepted(): void
    {
        $this->assertTrue(DeviceBan::isValidDeviceId(str_repeat('a', 64)));
        $this->assertTrue(DeviceBan::isValidDeviceId('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertTrue(DeviceBan::isValidDeviceId('abc_123-XYZ'));
    }

    public function testInvalidDeviceIdRejected(): void
    {
        $this->assertFalse(DeviceBan::isValidDeviceId(''));
        $this->assertFalse(DeviceBan::isValidDeviceId(str_repeat('a', 65)), 'lebih dari 64');
        $this->assertFalse(DeviceBan::isValidDeviceId('spasi tidak boleh'));
        $this->assertFalse(DeviceBan::isValidDeviceId("a\nb"));
        $this->assertFalse(DeviceBan::isValidDeviceId('titik.koma;'));
    }

    public function testCacheKeyIsNamespacedAndStable(): void
    {
        $key = DeviceBan::cacheKey('abc123');

        $this->assertSame('kiosk_device_ban:abc123', $key);
        $this->assertSame($key, DeviceBan::cacheKey('abc123'));
    }

    public function testCacheHitDecidesDirectly(): void
    {
        $this->assertTrue(DeviceBan::decideFromCache('1'));
        $this->assertFalse(DeviceBan::decideFromCache('0'));
    }

    /**
     * Ini tes terpenting di berkas ini. Cache dingin HARUS berarti "belum
     * tahu, tanya database" dan tidak boleh berarti "tidak terblokir".
     * Kalau ini gagal-terbuka, `cbt.sh redis flush` akan membuka semua blokir
     * tanpa suara.
     */
    public function testColdOrGarbageCacheMeansAskTheDatabase(): void
    {
        $this->assertNull(DeviceBan::decideFromCache(null), 'cache dingin');
        $this->assertNull(DeviceBan::decideFromCache(''), 'nilai kosong');
        $this->assertNull(DeviceBan::decideFromCache('true'), 'nilai tak dikenal');
        $this->assertNull(DeviceBan::decideFromCache('2'), 'nilai tak dikenal');
    }

    public function testCacheTtlIsPositive(): void
    {
        $this->assertGreaterThan(0, DeviceBan::CACHE_TTL_SECONDS);
    }
}
```

- [ ] **Step 2: Daftarkan tidak perlu — suite Resilience sudah ada**

Suite `Resilience` sudah terdaftar di `src/phpunit.xml.dist`. Tidak ada perubahan konfigurasi.

- [ ] **Step 3: Jalankan tes untuk memastikan GAGAL**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite Resilience'`
Expected: FAIL — `Error: Class "App\Libraries\DeviceBan" not found`

- [ ] **Step 4: Tulis implementasi minimal (hanya bagian murni)**

`src/app/Libraries/DeviceBan.php`:

```php
<?php

namespace App\Libraries;

use App\Models\KioskBannedDeviceModel;

/**
 * Blokir satu perangkat dari menjalankan aplikasi ujian.
 *
 * Menyasar perangkat, BUKAN akun: siswa yang perangkatnya diblokir tetap bisa
 * melanjutkan di perangkat lain bila pengawas mengizinkan. Untuk menghukum
 * akun, yang dipakai tetap ProctorAction::lockAccount().
 *
 * Sumber kebenaran ada di MariaDB. Redis hanya cache per-perangkat, karena
 * `cbt.sh redis flush` adalah perintah yang memang ada dan memang dipakai —
 * kalau ban hanya hidup di Redis, perintah itu akan membuka semua blokir tanpa
 * suara. Kendali keamanan tidak boleh punya jalur gagal-terbuka yang sunyi.
 */
class DeviceBan
{
    /**
     * Umur cache. Pendek karena satu-satunya yang dibayar saat kedaluwarsa
     * adalah satu kueri primary-key. Ban dan unlock tidak menunggu TTL ini:
     * keduanya menghapus kunci cache-nya langsung.
     */
    public const CACHE_TTL_SECONDS = 30;

    /**
     * Batas yang sama dengan yang sudah dipakai kiosk-heartbeat.php
     * (`substr(..., 0, 64)`) dan KioskController (`[a-zA-Z0-9_-]`, `<= 64`).
     */
    public static function isValidDeviceId(string $raw): bool
    {
        return $raw !== ''
            && strlen($raw) <= 64
            // \A dan \z, bukan ^ dan $: dalam PCRE, $ ikut cocok TEPAT SEBELUM
            // newline di ujung, sehingga "abc\n" akan lolos sebagai id yang sah
            // dan menyelundupkan newline ke dalam kunci Redis.
            && preg_match('/\A[A-Za-z0-9_-]+\z/', $raw) === 1;
    }

    public static function cacheKey(string $deviceId): string
    {
        return 'kiosk_device_ban:' . $deviceId;
    }

    /**
     * Terjemahkan isi cache menjadi keputusan.
     *
     * null berarti "belum tahu, tanya database" — BUKAN "tidak terblokir".
     * Nilai yang tidak dikenal ikut diperlakukan sebagai belum tahu, supaya
     * cache yang rusak atau baru dikosongkan tidak pernah membuka blokir.
     */
    public static function decideFromCache(?string $cached): ?bool
    {
        if ($cached === '1') {
            return true;
        }
        if ($cached === '0') {
            return false;
        }

        return null;
    }
}
```

- [ ] **Step 5: Jalankan tes untuk memastikan LULUS**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite Resilience'`
Expected: PASS, dan jumlah tesnya bertambah 6 dari sebelumnya (3 → 9).

- [ ] **Step 6: Commit**

```bash
git add src/app/Libraries/DeviceBan.php src/tests/Resilience/DeviceBanTest.php
git commit -m "feat(device-ban): pembantu murni DeviceBan, cache dingin tidak gagal-terbuka"
```

---

## Task 3: `DeviceBan` bagian I/O

**Files:**
- Modify: `src/app/Libraries/DeviceBan.php`

- [ ] **Step 1: Tambahkan metode I/O**

Sisipkan sebelum kurung tutup kelas di `src/app/Libraries/DeviceBan.php`:

```php
    /**
     * Apakah perangkat ini sedang terblokir.
     *
     * Cache dulu, database kalau cache dingin. Redis mati bukan alasan untuk
     * melewatkan pemeriksaan: jatuh ke database, lebih lambat tapi tetap benar.
     */
    public static function isBanned(string $deviceId): bool
    {
        if (!self::isValidDeviceId($deviceId)) {
            // Tidak ada yang bisa dicocokkan. Diperlakukan seperti perangkat
            // yang tidak mengirim identitas sama sekali — lolos di sini, dan
            // tertangkap heartbeat begitu APK-nya diperbarui.
            return false;
        }

        $redis = null;
        try {
            $redis = RedisClient::getInstance();
        } catch (\Throwable $e) {
            $redis = null;
        }

        if ($redis !== null) {
            try {
                $cached = $redis->get(self::cacheKey($deviceId));
                $decision = self::decideFromCache($cached === false ? null : (string) $cached);
                if ($decision !== null) {
                    return $decision;
                }
            } catch (\Throwable $e) {
                log_message('warning', 'DeviceBan: gagal baca cache: ' . $e->getMessage());
            }
        }

        $banned = (new KioskBannedDeviceModel())->activeFor($deviceId) !== null;

        if ($redis !== null) {
            try {
                $redis->setex(self::cacheKey($deviceId), self::CACHE_TTL_SECONDS, $banned ? '1' : '0');
            } catch (\Throwable $e) {
                log_message('warning', 'DeviceBan: gagal isi cache: ' . $e->getMessage());
            }
        }

        return $banned;
    }

    /**
     * Buang cache satu perangkat supaya ban atau unlock berlaku seketika
     * alih-alih menunggu TTL habis.
     */
    public static function forget(string $deviceId): void
    {
        if (!self::isValidDeviceId($deviceId)) {
            return;
        }

        try {
            $redis = RedisClient::getInstance();
            if ($redis !== null) {
                $redis->del(self::cacheKey($deviceId));
            }
        } catch (\Throwable $e) {
            log_message('warning', 'DeviceBan: gagal buang cache: ' . $e->getMessage());
        }
    }

    /**
     * Blokir satu perangkat. Idempoten: perangkat yang sudah terblokir hanya
     * diperbarui alasannya, tidak menghasilkan baris aktif kedua.
     *
     * @return array{ok:bool, message:string}
     */
    public static function ban(
        string $deviceId,
        string $reason,
        int $actorId,
        ?int $lastUserId = null,
        ?int $lastTestId = null
    ): array {
        if (!self::isValidDeviceId($deviceId)) {
            return ['ok' => false, 'message' => 'ID perangkat tidak sah.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'Alasan wajib diisi.'];
        }
        $reason = mb_substr($reason, 0, 255);

        $model = new KioskBannedDeviceModel();
        $db    = \Config\Database::connect();

        $db->transStart();

        $existing = $model->activeFor($deviceId);
        if ($existing !== null) {
            $model->update($existing->id, ['reason' => $reason]);
        } else {
            $model->insert([
                'device_id'    => $deviceId,
                'reason'       => $reason,
                'banned_by'    => $actorId,
                'banned_at'    => date('Y-m-d H:i:s'),
                'last_user_id' => $lastUserId,
                'last_test_id' => $lastTestId,
            ]);
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return ['ok' => false, 'message' => 'Gagal menyimpan blokir perangkat.'];
        }

        self::forget($deviceId);

        try {
            (new \App\Models\ActivityLogModel())->log(
                'device_ban',
                $actorId,
                'device',
                null,
                'Memblokir perangkat ' . substr($deviceId, 0, 8) . '… — ' . $reason
            );
        } catch (\Throwable $e) {
            log_message('error', 'DeviceBan audit gagal: ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => 'Perangkat diblokir.'];
    }

    /**
     * Buka blokir. Idempoten: perangkat yang tidak terblokir bukan galat.
     *
     * @return array{ok:bool, message:string}
     */
    public static function unlock(string $deviceId, int $actorId): array
    {
        if (!self::isValidDeviceId($deviceId)) {
            return ['ok' => false, 'message' => 'ID perangkat tidak sah.'];
        }

        $model    = new KioskBannedDeviceModel();
        $existing = $model->activeFor($deviceId);

        if ($existing === null) {
            self::forget($deviceId);

            return ['ok' => true, 'message' => 'Perangkat memang tidak terblokir.'];
        }

        $model->update($existing->id, [
            'unlocked_by' => $actorId,
            'unlocked_at' => date('Y-m-d H:i:s'),
        ]);

        self::forget($deviceId);

        try {
            (new \App\Models\ActivityLogModel())->log(
                'device_unban',
                $actorId,
                'device',
                null,
                'Membuka blokir perangkat ' . substr($deviceId, 0, 8) . '…'
            );
        } catch (\Throwable $e) {
            log_message('error', 'DeviceBan audit gagal: ' . $e->getMessage());
        }

        return ['ok' => true, 'message' => 'Blokir perangkat dibuka.'];
    }
```

- [ ] **Step 2: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Libraries/DeviceBan.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Pastikan tes murni masih lulus**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit --testsuite Resilience'`
Expected: PASS, jumlah tes tetap 9.

- [ ] **Step 4: Verifikasi jalur I/O secara manual lewat spark**

Buat berkas sementara `src/app/Commands/TmpDeviceBanCheck.php`:

```php
<?php
namespace App\Commands;

use App\Libraries\DeviceBan;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TmpDeviceBanCheck extends BaseCommand
{
    protected $group = 'System';
    protected $name = 'tmp:device-ban-check';
    protected $description = 'temporary';

    public function run(array $params)
    {
        $id = str_repeat('a', 64);
        CLI::write('sebelum ban : ' . var_export(DeviceBan::isBanned($id), true) . '  (harap false)');
        CLI::write('ban         : ' . json_encode(DeviceBan::ban($id, 'uji coba', 1)));
        CLI::write('sesudah ban : ' . var_export(DeviceBan::isBanned($id), true) . '  (harap true)');
        CLI::write('ban ulang   : ' . json_encode(DeviceBan::ban($id, 'alasan baru', 1)));
        CLI::write('unlock      : ' . json_encode(DeviceBan::unlock($id, 1)));
        CLI::write('sesudah buka: ' . var_export(DeviceBan::isBanned($id), true) . '  (harap false)');
        CLI::write('tanpa alasan: ' . json_encode(DeviceBan::ban($id, '   ', 1)) . '  (harap ok:false)');
        return EXIT_SUCCESS;
    }
}
```

**Urutan langkah di berkas ini penting dan jangan diubah.** `isBanned()`
dipanggil SEBELUM `ban()` justru supaya cache terisi `'0'` lebih dulu. Kalau
`ban()` gagal membatalkan cache, nilai `'0'` itu akan bertahan dan baris
`sesudah ban` akan mencetak `false`. Jadi urutan inilah yang membuktikan
pembatalan cache berlaku seketika, bukan menunggu TTL 30 detik habis — spec §10
butir 3.

Run: `docker compose exec --user 33:33 php php spark tmp:device-ban-check`
Expected:
```
sebelum ban : false  (harap false)
ban         : {"ok":true,"message":"Perangkat diblokir."}
sesudah ban : true  (harap true)
ban ulang   : {"ok":true,"message":"Perangkat diblokir."}
unlock      : {"ok":true,"message":"Blokir perangkat dibuka."}
sesudah buka: false  (harap false)
tanpa alasan: {"ok":false,"message":"Alasan wajib diisi."}
```

- [ ] **Step 5: Pastikan tidak ada baris aktif ganda, lalu bersihkan**

Run:
```bash
docker compose exec mariadb sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -N -B -e "SELECT COUNT(*) FROM kiosk_banned_devices;"'
```
Expected: `1` — satu baris riwayat, bukan dua, karena ban kedua memperbarui alih-alih menyisipkan.

Bersihkan data uji dan berkas sementara:
```bash
docker compose exec mariadb sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "DELETE FROM kiosk_banned_devices WHERE device_id = REPEAT(\"a\", 64);"'
rm -f src/app/Commands/TmpDeviceBanCheck.php
```

- [ ] **Step 6: Commit**

```bash
git add src/app/Libraries/DeviceBan.php
git commit -m "feat(device-ban): pemeriksaan dan penulisan ban dengan cache yang dibatalkan saat menulis"
```

---

## Task 4: Penegakan di heartbeat

Titik ini bekerja tanpa APK baru, jadi fiturnya sudah menggigit begitu task ini selesai.

**Files:**
- Modify: `src/public/kiosk-heartbeat.php`

- [ ] **Step 1: Sisipkan pemeriksaan ban**

Di `src/public/kiosk-heartbeat.php`, tepat **setelah** blok yang membangun `$fields` dan **sebelum** `$isNew = $redis->hSetNx(...)`, sisipkan:

```php
    // Perangkat terblokir: jangan tulis kiosk_live sama sekali. Selain
    // menjawab 403 supaya aplikasi menampilkan layar terkunci, berhentinya
    // tulisan membuat heartbeat menjadi basi, sehingga KioskPresence menolak
    // tulisan jawaban setelah STALE_SECONDS. Lapis itu datang gratis.
    //
    // Bebas framework dengan sengaja, jadi tabelnya dibaca lewat PDO yang
    // sudah dipakai di berkas ini — bukan lewat App\Libraries\DeviceBan.
    $deviceId = $fields['device_id'];
    // \A dan \z, bukan ^ dan $ — lihat alasannya di DeviceBan::isValidDeviceId().
    if ($deviceId !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $deviceId) === 1) {
        $banCacheKey = 'kiosk_device_ban:' . $deviceId;
        $cached      = $redis->get($banCacheKey);

        $isBanned = null;
        if ($cached === '1') {
            $isBanned = true;
        } elseif ($cached === '0') {
            $isBanned = false;
        }

        if ($isBanned === null) {
            // Cache dingin atau rusak: tanya database. TIDAK boleh dianggap
            // "tidak terblokir" — itu jalur gagal-terbuka yang sunyi.
            try {
                $pdoBan = new PDO(
                    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
                    . ';port=' . (getenv('DB_PORT') ?: '3306')
                    . ';dbname=' . (getenv('DB_DATABASE') ?: 'cbt')
                    . ';charset=utf8mb4',
                    getenv('DB_USERNAME') ?: 'root',
                    getenv('DB_PASSWORD') ?: '',
                    [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $stmt = $pdoBan->prepare(
                    'SELECT reason FROM kiosk_banned_devices
                     WHERE device_id = ? AND unlocked_at IS NULL
                     ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([$deviceId]);
                $row      = $stmt->fetch(PDO::FETCH_ASSOC);
                $isBanned = $row !== false;
                $banReason = $isBanned ? (string) $row['reason'] : '';

                $redis->setex($banCacheKey, 30, $isBanned ? '1' : '0');
            } catch (Throwable $e) {
                // Database tidak terjangkau: seluruh situs sudah masuk
                // maintenance lewat deps:probe. Jangan menahan heartbeat.
                error_log('[kiosk-heartbeat] cek ban gagal: ' . $e->getMessage());
                $isBanned = false;
                $banReason = '';
            }
        } else {
            $banReason = '';
        }

        if ($isBanned) {
            http_response_code(403);
            echo json_encode(['status' => 'device_banned', 'reason' => $banReason]);
            exit;
        }
    }
```

- [ ] **Step 2: Lint**

Run: `docker compose exec php php -l /var/www/html/public/kiosk-heartbeat.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verifikasi perangkat bersih masih lolos**

Perlu token `ws_student_token` yang sah, yang hanya ada saat ada ujian berjalan. Tanpa itu endpoint menjawab 401 sebelum sampai ke pemeriksaan ban — itu sendiri sudah membuktikan pemeriksaan tidak dijalankan terlalu awal:

```bash
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php \
  -H 'Content-Type: application/json' \
  -d '{"token":"tidak-sah","device_id":"aaaa"}' -w '\nHTTP %{http_code}\n'
```
Expected: `{"status":"invalid_token"}` dan `HTTP 401`.

- [ ] **Step 4: Commit**

```bash
git add src/public/kiosk-heartbeat.php
git commit -m "feat(device-ban): heartbeat menolak perangkat terblokir dengan 403"
```

---

## Task 5: Aksi Blokir Perangkat di monitoring

**Files:**
- Modify: `src/app/Controllers/Admin/KioskLiveController.php`

- [ ] **Step 1: Terima aksi `ban_device`**

Di `src/app/Controllers/Admin/KioskLiveController.php`, tambahkan import di bagian atas berkas:

```php
use App\Libraries\DeviceBan;
```

Ganti blok validasi aksi. Yang ada sekarang:

```php
        if (!ProctorAction::isValidAction($action)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error', 'message' => 'Aksi tidak dikenal.',
            ]);
        }
```

menjadi:

```php
        // ban_device bukan ProctorAction: ia menyasar perangkat, bukan akun,
        // jadi sengaja tidak masuk ke ProctorAction::ACTIONS.
        if ($action !== 'ban_device' && !ProctorAction::isValidAction($action)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error', 'message' => 'Aksi tidak dikenal.',
            ]);
        }

        if ($action === 'ban_device') {
            $deviceId = (string) ($body['device_id'] ?? '');
            $actorId  = (int) session('user_id');

            if (!DeviceBan::isValidDeviceId($deviceId)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status'  => 'error',
                    'message' => 'Perangkat ini belum melaporkan ID — aplikasinya perlu diperbarui.',
                ]);
            }

            $banResult = DeviceBan::ban($deviceId, (string) ($body['reason'] ?? ''), $actorId, $userId, $testId);
            if (!$banResult['ok']) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error', 'message' => $banResult['message'],
                ]);
            }

            // Blokir perangkat SEKALIGUS mengeluarkan sesi berjalan: pengawas
            // menekan tombol ini justru untuk menghentikan yang sedang terjadi.
            // Akun TIDAK dikunci — "perangkat ini bermasalah" bukan "siswa ini
            // dihukum", dan siswanya masih bisa dipindah ke perangkat lain.
            $ejectResult = (new ProctorAction())->eject($testId, $userId, $actorId, 'Perangkat diblokir pengawas');

            return $this->response->setJSON([
                'status'  => 'success',
                'message' => $banResult['message'] . ' ' . $ejectResult['message'],
            ]);
        }
```

- [ ] **Step 2: Perbarui komentar kontrak di atas `action()`**

Ganti:

```php
     * POST { test_id, user_id, action: eject|lock|eject_lock, reason? }
```

menjadi:

```php
     * POST { test_id, user_id, action: eject|lock|eject_lock|ban_device, reason?, device_id? }
```

- [ ] **Step 3: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Controllers/Admin/KioskLiveController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Verifikasi aksi tak dikenal masih ditolak**

Run:
```bash
curl -s -X POST http://localhost:8080/admin/kiosk/live/action \
  -H 'Content-Type: application/json' -d '{"test_id":1,"user_id":1,"action":"ngawur"}' \
  -o /dev/null -w 'HTTP %{http_code}\n'
```
Expected: `HTTP 302` atau `HTTP 401` — ditolak filter autentikasi sebelum sampai ke controller. Itu yang diharapkan; verifikasi perilaku sebenarnya dilakukan lewat antarmuka di Task 7.

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Admin/KioskLiveController.php
git commit -m "feat(device-ban): aksi ban_device yang sekaligus mengeluarkan sesi"
```

---

## Task 6: Halaman perangkat terkunci dan tombol Buka Kunci

**Files:**
- Create: `src/app/Controllers/Admin/KioskDeviceController.php`
- Create: `src/app/Views/admin/kiosk/devices.php`
- Modify: `src/app/Config/Routes.php`

- [ ] **Step 1: Tulis controller**

`src/app/Controllers/Admin/KioskDeviceController.php`:

```php
<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DeviceBan;
use App\Models\KioskBannedDeviceModel;

class KioskDeviceController extends BaseController
{
    public function index()
    {
        $model   = new KioskBannedDeviceModel();
        $devices = $model->allActive();

        // Nama pengunci dilengkapi supaya pengawas tahu harus bertanya kepada
        // siapa, bukan sekadar melihat angka id.
        $db = \Config\Database::connect();
        foreach ($devices as $device) {
            $device->banned_by_name = $this->userLabel($db, (int) $device->banned_by);
            $device->last_user_name = $device->last_user_id !== null
                ? $this->userLabel($db, (int) $device->last_user_id)
                : '—';
        }

        return view('admin/kiosk/devices', [
            'title'   => 'Perangkat Terkunci',
            'devices' => $devices,
        ]);
    }

    public function unlock()
    {
        $body = $this->request->getJSON(true);
        if (!is_array($body)) {
            $body = $this->request->getPost();
        }

        $deviceId = (string) ($body['device_id'] ?? '');
        $result   = DeviceBan::unlock($deviceId, (int) session('user_id'));

        return $this->response
            ->setStatusCode($result['ok'] ? 200 : 400)
            ->setJSON([
                'status'  => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
            ]);
    }

    private function userLabel($db, int $userId): string
    {
        $row = $db->table('users')
            ->select('username, firstname, lastname')
            ->where('id', $userId)
            ->get()
            ->getRow();

        if ($row === null) {
            return 'user #' . $userId;
        }

        return trim($row->firstname . ' ' . $row->lastname) . ' (' . $row->username . ')';
    }
}
```

- [ ] **Step 2: Tambahkan rute**

Di `src/app/Config/Routes.php`, tepat setelah baris `$routes->post('kiosk/live/action', 'Admin\KioskLiveController::action');`, tambahkan:

```php
        // Perangkat yang diblokir dari menjalankan aplikasi ujian
        $routes->get('kiosk/devices', 'Admin\KioskDeviceController::index');
        $routes->post('kiosk/devices/unlock', 'Admin\KioskDeviceController::unlock');
```

- [ ] **Step 3: Tulis view**

`src/app/Views/admin/kiosk/devices.php`:

```php
<?= $this->extend('layouts/admin') ?>
<?= $this->section('page_title') ?>Perangkat Terkunci<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid" x-data="kioskDevices()">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Perangkat Terkunci</h5>
            <p class="text-muted small mb-0">
                Perangkat di daftar ini tidak dapat menjalankan aplikasi ujian sama sekali.
                Akun siswanya tidak ikut terkunci — mereka tetap bisa mengerjakan di perangkat lain.
            </p>
        </div>
        <a href="<?= base_url('/admin/kiosk/live') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Monitoring
        </a>
    </div>

    <div class="alert" :class="actionOk ? 'alert-success' : 'alert-danger'"
         x-show="actionMessage" x-text="actionMessage" style="display:none"></div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID Perangkat</th>
                        <th>Alasan</th>
                        <th>Dikunci oleh</th>
                        <th>Waktu</th>
                        <th>Pemakai terakhir</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td><code class="small"><?= esc(substr($device->device_id, 0, 12)) ?>…</code></td>
                            <td><?= esc($device->reason) ?></td>
                            <td class="small"><?= esc($device->banned_by_name) ?></td>
                            <td class="small text-muted"><?= esc($device->banned_at) ?></td>
                            <td class="small"><?= esc($device->last_user_name) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-success"
                                        :disabled="busyDevice === '<?= esc($device->device_id, 'attr') ?>'"
                                        @click="unlock('<?= esc($device->device_id, 'attr') ?>')">
                                    <i class="bi bi-unlock me-1"></i>Buka Kunci
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($devices === []): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-shield-check fs-3 d-block mb-2"></i>
                                Tidak ada perangkat yang terkunci.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function kioskDevices() {
    return {
        busyDevice: null,
        actionMessage: '',
        actionOk: true,
        unlock(deviceId) {
            if (!window.confirm('Buka kunci perangkat ini?\n\nPerangkat akan langsung bisa menjalankan aplikasi ujian lagi.')) return;

            this.busyDevice = deviceId;
            fetch('<?= base_url('/admin/kiosk/devices/unlock') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                },
                body: JSON.stringify({ device_id: deviceId })
            })
                .then(r => r.json())
                .then(d => {
                    this.actionOk = d.status === 'success';
                    this.actionMessage = d.message || (this.actionOk ? 'Berhasil.' : 'Gagal.');
                    if (this.actionOk) setTimeout(() => window.location.reload(), 800);
                })
                .catch(e => {
                    console.error('unlock failed:', e);
                    this.actionOk = false;
                    this.actionMessage = 'Gagal terkirim. Periksa koneksi lalu coba lagi.';
                })
                .finally(() => { this.busyDevice = null; });
        }
    }
}
</script>
<?= $this->endSection() ?>
```

- [ ] **Step 4: Lint ketiganya**

Run:
```bash
docker compose exec php php -l /var/www/html/app/Controllers/Admin/KioskDeviceController.php
docker compose exec php php -l /var/www/html/app/Views/admin/kiosk/devices.php
docker compose exec php php -l /var/www/html/app/Config/Routes.php
```
Expected: tiga baris `No syntax errors detected`

- [ ] **Step 5: Verifikasi rute terdaftar**

Run: `docker compose exec php php spark routes | grep -i "kiosk/devices"`
Expected: dua baris — `admin/kiosk/devices` (GET) dan `admin/kiosk/devices/unlock` (POST).

- [ ] **Step 6: Commit**

```bash
git add src/app/Controllers/Admin/KioskDeviceController.php src/app/Views/admin/kiosk/devices.php src/app/Config/Routes.php
git commit -m "feat(device-ban): halaman perangkat terkunci dengan tombol buka kunci"
```

---

## Task 7: Tombol dan lencana di monitoring

**Files:**
- Modify: `src/app/Views/admin/kiosk/live.php`
- Modify: `src/app/Controllers/Admin/KioskLiveController.php`

- [ ] **Step 1: Kirim jumlah perangkat terkunci ke view**

Di `src/app/Controllers/Admin/KioskLiveController.php`, di dalam `index()`, ganti blok `return view(...)`:

```php
        return view('admin/kiosk/live', [
            'title'       => 'Monitoring Kiosk Real-Time',
            'activeTests' => $activeTests,
        ]);
```

menjadi:

```php
        return view('admin/kiosk/live', [
            'title'        => 'Monitoring Kiosk Real-Time',
            'activeTests'  => $activeTests,
            // Perangkat sekolah dipakai bergilir. Ban yang terlupakan akan
            // mengunci siswa berikutnya, jadi jumlahnya harus selalu terlihat.
            'bannedCount'  => (new \App\Models\KioskBannedDeviceModel())->countActive(),
        ]);
```

- [ ] **Step 2: Tambahkan lencana di view**

Di `src/app/Views/admin/kiosk/live.php`, tepat setelah baris `<?= $this->section('content') ?>`, sisipkan:

```php
<?php if (($bannedCount ?? 0) > 0): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-slash-circle me-2"></i>
            <strong><?= (int) $bannedCount ?></strong> perangkat sedang terkunci dan tidak bisa menjalankan aplikasi ujian.
        </span>
        <a href="<?= base_url('/admin/kiosk/devices') ?>" class="btn btn-sm btn-outline-dark">Kelola</a>
    </div>
<?php endif; ?>
```

- [ ] **Step 3: Tambahkan butir menu Blokir Perangkat**

Di berkas yang sama, ganti blok menu aksi. Yang ada sekarang:

```php
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button class="dropdown-item text-danger fw-semibold" type="button" @click="runAction(s, 'eject_lock')">
                                                <i class="bi bi-shield-exclamation me-2"></i>Keluarkan &amp; Kunci</button></li>
```

menjadi:

```php
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button class="dropdown-item text-danger fw-semibold" type="button" @click="runAction(s, 'eject_lock')">
                                                <i class="bi bi-shield-exclamation me-2"></i>Keluarkan &amp; Kunci</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button class="dropdown-item text-danger fw-semibold" type="button"
                                                        :disabled="!s.device_id"
                                                        :title="s.device_id ? '' : 'Perangkat ini belum melaporkan ID — aplikasinya perlu diperbarui'"
                                                        @click="runAction(s, 'ban_device')">
                                                <i class="bi bi-slash-circle me-2"></i>Blokir Perangkat</button></li>
```

- [ ] **Step 4: Tambahkan label dan alasan wajib di `runAction`**

Di blok `<script>` berkas yang sama, ganti `actionLabel`:

```php
        actionLabel(action) {
            if (action === 'eject') return 'mengeluarkan siswa ini dari ujian';
            if (action === 'lock') return 'mengunci akun siswa ini';
            return 'mengeluarkan siswa ini dari ujian DAN mengunci akunnya';
        },
```

menjadi:

```php
        actionLabel(action) {
            if (action === 'eject') return 'mengeluarkan siswa ini dari ujian';
            if (action === 'lock') return 'mengunci akun siswa ini';
            if (action === 'ban_device') return 'MEMBLOKIR PERANGKAT ini dari menjalankan aplikasi ujian, dan mengeluarkan sesinya sekarang';
            return 'mengeluarkan siswa ini dari ujian DAN mengunci akunnya';
        },
```

Lalu ganti awal `runAction`:

```php
        runAction(student, action) {
            const nama = (student.firstname + ' ' + student.lastname).trim() + ' (' + student.username + ')';
            if (!window.confirm('Anda akan ' + this.actionLabel(action) + ':\n\n' + nama + '\n\nLanjutkan?')) return;

            this.busyUser = student.user_id;
```

menjadi:

```php
        runAction(student, action) {
            const nama = (student.firstname + ' ' + student.lastname).trim() + ' (' + student.username + ')';
            if (!window.confirm('Anda akan ' + this.actionLabel(action) + ':\n\n' + nama + '\n\nLanjutkan?')) return;

            // Alasan wajib untuk blokir perangkat: ini keputusan yang akan
            // dibaca orang lain saat memutuskan membukanya kembali.
            let reason = '';
            if (action === 'ban_device') {
                reason = (window.prompt('Alasan memblokir perangkat ini (wajib):') || '').trim();
                if (!reason) {
                    this.actionOk = false;
                    this.actionMessage = 'Blokir dibatalkan — alasan wajib diisi.';
                    return;
                }
            }

            this.busyUser = student.user_id;
```

Terakhir, tambahkan `device_id` dan `reason` ke badan permintaan. Ganti:

```php
                body: JSON.stringify({
                    test_id: this.selectedTest,
                    user_id: student.user_id,
                    action: action
                })
```

menjadi:

```php
                body: JSON.stringify({
                    test_id: this.selectedTest,
                    user_id: student.user_id,
                    action: action,
                    device_id: student.device_id || '',
                    reason: reason
                })
```

- [ ] **Step 5: Lint keduanya**

Run:
```bash
docker compose exec php php -l /var/www/html/app/Views/admin/kiosk/live.php
docker compose exec php php -l /var/www/html/app/Controllers/Admin/KioskLiveController.php
```
Expected: dua baris `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add src/app/Views/admin/kiosk/live.php src/app/Controllers/Admin/KioskLiveController.php
git commit -m "feat(device-ban): tombol blokir perangkat dan lencana jumlah terkunci"
```

---

## Task 8: Penegakan di `/api/kiosk/config`

Titik ini yang menjawab permintaan intinya — WebView tidak pernah dimuat. Baru menggigit setelah Task 10.

**Files:**
- Modify: `src/app/Controllers/Api/KioskController.php`

- [ ] **Step 1: Tambahkan field `blocked`**

Di `src/app/Controllers/Api/KioskController.php`, tambahkan import di bagian atas:

```php
use App\Libraries\DeviceBan;
use App\Models\KioskBannedDeviceModel;
```

Lalu di dalam `config()`, ganti blok `return $this->response->setJSON([...])` yang ada sekarang menjadi:

```php
        $payload = [
            'school_name'     => $settingModel->getValue('app_name', 'CBT-MF Kiosk System'),
            'exam_url'        => base_url('student/dashboard'),
            'min_app_version' => $settingModel->getValue('kiosk_min_app_version', '1.0.0'),
            'features'        => [
                'siren_enabled'             => (bool) $settingModel->getValue('kiosk_siren_enabled', true),
                'siren_max_volume'          => (bool) $settingModel->getValue('kiosk_siren_max_volume', true),
                'enforce_home_launcher'     => (bool) $settingModel->getValue('kiosk_enforce_home_launcher', true),
                'block_clipboard'          => (bool) $settingModel->getValue('kiosk_block_clipboard', true),
                'root_detection_strictness' => $settingModel->getValue('kiosk_root_strictness', 'warning'),
                'overlay_guard_enabled'     => (bool) $settingModel->getValue('kiosk_overlay_guard_enabled', true),
            ],
            'ui_bundle'       => $bundleInfo,
        ];

        // Perangkat terblokir tetap dijawab 200 dengan konfigurasi lengkap,
        // bukan 4xx. Dua alasan: layar terkunci masih bisa menampilkan nama
        // sekolah alih-alih layar kosong, dan status galat mengundang aplikasi
        // memperlakukan ini sebagai gangguan jaringan lalu mencoba ulang —
        // padahal ini keputusan final, bukan kegagalan.
        //
        // APK lama tidak mengirim device_id sama sekali: mereka lolos di sini
        // dan tertangkap di kiosk-heartbeat.php beberapa detik kemudian.
        $deviceId = (string) ($this->request->getGet('device_id') ?? '');
        if (DeviceBan::isValidDeviceId($deviceId) && DeviceBan::isBanned($deviceId)) {
            $ban = (new KioskBannedDeviceModel())->activeFor($deviceId);
            $payload['blocked'] = [
                'reason' => $ban !== null ? $ban->reason : '',
                'since'  => $ban !== null ? $ban->banned_at : '',
            ];
        }

        return $this->response->setJSON($payload);
```

- [ ] **Step 2: Lint**

Run: `docker compose exec php php -l /var/www/html/app/Controllers/Api/KioskController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verifikasi perangkat bersih tidak mendapat `blocked`**

Run:
```bash
curl -s "http://localhost:8080/api/kiosk/config?device_id=$(printf 'b%.0s' {1..64})" | python3 -m json.tool | grep -c blocked
```
Expected: `0`

- [ ] **Step 4: Verifikasi perangkat terblokir mendapat `blocked` dan tetap 200**

Blokir satu perangkat uji langsung lewat SQL, panggil config, lalu bersihkan:

```bash
docker compose exec mariadb sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "
INSERT INTO kiosk_banned_devices (device_id, reason, banned_by, banned_at)
VALUES (REPEAT(\"c\", 64), \"uji coba\", 1, NOW());"'

curl -s -o /tmp/cfg.json -w 'HTTP %{http_code}\n' "http://localhost:8080/api/kiosk/config?device_id=$(printf 'c%.0s' {1..64})"
python3 -m json.tool < /tmp/cfg.json | grep -A 3 blocked

docker compose exec mariadb sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "
DELETE FROM kiosk_banned_devices WHERE device_id = REPEAT(\"c\", 64);"'
docker compose exec php php -r '$r=new Redis();$r->connect(getenv("REDIS_HOST")?:"redis",6379,2);$p=getenv("REDIS_PASSWORD");if($p)$r->auth($p);$r->del("kiosk_device_ban:".str_repeat("c",64));echo "cache dibuang\n";'
```
Expected: `HTTP 200`, lalu `"blocked": {` dengan `"reason": "uji coba"`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Api/KioskController.php
git commit -m "feat(device-ban): config melaporkan blocked untuk perangkat terkunci"
```

---

## Task 9: Derivasi identitas perangkat (Kotlin, TDD)

Dipisahkan dari `MainActivity` supaya bisa diuji dengan JUnit biasa tanpa framework Android — `Settings.Secure.getString()` butuh `ContentResolver`, tetapi hashing dan validasinya tidak.

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/DeviceIdentity.kt`
- Test: `cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/DeviceIdentityTest.kt`

- [ ] **Step 1: Tulis tes yang gagal**

`cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/DeviceIdentityTest.kt`:

```kotlin
package id.sch.cbt.kiosk

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DeviceIdentityTest {

    @Test
    fun `android id yang sah diterima`() {
        assertTrue(DeviceIdentity.isUsableAndroidId("a1b2c3d4e5f60718"))
        assertTrue(DeviceIdentity.isUsableAndroidId("0123456789ABCDEF"))
    }

    @Test
    fun `android id kosong atau null ditolak`() {
        assertFalse(DeviceIdentity.isUsableAndroidId(null))
        assertFalse(DeviceIdentity.isUsableAndroidId(""))
        assertFalse(DeviceIdentity.isUsableAndroidId("   "))
    }

    @Test
    fun `nilai kembar terkenal ditolak`() {
        // Sejumlah perangkat lama mengembalikan nilai yang sama persis ini,
        // sehingga memakainya akan menyamakan perangkat yang berbeda.
        assertFalse(DeviceIdentity.isUsableAndroidId("9774d56d682e549c"))
        assertFalse(DeviceIdentity.isUsableAndroidId("9774D56D682E549C"))
    }

    @Test
    fun `panjang atau format salah ditolak`() {
        assertFalse(DeviceIdentity.isUsableAndroidId("abc"))
        assertFalse(DeviceIdentity.isUsableAndroidId("a1b2c3d4e5f6071"), "15 karakter")
        assertFalse(DeviceIdentity.isUsableAndroidId("a1b2c3d4e5f607189"), "17 karakter")
        assertFalse(DeviceIdentity.isUsableAndroidId("z1b2c3d4e5f60718"), "bukan heksadesimal")
    }

    @Test
    fun `derivasi menghasilkan 64 heksadesimal huruf kecil`() {
        val id = DeviceIdentity.derive("a1b2c3d4e5f60718")

        assertEquals(64, id.length)
        assertTrue(id.matches(Regex("^[0-9a-f]{64}$")))
    }

    @Test
    fun `derivasi stabil untuk masukan yang sama`() {
        assertEquals(
            DeviceIdentity.derive("a1b2c3d4e5f60718"),
            DeviceIdentity.derive("a1b2c3d4e5f60718")
        )
    }

    @Test
    fun `perangkat berbeda menghasilkan penanda berbeda`() {
        assertNotEquals(
            DeviceIdentity.derive("a1b2c3d4e5f60718"),
            DeviceIdentity.derive("00112233445566aa")
        )
    }

    @Test
    fun `penanda tidak sama dengan hash polos nilai aslinya`() {
        // Prefiks namespace mengunci nilai ke aplikasi ini, sehingga penanda
        // yang sama tidak bisa dihasilkan pihak lain dari ANDROID_ID yang sama.
        val polos = DeviceIdentity.sha256Hex("a1b2c3d4e5f60718")

        assertNotEquals(polos, DeviceIdentity.derive("a1b2c3d4e5f60718"))
    }
}
```

- [ ] **Step 2: Jalankan tes untuk memastikan GAGAL**

Run: `cd cbt-kiosk-app && ./gradlew :app:testDebugUnitTest --tests 'id.sch.cbt.kiosk.DeviceIdentityTest'`
Expected: FAIL — `Unresolved reference: DeviceIdentity`

- [ ] **Step 3: Tulis implementasi**

`cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/DeviceIdentity.kt`:

```kotlin
package id.sch.cbt.kiosk

import java.security.MessageDigest

/**
 * Penanda perangkat untuk keperluan blokir.
 *
 * Diturunkan dari ANDROID_ID, yang sejak Android 8 disekat per kunci tanda
 * tangan aplikasi — nilai yang sama TIDAK terlihat oleh aplikasi lain mana pun,
 * dan minSdk proyek ini 28 sehingga penyekatan itu berlaku di seluruh perangkat
 * yang didukung. Berbeda dari UUID per-pemasangan, nilai ini bertahan melewati
 * hapus data aplikasi dan pasang ulang.
 *
 * Yang meninggalkan perangkat adalah hash-nya, bukan nilainya. Batas "hanya
 * penanda, tidak lebih" jadi sifat konstruksi alih-alih janji: server hanya bisa
 * membandingkan sama atau tidak sama, nilainya tidak bisa dibalik menjadi
 * identitas perangkat, dan bocornya basis data tidak membocorkan identifier apa
 * pun.
 *
 * Batasnya jujur: perangkat yang di-root masih bisa mengubah ANDROID_ID, dan
 * factory reset mengembalikannya. Yang dibeli adalah lompatan friction dari
 * "hapus data aplikasi" menjadi "root atau factory reset" — bukan tembok.
 *
 * Sengaja terpisah dari MainActivity supaya bisa diuji tanpa framework Android.
 */
object DeviceIdentity {

    /**
     * Sejumlah perangkat lama mengembalikan nilai ini secara identik, sehingga
     * memakainya akan menyamakan perangkat-perangkat yang sebenarnya berbeda.
     */
    private const val KNOWN_DUPLICATE = "9774d56d682e549c"

    private const val NAMESPACE = "cbt-mf|"

    private val HEX16 = Regex("^[0-9a-fA-F]{16}$")

    fun isUsableAndroidId(raw: String?): Boolean {
        val value = raw?.trim() ?: return false

        return value.isNotEmpty()
            && HEX16.matches(value)
            && !value.equals(KNOWN_DUPLICATE, ignoreCase = true)
    }

    /**
     * Penanda yang dikirim ke server: 64 heksadesimal huruf kecil.
     */
    fun derive(androidId: String): String = sha256Hex(NAMESPACE + androidId.trim())

    fun sha256Hex(input: String): String {
        val bytes = MessageDigest.getInstance("SHA-256").digest(input.toByteArray(Charsets.UTF_8))

        return bytes.joinToString("") { "%02x".format(it) }
    }
}
```

- [ ] **Step 4: Jalankan tes untuk memastikan LULUS**

Run: `cd cbt-kiosk-app && ./gradlew :app:testDebugUnitTest --tests 'id.sch.cbt.kiosk.DeviceIdentityTest'`
Expected: `BUILD SUCCESSFUL`, 8 tes lulus.

- [ ] **Step 5: Commit**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/DeviceIdentity.kt cbt-kiosk-app/app/src/test/java/id/sch/cbt/kiosk/DeviceIdentityTest.kt
git commit -m "feat(device-ban): derivasi penanda perangkat dari ANDROID_ID yang di-hash"
```

---

## Task 10: Wiring aplikasi Android

**Files:**
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`
- Modify: `cbt-kiosk-app/app/src/main/res/values/strings.xml`

- [ ] **Step 1: Pakai `DeviceIdentity` di `getOrCreateDeviceId()`**

Ganti seluruh fungsi (sekarang di sekitar baris 340):

```kotlin
    private fun getOrCreateDeviceId(): String {
        val existing = prefs.getString("kiosk_device_id", "")
        if (!existing.isNullOrBlank()) return existing
        val newId = java.util.UUID.randomUUID().toString()
        prefs.edit().putString("kiosk_device_id", newId).apply()
        return newId
    }
```

menjadi:

```kotlin
    /**
     * Penanda perangkat. Diturunkan dari ANDROID_ID supaya bertahan melewati
     * hapus data aplikasi dan pasang ulang — UUID per-pemasangan yang dulu
     * dipakai lenyap begitu data dihapus, friction yang terlalu murah untuk
     * aplikasi yang memang biasa dipasang ulang.
     *
     * Hasilnya di-cache di prefs supaya tidak dihitung ulang tiap heartbeat.
     * Cache dibedakan per skema: perangkat yang sudah menyimpan UUID lama harus
     * naik ke penanda baru, bukan terus memakai yang lama.
     */
    private fun getOrCreateDeviceId(): String {
        val cached = prefs.getString("kiosk_device_id_v2", "")
        if (!cached.isNullOrBlank()) return cached

        val androidId = try {
            android.provider.Settings.Secure.getString(
                contentResolver,
                android.provider.Settings.Secure.ANDROID_ID
            )
        } catch (e: Throwable) {
            Log.w("MainActivity", "Gagal membaca ANDROID_ID", e)
            null
        }

        val id = if (DeviceIdentity.isUsableAndroidId(androidId)) {
            DeviceIdentity.derive(androidId!!)
        } else {
            // Jalur cadangan: blokir tetap berfungsi untuk perangkat ini, hanya
            // kembali bisa dilepas dengan menghapus data aplikasi.
            Log.w("MainActivity", "ANDROID_ID tidak dapat dipakai, memakai UUID per-pemasangan")
            val legacy = prefs.getString("kiosk_device_id", "")
            if (!legacy.isNullOrBlank()) legacy else java.util.UUID.randomUUID().toString()
        }

        prefs.edit().putString("kiosk_device_id_v2", id).apply()
        return id
    }
```

- [ ] **Step 2: Kirim `device_id` saat mengambil config**

Di `fetchServerKioskConfig()`, ganti:

```kotlin
                val configUrl = "$baseUrl/api/kiosk/config"
```

menjadi:

```kotlin
                val configUrl = "$baseUrl/api/kiosk/config?device_id=" + Uri.encode(getOrCreateDeviceId())
```

- [ ] **Step 3: Hentikan pemuatan WebView bila terblokir**

Di `applyKioskConfig()`, tepat setelah baris `val json = org.json.JSONObject(configJson)`, sisipkan:

```kotlin
            // Perangkat terblokir: berhenti di sini. WebView tidak pernah
            // dijalankan, jadi halaman ujian benar-benar tidak termuat.
            val blocked = json.optJSONObject("blocked")
            if (blocked != null) {
                val reason = blocked.optString("reason", "")
                showDeviceBlockedScreen(json.optString("school_name", ""), reason)
                return
            }
```

- [ ] **Step 4: Tambahkan layar terkunci**

Tambahkan fungsi baru di `MainActivity`, tepat sebelum `fetchServerKioskConfig()`:

```kotlin
    /**
     * Layar akhir untuk perangkat yang diblokir. Tidak ada tombol coba lagi:
     * ini keputusan pengawas, bukan gangguan jaringan, dan tombol coba lagi
     * hanya mengundang siswa menekannya berkali-kali.
     */
    private fun showDeviceBlockedScreen(schoolName: String, reason: String) {
        runOnUiThread {
            try {
                webView.loadUrl("about:blank")
                webView.visibility = android.view.View.GONE
            } catch (e: Throwable) {
                Log.w("MainActivity", "Gagal menyembunyikan WebView", e)
            }

            val pesan = buildString {
                append(getString(R.string.device_blocked_body))
                if (reason.isNotBlank()) {
                    append("\n\n")
                    append(getString(R.string.device_blocked_reason_prefix))
                    append(' ')
                    append(reason)
                }
            }

            AlertDialog.Builder(this)
                .setTitle(
                    if (schoolName.isBlank()) getString(R.string.device_blocked_title)
                    else schoolName
                )
                .setMessage(pesan)
                .setCancelable(false)
                .setPositiveButton(R.string.device_blocked_close) { _, _ -> finishAffinity() }
                .show()
        }
    }
```

- [ ] **Step 5: Tambahkan string**

Di `cbt-kiosk-app/app/src/main/res/values/strings.xml`, tepat sebelum `</resources>`, tambahkan:

```xml
    <string name="device_blocked_title">Perangkat Diblokir</string>
    <string name="device_blocked_body">Perangkat ini diblokir oleh pengawas dan tidak dapat digunakan untuk ujian. Serahkan perangkat kepada pengawas.</string>
    <string name="device_blocked_reason_prefix">Alasan:</string>
    <string name="device_blocked_close">Tutup Aplikasi</string>
```

- [ ] **Step 6: Bangun APK debug**

Run: `cd cbt-kiosk-app && ./gradlew :app:assembleDebug`
Expected: `BUILD SUCCESSFUL`, APK di `app/build/outputs/apk/debug/app-debug.apk`

- [ ] **Step 7: Jalankan seluruh tes unit Android**

Run: `cd cbt-kiosk-app && ./gradlew :app:testDebugUnitTest`
Expected: `BUILD SUCCESSFUL`

- [ ] **Step 8: Commit**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt cbt-kiosk-app/app/src/main/res/values/strings.xml
git commit -m "feat(device-ban): aplikasi menolak jalan saat perangkatnya diblokir"
```

---

## Task 11: Verifikasi akhir

**Files:** tidak ada perubahan kode.

- [ ] **Step 1: Seluruh tes PHP**

Run: `docker compose exec --user 33:33 php sh -c 'cd /var/www/html && vendor/bin/phpunit'`
Expected: `OK (66 tests, ...)` — 60 sebelumnya ditambah 6 dari `DeviceBanTest`.

- [ ] **Step 2: Seluruh tes Android**

Run: `cd cbt-kiosk-app && ./gradlew :app:testDebugUnitTest`
Expected: `BUILD SUCCESSFUL`

- [ ] **Step 3: Pastikan tidak ada berkas sementara tertinggal**

Run: `git status --short && ls src/app/Commands/Tmp* 2>/dev/null || echo "bersih"`
Expected: tidak ada berkas `Tmp*`, tidak ada perubahan yang belum di-commit selain `strix_runs/` yang memang tidak dilacak.

- [ ] **Step 4: Verifikasi manual di perangkat nyata**

Ini tidak bisa diotomatiskan dan **harus dijalankan pengguna**, bukan agen — berikan sebagai instruksi bernomor:

1. Pasang APK debug ke satu perangkat uji, jalankan, pastikan ujian berjalan normal.
2. Di `/admin/kiosk/live`, pilih ujian yang sedang berjalan, buka menu Aksi pada baris perangkat itu, tekan **Blokir Perangkat**, isi alasan.
3. Perangkat harus keluar dari ujian saat itu juga.
4. Tutup dan buka lagi aplikasi di perangkat itu. Harus muncul dialog **Perangkat Diblokir** dan **halaman ujian tidak boleh termuat sama sekali**.
5. Login akun siswa yang sama di perangkat lain — harus tetap bisa masuk. Ini yang membuktikan blokir menyasar perangkat, bukan akun.
6. Di `/admin/kiosk/devices`, tekan **Buka Kunci** pada perangkat itu.
7. Buka lagi aplikasi di perangkat pertama — harus kembali normal tanpa perlu memasang ulang apa pun.

- [ ] **Step 5: Commit apa pun yang tersisa**

```bash
git status --short
```
Bila bersih, tidak ada yang perlu di-commit.

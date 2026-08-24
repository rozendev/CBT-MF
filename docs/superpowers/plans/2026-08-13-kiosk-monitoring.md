# Monitoring Kiosk Real-Time Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Perangkat kiosk Android mengirim heartbeat 15 detik ke endpoint framework-free; admin memantau status perangkat per ujian lewat dashboard baru dengan polling 10 detik.

**Architecture:** Endpoint `public/kiosk-heartbeat.php` (tanpa bootstrap CI4, seperti `maintenance-check.php`) memvalidasi `ws_student_token` di Redis, menulis `HSET kiosk_live:{test_id}:{user_id}` (tanpa TTL, field `ts`), dan mengaudit transisi online via `HSETNX`. Command cron `kiosk:prune` tiap 60 detik menghapus key stale (>90 dtk) + audit `kiosk_offline`. Dashboard `admin/kiosk/live` di-polling tiap 10 detik + banner outage via `maintenance-check.php`. Android `HeartbeatManager` native (Kotlin) mengirim payload status perangkat.

**Tech Stack:** PHP 8.5 (framework-free + CI4 4.7.4), Redis (phpredis), MySQL (PDO/Query Builder), nginx, Kotlin (Android, API 28+), Alpine.js 3 (CDN), Bootstrap 5.

## Global Constraints

- Endpoint heartbeat bebas framework: TIDAK menggunakan CI4 bootstrap/session/service — hanya `\Redis()` + PDO (env `REDIS_HOST/REDIS_PORT/REDIS_PASSWORD` dan `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`).
- Token valid: `ws_student_token:{token}` → JSON `{user_id, attempt_id, test_id}` (sudah dipakai `canExit`; SETEX 14400).
- Key Redis: `kiosk_live:{test_id}:{user_id}` (hash), tanpa TTL; field: `battery, charging, network, app_version, device_id, ts`.
- Threshold status: hijau `now-ts ≤ 30`, kuning/stale `30 < now-ts ≤ 90`, abu/offline key tidak ada.
- Audit: `exam_kiosk_events` (`event_type` `'kiosk_online'`/`'kiosk_offline'`; kolom `exam_session_id` DIISI `test_id` — konsisten dengan `WebSocketServerHandler`); `event_details` JSON; `created_at` `date('Y-m-d H:i:s')`.
- Nama command: `kiosk:prune` (group `Exam`); cron loop di docker-compose `cron` service, interval 60 dtk.
- Dashboard: route admin-only (`role:admin`) `GET admin/kiosk/live` + `GET admin/kiosk/live-data`.
- Android package: `id.sch.cbt.kiosk`; heartbeat di `kiosk/HeartbeatManager.kt`; interval 15 detik, backoff 30 detik saat gagal, berhenti pada `401`.
- Commit per task, stage HANYA file task tersebut (`git add <paths>` eksplisit). Catatan: `MainActivity.kt`/`KioskManager.kt` sudah membawa perubahan kiosk yang belum di-commit — tetap diikutkan pada commit Task 5.

---

### Task 1: Endpoint Heartbeat `public/kiosk-heartbeat.php`

**Files:**
- Create: `src/public/kiosk-heartbeat.php`

**Interfaces:**
- Consumes: env `REDIS_HOST/REDIS_PORT/REDIS_PASSWORD`, `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`; Redis key `ws_student_token:{token}` → JSON `{user_id, attempt_id, test_id}`; tabel `exam_kiosk_events`.
- Produces: Redis `kiosk_live:{test_id}:{user_id}` (hash); HTTP kontrak: `200 {"status":"ok"}`, `401 {"status":"invalid_token"}`, `503 {"status":"maintenance","mode":"redis"}` (JSON, `Cache-Control: no-store`).

- [ ] **Step 1: Tulis file endpoint**

```php
<?php

/**
 * Kiosk heartbeat endpoint — intentionally framework-free.
 *
 * Served through the nginx `location ~ \.php$` regex (like
 * maintenance-check.php), so it bypasses the maintenance flag gate and
 * keeps working during manual maintenance mode. When Redis itself is
 * down it answers 503 {mode:redis} so the kiosk can back off.
 *
 * Contract (POST, JSON body):
 *   {token, device_id, battery, charging, network, app_version}
 *   200 {"status":"ok"} | 401 {"status":"invalid_token"} | 503 {"status":"maintenance","mode":"redis"}
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$status = 200;
$body   = ['status' => 'ok'];

try {
    $raw = file_get_contents('php://input');
    $req = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($req)) {
        $req = [];
    }

    $token = (string) ($req['token'] ?? '');
    if ($token === '') {
        http_response_code(401);
        echo json_encode(['status' => 'invalid_token']);
        exit;
    }

    $redis = new Redis();
    if (!$redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 1.5)) {
        http_response_code(503);
        echo json_encode(['status' => 'maintenance', 'mode' => 'redis']);
        exit;
    }
    $password = (string) getenv('REDIS_PASSWORD');
    if ($password !== '' && !$redis->auth($password)) {
        http_response_code(503);
        echo json_encode(['status' => 'maintenance', 'mode' => 'redis']);
        exit;
    }

    $sessionRaw = $redis->get('ws_student_token:' . $token);
    $session    = $sessionRaw !== false ? json_decode($sessionRaw, true) : null;
    if (!is_array($session) || !isset($session['user_id'], $session['attempt_id'], $session['test_id'])) {
        http_response_code(401);
        echo json_encode(['status' => 'invalid_token']);
        exit;
    }

    $testId  = (int) $session['test_id'];
    $userId  = (int) $session['user_id'];
    $key     = "kiosk_live:{$testId}:{$userId}";
    $now     = time();

    $battery = (int) ($req['battery'] ?? -1);
    if ($battery < 0 || $battery > 100) {
        $battery = -1;
    }

    $fields = [
        'battery'     => (string) $battery,
        'charging'    => !empty($req['charging']) ? '1' : '0',
        'network'     => in_array(($req['network'] ?? ''), ['wifi', 'mobile', 'none'], true) ? $req['network'] : 'unknown',
        'app_version' => substr((string) ($req['app_version'] ?? ''), 0, 32),
        'device_id'   => substr((string) ($req['device_id'] ?? ''), 0, 64),
        'ts'          => (string) $now,
    ];

    // Race-free audit of first heartbeat of a session:
    // HSETNX returns 1 only when the key is created.
    $isNew = $redis->hSetNx($key, 'ts', (string) $now);
    $redis->hMSet($key, $fields);

    if ($isNew) {
        try {
            $pdo = new PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
                . ';port=' . (getenv('DB_PORT') ?: '3306')
                . ';dbname=' . (getenv('DB_DATABASE') ?: 'cbt')
                . ';charset=utf8mb4',
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: '',
                [PDO::ATTR_TIMEOUT => 2, PDO::ERRMODE_EXCEPTION => true]
            );
            $stmt = $pdo->prepare(
                'INSERT INTO exam_kiosk_events (exam_session_id, student_id, event_type, event_details, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $testId,
                $userId,
                'kiosk_online',
                json_encode([
                    'device_id'   => $fields['device_id'],
                    'battery'     => $fields['battery'],
                    'network'     => $fields['network'],
                    'app_version' => $fields['app_version'],
                ], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s', $now),
            ]);
        } catch (Throwable $e) {
            // Audit is best-effort: a DB failure must not break the heartbeat.
            error_log('[kiosk-heartbeat] audit insert failed: ' . $e->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    error_log('[kiosk-heartbeat] error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['status' => 'maintenance', 'mode' => 'redis']);
}
```

- [ ] **Step 2: Lint**

Run: `docker exec ex_php php -l /var/www/html/public/kiosk-heartbeat.php`
Expected: `No syntax errors detected in ...`

- [ ] **Step 3: Uji fungsional (token valid → 200 + Redis + audit DB)**

```bash
cd /home/rozen/conquer/CBT-MF
# Seed token valid
docker exec ex_php php -r '
$r = new Redis(); $r->connect(getenv("REDIS_HOST") ?: "redis", 6379, 2);
$r->auth(getenv("REDIS_PASSWORD"));
$r->setex("ws_student_token:abcd1234", 14400, json_encode(["user_id" => 2, "attempt_id" => 99, "test_id" => 3]));
'
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php \
  -H 'Content-Type: application/json' \
  -d '{"token":"abcd1234","device_id":"dev-1","battery":87,"charging":true,"network":"wifi","app_version":"1.2.0"}'
# Expected: {"status":"ok"}
docker exec ex_php php -r '
$r = new Redis(); $r->connect("redis", 6379, 2); $r->auth(getenv("REDIS_PASSWORD"));
var_dump($r->hGetAll("kiosk_live:3:2"));
'
# Expected: array dengan battery=87, network=wifi, ts
```

Verifikasi audit DB:
```bash
docker exec ex_php sh -c 'php -r '"'"'
$pdo = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
foreach ($pdo->query("SELECT exam_session_id, student_id, event_type, event_details FROM exam_kiosk_events ORDER BY id DESC LIMIT 1") as $row) { print_r($row); }
'"'"''
```
Expected: `event_type=kiosk_online`, `exam_session_id=3` (test_id), detail `dev-1`.

- [ ] **Step 4: Uji token invalid → 401**

```bash
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php -H 'Content-Type: application/json' -d '{"token":"nope"}'
# Expected: {"status":"invalid_token"} (HTTP 401)
```

- [ ] **Step 5: Uji Redis down → 503 (dan pulih)**

```bash
docker stop ex_redis
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php -H 'Content-Type: application/json' -d '{"token":"abcd1234"}'
# Expected: {"status":"maintenance","mode":"redis"} (HTTP 503)
docker start ex_redis
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php -H 'Content-Type: application/json' -d '{"token":"abcd1234"}'
# Expected: {"status":"ok"}
```

- [ ] **Step 6: Commit**

```bash
git add src/public/kiosk-heartbeat.php
git commit -m "feat(kiosk): add framework-free heartbeat endpoint with Redis live store and online audit"
```

---

### Task 2: Command `kiosk:prune` + wiring cron

**Files:**
- Create: `src/app/Commands/KioskPrune.php`
- Modify: `docker-compose.yml:85` (command cron — tambah loop `kiosk:prune`)

**Interfaces:**
- Consumes: `App\Libraries\RedisClient::getInstance()` (`?\Redis`, statik); Redis `kiosk_live:*`; tabel `exam_kiosk_events`.
- Produces: command `php spark kiosk:prune` — menghapus key `ts < now-90` + audit `'kiosk_offline'`; exit 0.

- [ ] **Step 1: Tulis command**

```php
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\RedisClient;

/**
 * Prunes stale kiosk heartbeat keys (no heartbeat for > 90s) and records
 * the offline transition audit.
 *
 * Intended to run as a cron loop (every 60s):
 *   while true; do php spark kiosk:prune; sleep 60; done
 */
class KioskPrune extends BaseCommand
{
    protected $group       = 'Exam';
    protected $name        = 'kiosk:prune';
    protected $description = 'Remove stale kiosk_live keys and audit offline transitions (run every 60s from cron).';

    /** No heartbeat within this window → device considered offline. */
    private const STALE_SECONDS = 90;

    public function run(array $params)
    {
        $redis = RedisClient::getInstance();
        if ($redis === null) {
            CLI::write('kiosk:prune — Redis unavailable, skipping.', 'yellow');

            return EXIT_SUCCESS;
        }

        $cutoff   = time() - self::STALE_SECONDS;
        $cursor   = null;
        $pruned   = 0;

        do {
            $keys = $redis->scan($cursor, 'kiosk_live:*', 500);
            if (!is_array($keys)) {
                break;
            }

            foreach ($keys as $key) {
                $ts = (int) $redis->hGet($key, 'ts');
                if ($ts === 0 || $ts >= $cutoff) {
                    continue;
                }

                $fields = $redis->hMGet($key, ['device_id', 'battery', 'network']);

                $redis->del($key);

                $parts = explode(':', $key); // kiosk_live:{test_id}:{user_id}
                $testId = (int) ($parts[1] ?? 0);
                $userId = (int) ($parts[2] ?? 0);

                try {
                    $db = \Config\Database::connect();
                    $db->table('exam_kiosk_events')->insert([
                        'exam_session_id' => $testId,
                        'student_id'      => $userId,
                        'event_type'      => 'kiosk_offline',
                        'event_details'   => json_encode([
                            'device_id' => (string) ($fields['device_id'] ?? ''),
                            'last_seen' => date('Y-m-d H:i:s', $ts),
                            'battery'   => (int) ($fields['battery'] ?? -1),
                            'network'   => (string) ($fields['network'] ?? ''),
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $e) {
                    log_message('error', 'kiosk:prune audit insert failed: ' . $e->getMessage());
                }

                $pruned++;
                CLI::write(sprintf('kiosk:prune — offline: user %d test %d', $userId, $testId), 'yellow');
            }
        } while ($cursor !== null && $cursor > 0);

        CLI::write('kiosk:prune — done, ' . $pruned . ' stale key(s) cleaned.', 'green');

        return EXIT_SUCCESS;
    }
}
```

- [ ] **Step 2: Tambah loop cron di `docker-compose.yml`**

Ganti baris `command:` service `cron` menjadi (loop `kiosk:prune` tiap 60 dtk sebagai proses background ketiga):

```yaml
    command: sh -c "echo '[cron] Auto-finalizer started'; while true; do php /var/www/html/spark finalize:expired 2>&1; sleep 60; done & while true; do php /var/www/html/spark redis:probe 2>&1; sleep 10; done & while true; do php /var/www/html/spark kiosk:prune 2>&1; sleep 60; done & wait"
```

- [ ] **Step 3: Lint + apply**

```bash
docker exec ex_php php -l /var/www/html/app/Commands/KioskPrune.php
docker compose config --quiet
```
Expected: lint OK; `docker compose config` exit 0.

- [ ] **Step 4: Uji prune (key stale → hapus + audit)**

```bash
cd /home/rozen/conquer/CBT-MF
# Seed key stale (ts 200 detik lalu) + key segar
docker exec ex_php php -r '
$r = new Redis(); $r->connect("redis", 6379, 2); $r->auth(getenv("REDIS_PASSWORD"));
$r->hMSet("kiosk_live:3:2", ["battery" => "80", "network" => "wifi", "device_id" => "dev-1", "ts" => (string)(time()-200)]);
$r->hMSet("kiosk_live:3:2:FRESH", ["battery" => "90", "network" => "mobile", "device_id" => "dev-2", "ts" => (string)time()]);
'
docker exec ex_php_cron php spark kiosk:prune
# Expected: 'kiosk:prune — offline: user 2 test 3' + 'done, 1 stale key(s) cleaned.'
```
Verifikasi key segar masih ada, stale hilang, dan audit terisi:
```bash
docker exec ex_php php -r '
$r = new Redis(); $r->connect("redis", 6379, 2); $r->auth(getenv("REDIS_PASSWORD"));
var_dump($r->exists("kiosk_live:3:2"), $r->exists("kiosk_live:3:2:FRESH"));
'
# Expected: bool(false), bool(true)
```

- [ ] **Step 5: Recreate cron container + verifikasi loop**

```bash
docker compose up -d cron
sleep 15
docker logs ex_php_cron --since 20s 2>&1 | grep -E "kiosk:prune|finalize|redis:probe" | tail -4
```
Expected: `redis:probe — Redis OK...`, `kiosk:prune — done...`, `No expired attempts found.` (ketiganya jalan).

- [ ] **Step 6: Commit**

```bash
git add src/app/Commands/KioskPrune.php docker-compose.yml
git commit -m "feat(kiosk): add kiosk:prune command and cron loop for offline detection"
```

---

### Task 3: Route + `KioskLiveController`

**Files:**
- Modify: `src/app/Config/Routes.php` (setelah baris 91 `kiosk/update`)
- Create: `src/app/Controllers/Admin/KioskLiveController.php`

**Interfaces:**
- Consumes: `TestModel` (`tests`), Query Builder `test_attempts` + `users`; `RedisClient::getInstance()`; model `TestAttemptModel`.
- Produces:
  - `index()` → render `admin/kiosk/live` dengan `$activeTests` (array: `id`, `name`, `attempt_count`).
  - `data()` → JSON `{test_id, now, students: [{user_id, firstname, lastname, username, status: "online"|"stale"|"offline", battery, charging, network, app_version, device_id, last_seen}]}`.
  - Route: `GET admin/kiosk/live`, `GET admin/kiosk/live-data` (keduanya `role:admin`).

- [ ] **Step 1: Tambah route**

Di dalam grup admin-only (setelah baris 91), tambahkan:

```php
        // Kiosk Live Monitoring
        $routes->get('kiosk/live', 'Admin\KioskLiveController::index');
        $routes->get('kiosk/live-data', 'Admin\KioskLiveController::data');
```

- [ ] **Step 2: Tulis controller**

```php
<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\RedisClient;
use App\Models\TestModel;

class KioskLiveController extends BaseController
{
    private const STALE_SECONDS = 90;

    public function index()
    {
        $db = \Config\Database::connect();

        $activeTests = $db->table('tests')
            ->select('tests.id, tests.name, COUNT(ta.id) AS attempt_count')
            ->join('test_attempts ta', 'ta.test_id = tests.id', 'inner')
            ->whereIn('ta.status', [0, 1, 2])
            ->groupBy('tests.id, tests.name')
            ->orderBy('tests.id', 'DESC')
            ->limit(100)
            ->get()
            ->getResultArray();

        return view('admin/kiosk/live', [
            'title'       => 'Monitoring Kiosk Real-Time',
            'activeTests' => $activeTests,
        ]);
    }

    public function data()
    {
        $testId = (int) $this->request->getGet('test_id');
        if ($testId <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'test_id wajib']);
        }

        $db = \Config\Database::connect();
        $attempts = $db->table('test_attempts')
            ->select('test_attempts.user_id, users.firstname, users.lastname, users.username')
            ->join('users', 'users.id = test_attempts.user_id')
            ->where('test_attempts.test_id', $testId)
            ->whereIn('test_attempts.status', [0, 1, 2])
            ->get()
            ->getResultArray();

        $redis = RedisClient::getInstance();
        $now   = time();

        $students = [];
        foreach ($attempts as $attempt) {
            $userId = (int) $attempt['user_id'];
            $status = 'offline';
            $device = [
                'battery'     => -1,
                'charging'    => false,
                'network'     => 'unknown',
                'app_version' => '',
                'device_id'   => '',
                'last_seen'   => null,
            ];

            if ($redis) {
                $info = $redis->hGetAll("kiosk_live:{$testId}:{$userId}");
                if (!empty($info)) {
                    $ts = (int) ($info['ts'] ?? 0);
                    $status = ($now - $ts) <= 30 ? 'online' : 'stale';
                    $device = [
                        'battery'     => (int) ($info['battery'] ?? -1),
                        'charging'    => ($info['charging'] ?? '0') === '1',
                        'network'     => (string) ($info['network'] ?? 'unknown'),
                        'app_version' => (string) ($info['app_version'] ?? ''),
                        'device_id'   => (string) ($info['device_id'] ?? ''),
                        'last_seen'   => date('Y-m-d H:i:s', $ts),
                    ];
                }
            }

            $students[] = array_merge([
                'user_id'  => $userId,
                'username' => $attempt['username'],
                'firstname'=> $attempt['firstname'],
                'lastname' => $attempt['lastname'],
                'status'   => $status,
            ], $device);
        }

        return $this->response->setJSON([
            'test_id'  => $testId,
            'now'      => $now,
            'students' => $students,
        ]);
    }
}
```

- [ ] **Step 3: Lint**

Run: `docker exec ex_php php -l /var/www/html/app/Controllers/Admin/KioskLiveController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Uji endpoint data**

```bash
cd /home/rozen/conquer/CBT-MF
# Ambil cookie + CSRF: akses halaman login via curl dengan cookie jar
curl -s -c /tmp/opencode/kiosk-cookies.txt -H "X-Forwarded-Proto: https" http://localhost:8080/login -o /tmp/opencode/login.html
CSRF=$(grep -oE 'name="[a-z0-9_]+" value="[a-f0-9]+"' /tmp/opencode/login.html | grep -oE '[a-f0-9]{40}' | head -1)
curl -s -b /tmp/opencode/kiosk-cookies.txt -c /tmp/opencode/kiosk-cookies.txt -H "X-Forwarded-Proto: https" \
  -d "username=admin&password=ADMIN_PASSWORD_KAMU&csrf_test_name=$CSRF" \
  http://localhost:8080/login -o /dev/null -w "login: %{http_code}\n"
curl -s -b /tmp/opencode/kiosk-cookies.txt \
  -H "X-Forwarded-Proto: https" -H "X-Requested-With: XMLHttpRequest" -H "X-CSRF-TOKEN: $(grep -oE 'X-CSRF-TOKEN: [a-f0-9]+' /dev/null 2>/dev/null || true)" \
  "http://localhost:8080/admin/kiosk/live-data?test_id=3"
```
Catatan: CSRF header diambil dari bagian ini adalah ilustrasi; untuk uji cepat yang andal, login manual lalu buka URL di browser. Pada uji otomatis boleh sementara menambahkan `'kiosk/live-data'` ke daftar `except` CSRF di `Filters.php` **hanya untuk verifikasi lokal**, lalu kembalikan. Tujuan uji: `200` dengan `"students"` array; tambahkan `kiosk_live:3:2` (dari Task 1) → siswa `status: online/stale` sesuai `ts`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Config/Routes.php src/app/Controllers/Admin/KioskLiveController.php
git commit -m "feat(admin): add kiosk live monitoring controller and data endpoint"
```

---

### Task 4: Dashboard View `admin/kiosk/live.php` + menu sidebar

**Files:**
- Create: `src/app/Views/admin/kiosk/live.php`
- Modify: `src/app/Views/layouts/admin.php` (sidebar — tambah item menu "Kiosk Live" setelah entri `/admin/kiosk` ~baris 929)

**Interfaces:**
- Consumes: `$activeTests` (dari `KioskLiveController::index`), endpoint `admin/kiosk/live-data`, `maintenance-check.php`, Alpine.js.
- Produces: halaman dashboard interaktif (dropdown test, tabel status, banner outage, polling 10 dtk).

- [ ] **Step 1: Tulis view**

```php
<?= $this->extend('layouts/admin') ?>
<?= $this->section('page_title') ?>Monitoring Kiosk Real-Time<?= $this->endSection() ?>
<?= $this->section('styles') ?>
<style>
    .status-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
    .status-online { background: #22c55e; box-shadow: 0 0 0 4px rgba(34,197,94,.15); }
    .status-stale  { background: #f59e0b; box-shadow: 0 0 0 4px rgba(245,158,11,.15); }
    .status-offline{ background: #9ca3af; }
    .kiosk-outage-banner { border-left: 4px solid var(--danger, #dc3545); }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div x-data="kioskLive()" class="pb-5">
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h3 class="fw-bold mb-1"><i class="bi bi-phone me-2 text-primary"></i>Monitoring Kiosk Real-Time</h3>
            <p class="text-muted mb-0">Status perangkat kiosk siswa per ujian. Data diperbarui otomatis tiap 10 detik.</p>
        </div>
        <div class="col-md-4 text-end">
            <select x-model="selectedTest" @change="loadData()" class="form-select d-inline-block w-auto">
                <option value="">— Pilih Ujian —</option>
                <template x-for="t in tests" :key="t.id">
                    <option :value="t.id" x-text="t.name + ' (' + t.attempt_count + ' peserta)'"></option>
                </template>
            </select>
        </div>
    </div>

    <div x-show="outageMessage" class="alert kiosk-outage-banner mb-4" x-cloak>
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span x-text="outageMessage"></span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Status</th><th>Siswa</th><th>Baterai</th><th>Jaringan</th>
                            <th>Versi App</th><th>Device ID</th><th>Terakhir Terlihat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in students" :key="s.user_id">
                            <tr>
                                <td>
                                    <span class="status-dot" :class="'status-' + s.status"></span>
                                    <span class="ms-2 badge" :class="s.status==='online' ? 'bg-success' : (s.status==='stale' ? 'bg-warning text-dark' : 'bg-secondary')"
                                          x-text="s.status==='online' ? 'Online' : (s.status==='stale' ? 'Stale' : 'Offline')"></span>
                                </td>
                                <td><span class="fw-semibold" x-text="s.firstname + ' ' + s.lastname"></span><br>
                                    <small class="text-muted" x-text="s.username"></small></td>
                                <td>
                                    <template x-if="s.battery >= 0">
                                        <span><i class="bi" :class="s.charging ? 'bi-battery-charging text-success' : 'bi-battery-half'"></i>
                                            <span x-text="s.battery + '%'"></span></span>
                                    </template>
                                    <template x-if="s.battery < 0"><span class="text-muted">—</span></template>
                                </td>
                                <td>
                                    <i class="bi" :class="s.network==='wifi' ? 'bi-wifi text-primary' : (s.network==='mobile' ? 'bi-signal text-primary' : 'bi-x-circle text-muted')"></i>
                                    <span class="ms-1 text-capitalize" x-text="s.network === 'unknown' ? '—' : s.network"></span>
                                </td>
                                <td><span class="text-muted" x-text="s.app_version || '—'"></span></td>
                                <td><span class="text-muted small" x-text="s.device_id ? s.device_id.substring(0, 8) + '…' : '—'"></span></td>
                                <td><span class="text-muted small" x-text="s.last_seen || '—'"></span></td>
                            </tr>
                        </template>
                        <tr x-show="students.length === 0">
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                <template x-if="!selectedTest">Pilih ujian untuk melihat status perangkat.</template>
                                <template x-if="selectedTest">Belum ada peserta aktif pada ujian ini.</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function kioskLive() {
    return {
        tests: <?= json_encode($activeTests ?? [], JSON_UNESCAPED_UNICODE) ?>,
        selectedTest: '',
        students: [],
        outageMessage: '',
        timer: null,
        init() {
            if (this.tests.length === 1) {
                this.selectedTest = String(this.tests[0].id);
                this.loadData();
            }
            this.checkOutage();
            this.timer = setInterval(() => {
                if (this.selectedTest) this.loadData();
                this.checkOutage();
            }, 10000);
        },
        loadData() {
            if (!this.selectedTest) return;
            const headers = {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
            };
            fetch('<?= base_url('/admin/kiosk/live-data') ?>?test_id=' + encodeURIComponent(this.selectedTest), { headers })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(d => { this.students = d.students || []; })
                .catch(e => console.error('kiosk live-data failed:', e));
        },
        checkOutage() {
            fetch('<?= base_url('/maintenance-check.php') ?>', { cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    if (d.mode === 'redis') {
                        this.outageMessage = 'Redis tidak tersedia — data mungkin kedaluwarsa hingga layanan pulih.';
                    } else if (d.mode === 'manual') {
                        this.outageMessage = 'Mode pemeliharaan manual aktif — data saat ini mungkin tidak lengkap.';
                    } else {
                        this.outageMessage = '';
                    }
                })
                .catch(() => {});
        }
    }
}
</script>
<?= $this->endSection() ?>
```

Catatan view:
- `x-cloak` didukung layout admin? Jika tidak, tambahkan `[x-cloak]{display:none!important}` ke section `styles`. (Opsional — banner `x-show` aman tanpa `x-cloak`; pertahankan `<div x-show=...>` biasa.)
- Biarkan tanpa `x-cloak` pada banner agar tidak butuh CSS tambahan.

- [ ] **Step 2: Tambah menu sidebar**

Di `src/app/Views/layouts/admin.php`, setelah entri `/admin/kiosk` (baris ~929) tambahkan:

```php
            <a href="<?= base_url('/admin/kiosk/live') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/kiosk/live') ? 'active' : '' ?>" title="Monitoring Kiosk Real-Time">
                <i class="bi bi-broadcast-pin"></i>
                <span class="nav-text">Kiosk Live</span>
            </a>
```

Catatan: `str_contains('/admin/kiosk/live', '/admin/kiosk')` bernilai true untuk entri lama → pastikan entri lama diberi kondisi tambahan agar tidak aktif ganda: ubah kondisi entri lama menjadi `str_contains($currentUrl, '/admin/kiosk') && !str_contains($currentUrl, '/admin/kiosk/live')`.

- [ ] **Step 3: Uji halaman**

Login admin di browser → buka `/admin/kiosk/live`:
- Dropdown menampilkan test dengan peserta aktif; bila hanya 1 test → otomatis terpilih dan data langsung tampil.
- Seed `kiosk_live:3:2` dengan `ts` segar → siswa tampil dot hijau + battery; ubah `ts` ke 60 dtk lalu → kuning; hapus key → abu.
- Banner: `docker stop ex_redis` → banner merah muncul ≤10 dtk; `docker start ex_redis` → banner hilang.
- Console bebas error (`live-data` 200).

- [ ] **Step 4: Commit**

```bash
git add src/app/Views/admin/kiosk/live.php src/app/Views/layouts/admin.php
git commit -m "feat(admin): add kiosk live monitoring dashboard with 10s polling and outage banner"
```

---

### Task 5: Android `HeartbeatManager` + integrasi

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HeartbeatManager.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt` (simpan + pass token, start/stop heartbeat)
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt` (buat `HeartbeatManager` + callback unauthorized)

**Interfaces:**
- Consumes: `MainActivity.prefs` (`server_url`, `kiosk_device_id`), `KioskManager.currentToken`/`currentExamId`, `CommsBridge.sendEventToJS(webView, "kiosk_failed", ...)`.
- Produces: `HeartbeatManager(activity, onUnauthorized)` dengan `start(examId, token)` / `stop()`.

- [ ] **Step 1: Tulis `HeartbeatManager.kt`**

```kotlin
package id.sch.cbt.kiosk.kiosk

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Handler
import android.os.Looper
import android.util.Log
import id.sch.cbt.kiosk.BuildConfig
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * Sends device status heartbeats to the CBT-MF server while the kiosk
 * exam is active: `POST {server_url}/kiosk-heartbeat.php` every 15s.
 *
 * - 200 → continue
 * - 401 → stop and notify via [onUnauthorized] (session expired)
 * - 503 / network error → back off to 30s (outage noise guard)
 */
class HeartbeatManager(
    private val activity: Activity,
    private val onUnauthorized: () -> Unit
) {

    companion object {
        private const val TAG = "HeartbeatManager"
        private const val INTERVAL_MS = 15_000L
        private const val BACKOFF_MS = 30_000L
        private const val TIMEOUT_MS = 5_000
    }

    private val handler = Handler(Looper.getMainLooper())
    private var examId = ""
    private var token = ""
    private var running = false
    private var backoff = false

    fun start(examId: String, token: String) {
        this.examId = examId
        this.token = token
        if (running) return
        running = true
        Log.d(TAG, "heartbeat started for exam $examId")
        schedule()
    }

    fun stop() {
        running = false
        handler.removeCallbacksAndMessages(null)
        Log.d(TAG, "heartbeat stopped")
    }

    private fun schedule() {
        if (!running) return
        handler.postDelayed({ tick() }, if (backoff) BACKOFF_MS else INTERVAL_MS)
    }

    private fun tick() {
        if (!running || token.isBlank()) return
        val url = (activity.getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)
            .getString("server_url", "") ?: "")
            .trimEnd('/') + "/kiosk-heartbeat.php"
        if (url == "/kiosk-heartbeat.php") {
            schedule()
            return
        }

        val deviceId = activity.getSharedPreferences("cbt_kiosk_prefs", Context.MODE_PRIVATE)
            .getString("kiosk_device_id", "") ?: ""
        val payload = buildPayload(deviceId)

        thread(start = true, isDaemon = true, name = "KioskHeartbeat") {
            var code = 0
            try {
                code = postJson(url, payload)
            } catch (e: Throwable) {
                Log.w(TAG, "heartbeat request failed", e)
            }

            when {
                code == 401 -> {
                    running = false
                    handler.removeCallbacksAndMessages(null)
                    activity.runOnUiThread { onUnauthorized() }
                }
                code == 200 -> backoff = false
                else -> backoff = true // 503 / 5xx / network error
            }

            handler.post { schedule() }
        }
    }

    private fun buildPayload(deviceId: String): String {
        val battery = (activity.getSystemService(Context.BATTERY_SERVICE) as BatteryManager)
            .getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        val isCharging = try {
            val sticky = activity.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            sticky?.getIntExtra(BatteryManager.EXTRA_STATUS, -1) == BatteryManager.BATTERY_STATUS_CHARGING
        } catch (e: Throwable) { false }

        val cm = activity.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = try {
            val caps = cm.getNetworkCapabilities(cm.activeNetwork)
            when {
                caps == null -> "none"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "mobile"
                else -> "none"
            }
        } catch (e: Throwable) { "none" }

        return JSONObject()
            .put("token", token)
            .put("device_id", deviceId)
            .put("battery", battery)
            .put("charging", isCharging)
            .put("network", network)
            .put("app_version", BuildConfig.VERSION_NAME)
            .toString()
    }

    private fun postJson(url: String, body: String): Int {
        val conn = URL(url).openConnection() as HttpURLConnection
        return try {
            conn.requestMethod = "POST"
            conn.connectTimeout = TIMEOUT_MS
            conn.readTimeout = TIMEOUT_MS
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
            conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            conn.responseCode
        } finally {
            conn.disconnect()
        }
    }
}
```

Catatan: `BuildConfig.VERSION_NAME` — pastikan `buildFeatures { buildConfig = true }` aktif di `app/build.gradle` (cek; jika belum, tambahkan).

- [ ] **Step 2: Integrasi `KioskManager.kt`**

Tambah field + setter (pola sama seperti `setSecurityManager`), dan start/stop:

```kotlin
    private var heartbeatManager: HeartbeatManager? = null

    fun setHeartbeatManager(manager: HeartbeatManager) {
        this.heartbeatManager = manager
    }
```

Di `startKiosk(...)` tepat setelah `isKioskActive = true`:
```kotlin
            heartbeatManager?.start(examId, token)
```
Di `stopKiosk()`, tepat setelah `isKioskActive = false`:
```kotlin
            heartbeatManager?.stop()
```

- [ ] **Step 3: Integrasi `MainActivity.kt`**

Di dalam `onCreate` (setelah `kioskManager` dibuat), pasang manager:

```kotlin
        kioskManager.setHeartbeatManager(
            HeartbeatManager(this) {
                CommsBridge.sendEventToJS(
                    getSafeWebView() ?: return@HeartbeatManager,
                    "kiosk_failed",
                    "{\"error\": \"Sesi kiosk tidak valid (401)\"}"
                )
            }
        )
```
(Jika `getSafeWebView()` nullable dan lambda butuh return — gunakan bentuk if-else eksplisit bila perlu.)

- [ ] **Step 4: Build check**

```bash
cd cbt-kiosk-app
./gradlew :app:compileDebugKotlin   # atau assembleDebug bila SDK tersedia
```
Expected: BUILD SUCCESSFUL (perbaiki error kompilasi jika ada). Jika tanpa Android SDK di mesin ini → jalankan `./gradlew tasks` minimal untuk memastikan skrip valid, dan beri tahu user untuk build manual di Android Studio.

- [ ] **Step 5: Uji manual (perangkat kiosk)**

1. Build & instal APK di perangkat kiosk.
2. Login siswa → mulai ujian → `logcat | grep HeartbeatManager`: `heartbeat started`.
3. Server: `docker exec ex_php redis-cli ... hgetall kiosk_live:{test}:{user}` atau via dashboard: perangkat muncul hijau dengan battery/network/versi.
4. Tutup paksa app → ≤2 menit dashboard jadi abu + event `kiosk_offline`.
5. Hapus token di Redis (`DEL ws_student_token:xxx`) → heartbeat berhenti + event `kiosk_failed` di JS.

- [ ] **Step 6: Commit**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/HeartbeatManager.kt \
        cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/kiosk/KioskManager.kt \
        cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt
git commit -m "feat(kiosk): add native heartbeat manager with battery/network reporting"
```

---

### Task 6: Verifikasi End-to-End

**Files:** (tidak ada perubahan kode)

**Interfaces:**
- Consumes: semua output Task 1–5.

- [ ] **Step 1: Simulasi alarm lengkap dari server**

```bash
cd /home/rozen/conquer/CBT-MF
# 1. Seed token + heartbeat → online
docker exec ex_php php -r '
$r = new Redis(); $r->connect("redis", 6379, 2); $r->auth(getenv("REDIS_PASSWORD"));
$r->setex("ws_student_token:e2e", 14400, json_encode(["user_id" => 2, "attempt_id" => 99, "test_id" => 3]));
'
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php -H 'Content-Type: application/json' \
  -d '{"token":"e2e","battery":55,"charging":false,"network":"mobile","app_version":"1.2.0"}'   # → {"status":"ok"}

# 2. Prune: key dibuat stale → jalankan command
docker exec ex_php_cron php spark kiosk:prune   # → "done, 0 stale" (baru)

# 3. Dashboard data
curl -s "http://localhost:8080/admin/kiosk/live-data?test_id=3"   # (dengan sesi admin; lihat Task 3 step 4)
# → students berisi user 2 status online

# 4. Redis down → 503 + banner
docker stop ex_redis
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php -H 'Content-Type: application/json' -d '{"token":"e2e"}'
# → {"status":"maintenance","mode":"redis"}
docker start ex_redis
```

- [ ] **Step 2: Siklus offline (stale → prune)**

```bash
# Buat key seolah-olah offline 120 detik
docker exec ex_php php -r '
$r = new Redis(); $r->connect("redis", 6379, 2); $r->auth(getenv("REDIS_PASSWORD"));
$r->hMSet("kiosk_live:3:2", ["battery" => "40", "network" => "none", "device_id" => "dev-e2e", "ts" => (string)(time()-120)]);
'
docker exec ex_php_cron php spark kiosk:prune
# → "kiosk:prune — offline: user 2 test 3" + "done, 1 stale"
```
Verifikasi baris audit `kiosk_offline` di DB (query seperti Task 1 Step 3).

- [ ] **Step 3: Siklus manual mode**

```bash
docker exec ex_php php -r '
require "/var/www/html/vendor/autoload.php";
define("WRITEPATH", rtrim((new \Config\Paths())->writableDirectory, "/") . "/");
\App\Libraries\MaintenanceFlag::set(\App\Libraries\MaintenanceFlag::MODE_MANUAL, "uji");
'
curl -s -X POST http://localhost:8080/kiosk-heartbeat.php -H 'Content-Type: application/json' -d '{"token":"e2e"}'
# → {"status":"ok"}  (mode manual TIDAK menghalangi heartbeat — desain §3.3)
docker exec ex_php php -r '
require "/var/www/html/vendor/autoload.php";
define("WRITEPATH", rtrim((new \Config\Paths())->writableDirectory, "/") . "/");
\App\Libraries\MaintenanceFlag::clear(\App\Libraries\MaintenanceFlag::MODE_MANUAL);
'
```

- [ ] **Step 4: Bersihkan data uji**

```bash
docker exec ex_php php -r '
$r = new Redis(); $r->connect("redis", 6379, 2); $r->auth(getenv("REDIS_PASSWORD"));
$r->del("ws_student_token:abcd1234", "ws_student_token:e2e", "kiosk_live:3:2", "kiosk_live:3:2:FRESH");
'
```

- [ ] **Step 5: Ringkasan hasil**

Laporkan ke user: status uji tiap Task (1–5), screenshot/output penting, dan sisa: build APK + uji perangkat nyata (Task 5 Step 5).

---

## Self-Review (dijalankan penulis plan)

- **Cakupan spec:** §3 endpoint → Task 1; §4 prune + cron → Task 2; §5 dashboard (halaman+data) → Task 3 & 4; §6 Android → Task 5; §7 tabel error handling → terdistribusi (503/401/backoff/stale/manual mode) dan Task 6 memverifikasi; §8 testing → tersebar tiap Task + Task 6. Otomatis preselect satu test & banner → Task 4. Satu penyimpangan kecil dari spec: spec menyebut auditable `kiosk_online` juga ditulis saat `HSETNX` (Task 1) — sesuai.
- **Placeholder scan:** tidak ada TBD/TODO; kode tiap langkah lengkap. Satu nilai yang harus diisi user: password admin untuk uji curl (ditandai `ADMIN_PASSWORD_KAMU`) — penggantinya: uji browser manual.
- **Konsistensi tipe:** `HeartbeatManager(activity, onUnauthorized).start(examId, token)/stop()` konsisten antara Task 5 Step 1/2/3; `KioskLiveController::index/data` konsisten dengan route Task 3; event JSON `{status:ok|invalid_token|maintenance}` konsisten endpoint ↔ manager; key helper `kiosk_live:{test_id}:{user_id}` konsisten lintas task.
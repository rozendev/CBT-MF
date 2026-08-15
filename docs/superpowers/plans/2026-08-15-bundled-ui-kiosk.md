# Bundled UI Kiosk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pindahkan seluruh UI siswa (login → dashboard → exam → results → review) dari server ke bundle lokal di device Android — server hanya menyediakan data JSON + satu paket UI yang di-download sekali, di-verify, dan di-cache; update tanpa rilis APK.

**Architecture:** Server: command `cbt:build-ui-bundle` merender 5 halaman + aset → `public/ui-bundle/` (zip + manifest per-file sha256) yang disajikan Nginx sebagai static file; `/api/kiosk/config` mengembalikan `ui_bundle.version`; API data memakai cookie session yang sama. App: `UiBundleManager` (DownloadManager / Import Bundle → verify sha256 per file vs manifest → extract ke staging → atomic rename) + WebViewAssetLoader untuk origin `https://appassets.androidplatform.net`. Halaman bundle memanggil API absolute + `credentials: 'include'`.

**Tech Stack:** PHP CodeIgniter 4 (server), Kotlin + WebView (`androidx.webkit:1.7.0`, minSdk 28), Alpine.js, Nginx, Android DownloadManager.

## Global Constraints

- Cookie session: `samesite = 'None'`, `secure = true` — di `Config\Cookie` (baris 92) DAN `Config\Session` (baris 33). HTTPS wajib karena setup screen app sudah enforce.
- CORS: `Access-Control-Allow-Origin` HANYA dari allowlist (env `CORS_ALLOWED_ORIGINS`, default baseURL) — TIDAK pernah wildcard; `Access-Control-Allow-Credentials: true`; `Vary: Origin`. (`CorsApiFilter` sudah menerapkan ini — dipakai ulang, tidak dibuat baru.)
- Semua `fetch` di halaman bundle wajib `credentials: 'include'`.
- CSRF: di-jalur JSON login TIDAK dipasang; mitigasi = CORS origin allowlist + origin check ketat (hanya origin `https://appassets.androidplatform.net` yang di-skip). Jalur web form-POST login tetap pakai CSRF (tidak berubah).
- `kiosk-integration.js` = `<script>` PERTAMA di `<head>` setiap halaman bundle.
- Bundle size budget: **< 300KB (300 × 1024 bytes)** — ukuran file `ui-bundle.zip` dijadikan gate; lewat budget = command gagal (exit code non-zero). Tidak ada webfont (system font), icon inline SVG. Vendor di bundle HANYA: `alpine.min.js` + `sweetalert2.min.js` (dibutuhkan exam-app.js; swal menanam CSS sendiri). TANPA bootstrap/jquery/bootstrap-icons/katex/quill. Estimasi zip ~80-100KB, jauh di bawah gate.
- Halaman server asli (jalur Windows) TIDAK diubah.
- KioskGuardService / HeartbeatManager / SecurityManager / CommsBridge TIDAK diubah.
- Semua perubahan di-commit per-task, pesan commit mengikuti gaya repo (`feat(...)`, `fix(...)`, `docs(...)`).
- Lint PHP: `docker compose exec ex_php php -l <file>` (container `ex_php`, env `CONTAINER_PHP=ex_php`).
- Reload nginx: `docker compose exec ex_nginx nginx -t && docker compose exec ex_nginx nginx -s reload`.

---

### Task 1: Cookie SameSite=None+Secure, CSRF exempt hanya untuk origin kiosk, login JSON, wiring CORS

**Files:**
- Modify: `src/app/Config/Cookie.php:92`
- Modify: `src/app/Config/Session.php:33`
- Create: `src/app/Filters/KioskOriginCsrfFilter.php`
- Modify: `src/app/Config/Filters.php:84-95,124-130`
- Modify: `src/app/Controllers/Auth/AuthController.php:44-198` (branch JSON di `attemptLogin`)
- Modify: `.env.example` dan `.env` (dev) — tambah `CORS_ALLOWED_ORIGINS`

**Interfaces:**
- Produces: filter alias `kioskcsrflogin` (class `App\Filters\KioskOriginCsrfFilter`); `AuthController::attemptLogin()` merespons JSON bila header `Accept` mengandung `application/json`; env `CORS_ALLOWED_ORIGINS` berisi `https://appassets.androidplatform.net`.

- [ ] **Step 1: Ubah cookie config**

`src/app/Config/Cookie.php` baris 92:
```php
    public string $samesite = 'None';
```
`src/app/Config/Session.php` baris 33:
```php
        'samesite'  => 'None',
```

- [ ] **Step 2: Buat filter CSRF exempt origin kiosk**

`src/app/Filters/KioskOriginCsrfFilter.php`:
```php
<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class KioskOriginCsrfFilter implements FilterInterface
{
    /**
     * Hanya request dengan Origin persis milik kiosk WebView yang bebas CSRF.
     * Semua request lain (web form, curl, origin asing) tetap wajib token CSRF.
     */
    private function isKioskOrigin(string $origin): bool
    {
        $kioskOrigin = env('KIOSK_CORS_ORIGIN', 'https://appassets.androidplatform.net');
        return $origin !== '' && $origin === $kioskOrigin;
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');

        if ($this->isKioskOrigin($origin)) {
            return null; // trusted kiosk origin: skip CSRF, CORS allowlist menjaga
        }

        // Jalur web (Windows) tetap wajib CSRF — pola persis Filter\CSRF bawaan CI4:
        try {
            service('security')->verify($request);
        } catch (\CodeIgniter\Security\Exceptions\SecurityException $e) {
            $security = service('security');
            if ($security->shouldRedirect() && !$request->isAJAX()) {
                return redirect()->back()->with('error', $e->getMessage());
            }
            throw $e;
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
```

- [ ] **Step 3: Daftarkan filter + alias di Filters.php**

`src/app/Config/Filters.php` — alias di blok `$aliases`:
```php
        'kioskcsrflogin' => \App\Filters\KioskOriginCsrfFilter::class,
```
Tambah `'login'` ke except CSRF global (baris ~86):
```php
            'csrf' => ['except' => [
                'login',
                'student/exam/stream/*',
                ...
```
Perluas `$filters` (baris ~124):
```php
        'corsapi'        => ['before' => ['api/exam/*', 'api/student/*', 'login']],
        'loginratelimit' => ['before' => ['login']],
        'apiratelimit'   => ['before' => ['api/*']],
        'kioskcsrflogin' => ['before' => ['login']],
```

- [ ] **Step 4: Branch JSON di attemptLogin**

Di `src/app/Controllers/Auth/AuthController.php::attemptLogin()` — segera setelah validasi input (setelah blok `$this->validate`), tambahkan helper response JSON + gunakan di tiap early-return. Ganti SEMUA `redirect()->back()->with('error', $msg)` pada jalur error menjadi:

```php
        $wantsJson = str_contains($this->request->getHeaderLine('Accept'), 'application/json')
            || $this->request->getHeaderLine('X-Requested-With') === 'kiosk-bundle';

        $fail = function (string $message) use ($wantsJson) {
            if ($wantsJson) {
                return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => $message]);
            }
            return redirect()->back()->withInput()->with('error', $message);
        };
```

Ganti setiap `return redirect()->back()->withInput()->with('error', '...')` di `attemptLogin` dengan `return $fail('...')` (pesan sama persis). Di ujung sukses (setelah `session()->set([...])` dan log), ganti redirect role menjadi:

```php
        if ($wantsJson) {
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Login berhasil.',
                'user' => [
                    'id' => (int) session('user_id'),
                    'username' => session('username'),
                    'firstname' => session('firstname'),
                    'lastname' => session('lastname'),
                ],
            ]);
        }

        return $this->redirectByRole();
```

Catatan: akun nonaktif / terkunci / password salah — pesan & status HTTP tetap lewat `$fail` (401). Jangan sentuh `redirectByRole()`.

- [ ] **Step 5: Tambah env CORS origin**

`.env.example` (baris setelah `CONTAINER_*`):
```
CORS_ALLOWED_ORIGINS=https://appassets.androidplatform.net
```
`.env` (dev) — append origin kiosk ke nilai yang sudah ada, pisah koma:
```
CORS_ALLOWED_ORIGINS=https://appassets.androidplatform.net
```

- [ ] **Step 6: Lint + verifikasi manual**

Run:
```bash
docker compose exec ex_php php -l src/app/Config/Cookie.php
docker compose exec ex_php php -l src/app/Config/Session.php
docker compose exec ex_php php -l src/app/Filters/KioskOriginCsrfFilter.php
docker compose exec ex_php php -l src/app/Config/Filters.php
docker compose exec ex_php php -l src/app/Controllers/Auth/AuthController.php
docker compose exec ex_php php -r 'echo PHP_VERSION;'
```
Expected: semua `No syntax errors`.

Verifikasi cookie SameSite: `docker compose exec ex_php curl -s -D - -o /dev/null -X POST https://<host>/login -H "Origin: https://appassets.androidplatform.net" -H "Accept: application/json" -d "username=...&password=..." 2>/dev/null` — lihat `Set-Cookie` berisi `SameSite=None; secure`. (Dev: bisa via localhost + header Host.) Setidaknya pastikan tidak error PHP.

- [ ] **Step 7: Commit**

```bash
git add src/app/Config/Cookie.php src/app/Config/Session.php src/app/Filters/KioskOriginCsrfFilter.php src/app/Config/Filters.php src/app/Controllers/Auth/AuthController.php .env.example
git commit -m "feat(auth): kiosk-origin CSRF exemption, SameSite=None cookie, JSON login response"
```
Jangan commit `.env`.

---

### Task 2: API siswa — exams (+active_attempt), results, review

**Files:**
- Create: `src/app/Controllers/Api/StudentApiController.php`
- Modify: `src/app/Config/Routes.php` (grup `api`)

**Interfaces:**
- Produces: `GET /api/student/exams` → `{status, exams:[{id,name,exam_mode,duration_minutes,begin_time,end_time,status,attempt_status,password_required,can_show_score,can_allow_review,is_repeatable,show_menu,allow_noanswer}], active_attempt:{test_id, attempt_id}|null}`; `GET /api/student/results` → `{status, results:[{test_id,test_name,score,max_score,passed,status,finished_at}]}`; `GET /api/student/review?test_id=X` → `{status, test:{...}, show_score, show_correct, allow_review, summary:{total,correct,wrong,unanswered,score,max_score}, questions:[{question_id,question_text,question_type,is_unsure,user_answers:[...],correct_answers:[...],is_correct,score}]}`.

- [ ] **Step 1: Buat controller**

`src/app/Controllers/Api/StudentApiController.php`:

```php
<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\SettingModel;

class StudentApiController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
    }

    private function requireUser(): ?int
    {
        $userId = session('user_id');
        if (!$userId) {
            return null;
        }
        return (int) $userId;
    }

    public function exams()
    {
        $userId = $this->requireUser();
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Sesi berakhir. Silakan login kembali.']);
        }

        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        $sql = "
            SELECT DISTINCT t.id, t.name, t.exam_mode, t.duration_minutes, t.begin_time, t.end_time,
                   t.password, t.is_repeatable, t.show_menu, t.allow_noanswer,
                   t.show_score_after_exam, t.allow_review,
                   (SELECT status FROM test_attempts ta WHERE ta.test_id = t.id AND ta.user_id = ? ORDER BY ta.id DESC LIMIT 1) as attempt_status
            FROM tests t
            JOIN test_groups tg ON tg.test_id = t.id
            JOIN user_groups ug ON ug.group_id = tg.group_id
            WHERE ug.user_id = ?
              AND t.is_enabled = 1
              AND t.deleted_at IS NULL
            ORDER BY t.created_at DESC
        ";
        $tests = $db->query($sql, [$userId, $userId])->getResult();

        $settingModel = new SettingModel();
        $globalShowScore = (bool) $settingModel->getValue('show_score_after_exam', false);
        $globalAllowReview = (bool) $settingModel->getValue('allow_review', false);

        $activeAttempt = null;
        $exams = [];
        foreach ($tests as $t) {
            // ikuti pola DashboardController: nilai per-test menang, fallback ke global
            if ($t->show_score_after_exam !== null) {
                $canShowScore = (bool) $t->show_score_after_exam;
            } else {
                $canShowScore = $globalShowScore;
            }
            if ($t->allow_review !== null) {
                $canAllowReview = (bool) $t->allow_review;
            } else {
                $canAllowReview = $globalAllowReview;
            }

            $exams[] = [
                'id' => (int) $t->id,
                'name' => $t->name,
                'exam_mode' => $t->exam_mode,
                'duration_minutes' => (int) $t->duration_minutes,
                'begin_time' => $t->begin_time,
                'end_time' => $t->end_time,
                'password_required' => !empty($t->password),
                'is_repeatable' => (int) $t->is_repeatable,
                'show_menu' => (int) $t->show_menu,
                'allow_noanswer' => (int) $t->allow_noanswer,
                'attempt_status' => $t->attempt_status !== null ? (int) $t->attempt_status : null,
                'can_show_score' => $canShowScore,
                'can_allow_review' => $canAllowReview,
            ];

            if ($t->attempt_status !== null && (int) $t->attempt_status === 1 && !$activeAttempt) {
                $activeAttempt = ['test_id' => (int) $t->id, 'attempt_status' => 1];
            }
        }

        // attempt aktif: test_logs sudah dibuat, status 1
        if (!$activeAttempt) {
            $row = $db->table('test_attempts')
                ->select('test_id')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->orderBy('id', 'DESC')
                ->get()->getRow();
            if ($row) {
                $activeAttempt = ['test_id' => (int) $row->test_id, 'attempt_status' => 1];
            }
        }

        return $this->response->setJSON([
            'status' => 'success',
            'exams' => $exams,
            'active_attempt' => $activeAttempt,
        ]);
    }

    public function results()
    {
        $userId = $this->requireUser();
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error']);
        }

        $db = \Config\Database::connect();
        $rows = $db->query("
            SELECT ta.id as attempt_id, ta.test_id, t.name as test_name, ta.score, t.max_score,
                   ta.status, ta.finished_at
            FROM test_attempts ta
            JOIN tests t ON t.id = ta.test_id
            WHERE ta.user_id = ? AND ta.status IN (3, 4)
            ORDER BY ta.finished_at DESC
        ", [$userId])->getResult();

        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'attempt_id' => (int) $r->attempt_id,
                'test_id' => (int) $r->test_id,
                'test_name' => $r->test_name,
                'score' => $r->score !== null ? (float) $r->score : null,
                'max_score' => $r->max_score !== null ? (float) $r->max_score : null,
                'status' => (int) $r->status,
                'finished_at' => $r->finished_at,
            ];
        }

        return $this->response->setJSON(['status' => 'success', 'results' => $results]);
    }

    public function review()
    {
        $userId = $this->requireUser();
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error']);
        }

        $testId = (int) $this->request->getGet('test_id');
        if ($testId <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'test_id diperlukan.']);
        }

        $test = $this->testModel->find($testId);
        if (!$test) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Ujian tidak ditemukan.']);
        }

        $attempt = $this->attemptModel->where('test_id', $testId)->where('user_id', $userId)->orderBy('id', 'DESC')->first();
        if (!$attempt) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Anda belum mengerjakan ujian ini.']);
        }
        if ((int) $attempt->status !== 3) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'Ujian ini belum selesai.']);
        }

        $showScore = $test->show_score_after_exam !== null ? (bool) $test->show_score_after_exam : (bool) (new SettingModel())->getValue('show_score_after_exam', true);
        $showCorrect = $test->show_correct_answers !== null ? (bool) $test->show_correct_answers : (bool) (new SettingModel())->getValue('show_correct_answers', false);
        $allowReview = $test->allow_review !== null ? (bool) $test->allow_review : (bool) (new SettingModel())->getValue('allow_review', true);

        // Copy struktur ResultController::view + review() ke JSON (lihat file referensi,
        // baris 30-210): summary counts, per-question data, kunci jawaban hanya bila $showCorrect.
        // --- referensi data ---
        $db = \Config\Database::connect();
        $logs = $db->table('test_logs')->where('test_attempt_id', $attempt->id)->orderBy('display_order', 'ASC')->get()->getResult();

        $summary = ['total' => count($logs), 'correct' => 0, 'wrong' => 0, 'unanswered' => 0, 'score' => (float) ($attempt->score ?? 0), 'max_score' => (float) ($test->max_score ?? 0)];
        $questions = [];
        foreach ($logs as $log) {
            $q = [
                'question_id' => (int) $log->question_id,
                'question_text' => $log->question_text,
                'question_type' => (int) $log->question_type,
                'is_unsure' => (int) ($log->is_unsure ?? 0),
                'score' => (float) ($log->score ?? 0),
                'user_answers' => [],
                'correct_answers' => [],
            ];
            if ((int) $log->question_type === 3) {
                $q['user_answers'] = trim((string) ($log->answer_text ?? '')) === '' ? [] : [['text' => $log->answer_text]];
                if (empty($q['user_answers'])) {
                    $summary['unanswered']++;
                } elseif ($log->score > 0) {
                    $summary['correct']++;
                } else {
                    $summary['wrong']++;
                }
            } else {
                $raw = $db->table('test_log_answers')->where('test_log_id', $log->id)->orderBy('display_order', 'ASC')->get()->getResult();
                $userSel = []; $correctSet = [];
                foreach ($raw as $a) {
                    if ($showCorrect && (int) $a->is_correct === 1) {
                        $correctSet[] = ['answer_id' => (int) $a->answer_id, 'answer_text' => $a->answer_text];
                    }
                    if ((int) $a->is_selected === 1) {
                        $userSel[] = ['answer_id' => (int) $a->answer_id, 'answer_text' => $a->answer_text];
                    }
                }
                $q['user_answers'] = $userSel;
                $q['correct_answers'] = $correctSet;
                if (empty($userSel)) {
                    $summary['unanswered']++;
                } elseif ($log->score > 0) {
                    $summary['correct']++;
                } else {
                    $summary['wrong']++;
                }
            }
            $questions[] = $q;
        }

        return $this->response->setJSON([
            'status' => 'success',
            'test' => ['id' => (int) $test->id, 'name' => $test->name, 'max_score' => (float) ($test->max_score ?? 0)],
            'show_score' => (bool) $showScore,
            'show_correct' => (bool) $showCorrect,
            'allow_review' => (bool) $allowReview,
            'summary' => $summary,
            'questions' => $questions,
        ]);
    }
}
```

Catatan implementer: cek field `is_correct` ada di tabel `test_log_answers` (`SHOW COLUMNS FROM test_log_answers`); jika kolomnya bernama lain (`is_selected` + scoring), sesuaikan query `$correctSet` dengan mengambil `answer_id` dari kolom kunci sebenarnya (lihat ResultController::review di file yang sama). Hasil akhir API HARUS memiliki bentuk `correct_answers` per soal.

- [ ] **Step 2: Daftarkan rute**

`src/app/Config/Routes.php` — grup api (di dekat baris 24-30):
```php
    $routes->get('student/exams', 'Api\StudentApiController::exams');
    $routes->get('student/results', 'Api\StudentApiController::results');
    $routes->get('student/review', 'Api\StudentApiController::review');
```

- [ ] **Step 3: Lint**

Run: `docker compose exec ex_php php -l src/app/Controllers/Api/StudentApiController.php && docker compose exec ex_php php -l src/app/Config/Routes.php`
Expected: `No syntax errors`.

- [ ] **Step 4: Smoke test via login JSON + curl (dev)**

```bash
# login → cookie jar → panggil exams
docker compose exec ex_php curl -s -c /tmp/cj -X POST http://localhost/login \
  -H "Origin: https://appassets.androidplatform.net" -H "Accept: application/json" \
  -d "username=<akun siswa>&password=<pass>" && echo
docker compose exec ex_php curl -s -b /tmp/cj http://localhost/api/student/exams && echo
docker compose exec ex_php curl -s -b /tmp/cj "http://localhost/api/student/review?test_id=1" && echo
```
Expected: `status:success` + array exams/attempt; review → summary + questions (ganti test_id dengan ujian yang sudah selesai).

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Api/StudentApiController.php src/app/Config/Routes.php
git commit -m "feat(api): student exams/results/review JSON endpoints with active_attempt"
```

---

### Task 3: API `api/exam/start`

**Files:**
- Modify: `src/app/Controllers/Api/ExamApiController.php` (tambah method `start`)
- Modify: `src/app/Config/Routes.php`

**Interfaces:**
- Consumes: `ExamService::generateAttempt(int testId, int userId, string ip)` (lihat `Student\ExamController::start` baris 116-122).
- Produces: `POST /api/exam/start` → `{status:'success', attempt_id}` | `{status:'error', message}` | `{status:'need_login'}`.

- [ ] **Step 1: Tambah method start**

Di `src/app/Controllers/Api/ExamApiController.php` (sebelum `autosave()`), salin logika `Student\ExamController::start` (baris 76-124) sebagai `start()` dengan perubahan: parameter `test_id` dari `getPost`, dan semua `redirect()->...` diganti response JSON:

```php
    public function start()
    {
        $userId = session('user_id');
        if (!$userId) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Silakan login terlebih dahulu.']);
        }

        $testId = (int) ($this->request->getPost('test_id') ?? 0);
        if ($testId <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'test_id diperlukan.']);
        }

        $test = $this->testModel->findCached($testId);
        if (!$test || !$test->is_enabled) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Ujian tidak valid.']);
        }

        $password = (string) ($this->request->getPost('password') ?? '');
        if (!empty($test->password) && !hash_equals($test->password, $password)) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Password ujian salah.']);
        }

        $activeAttempt = $this->attemptModel->getActiveAttemptCached($testId, $userId);
        if ($activeAttempt) {
            return $this->response->setJSON(['status' => 'success', 'attempt_id' => (int) $activeAttempt->id, 'resumed' => true]);
        }

        if (empty($test->is_repeatable)) {
            $completed = $this->attemptModel->where('user_id', $userId)
                                             ->where('test_id', $testId)
                                             ->whereIn('status', [3, 4])
                                             ->first();
            if ($completed) {
                return $this->response->setJSON(['status' => 'error', 'message' => 'Ujian ini hanya dapat dikerjakan satu kali.']);
            }
        }

        $examService = new \App\Libraries\ExamService();
        $attempt = $examService->generateAttempt($testId, (int) $userId, $this->request->getIPAddress());

        if (!$attempt) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Ujian ini hanya dapat dikerjakan satu kali atau terjadi kesalahan saat menyiapkan ujian.']);
        }

        $this->activityLog->log('start_exam', $userId, 'test', $testId, 'Memulai ujian (kiosk bundle)');

        return $this->response->setJSON(['status' => 'success', 'attempt_id' => (int) $attempt->id, 'resumed' => false]);
    }
```

- [ ] **Step 2: Rute**

`src/app/Config/Routes.php`:
```php
    $routes->post('exam/start', 'Api\ExamApiController::start');
```

- [ ] **Step 3: Lint + smoke**

Run: `docker compose exec ex_php php -l src/app/Controllers/Api/ExamApiController.php && docker compose exec ex_php php -l src/app/Config/Routes.php`
Expected: `No syntax errors`.

Smoke (pakai cookie jar dari Task 1):
```bash
docker compose exec ex_php curl -s -b /tmp/cj -X POST http://localhost/api/exam/start \
  -H "Origin: https://appassets.androidplatform.net" \
  -d "test_id=<id ujian belum dikerjakan>" && echo
docker compose exec ex_php curl -s -b /tmp/cj -X POST http://localhost/api/exam/init -d "test_id=<id>" && echo
```
Expected: start → `{status:success, attempt_id}`; init → `status:success` + questions (TIDAK `need_prepare`).

- [ ] **Step 4: Commit**

```bash
git add src/app/Controllers/Api/ExamApiController.php src/app/Config/Routes.php
git commit -m "feat(api): api/exam/start replica of web start flow with JSON response"
```

---

### Task 4: Generator bundle — `cbt:build-ui-bundle`

**Files:**
- Create: `src/app/Libraries/UiBundleBuilder.php`
- Create: `src/app/Commands/BuildUiBundle.php`
- Create: `src/app/Views/bundle/_head.php` (partial: head + kiosk-integration.js PERTAMA + aset)
- Create: `src/app/Views/bundle/login.php`
- Create: `src/app/Views/bundle/dashboard.php`
- Create: `src/app/Views/bundle/exam.php`
- Create: `src/app/Views/bundle/results.php`
- Create: `src/app/Views/bundle/review.php`
- Modify: `src/public/js/kiosk-integration.js` (listener `kiosk_config_ready`)
- Modify: `src/public/assets/exam-app.js` (hook bundle-mode: tunggu `__bundleConfigPromise`)

**Interfaces:**
- Produces: `App\Libraries\UiBundleBuilder::build(): array{version:string, size:int, path:string}`; command `spark cbt:build-ui-bundle`; output ke `public/ui-bundle/` (`{login,dashboard,exam,results,review}.html`, `assets/`, `manifest.json`, `ui-bundle.zip`); gate size: >300KB → `CLI::error` + exit 1.
- Konsumen (Task 5): `KioskController::config()` membaca `public/ui-bundle/manifest.json`.

- [ ] **Step 1: Partial head — kiosk-integration.js PERTAMA**

`src/app/Views/bundle/_head.php` — partial yang dipanggil tiap halaman; `$pageTitle`, `$assetVersion` (sha256 12-char file assets — hitung di builder), `$baseUrl` (server base):
```php
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= esc($pageTitle) ?> — Kiosk CBT</title>
    <script src="<?= $baseUrl ?>/js/kiosk-integration.js"></script>
    <link rel="stylesheet" href="assets/exam-app.css?v=<?= esc($assetVersion) ?>">
    <script>
        window.KIOSK_BUNDLE = true;
        window.KIOSK_BASE_URL = <?= json_encode($baseUrl) ?>;
    </script>
    <style>
        :root { --kiosk-bg: #f1f5f9; --kiosk-card: #ffffff; --kiosk-ink: #0f172a;
                --kiosk-primary: #2563eb; --kiosk-border: #e2e8f0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: var(--kiosk-bg); color: var(--kiosk-ink); }
        .k-card { background: var(--kiosk-card); border: 1px solid var(--kiosk-border); border-radius: 12px; padding: 20px; }
        .k-btn { display: inline-block; background: var(--kiosk-primary); color: #fff; border: 0;
                 border-radius: 10px; padding: 12px 18px; font-size: 16px; width: 100%; }
        .k-btn:disabled { opacity: .5; }
        .k-input { width: 100%; padding: 12px; border: 1px solid var(--kiosk-border); border-radius: 10px; font-size: 16px; }
        .k-error { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px; }
    </style>
</head>
```

- [ ] **Step 2: Halaman login**

`src/app/Views/bundle/login.php`:
```php
<?= view('bundle/_head', ['pageTitle' => 'Login', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:420px;margin:8vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Login Ujian</h2>
        <p id="err" class="k-error" style="display:none"></p>
        <form id="loginForm">
            <label for="username">Username</label>
            <input class="k-input" id="username" name="username" autocomplete="username" required>
            <label for="password" style="margin-top:12px;display:block">Password</label>
            <input class="k-input" id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="k-btn" id="btn" style="margin-top:18px" type="submit">Masuk</button>
        </form>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        localStorage.setItem('kiosk_base_url', base);
        var form = document.getElementById('loginForm');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('btn');
            var err = document.getElementById('err');
            btn.disabled = true; err.style.display = 'none';
            fetch(base + '/login', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'kiosk-bundle'
                },
                body: 'username=' + encodeURIComponent(document.getElementById('username').value) +
                      '&password=' + encodeURIComponent(document.getElementById('password').value)
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                  if (res.j.status === 'success') {
                      location.href = 'dashboard.html';
                  } else {
                      err.textContent = res.j.message || 'Login gagal.';
                      err.style.display = 'block';
                      btn.disabled = false;
                  }
              }).catch(function () {
                  err.textContent = 'Tidak dapat terhubung ke server.';
                  err.style.display = 'block';
                  btn.disabled = false;
              });
        });
    })();
</script>
</body></html>
```

- [ ] **Step 3: Halaman dashboard**

`src/app/Views/bundle/dashboard.php` — daftar ujian dari `/api/student/exams`; resume otomatis bila `active_attempt` ada (auto-redirect ke `exam.html`):
```php
<?= view('bundle/_head', ['pageTitle' => 'Dashboard', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:640px;margin:4vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Daftar Ujian</h2>
        <div id="list">Memuat...</div>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        fetch(base + '/api/student/exams', { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { window.location.href = 'login.html'; return; }
                if (j.active_attempt) { window.location.href = 'exam.html?test_id=' + j.active_attempt.test_id + '&resume=1'; return; }
                var list = document.getElementById('list');
                if (!j.exams || !j.exams.length) { list.innerHTML = '<p>Tidak ada ujian tersedia.</p>'; return; }
                list.innerHTML = '';
                j.exams.forEach(function (t) {
                    var card = document.createElement('div');
                    card.style.cssText = 'border:1px solid var(--kiosk-border);border-radius:10px;padding:14px;margin-bottom:12px';
                    var status = t.attempt_status === 3 ? ' (Selesai)' : '';
                    var btn = '<button class="k-btn" style="width:auto;padding:10px 16px" onclick="window.location.href=\'exam.html?test_id=' + t.id + '\'">Kerjakan</button>';
                    if (t.attempt_status === 3) { btn = '<button class="k-btn" style="width:auto;padding:10px 16px;background:#64748b" onclick="window.location.href=\'results.html?test_id=' + t.id + '\'">Lihat Hasil</button>'; }
                    card.innerHTML = '<h3 style="margin:0 0 4px">' + t.name + status + '</h3>' +
                        '<p style="margin:0 0 10px;color:#475569;font-size:14px">Durasi: ' + t.duration_minutes + ' menit</p>' + btn;
                    list.appendChild(card);
                });
            })
            .catch(function () {
                document.getElementById('list').innerHTML =
                    '<div class="k-error">Tidak dapat terhubung ke server. <button class="k-btn" style="width:auto;margin-top:8px" onclick="location.reload()">Coba Lagi</button></div>';
            });
    })();
</script>
</body></html>
```

- [ ] **Step 4: Halaman exam (renderer dari init)**

`src/app/Views/bundle/exam.php` — varian static exam tanpa data inline; data dari `/api/exam/init` di runtime, lalu hook `__bundleConfigPromise` yang dikonsumsi exam-app.js (Step 7):
```php
<?= view('bundle/_head', ['pageTitle' => 'Ujian', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body class="noselect">
<div id="loading-screen" class="loading-screen">Memuat soal...</div>
<div id="exam-app" x-data="examApp()" x-show="ready" style="display:none">
    <!-- kerangka UI ujian identik dengan static_exam_template.php bagian body (bagian 2),
         render soal via Alpine dari window.questionsData / window.answersData -->
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(window.location.search);
        var testId = params.get('test_id') || '';

        window.__bundleConfigPromise = (function () {
            var ready = function (j) {
                if (j.status !== 'success') {
                    throw new Error(j.message || 'Gagal memuat soal');
                }
                // mapping penuh — kunci persis yang dibaca exam-app.js
                // (kontrak lihat static_exam_template.php :200-221):
                window.EXAM_CONFIG = {
                    testId: j.test.id,
                    testName: j.test.name,
                    durationMinutes: j.test.duration_minutes,
                    passingScore: j.test.passing_score,
                    maxScore: j.test.max_score,
                    showMenu: j.test.show_menu,
                    allowNoanswer: j.test.allow_noanswer,
                    autoLogoutOnTimeout: j.test.auto_logout_on_timeout,
                    hasPassword: false,
                    antiCheat: j.anti_cheat || {},
                    apiBaseUrl: base,
                    appBaseUrl: base,
                    questionsData: j.questions || [],
                    answersData: j.answers || {},
                    wsUrl: '',
                    csrfName: j.csrf_name,
                    csrfToken: j.csrf_token,
                    attemptId: j.attempt_id
                };
                window.questionsData = window.EXAM_CONFIG.questionsData;
                window.answersData = window.EXAM_CONFIG.answersData;
                window.CBT_EXAM_CONFIG = { examId: String(j.test.id), token: j.ws_token || '' };
                window.dispatchEvent(new Event('kiosk_config_ready'));
                return true;
            };
            var init = function () {
                return fetch(base + '/api/exam/init?test_id=' + encodeURIComponent(testId), { credentials: 'include', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j.status === 'need_prepare') {
                            // belum ada attempt → buat dulu, lalu init ulang (sekali saja)
                            return fetch(base + '/api/exam/start', {
                                method: 'POST',
                                credentials: 'include',
                                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'test_id=' + encodeURIComponent(testId)
                            }).then(function (r) { return r.json(); })
                              .then(function (s) {
                                  if (s.status !== 'success') { throw new Error(s.message || 'Gagal memulai ujian.'); }
                                  return init();
                              });
                        }
                        if (j.status === 'error' && j.action === 'logout') { window.location.href = 'login.html'; return null; }
                        return ready(j);
                    });
            };
            return init();
        })();

        window.__bundleConfigPromise.catch(function (e) {
            document.getElementById('loading-screen').textContent = e && e.message ? e.message : 'Gagal memuat soal';
        });
    })();
</script>
<script src="assets/exam-app.js?v=<?= esc($assetVersion) ?>"></script>
</body></html>
```

Catatan: isi body `#exam-app` (navigasi soal, pilihan jawaban, essay, matching, timer, tombol selesai) adalah **port** dari `static_exam_template.php` baris 261-434 (blok `x-data="examApp()"` sampai sebelum skrip fallback) dengan aturan:
- SEMUA class bootstrap (`offcanvas`, `d-none`, `text-*`, `fw-bold`, `modal`, `btn-*` bootstrap dll.) dihapus. Gantikan dengan class yang sudah ada di `exam-app.css` (`.answer-option`, `.btn-nav`, `.exam-timer-chip`, `.btn-end-exam`, `.btn-flag`, `.loading-screen`, `.noselect`) + sediakan CSS shim mini inline untuk sisanya (komponen yang disebut class bootstrap): header bar, sidebar navigasi, grid soal jadi panel fixed kanan, row/col jadi flex. Swal menyuplai styling sendiri.
- TIDAK memuat jquery/bootstrap/katex/quill — bagian skrip katex/ensureMath (template :99-158) dilewati (tanpa katex; `renderMath` dipanggil exam-app.js hanya bila ada — lihat Step 7 hook).
- Sertakan vendor yang WAJIB untuk exam-app.js: `<script src="assets/alpine.min.js?v=..."></script>` dan `<script src="assets/sweetalert2.min.js?v=..."></script>` (defer, setelah kiosk-integration.js & app.js).
- Tombol "Selesai" memanggil fungsi yang sudah ada di exam-app.js; setelah sukses → `location.href = 'results.html?test_id=' + window.EXAM_CONFIG.testId`. Tambahkan di akhir aksi finish: `window.CommsBridge && window.CommsBridge.setExamActive(false);` dan saat soal pertama dirender: `window.CommsBridge && window.CommsBridge.setExamActive(true);` (lihat Task 6 Step 2).

- [ ] **Step 5: Halaman results + review**

`src/app/Views/bundle/results.php`:
```php
<?= view('bundle/_head', ['pageTitle' => 'Hasil', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:640px;margin:4vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Hasil Ujian</h2>
        <div id="summary">Memuat...</div>
        <button class="k-btn" style="margin-top:16px" onclick="window.location.href='dashboard.html'">Kembali</button>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(window.location.search);
        fetch(base + '/api/student/review?test_id=' + encodeURIComponent(params.get('test_id') || ''), { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { document.getElementById('summary').innerHTML = '<p>' + (j.message || 'Gagal memuat hasil.') + '</p>'; return; }
                var el = document.getElementById('summary');
                if (j.show_score) {
                    el.innerHTML = '<p style="font-size:22px;margin:0">Skor: <b>' + j.summary.score + ' / ' + j.summary.max_score + '</b></p>' +
                        '<p style="color:#475569">Benar: ' + j.summary.correct + ' · Salah: ' + j.summary.wrong + ' · Kosong: ' + j.summary.unanswered + '</p>';
                } else {
                    el.innerHTML = '<p>Skor tidak ditampilkan.</p>';
                }
                if (j.allow_review) {
                    el.innerHTML += '<button class="k-btn" style="margin-top:12px" onclick="window.location.href=\'review.html?test_id=' + params.get('test_id') + '\'">Review Jawaban</button>';
                }
            })
            .catch(function () { document.getElementById('summary').innerHTML = '<div class="k-error">Tidak dapat terhubung ke server.</div>'; });
    })();
</script>
</body></html>
```

`src/app/Views/bundle/review.php` — daftar soal + jawaban user (dan kunci bila `show_correct`):
```php
<?= view('bundle/_head', ['pageTitle' => 'Review', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:640px;margin:4vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Review Jawaban</h2>
        <div id="review">Memuat...</div>
        <button class="k-btn" style="margin-top:16px" onclick="window.location.href='dashboard.html'">Kembali</button>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(window.location.search);
        fetch(base + '/api/student/review?test_id=' + encodeURIComponent(params.get('test_id') || ''), { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { document.getElementById('review').innerHTML = '<p>' + (j.message || 'Gagal memuat review.') + '</p>'; return; }
                if (!j.allow_review) { document.getElementById('review').innerHTML = '<p>Review tidak tersedia.</p>'; return; }
                var el = document.getElementById('review');
                el.innerHTML = '';
                j.questions.forEach(function (q, i) {
                    var user = (q.user_answers || []).map(function (a) { return a.answer_text; }).join(', ') || '(kosong)';
                    var correct = (q.correct_answers || []).map(function (a) { return a.answer_text; }).join(', ');
                    var html = '<div style="border:1px solid var(--kiosk-border);border-radius:10px;padding:14px;margin-bottom:12px">' +
                        '<h3 style="margin:0 0 6px">' + (i + 1) + '. ' + q.question_text + '</h3>' +
                        '<p style="margin:4px 0;color:#475569">Jawaban Anda: <b>' + user + '</b></p>';
                    if (j.show_correct && correct) { html += '<p style="margin:4px 0;color:#15803d">Kunci: ' + correct + '</p>'; }
                    html += '</div>';
                    el.innerHTML += html;
                });
            })
            .catch(function () { document.getElementById('review').innerHTML = '<div class="k-error">Tidak dapat terhubung ke server.</div>'; });
    })();
</script>
</body></html>
```

- [ ] **Step 6: Builder library + command**

`src/app/Libraries/UiBundleBuilder.php`:

```php
<?php

namespace App\Libraries;

use CodeIgniter\CLI\CLI;

class UiBundleBuilder
{
    public const OUT_DIR = 'ui-bundle';
    public const SIZE_BUDGET_BYTES = 300 * 1024; // < 300KB zip = gate

    /**
     * @return array{version:string, size:int, path:string}
     */
    public function build(): array
    {
        $outDir = FCPATH . self::OUT_DIR;
        $assetsDir = $outDir . '/assets';
        $assetsSrc = FCPATH . 'assets';
        $vendorSrc = FCPATH . 'vendor/alpinejs';

        @mkdir($outDir, 0755, true);
        @mkdir($assetsDir, 0755, true);

        $baseUrl = rtrim(base_url(), '/');
        $assetVersion = substr(hash_file('sha256', $assetsSrc . '/exam-app.js'), 0, 12);

        // 1) render halaman
        $pages = [
            'login.html'    => 'bundle/login',
            'dashboard.html' => 'bundle/dashboard',
            'exam.html'     => 'bundle/exam',
            'results.html'  => 'bundle/results',
            'review.html'   => 'bundle/review',
        ];
        foreach ($pages as $file => $view) {
            $html = view($view, ['baseUrl' => $baseUrl, 'assetVersion' => $assetVersion]);
            file_put_contents("$outDir/$file", $html);
        }

        // 2) copy assets: exam-app.js, exam-app.css, alpine.min.js, sweetalert2.min.js, kiosk-integration.js
        copy($assetsSrc . '/exam-app.js', "$assetsDir/exam-app.js");
        copy($assetsSrc . '/exam-app.css', "$assetsDir/exam-app.css");
        copy($vendorSrc . '/alpine.min.js', "$assetsDir/alpine.min.js");
        copy(FCPATH . 'vendor/sweetalert2/sweetalert2.min.js', "$assetsDir/sweetalert2.min.js");
        copy(FCPATH . 'js/kiosk-integration.js', "$assetsDir/kiosk-integration.js");

        // 3) manifest per-file sha256 + version (hash canonical manifest)
        $fileHashes = [];
        $files = array_merge(array_keys($pages), [
            'assets/exam-app.js',
            'assets/exam-app.css',
            'assets/alpine.min.js',
            'assets/sweetalert2.min.js',
            'assets/kiosk-integration.js',
        ]);
        sort($files);
        foreach ($files as $rel) {
            $fileHashes[$rel] = hash_file('sha256', "$outDir/$rel");
        }
        $manifest = ['files' => $fileHashes];
        $manifest['version'] = hash('sha256', json_encode($fileHashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents("$outDir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 4) zip (manifest ikut di dalam zip; verify app = per-file hash vs manifest.files)
        $zip = new \ZipArchive();
        $zipPath = "$outDir/ui-bundle.zip";
        @unlink($zipPath);
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal membuat zip bundle.');
        }
        foreach (array_merge($files, ['manifest.json']) as $rel) {
            $zip->addFile("$outDir/$rel", $rel);
        }
        $zip->close();

        $size = (int) filesize($zipPath);
        $version = $manifest['version'];

        CLI::write("Bundle version: {$version}");
        CLI::write(sprintf('Bundle zip size: %d bytes (%d KB)', $size, (int) round($size / 1024)));

        if ($size > self::SIZE_BUDGET_BYTES) {
            throw new \RuntimeException(sprintf('SIZE BUDGET FAILED: %d bytes > %d bytes. Kurangi aset bundle.', $size, self::SIZE_BUDGET_BYTES));
        }
        CLI::write('SIZE BUDGET OK (< 300KB).', 'green');

        return ['version' => $version, 'size' => $size, 'path' => $outDir];
    }
}
```

`src/app/Commands/BuildUiBundle.php`:

```php
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\UiBundleBuilder;

class BuildUiBundle extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:build-ui-bundle';
    protected $description = 'Generate kiosk UI bundle (5 pages + assets + manifest + zip) ke public/ui-bundle/';

    public function run(array $params)
    {
        try {
            (new UiBundleBuilder())->build();
        } catch (\Throwable $e) {
            CLI::error('Build gagal: ' . $e->getMessage());
            exit(1);
        }
    }
}
```

- [ ] **Step 7: Hook bundle-mode di exam-app.js + listener kiosk_config_ready**

Empat perubahan wajib di `src/public/assets/exam-app.js` (temuan verifikasi — tanpa ini bundle break):

1. **`fetchWithTimeout` harus kirim cookie lintas origin** (spes: `credentials: 'include'`). Cari `credentials: 'same-origin'` (baris ~55) → ganti:
```js
            credentials: 'include',
```
2. **Gate "assets siap"** (baris ~190, `if (window.jQuery && window.Alpine)`) — bundle tidak punya jQuery; kalau gate ini gagal, loading screen tak pernah hilang. Ganti kondisi:
```js
                if (window.__KIOSK_BUNDLE__ || (window.jQuery && window.Alpine)) {
```
3. **Skip init internal di bundle + tunggu config dari exam.html** — di awal fungsi init async yang memanggil `/api/exam/init` dan menangani `need_prepare`/redirect prepare-take (baris ~90-120), tambahkan:
```js
        if (window.__KIOSK_BUNDLE__) {
            const ok = await window.__bundleConfigPromise;
            if (!ok) { window.location.href = 'login.html'; return; }
            return; // config sudah diset exam.html: lanjut render Alpine
        }
```
(`__bundleConfigPromise` didefinisikan exam.html Step 4; `need_prepare` → `exam/start` → init ulang sudah ditangani di sana. Fungsi ini dibungkus `async` bila belum.)
4. **Redirect WS-resume/ban di bundle** — di tempat yang melakukan `window.location.href = API + '/student/exam/take/' + ...` (sekitar baris 449, 550, 633 — event ws resume/ban), ganti:
```js
                            const target = window.__KIOSK_BUNDLE__
                                ? 'exam.html?test_id=' + EXAM_CONFIG.testId + '&resume=1'
                                : API + '/student/exam/take/' + EXAM_CONFIG.testId;
                            window.location.href = target;
```
Jangan sentuh jalur `!__KIOSK_BUNDLE__` (web tetap sama).

`src/public/js/kiosk-integration.js` — tambahkan listener (di dalam `DOMContentLoaded` yang sudah ada, setelah blok `CommsBridge` start, sekitar baris 31):

```js
    // Kiosk bundle mode: config tersedia setelah exam/init resolve.
    window.addEventListener("kiosk_config_ready", function() {
        if (window.CommsBridge && window.CBT_EXAM_CONFIG && !window.CBT_EXAM_FINISHED) {
            window.CommsBridge.startKiosk(
                window.CBT_EXAM_CONFIG.examId || "0",
                window.CBT_EXAM_CONFIG.token || ""
            );
        }
    });
```

Verifikasi: `node --check src/public/assets/exam-app.js && node --check src/public/js/kiosk-integration.js`.

- [ ] **Step 8: Lint + build + size gate**

Run:
```bash
docker compose exec ex_php php -l src/app/Libraries/UiBundleBuilder.php
docker compose exec ex_php php -l src/app/Commands/BuildUiBundle.php
node --check src/public/assets/exam-app.js
node --check src/public/js/kiosk-integration.js
docker compose exec ex_php php spark cbt:build-ui-bundle
ls -la public/ui-bundle/
```
Expected: semua lint pass; command print `Bundle version: <64-hex>`, `Bundle zip size: ...`, `SIZE BUDGET OK`; dir berisi 5 html + assets/ + manifest.json + ui-bundle.zip. Buka `public/ui-bundle/login.html` di browser (via `python3 -m http.server` + akses localhost) — halaman tampil, tombol login muncul (error jaringan wajar tanpa server host).

- [ ] **Step 9: Commit**

```bash
git add src/app/Libraries/UiBundleBuilder.php src/app/Commands/BuildUiBundle.php src/app/Views/bundle/ src/public/js/kiosk-integration.js src/public/assets/exam-app.js
git commit -m "feat(bundle): cbt:build-ui-bundle generator with size gate, bundle-mode hooks, kiosk_config_ready listener"
```

---

### Task 5: Serve bundle via Nginx + `ui_bundle` di kiosk config

**Files:**
- Modify: `docker/nginx/default.conf` (tambah location setelah blok `^~ /static/` baris ~131-139)
- Modify: `src/app/Controllers/Api/KioskController.php` (method `config` baris 14-31)

**Interfaces:**
- Consumes: `UiBundleBuilder` output (`public/ui-bundle/manifest.json`, `ui-bundle.zip`).
- Produces: `GET /ui-bundle/ui-bundle.zip` via Nginx (Range supported native); `GET /api/kiosk/config` + field `ui_bundle: {version, url, size}`.

- [ ] **Step 1: Nginx location**

`docker/nginx/default.conf`, setelah blok `location ^~ /static/`:

```nginx
    # Kiosk UI bundle — must-revalidate agar update bundle tidak ter-cache lama
    location ^~ /ui-bundle/ {
        add_header Cache-Control "public, max-age=0, must-revalidate";
        try_files $uri =404;
    }
```

Reload: `docker compose exec ex_nginx nginx -t && docker compose exec ex_nginx nginx -s reload`

- [ ] **Step 2: KioskController::config + ui_bundle**

Di `Api\KioskController::config()` (setelah `$features`), tambah:

```php
        $bundleInfo = ['version' => '', 'url' => '', 'size' => 0];
        $manifestPath = FCPATH . 'ui-bundle/manifest.json';
        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                $bundleInfo = [
                    'version' => (string) ($manifest['version'] ?? ''),
                    'url'     => base_url('ui-bundle/ui-bundle.zip'),
                    'size'    => (int) (is_file(FCPATH . 'ui-bundle/ui-bundle.zip') ? filesize(FCPATH . 'ui-bundle/ui-bundle.zip') : 0),
                ];
            } catch (\Throwable $e) {
                log_message('error', 'Kiosk config bundle manifest error: ' . $e->getMessage());
            }
        }
```
dan masukkan `'ui_bundle' => $bundleInfo` di array response config.

- [ ] **Step 3: Lint + verify**

Run:
```bash
docker compose exec ex_php php -l src/app/Controllers/Api/KioskController.php
docker compose exec ex_nginx nginx -t
docker compose exec ex_php curl -s http://localhost/api/kiosk/config && echo
docker compose exec ex_php curl -sI http://localhost/ui-bundle/ui-bundle.zip
```
Expected: config berisi `ui_bundle.version` 64-hex; curl -I zip → `HTTP/1.1 200` + `Cache-Control: public, max-age=0, must-revalidate` (+ `Accept-Ranges`).

- [ ] **Step 4: Commit**

```bash
git add docker/nginx/default.conf src/app/Controllers/Api/KioskController.php
git commit -m "feat(bundle): serve ui-bundle via nginx static + expose version in kiosk config"
```

---

### Task 6: UiBundleManager (Android)

**Files:**
- Create: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bundle/UiBundleManager.kt`
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt` (tambah `setExamActive`)

**Interfaces:**
- Consumes: `prefs` (`SharedPreferences` "cbt_kiosk_prefs"), server URL dari prefs `server_url`; bundle info JSON dari `/api/kiosk/config`.
- Produces: `class UiBundleManager(context, prefs, onReady: (Boolean) -> Unit, onError: (String) -> Unit)`; methods: `val examActive: Boolean` (+setter, dipakai `CommsBridge.setExamActive`), `fun canRefresh(): Boolean` (gate: !examActive), `fun localVersion(): String?` (hash dari manifest lokal), `fun downloadViaDownloadManager(serverBaseUrl: String, zipUrl: String, expectedVersion: String)`, `fun verifyAndInstall(zipFile: File): Boolean` (verify per-file + atomic rename), `fun importBundle(uri: Uri): Boolean`.

- [ ] **Step 1: Tulis UiBundleManager**

`cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bundle/UiBundleManager.kt`:

```kotlin
package id.sch.cbt.kiosk.bundle

import android.app.DownloadManager
import android.content.Context
import android.content.SharedPreferences
import android.net.Uri
import android.os.Environment
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * Menyediakan bundle UI lokal:
 *  - download via DownloadManager (resume native) / import dari file picker
 *  - verifikasi sha256 per file vs manifest.json (satu pipeline untuk keduanya)
 *  - extract ke staging dir lalu ATOMIC rename ke ui-bundle/
 */
class UiBundleManager(
    private val context: Context,
    private val prefs: SharedPreferences,
    private val onReady: (Boolean) -> Unit,   // true = bundle siap digunakan
    private val onError: (String) -> Unit
) {
    companion object {
        private const val TAG = "UiBundleManager"
        private const val PREFS_VERSION = "ui_bundle_version"
        private const val PREFS_EXAM_ACTIVE = "ui_exam_active"
        const val BUNDLE_DIR = "ui-bundle"
        const val STAGING_DIR = "ui-bundle.staging"
        const val DL_DIR = "ui-bundle-download"
    }

    private val bundleDir: File get() = File(context.filesDir, BUNDLE_DIR)
    private val stagingDir: File get() = File(context.filesDir, STAGING_DIR)
    private val dlDir: File get() = File(context.filesDir, DL_DIR)

    var examActive: Boolean
        get() = prefs.getBoolean(PREFS_EXAM_ACTIVE, false)
        set(v) {
            prefs.edit().putBoolean(PREFS_EXAM_ACTIVE, v).apply()
            Log.i(TAG, "examActive = $v")
        }

    /** Gate: refresh bundle hanya di cold start / tanpa attempt aktif. */
    fun canRefresh(): Boolean = !examActive

    fun bundleVersion(): String? = prefs.getString(PREFS_VERSION, null)

    /** Baca manifest lokal untuk bandingkan versi. */
    fun localVersion(): String? {
        return try {
            val mf = File(bundleDir, "manifest.json")
            if (!mf.exists()) return null
            JSONObject(mf.readText()).optString("version", "").takeIf { it.isNotBlank() }
        } catch (e: Throwable) {
            Log.w(TAG, "localVersion error", e); null
        }
    }

    /** Panggil saat config server menyebut versi baru / versi belum ada. */
    fun downloadViaDownloadManager(serverBaseUrl: String, zipUrl: String, expectedVersion: String) {
        dlDir.mkdirs()
        val request = DownloadManager.Request(Uri.parse(zipUrl))
            .setTitle("UI Bundle")
            .setDescription("Mengunduh paket UI ujian ($expectedVersion)")
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_HIDDEN)
            .setAllowedOverMetered(true)
            .setDestinationInExternalFilesDir(context, Environment.DIRECTORY_DOWNLOADS, "ui-bundle.zip")
        val dm = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
        dm.enqueue(request)
        Log.i(TAG, "DownloadManager enqueue: $zipUrl (v$expectedVersion)")
    }

    /** Pipeline verify+extract untuk download/import. Return true bila berhasil. */
    @Synchronized
    fun verifyAndInstall(zipFile: File): Boolean {
        return try {
            if (!zipFile.exists()) { onError("File bundle tidak ditemukan."); return false }
            // ekstrak ke staging (hapus staging lama dulu)
            stagingDir.deleteRecursively()
            stagingDir.mkdirs()
            val zin = java.util.zip.ZipInputStream(zipFile.inputStream().buffered())
            var entry = zin.nextEntry
            while (entry != null) {
                val target = File(stagingDir, entry.name)
                if (!target.canonicalPath.startsWith(stagingDir.canonicalPath + File.separator) && entry.name != "manifest.json") {
                    onError("Bundle tidak valid (path traversal)."); zin.close(); return false
                }
                if (!entry.isDirectory) {
                    target.parentFile?.mkdirs()
                    target.outputStream().use { out -> zin.copyTo(out) }
                }
                entry = zin.nextEntry
            }
            zin.close()

            // verifikasi per-file vs manifest
            val mf = File(stagingDir, "manifest.json")
            if (!mf.exists()) { onError("manifest.json tidak ada di bundle."); return false }
            val manifest = JSONObject(mf.readText())
            val files = manifest.getJSONObject("files")
            val expectedVersion = manifest.getString("version")
            val it = files.keys()
            while (it.hasNext()) {
                val rel = it.next()
                val f = File(stagingDir, rel)
                if (!f.exists()) { onError("File hilang di bundle: $rel"); return false }
                val actual = sha256(f.readBytes())
                if (actual != files.getString(rel)) { onError("Hash tidak cocok: $rel"); return false }
            }

            // atomic rename: hapus staging lama bila ada sisa, ganti ui-bundle
            val backup = File(context.filesDir, "ui-bundle.old")
            backup.deleteRecursively()
            if (bundleDir.exists()) bundleDir.renameTo(backup)
            if (!stagingDir.renameTo(bundleDir)) {
                if (backup.exists()) backup.renameTo(bundleDir)
                onError("Gagal menginstal bundle (rename).")
                return false
            }
            backup.deleteRecursively()
            prefs.edit().putString(PREFS_VERSION, expectedVersion).apply()
            zipFile.delete()
            onReady(true)
            Log.i(TAG, "Bundle v$expectedVersion terinstal")
            true
        } catch (e: Throwable) {
            onError("Gagal memproses bundle: ${e.message}")
            false
        }
    }

    private fun sha256(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    fun importBundle(uri: Uri): Boolean {
        return try {
            val tmp = File(context.cacheDir, "import-ui-bundle.zip")
            tmp.delete()
            context.contentResolver.openInputStream(uri)?.use { input ->
                tmp.outputStream().use { output -> input.copyTo(output) }
            } ?: run { onError("Gagal membaca file bundle."); return false }
            verifyAndInstall(tmp)
        } catch (e: Throwable) {
            onError("Gagal import bundle: ${e.message}")
            false
        }
    }
}
```

Catatan: `verifyAndInstall` menolak entry zip dengan `../`/absolute path (guard path traversal); `DownloadManager` menyimpan ke `ExternalFilesDir(DIRECTORY_DOWNLOADS)` agar tidak butuh permission (receiver harus menyalin ke `cacheDir` sebelum `verifyAndInstall` — lihat Step 3).

- [ ] **Step 2: CommsBridge — setExamActive**

`cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt` — tambah `@JavascriptInterface` (file ini sudah memegang `activity: MainActivity` sebagai `private val activity` — gunakan itu):
```kotlin
    @JavascriptInterface
    fun setExamActive(active: Boolean) {
        activity.uiBundleManager.examActive = active
    }
```
Catatan: pastikan `uiBundleManager` adalah properti publik `MainActivity` (Task 7 Step 2), agar `CommsBridge` bisa mengaksesnya.

- [ ] **Step 3: Receiver DownloadManager**

Di `UiBundleManager` atau `MainActivity` — receiver `ACTION_DOWNLOAD_COMPLETE` yang menyambungkan id → file:
```kotlin
// MainActivity.onCreate (Task 7) mendaftarkan:
// registerReceiver(downloadReceiver, IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE))
// downloadReceiver.onReceive: query DownloadManager utk uri per id,
//   salin ke File(context.cacheDir, "dl-ui-bundle.zip"),
//   lalu uiBundleManager.verifyAndInstall(salinan)
```

- [ ] **Step 4: Lint/logic check (build Android)**

Run: `cd cbt-kiosk-app && gradle assembleDebug`
Expected: BUILD SUCCESSFUL. (Belum ada perubahan MainActivity — `uiBundleManager` belum terpasang → Step 2 compile akan gagal sampai Task 7; kerjakan Task 6 dan 7 sebelum build. Ordering: selesaikan Task 6 Step 2 & 3 BERSAMA Task 7, lalu build di Task 7.)

- [ ] **Step 5: Commit**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bundle/UiBundleManager.kt cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/bridge/CommsBridge.kt
git commit -m "feat(kiosk-app): UiBundleManager with verify/atomic-install, exam-active gate, import pipeline"
```

---

### Task 7: MainActivity + manifest — WebViewAssetLoader, bundle load, import flow

**Files:**
- Modify: `cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt`
- Modify: `cbt-kiosk-app/app/src/main/AndroidManifest.xml`
- Modify: `cbt-kiosk-app/app/src/main/res/layout/activity_main.xml` (tambah tombol Import Bundle di setup screen)

**Interfaces:**
- Consumes: `UiBundleManager` (Task 6), `fetchServerKioskConfig` existing (MainActivity ~baris 115-121), prefs `server_url`.
- Produces: WebView memuat `https://appassets.androidplatform.net/login.html?server=<encoded>`; properti `uiBundleManager` dipakai `CommsBridge.setExamActive`.

- [ ] **Step 1: AndroidManifest — intent-filter import zip**

`cbt-kiosk-app/app/src/main/AndroidManifest.xml`, di `<activity android:name=".MainActivity">` (baris 18-32), tambah:
```xml
            <intent-filter>
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:mimeType="application/zip" />
            </intent-filter>
```
Pastikan `android:exported="true"` ada di atribut `<activity>` (wajib karena ada intent-filter).

- [ ] **Step 2: MainActivity — integrasi**

Di `MainActivity.kt`:
1. Property: `lateinit var uiBundleManager: UiBundleManager` (inisialisasi di `onCreate` setelah `prefs`).
2. Di `fetchServerKioskConfig(savedUrl)` / setelah config fetch (tempat `kiosk_min_app_version` disimpan), tambah: ambil `ui_bundle.version` dari JSON config → simpan `serverBundleVersion`; lalu:
```kotlin
val bundleInfo = config.optJSONObject("ui_bundle")
val serverBundleVersion = bundleInfo?.optString("version") ?: ""
if (serverBundleVersion.isBlank()) {
    // server belum generate bundle — tampilkan pesan & jangan lanjut
    Toast.makeText(this, "Bundle UI belum tersedia di server.", Toast.LENGTH_LONG).show()
    return
}
if (uiBundleManager.canRefresh() && uiBundleManager.localVersion() != serverBundleVersion) {
    uiBundleManager.downloadViaDownloadManager(finalUrl, bundleInfo.optString("url") ?: finalUrl + "/ui-bundle/ui-bundle.zip", serverBundleVersion)
    // UI: tampilkan status "Mengunduh bundle..." — lanjut lewat onReady (install selesai)
}
```
(Untuk kesederhanaan rilis awal: bila bundle belum ada (localVersion()==null) dan download dimulai, tunggu `onReady` callback sebelum pindah ke WebView; saat `localVersion()==serverBundleVersion` langsung lanjut.)
3. Ganti pemanggilan load di `startExamAndLockKiosk` (baris ~348): setelah bundle ready:
```kotlin
private fun loadBundleLoginPage(baseUrl: String) {
    val loader = WebViewAssetLoader.Builder()
        .setDomain("appassets.androidplatform.net")
        .addPathHandler("/", WebViewAssetLoader.AssetsPathHandler(assets))
        .build()
    webView.webViewClient = object : WebViewClient() {
        override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? =
            loader.shouldInterceptRequest(request)
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            if (request.url.host == "appassets.androidplatform.net") return false // internal bundle nav
            return true // blok navigasi keluar; server hanya lewat fetch
        }
    }
    webView.loadUrl("https://appassets.androidplatform.net/login.html?server=" + Uri.encode(baseUrl))
}
```
4. Import flow di setup screen (`btnStartExam` area): tombol "Import Bundle (.zip)" → SAF `ACTION_OPEN_DOCUMENT` (mime `application/zip`) → `uiBundleManager.importBundle(uri)` → saat `onReady(true)` → lanjut `startExamAndLockKiosk`.
5. Handler intent `ACTION_VIEW` di `onCreate` (atau `onNewIntent`): `intent.data` + `intent.type == "application/zip"` → langsung `importBundle` lalu mulai exam flow.
6. DownloadManager receiver (lihat Task 6 Step 3): query `DownloadManager.getUriForDownloadedFile(id)` → salin ke `cacheDir/dl-ui-bundle.zip` → `verifyAndInstall`.
7. Tambahkan `uiBundleManager` ke `CommsBridge` konstruktor (sesuaikan pemanggilan di onCreate: `CommsBridge(webView, this, uiBundleManager, ...)` — cek signature existing).

Catatan: bundle di `filesDir`, bukan assets → pakai `InternalStoragePathHandler` (tersedia di androidx.webkit 1.7.0):
```kotlin
.addPathHandler("/", WebViewAssetLoader.InternalStoragePathHandler(context, File(context.filesDir, "ui-bundle")))
```
(path handler ini menangani `ui-bundle/*` sebagai path root `/`; halaman direferensi `login.html` relatif → URL absolut `https://appassets.androidplatform.net/login.html`.)

- [ ] **Step 3: Layout — tombol import**

`activity_main.xml` di area setup (`etServerUrl`/`btnStartExam`), tambah:
```xml
    <Button
        android:id="@+id/btnImportBundle"
        android:layout_width="match_parent"
        android:layout_height="wrap_content"
        android:text="Import Bundle (.zip)"
        ... />
```

- [ ] **Step 4: Build**

Run: `cd cbt-kiosk-app && gradle assembleDebug`
Expected: BUILD SUCCESSFUL. Perbaiki semua error compile sampai sukses (signature `CommsBridge`, `webViewClient` override, unresolved refs).

- [ ] **Step 5: Commit**

```bash
git add cbt-kiosk-app/app/src/main/java/id/sch/cbt/kiosk/MainActivity.kt cbt-kiosk-app/app/src/main/AndroidManifest.xml cbt-kiosk-app/app/src/main/res/layout/activity_main.xml
git commit -m "feat(kiosk-app): load bundled UI via WebViewAssetLoader, import-bundle flow, download receiver"
```

---

### Task 8: Verifikasi E2E (gate terakhir)

**Files:** tidak ada perubahan kode (kecuali temuan bug → perbaiki + commit sendiri).

- [ ] **Step 1: Server E2E via curl (seluruh alur data)**

```bash
# 1. login JSON
docker compose exec ex_php curl -s -c /tmp/cj -X POST http://localhost/login \
  -H "Origin: https://appassets.androidplatform.net" -H "Accept: application/json" \
  -d "username=<siswa>&password=<pass>"
# 2. exams (+ active_attempt)
docker compose exec ex_php curl -s -b /tmp/cj http://localhost/api/student/exams
# 3. start → init
docker compose exec ex_php curl -s -b /tmp/cj -X POST http://localhost/api/exam/start -d "test_id=<id>"
docker compose exec ex_php curl -s -b /tmp/cj "http://localhost/api/exam/init?test_id=<id>"
# 4. autosave + finish + results + review
docker compose exec ex_php curl -s -b /tmp/cj -X POST http://localhost/api/exam/autosave -d "attempt_id=<id>&question_id=<qid>&answer_id=<aid>"
docker compose exec ex_php curl -s -b /tmp/cj -X POST http://localhost/api/exam/finish -d "test_id=<id>"
docker compose exec ex_php curl -s -b /tmp/cj http://localhost/api/student/results
docker compose exec ex_php curl -s -b /tmp/cj "http://localhost/api/student/review?test_id=<id>"
```
Expected: semua `status:success`, review berisi `summary.questions`.

- [ ] **Step 2: Bundle build + budget + lint penuh**

Run:
```bash
docker compose exec ex_php php spark cbt:build-ui-bundle
for f in $(git diff --name-only HEAD~3 | grep '\.php$'); do docker compose exec ex_php php -l "src/$f"; done
node --check src/public/assets/exam-app.js
node --check src/public/js/kiosk-integration.js
```
Expected: `SIZE BUDGET OK`; semua `No syntax errors`.

- [ ] **Step 3: APK build**

Run: `cd cbt-kiosk-app && gradle assembleDebug`
Expected: BUILD SUCCESSFUL; APK di `app/build/outputs/apk/debug/`.

- [ ] **Step 4: Manual device checklist (user)**

1. Install APK → setup server URL → bundle download pertama (atau Import Bundle via ShareIt/File Manager).
2. Login sukses → dashboard → kerjakan ujian → autosave berjalan (cek DB).
3. Mid-attempt kill app → buka lagi → resume ke `exam.html` (bukan dashboard) → lanjut kerjakan.
4. Selesai → results → review (kunci hanya bila `show_correct`).
5. Update bundle di server (regen) → app cold start → DownloadManager tarik versi baru → atomic swap tanpa error.
6. Offline (airplane mode) dengan bundle terpasang → halaman UI tampil, data fetch gagal → error panel + retry.
7. Kiosk lock tetap aktif (exit password, siren, heartbeat tetap jalan).

- [ ] **Step 5: Commit final (bila ada fix)**

```bash
git add -A && git commit -m "fix(bundle): perbaikan hasil verifikasi E2E"
```

---

## Self-Review Map (spec → task)

| Spec requirement | Task |
|---|---|
| SameSite=None+Secure global | Task 1 |
| CORS origin-spesifik (allowlist, credentials) | Task 1 (reuse CorsApiFilter) |
| Fetch `credentials: 'include'` | Task 4 (semua fetch di bundle) |
| CSRF skip hanya origin kiosk | Task 1 (KioskOriginCsrfFilter) |
| `kiosk-integration.js` script pertama | Task 4 (_head.php) |
| DownloadManager + Nginx static (Range) | Task 5 + Task 6 |
| Sideload primer (Import Bundle, satu pipeline) | Task 6 (`importBundle` + `verifyAndInstall`) |
| Verify sha256 per-file vs manifest dari config | Task 4 (manifest) + Task 6 |
| Gate refresh: cold start / tanpa attempt | Task 6 (`canRefresh`) |
| Extract → staging → atomic rename | Task 6 (`verifyAndInstall`) |
| Resume 401: attempt_id app storage + active_attempt | Task 2 (exams) + Task 6 (examActive) + Task 4 (dashboard redirect) |
| 5 halaman: login/dashboard/exam/results/review | Task 4 |
| API: exams/results/review/start | Task 2 + Task 3 |
| `ui_bundle` di kiosk/config | Task 5 |
| Size gate <300KB | Task 4 (SIZE_BUDGET_BYTES) |
| Web path Windows tidak berubah | Semua task (anti-negatif) |
| kiosk-integration listener `kiosk_config_ready` | Task 4 Step 7 |
| exam-app.js bundle hook | Task 4 Step 7 |

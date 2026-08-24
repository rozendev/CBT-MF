# Koreksi Cepat Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Guru menilai satu soal esai lintas semua siswa dalam satu layar keyboard-driven (↑ penuh, ↓ nol, 1–9 persen, ←/→ navigasi, u undo), tanpa reload halaman.

**Architecture:** Halaman admin baru (`admin/results/grade/...`) ber-Alpine.js + dua endpoint AJAX JSON di `Admin\ResultController`. Logika kalkulasi ulang skor diekstrak dari `updateManualScore()` agar form lama dan endpoint baru memakai satu rumus yang sama. Tanpa tabel/migration baru — data bersumber dari snapshot `test_logs` + join `questions.answer_mode`.

**Tech Stack:** PHP 8.5 / CodeIgniter 4.7, Alpine.js 3 + Bootstrap 5 (sudah ada di layout admin), MariaDB.

Spec referensi: `docs/superpowers/specs/2026-08-23-koreksi-cepat-design.md`.

---

## Catatan lingkungan

- **Jangan pakai git worktree** — container `php` hanya mount repo utama (`./src:/var/www/html`). Kerjakan di checkout utama, branch `feature/cbt-cli-upgrade`.
- Perintah verifikasi:
  ```bash
  # lint
  docker compose exec -T php php -l app/Controllers/Admin/ResultController.php
  # suite tetap hijau
  docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
  # route terdaftar
  docker compose exec -T php sh -c 'cd /var/www/html && php spark routes | grep grade'
  ```
- Fakta kode yang sudah diverifikasi (jangan diubah-ubah):
  - Route results ada di grup `role:admin` (`src/app/Config/Routes.php:175-179`); route baru disisipkan di sana.
  - Layout admin menyediakan section `page_title`, `styles`, `content`, `scripts`.
  - Pola CSRF fetch yang dipakai repo: header `'X-CSRF-TOKEN': '<?= csrf_hash() ?>'`.
  - `test_logs` menyimpan snapshot `question_text`, `question_type`; `answer_mode` TIDAK tersimpan — harus join `questions` (persis seperti SQL di ScoringEngine).
  - NIS siswa = `users.registration_number`.
  - Kunci referensi esai per attempt = baris `test_log_answers` dengan `is_correct = 1`.

---

### Task 1: Ekstrak `recalcAttemptScore()` (refactor murni)

**Files:**
- Modify: `src/app/Controllers/Admin/ResultController.php:125-169` (`updateManualScore`)

Perilaku tidak berubah — blok kalkulasi (baris ±136–166) pindah ke method private yang nanti dipakai bersama endpoint AJAX.

- [ ] **Step 1: Ganti isi `updateManualScore()` dan tambah method private**

Ganti seluruh `updateManualScore()` dengan:

```php
    /**
     * Manually update question score and recalculate total score
     */
    public function updateManualScore()
    {
        $logId = $this->request->getPost('log_id');
        $score = (float) $this->request->getPost('score');

        $log = $this->testLogModel->find($logId);
        if (!$log) return redirect()->back()->with('error', 'Data tidak valid.');

        $this->testLogModel->update($logId, ['score' => $score]);

        $this->recalcAttemptScore((int) $log->test_attempt_id);

        return redirect()->back()->with('success', 'Nilai soal berhasil diperbarui dan skor akhir telah dikalkulasi ulang.');
    }

    /**
     * Hitung ulang dan simpan skor akhir satu attempt dari nilai test_logs.
     * Dipakai bersama oleh updateManualScore() dan gradeSave().
     *
     * Hanya soal yang sudah dinilai yang ikut jadi pembagi. Esai yang
     * menunggu koreksi bernilai NULL; kalau ikut dihitung, nilai siswa
     * tertekan turun hanya karena gurunya belum sempat mengoreksi.
     */
    private function recalcAttemptScore(int $attemptId): float
    {
        $db = \Config\Database::connect();

        $attempt = $this->attemptModel->find($attemptId);
        if (!$attempt) return 0.0;

        $test = $this->testModel->find($attempt->test_id);
        if (!$test) return 0.0;

        $result = $db->query("SELECT SUM(score) as total FROM test_logs WHERE test_attempt_id = ?", [$attemptId])->getRow();
        $rawScore = $result->total ?? 0;

        $resultMax = $db->query(
            "SELECT COUNT(*) as num_questions FROM test_logs WHERE test_attempt_id = ? AND score IS NOT NULL",
            [$attemptId]
        )->getRow();
        $numQuestions = $resultMax->num_questions ?? 0;

        $maxPossiblePoints = $numQuestions * $test->score_right;

        $finalScore = 0;
        if ($maxPossiblePoints > 0) {
            $finalScore = ($rawScore / $maxPossiblePoints) * $test->max_score;
        }
        if ($finalScore < 0) $finalScore = 0;

        $finalScore = round($finalScore, 3);
        $this->attemptModel->update($attemptId, ['score' => $finalScore]);

        return (float) $finalScore;
    }
```

Catatan urutan: `updateManualScore()` tetap menyimpan nilai log DULU baru recalc — sama seperti semula; `recalcAttemptScore()` membaca ulang dari DB sehingga hasilnya identik.

- [ ] **Step 2: Lint + suite**

```bash
docker compose exec -T php php -l app/Controllers/Admin/ResultController.php
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
```
Expected: `No syntax errors detected`, `OK (41 tests, 117 assertions)`.

- [ ] **Step 3: Commit**

```bash
git add src/app/Controllers/Admin/ResultController.php
git commit -m "refactor(results): ekstrak recalcAttemptScore dari updateManualScore" -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 2: Route + empat method backend

**Files:**
- Modify: `src/app/Config/Routes.php:179` (sisipkan setelah baris `results/update-score`)
- Modify: `src/app/Controllers/Admin/ResultController.php` (tambah method di akhir kelas, sebelum kurung tutup)

- [ ] **Step 1: Sisipkan route**

Di `Routes.php`, tepat setelah baris `$routes->post('results/update-score', ...)`, tambah:

```php
    // Koreksi cepat: satu soal esai dinilai lintas siswa dalam satu layar.
    $routes->get('results/grade/(:num)', 'Admin\ResultController::gradeRedirect/$1');
    $routes->get('results/grade/(:num)/(:num)', 'Admin\ResultController::grade/$1/$2');
    $routes->get('results/grade-data/(:num)/(:num)', 'Admin\ResultController::gradeData/$1/$2');
    $routes->post('results/grade-save', 'Admin\ResultController::gradeSave');
```

- [ ] **Step 2: Tambah method controller**

Tambahkan di `ResultController` (setelah `deleteAttempt`):

```php
    /**
     * Titik masuk koreksi cepat: arahkan ke soal manual pertama yang masih
     * ada nilai kosong. Kalau semua sudah terkoreksi, tetap masuk ke soal
     * pertama — guru bisa menyesuaikan nilai lama dari layar yang sama.
     */
    public function gradeRedirect(int $testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        $db   = \Config\Database::connect();
        $base = "
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE ta.test_id = ? AND ta.status = 3 AND q.type = 3 AND q.answer_mode = 'manual'
        ";

        $row = $db->query("
            SELECT tl.question_id
            {$base}
            GROUP BY tl.question_id
            HAVING SUM(tl.score IS NULL) > 0
            ORDER BY MIN(tl.display_order) ASC
            LIMIT 1
        ", [$testId])->getRow();

        if (!$row) {
            $row = $db->query("
                SELECT tl.question_id
                {$base}
                GROUP BY tl.question_id
                ORDER BY MIN(tl.display_order) ASC
                LIMIT 1
            ", [$testId])->getRow();
        }

        if (!$row) {
            return redirect()->to('/admin/results/view/' . $testId)
                ->with('error', 'Belum ada jawaban soal esai untuk dikoreksi pada ujian ini.');
        }

        return redirect()->to('/admin/results/grade/' . $testId . '/' . $row->question_id);
    }

    /**
     * Shell layar koreksi cepat; datanya diambil gradeData() via AJAX.
     */
    public function grade(int $testId, int $questionId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->to('/admin/results')->with('error', 'Ujian tidak ditemukan.');
        }

        $question = (new \App\Models\QuestionModel())->find($questionId);
        if (!$question || (int) $question->type !== 3 || $question->answer_mode !== 'manual') {
            return redirect()->to('/admin/results/view/' . $testId)
                ->with('error', 'Soal ini tidak dapat dinilai lewat koreksi cepat.');
        }

        return view('admin/results/grade', ['test' => $test, 'questionId' => (int) $questionId]);
    }

    /**
     * JSON roster: daftar siswa (jawaban + nilai saat ini) untuk satu soal.
     */
    public function gradeData(int $testId, int $questionId)
    {
        $test     = $this->testModel->find($testId);
        $question = (new \App\Models\QuestionModel())->find($questionId);
        if (!$test || !$question || (int) $question->type !== 3 || $question->answer_mode !== 'manual') {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Data koreksi tidak ditemukan.']);
        }

        $db = \Config\Database::connect();

        // Roster: attempt selesai yang punya baris log untuk soal ini.
        // Attempt tanpa log (soal ditambah setelah dia selesai) dikeluarkan —
        // tidak ada tempat menaruh nilainya, dan counter tetap jujur.
        $rows = $db->query("
            SELECT tl.id AS log_id, tl.answer_text, tl.score,
                   u.firstname, u.lastname, u.registration_number
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN users u ON u.id = ta.user_id
            WHERE ta.test_id = ? AND ta.status = 3 AND tl.question_id = ?
            ORDER BY u.firstname ASC, u.lastname ASC
        ", [$testId, $questionId])->getResult();

        // Kunci referensi per log (snapshot ikut attempt).
        $keys   = [];
        $logIds = array_column($rows, 'log_id');
        if (!empty($logIds)) {
            $keyRows = $db->query("
                SELECT tla.test_log_id, tla.answer_text
                FROM test_log_answers tla
                WHERE tla.test_log_id IN ? AND tla.is_correct = 1
                ORDER BY tla.display_order ASC
            ", [$logIds])->getResult();
            foreach ($keyRows as $k) {
                $keys[$k->test_log_id] ??= $k->answer_text;
            }
        }

        $students = [];
        $graded   = 0;
        foreach ($rows as $r) {
            if ($r->score !== null) $graded++;
            $students[] = [
                'log_id' => (int) $r->log_id,
                'name'   => trim($r->firstname . ' ' . $r->lastname),
                'nis'    => (string) $r->registration_number,
                'answer' => (string) ($r->answer_text ?? ''),
                'key'    => (string) ($keys[$r->log_id] ?? ''),
                'score'  => $r->score === null ? null : (float) $r->score,
            ];
        }

        // Dropdown pemilih soal: semua soal manual ujian ini + counter pending.
        $qRows = $db->query("
            SELECT q.id, q.description, COUNT(*) AS total, SUM(tl.score IS NULL) AS pending
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE ta.test_id = ? AND ta.status = 3 AND q.type = 3 AND q.answer_mode = 'manual'
            GROUP BY q.id, q.description
            ORDER BY MIN(tl.display_order) ASC
        ", [$testId])->getResult();

        $questions = [];
        foreach ($qRows as $qr) {
            $label = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $qr->description)));
            $questions[] = [
                'id'      => (int) $qr->id,
                'label'   => mb_substr($label, 0, 80),
                'pending' => (int) $qr->pending,
                'total'   => (int) $qr->total,
            ];
        }

        return $this->response->setJSON([
            'status'   => 'success',
            'question' => [
                'id'         => (int) $questionId,
                'max_points' => (float) $test->score_right,
            ],
            'counts'   => ['total' => count($students), 'graded' => $graded],
            'students' => $students,
            'questions'=> $questions,
        ]);
    }

    /**
     * Simpan satu nilai dari layar koreksi cepat. score kosong berarti NULL
     * ("belum dikoreksi") — dipakai tombol undo untuk mengembalikan kondisi.
     */
    public function gradeSave()
    {
        $logId = (int) $this->request->getPost('log_id');
        $raw   = $this->request->getPost('score');
        $score = ($raw === '' || $raw === null) ? null : (float) $raw;

        $db  = \Config\Database::connect();
        $log = $db->query("
            SELECT tl.id, tl.question_id, tl.test_attempt_id, ta.test_id, q.type, q.answer_mode
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE tl.id = ?
        ", [$logId])->getRow();

        if (!$log || (int) $log->type !== 3 || $log->answer_mode !== 'manual') {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Soal tidak dapat dinilai lewat koreksi cepat.']);
        }

        if ($score !== null && $score < 0) {
            $score = 0;
        }

        $this->testLogModel->update($logId, ['score' => $score]);
        $attemptScore = $this->recalcAttemptScore((int) $log->test_attempt_id);

        $remaining = (int) ($db->query("
            SELECT COUNT(*) AS c
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            WHERE ta.test_id = ? AND ta.status = 3 AND tl.question_id = ? AND tl.score IS NULL
        ", [$log->test_id, $log->question_id])->getRow()->c ?? 0);

        return $this->response->setJSON([
            'status'        => 'success',
            'log_id'        => $logId,
            'attempt_score' => $attemptScore,
            'remaining'     => $remaining,
        ]);
    }
```

- [ ] **Step 3: Verifikasi route terdaftar + lint + suite**

```bash
docker compose exec -T php sh -c 'cd /var/www/html && php spark routes | grep grade'
docker compose exec -T php php -l app/Controllers/Admin/ResultController.php
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
```
Expected: 4 route `results/grade*` muncul dengan filter `role:admin`; `No syntax errors detected`; `OK (41 tests, 117 assertions)`.

- [ ] **Step 4: Commit**

```bash
git add src/app/Config/Routes.php src/app/Controllers/Admin/ResultController.php
git commit -m "feat(results): endpoint koreksi cepat lintas siswa" -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 3: Layar grading + tombol titik masuk

**Files:**
- Create: `src/app/Views/admin/results/grade.php`
- Modify: `src/app/Controllers/Admin/ResultController.php` (`view()` — flag `$hasManualGrading`)
- Modify: `src/app/Views/admin/results/view.php:13-15` (tombol Koreksi Cepat)

- [ ] **Step 1: Ubah `view()` agar mengirim flag**

Di `view()`, sebelum `return view(...)`, tambah:

```php
        $hasManualGrading = (bool) $db->query("
            SELECT COUNT(*) AS c
            FROM test_logs tl
            JOIN test_attempts ta ON ta.id = tl.test_attempt_id
            JOIN questions q ON q.id = tl.question_id
            WHERE ta.test_id = ? AND ta.status = 3 AND q.type = 3 AND q.answer_mode = 'manual'
        ", [$testId])->getRow()->c;
```

dan ubah array view menjadi:

```php
        return view('admin/results/view', [
            'test'             => $test,
            'attempts'         => $attempts,
            'hasManualGrading' => $hasManualGrading,
        ]);
```

- [ ] **Step 2: Tombol di `view.php`**

Ganti blok `<div class="col-md-4 text-end">...</div>` (baris 13–15) dengan:

```php
            <div class="col-md-4 text-end">
                <?php if (!empty($hasManualGrading)): ?>
                    <a href="<?= base_url('/admin/results/grade/' . $test->id) ?>" class="btn btn-primary me-1">
                        <i class="bi bi-lightning-charge me-1"></i>Koreksi Cepat
                    </a>
                <?php endif; ?>
                <a href="<?= base_url('/admin/results') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Kembali</a>
            </div>
```

- [ ] **Step 3: Buat `src/app/Views/admin/results/grade.php`**

```php
<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Koreksi Cepat: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div x-data="gradeApp()" @keydown.window="onKey($event)" class="pb-5">

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4 bg-light rounded-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-1"><i class="bi bi-lightning-charge me-2 text-primary"></i>Koreksi Cepat</h5>
                <p class="text-muted mb-0"><?= esc($test->name) ?></p>
            </div>
            <a href="<?= base_url('/admin/results/view/' . $test->id) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Daftar Nilai</a>
        </div>
    </div>

    <div class="alert alert-danger" x-show="loadError" x-text="loadError"></div>

    <div class="card shadow-sm" x-show="loaded && !loadError">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <select x-model.number="currentQuestionId" @change="loadQuestion()" class="form-select w-auto" aria-label="Pilih soal">
                    <template x-for="q in questions" :key="q.id">
                        <option :value="q.id" x-text="'[' + q.pending + ' belum] ' + q.label"></option>
                    </template>
                </select>
                <span class="badge bg-primary fs-6" x-text="gradedCount() + '/' + students.length + ' terkoreksi'"></span>
            </div>
            <div class="alert alert-success mt-3 mb-0 py-2" x-show="allDone()">
                Semua esai sudah dikoreksi — nilai tetap bisa disesuaikan.
            </div>
        </div>

        <div class="card-body p-4" x-show="current">
            <template x-if="current">
                <div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h6 class="m-0 fw-bold">
                            <span x-text="current.name"></span>
                            <span class="text-muted fw-normal" x-text="'(NIS ' + (current.nis || '-') + ')'"></span>
                            <span class="badge ms-2" :class="current._failed ? 'bg-danger' : (current.score === null ? 'bg-primary' : 'bg-success')"
                                  x-text="current._failed ? 'gagal simpan' : (current.score === null ? 'belum dinilai' : 'sudah dinilai')"></span>
                        </h6>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="prevStudent()" :disabled="qIndex <= 0">‹</button>
                            <span class="btn btn-sm btn-light disabled" x-text="(qIndex + 1) + ' / ' + students.length"></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="nextStudent()" :disabled="qIndex >= students.length - 1">›</button>
                        </div>
                    </div>

                    <div class="border rounded-3 p-3 mb-3">
                        <div class="text-muted small fw-bold mb-1">Jawaban Siswa</div>
                        <p class="mb-0" style="white-space: pre-wrap; min-height: 96px;" x-text="current.answer || 'Tidak diisi'"></p>
                    </div>

                    <div class="text-muted small mb-3" x-show="current.key">
                        <i class="bi bi-key me-1"></i>Kunci: <span x-text="current.key"></span>
                    </div>

                    <div class="text-center py-3 border rounded-3"
                         :class="saving ? 'bg-warning bg-opacity-10' : (current._failed ? 'bg-danger bg-opacity-10' : '')">
                        <div class="display-6 fw-bold" x-text="current.score === null ? '—' : current.score"></div>
                        <div class="text-muted small">dari maksimum <span x-text="maxPoints"></span> poin</div>
                        <div class="text-danger small mt-2" x-show="current._failed"
                             x-text="saveError + ' — tekan ulang aksinya.'"></div>
                    </div>

                    <div class="text-center text-muted small mt-3">
                        <i class="bi bi-keyboard me-1"></i>↑ penuh · ↓ nol · 1–9 parsial (%) · ←/→ pindah siswa · U urungkan
                    </div>
                </div>
            </template>
        </div>

        <div class="card-body text-center text-muted py-5" x-show="loaded && students.length === 0">
            Belum ada siswa yang menyelesaikan ujian ini dengan soal tersebut.
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
function gradeApp() {
    return {
        csrfToken: '<?= csrf_hash() ?>',
        baseUrl: '<?= base_url('/admin/results') ?>',
        testId: <?= (int) $test->id ?>,
        initialQuestionId: <?= (int) $questionId ?>,

        loaded: false,
        loadError: '',
        saving: false,
        saveError: '',
        lastAction: null,

        questions: [],
        students: [],
        maxPoints: 0,
        currentQuestionId: null,
        qIndex: 0,

        async init() {
            await this.loadQuestion(this.initialQuestionId);
        },

        get current() { return this.students[this.qIndex] ?? null; },

        gradedCount() { return this.students.filter(s => s.score !== null).length; },
        allDone() { return this.students.length > 0 && this.gradedCount() === this.students.length; },

        async loadQuestion(questionId = null) {
            const id = questionId ?? this.currentQuestionId;
            this.loadError = '';
            try {
                const res = await fetch(`${this.baseUrl}/grade-data/${this.testId}/${id}`, {
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.message || 'Gagal memuat data.');
                this.questions = data.questions ?? [];
                this.students = data.students.map(s => ({ ...s, _failed: false }));
                this.maxPoints = parseFloat(data.question.max_points) || 0;
                this.currentQuestionId = parseInt(data.question.id, 10);
                const firstPending = this.students.findIndex(s => s.score === null);
                this.qIndex = firstPending >= 0 ? firstPending : 0;
                this.lastAction = null;
                this.loaded = true;
            } catch (e) {
                this.loadError = e.message || 'Gagal memuat data koreksi.';
                this.loaded = true;
            }
        },

        async applyScore(value) {
            const s = this.current;
            if (!s || this.saving) return;
            this.saving = true;
            this.saveError = '';
            const prev = s.score;
            try {
                const body = new FormData();
                body.append('log_id', s.log_id);
                body.append('score', value);
                const data = await this.post(body);
                s.score = value;
                s._failed = false;
                this.lastAction = { log_id: s.log_id, prev };
                this.advance();
            } catch (e) {
                s._failed = true;
                this.saveError = e.message || 'Gagal menyimpan.';
            } finally {
                this.saving = false;
            }
        },

        // Setelah simpan, lompat ke siswa berikutnya yang BELUM dinilai.
        // Kalau tak ada lagi, lanjut index+1 biasa supaya review tetap urut.
        advance() {
            let next = -1;
            for (let i = this.qIndex + 1; i < this.students.length; i++) {
                if (this.students[i].score === null && !this.students[i]._failed) { next = i; break; }
            }
            if (next < 0) next = Math.min(this.qIndex + 1, this.students.length - 1);
            this.qIndex = next;
        },

        prevStudent() { if (this.qIndex > 0) this.qIndex--; },
        nextStudent() { if (this.qIndex < this.students.length - 1) this.qIndex++; },

        // Undo satu langkah: kembalikan simpanan terakhir ke nilai semula
        // ('' = NULL = belum dikoreksi), lalu kursor balik ke siswa itu.
        async undo() {
            const action = this.lastAction;
            if (!action || this.saving) return;
            const idx = this.students.findIndex(s => s.log_id === action.log_id);
            if (idx < 0) { this.lastAction = null; return; }
            const s = this.students[idx];
            this.saving = true;
            try {
                const body = new FormData();
                body.append('log_id', s.log_id);
                body.append('score', action.prev === null ? '' : action.prev);
                await this.post(body);
                s.score = action.prev;
                s._failed = false;
                this.lastAction = null;
                this.qIndex = idx;
            } catch (e) {
                s._failed = true;
                this.saveError = e.message || 'Gagal mengembalikan nilai.';
            } finally {
                this.saving = false;
            }
        },

        async post(body) {
            const res = await fetch(`${this.baseUrl}/grade-save`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || 'Permintaan gagal.');
            return data;
        },

        onKey(e) {
            if (!this.loaded || !this.current || this.saving) return;
            const tag = (e.target.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            // preventDefault hanya untuk tombol yang memang kita tangani,
            // supaya panah tidak menggulir halaman dan ketikan normal di
            // elemen lain tidak ikut terblokir.
            switch (e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    if (this.maxPoints > 0) this.applyScore(this.maxPoints);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.applyScore(0);
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    this.prevStudent();
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    this.nextStudent();
                    break;
                case 'u':
                case 'U':
                    e.preventDefault();
                    this.undo();
                    break;
                default:
                    if (/^[1-9]$/.test(e.key) && this.maxPoints > 0) {
                        e.preventDefault();
                        this.applyScore(Math.round(this.maxPoints * (parseInt(e.key, 10) / 10) * 100) / 100);
                    }
            }
        },
    };
}
</script>
<?= $this->endSection() ?>
```

Catatan desain layar:
- Jawaban & kunci dirender `x-text` (Alpine meng-escape otomatis — konten siswa tidak pernah dieksekusi sebagai HTML).
- Keyboard listener di window dengan guard tagName (INPUT/TEXTAREA/SELECT diabaikan) dan `preventDefault()` hanya pada tombol yang ditangani — panah tak menggulir halaman, ketikan normal di elemen lain tak terganggu.
- Kartu merah + badge "gagal simpan" = kursor tidak maju sampai simpan berhasil.

- [ ] **Step 4: Lint + suite**

```bash
docker compose exec -T php php -l app/Controllers/Admin/ResultController.php
docker compose exec -T php sh -c 'cd /var/www/html && php vendor/bin/phpunit --testsuite WordImport'
```
Expected: `No syntax errors detected`, `OK (41 tests, 117 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add src/app/Controllers/Admin/ResultController.php src/app/Views/admin/results/grade.php src/app/Views/admin/results/view.php
git commit -m "feat(results): layar koreksi cepat satu soal lintas siswa" -m "Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

### Task 4: Verifikasi manual end-to-end (user memegang browser)

Checklist bernomor untuk dijalankan user di browser (butuh data: satu ujian berisi minimal 2 soal esai berkunci, ≥3 siswa selesai ujian, satu di antaranya meninggalkan jawaban kosong):

- [ ] 1. Buka `admin/results` → pilih ujian → tombol **Koreksi Cepat** tampil di kanan atas.
- [ ] 2. Klik tombol → mendarat di layar grading, langsung pada soal esai pertama, kursor di siswa **pertama yang belum dinilai**, badge counter `[N belum]` di dropdown benar.
- [ ] 3. Tekan `↑` → nilai = maksimum poin, otomatis pindah siswa berikutnya yang belum dinilai, counter bertambah.
- [ ] 4. Tekan `↓` → nilai 0 tersimpan, lanjut.
- [ ] 5. Tekan `7` → nilai = 70% × maksimum (mis. maks 10 → 7).
- [ ] 6. Tekan `←/→` → pindah siswa **tanpa** menyimpan apa pun.
- [ ] 7. Tekan `U` → simpanan terakhir kembali ke kondisi semula (kalau sebelumnya belum dinilai → kembali "—" dan masuk hitungan belum), kursor balik ke siswa itu.
- [ ] 8. Pilih soal lain di dropdown → roster soal itu termuat, posisi mulai dari yang belum dinilai.
- [ ] 9. Siswa dengan jawaban kosong tampil "Tidak diisi", bisa dinilai `↓` (0).
- [ ] 10. Setelah semua dinilai → banner hijau "Semua esai sudah dikoreksi".
- [ ] 11. Buka detail salah satu siswa → skor akhir terhitung ulang dan konsisten dengan pembagi (esai belum dikoreksi tidak menekan nilai).
- [ ] 12. Form intervensi nilai lama di halaman detail masih berfungsi (regresi Task 1).

## Di luar lingkup (sesuai spec)

- Koreksi multi-soal dalam satu layar; antrian lintas ujian; undo multi-langkah.

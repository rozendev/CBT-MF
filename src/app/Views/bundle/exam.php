<?= view('bundle/_head', ['pageTitle' => 'Ujian', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body class="noselect">
<style>
    :root {
        --color-background: #f4f6f9; --color-primary: #0d6efd; --color-primary-rgb: 13,110,253;
        --color-primary-dark: #0858c2; --color-surface: #ffffff; --color-text: #212529;
        --color-text-muted: #6c757d; --color-danger: #dc3545; --color-warning: #ffc107;
    }
    body { background: var(--color-background); color: var(--color-text);
           font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
           -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; padding-bottom: 80px; }
    .noselect { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
    .loading-screen { position: fixed; inset: 0; z-index: 100001; background: var(--color-background);
           display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--color-text); }
    .kiosk-spinner { width: 3rem; height: 3rem; border: 0.35rem solid rgba(0,0,0,0.1);
           border-top-color: var(--color-primary); border-radius: 50%; animation: kiosk-spin 0.8s linear infinite; margin-bottom: 16px; }
    .spinner-border { display: inline-block; width: 1rem; height: 1rem; border: 0.2em solid currentColor;
           border-right-color: transparent; border-radius: 50%; animation: kiosk-spin 0.6s linear infinite; }
    @keyframes kiosk-spin { to { transform: rotate(360deg); } }
    .form-check-input { width: 1.15em; height: 1.15em; accent-color: var(--color-primary); flex-shrink: 0; cursor: pointer; }
    .form-control { width: 100%; padding: 12px; border: 1px solid rgba(0,0,0,0.15); border-radius: 12px;
           font-size: 15px; background: var(--color-surface); color: var(--color-text); }
    .form-select { width: 100%; padding: 9px 12px; border: 1px solid var(--color-primary); border-radius: 8px;
           font-size: 14px; background: var(--color-surface); color: var(--color-text); }
    .table { width: 100%; border-collapse: collapse; background: var(--color-surface); border-radius: 10px; }
    .table th, .table td { padding: 10px 8px; border-bottom: 1px solid rgba(0,0,0,0.08); font-size: 14px; }
    .alert { padding: 12px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
    .alert-info { background: #e7f1ff; color: #0c5460; }
    .modal { display: none; position: fixed; inset: 0; z-index: 1050; background: rgba(15,23,42,0.5);
           align-items: center; justify-content: center; padding: 16px; }
    .modal-dialog { width: 100%; max-width: 480px; }
    .modal-content { background: var(--color-surface); border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); overflow: hidden; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid rgba(0,0,0,0.08); }
    .modal-body { padding: 20px 18px; text-align: center; }
    .modal-footer { padding: 14px 18px; border-top: 1px solid rgba(0,0,0,0.08); display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
    .btn-close { border: 0; background: none; font-size: 20px; line-height: 1; cursor: pointer; color: var(--color-text); padding: 4px 8px; }
    .btn-close-white { color: #fff; }
    .btn { display: inline-block; border: 1px solid transparent; border-radius: 10px; padding: 10px 16px;
           font-size: 15px; font-weight: 600; cursor: pointer; color: var(--color-text); }
    .btn-primary { background: var(--color-primary); color: #fff; }
    .btn-success { background: #198754; color: #fff; }
    .btn-danger { background: var(--color-danger); color: #fff; }
    .btn-light { background: #f8f9fa; color: var(--color-text); border-color: rgba(0,0,0,0.12); }
    .btn-link { background: none; color: var(--color-danger); text-decoration: underline; border: 0; }
    .btn-sidebar-toggle { background: none; border: 0; color: var(--color-text); padding: 4px; cursor: pointer; }
    .w-100 { width: 100%; }
    .d-none { display: none; }
    .d-flex { display: flex; }
    .d-block { display: block; }
    .align-items-center { align-items: center; }
    .justify-content-center { justify-content: center; }
    .text-center { text-align: center; }
    .flex-column { flex-direction: column; }
    .fw-bold { font-weight: 700; }
    .text-primary { color: var(--color-primary); } .text-danger { color: var(--color-danger); }
    .text-warning { color: var(--color-warning); } .text-success { color: #198754; }
    .text-dark { color: var(--color-text); } .text-muted { color: var(--color-text-muted); } .text-white { color: #fff; }
    .small { font-size: 13px; }
    .rounded-3 { border-radius: 12px; }
    .shadow-lg { box-shadow: 0 12px 40px rgba(0,0,0,0.18); }
    .rounded-circle { border-radius: 50%; }
    .bg-white { background: #fff; }
    .p-3 { padding: 12px; } .px-4 { padding: 0 16px; } .py-2 { padding: 8px 0; }
    .py-3 { padding: 12px 0; } .py-4 { padding: 16px 0; }
    .mb-2 { margin-bottom: 8px; } .mb-3 { margin-bottom: 12px; } .mb-4 { margin-bottom: 16px; }
    .mt-2 { margin-top: 8px; } .me-2 { margin-right: 8px; } .me-3 { margin-right: 12px; }
    .gap-3 { gap: 12px; }
    .border-bottom { border-bottom: 1px solid rgba(0,0,0,0.08); }
    .flex-shrink-0 { flex-shrink: 0; }
    .offline-overlay { display: flex; position: fixed; inset: 0; z-index: 99999; background: rgba(15,23,42,0.95);
           align-items: center; justify-content: center; flex-direction: column; }
    .offcanvas { position: fixed; top: 0; right: 0; height: 100vh; width: 300px; max-width: 85vw;
           background: var(--color-surface); z-index: 1045; display: none; flex-direction: column;
           box-shadow: -8px 0 30px rgba(0,0,0,0.15); }
    .offcanvas.show { display: flex; }
    .offcanvas-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid rgba(0,0,0,0.08); }
    .offcanvas-body { padding: 16px; overflow-y: auto; }
    .offcanvas-backdrop { display: none; position: fixed; inset: 0; z-index: 1044; background: rgba(15,23,42,0.45); }
    .desktop-drawer-wrapper { display: none; }
    @media (min-width: 992px) { .desktop-drawer-wrapper { display: block; } }
    @media (min-width: 576px) { .d-sm-inline { display: inline; } }
</style>

<div id="loading-screen" class="loading-screen">
    <div class="kiosk-spinner"></div>
    <p style="margin:0">Memuat soal...</p>
</div>

<!-- Image Lightbox Overlay -->
<div class="image-lightbox" id="imageLightbox">
    <div class="image-lightbox-close" id="imageLightboxClose">&times;</div>
    <img src="" id="imageLightboxImg" alt="Preview">
</div>

<!-- Suspend Overlay (Anti-Cheat) -->
<div class="suspend-overlay" id="suspendOverlay" style="display:none;">
    <img src="" alt="Warning Logo" id="antiCheatLogoImg" style="max-height:120px;display:none;margin-bottom:16px">
    <h2 id="antiCheatTitle">⚠️ Peringatan Kecurangan!</h2>
    <p id="antiCheatMessage" style="font-size:16px;max-width:600px;padding:0 16px;margin-bottom:16px">Sistem mendeteksi Anda meninggalkan halaman ujian.</p>
    <div style="margin-bottom:16px">
        <span id="suspendTimerDisplay" style="font-size:5rem;font-weight:700;">30</span>
    </div>
    <p style="margin-bottom:8px;color:var(--color-warning)">Pelanggaran: <span id="strikeCount" style="font-weight:700">1</span> / <span id="maxStrikes" style="font-weight:700">2</span></p>
</div>

<script>
    // Tunda start Alpine sampai config ujian siap (EXAM_CONFIG wajib ada saat
    // komponen x-data dibuat — fetch init bisa lebih lambat dari DOM ready).
    window.deferLoadingAlpine = function (startAlpine) {
        if (window.__configReady) startAlpine();
        else window.__alpineReady = startAlpine;
    };

    var base = window.KIOSK_BASE_URL;
    var params = new URLSearchParams(window.location.search);
    var testId = params.get('test_id') || '';

    window.__bundleConfigPromise = (function () {
        var ready = function (j) {
            if (j.status !== 'success') { throw new Error(j.message || 'Gagal memuat soal'); }
            if (!j.test) { throw new Error('Respon init tidak valid.'); }
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
                attemptId: j.attempt_id,
                studentName: j.user ? (((j.user.firstname || '') + ' ' + (j.user.lastname || '')).trim()) : '',
                beginTimeMs: j.test.begin_time_ms,
                serverNowMs: j.test.server_now_ms
            };
            window.questionsData = window.EXAM_CONFIG.questionsData;
            window.answersData = window.EXAM_CONFIG.answersData;
            window.CBT_EXAM_CONFIG = { examId: String(j.test.id), token: j.ws_token || '' };
            window.__bundleCsrf = { csrf_name: j.csrf_name, csrf_token: j.csrf_token };
            window.__examData = {
                questions: window.EXAM_CONFIG.questionsData,
                answers: window.EXAM_CONFIG.answersData,
                attemptId: j.attempt_id,
                studentName: window.EXAM_CONFIG.studentName,
                beginTimeMs: j.test.begin_time_ms,
                timeOffset: (j.test.server_now_ms || Date.now()) - Date.now(),
                antiCheat: j.anti_cheat || null,
                user: j.user || null,
                wsToken: j.ws_token || null
            };
            window.dispatchEvent(new Event('kiosk_config_ready'));
            var ls = document.getElementById('loading-screen');
            if (ls) ls.style.display = 'none';
            window.__configReady = true;
            if (window.__alpineReady) window.__alpineReady();
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
        var ls = document.getElementById('loading-screen');
        if (ls) ls.textContent = e && e.message ? e.message : 'Gagal memuat soal';
    });

    function openQuestionGrid() {
        var el = document.getElementById('questionGridSheet');
        var back = document.getElementById('questionGridBackdrop');
        if (el) { el.style.display = ''; el.classList.add('show'); }
        if (back) back.style.display = 'block';
    }
    function closeQuestionGrid() {
        var el = document.getElementById('questionGridSheet');
        var back = document.getElementById('questionGridBackdrop');
        if (el) { el.classList.remove('show'); el.style.display = 'none'; }
        if (back) back.style.display = 'none';
    }
    window.closeBundleModal = function (id) {
        var el = document.getElementById(id);
        if (el) { el.style.display = 'none'; }
    };
</script>

<!-- EXAM CONTENT -->
<div id="examContent" style="display:none;" x-data="examApp()" x-show="ready">
    <!-- Offline Overlay -->
    <div class="offline-overlay" x-show="isOffline" style="display: none;">
        <div class="bg-white" style="border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,0.35);padding:28px;max-width:450px;width:90%;text-align:center;">
            <div class="d-flex justify-content-center mb-4">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:80px;height:80px;background:rgba(220,53,69,0.1);">
                    <span style="font-size:2.2rem">📡</span>
                </div>
            </div>
            <h3 class="fw-bold mb-3">Koneksi Terputus!</h3>
            <p class="text-muted mb-4">Sistem mendeteksi Anda sedang offline. Ujian dihentikan sementara hingga koneksi internet Anda kembali. Harap segera sambungkan ulang perangkat Anda ke jaringan.</p>
            <div class="py-3" style="background:#d1e7dd;color:#0f5132;padding:10px;border-radius:10px;display:flex;align-items:center;gap:10px;text-align:left">
                <span style="font-size:1.4rem">🛡️</span>
                <div>
                    <strong class="d-block">Jangan Khawatir!</strong>
                    <span class="small">Jawaban Anda sebelumnya sudah tersimpan dengan aman di dalam perangkat.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="exam-layout">
    <div class="exam-main">

        <!-- Top Navigation -->
        <div class="exam-topbar">
            <div class="exam-title-area">
                <div class="exam-title-text" x-text="testName"></div>
                <div class="exam-student-text" x-text="studentName"></div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <template x-if="durationMinutes > 0">
                    <div class="exam-timer-chip" :class="{'danger': timeLeft <= ((window.APP_CONFIG||{}).warning_threshold_ms || 300000)}">
                        <span>⏱</span> <span x-text="formatTime(timeLeft)">--:--:--</span>
                    </div>
                </template>
                <button type="button" class="btn btn-sidebar-toggle" onclick="openQuestionGrid()" aria-label="Navigasi soal">
                    <span style="font-size:1.5rem;line-height:1">☰</span>
                </button>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-wrapper">
            <div class="progress-fill" :style="'width: ' + ((countAnswered() / questions.length) * 100) + '%'"></div>
        </div>

        <!-- Status simpan MENETAP: banner ini tinggal selama masih ada jawaban
             yang gagal tersimpan. Bug fatal sebelumnya lolos justru karena
             satu-satunya penanda adalah chip "Tersimpan" selama 2 detik. -->
        <div class="k-savebar k-savebar--bad" x-show="saveState === 'failed'" style="display:none">
            <div class="k-savebar__title">
                ⚠ <span x-text="unsavedCount + ' jawaban belum tersimpan'"></span>
            </div>
            <div class="k-savebar__msg" x-text="saveErrorMsg"></div>
            <div class="k-savebar__msg">Jangan selesaikan ujian dulu — beri tahu pengawas.</div>
        </div>

        <!-- Peredam aksi beruntun: di kiosk dipakai Toast native lewat
             CommsBridge; ini cadangan bila bridge tidak tersedia. -->
        <div class="k-ratetoast" x-show="showRateToast" x-transition.opacity.duration.200ms
             style="display:none" x-text="rateToastMsg"></div>

        <!-- Autosave Indicator -->
        <div class="autosave-chip" x-show="showSavedToast" x-transition.opacity.duration.300ms style="display: none;">
            <span style="color:#198754;">✔</span> Tersimpan
        </div>
        <div class="autosave-chip" x-show="showErrorToast" x-transition.opacity.duration.300ms style="display: none; background-color: rgba(220, 53, 69, 0.9);">
            <span style="color:#fff;">✖</span> <span style="color:#fff;">Koneksi Gagal (Offline)</span>
        </div>
        <div class="autosave-chip" x-show="isSaving" x-transition.opacity.duration.150ms style="display: none;">
            <span class="spinner-border" role="status" style="color:var(--color-primary);"></span> Menyimpan...
        </div>

        <!-- Main Content -->
        <div class="question-container">
            <div class="question-label">Soal No. <span x-text="currentIndex + 1"></span> dari <span x-text="questions.length"></span></div>

            <div class="question-text" x-html="currentQuestion.question_text"></div>

            <!-- Type 1: MCSA (Radio) -->
            <template x-if="currentQuestion.question_type == 1">
                <div>
                    <template x-for="(answer, i) in currentAnswers" :key="answer.answer_id">
                        <label class="answer-option" :class="{'selected': answer.is_selected == 1}" @click="selectRadio(answer.answer_id)">
                            <input type="radio" :name="'q_' + currentQuestion.question_id" class="form-check-input flex-shrink-0" :checked="answer.is_selected == 1">
                            <div class="answer-content" x-html="answer.answer_text"></div>
                        </label>
                    </template>
                </div>
            </template>

            <!-- Type 2: MCMA (Checkbox) -->
            <template x-if="currentQuestion.question_type == 2">
                <div>
                    <template x-for="(answer, i) in currentAnswers" :key="answer.answer_id">
                        <label class="answer-option" :class="{'selected': answer.is_selected == 1}">
                            <input type="checkbox" class="form-check-input flex-shrink-0" :checked="answer.is_selected == 1" @change="toggleCheckbox(answer.answer_id, $event.target.checked)">
                            <div class="answer-content" x-html="answer.answer_text"></div>
                        </label>
                    </template>
                </div>
            </template>

            <!-- Type 3: Essay -->
            <template x-if="currentQuestion.question_type == 3">
                <div>
                    <textarea class="form-control" rows="8" x-model="currentQuestion.answer_text" @input="this._typingQid = this.currentQuestion.question_id" @input.debounce.500ms="saveAnswer(this._typingQid)" placeholder="Tulis jawaban Anda di sini..."></textarea>
                </div>
            </template>

            <!-- Type 4: Matching -->
            <template x-if="currentQuestion.question_type == 4">
                <div>
                    <div class="alert alert-info mb-4">ℹ️ Jodohkan Kiri (Premis) dengan Kanan (Jawaban) yang tepat.</div>
                    <template x-for="(pair, i) in currentQuestion.matchingPairs" :key="i">
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;background:rgba(0,0,0,0.03);border:1px solid rgba(0,0,0,0.05);border-radius:10px;padding:12px;margin-bottom:12px">
                            <div class="fw-bold" style="flex:1;min-width:200px" x-html="pair.left"></div>
                            <div style="flex:1;min-width:200px">
                                <select class="form-select" :value="pair.selected" @change="updateMatching(i, $event.target.value)">
                                    <option value="" :selected="pair.selected === ''">-- Pilih Jawaban --</option>
                                    <template x-for="opt in currentQuestion.matchingOptions" :key="opt">
                                        <option :value="opt" x-text="opt" :selected="pair.selected === opt"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Type 5: True/False -->
            <template x-if="currentQuestion.question_type == 5">
                <div>
                    <div class="alert alert-info mb-4">ℹ️ Pilih Benar atau Salah untuk setiap pernyataan di bawah ini.</div>
                    <div style="overflow-x:auto;">
                        <table class="table">
                            <thead class="text-center" style="font-size:14px;opacity:0.8;">
                                <tr>
                                    <th style="text-align:left">Pernyataan</th>
                                    <th style="width:80px;">Benar</th>
                                    <th style="width:80px;">Salah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(pair, i) in currentQuestion.matchingPairs" :key="i">
                                    <tr>
                                        <td x-html="pair.left" class="py-3"></td>
                                        <td class="text-center py-3">
                                            <input type="radio" :name="'tf_' + currentQuestion.question_id + '_' + i" value="Benar" :checked="pair.selected === 'Benar'" class="form-check-input" style="transform:scale(1.5);" @change="updateMatching(i, 'Benar')">
                                        </td>
                                        <td class="text-center py-3">
                                            <input type="radio" :name="'tf_' + currentQuestion.question_id + '_' + i" value="Salah" :checked="pair.selected === 'Salah'" class="form-check-input" style="transform:scale(1.5);" @change="updateMatching(i, 'Salah')">
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

            <div class="end-exam-container" x-show="currentIndex === questions.length - 1">
                <button type="button" class="btn-end-exam" @click="confirmFinish()">
                    Akhiri Ujian
                </button>
            </div>
        </div>

        <!-- Bottom Navigation -->
        <div class="bottom-nav">
            <button class="btn-nav ghost" @click="prevQuestion()" :style="currentIndex === 0 ? 'visibility: hidden;' : ''">
                <span>←</span> <span class="d-none d-sm-inline">Sebelumnya</span>
            </button>
            <button class="btn-flag" :class="{'active': currentQuestion.is_flagged}" @click="toggleFlag()">
                <span>⚑</span>
            </button>
            <button class="btn-nav filled" @click="nextQuestion()">
                <span class="d-none d-sm-inline" x-text="currentIndex === questions.length - 1 ? 'Selesai' : 'Selanjutnya'"></span>
                <span x-show="currentIndex !== questions.length - 1">→</span>
                <span x-show="currentIndex === questions.length - 1">✔</span>
            </button>
        </div>

        <!-- Mobile Sidebar (panel kanan) -->
        <div class="offcanvas offcanvas-end" tabindex="-1" id="questionGridSheet">
            <div class="offcanvas-header border-bottom">
                <h5 class="fw-bold" style="margin:0"><span class="me-2">☰</span>Navigasi Soal</h5>
                <button type="button" class="btn-close" onclick="closeQuestionGrid()" aria-label="Tutup">&times;</button>
            </div>
            <div class="offcanvas-body p-3">
                <div class="q-grid-container">
                    <template x-for="(q, idx) in questions" :key="q.question_id">
                        <button class="q-grid-btn" :class="getGridButtonClass(idx)" @click="goToQuestion(idx); closeMobileSidebar()" x-text="idx + 1"></button>
                    </template>
                </div>

                <div class="sidebar-legend">
                    <div class="sidebar-legend-item"><span class="legend-dot" style="background:var(--color-primary);"></span> Dijawab: <strong x-text="countAnswered()"></strong></div>
                    <div class="sidebar-legend-item"><span class="legend-dot" style="background:var(--color-warning);"></span> Ditandai: <strong x-text="countFlagged()"></strong></div>
                    <div class="sidebar-legend-item"><span class="legend-dot" style="background:rgba(0,0,0,0.08); border:1px solid rgba(0,0,0,0.15);"></span> Belum: <strong x-text="questions.length - countAnswered()"></strong></div>
                </div>
            </div>
        </div>
        <div class="offcanvas-backdrop" id="questionGridBackdrop" onclick="closeQuestionGrid()"></div>

    </div><!-- /exam-main -->

    <!-- Desktop Sidebar -->
    <div class="desktop-drawer-wrapper">
        <div class="desktop-drawer-trigger">
            <span style="font-size:1.2rem;color:var(--color-primary);margin-bottom:8px;display:block">☰</span>
            <div style="writing-mode:vertical-rl;text-orientation:mixed;font-size:13px;font-weight:700;letter-spacing:2px;color:var(--color-text-muted);">
                SOAL
            </div>
        </div>

        <div class="desktop-drawer">
            <h5 class="fw-bold" style="margin:0 0 16px;color:var(--color-primary)"><span class="me-2">☰</span>Navigasi Soal</h5>

            <div class="q-grid-container mb-4">
                <template x-for="(q, idx) in questions" :key="q.log_id || q.question_id">
                    <button class="q-grid-btn" :class="getGridButtonClass(idx)" @click="goToQuestion(idx)" x-text="idx + 1"></button>
                </template>
            </div>

            <div class="sidebar-legend mb-4">
                <div class="sidebar-legend-item"><span class="legend-dot" style="background:var(--color-primary);"></span> Dijawab: <strong x-text="countAnswered()"></strong></div>
                <div class="sidebar-legend-item"><span class="legend-dot" style="background:var(--color-warning);"></span> Ditandai: <strong x-text="countFlagged()"></strong></div>
                <div class="sidebar-legend-item"><span class="legend-dot" style="background:rgba(0,0,0,0.04); border:1px solid rgba(0,0,0,0.1);"></span> Belum: <strong x-text="questions.length - countAnswered()"></strong></div>
            </div>

            <div style="margin-top:auto;padding-top:12px;border-top:1px solid rgba(0,0,0,0.08);">
                <button type="button" class="btn btn-danger w-100 shadow-lg" style="height:48px;border-radius:12px;" @click="confirmFinish()">
                    <span class="me-2">⏹</span>Akhiri Ujian
                </button>
            </div>
        </div>
    </div>
    </div><!-- /exam-layout -->

    <!-- Finish Confirmation Modal -->
    <div class="modal" id="finishModal" tabindex="-1" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="fw-bold" style="margin:0"><span class="me-2">⚠</span>Konfirmasi Selesai</h5>
                    <button type="button" class="btn-close" onclick="closeBundleModal('finishModal')">&times;</button>
                </div>
                <div class="modal-body py-4">
                    <div x-show="countAnswered() < questions.length" style="background:#fff3cd;color:#664d03;border-radius:10px;padding:12px;margin-bottom:14px;">
                        <span>⚠</span> Masih ada <strong><span x-text="questions.length - countAnswered()"></span> soal</strong> yang belum dijawab!
                    </div>
                    <p style="margin:0;font-size:16px">Apakah Anda yakin ingin mengakhiri ujian ini?</p>
                    <p class="text-muted small mt-2">Anda tidak dapat mengubah jawaban lagi setelah ini.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" onclick="closeBundleModal('finishModal')">Batal, Lanjut Kerjakan</button>
                    <button type="button" class="btn btn-success" @click="submitFinish()">Ya, Selesai</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Warning Minimum Score Modal -->
    <div class="modal" id="warningFinishModal" tabindex="-1" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--color-danger);color:#fff;">
                    <h5 class="fw-bold" style="margin:0">⚠ Peringatan Nilai Minimum</h5>
                    <button type="button" class="btn-close btn-close-white" onclick="closeBundleModal('warningFinishModal')">&times;</button>
                </div>
                <div class="modal-body py-4">
                    <div style="font-size:3rem;color:var(--color-danger);">⚠</div>
                    <h4 class="fw-bold mt-2" style="margin-top:12px;">Belum Memenuhi Syarat!</h4>
                    <p style="margin:0;font-size:16px">Nilai ujian Anda saat ini belum memenuhi kriteria batas kelulusan.</p>
                    <p class="text-danger fw-bold mt-2" style="color:var(--color-danger);font-weight:700;margin-top:8px;">Anda diwajibkan untuk melanjutkan pengerjaan ujian!</p>
                </div>
                <div class="modal-footer flex-column">
                    <button type="button" class="btn btn-primary w-100" onclick="closeBundleModal('warningFinishModal')">
                        <span class="me-2">✎</span>Kembali Mengerjakan
                    </button>
                    <button type="button" class="btn btn-link" style="text-decoration:underline;color:var(--color-danger);" @click="forceSubmit()">
                        Akhiri sekarang juga (Nyerah)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Unanswered Required Modal (allow_noanswer = 0) -->
    <div class="modal" id="unansweredRequiredModal" tabindex="-1" style="display:none;">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background:var(--color-warning);">
                    <h5 class="fw-bold text-dark" style="margin:0"><span class="me-2">⚠</span>Soal Belum Lengkap</h5>
                    <button type="button" class="btn-close" onclick="closeBundleModal('unansweredRequiredModal')">&times;</button>
                </div>
                <div class="modal-body py-4">
                    <div style="font-size:3rem;color:var(--color-warning);">⚠</div>
                    <h4 class="fw-bold mt-2" style="margin-top:12px;">Jawab Semua Soal!</h4>
                    <p style="margin:0;font-size:16px">Anda masih memiliki <strong><span x-text="questions.length - countAnswered()"></span> soal</strong> yang belum dijawab.</p>
                    <p class="text-muted mt-2">Ujian ini mewajibkan semua soal dijawab sebelum dapat diselesaikan.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary w-100" onclick="closeBundleModal('unansweredRequiredModal')">
                        <span class="me-2">✎</span>Kembali Mengerjakan
                    </button>
                </div>
            </div>
        </div>
    </div>
</div><!-- /examContent -->

<script>
    // Bootstrap tidak dimuat di bundle — shim minimal untuk modal/offcanvas
    // yang dipanggil exam-app.js (hanya display toggling).
    window.bootstrap = {
        Modal: function (el) {
            this.el = el;
            return {
                show: function () { el.style.display = 'flex'; },
                hide: function () { el.style.display = 'none'; }
            };
        },
        Offcanvas: {
            getInstance: function (el) {
                return el ? { hide: function () { el.style.display = 'none'; var b = document.getElementById('questionGridBackdrop'); if (b) b.style.display = 'none'; } } : null;
            }
        }
    };
    window.bootstrap.Modal.getInstance = function (el) {
        return el && el.classList && el.classList.contains('show') ? { hide: function () { el.style.display = 'none'; } } : null;
    };
</script>
<script defer src="assets/jquery-shim.js?v=<?= esc($assetVersion) ?>"></script>
<script defer src="assets/alpine.min.js?v=<?= esc($assetVersion) ?>"></script>
<!-- app.js sengaja NON-defer dan dieksekusi SEBELUM alpine.min.js agar listener
     alpine:init (registrasi Alpine.data('examApp', ...)) terdaftar lebih dulu —
     menyimpang dari urutan huruf di plan, tetapi ini yang benar;
     jangan ubah urutan tanpa mengecek registrasi komponen Alpine. -->
    <script src="assets/exam-app.js?v=<?= esc($assetVersion) ?>"></script>
<script defer src="assets/sweetalert2.min.js?v=<?= esc($assetVersion) ?>"></script>
</body></html>

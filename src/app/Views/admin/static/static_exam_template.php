<?php
/**
 * Static Exam Template
 *
 * This file is rendered ONCE at generation time to produce a self-contained HTML file.
 * At runtime it is pure HTML/CSS/JS — no PHP processing required.
 *
 * Required PHP variables at generation time:
 *   $test        — test object (id, name, duration_minutes, passing_score, max_score, show_menu, allow_noanswer, auto_logout_on_timeout, password)
 *   $antiCheat   — anti-cheat config array
 *   $apiBaseUrl   — base URL for the API endpoints
 */
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
$secondaryColor = $settingModel->getValue('secondary_color', '#f4f6f9');
$textColor = $settingModel->getValue('text_color', '#212529');
$appLogo = $settingModel->getValue('app_logo', '');
$appFavicon = $settingModel->getValue('app_favicon', '');
$faviconUrl = !empty($appFavicon) ? base_url($appFavicon) : (!empty($appLogo) ? base_url($appLogo) : base_url('favicon.ico'));
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian: <?= esc($test->name) ?> - <?= esc($appName) ?></title>
    <link rel="icon" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" href="<?= $faviconUrl ?>">
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/outfit.css?v=1.1') ?>" rel="stylesheet">
    <link rel="preload" as="script" fetchpriority="high" href="<?= base_url('assets/exam-app.js?v=' . $assetVersion['app']) ?>">
    <link rel="preload" as="script" fetchpriority="high" href="<?= base_url('vendor/alpinejs/alpine.min.js?v=3.14.8') ?>">
    <link rel="preload" as="script" fetchpriority="high" href="<?= base_url('vendor/jquery/jquery-3.6.0.min.js') ?>">
    <script>
        (function () {
            // ── Boot-first: hubungi server & muat data ujian SEBELUM aset besar
            // selesai diunduh. Berjalan paralel, tanpa jQuery — fetch native.
            var API = <?= json_encode($apiBaseUrl) ?>;
            var boot = { state: 'pending', status: 'Menghubungi server…', data: null, error: null };
            window.__boot = boot;

            window.__bootPromise = (async function () {
                var csrfName = 'csrf_test_name';
                var csrfToken = '';
                try {
                    var csrfRes = await fetch(API + '/health', {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: AbortSignal.timeout(20000)
                    });
                    csrfToken = csrfRes.headers.get('X-CSRF-TOKEN') || '';

                    var body = new URLSearchParams();
                    body.set('test_id', <?= (int) $test->id ?>);
                    if (csrfToken) body.set(csrfName, csrfToken);

                    var res = await fetch(API + '/api/exam/init', {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: body.toString(),
                        signal: AbortSignal.timeout(20000)
                    });
                    if (!res.ok) throw new Error('init HTTP ' + res.status);
                    var data = await res.json();
                    boot.state = 'ok';
                    boot.status = 'Data ujian diterima, menyiapkan tampilan…';
                    boot.data = data;
                    return data;
                } catch (e) {
                    boot.state = 'error';
                    boot.status = 'Gagal menghubungi server. Mencoba ulang…';
                    boot.error = e;
                    return null;
                }
            })();

            document.addEventListener('DOMContentLoaded', function () {
                var statusEl = document.getElementById('bootStatus');
                if (!statusEl) return;
                var timer = setInterval(function () {
                    statusEl.textContent = boot.status;
                    if (boot.state === 'ok' || boot.state === 'error') clearInterval(timer);
                }, 250);
            });
        })();
    </script>
    <script>
        (function () {
            // Katex dimuat LAZY: hanya jika soal benar-benar mengandung rumus
            // (klasik .ql-formula atau delimiters $$). Hemat ~300KB dari
            // critical path pada ujian tanpa rumus.
            var katexLoading = false, katexQueue = [];
            var KATEX_BASE = <?= json_encode(base_url()) ?>;

            window.ensureKatex = function (cb) {
                if (window.katex && window.renderMathInElement) { cb(); return; }
                katexQueue.push(cb);
                if (katexLoading) return;
                katexLoading = true;

                var css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = KATEX_BASE + 'vendor/katex/katex.min.css';
                document.head.appendChild(css);

                function loadScript(src, done) {
                    var s = document.createElement('script');
                    s.src = src;
                    s.onload = done;
                    s.onerror = done; // jangan blokir ujian jika katex gagal
                    document.head.appendChild(s);
                }
                loadScript(KATEX_BASE + 'vendor/katex/katex.min.js', function () {
                    loadScript(KATEX_BASE + 'vendor/katex/auto-render.min.js', function () {
                        var q = katexQueue; katexQueue = [];
                        q.forEach(function (f) { try { f(); } catch (e) {} });
                    });
                });
            };

            window.renderMath = function () {
                var container = document.querySelector('.question-container');
                if (!container) return;

                var hasFormulas = container.querySelector('.ql-formula') !== null ||
                                  (container.textContent || '').indexOf('$$') !== -1;
                if (!hasFormulas) return;

                window.ensureKatex(function () {
                    container.querySelectorAll('.ql-formula').forEach(function (el) {
                        if (!el.hasAttribute('data-rendered')) {
                            var math = el.getAttribute('data-value');
                            if (math) {
                                try { katex.render(math, el, { throwOnError: false }); } catch (e) {}
                            }
                            el.setAttribute('data-rendered', 'true');
                        }
                    });
                    if (typeof renderMathInElement !== 'undefined') {
                        renderMathInElement(container, {
                            delimiters: [
                                { left: '$$', right: '$$', display: true },
                                { left: '\\(', right: '\\)', display: false },
                                { left: '\\[', right: '\\]', display: true }
                            ],
                            throwOnError: false
                        });
                    }
                });
            };

            document.addEventListener('DOMContentLoaded', function () {
                setTimeout(window.renderMath, 500);
            });
        })();
    </script>
    <script defer src="<?= base_url('assets/exam-app.js?v=' . $assetVersion['app']) ?>" onerror="window.__assetsFailed=true;window.__checkFallback&&window.__checkFallback()"></script>
    <script defer src="<?= base_url('vendor/alpinejs/alpine.min.js?v=3.14.8') ?>" onerror="window.__assetsFailed=true;window.__checkFallback&&window.__checkFallback()"></script>
    <script defer src="<?= base_url('vendor/sweetalert2/sweetalert2.min.js') ?>" onerror="window.__assetsFailed=true;window.__checkFallback&&window.__checkFallback()"></script>
    <style>
        :root {
            --color-background: <?= $secondaryColor ?>;
            --color-primary: <?= $primaryColor ?>;
            --color-primary-rgb: <?= sscanf($primaryColor, "#%02x%02x%02x")[0] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[1] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[2] ?>;
            --color-primary-dark: color-mix(in srgb, var(--color-primary) 85%, black);
            --color-surface: #ffffff;
            --color-text: <?= $textColor ?>;
            --color-text-muted: #6c757d;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
        }
        body {
            background-color: var(--color-background); 
            color: var(--color-text);
            font-family: 'Outfit', sans-serif;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            padding-bottom: 80px; /* Space for bottom nav */
        }
        .noselect { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }

        /* Loading Screen — tetap inline agar sheet tampil walau CSS utama belum tiba */
        .loading-screen {
            position:fixed; inset:0; z-index:100001;
            background:var(--color-background); display:flex; flex-direction:column; align-items:center; justify-content:center;
            color: var(--color-text);
        }
    </style>
    <link href="<?= base_url('assets/exam-app.css?v=' . $assetVersion['css']) ?>" rel="stylesheet" onerror="window.__assetsFailed=true;window.__checkFallback&&window.__checkFallback()">
</head>
<body class="noselect">
    <?php include __DIR__ . '/../../layouts/_frontend_config.php'; ?>
    <script>
        const EXAM_CONFIG = {
            testId: <?= $test->id ?>,
            testName: <?= json_encode($test->name) ?>,
            durationMinutes: <?= (int)$test->duration_minutes ?>,
            passingScore: <?= (float)$test->passing_score ?>,
            maxScore: <?= (float)$test->max_score ?>,
            showMenu: <?= (int)$test->show_menu ?>,
            allowNoanswer: <?= (int)$test->allow_noanswer ?>,
            autoLogoutOnTimeout: <?= (int)$test->auto_logout_on_timeout ?>,
            hasPassword: <?= !empty($test->password) ? 'true' : 'false' ?>,
            antiCheat: <?= json_encode($antiCheat) ?>,
            apiBaseUrl: '<?= esc($apiBaseUrl) ?>',
            appBaseUrl: '<?= base_url() ?>',
            questionsData: <?= json_encode($questionsData ?? []) ?>,
            answersData: <?= json_encode($answersData ?? []) ?>,
            randomQuestions: <?= $test->random_questions ? 'true' : 'false' ?>,
            randomAnswers: <?= $test->random_answers ? 'true' : 'false' ?>,
            generatedAt: <?= (int)$generatedAt ?>,
            wsUrl: '<?= esc($wsUrl ?? '') ?>'
        };

        // Question and answer ordering is now handled server-side per-attempt.
        // The init API returns display_order for each question/answer,
        // and the client reorders DOM elements after receiving the response.
    </script>

    <!-- Loading Screen -->
    <div class="loading-screen" id="loadingScreen">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;" role="status">
            <span class="visually-hidden">Memuat...</span>
        </div>
        <h5 class="text-muted">Memuat ujian...</h5>
        <p class="text-muted small mt-2" id="bootStatus" style="margin-top:8px;">Menghubungi server…</p>
    </div>

    <!-- Image Lightbox Overlay -->
    <div class="image-lightbox" id="imageLightbox">
        <div class="image-lightbox-close" id="imageLightboxClose">&times;</div>
        <img src="" id="imageLightboxImg" alt="Preview">
    </div>

    <!-- Suspend Overlay (Anti-Cheat) -->
    <div class="suspend-overlay" id="suspendOverlay" style="display:none;">
        <?php if (!empty($antiCheat['logo'])): ?>
            <img src="<?= base_url($antiCheat['logo']) ?>" alt="Warning Logo" class="mb-4" id="antiCheatLogoImg" style="max-height: 120px;">
        <?php else: ?>
            <img src="" alt="Warning Logo" class="mb-4" id="antiCheatLogoImg" style="max-height: 120px; display: none;">
        <?php endif; ?>
        
        <h2 class="fw-bold text-danger mb-3" id="antiCheatTitle"><?= esc($antiCheat['title']) ?></h2>
        <p class="fs-5 px-4 mb-4" style="max-width:600px;" id="antiCheatMessage"><?= esc($antiCheat['message']) ?></p>
        
        <div class="mb-4">
            <span class="fs-1 fw-bold text-white" id="suspendTimerDisplay" style="font-size:5rem !important;"><?= $antiCheat['suspend_timer'] ?></span>
        </div>
        
        <p class="mb-2 text-warning">Pelanggaran: <span id="strikeCount" class="fw-bold fs-5">1</span> / <span id="maxStrikes" class="fw-bold fs-5"><?= $antiCheat['max_strikes'] ?></span></p>
    </div>

    <!-- EXAM CONTENT -->
    <div id="examContent" style="display:none;" x-data="examApp()">
        <!-- Offline Overlay -->
        <div x-show="isOffline" style="display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; flex-direction: column;" :class="{'d-flex': isOffline}">
            <div class="bg-white rounded-4 shadow-lg p-5 text-center" style="max-width: 450px; width: 90%;">
                <div class="mb-4 d-flex justify-content-center">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; background: rgba(220, 53, 69, 0.1);">
                        <i class="bi bi-wifi-off text-danger" style="font-size: 2.5rem;"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-dark mb-3">Koneksi Terputus!</h3>
                <p class="text-secondary mb-4">Sistem mendeteksi Anda sedang offline. Ujian dihentikan sementara hingga koneksi internet Anda kembali. Harap segera sambungkan ulang perangkat Anda ke jaringan.</p>
                <div class="alert alert-success border-success-subtle py-3 text-start d-flex align-items-center">
                    <i class="bi bi-shield-check text-success fs-3 me-3"></i>
                    <div>
                        <strong class="text-success d-block">Jangan Khawatir!</strong>
                        <span class="text-success small">Jawaban Anda sebelumnya sudah tersimpan dengan aman di dalam perangkat.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Penyimpanan server tak terjangkau: ujian dibekukan di tempat.
             Sengaja tidak memindahkan halaman — sesi ada di Redis, jadi kalau
             Redis yang tumbang siswa tidak akan bisa login kembali. Dengan
             bertahan di sini, jawaban di memori utuh dan menyusul sendiri
             begitu server pulih. -->
        <div x-show="storageDown" style="display: none; position: fixed; inset: 0; z-index: 100000; background: rgba(15, 23, 42, 0.97); backdrop-filter: blur(8px); align-items: center; justify-content: center; flex-direction: column;" :class="{'d-flex': storageDown}">
            <div class="bg-white rounded-4 shadow-lg p-5 text-center" style="max-width: 480px; width: 90%;">
                <div class="mb-4 d-flex justify-content-center">
                    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 80px; height: 80px; background: rgba(255, 193, 7, 0.15);">
                        <i class="bi bi-database-exclamation text-warning" style="font-size: 2.5rem;"></i>
                    </div>
                </div>
                <h3 class="fw-bold text-dark mb-3">Ujian Dihentikan Sementara</h3>
                <p class="text-secondary mb-2" x-text="storageDownMsg"></p>
                <p class="text-secondary mb-4"><strong>Jangan tutup atau muat ulang halaman ini.</strong> Segera panggil pengawas.</p>
                <div class="alert alert-success border-success-subtle py-3 text-start d-flex align-items-center mb-3">
                    <i class="bi bi-shield-check text-success fs-3 me-3"></i>
                    <div>
                        <strong class="text-success d-block">Jawaban Anda tidak hilang</strong>
                        <span class="text-success small">Semua jawaban masih tersimpan di perangkat ini dan akan dikirim otomatis begitu server pulih.</span>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-center text-secondary small">
                    <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                    <span>Mencoba menyambung ulang otomatis…</span>
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
                        <i class="bi bi-clock-history"></i> <span x-text="formatTime(timeLeft)">--:--:--</span>
                    </div>
                </template>
                <button type="button" class="btn text-dark border-0 p-1 btn-sidebar-toggle" data-bs-toggle="offcanvas" data-bs-target="#questionGridSheet">
                    <i class="bi bi-grid-fill fs-3"></i>
                </button>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-wrapper">
            <div class="progress-fill" :style="'width: ' + ((countAnswered() / questions.length) * 100) + '%'"></div>
        </div>

        <!-- Autosave Indicator -->
        <div class="autosave-chip" x-show="showSavedToast" x-transition.opacity.duration.300ms style="display: none;">
            <i class="bi bi-check-circle-fill" style="color: #198754;"></i> Tersimpan
        </div>
        <div class="autosave-chip" x-show="showErrorToast" x-transition.opacity.duration.300ms style="display: none; background-color: rgba(220, 53, 69, 0.9);">
            <i class="bi bi-x-circle-fill" style="color: #fff;"></i> <span style="color: #fff;">Koneksi Gagal (Offline)</span>
        </div>
        <div class="autosave-chip" x-show="isSaving" x-transition.opacity.duration.150ms style="display: none;">
            <span class="spinner-border spinner-border-sm text-primary" role="status" style="width: 1rem; height: 1rem;"></span> Menyimpan...
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
                    <textarea class="form-control" rows="8" style="border-radius:12px;" x-model="currentQuestion.answer_text" @input="_typingQid = qKey(currentQuestion)" @input.debounce.500ms="saveAnswer(_typingQid)" placeholder="Tulis jawaban Anda di sini..."></textarea>
                </div>
            </template>
            
            <!-- Type 4: Matching -->
            <template x-if="currentQuestion.question_type == 4">
                <div>
                    <div class="alert alert-info border-0 rounded-3 mb-4" style="font-size:14px;">
                        <i class="bi bi-info-circle me-1"></i> Jodohkan Kiri (Premis) dengan Kanan (Jawaban) yang tepat.
                    </div>
                    <template x-for="(pair, i) in currentQuestion.matchingPairs" :key="i">
                        <div class="row align-items-center mb-3 p-3 rounded-3" style="background: rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.05);">
                            <div class="col-12 col-md-6 fw-bold mb-2 mb-md-0" x-html="pair.left"></div>
                            <div class="col-12 col-md-6">
                                <select class="form-select border-primary" style="border-radius:8px;" :value="pair.selected" @change="updateMatching(i, $event.target.value)">
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
                    <div class="alert alert-info border-0 rounded-3 mb-4" style="font-size:14px;">
                        <i class="bi bi-info-circle me-1"></i> Pilih Benar atau Salah untuk setiap pernyataan di bawah ini.
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="text-center" style="font-size:14px; opacity:0.8;">
                                <tr>
                                    <th class="text-start border-bottom">Pernyataan</th>
                                    <th class="border-bottom" style="width: 80px;">Benar</th>
                                    <th class="border-bottom" style="width: 80px;">Salah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(pair, i) in currentQuestion.matchingPairs" :key="i">
                                    <tr>
                                        <td x-html="pair.left" class="fs-6 py-3 border-bottom"></td>
                                        <td class="text-center py-3 border-bottom">
                                            <input type="radio" :name="'tf_' + currentQuestion.question_id + '_' + i" value="Benar" :checked="pair.selected === 'Benar'" class="form-check-input" style="transform: scale(1.5);" @change="updateMatching(i, 'Benar')">
                                        </td>
                                        <td class="text-center py-3 border-bottom">
                                            <input type="radio" :name="'tf_' + currentQuestion.question_id + '_' + i" value="Salah" :checked="pair.selected === 'Salah'" class="form-check-input" style="transform: scale(1.5);" @change="updateMatching(i, 'Salah')">
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
                <i class="bi bi-chevron-left"></i> <span class="d-none d-sm-inline">Sebelumnya</span>
            </button>
            <button class="btn-flag" :class="{'active': currentQuestion.is_flagged}" @click="toggleFlag()">
                <i class="bi bi-flag-fill"></i>
            </button>
            <button class="btn-nav filled" @click="nextQuestion()">
                <span class="d-none d-sm-inline" x-text="currentIndex === questions.length - 1 ? 'Selesai' : 'Selanjutnya'"></span>
                <i class="bi bi-chevron-right" x-show="currentIndex !== questions.length - 1"></i>
                <i class="bi bi-check-lg" x-show="currentIndex === questions.length - 1"></i>
            </button>
        </div>

        <!-- Mobile Sidebar (offcanvas-end) -->
        <div class="offcanvas offcanvas-end" tabindex="-1" id="questionGridSheet">
            <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title fw-bold"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Navigasi Soal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
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
                    <div class="sidebar-legend-item"><span class="legend-dot" style="background:rgba(0,0,0,0.08); border: 1px solid rgba(0,0,0,0.15);"></span> Belum: <strong x-text="questions.length - countAnswered()"></strong></div>
                </div>
            </div>
        </div>

    </div><!-- /exam-main -->

    <!-- Desktop Sidebar -->
    <div class="desktop-drawer-wrapper d-none d-lg-block">
        <div class="desktop-drawer-trigger">
            <i class="bi bi-grid-3x3-gap-fill text-primary mb-2 fs-5"></i>
            <div style="writing-mode: vertical-rl; text-orientation: mixed; font-size: 13px; font-weight: 700; letter-spacing: 2px; color: var(--color-text-muted);">
                SOAL
            </div>
        </div>
        
        <div class="desktop-drawer">
            <div class="drawer-header border-bottom pb-3 mb-4">
                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Navigasi Soal</h5>
            </div>
            
            <div class="q-grid-container mb-4">
                <template x-for="(q, idx) in questions" :key="q.log_id || q.question_id">
                    <button class="q-grid-btn shadow-sm" :class="getGridButtonClass(idx)" @click="goToQuestion(idx)" x-text="idx + 1"></button>
                </template>
            </div>
            
            <div class="sidebar-legend mb-4">
                <div class="sidebar-legend-item"><span class="legend-dot shadow-sm" style="background:var(--color-primary);"></span> Dijawab: <strong x-text="countAnswered()"></strong></div>
                <div class="sidebar-legend-item"><span class="legend-dot shadow-sm" style="background:var(--color-warning);"></span> Ditandai: <strong x-text="countFlagged()"></strong></div>
                <div class="sidebar-legend-item"><span class="legend-dot shadow-sm" style="background:rgba(0,0,0,0.04); border: 1px solid rgba(0,0,0,0.1);"></span> Belum: <strong x-text="questions.length - countAnswered()"></strong></div>
            </div>
            
            <div class="mt-auto pt-3 border-top">
                <button type="button" class="btn btn-danger w-100 rounded-3 fw-bold py-2 shadow-sm" style="height: 48px;" @click="confirmFinish()">
                    <i class="bi bi-stop-circle-fill me-2"></i>Akhiri Ujian
                </button>
            </div>
        </div>
    </div>
    </div><!-- /exam-layout -->

        <!-- Finish Confirmation Modal -->
        <div class="modal fade" id="finishModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-3 border-0 shadow">
                    <div class="modal-header border-bottom-0 pb-0">
                        <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Konfirmasi Selesai</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body py-4 text-center">
                        <div x-show="countAnswered() < questions.length" class="alert alert-warning border-0">
                            <i class="bi bi-exclamation-triangle-fill fs-4 d-block mb-2"></i>
                            Masih ada <strong><span x-text="questions.length - countAnswered()"></span> soal</strong> yang belum dijawab!
                        </div>
                        <p class="mb-0 fs-5">Apakah Anda yakin ingin mengakhiri ujian ini?</p>
                        <p class="text-muted small mt-2">Anda tidak dapat mengubah jawaban lagi setelah ini.</p>
                    </div>
                    <div class="modal-footer border-top-0 pt-0 justify-content-center">
                        <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Batal, Lanjut Kerjakan</button>
                        <button type="button" class="btn btn-success px-5 fw-bold" @click="submitFinish()">Ya, Selesai</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warning Minimum Score Modal -->
        <div class="modal fade" id="warningFinishModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-3 border-0 shadow">
                    <div class="modal-header bg-danger text-white border-bottom-0 pb-3">
                        <h5 class="modal-title fw-bold"><i class="bi bi-x-octagon-fill me-2"></i>Peringatan Nilai Minimum</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body py-4 text-center">
                        <i class="bi bi-exclamation-triangle text-danger" style="font-size: 4rem;"></i>
                        <h4 class="fw-bold mt-3">Belum Memenuhi Syarat!</h4>
                        <p class="mb-0 fs-5">Nilai ujian Anda saat ini belum memenuhi kriteria batas kelulusan.</p>
                        <p class="text-danger fw-bold mt-2">Anda diwajibkan untuk melanjutkan pengerjaan ujian!</p>
                    </div>
                    <div class="modal-footer border-top-0 pt-0 flex-column">
                        <button type="button" class="btn btn-primary w-100 py-2 fw-bold mb-2" data-bs-dismiss="modal">
                            <i class="bi bi-pencil-square me-2"></i>Kembali Mengerjakan
                        </button>
                        <button type="button" class="btn btn-link text-danger w-100 text-decoration-none" @click="forceSubmit()">
                            Akhiri sekarang juga (Nyerah)
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unanswered Required Modal (allow_noanswer = 0) -->
        <div class="modal fade" id="unansweredRequiredModal" tabindex="-1" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-3 border-0 shadow">
                    <div class="modal-header bg-warning border-bottom-0 pb-3">
                        <h5 class="modal-title fw-bold text-dark"><i class="bi bi-exclamation-circle-fill me-2"></i>Soal Belum Lengkap</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body py-4 text-center">
                        <i class="bi bi-clipboard-x text-warning" style="font-size: 4rem;"></i>
                        <h4 class="fw-bold mt-3">Jawab Semua Soal!</h4>
                        <p class="mb-0 fs-5">Anda masih memiliki <strong><span x-text="questions.length - countAnswered()"></span> soal</strong> yang belum dijawab.</p>
                        <p class="text-muted mt-2">Ujian ini mewajibkan semua soal dijawab sebelum dapat diselesaikan.</p>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-primary w-100 py-2 fw-bold" data-bs-dismiss="modal">
                            <i class="bi bi-pencil-square me-2"></i>Kembali Mengerjakan
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /examContent -->

    <script defer src="<?= base_url('vendor/jquery/jquery-3.6.0.min.js') ?>" onerror="window.__assetsFailed=true;window.__checkFallback&&window.__checkFallback()"></script>
    <!-- jQuery defer — setup global dijalankan oleh <script defer> INLINE (dieksekusi
         setelah semua defer selesai, sebelum DOMContentLoaded) -->
    <script defer>
        if (typeof window.jQuery === 'undefined') {
            // jQuery gagal/terlambat dimuat — fallback renderer yang menangani.
            window.__jqAbsent = true;
        } else {
            $(function () {
                $.ajaxSetup({
                    xhrFields: { withCredentials: true }
                });
                // Automatically update CSRF token on every AJAX response
                $(document).ajaxComplete(function(event, xhr, settings) {
                    const csrfHeader = xhr.getResponseHeader('X-CSRF-TOKEN');
                    if (csrfHeader) {
                        CSRF_HASH = csrfHeader;
                        $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': csrfHeader } });
                    }
                });
            });
        }
    </script>
    <script defer src="<?= base_url('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>" onerror="window.__assetsFailed=true;window.__checkFallback&&window.__checkFallback()"></script>
    <script>
        window.CBT_EXAM_CONFIG = {
            examId: EXAM_CONFIG.testId || "0",
            token: (window.__examData && window.__examData.wsToken) || ""
        };
    </script>
    <script src="<?= base_url('js/kiosk-integration.js') ?>"></script>
    <script>
    (function () {
        // ═══ FALLBACK RENDERER (VANILLA — tanpa jQuery/Alpine) ═══
        // Aktif hanya ketika aset besar (jquery/bootstrap/alpine/swal) gagal
        // atau sangat lambat dimuat. Boot-first di <head> sudah memberi data
        // ujian, jadi soal tetap bisa dikerjakan + jawaban tersimpan otomatis.
        var ENGAGE_AFTER_MS = 4000;
        var engaged = false;
        var state = null;
        var root = null;
        var saveStatusEl = null;
        var timerInterval = null;
        var watchTimer = null;
        var saveTimer = null;
        var savePending = false;
        var saveFailed = false;
        var flagged = {};
        var warningShown = false;
        var finishing = false;
        var lastSaveAt = 0;

        var EXAM_CFG = window.EXAM_CONFIG || {};
        var API_BASE = EXAM_CFG.apiBaseUrl || '';
        var TEST_ID = EXAM_CFG.testId || 0;
        var GENERATED_AT = EXAM_CFG.generatedAt || 0;
        var PASSING_SCORE = EXAM_CFG.passingScore || 0;

        function buildFd(fields) {
            var fd = new FormData();
            if (state && state.csrfName && state.csrfHash) fd.append(state.csrfName, state.csrfHash);
            for (var k in fields) {
                if (Array.isArray(fields[k])) {
                    fields[k].forEach(function (v) { fd.append(k + '[]', v); });
                } else {
                    fd.append(k, fields[k]);
                }
            }
            return fd;
        }

        function updateCsrf(res) {
            if (!res || !state) return;
            if (res.csrf_name) state.csrfName = res.csrf_name;
            if (res.csrf_token) state.csrfHash = res.csrf_token;
            if (res.csrf_hash) state.csrfHash = res.csrf_hash;
        }

        async function postJson(url, fields) {
            var res = await fetch(API_BASE + url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: (state && state.csrfHash) ? { 'X-CSRF-TOKEN': state.csrfHash } : {},
                body: buildFd(fields),
                signal: AbortSignal.timeout(20000)
            });
            var data = {};
            try { data = await res.json(); } catch (e) {}
            updateCsrf(data);
            if (res.status === 401 || res.status === 403) {
                window.location.href = API_BASE + '/login';
                throw new Error('session-expired');
            }
            return data;
        }

        function engage(data) {
            if (engaged) return;
            engaged = true;
            if (watchTimer) clearInterval(watchTimer);

            state = {
                attemptId: data.attempt_id,
                csrfName: data.csrf_name || 'csrf_test_name',
                csrfHash: data.csrf_token || data.csrf_hash || '',
                idx: 0,
                endTimeMs: 0,
                timeOffset: 0
            };

            // ── State soal: UTAMA dari response init API (mandiri, tidak
            // bergantung pada EXAM_CONFIG yang mungkin gagal dimuat) ──
            var mergedQuestions, mergedAnswers;

            if (data.questions && data.questions.length) {
                mergedQuestions = data.questions.map(function (q) {
                    return {
                        log_id: q.log_id, question_id: q.question_id,
                        question_text: q.question_text, question_type: q.question_type,
                        display_order: q.display_order, num_answers: q.num_answers,
                        answer_text: q.answer_text || '', is_unsure: q.is_unsure || 0
                    };
                });
                mergedAnswers = {};
                mergedQuestions.forEach(function (q) {
                    var list = (data.answers && (data.answers[q.log_id] || data.answers[q.question_id])) || [];
                    mergedAnswers[q.question_id] = list.map(function (a) {
                        return {
                            answer_id: a.answer_id, answer_text: a.answer_text,
                            display_order: a.display_order, is_selected: a.is_selected || 0
                        };
                    });
                });
            } else {
                // Cadangan: snapshot statis (hanya bila init tidak mengirim soal)
                mergedQuestions = JSON.parse(JSON.stringify(EXAM_CFG.questionsData || []));
                mergedAnswers = JSON.parse(JSON.stringify(EXAM_CFG.answersData || {}));

                if (data.answers) {
                    for (var qId in data.answers) {
                        if (mergedAnswers[qId]) {
                            var savedMap = {};
                            data.answers[qId].forEach(function (sa) { savedMap[sa.answer_id] = sa.is_selected; });
                            mergedAnswers[qId].forEach(function (ma) {
                                if (savedMap[ma.answer_id] !== undefined) ma.is_selected = savedMap[ma.answer_id];
                            });
                        }
                    }
                }
                if (data.questions) {
                    var qSavedMap = {};
                    data.questions.forEach(function (q) { qSavedMap[q.question_id] = q; });
                    mergedQuestions.forEach(function (mq) {
                        if (qSavedMap[mq.question_id]) mq.answer_text = qSavedMap[mq.question_id].answer_text || '';
                    });
                }
            }

            if (mergedQuestions.length && mergedQuestions[0].display_order !== undefined) {
                mergedQuestions.sort(function (a, b) { return (a.display_order || 0) - (b.display_order || 0); });
                mergedQuestions.forEach(function (q, i) { q.display_order = i + 1; });
            }
            for (var k in mergedAnswers) {
                if (Array.isArray(mergedAnswers[k]) && mergedAnswers[k].length && mergedAnswers[k][0].display_order !== undefined) {
                    mergedAnswers[k].sort(function (a, b) { return (a.display_order || 0) - (b.display_order || 0); });
                }
            }
            mergedQuestions.forEach(function (q) {
                if (q.question_type == 4 || q.question_type == 5) {
                    q.matchingPairs = [];
                    var savedMatching = {};
                    try { if (q.answer_text) savedMatching = JSON.parse(q.answer_text); } catch (e) {}
                    var rights = [];
                    (mergedAnswers[q.question_id] || []).forEach(function (a) {
                        var parts = (a.answer_text || '').split('|::|');
                        if (parts[0] && parts[1]) {
                            q.matchingPairs.push({ left: parts[0], right: parts[1], selected: savedMatching[parts[0]] || '' });
                            rights.push(parts[1]);
                        }
                    });
                    q.matchingOptions = rights.sort(function () { return 0.5 - Math.random(); });
                }
            });

            state.questions = mergedQuestions;
            state.answers = mergedAnswers;

            var savedIdx = localStorage.getItem('current_question_index_' + state.attemptId);
            if (savedIdx !== null) {
                var parsed = parseInt(savedIdx, 10);
                if (!isNaN(parsed) && parsed >= 0 && parsed < mergedQuestions.length) state.idx = parsed;
            }

            state.endTimeMs = (data.test && data.test.begin_time_ms) || Date.now();
            state.timeOffset = ((data.test && data.test.server_now_ms) || Date.now()) - Date.now();
            if (EXAM_CFG.durationMinutes > 0) {
                state.endTimeMs += EXAM_CFG.durationMinutes * 60000;
            } else {
                state.endTimeMs = 0;
            }

            document.getElementById('examContent').style.display = 'none';
            var loading = document.getElementById('loadingScreen');
            if (loading) loading.style.display = 'none';

            renderShell();
            renderQuestion();
            startHeartbeat();

            if (state.endTimeMs > 0) {
                timerInterval = setInterval(tick, 1000);
                tick();
            }
            setSaveStatus('Mode ringan — ' + mergedQuestions.length + ' soal dimuat — jawaban tersimpan otomatis', 'info');
        }

        function renderShell() {
            root = document.createElement('div');
            root.id = 'fallbackRoot';
            root.style.cssText = 'position:fixed;inset:0;z-index:20000;background:#f1f4f9;overflow:auto;font-family:system-ui,-apple-system,sans-serif;';
            root.innerHTML =
                '<div class="fb-header" style="position:sticky;top:0;background:#fff;border-bottom:1px solid #dee2e6;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">' +
                    '<strong id="fbTestName" style="font-size:15px;color:#1f2937;"></strong>' +
                    '<span id="fbTimer" style="font-size:15px;font-weight:700;color:#dc3545;font-variant-numeric:tabular-nums;"></span>' +
                    '<span id="fbSaveStatus" style="font-size:13px;color:#6c757d;"></span>' +
                '</div>' +
                '<div class="fb-body" style="max-width:860px;margin:16px auto;padding:0 16px 120px;">' +
                    '<div class="fb-card" style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);margin-bottom:14px;border:2px solid transparent;" id="fbCard">' +
                        '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f1f3f5;">' +
                            '<span style="font-size:13px;font-weight:600;color:#495057;">Soal <span id="fbNo"></span> dari <span id="fbTotal"></span></span>' +
                            '<button id="fbFlag" type="button" style="border:none;background:none;font-size:13px;color:#6c757d;padding:4px 8px;border-radius:6px;">⚑ Tandai</button>' +
                        '</div>' +
                        '<div style="padding:18px;" id="fbBody"></div>' +
                    '</div>' +
                    '<div id="fbGrid" style="display:flex;flex-wrap:wrap;gap:6px;"></div>' +
                '</div>' +
                '<div class="fb-nav" style="position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #dee2e6;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;z-index:5;">' +
                    '<button id="fbPrev" type="button" style="padding:9px 18px;border-radius:10px;border:1px solid #dee2e6;background:#fff;color:#374151;font-weight:600;">← Sebelumnya</button>' +
                    '<button id="fbNext" type="button" style="padding:9px 18px;border-radius:10px;border:none;background:#4f46e5;color:#fff;font-weight:600;">Selanjutnya →</button>' +
                '</div>';
            document.body.appendChild(root);
            root.querySelector('#fbTestName').textContent = EXAM_CFG.testName || 'Ujian';
            root.querySelector('#fbTotal').textContent = state.questions.length;
            root.querySelector('#fbPrev').addEventListener('click', function () { goTo(state.idx - 1); });
            root.querySelector('#fbNext').addEventListener('click', onNext);
            root.querySelector('#fbFlag').addEventListener('click', toggleFlag);
            root.addEventListener('change', onRootChange);
            root.addEventListener('input', onRootInput);
            saveStatusEl = root.querySelector('#fbSaveStatus');
        }

        function currentQ() { return state.questions[state.idx]; }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function renderQuestion() {
            var q = currentQ();
            if (!q) return;
            try {
                renderQuestionInner(q);
            } catch (e) {
                var fbBody = root.querySelector('#fbBody');
                if (fbBody) {
                    fbBody.innerHTML = '<div style="color:#dc3545;font-size:14px;">Gagal merender soal: ' +
                        escapeHtml(String((e && e.message) || e)) + '</div>';
                }
                setSaveStatus('Gagal merender soal — muat ulang halaman', 'err');
            }
        }

        function renderQuestionInner(q) {
            var card = root.querySelector('#fbCard');
            card.style.borderColor = flagged[q.question_id] ? '#e11d48' : 'transparent';
            root.querySelector('#fbFlag').textContent = flagged[q.question_id] ? '⚑ Tandai (aktif)' : '⚑ Tandai';
            root.querySelector('#fbNo').textContent = state.idx + 1;
            root.querySelector('#fbPrev').style.visibility = state.idx === 0 ? 'hidden' : 'visible';
            root.querySelector('#fbNext').innerHTML = (state.idx === state.questions.length - 1)
                ? '<span>Selesai</span> ✓' : 'Selanjutnya →';

            var html = '<div style="font-size:15.5px;line-height:1.65;color:#111827;min-height:60px;">' + q.question_text + '</div>';
            var ansList = state.answers[q.question_id] || [];

            if (q.question_type == 3) {
                var val = escapeHtml(q.answer_text || '');
                html += '<textarea data-qid="' + q.question_id + '" id="fbEssay" rows="8" style="width:100%;margin-top:16px;border:1px solid #d1d5db;border-radius:10px;padding:12px;font-size:14.5px;line-height:1.6;">' + val + '</textarea>';
            } else if (q.question_type == 4 || q.question_type == 5) {
                html += '<div style="margin-top:16px;">';
                (q.matchingPairs || []).forEach(function (pair, i) {
                    html += '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #f1f3f5;">';
                    html += '<div style="flex:1;font-size:14.5px;color:#111827;">' + pair.left + '</div>';
                    if (q.question_type == 5) {
                        html += '<div style="display:flex;gap:16px;">' +
                            '<label style="font-size:14px;"><input type="radio" name="fb_tf_' + i + '" value="Benar" data-qid="' + q.question_id + '" data-match="' + i + '"' + (pair.selected === 'Benar' ? ' checked' : '') + '> Benar</label>' +
                            '<label style="font-size:14px;"><input type="radio" name="fb_tf_' + i + '" value="Salah" data-qid="' + q.question_id + '" data-match="' + i + '"' + (pair.selected === 'Salah' ? ' checked' : '') + '> Salah</label>' +
                        '</div>';
                    } else {
                        html += '<select data-qid="' + q.question_id + '" data-match="' + i + '" style="flex:1;max-width:280px;border:1px solid #d1d5db;border-radius:8px;padding:8px;font-size:14px;">' +
                            '<option value="">-- Pilih Jawaban --</option>';
                        (q.matchingOptions || []).forEach(function (opt) {
                            html += '<option value="' + escapeHtml(opt) + '"' + (pair.selected === opt ? ' selected' : '') + '>' + escapeHtml(opt) + '</option>';
                        });
                        html += '</select>';
                    }
                    html += '</div>';
                });
                html += '</div>';
            } else {
                html += '<div style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">';
                ansList.forEach(function (a) {
                    var multi = (q.question_type == 2);
                    var checked = a.is_selected == 1;
                    var input = multi
                        ? '<input type="checkbox" data-qid="' + q.question_id + '" data-aid="' + a.answer_id + '"' + (checked ? ' checked' : '') + ' style="width:18px;height:18px;flex-shrink:0;">'
                        : '<input type="radio" name="fb_opt_' + q.question_id + '" data-qid="' + q.question_id + '" data-aid="' + a.answer_id + '"' + (checked ? ' checked' : '') + ' style="width:18px;height:18px;flex-shrink:0;">';
                    html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fafbfc;font-size:14.5px;line-height:1.55;cursor:pointer;">' +
                        input + '<span>' + a.answer_text + '</span></label>';
                });
                html += '</div>';
            }

            root.querySelector('#fbBody').innerHTML = html;
            renderGrid();

            var essay = document.getElementById('fbEssay');
            if (essay) essay.value = q.answer_text || '';

            if (window.ensureKatex) {
                window.ensureKatex(function () {
                    var body = root.querySelector('#fbBody');
                    if (!body) return;
                    body.querySelectorAll('.ql-formula').forEach(function (el) {
                        var math = el.getAttribute('data-value');
                        if (math && window.katex) {
                            try { katex.render(math, el, { throwOnError: false }); } catch (e) {}
                        }
                    });
                    if (window.renderMathInElement) {
                        renderMathInElement(body, {
                            delimiters: [
                                { left: '$$', right: '$$', display: true },
                                { left: '\\(', right: '\\)', display: false },
                                { left: '\\[', right: '\\]', display: true }
                            ],
                            throwOnError: false
                        });
                    }
                });
            }
        }

        function renderGrid() {
            var grid = root.querySelector('#fbGrid');
            var html = '';
            state.questions.forEach(function (q, i) {
                var answered = isAnswered(q);
                var cls = answered ? '#10b981' : '#d1d5db';
                var txt = answered ? '#fff' : '#4b5563';
                if (i === state.idx) cls = '#4f46e5';
                html += '<button type="button" data-goto="' + i + '" style="width:42px;height:42px;border-radius:10px;border:none;background:' + cls + ';color:' + txt + ';font-weight:700;font-size:14px;' + (flagged[q.question_id] ? 'outline:3px solid #e11d48;' : '') + '">' + (i + 1) + '</button>';
            });
            grid.innerHTML = html;
            grid.querySelectorAll('[data-goto]').forEach(function (btn) {
                btn.addEventListener('click', function () { goTo(parseInt(btn.getAttribute('data-goto'), 10)); });
            });
        }

        function isAnswered(q) {
            if (q.question_type == 3) return !!(q.answer_text && q.answer_text.trim() !== '');
            if (q.question_type == 4 || q.question_type == 5) {
                return (q.matchingPairs || []).length > 0 && q.matchingPairs.every(function (p) { return p.selected !== ''; });
            }
            return (state.answers[q.question_id] || []).some(function (a) { return a.is_selected == 1; });
        }

        function goTo(idx) {
            if (idx < 0 || idx >= state.questions.length) return;
            state.idx = idx;
            localStorage.setItem('current_question_index_' + state.attemptId, idx);
            renderQuestion();
        }

        function toggleFlag() {
            var q = currentQ();
            if (!q) return;
            flagged[q.question_id] = !flagged[q.question_id];
            renderQuestion();
        }

        function onRootChange(e) {
            var t = e.target;
            var qid = t.getAttribute('data-qid');
            if (!qid) return;
            var q = state.questions.find(function (x) { return String(x.question_id) === qid; });
            if (!q) return;

            if (q.question_type == 2) {
                var a = state.answers[qid].find(function (x) { return String(x.answer_id) === t.getAttribute('data-aid'); });
                if (a) a.is_selected = t.checked ? 1 : 0;
            } else if (q.question_type == 4 || q.question_type == 5) {
                var i = parseInt(t.getAttribute('data-match'), 10);
                if (q.matchingPairs[i]) q.matchingPairs[i].selected = t.value;
            } else {
                state.answers[qid].forEach(function (x) {
                    x.is_selected = (String(x.answer_id) === t.getAttribute('data-aid') && t.checked) ? 1 : 0;
                });
            }
            renderGrid();
            scheduleSave();
        }

        function onRootInput(e) {
            var t = e.target;
            if (t.id !== 'fbEssay') return;
            var q = currentQ();
            if (q) {
                q.answer_text = t.value;
                renderGrid();
                scheduleSave();
            }
        }

        function scheduleSave() {
            if (!engaged || finishing) return;
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(doSave, 800);
            setSaveStatus('Menyimpan…', '');
        }

        function doSave() {
            saveTimer = null;
            if (!engaged || finishing) return;
            var q = currentQ();
            if (!q) return;

            var payload = { attempt_id: state.attemptId, question_id: q.question_id, question_type: q.question_type, generated_at: GENERATED_AT };
            if (q.question_type == 3) {
                payload.answer_text = q.answer_text || '';
            } else if (q.question_type == 4 || q.question_type == 5) {
                var matches = {};
                q.matchingPairs.forEach(function (p) { matches[p.left] = p.selected; });
                payload.matching_answers_json = JSON.stringify(matches);
            } else {
                payload.selected_answers = (state.answers[q.question_id] || [])
                    .filter(function (a) { return a.is_selected == 1; })
                    .map(function (a) { return a.answer_id; });
            }

            savePending = true;
            return postJson('/api/exam/autosave', payload)
                .then(function (res) {
                    if (res.status === 'kicked') {
                        window.location.href = API_BASE + '/login';
                        return;
                    }
                    lastSaveAt = Date.now();
                    saveFailed = false;
                    setSaveStatus('Tersimpan ' + new Date().toLocaleTimeString(), 'ok');
                })
                .catch(function (err) {
                    if (String(err && err.message).indexOf('session-expired') !== -1) return;
                    saveFailed = true;
                    setSaveStatus('Gagal menyimpan — mencoba ulang…', 'err');
                    setTimeout(function () {
                        if (engaged && !finishing && saveFailed) doSave();
                    }, 2500);
                })
                .finally(function () { savePending = false; });
        }

        function startHeartbeat() {
            setInterval(function () {
                if (!engaged || !state.attemptId) return;
                postJson('/api/exam/auto-sync', { attempt_id: state.attemptId })
                    .then(function (res) {
                        if (res.exam_mode !== undefined) {
                            if (res.exam_mode !== 'static' || !res.static_page_path) {
                                window.location.href = API_BASE + '/student/exam/take/' + TEST_ID;
                            }
                        }
                    })
                    .catch(function () {});
            }, 60000);
        }

        function tick() {
            var now = Date.now() + state.timeOffset;
            var distance = state.endTimeMs - now;
            if (distance <= 0) {
                clearInterval(timerInterval);
                root.querySelector('#fbTimer').textContent = '00:00:00';
                finishExam(true);
                return;
            }
            var h = Math.floor(distance / 3600000);
            var m = Math.floor((distance % 3600000) / 60000);
            var s = Math.floor((distance % 60000) / 1000);
            root.querySelector('#fbTimer').textContent =
                (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            if (distance <= 300000 && !warningShown) {
                warningShown = true;
                setSaveStatus('⚠ Waktu tersisa 5 menit!', 'warn');
            }
        }

        function onNext() {
            if (state.idx < state.questions.length - 1) {
                goTo(state.idx + 1);
            } else {
                finishExam(false);
            }
        }

        function finishExam(auto) {
            if (finishing) return;
            finishing = true;

            if (!auto) {
                var unanswered = state.questions.filter(function (q) { return !isAnswered(q); }).length;
                if (EXAM_CFG.allowNoanswer === 0 && unanswered > 0) {
                    alert('Masih ada ' + unanswered + ' soal yang belum dijawab.');
                    finishing = false;
                    return;
                }
                if (!confirm('Apakah Anda yakin ingin mengakhiri ujian?')) {
                    finishing = false;
                    return;
                }
            }

            postJson('/api/exam/check-score', { attempt_id: state.attemptId })
                .then(function (res) {
                    var proceed = true;
                    if (res.status === 'success' && res.score < PASSING_SCORE) {
                        proceed = confirm('Nilai Anda ' + res.score + ' (di bawah nilai minimal ' + PASSING_SCORE + '). Apakah tetap ingin mengakhiri ujian?');
                    }
                    if (!proceed) {
                        finishing = false;
                        return;
                    }
                    return postJson('/api/exam/finish', { test_id: TEST_ID, attempt_id: state.attemptId })
                        .then(function (res2) {
                            if (window.CommsBridge) window.CommsBridge.requestExit('');
                            if (res2.redirect) window.location.href = res2.redirect;
                            else window.location.href = API_BASE + '/student/results/view/' + TEST_ID;
                        });
                })
                .catch(function () {
                    finishing = false;
                    alert('Gagal menyelesaikan ujian. Periksa koneksi dan coba lagi.');
                });
        }

        function setSaveStatus(msg, kind) {
            if (!saveStatusEl) return;
            saveStatusEl.textContent = msg;
            if (kind === 'ok') saveStatusEl.style.color = '#10b981';
            else if (kind === 'err') saveStatusEl.style.color = '#dc3545';
            else if (kind === 'warn') saveStatusEl.style.color = '#b45309';
            else saveStatusEl.style.color = '#6c757d';
        }

        function assetsReady() {
            return !!(window.jQuery && window.Alpine && window.__appReady);
        }

        function tryEngage() {
            if (engaged || !window.__boot) return;
            if (window.__boot.state === 'ok' && window.__boot.data && window.__boot.data.status === 'success') {
                engage(window.__boot.data);
            } else if (window.__boot.state === 'error' && !window.jQuery) {
                // Boot gagal DAN jQuery tidak ada (tidak bisa tampilkan Swal)
                document.getElementById('examContent').style.display = 'none';
                var loading = document.getElementById('loadingScreen');
                if (loading) loading.style.display = 'none';
                var el = document.createElement('div');
                el.style.cssText = 'position:fixed;inset:0;z-index:20000;background:#fff;display:flex;align-items:center;justify-content:center;font-family:system-ui;padding:24px;text-align:center;';
                el.innerHTML = '<div><h4 style="color:#dc3545;margin-bottom:12px;">Tidak dapat terhubung ke server</h4>' +
                    '<p style="color:#6b7280;font-size:14px;">Periksa koneksi Anda, lalu muat ulang halaman.</p>' +
                    '<button onclick="location.reload()" style="margin-top:8px;padding:9px 20px;border:none;border-radius:10px;background:#4f46e5;color:#fff;font-weight:600;">Muat Ulang</button></div>';
                document.body.appendChild(el);
                if (watchTimer) clearInterval(watchTimer);
            }
        }

        window.__checkFallback = function () {
            if (engaged) return;
            tryEngage();
        };

        // Pemburu: tunggu boot sukses; beri kesempatan aset 12 detik, atau
        // segera bergabung jika salah satu aset dinyatakan GAGAL via onerror.
        (function poll() {
            if (window.__boot && window.__boot.state !== 'pending') {
                var t0 = (window.__boot._t || (window.__boot._t = Date.now()));
                if (window.__assetsFailed || (window.__boot.state === 'ok' && Date.now() - t0 > ENGAGE_AFTER_MS && !assetsReady())) {
                    tryEngage();
                    return;
                }
                if (assetsReady()) return; // UI penuh berfungsi — tidak perlu fallback
            }
            watchTimer = setTimeout(poll, 500);
        })();
    })();
    </script>
</body>
</html>

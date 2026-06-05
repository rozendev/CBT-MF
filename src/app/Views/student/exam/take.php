<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
$secondaryColor = $settingModel->getValue('secondary_color', '#f4f6f9');
$appLogo = $settingModel->getValue('app_logo', '');
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
$antiCheatTitle = $settingModel->getValue('anti_cheat_title', '⚠️ Peringatan Kecurangan!');
$antiCheatMessage = $settingModel->getValue('anti_cheat_message', 'Sistem mendeteksi Anda meninggalkan halaman ujian.');
$antiCheatLogo = $settingModel->getValue('anti_cheat_logo', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian: <?= esc($test->name) ?> - <?= esc($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <style>
        :root {
            --primary-bg: <?= $secondaryColor ?>;
            --bs-primary: <?= $primaryColor ?>;
            --bs-primary-rgb: <?= sscanf($primaryColor, "#%02x%02x%02x")[0] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[1] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[2] ?>;
        }
        .bg-primary { background-color: var(--bs-primary) !important; }
        .text-primary { color: var(--bs-primary) !important; }
        .btn-primary { background-color: var(--bs-primary); border-color: var(--bs-primary); }
        .btn-outline-primary { color: var(--bs-primary); border-color: var(--bs-primary); }
        .btn-outline-primary:hover { background-color: var(--bs-primary); color: #fff; }

        body { background-color: var(--primary-bg); }
        .exam-header { background-color: #fff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,.04); }
        .q-grid-btn { width: 40px; height: 40px; padding: 0; display: flex; align-items: center; justify-content: center; font-weight: 600; margin: 3px; border-radius: 8px; }
        .q-grid-btn.answered { background-color: #198754; color: white; border-color: #198754; }
        .q-grid-btn.current { border: 2px solid var(--bs-primary); background-color: #e9ecef; color: #000; }
        .q-grid-btn.unanswered { background-color: #fff; border: 1px solid #ced4da; color: #495057; }
        .answer-option { display: block; padding: 15px; margin-bottom: 10px; border: 1px solid #dee2e6; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: #fff;}
        .answer-option:hover { background-color: #f8f9fa; border-color: #b1b7bd; }
        .answer-option input:checked + .answer-content { font-weight: bold; }
        .answer-option.selected { border-color: var(--bs-primary); background-color: rgba(var(--bs-primary-rgb), 0.1); }
        .noselect { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }

        /* Fullscreen Gate */
        .fullscreen-gate {
            position: fixed; inset: 0; z-index: 99999;
            background-color: var(--primary-bg);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: #333; text-align: center;
        }
        .fullscreen-gate .gate-icon { font-size: 5rem; margin-bottom: 1.5rem; color: var(--bs-primary); }
        .fullscreen-gate .gate-btn {
            background-color: var(--bs-primary);
            border: none; color: white; font-size: 1.2rem; font-weight: 700;
            padding: 1rem 3rem; border-radius: 50px; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .fullscreen-gate .gate-btn:hover { transform: scale(1.05); box-shadow: 0 8px 25px rgba(var(--bs-primary-rgb),0.4); }

        /* Suspend Overlay */
        .suspend-overlay {
            position: fixed; inset: 0; z-index: 99998;
            background: #000000;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white;
            text-align: center;
        }
        .suspend-overlay .pulse-icon { animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse {
            0%,100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.7; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="noselect">

    <!-- ▼ FULLSCREEN GATE — User MUST click to enter fullscreen (browser requirement) ▼ -->
    <div class="fullscreen-gate" id="fullscreenGate">
        <div class="gate-icon mb-4">
            <?php if ($appLogo): ?>
                <img src="<?= base_url($appLogo) ?>" alt="Logo" style="height: 100px;">
            <?php else: ?>
                <i class="bi bi-shield-lock"></i>
            <?php endif; ?>
        </div>
        <h2 class="fw-bold mb-2">Mode Ujian Aman</h2>
        <p class="text-secondary mb-4 px-4" style="max-width:500px;">
            Ujian ini menggunakan mode layar penuh (<em>fullscreen</em>) untuk mencegah kecurangan.<br>
            Klik tombol di bawah untuk memulai.
        </p>
        <button class="gate-btn" id="enterFullscreenBtn">
            <i class="bi bi-arrows-fullscreen me-2"></i> Masuk Mode Ujian
        </button>
    </div>

    <!-- ▼ SUSPEND OVERLAY (Anti-Cheat) ▼ -->
    <div class="suspend-overlay" id="suspendOverlay" style="display:none;">
        <?php if ($antiCheatLogo): ?>
            <img src="<?= base_url($antiCheatLogo) ?>" alt="Warning Logo" class="mb-4" style="max-height: 120px;">
        <?php endif; ?>
        
        <h2 class="fw-bold text-danger mb-3"><?= esc($antiCheatTitle) ?></h2>
        <p class="fs-5 px-4 mb-4" style="max-width: 600px;"><?= esc($antiCheatMessage) ?></p>
        
        <div class="mb-4">
            <span class="fs-1 fw-bold text-white" id="suspendTimerDisplay" style="font-size: 5rem !important;">30</span>
        </div>
        
        <p class="mb-2 text-warning">Pelanggaran: <span id="strikeCount" class="fw-bold fs-5">1</span> / <span id="maxStrikes" class="fw-bold fs-5">2</span></p>
    </div>

    <!-- ▼ EXAM CONTENT ▼ -->
    <div id="examContent" style="display:none;" x-data="examApp()">
        <!-- Header -->
        <div class="exam-header sticky-top py-3">
            <div class="container-fluid px-4">
                <div class="row align-items-center">
                    <div class="col-md-4">
                        <h5 class="mb-0 fw-bold text-primary text-truncate"><?= esc($test->name) ?></h5>
                        <div class="small text-muted"><?= esc(session('firstname') . ' ' . session('lastname')) ?></div>
                    </div>
                    <div class="col-md-4 text-center">
                        <?php if ($test->duration_minutes > 0): ?>
                            <div class="d-inline-block bg-light border border-danger rounded-pill px-4 py-2">
                                <span class="text-danger fw-bold fs-5" x-text="formatTime(timeLeft)">--:--:--</span>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-secondary rounded-pill px-3 py-2">Waktu Tidak Dibatasi</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4 text-end">
                        <form action="<?= base_url('/student/exam/finish/' . $test->id) ?>" method="POST" id="finishForm" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="button" class="btn btn-success fw-bold" @click="confirmFinish()">
                                <i class="bi bi-check-circle-fill me-1"></i> Selesai Ujian
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container-fluid px-4 py-4">
            <div class="row g-4">
                <div class="col-lg-8 col-xl-9">
                    <div class="card shadow-sm border-0 rounded-3 mb-3">
                        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                            <h5 class="m-0 fw-bold">Soal No. <span x-text="currentIndex + 1"></span> <span class="text-muted fw-normal fs-6 d-none d-sm-inline">dari <?= count($questions) ?></span></h5>
                            <div class="d-flex align-items-center">
                                <div class="spinner-border spinner-border-sm text-primary me-3" role="status" x-show="isSaving">
                                    <span class="visually-hidden">Menyimpan...</span>
                                </div>
                                <button class="btn btn-outline-primary btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#questionGridOffcanvas" aria-controls="questionGridOffcanvas">
                                    <i class="bi bi-grid-3x3-gap-fill me-1"></i> Daftar Soal
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-3 p-md-4 fs-6 fs-md-5" style="min-height: 400px; line-height: 1.6;">
                            <div class="mb-5 text-dark" x-html="currentQuestion.question_text"></div>
                            <template x-if="currentQuestion.question_type == 1">
                                <div>
                                    <template x-for="(answer, i) in currentAnswers" :key="answer.answer_id">
                                        <label class="answer-option" :class="{'selected': answer.is_selected == 1}" @click="selectRadio(answer.answer_id)">
                                            <div class="d-flex align-items-start gap-3">
                                                <input type="radio" :name="'q_' + currentQuestion.log_id" class="form-check-input mt-1" :checked="answer.is_selected == 1">
                                                <div class="answer-content" x-html="answer.answer_text"></div>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                            </template>
                            <template x-if="currentQuestion.question_type == 2">
                                <div>
                                    <template x-for="(answer, i) in currentAnswers" :key="answer.answer_id">
                                        <label class="answer-option" :class="{'selected': answer.is_selected == 1}">
                                            <div class="d-flex align-items-start gap-3">
                                                <input type="checkbox" class="form-check-input mt-1" :checked="answer.is_selected == 1" @change="toggleCheckbox(answer.answer_id, $event.target.checked)">
                                                <div class="answer-content" x-html="answer.answer_text"></div>
                                            </div>
                                        </label>
                                    </template>
                                </div>
                            </template>
                            <template x-if="currentQuestion.question_type == 3">
                                <div>
                                    <textarea class="form-control" rows="8" x-model="currentQuestion.answer_text" @input.debounce.500ms="saveAnswer()" placeholder="Tulis jawaban Anda di sini..."></textarea>
                                </div>
                            </template>
                            <template x-if="currentQuestion.question_type == 4">
                                <div>
                                    <div class="alert alert-info border-0 rounded-0 mb-4">
                                        <i class="bi bi-info-circle me-1"></i> Jodohkan Kiri (Premis) dengan Kanan (Jawaban) yang tepat.
                                    </div>
                                    <template x-for="(pair, i) in currentQuestion.matchingPairs" :key="i">
                                        <div class="row align-items-center mb-3 p-3 bg-light rounded-3 border">
                                            <div class="col-md-6 fw-bold" x-html="pair.left"></div>
                                            <div class="col-md-6">
                                                <select class="form-select border-primary" :value="pair.selected" @change="updateMatching(i, $event.target.value)">
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
                            <template x-if="currentQuestion.question_type == 5">
                                <div>
                                    <div class="alert alert-info border-0 rounded-0 mb-4">
                                        <i class="bi bi-info-circle me-1"></i> Pilih Benar atau Salah untuk setiap pernyataan di bawah ini.
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle">
                                            <thead class="table-light text-center">
                                                <tr>
                                                    <th class="text-start">Pernyataan</th>
                                                    <th style="width: 120px;">Benar</th>
                                                    <th style="width: 120px;">Salah</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(pair, i) in currentQuestion.matchingPairs" :key="i">
                                                    <tr>
                                                        <td x-html="pair.left" class="fs-6"></td>
                                                        <td class="text-center">
                                                            <input type="radio" :name="'tf_' + currentQuestion.log_id + '_' + i" value="Benar" :checked="pair.selected === 'Benar'" class="form-check-input" style="transform: scale(1.5);" @change="updateMatching(i, 'Benar')">
                                                        </td>
                                                        <td class="text-center">
                                                            <input type="radio" :name="'tf_' + currentQuestion.log_id + '_' + i" value="Salah" :checked="pair.selected === 'Salah'" class="form-check-input" style="transform: scale(1.5);" @change="updateMatching(i, 'Salah')">
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="card-footer bg-white py-3 d-flex justify-content-between">
                            <button class="btn btn-outline-secondary" @click="prevQuestion()" :disabled="currentIndex === 0">
                                <i class="bi bi-chevron-left me-1"></i> Sebelumnya
                            </button>
                            <button class="btn btn-primary" @click="nextQuestion()">
                                Selanjutnya <i class="bi bi-chevron-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-xl-3">
                    <div class="offcanvas-lg offcanvas-end" tabindex="-1" id="questionGridOffcanvas" aria-labelledby="questionGridOffcanvasLabel">
                        <div class="offcanvas-header border-bottom">
                            <h5 class="offcanvas-title fw-bold" id="questionGridOffcanvasLabel">Navigasi Soal</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#questionGridOffcanvas" aria-label="Close"></button>
                        </div>
                        <div class="offcanvas-body p-0 d-block">
                            <div class="card shadow-sm border-0 rounded-3 mb-3 w-100">
                                <div class="card-header bg-white border-bottom py-3 d-none d-lg-block"><h6 class="m-0 fw-bold">Navigasi Soal</h6></div>
                                <div class="card-body">
                                    <div class="d-flex flex-wrap justify-content-start">
                                        <template x-for="(q, idx) in questions" :key="q.log_id">
                                            <button class="btn btn-sm q-grid-btn" :class="getGridButtonClass(idx)" @click="goToQuestion(idx)" x-text="idx + 1" data-bs-dismiss="offcanvas" data-bs-target="#questionGridOffcanvas"></button>
                                        </template>
                                    </div>
                                    <hr>
                                    <div class="small text-muted mb-1"><span class="d-inline-block bg-success rounded-circle me-1" style="width:10px;height:10px;"></span> Sudah Dijawab (<span x-text="countAnswered()"></span>)</div>
                                    <div class="small text-muted"><span class="d-inline-block bg-white border rounded-circle me-1" style="width:10px;height:10px;"></span> Belum Dijawab (<span x-text="questions.length - countAnswered()"></span>)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
    </div><!-- /examContent -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const RAW_QUESTIONS = <?= json_encode($questions) ?>;
        const RAW_ANSWERS = <?= json_encode($answers) ?>;
        const SAVE_URL = '<?= base_url('/student/exam/save-answer') ?>';
        const REPORT_CHEAT_URL = '<?= base_url('/student/exam/report-cheat') ?>';
        const ATTEMPT_ID = <?= $attempt->id ?>;
        const DASHBOARD_URL = '<?= base_url('/student/dashboard') ?>';
        const durationMin = <?= (int) $test->duration_minutes ?>;
        const startTime = <?= strtotime($attempt->started_at) * 1000 ?>;

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
            }
        });

        // ═══════════════════════════════════════════════════════
        //  1. FULLSCREEN GATE
        // ═══════════════════════════════════════════════════════
        let examStarted = false;

        document.getElementById('enterFullscreenBtn').addEventListener('click', function() {
            const el = document.documentElement;
            const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
            if (rfs) {
                rfs.call(el).then(function() {
                    document.getElementById('fullscreenGate').style.display = 'none';
                    document.getElementById('examContent').style.display = 'block';
                    examStarted = true;
                }).catch(function() {
                    // If fullscreen fails (e.g. iframe restriction), still allow exam
                    document.getElementById('fullscreenGate').style.display = 'none';
                    document.getElementById('examContent').style.display = 'block';
                    examStarted = true;
                });
            } else {
                // Browser doesn't support fullscreen API
                document.getElementById('fullscreenGate').style.display = 'none';
                document.getElementById('examContent').style.display = 'block';
                examStarted = true;
            }
        });

        // ═══════════════════════════════════════════════════════
        //  2. ALPINE.JS EXAM APP
        // ═══════════════════════════════════════════════════════
        document.addEventListener('alpine:init', () => {
            Alpine.data('examApp', () => ({
                questions: RAW_QUESTIONS,
                allAnswers: RAW_ANSWERS,
                currentIndex: 0,
                isSaving: false,
                timeLeft: 0,
                timerInterval: null,
                warningShown: false,

                init() {
                    // Parse Matching Options for Type 4 and Type 5
                    this.questions.forEach(q => {
                        if (q.question_type == 4 || q.question_type == 5) {
                            q.matchingPairs = [];
                            let rights = [];
                            let savedMatching = {};
                            try { if (q.answer_text) savedMatching = JSON.parse(q.answer_text); } catch(e){}

                            let ansList = this.allAnswers[q.log_id] || [];
                            ansList.forEach(a => {
                                let parts = (a.answer_text || '').split('|::|');
                                let left = parts[0] || '';
                                let right = parts[1] || '';
                                if (left && right) {
                                    q.matchingPairs.push({
                                        left: left,
                                        right: right,
                                        selected: savedMatching[left] || ''
                                    });
                                    rights.push(right);
                                }
                            });
                            q.matchingOptions = rights.sort(() => 0.5 - Math.random());
                        }
                    });

                    // Auto Sync to DB every 60 seconds (Write-Behind Hybrid)
                    setInterval(() => {
                        $.post('<?= base_url('/student/exam/auto-sync') ?>', { attempt_id: ATTEMPT_ID });
                    }, 60000);

                    // ═══ SSE: Real-time Ban/Kick Detection ═══
                    this.initSSE();

                    // ═══ Countdown Timer (if timed exam) ═══
                    if (durationMin > 0) {
                        const endTime = startTime + (durationMin * 60 * 1000);
                        this.timerInterval = setInterval(() => {
                            const now = new Date().getTime();
                            const distance = endTime - now;

                            if (distance <= 0) {
                                clearInterval(this.timerInterval);
                                this.timeLeft = 0;
                                Swal.fire('Waktu Habis!', 'Waktu Anda telah habis! Ujian akan disubmit otomatis.', 'info').then(() => {
                                    this.submitFinish();
                                });
                            } else {
                                this.timeLeft = distance;
                                
                                // Tampilkan notifikasi peringatan jika sisa waktu <= 5 menit (300000 ms)
                                if (distance <= 300000 && !this.warningShown) {
                                    this.warningShown = true;
                                    Swal.fire({
                                        title: 'Peringatan Waktu!',
                                        text: 'Waktu ujian Anda tersisa 5 menit lagi.',
                                        icon: 'warning',
                                        toast: true,
                                        position: 'top-end',
                                        showConfirmButton: false,
                                        timer: 5000,
                                        timerProgressBar: true
                                    });
                                }
                            }
                        }, 1000);
                    }
                },

                /**
                 * Initialize SSE connection for real-time ban/kick detection.
                 * EventSource automatically reconnects on connection loss.
                 */
                initSSE() {
                    if (typeof EventSource === 'undefined') {
                        // Fallback: browser doesn't support SSE (very rare)
                        console.warn('SSE not supported, falling back to polling');
                        this.fallbackPolling();
                        return;
                    }

                    const sseUrl = '<?= base_url('/student/exam/stream/') ?>' + ATTEMPT_ID;
                    this.sseSource = new EventSource(sseUrl);
                    this.sseErrorCount = 0;

                    // Ban event — admin banned the student
                    this.sseSource.addEventListener('ban', (e) => {
                        const data = JSON.parse(e.data);
                        this.sseSource.close();
                        Swal.fire({
                            title: 'Akun Di-Ban',
                            text: data.message,
                            icon: 'error',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.location.href = '<?= base_url('/login') ?>';
                        });
                    });

                    // Kick event — exam locked due to cheating or admin action
                    this.sseSource.addEventListener('kick', (e) => {
                        const data = JSON.parse(e.data);
                        this.sseSource.close();
                        Swal.fire('Sesi Dihentikan', data.message, 'error').then(() => {
                            window.location.href = '<?= base_url('/login') ?>';
                        });
                    });

                    // Finished event — exam was auto-completed
                    this.sseSource.addEventListener('finished', (e) => {
                        const data = JSON.parse(e.data);
                        this.sseSource.close();
                        Swal.fire('Ujian Selesai', data.message, 'info').then(() => {
                            window.location.href = DASHBOARD_URL;
                        });
                    });

                    // Connection established
                    this.sseSource.addEventListener('connected', (e) => {
                        this.sseErrorCount = 0;
                        console.log('SSE connected');
                    });

                    // Error handling — EventSource auto-reconnects, but if too many errors, fallback
                    this.sseSource.onerror = () => {
                        this.sseErrorCount++;
                        if (this.sseErrorCount > 10) {
                            console.warn('SSE too many errors, closing and falling back to polling');
                            this.sseSource.close();
                            this.fallbackPolling();
                        }
                    };
                },

                /**
                 * Fallback polling if SSE is not available or fails repeatedly.
                 * Checks attempt status via saveAnswer endpoint piggybacking.
                 */
                fallbackPolling() {
                    // No-op: rely on MultiLoginFilter + saveAnswer piggybacking
                    // The MultiLoginFilter already checks ban status on every request
                    console.log('SSE fallback: relying on MultiLoginFilter for ban detection');
                },

                get currentQuestion() { return this.questions[this.currentIndex]; },
                get currentAnswers() { return this.allAnswers[this.currentQuestion.log_id] || []; },

                formatTime(ms) {
                    if (ms <= 0) return "00:00:00";
                    let h = Math.floor(ms / 3600000);
                    let m = Math.floor((ms % 3600000) / 60000);
                    let s = Math.floor((ms % 60000) / 1000);
                    return (h<10?"0"+h:h)+":"+(m<10?"0"+m:m)+":"+(s<10?"0"+s:s);
                },

                nextQuestion() {
                    if (this.currentIndex < this.questions.length - 1) this.currentIndex++;
                    else this.confirmFinish();
                },
                prevQuestion() { if (this.currentIndex > 0) this.currentIndex--; },
                goToQuestion(idx) { this.currentIndex = idx; },

                selectRadio(answerId) {
                    this.currentAnswers.forEach(a => { a.is_selected = (a.answer_id == answerId) ? 1 : 0; });
                    this.saveAnswer();
                },
                toggleCheckbox(answerId, isChecked) {
                    let ans = this.currentAnswers.find(a => a.answer_id == answerId);
                    if (ans) ans.is_selected = isChecked ? 1 : 0;
                    this.saveAnswer();
                },
                updateMatching(index, value) {
                    this.questions[this.currentIndex].matchingPairs[index].selected = value;
                    this.saveAnswer();
                },

                saveAnswer() {
                    this.isSaving = true;
                    const logId = this.currentQuestion.log_id;
                    const type = this.currentQuestion.question_type;
                    let data = { log_id: logId, question_type: type };

                    if (type == 3) {
                        data.answer_text = this.currentQuestion.answer_text;
                    } else if (type == 4 || type == 5) {
                        let matches = {};
                        this.currentQuestion.matchingPairs.forEach(p => {
                            matches[p.left] = p.selected;
                        });
                        data.matching_answers_json = JSON.stringify(matches);
                    } else {
                        data.selected_answers = this.currentAnswers.filter(a => a.is_selected == 1).map(a => a.answer_id);
                    }

                    $.post('<?= base_url('/student/exam/autosave') ?>', data)
                     .done((res) => { 
                         this.isSaving = false;
                         if (res.status === 'kicked') {
                             if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                             Swal.fire('Informasi', res.message, 'info').then(() => {
                                 window.location.href = '<?= base_url('/login') ?>';
                             });
                         }
                     })
                     .fail((err) => { 
                         this.isSaving = false; 
                         console.error("Gagal menyimpan jawaban", err);
                         if (err.status === 401 || err.status === 403) {
                             if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                             Swal.fire('Sesi Berakhir', 'Sesi Anda telah habis atau dihentikan.', 'error').then(() => {
                                 window.location.href = '<?= base_url('/login') ?>';
                             });
                         }
                     });
                },

                countAnswered() {
                    let count = 0;
                    this.questions.forEach(q => {
                        if (q.question_type == 3) { 
                            if (q.answer_text && q.answer_text.trim() !== '') count++; 
                        } else if (q.question_type == 4 || q.question_type == 5) {
                            if (q.matchingPairs && q.matchingPairs.every(p => p.selected !== '')) count++;
                        } else { 
                            if ((this.allAnswers[q.log_id]||[]).some(a => a.is_selected == 1)) count++; 
                        }
                    });
                    return count;
                },

                getGridButtonClass(idx) {
                    const q = this.questions[idx];
                    let answered = false;
                    if (q.question_type == 3) answered = (q.answer_text && q.answer_text.trim() !== '');
                    else if (q.question_type == 4 || q.question_type == 5) answered = (q.matchingPairs && q.matchingPairs.every(p => p.selected !== ''));
                    else answered = (this.allAnswers[q.log_id]||[]).some(a => a.is_selected == 1);
                    if (idx === this.currentIndex) return 'current';
                    return answered ? 'answered' : 'unanswered';
                },

                confirmFinish() { 
                    this.isSaving = true;
                    $.post('<?= base_url('/student/exam/check-score') ?>', { attempt_id: ATTEMPT_ID })
                     .done((res) => {
                         this.isSaving = false;
                         if (res.status === 'success') {
                             if (res.score < <?= $test->passing_score ?>) {
                                 // Show Warning Modal
                                 new bootstrap.Modal(document.getElementById('warningFinishModal')).show();
                             } else {
                                 // Passed
                                 new bootstrap.Modal(document.getElementById('finishModal')).show();
                             }
                         } else {
                             new bootstrap.Modal(document.getElementById('finishModal')).show();
                         }
                     })
                     .fail((err) => {
                         this.isSaving = false;
                         new bootstrap.Modal(document.getElementById('finishModal')).show();
                     });
                },

                async forceSubmit() {
                    const w1 = await Swal.fire({
                        title: 'Peringatan 1',
                        text: "Apakah Anda yakin? Nilai Anda saat ini tidak memenuhi syarat kelulusan.",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yakin',
                        cancelButtonText: 'Batal'
                    });
                    if (!w1.isConfirmed) return;
                    
                    const w2 = await Swal.fire({
                        title: 'Peringatan 2',
                        text: "Anda masih memiliki waktu. Yakin ingin benar-benar menyerah?",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yakin Menyerah',
                        cancelButtonText: 'Batal'
                    });
                    if (!w2.isConfirmed) return;
                    
                    const w3 = await Swal.fire({
                        title: 'Peringatan Terakhir',
                        text: "Ujian akan diakhiri secara permanen dengan status gagal. Lanjutkan?",
                        icon: 'error',
                        showCancelButton: true,
                        confirmButtonText: 'Akhiri Ujian',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#d33'
                    });
                    if (w3.isConfirmed) {
                        $('#warningFinishModal').modal('hide');
                        this.submitFinish();
                    }
                },

                submitFinish() {
                    window.isSubmitting = true; // Prevent anti-cheat from triggering
                    document.getElementById('finishForm').submit();
                }
            }));
        });

        // ═══════════════════════════════════════════════════════
        //  3. ANTI-CHEAT ENGINE
        //  Rules:
        //    - Tab switch (visibilitychange) → INSTANT BAN
        //    - Fullscreen exit → Warning overlay (suspend)
        // ═══════════════════════════════════════════════════════
        
        (function() {
            let isSuspended = false;
            let isLocked = false;

            // ── TAB SWITCH → INSTANT BAN ──
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden || !examStarted || isLocked || isSuspended || window.isSubmitting) return;
                isLocked = true;

                $.ajax({
                    url: REPORT_CHEAT_URL,
                    type: 'POST',
                    data: { attempt_id: ATTEMPT_ID, type: 'tab_switch' },
                    dataType: 'json',
                    success: function(res) {
                        if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                        if (res.status === 'success') {
                            Swal.fire('Peringatan', res.message || 'Anda terdeteksi membuka tab lain. Ujian dikunci.', 'warning').then(() => {
                                window.location.href = DASHBOARD_URL;
                            });
                        } else if (res.status === 'suspended') {
                            Swal.fire('Dihentikan', 'Sesi Anda telah dihentikan oleh Admin.', 'error').then(() => {
                                window.location.href = DASHBOARD_URL;
                            });
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Kecurangan terdeteksi. Ujian dikunci.', 'error').then(() => {
                            window.location.href = DASHBOARD_URL;
                        });
                    }
                });
            });

            // ── FULLSCREEN EXIT → WARNING OVERLAY ──
            document.addEventListener('fullscreenchange', function() {
                if (document.fullscreenElement || !examStarted || isSuspended || isLocked || window.isSubmitting) return;
                // User exited fullscreen
                isSuspended = true;

                // Hide exam, show overlay
                document.getElementById('examContent').style.display = 'none';
                var overlay = document.getElementById('suspendOverlay');
                overlay.style.display = 'flex';

                $.ajax({
                    url: REPORT_CHEAT_URL,
                    type: 'POST',
                    data: { attempt_id: ATTEMPT_ID, type: 'fullscreen_exit' },
                    dataType: 'json',
                    success: function(res) {
                        if (res.action === 'lock') {
                            isLocked = true;
                            Swal.fire('Informasi', res.message, 'info').then(() => {
                                window.location.href = '<?= base_url('/login') ?>';
                            });
                        } else if (res.action === 'suspend') {
                            document.getElementById('strikeCount').innerText = res.strike;
                            var sec = parseInt(res.timer);
                            var timerEl = document.getElementById('suspendTimerDisplay');
                            timerEl.innerText = sec;

                            var cd = setInterval(function() {
                                sec--;
                                timerEl.innerText = sec;
                                if (sec <= 0) {
                                    clearInterval(cd);
                                    overlay.style.display = 'none';
                                    isSuspended = false;
                                    // Show gate to re-enter fullscreen
                                    document.getElementById('fullscreenGate').style.display = 'flex';
                                }
                            }, 1000);
                        } else if (res.action === 'none') {
                            // Anti-cheat disabled, just show gate
                            overlay.style.display = 'none';
                            isSuspended = false;
                            document.getElementById('fullscreenGate').style.display = 'flex';
                        }
                    },
                    error: function() {
                        overlay.style.display = 'none';
                        isSuspended = false;
                        document.getElementById('fullscreenGate').style.display = 'flex';
                    }
                });
            });
        })();
    </script>
</body>
</html>


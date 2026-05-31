<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian: <?= esc($test->name) ?> - Sistem Ujian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <style>
        body { background-color: #f4f6f9; }
        .exam-header { background-color: #fff; border-bottom: 1px solid #dee2e6; box-shadow: 0 2px 4px rgba(0,0,0,.04); }
        .q-grid-btn { width: 40px; height: 40px; padding: 0; display: flex; align-items: center; justify-content: center; font-weight: 600; margin: 3px; border-radius: 8px; }
        .q-grid-btn.answered { background-color: #198754; color: white; border-color: #198754; }
        .q-grid-btn.current { border: 2px solid #0d6efd; background-color: #e9ecef; color: #000; }
        .q-grid-btn.unanswered { background-color: #fff; border: 1px solid #ced4da; color: #495057; }
        .answer-option { display: block; padding: 15px; margin-bottom: 10px; border: 1px solid #dee2e6; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: #fff;}
        .answer-option:hover { background-color: #f8f9fa; border-color: #b1b7bd; }
        .answer-option input:checked + .answer-content { font-weight: bold; }
        .answer-option.selected { border-color: #0d6efd; background-color: #f0f7ff; }
        .noselect { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }

        /* Fullscreen Gate */
        .fullscreen-gate {
            position: fixed; inset: 0; z-index: 99999;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white; text-align: center;
        }
        .fullscreen-gate .gate-icon { font-size: 5rem; margin-bottom: 1.5rem; }
        .fullscreen-gate .gate-btn {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border: none; color: white; font-size: 1.2rem; font-weight: 700;
            padding: 1rem 3rem; border-radius: 50px; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .fullscreen-gate .gate-btn:hover { transform: scale(1.05); box-shadow: 0 8px 25px rgba(79,70,229,0.4); }

        /* Suspend Overlay */
        .suspend-overlay {
            position: fixed; inset: 0; z-index: 99998;
            background: rgba(15,23,42,0.97);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white;
        }
        .suspend-overlay .pulse-icon { animation: pulse 2s ease-in-out infinite; }
        @keyframes pulse {
            0%,100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.7; }
        }
    </style>
</head>
<body class="noselect">

    <!-- ▼ FULLSCREEN GATE — User MUST click to enter fullscreen (browser requirement) ▼ -->
    <div class="fullscreen-gate" id="fullscreenGate">
        <div class="gate-icon">🔒</div>
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
        <i class="bi bi-shield-lock-fill text-warning mb-3 pulse-icon" style="font-size: 4rem;"></i>
        <h2 class="fw-bold text-warning mb-2">⚠️ Peringatan Kecurangan!</h2>
        <p class="fs-5 text-center px-4 mb-3">Sistem mendeteksi Anda meninggalkan halaman ujian.</p>
        <p class="mb-4">Pelanggaran: <span id="strikeCount" class="fw-bold text-danger fs-4">1</span> / <span id="maxStrikes" class="fw-bold fs-4">2</span></p>
        <div class="bg-dark bg-opacity-50 rounded-pill px-5 py-3 mb-3">
            <span class="fs-1 fw-bold text-white" id="suspendTimerDisplay">30</span>
            <span class="text-muted ms-2">detik</span>
        </div>
        <p class="text-secondary small">Harap tunggu hingga timer selesai untuk melanjutkan.</p>
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
                            <h5 class="m-0 fw-bold">Soal No. <span x-text="currentIndex + 1"></span> <span class="text-muted fw-normal fs-6">dari <?= count($questions) ?></span></h5>
                            <div class="spinner-border spinner-border-sm text-primary" role="status" x-show="isSaving">
                                <span class="visually-hidden">Menyimpan...</span>
                            </div>
                        </div>
                        <div class="card-body p-4 fs-5" style="min-height: 400px; line-height: 1.6;">
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
                    <div class="card shadow-sm border-0 rounded-3 mb-3">
                        <div class="card-header bg-white border-bottom py-3"><h6 class="m-0 fw-bold">Navigasi Soal</h6></div>
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-start">
                                <template x-for="(q, idx) in questions" :key="q.log_id">
                                    <button class="btn btn-sm q-grid-btn" :class="getGridButtonClass(idx)" @click="goToQuestion(idx)" x-text="idx + 1"></button>
                                </template>
                            </div>
                            <hr>
                            <div class="small text-muted">
                                <span class="badge bg-success me-1">&nbsp;</span> Sudah dijawab
                                <span class="badge bg-light border ms-2 me-1">&nbsp;</span> Belum dijawab
                            </div>
                            <div class="mt-3 text-center">
                                <span class="fw-bold" x-text="countAnswered()"></span> / <?= count($questions) ?> soal dijawab
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

                init() {
                    if (durationMin > 0) {
                        const endTime = startTime + (durationMin * 60 * 1000);
                        this.timerInterval = setInterval(() => {
                            const now = new Date().getTime();
                            const distance = endTime - now;
                            if (distance <= 0) {
                                clearInterval(this.timerInterval);
                                this.timeLeft = 0;
                                alert('Waktu Anda telah habis! Ujian akan disubmit otomatis.');
                                this.submitFinish();
                            } else {
                                this.timeLeft = distance;
                            }
                        }, 1000);
                    }
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

                saveAnswer() {
                    this.isSaving = true;
                    const logId = this.currentQuestion.log_id;
                    const type = this.currentQuestion.question_type;
                    let data = { log_id: logId, question_type: type };

                    if (type == 3) {
                        data.answer_text = this.currentQuestion.answer_text;
                    } else {
                        data.selected_answers = this.currentAnswers.filter(a => a.is_selected == 1).map(a => a.answer_id);
                    }

                    $.post(SAVE_URL, data)
                     .done(() => { this.isSaving = false; })
                     .fail((err) => { this.isSaving = false; console.error("Gagal menyimpan jawaban", err); });
                },

                countAnswered() {
                    let count = 0;
                    this.questions.forEach(q => {
                        if (q.question_type == 3) { if (q.answer_text && q.answer_text.trim() !== '') count++; }
                        else { if ((this.allAnswers[q.log_id]||[]).some(a => a.is_selected == 1)) count++; }
                    });
                    return count;
                },

                getGridButtonClass(idx) {
                    const q = this.questions[idx];
                    let answered = false;
                    if (q.question_type == 3) answered = (q.answer_text && q.answer_text.trim() !== '');
                    else answered = (this.allAnswers[q.log_id]||[]).some(a => a.is_selected == 1);
                    if (idx === this.currentIndex) return 'current';
                    return answered ? 'answered' : 'unanswered';
                },

                confirmFinish() { new bootstrap.Modal(document.getElementById('finishModal')).show(); },
                submitFinish() {
                    if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
                    document.getElementById('finishForm').submit();
                }
            }));
        });

        // ═══════════════════════════════════════════════════════
        //  3. ANTI-CHEAT ENGINE
        //  Rules:
        //    - Tab switch (visibilitychange) → INSTANT BAN
        //    - Fullscreen exit → Warning overlay (suspend)
        //    - Heartbeat poll every 5s → detect admin ban
        // ═══════════════════════════════════════════════════════
        const HEARTBEAT_URL = '<?= base_url('/student/exam/heartbeat') ?>';

        (function() {
            let isSuspended = false;
            let isLocked = false;

            // ── HEARTBEAT: detect admin ban in real-time ──
            setInterval(function() {
                if (isLocked || !examStarted) return;
                $.getJSON(HEARTBEAT_URL + '?attempt_id=' + ATTEMPT_ID, function(res) {
                    if (res.status === 'kicked') {
                        isLocked = true;
                        if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                        alert(res.message);
                        window.location.href = DASHBOARD_URL;
                    }
                });
            }, 5000);

            // ── TAB SWITCH → INSTANT BAN ──
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden || !examStarted || isLocked || isSuspended) return;
                isLocked = true;

                $.ajax({
                    url: REPORT_CHEAT_URL,
                    type: 'POST',
                    data: { attempt_id: ATTEMPT_ID, type: 'tab_switch' },
                    dataType: 'json',
                    success: function(res) {
                        if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                        alert(res.message || 'Anda terdeteksi membuka tab lain. Ujian dikunci.');
                        window.location.href = DASHBOARD_URL;
                    },
                    error: function() {
                        alert('Kecurangan terdeteksi. Ujian dikunci.');
                        window.location.href = DASHBOARD_URL;
                    }
                });
            });

            // ── FULLSCREEN EXIT → WARNING OVERLAY ──
            document.addEventListener('fullscreenchange', function() {
                if (document.fullscreenElement || !examStarted || isSuspended || isLocked) return;
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
                        if (res.action === 'suspend') {
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


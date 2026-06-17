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
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian: <?= esc($test->name) ?> - <?= esc($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            padding-bottom: 80px;
        }

        /* Sidebar Layout */
        .exam-layout {
            display: flex;
            min-height: 100vh;
        }
        .exam-main {
            flex: 1;
            min-width: 0;
        }
        
        /* Beautiful Desktop Drawer */
        .desktop-drawer-wrapper {
            position: fixed;
            right: 0;
            top: 0;
            height: 100vh;
            width: 40px;
            z-index: 1040;
        }
        .desktop-drawer-trigger {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            background: var(--color-surface);
            border: 1px solid rgba(0,0,0,0.08);
            border-right: none;
            border-radius: 16px 0 0 16px;
            padding: 20px 8px;
            box-shadow: -4px 0 15px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .desktop-drawer-wrapper:hover .desktop-drawer-trigger {
            transform: translateY(-50%) translateX(100%);
            opacity: 0;
        }
        .desktop-drawer {
            position: absolute;
            right: -360px;
            top: 0;
            width: 360px;
            height: 100vh;
            background: var(--color-surface);
            box-shadow: -10px 0 30px rgba(0,0,0,0.1);
            transition: right 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            padding: 30px 24px;
            overflow-y: auto;
            border-left: 1px solid rgba(0,0,0,0.05);
        }
        .desktop-drawer-wrapper:hover .desktop-drawer {
            right: 0;
        }
        .desktop-drawer-wrapper:hover {
            width: 360px;
        }
        
        .noselect { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }

        /* Loading & Anti Cheat */
        .loading-screen {
            position:fixed; inset:0; z-index:100001;
            background:var(--color-background); display:flex; flex-direction:column; align-items:center; justify-content:center;
            color: var(--color-text);
        }
        .suspend-overlay {
            position: fixed; inset: 0; z-index: 99998;
            background: #000000;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: white; text-align: center;
        }

        /* Top Navigation */
        .exam-topbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: var(--color-surface);
            border-bottom: 1px solid rgba(0,0,0,0.08);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .exam-title-area {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            max-width: 60%;
        }
        .exam-title-text {
            font-weight: 700;
            font-size: 16px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--color-text);
        }
        .exam-student-text {
            font-size: 12px;
            color: var(--color-text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .exam-timer-chip {
            background: rgba(var(--color-primary-rgb), 0.1);
            color: var(--color-primary);
            padding: 6px 12px;
            border-radius: 9999px;
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .exam-timer-chip.danger {
            background: rgba(220,53,69,0.1);
            color: var(--color-danger);
        }
        
        /* Progress Bar */
        .progress-wrapper {
            width: 100%;
            height: 4px;
            background: rgba(0,0,0,0.05);
            position: sticky;
            top: 61px;
            z-index: 1019;
        }
        .progress-fill {
            height: 100%;
            background: var(--color-primary);
            transition: width 0.3s ease;
        }

        /* Question Area */
        .question-container {
            padding: 24px 32px;
            max-width: 900px;
            margin: 0 auto;
        }
        .question-label {
            font-size: 13px;
            color: var(--color-text-muted);
            font-weight: 600;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .question-text {
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 16px;
            color: var(--color-text);
        }
        .question-text img {
            max-width: 100%;
            height: auto;
        }
        
        /* Answer Options */
        .answer-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            margin-bottom: 8px;
            border: 2px solid rgba(0,0,0,0.08);
            border-radius: 10px;
            background: var(--color-surface);
            cursor: pointer;
            transition: all 150ms ease;
            min-height: 48px;
        }
        .answer-option:hover {
            border-color: rgba(var(--color-primary-rgb), 0.3);
        }
        .answer-option.selected {
            border-color: var(--color-primary);
            background: rgba(var(--color-primary-rgb), 0.05);
        }
        .answer-option .form-check-input {
            margin-top: 3px;
            transform: scale(1.2);
            cursor: pointer;
        }
        .answer-content {
            font-size: 15px;
            line-height: 1.5;
            color: var(--color-text);
            flex-grow: 1;
        }
        .answer-content img {
            max-width: 100%;
            height: auto;
        }

        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--color-surface);
            border-top: 1px solid rgba(0,0,0,0.08);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1020;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.05);
        }
        .btn-nav {
            height: 48px;
            border-radius: 9999px;
            font-weight: 600;
            padding: 0 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 150ms ease;
            border: none;
            cursor: pointer;
        }
        .btn-nav.ghost {
            background: transparent;
            color: var(--color-text-muted);
        }
        .btn-nav.ghost:active { background: rgba(0,0,0,0.05); }
        .btn-nav.filled {
            background: var(--color-primary);
            color: #fff;
        }
        .btn-nav.filled:active { transform: scale(0.96); }
        
        .btn-flag {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 2px solid rgba(0,0,0,0.08);
            background: var(--color-surface);
            color: var(--color-text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 150ms ease;
            cursor: pointer;
        }
        .btn-flag.active {
            border-color: var(--color-warning);
            color: var(--color-warning);
            background: rgba(255,193,7,0.1);
        }

        /* End Exam Button */
        .end-exam-container {
            margin-top: 48px;
            padding-top: 32px;
            border-top: 1px dashed rgba(0,0,0,0.1);
            text-align: center;
        }
        .btn-end-exam {
            background: transparent;
            border: 2px solid var(--color-text-muted);
            color: var(--color-text-muted);
            height: 48px;
            border-radius: 9999px;
            padding: 0 32px;
            font-weight: 600;
            transition: all 150ms ease;
        }
        .btn-end-exam:hover {
            border-color: var(--color-danger);
            color: var(--color-danger);
        }

        /* Sidebar Offcanvas (Mobile) */
        .offcanvas-end {
            width: 300px;
        }
        .q-grid-container {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
        }
        .q-grid-btn {
            width: 100%;
            height: 48px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            border-radius: 12px;
            border: 2px solid transparent;
            font-size: 14px;
            transition: all 150ms ease;
            cursor: pointer;
        }
        .q-grid-btn.answered {
            background-color: var(--color-primary);
            color: white;
        }
        .q-grid-btn.flagged {
            background-color: var(--color-warning);
            color: #000;
        }
        .q-grid-btn.unanswered {
            background-color: rgba(0,0,0,0.04);
            border-color: rgba(0,0,0,0.12);
            color: var(--color-text-muted);
        }
        .q-grid-btn.current {
            border-color: var(--color-text) !important;
        }
        .sidebar-legend {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 12px;
            margin-top: 16px;
            padding: 12px;
            background: rgba(0,0,0,0.03);
            border-radius: 12px;
        }
        .sidebar-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--color-text-muted);
        }
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        /* Auto-save chip */
        .autosave-chip {
            position: fixed;
            bottom: 80px;
            left: 16px;
            background: var(--color-surface);
            color: var(--color-text);
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 6px;
            z-index: 1010;
        }
        /* Hide sidebar toggle on desktop */
        @media (min-width: 992px) {
            .btn-sidebar-toggle {
                display: none !important;
            }
        }

        /* Offline notification banner */
        .offline-banner {
            position: fixed;
            bottom: 80px;
            left: 16px;
            right: auto;
            background: rgba(255, 243, 205, 0.95);
            color: #856404;
            border: 1px solid #ffc107;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1015;
            max-width: calc(100% - 32px);
            backdrop-filter: blur(4px);
        }
        .offline-banner.syncing {
            background: rgba(209, 236, 241, 0.95);
            color: #0c5460;
            border-color: #17a2b8;
        }
        .offline-banner.synced {
            background: rgba(212, 237, 218, 0.95);
            color: #155724;
            border-color: #28a745;
        }
        .offline-banner .close-btn {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            padding: 0;
            font-size: 16px;
            line-height: 1;
            margin-left: 4px;
        }
        .offline-banner .close-btn:hover {
            opacity: 1;
        }

        /* Image Lightbox Overlay */
        .image-lightbox {
            position: fixed; inset: 0; z-index: 1050;
            background: rgba(0,0,0,0.9);
            display: none; flex-direction: column; align-items: center; justify-content: center;
            backdrop-filter: blur(5px);
        }
        .image-lightbox.active { display: flex; }
        .image-lightbox img {
            max-width: 90vw; max-height: 90vh;
            border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            object-fit: contain;
        }
        .image-lightbox-close {
            position: absolute; top: 20px; right: 20px;
            color: white; font-size: 40px; line-height: 1; cursor: pointer;
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
        }
        .question-container img { cursor: zoom-in; }
    </style>
</head>
<body class="noselect">
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
            apiBaseUrl: <?= json_encode($apiBaseUrl) ?>,
            questionsData: <?= json_encode($questionsData ?? []) ?>,
            answersData: <?= json_encode($answersData ?? []) ?>,
            randomQuestions: <?= $test->random_questions ? 'true' : 'false' ?>,
            randomAnswers: <?= $test->random_answers ? 'true' : 'false' ?>,
            generatedAt: <?= $generatedAt ?>,
        };

        if (EXAM_CONFIG.randomQuestions) {
            EXAM_CONFIG.questionsData.sort(() => Math.random() - 0.5);
        }
        if (EXAM_CONFIG.randomAnswers) {
            for (let qId in EXAM_CONFIG.answersData) {
                EXAM_CONFIG.answersData[qId].sort(() => Math.random() - 0.5);
            }
        }
    </script>

    <!-- Loading Screen -->
    <div class="loading-screen" id="loadingScreen">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;" role="status">
            <span class="visually-hidden">Memuat...</span>
        </div>
        <h5 class="text-muted">Memuat ujian...</h5>
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

    <!-- Banned Overlay (Persistent Offline Ban) -->
    <div class="suspend-overlay" id="bannedOverlay" style="display:none; background: linear-gradient(135deg, #000 0%, #1a0000 100%); z-index: 99999;">
        <i class="bi bi-shield-lock-fill text-danger mb-4" style="font-size: 8rem;"></i>
        <h2 class="fw-bold text-danger mb-3">UJIAN DIKUNCI</h2>
        <p class="fs-5 px-4 mb-4" style="max-width:600px;">
            Sistem mendeteksi aktivitas mencurigakan atau pelanggaran aturan ujian yang berulang.<br><br>
            <strong>Anda tidak dapat melanjutkan ujian ini.</strong>
        </p>
        
        <div class="alert alert-danger mx-4 p-4 border-0 rounded-4" style="max-width:500px; background: rgba(220,53,69,0.15); color: #ff8e98;">
            <div class="mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i> <strong>PELANGGARAN TERDETEKSI</strong></div>
            <div class="fs-4 fw-bold mb-3"><span id="bannedStrikeCount">?</span> / <span id="bannedMaxStrikes">?</span></div>
            <p class="small mb-0 text-white-50">Silakan hubungi pengawas ujian untuk informasi lebih lanjut.</p>
        </div>

        <div id="bannedSyncingStatus" class="mt-5 text-white-50">
             <div class="spinner-border spinner-border-sm me-2" role="status"></div>
             Sinkronisasi status pemblokiran ke server...
        </div>
        <div id="bannedErrorStatus" class="mt-5 text-warning" style="display:none;">
             <i class="bi bi-wifi-off me-2"></i>
             Koneksi internet terputus. Menunggu koneksi untuk mengunci permanen...
        </div>
    </div>

    <!-- EXAM CONTENT -->
    <div id="examContent" style="display:none;" x-data="examApp()">
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
                    <div class="exam-timer-chip" :class="{'danger': timeLeft <= 300000}">
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
        <div class="autosave-chip" x-show="isSaving" x-transition.opacity.duration.150ms style="display: none;">
            <span class="spinner-border spinner-border-sm text-primary" role="status" style="width: 1rem; height: 1rem;"></span> Menyimpan...
        </div>

        <!-- Offline/Sync Notification Banner -->
        <div class="offline-banner" x-show="syncStatus === 'offline' && !bannerDismissed" x-transition.opacity.duration.300ms style="display: none;">
            <i class="bi bi-wifi-off"></i>
            <span>Offline. Jawaban disimpan di perangkat.</span>
            <span x-show="pendingCount > 0" class="badge bg-warning text-dark" x-text="pendingCount + ' pending'"></span>
            <button class="close-btn" @click="bannerDismissed = true" title="Tutup">&times;</button>
        </div>
        <div class="offline-banner syncing" x-show="syncStatus === 'syncing'" x-transition.opacity.duration.300ms style="display: none;">
            <span class="spinner-border spinner-border-sm" role="status" style="width: 1rem; height: 1rem;"></span>
            <span>Sync...</span>
        </div>
        <div class="offline-banner synced" x-show="syncStatus === 'synced'" x-transition.opacity.duration.300ms style="display: none;">
            <i class="bi bi-check-circle-fill"></i>
            <span>Tersinkronkan!</span>
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
                    <textarea class="form-control" rows="8" style="border-radius:12px;" x-model="currentQuestion.answer_text" @input.debounce.500ms="saveAnswer()" placeholder="Tulis jawaban Anda di sini..."></textarea>
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

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    let examStarted = false;
    let ATTEMPT_ID  = null;
    let USER_ID     = null;
    let CSRF_NAME   = '';
    let CSRF_HASH   = '';
    let STUDENT_NAME = '';
    let START_TIME  = 0;

    const API = EXAM_CONFIG.apiBaseUrl;

    $.ajaxSetup({
        xhrFields: { withCredentials: true }
    });

    // Automatically update CSRF token on every AJAX response to prevent token out-of-sync
    $(document).ajaxComplete(function(event, xhr, settings) {
        const csrfHeader = xhr.getResponseHeader('X-CSRF-TOKEN');
        if (csrfHeader) {
            CSRF_HASH = csrfHeader;
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': csrfHeader
                }
            });
        }
    });

    function buildFormData(obj) {
        const fd = new FormData();
        if (CSRF_NAME) fd.append(CSRF_NAME, CSRF_HASH);
        for (const key in obj) {
            if (Array.isArray(obj[key])) {
                obj[key].forEach(v => fd.append(key + '[]', v));
            } else {
                fd.append(key, obj[key]);
            }
        }
        return fd;
    }

    function updateCsrf(res) {
        if (res) {
            if (res.csrf_name) CSRF_NAME = res.csrf_name;
            if (res.csrf_token) CSRF_HASH = res.csrf_token;
            if (res.csrf_hash) CSRF_HASH = res.csrf_hash;
        }
    }

    const FETCH_TIMEOUT_MS = 15000;
    const FETCH_MAX_RETRIES = 3;
    const PING_TIMEOUT_MS = 5000;
    const PING_URL = API + '/health';

    function redirectReplace(url) {
        window.location.replace(url);
    }

    async function fetchWithTimeout(url, options = {}, timeoutMs = FETCH_TIMEOUT_MS) {
        return fetch(url, {
            ...options,
            credentials: 'same-origin',
            signal: AbortSignal.timeout(timeoutMs),
        });
    }

    async function fetchWithRetry(url, options = {}, maxRetries = FETCH_MAX_RETRIES, timeoutMs = FETCH_TIMEOUT_MS) {
        let lastError;
        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            try {
                const res = await fetchWithTimeout(url, options, timeoutMs);
                if (res.ok) return res;
                lastError = new Error('HTTP ' + res.status);
            } catch (err) {
                lastError = err;
            }
            if (attempt < maxRetries) {
                await new Promise(r => setTimeout(r, 1000 * (attempt + 1)));
            }
        }
        throw lastError;
    }

    async function pingServer() {
        const res = await fetchWithTimeout(PING_URL, { method: 'GET', cache: 'no-store' }, PING_TIMEOUT_MS);
        return res.ok;
    }

    async function ensureOnline() {
        if (!navigator.onLine) return false;
        try {
            return await pingServer();
        } catch (e) {
            return false;
        }
    }

    async function logoutAndRedirect(loginUrl) {
        try {
            const fd = buildFormData({});
            await fetchWithRetry(API + '/logout', { method: 'POST', body: fd });
        } catch (e) {
            console.warn('Logout request failed, redirecting anyway:', e);
        }
        redirectReplace(loginUrl);
    }

    // ═══ INIT FLOW ═══
    async function initExam() {
        const loading = document.getElementById('loadingScreen');
        loading.style.display = 'flex';

        try {
            const body = { test_id: EXAM_CONFIG.testId };
            if (CSRF_NAME) body[CSRF_NAME] = CSRF_HASH;

            const res = await $.ajax({
                url: API + '/api/exam/init',
                type: 'POST',
                data: body,
                dataType: 'json'
            });

            updateCsrf(res);

            if (res.status === 'need_prepare') {
                window.location.href = API + '/student/exam/prepare/' + EXAM_CONFIG.testId;
                return;
            }

            if (res.status === 'error') {
                loading.style.display = 'none';
                if (res.action === 'logout') {
                    await logoutAndRedirect(API + '/login');
                    return;
                }
                Swal.fire('Error', res.message || 'Terjadi kesalahan saat memuat ujian.', 'error');
                return;
            }

            if (res.status === 'success') {
                if (res.test && (res.test.exam_mode !== 'static' || !res.test.static_page_path)) {
                    window.location.href = API + '/student/exam/take/' + EXAM_CONFIG.testId;
                    return;
                }

                ATTEMPT_ID   = res.attempt_id;
                USER_ID      = res.user ? res.user.id : null;
                STUDENT_NAME = res.user ? (res.user.firstname + ' ' + res.user.lastname).trim() : '';
                START_TIME   = (res.test && res.test.started_at_ms) ? res.test.started_at_ms : Date.now();
                const serverNow = (res.test && res.test.server_now_ms) ? res.test.server_now_ms : Date.now();
                const timeOffset = serverNow - Date.now();

                // Save to sessionStorage for offline recovery
                try {
                    sessionStorage.setItem(`exam_attempt_${EXAM_CONFIG.testId}`, ATTEMPT_ID);
                    sessionStorage.setItem(`exam_student_${EXAM_CONFIG.testId}`, STUDENT_NAME);
                } catch (e) {
                    console.warn('Failed to save to sessionStorage:', e);
                }

                const mergedQuestions = JSON.parse(JSON.stringify(EXAM_CONFIG.questionsData));
                const mergedAnswers = JSON.parse(JSON.stringify(EXAM_CONFIG.answersData));

                if (res.answers) {
                    for (const [qId, savedAnswers] of Object.entries(res.answers)) {
                        if (mergedAnswers[qId]) {
                            const savedMap = {};
                            savedAnswers.forEach(sa => { savedMap[sa.answer_id] = sa.is_selected; });
                            mergedAnswers[qId].forEach(ma => {
                                if (savedMap[ma.answer_id] !== undefined) ma.is_selected = savedMap[ma.answer_id];
                            });
                        }
                    }
                }
                
                if (res.questions) {
                    const qSavedMap = {};
                    res.questions.forEach(q => { qSavedMap[q.question_id] = q; });
                    mergedQuestions.forEach(mq => {
                        if (qSavedMap[mq.question_id]) {
                            mq.answer_text = qSavedMap[mq.question_id].answer_text || '';
                        }
                    });
                }

                window.__examData = {
                    questions: mergedQuestions,
                    answers: mergedAnswers,
                    attemptId: ATTEMPT_ID,
                    studentName: STUDENT_NAME,
                    beginTimeMs: res.test.begin_time_ms,
                    timeOffset: timeOffset,
                    antiCheat: res.anti_cheat || null,
                    user: res.user || null,
                };

                document.dispatchEvent(new CustomEvent('exam-data-loaded'));

                if (res.anti_cheat) {
                    EXAM_CONFIG.antiCheat = res.anti_cheat;
                    document.getElementById('antiCheatTitle').textContent = res.anti_cheat.title || 'Peringatan Kecurangan!';
                    document.getElementById('antiCheatMessage').textContent = res.anti_cheat.message || 'Sistem mendeteksi Anda meninggalkan halaman ujian.';
                    if (res.anti_cheat.suspend_timer) document.getElementById('suspendTimerDisplay').textContent = res.anti_cheat.suspend_timer;
                    if (res.anti_cheat.max_strikes) document.getElementById('maxStrikes').textContent = res.anti_cheat.max_strikes;
                    
                    const logoImg = document.getElementById('antiCheatLogoImg');
                    if (res.anti_cheat.logo) {
                        logoImg.src = '<?= base_url() ?>' + res.anti_cheat.logo;
                        logoImg.style.display = 'inline-block';
                    } else {
                        logoImg.style.display = 'none';
                    }
                }

                loading.style.display = 'none';
                document.getElementById('examContent').style.display = 'block';
                examStarted = true;
            }
        } catch (err) {
            // Offline fallback: if network fails, try to load from baked-in data + LocalStorage
            if (!navigator.onLine || err.readyState === 0 || err.status === 0) {
                console.log('Offline detected - loading from baked-in data and LocalStorage');

                // Use baked-in questions and answers from EXAM_CONFIG
                const mergedQuestions = JSON.parse(JSON.stringify(EXAM_CONFIG.questionsData));
                const mergedAnswers = JSON.parse(JSON.stringify(EXAM_CONFIG.answersData));

                // Restore answers from LocalStorage if available
                try {
                    const storageKey = `exam_offline_static_${EXAM_CONFIG.testId}`;
                    const storage = JSON.parse(localStorage.getItem(storageKey) || '{"pending":{}}');
                    const pending = storage.pending || {};

                    for (const [questionId, answerData] of Object.entries(pending)) {
                        const q = mergedQuestions.find(q => q.question_id == questionId);
                        if (!q) continue;

                        if (q.question_type == 3) {
                            q.answer_text = answerData.data.answer_text || '';
                        } else if (q.question_type == 4 || q.question_type == 5) {
                            q.answer_text = answerData.data.matching_answers_json || '{}';
                        } else {
                            const selectedIds = answerData.data.selected_answers || [];
                            if (mergedAnswers[questionId]) {
                                mergedAnswers[questionId].forEach(ans => {
                                    ans.is_selected = selectedIds.includes(ans.answer_id) ? 1 : 0;
                                });
                            }
                        }
                    }
                } catch (e) {
                    console.error('Failed to restore from LocalStorage:', e);
                }

                // Try to restore attempt_id from session storage or use placeholder
                ATTEMPT_ID = sessionStorage.getItem(`exam_attempt_${EXAM_CONFIG.testId}`) || null;
                STUDENT_NAME = sessionStorage.getItem(`exam_student_${EXAM_CONFIG.testId}`) || '';

                window.__examData = {
                    questions: mergedQuestions,
                    answers: mergedAnswers,
                    attemptId: ATTEMPT_ID,
                    studentName: STUDENT_NAME,
                    beginTimeMs: Date.now(),
                    timeOffset: 0,
                };

                document.dispatchEvent(new CustomEvent('exam-data-loaded'));

                loading.style.display = 'none';
                document.getElementById('examContent').style.display = 'block';
                examStarted = true;

                Swal.fire({
                    title: 'Mode Offline',
                    text: 'Anda sedang offline. Jawaban akan disimpan di perangkat dan disinkronkan saat koneksi kembali.',
                    icon: 'info',
                    toast: true,
                    position: 'top',
                    timer: 4000,
                    showConfirmButton: false
                });
            } else {
                loading.style.display = 'none';
                const msg = (err.responseJSON && err.responseJSON.message) ? err.responseJSON.message : 'Gagal menghubungi server. Periksa koneksi Anda.';
                Swal.fire('Error', msg, 'error');
            }
        }
    }

    // ═══ AUTO FULLSCREEN ═══
    ['click', 'touchstart', 'keydown'].forEach(evt => {
        document.addEventListener(evt, function() {
            if (EXAM_CONFIG.antiCheat && EXAM_CONFIG.antiCheat.enabled === false) return;
            if (!document.fullscreenElement && examStarted && !window.isSubmitting) {
                const el = document.documentElement;
                const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
                if (rfs) rfs.call(el).catch(()=>{});
            }
        });
    });

    // ═══ ALPINE.JS EXAM APP ═══
    document.addEventListener('alpine:init', () => {
        Alpine.data('examApp', () => ({
            questions: [],
            allAnswers: {},
            currentIndex: 0,
            isSaving: false,
            showSavedToast: false,
            timeLeft: EXAM_CONFIG.durationMinutes * 60 * 1000,
            timerInterval: null,
            warningShown: false,
            testName: EXAM_CONFIG.testName,
            studentName: '',
            durationMinutes: EXAM_CONFIG.durationMinutes,
            sseSource: null,
            sseErrorCount: 0,
            syncInterval: null,

            // Offline mode properties
            isOnline: navigator.onLine,
            syncStatus: '',
            pendingCount: 0,
            consecutiveFailures: 0,
            bannerDismissed: false,

            parseMatching() {
                this.questions.forEach(q => {
                    q.is_flagged = false;
                    if (q.question_type == 4 || q.question_type == 5) {
                        q.matchingPairs = [];
                        let rights = [];
                        let savedMatching = {};
                        try { if (q.answer_text) savedMatching = JSON.parse(q.answer_text); } catch(e) {}

                        let ansList = this.allAnswers[q.question_id] || [];
                        ansList.forEach(a => {
                            let parts = (a.answer_text || '').split('|::|');
                            let left  = parts[0] || '';
                            let right = parts[1] || '';
                            if (left && right) {
                                q.matchingPairs.push({ left, right, selected: savedMatching[left] || '' });
                                rights.push(right);
                            }
                        });
                        q.matchingOptions = rights.sort(() => 0.5 - Math.random());
                    }
                });
            },

            init() {
                this.questions = JSON.parse(JSON.stringify(EXAM_CONFIG.questionsData || []));
                this.allAnswers = JSON.parse(JSON.stringify(EXAM_CONFIG.answersData || {}));
                this.parseMatching();

                document.addEventListener('exam-data-loaded', () => {
                    const data = window.__examData || {};
                    this.questions  = data.questions  || this.questions;
                    this.allAnswers = data.answers     || this.allAnswers;
                    this.studentName = data.studentName || '';
                    this.parseMatching();

                    this.initWebSocket();

                    // ═══ ANTI-CHEAT: Server-authoritative sync ═══
                    if (window.__antiCheat) {
                        const syncResult = window.__antiCheat.syncFromServer(data.antiCheat, data.user);
                        if (syncResult.cleared) {
                            window.__antiCheat.restoreExamAfterUnban();
                        } else if (syncResult.data && syncResult.data.banned) {
                            window.__antiCheat.showBannedScreen(syncResult.data.strikes, 'Ujian Anda dikunci oleh server.');
                            return;
                        }
                        window.__antiCheat.handleSuspendBypassOnLoad();
                    }

                    // Check for pending answers after ATTEMPT_ID is set
                    this.updatePendingCount();
                    if (this.pendingCount > 0 && this.isOnline) {
                        console.log(`Found ${this.pendingCount} pending answers after init - syncing...`);
                        this.syncPendingAnswers();
                    }

                    if (this.durationMinutes > 0) {
                        const beginTimeMs = data.beginTimeMs || Date.now();
                        const timeOffset = data.timeOffset || 0;
                        this.endTimeMs = beginTimeMs + (this.durationMinutes * 60 * 1000);

                        this.timerInterval = setInterval(() => {
                            const now = Date.now() + timeOffset;
                            const distance = this.endTimeMs - now;

                            if (distance <= 0) {
                                clearInterval(this.timerInterval);
                                this.timeLeft = 0;
                                Swal.fire('Waktu Habis!', 'Waktu Anda telah habis! Ujian akan disubmit otomatis.', 'info').then(() => {
                                    this.submitFinish();
                                });
                            } else {
                                this.timeLeft = distance;
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
                });

                // ═══ OFFLINE MODE: Event Listeners ═══
                window.addEventListener('online', async () => {
                    const ready = await ensureOnline();
                    if (!ready) return;

                    this.isOnline = true;
                    this.consecutiveFailures = 0;
                    console.log('Back online - validating status and syncing...');

                    if (window.__antiCheat) {
                        await window.__antiCheat.revalidateFromServer();
                    }

                    this.syncPendingAnswers();
                });

                window.addEventListener('offline', () => {
                    this.isOnline = false;
                    this.syncStatus = 'offline';
                    this.updatePendingCount();
                    console.log('Went offline - answers will be saved locally');
                });

                // Check for pending answers on page load (only if ATTEMPT_ID is already set)
                this.updatePendingCount();
                if (this.pendingCount > 0 && this.isOnline && ATTEMPT_ID) {
                    console.log(`Found ${this.pendingCount} pending answers - syncing...`);
                    this.syncPendingAnswers();
                }

                this.syncInterval = setInterval(() => {
                    if (!ATTEMPT_ID) return;
                    const fd = buildFormData({ attempt_id: ATTEMPT_ID });
                    $.ajax({
                        url: API + '/api/exam/auto-sync',
                        type: 'POST',
                        data: fd,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: (res) => { 
                            updateCsrf(res); 
                            if (res.exam_mode !== undefined) {
                                if (res.exam_mode !== 'static' || !res.static_page_path) {
                                    window.isSubmitting = true;
                                    window.location.href = API + '/student/exam/take/' + EXAM_CONFIG.testId;
                                } else {
                                    const expectedUrl = API + '/' + res.static_page_path;
                                    const currentPath = window.location.pathname;
                                    if (!expectedUrl.includes(currentPath)) {
                                        window.isSubmitting = true;
                                        window.location.href = expectedUrl;
                                    }
                                }
                            }
                        }
                    });
                }, 60000);
            },

            initWebSocket() {
                if (!ATTEMPT_ID) {
                    return;
                }
                const urlObj = new URL(API);
                const protocol = urlObj.protocol === 'https:' ? 'wss:' : 'ws:';
                const wsHost = urlObj.host;
                const wsUrl = `${protocol}//${wsHost}/ws/?user_id=${USER_ID}&attempt_id=${ATTEMPT_ID}`;
                
                this.connectWebSocket(wsUrl);
            },

            connectWebSocket(wsUrl) {
                this.ws = new WebSocket(wsUrl);

                this.ws.onopen = () => {
                    this.wsErrorCount = 0;
                    console.log('WebSocket connected');

                    // Connection restored - sync pending answers
                    if (!this.isOnline) {
                        this.isOnline = true;
                        this.consecutiveFailures = 0;
                        console.log('Connection restored via WebSocket - syncing pending answers...');
                        if (this.pendingCount > 0) {
                            this.syncPendingAnswers();
                        } else {
                            this.syncStatus = '';
                        }
                    }
                };

                this.ws.onmessage = (e) => {
                    const payload = JSON.parse(e.data);
                    const eventName = payload.event;
                    const d = payload.data || {};

                    if (eventName === 'ban') {
                        this.ws.close();
                        Swal.fire({ title:'Akun Di-Ban', text:d.message, icon:'error', allowOutsideClick:false, allowEscapeKey:false, confirmButtonText:'OK' })
                            .then(() => logoutAndRedirect(API + '/login'));
                    }
                    else if (eventName === 'kick') {
                        this.ws.close();
                        Swal.fire('Sesi Dihentikan', d.message, 'error')
                            .then(() => logoutAndRedirect(API + '/login'));
                    }
                    else if (eventName === 'finished') {
                        this.ws.close();
                        Swal.fire('Ujian Selesai', d.message, 'info')
                            .then(() => { window.location.href = API + '/student/dashboard'; });
                    }
                    else if (eventName === 'extend_time') {
                        if (d.test_id == EXAM_CONFIG.testId) {
                            this.durationMinutes = d.duration_minutes;
                            const data = window.__examData || {};
                            const beginTimeMs = data.beginTimeMs || Date.now();
                            this.endTimeMs = beginTimeMs + (this.durationMinutes * 60 * 1000);
                            this.warningShown = false;
                            
                            Swal.fire({
                                title: 'Waktu Ditambahkan!',
                                text: 'Admin telah menambahkan waktu ujian. Silakan periksa sisa waktu Anda.',
                                icon: 'success',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 5000,
                                timerProgressBar: true
                            });
                        }
                    }
                    else if (eventName === 'sync_mode') {
                        if (d.exam_mode !== 'static' || !d.static_page_path) {
                            window.isSubmitting = true;
                            window.location.href = API + '/student/exam/take/' + EXAM_CONFIG.testId;
                        } else {
                            const expectedUrl = API + '/' + d.static_page_path;
                            const currentPath = window.location.pathname;
                            if (!expectedUrl.includes(currentPath)) {
                                window.isSubmitting = true;
                                window.location.href = expectedUrl;
                            }
                        }
                    }
                };

                this.ws.onclose = (e) => {
                    console.warn('WebSocket closed', e);

                    // Mark as offline when WebSocket disconnects
                    if (this.isOnline && !window.isSubmitting) {
                        this.isOnline = false;
                        this.syncStatus = 'offline';
                        this.updatePendingCount();
                        console.log('WebSocket disconnected - switched to offline mode');
                    }

                    this.reconnectWebSocket(wsUrl);
                };

                this.ws.onerror = (err) => {
                    console.error('WebSocket error', err);
                    this.ws.close();
                };
            },

            reconnectWebSocket(wsUrl) {
                if (!this.wsErrorCount) this.wsErrorCount = 0;
                this.wsErrorCount++;
                
                if (this.wsErrorCount > 10) {
                    console.warn('WebSocket reconnect limit reached');
                    return;
                }
                
                const delay = Math.min(1000 * Math.pow(2, this.wsErrorCount), 30000);
                console.log(`Reconnecting WebSocket in ${delay}ms...`);
                
                setTimeout(() => {
                    this.connectWebSocket(wsUrl);
                }, delay);
            },

            get currentQuestion() { return this.questions[this.currentIndex] || {}; },
            get currentAnswers()  { return this.allAnswers[this.currentQuestion.question_id] || []; },

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
                this.checkExamMode();
            },
            prevQuestion() { 
                if (this.currentIndex > 0) this.currentIndex--; 
                this.checkExamMode();
            },
            goToQuestion(idx) { 
                this.currentIndex = idx; 
                this.checkExamMode();
            },
            closeMobileSidebar() {
                const el = document.getElementById('questionGridSheet');
                if (el) {
                    const bs = bootstrap.Offcanvas.getInstance(el);
                    if (bs) bs.hide();
                }
            },

            checkExamMode() {
                if (!ATTEMPT_ID) return;
                const fd = buildFormData({ attempt_id: ATTEMPT_ID });
                $.ajax({
                    url: API + '/api/exam/auto-sync',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: (res) => { 
                        updateCsrf(res); 
                        if (res.exam_mode !== undefined) {
                            if (res.exam_mode !== 'static' || !res.static_page_path) {
                                window.isSubmitting = true;
                                window.location.href = API + '/student/exam/take/' + EXAM_CONFIG.testId;
                            } else {
                                const expectedUrl = API + '/' + res.static_page_path;
                                const currentPath = window.location.pathname;
                                if (!expectedUrl.includes(currentPath)) {
                                    window.isSubmitting = true;
                                    window.location.href = expectedUrl;
                                }
                            }
                        }
                    }
                });
            },

            selectRadio(answerId) {
                let currentSelected = this.currentAnswers.find(a => a.is_selected == 1);
                if (currentSelected && currentSelected.answer_id == answerId) return;
                this.currentAnswers.forEach(a => { a.is_selected = (a.answer_id == answerId) ? 1 : 0; });
                this.saveAnswer();
            },
            toggleCheckbox(answerId, isChecked) {
                let ans = this.currentAnswers.find(a => a.answer_id == answerId);
                if (ans) {
                    if (ans.is_selected == (isChecked ? 1 : 0)) return;
                    ans.is_selected = isChecked ? 1 : 0;
                }
                this.saveAnswer();
            },
            updateMatching(index, value) {
                if (this.questions[this.currentIndex].matchingPairs[index].selected === value) return;
                this.questions[this.currentIndex].matchingPairs[index].selected = value;
                this.saveAnswer();
            },

            saveAnswer() {
                const questionId = this.currentQuestion.question_id;
                const type  = this.currentQuestion.question_type;
                let data = { attempt_id: ATTEMPT_ID, question_id: questionId, question_type: type, generated_at: EXAM_CONFIG.generatedAt };

                if (type == 3) {
                    data.answer_text = this.currentQuestion.answer_text || '';
                } else if (type == 4 || type == 5) {
                    let matches = {};
                    this.currentQuestion.matchingPairs.forEach(p => { matches[p.left] = p.selected; });
                    data.matching_answers_json = JSON.stringify(matches);
                } else {
                    data.selected_answers = this.currentAnswers.filter(a => a.is_selected == 1).map(a => a.answer_id);
                }

                // Always save to LocalStorage first
                this.saveToLocalStorage(questionId, type, data);
                this.updatePendingCount();

                // If online, send to server immediately
                if (this.isOnline) {
                    this.isSaving = true;
                    const fd = buildFormData(data);

                    $.ajax({
                        url: API + '/api/exam/autosave',
                        type: 'POST',
                        data: fd,
                        processData: false,
                        contentType: false,
                        dataType: 'json'
                    })
                    .done((res) => {
                        this.isSaving = false;
                        this.consecutiveFailures = 0;
                        updateCsrf(res);
                        this.showSavedToast = true;
                        setTimeout(() => { this.showSavedToast = false; }, 2000);

                        // Remove from pending queue on success
                        this.clearPendingAnswer(questionId);
                        this.updatePendingCount();

                        if (res.status === 'kicked') {
                            if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
                            Swal.fire('Informasi', res.message, 'info').then(() => {
                                redirectReplace(API + '/login');
                            });
                        }
                    })
                    .fail((err) => {
                        this.isSaving = false;
                        console.error("Gagal menyimpan jawaban, akan dicoba lagi saat online", err);

                        // Detect network errors (DNS failure, connection refused, etc.)
                        // readyState 0 = request never sent, status 0 = no response received
                        if (err.readyState === 0 || err.status === 0) {
                            this.consecutiveFailures++;
                            if (this.consecutiveFailures >= 2) {
                                this.isOnline = false;
                                this.syncStatus = 'offline';
                                console.log('Network unreachable - switched to offline mode');
                            }
                        }

                        if (err.status === 401 || err.status === 403) {
                            if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
                            Swal.fire('Sesi Berakhir', 'Sesi Anda telah habis atau dihentikan.', 'error').then(() => {
                                redirectReplace(API + '/login');
                            });
                        }
                        // Otherwise, answer is already in LocalStorage, will sync later
                    });
                } else {
                    // Offline - show saved locally indicator
                    this.showSavedToast = true;
                    setTimeout(() => { this.showSavedToast = false; }, 2000);
                }
            },

            saveToLocalStorage(questionId, questionType, data) {
                try {
                    const storageKey = `exam_offline_static_${EXAM_CONFIG.testId}`;
                    let storage = JSON.parse(localStorage.getItem(storageKey) || '{"pending":{}}');

                    storage.pending[questionId] = {
                        question_type: questionType,
                        data: data,
                        timestamp: Date.now()
                    };

                    localStorage.setItem(storageKey, JSON.stringify(storage));
                } catch (e) {
                    console.error('Failed to save to LocalStorage:', e);
                }
            },

            loadPendingAnswers() {
                try {
                    const storageKey = `exam_offline_static_${EXAM_CONFIG.testId}`;
                    const storage = JSON.parse(localStorage.getItem(storageKey) || '{"pending":{}}');
                    return storage.pending || {};
                } catch (e) {
                    console.error('Failed to load from LocalStorage:', e);
                    return {};
                }
            },

            clearPendingAnswer(questionId) {
                try {
                    const storageKey = `exam_offline_static_${EXAM_CONFIG.testId}`;
                    let storage = JSON.parse(localStorage.getItem(storageKey) || '{"pending":{}}');
                    delete storage.pending[questionId];
                    localStorage.setItem(storageKey, JSON.stringify(storage));
                } catch (e) {
                    console.error('Failed to clear from LocalStorage:', e);
                }
            },

            updatePendingCount() {
                const pending = this.loadPendingAnswers();
                this.pendingCount = Object.keys(pending).length;
            },

            async syncPendingAnswers() {
                const pending = this.loadPendingAnswers();
                const pendingIds = Object.keys(pending);

                if (pendingIds.length === 0) {
                    this.syncStatus = '';
                    return;
                }

                this.syncStatus = 'syncing';
                this.bannerDismissed = false;
                console.log(`Syncing ${pendingIds.length} pending answers...`);

                let successCount = 0;
                let failCount = 0;

                for (const questionId of pendingIds) {
                    const answerData = pending[questionId];

                    try {
                        await new Promise((resolve, reject) => {
                            const fd = buildFormData(answerData.data);
                            $.ajax({
                                url: API + '/api/exam/autosave',
                                type: 'POST',
                                data: fd,
                                processData: false,
                                contentType: false,
                                dataType: 'json'
                            })
                            .done((res) => {
                                updateCsrf(res);
                                if (res.status === 'kicked') {
                                    reject(new Error('kicked'));
                                } else {
                                    this.clearPendingAnswer(questionId);
                                    successCount++;
                                    resolve();
                                }
                            })
                            .fail((err) => {
                                if (err.status === 401 || err.status === 403) {
                                    reject(new Error('auth'));
                                } else {
                                    failCount++;
                                    resolve();
                                }
                            });
                        });
                    } catch (err) {
                        if (err.message === 'kicked' || err.message === 'auth') {
                            if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
                            Swal.fire('Sesi Berakhir', 'Sesi Anda telah habis atau dihentikan.', 'error').then(() => {
                                redirectReplace(API + '/login');
                            });
                            return;
                        }
                    }

                    // Small delay between requests to avoid overwhelming server
                    await new Promise(resolve => setTimeout(resolve, 100));
                }

                this.updatePendingCount();

                if (successCount > 0) {
                    this.isOnline = true;
                    this.consecutiveFailures = 0;
                    this.bannerDismissed = false;
                    this.syncStatus = 'synced';
                    setTimeout(() => {
                        this.syncStatus = this.pendingCount > 0 ? 'offline' : '';
                        this.bannerDismissed = false;
                    }, 3000);
                    console.log(`Synced ${successCount} answers successfully`);
                }

                if (failCount > 0) {
                    console.warn(`${failCount} answers failed to sync, will retry later`);
                    this.syncStatus = this.pendingCount > 0 ? 'offline' : '';
                }
            },

            countAnswered() {
                let count = 0;
                this.questions.forEach(q => {
                    if (q.question_type == 3) {
                        if (q.answer_text && q.answer_text.trim() !== '') count++;
                    } else if (q.question_type == 4 || q.question_type == 5) {
                        if (q.matchingPairs && q.matchingPairs.every(p => p.selected !== '')) count++;
                    } else {
                        if ((this.allAnswers[q.question_id] || []).some(a => a.is_selected == 1)) count++;
                    }
                });
                return count;
            },
            countFlagged() {
                return this.questions.filter(q => q.is_flagged).length;
            },

            toggleFlag() {
                this.currentQuestion.is_flagged = !this.currentQuestion.is_flagged;
            },

            getGridButtonClass(idx) {
                const q = this.questions[idx];
                if (!q) return 'unanswered';
                
                let classes = [];
                if (q.is_flagged) {
                    classes.push('flagged');
                } else {
                    let answered = false;
                    if (q.question_type == 3) answered = (q.answer_text && q.answer_text.trim() !== '');
                    else if (q.question_type == 4 || q.question_type == 5) answered = (q.matchingPairs && q.matchingPairs.every(p => p.selected !== ''));
                    else answered = (this.allAnswers[q.question_id] || []).some(a => a.is_selected == 1);
                    classes.push(answered ? 'answered' : 'unanswered');
                }
                
                if (idx === this.currentIndex) classes.push('current');
                return classes.join(' ');
            },

            confirmFinish() {
                if (EXAM_CONFIG.allowNoanswer === 0 && this.countAnswered() < this.questions.length) {
                    new bootstrap.Modal(document.getElementById('unansweredRequiredModal')).show();
                    return;
                }

                this.isSaving = true;
                const fd = buildFormData({ attempt_id: ATTEMPT_ID });

                $.ajax({
                    url: API + '/api/exam/check-score',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                })
                .done((res) => {
                    this.isSaving = false;
                    updateCsrf(res);
                    if (res.status === 'success' && res.score < EXAM_CONFIG.passingScore) {
                        new bootstrap.Modal(document.getElementById('warningFinishModal')).show();
                    } else {
                        new bootstrap.Modal(document.getElementById('finishModal')).show();
                    }
                })
                .fail(() => {
                    this.isSaving = false;
                    new bootstrap.Modal(document.getElementById('finishModal')).show();
                });
            },

            async forceSubmit() {
                const w1 = await Swal.fire({
                    title: 'Peringatan 1',
                    text: "Apakah Anda yakin ingin mengakhiri ujian?",
                    icon: 'warning', showCancelButton: true, confirmButtonText: 'Yakin', cancelButtonText: 'Batal'
                });
                if (!w1.isConfirmed) return;

                const w2 = await Swal.fire({
                    title: 'Peringatan 2',
                    text: "Anda masih memiliki waktu. Yakin ingin benar-benar menyerah?",
                    icon: 'warning', showCancelButton: true, confirmButtonText: 'Yakin Menyerah', cancelButtonText: 'Batal'
                });
                if (!w2.isConfirmed) return;

                const w3 = await Swal.fire({
                    title: 'Peringatan Terakhir',
                    text: "Ujian akan diakhiri secara permanen. Lanjutkan?",
                    icon: 'error', showCancelButton: true, confirmButtonText: 'Akhiri Ujian',
                    cancelButtonText: 'Batal', confirmButtonColor: '#d33'
                });
                if (w3.isConfirmed) {
                    document.querySelectorAll('.modal.show').forEach(m => {
                        bootstrap.Modal.getInstance(m)?.hide();
                    });
                    this.submitFinish();
                }
            },

            async submitFinish() {
                if (await ensureOnline() && window.__antiCheat) {
                    await window.__antiCheat.revalidateFromServer();
                }

                // Check if banned due to anti-cheat violations (cache only — server validated above when online)
                try {
                    const acData = JSON.parse(localStorage.getItem(`exam_anticheat_${EXAM_CONFIG.testId}`) || '{"banned":false}');
                    if (acData.banned) {
                        Swal.fire({
                            title: 'Ujian Dikunci',
                            html: 'Anda tidak dapat mengakhiri ujian karena akun Anda dikunci akibat pelanggaran (<strong>' + acData.strikes + '/' + EXAM_CONFIG.antiCheat.max_strikes + '</strong>).<br><br>Hubungi <strong>pengawas ujian</strong> untuk membuka kunci.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        return;
                    }
                } catch(e) {}

                // If offline with pending answers, block submission
                if (!this.isOnline && this.pendingCount > 0) {
                    Swal.fire({
                        title: 'Tidak Ada Koneksi',
                        text: `Anda memiliki ${this.pendingCount} jawaban yang belum tersinkronisasi. Harap sambungkan ke internet sebelum mengakhiri ujian.`,
                        icon: 'warning',
                        confirmButtonText: 'OK'
                    });
                    return;
                }

                // Sync any remaining pending answers first
                if (this.pendingCount > 0) {
                    this.syncStatus = 'syncing';
                    await this.syncPendingAnswers();

                    // If still have pending after sync (failed), warn user
                    if (this.pendingCount > 0) {
                        const proceed = await Swal.fire({
                            title: 'Sinkronisasi Gagal',
                            text: `Masih ada ${this.pendingCount} jawaban yang gagal disinkronisasi. Tetap akhiri ujian?`,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Ya, Akhiri',
                            cancelButtonText: 'Lanjut Mengerjakan'
                        });
                        if (!proceed.isConfirmed) {
                            this.syncStatus = 'offline';
                            return;
                        }
                    }
                }

                window.isSubmitting = true;
                if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});

                const fd = buildFormData({ test_id: EXAM_CONFIG.testId, attempt_id: ATTEMPT_ID });

                $.ajax({
                    url: API + '/api/exam/finish',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                })
                .done((res) => {
                    // Clear offline answers LocalStorage on successful finish
                    try {
                        localStorage.removeItem(`exam_offline_static_${EXAM_CONFIG.testId}`);
                    } catch(e) {}

                    updateCsrf(res);
                    if (res.redirect) {
                        window.location.href = res.redirect;
                    } else {
                        window.location.href = API + '/student/results/view/' + EXAM_CONFIG.testId;
                    }
                })
                .fail((err) => {
                    this.isSaving = false;
                    window.isSubmitting = false;
                    const msg = (err.responseJSON && err.responseJSON.message) ? err.responseJSON.message : 'Gagal menyelesaikan ujian.';
                    Swal.fire('Error', msg, 'error');
                });
            }
        }));
    });

    // ═══ ANTI-CHEAT ENGINE (OFFLINE-FIRST) ═══
    (function() {
        let isSuspended = false;
        let isLocked    = false;
        let isSyncingBanned = false;
        let suspendTimerInterval = null;

        const AC_CONFIG = EXAM_CONFIG.antiCheat || {};
        const STORAGE_KEY = `exam_anticheat_${EXAM_CONFIG.testId}`;
        const SUSPEND_KEY = `exam_suspend_${EXAM_CONFIG.testId}`;
        const PENDING_REPORT_KEY = `exam_pending_cheat_${EXAM_CONFIG.testId}`;

        function loadStrikeData() {
            try {
                return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{"strikes":0,"banned":false}');
            } catch(e) {
                return { strikes: 0, banned: false };
            }
        }

        function saveStrikeData(data) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            } catch(e) {}
        }

        function addStrike(type) {
            const data = loadStrikeData();
            data.strikes++;
            data.lastStrikeAt = Date.now();
            data.lastType = type;

            if (data.strikes >= AC_CONFIG.max_strikes) {
                data.banned = true;
                data.cheat_flag_at = Date.now();
            }
            saveStrikeData(data);
            return data;
        }

        function syncFromServer(serverAntiCheat, serverUser) {
            const localData = loadStrikeData();
            const serverStrikes = serverAntiCheat && serverAntiCheat.current_strikes !== undefined
                ? serverAntiCheat.current_strikes : null;
            const serverMax = (serverAntiCheat && serverAntiCheat.max_strikes) || AC_CONFIG.max_strikes;
            const unbannedAtMs = (serverAntiCheat && serverAntiCheat.unbanned_at_ms)
                || (serverUser && serverUser.unbanned_at_ms)
                || 0;
            const cheatFlagAt = localData.cheat_flag_at || 0;

            if (unbannedAtMs > 0 && unbannedAtMs > cheatFlagAt) {
                localData.strikes = serverStrikes !== null ? serverStrikes : 0;
                localData.banned = false;
                delete localData.cheat_flag_at;
                localData.syncedAt = Date.now();
                saveStrikeData(localData);
                localStorage.removeItem(SUSPEND_KEY);
                localStorage.removeItem(PENDING_REPORT_KEY);
                isLocked = false;
                console.log('Cheat flag cleared — admin unbanned after local flag');
                return { cleared: true, data: localData };
            }

            if (serverStrikes !== null) {
                if (serverStrikes < localData.strikes || (serverStrikes < serverMax && localData.banned)) {
                    localData.strikes = serverStrikes;
                    localData.banned = serverStrikes >= serverMax;
                    if (!localData.banned) delete localData.cheat_flag_at;
                    localData.syncedAt = Date.now();
                    saveStrikeData(localData);
                    console.log(`Anti-cheat synced from server: ${serverStrikes}/${serverMax} strikes`);
                } else if (serverStrikes >= serverMax) {
                    localData.strikes = serverStrikes;
                    localData.banned = true;
                    if (!localData.cheat_flag_at) localData.cheat_flag_at = Date.now();
                    saveStrikeData(localData);
                }
            }

            return { cleared: false, data: loadStrikeData() };
        }

        function restoreExamAfterUnban() {
            isLocked = false;
            const bannedOverlay = document.getElementById('bannedOverlay');
            if (bannedOverlay) bannedOverlay.style.display = 'none';
            const examContent = document.getElementById('examContent');
            if (examContent) examContent.style.display = 'block';
        }

        async function reportCheatToServer(type) {
            if (!ATTEMPT_ID) return;
            if (isSyncingBanned) return;

            if (isLocked) isSyncingBanned = true;

            try {
                const online = await ensureOnline();
                if (!online) throw new Error('offline');

                const fd = buildFormData({ attempt_id: ATTEMPT_ID, type: type });
                const res = await fetchWithRetry(API + '/api/exam/report-cheat', { method: 'POST', body: fd });
                const data = await res.json();

                isSyncingBanned = false;
                updateCsrf(data);
                localStorage.removeItem(PENDING_REPORT_KEY);

                if (data.current_strikes !== undefined) {
                    syncFromServer({
                        current_strikes: data.current_strikes,
                        max_strikes: data.max_strikes || AC_CONFIG.max_strikes,
                    }, null);
                }

                if (data.action === 'lock') {
                    const strikeData = loadStrikeData();
                    await finalizeBan(strikeData.strikes, data.message || 'Ujian dikunci oleh server.');
                }
            } catch (err) {
                isSyncingBanned = false;
                if (type !== 'banned_retry') {
                    localStorage.setItem(PENDING_REPORT_KEY, type);
                }

                if (isLocked) {
                    const syncStatus = document.getElementById('bannedSyncingStatus');
                    const errorStatus = document.getElementById('bannedErrorStatus');
                    if (syncStatus) syncStatus.style.display = 'none';
                    if (errorStatus) errorStatus.style.display = 'block';
                }
            }
        }

        async function finalizeBan(strikes, reason) {
            await Swal.fire({
                title: 'Ujian Dikunci Permanen',
                html: reason + '<br><br>Pelanggaran: <strong>' + strikes + '/' + AC_CONFIG.max_strikes + '</strong><br><br>Akun Anda telah <strong>dinonaktifkan</strong>. Menuju halaman login...',
                icon: 'error',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true
            });
            await logoutAndRedirect(API + '/login');
        }

        async function revalidateFromServer() {
            if (!await ensureOnline()) return;

            try {
                const fd = buildFormData({ test_id: EXAM_CONFIG.testId });
                const res = await fetchWithRetry(API + '/api/exam/init', { method: 'POST', body: fd });
                const data = await res.json();
                updateCsrf(data);

                if (data.action === 'logout' || (data.user && data.user.is_active === false)) {
                    await logoutAndRedirect(API + '/login');
                    return;
                }

                if (data.status === 'success') {
                    const syncResult = syncFromServer(data.anti_cheat, data.user);
                    if (syncResult.cleared) {
                        restoreExamAfterUnban();
                    } else if (syncResult.data && syncResult.data.banned && !isLocked) {
                        showBannedScreen(syncResult.data.strikes, 'Ujian Anda dikunci oleh server.');
                    }
                }
            } catch (e) {
                console.warn('Failed to revalidate status from server:', e);
            }
        }

        function clearSuspend() {
            try {
                localStorage.removeItem(SUSPEND_KEY);
            } catch(e) {}
            isSuspended = false;
            if (suspendTimerInterval) {
                clearInterval(suspendTimerInterval);
                suspendTimerInterval = null;
            }
        }

        function startSuspendOverlay(strikeData, remainingSec) {
            isSuspended = true;
            document.getElementById('examContent').style.display = 'none';
            var overlay = document.getElementById('suspendOverlay');
            overlay.style.display = 'flex';

            document.getElementById('strikeCount').innerText = strikeData.strikes;
            document.getElementById('maxStrikes').innerText = AC_CONFIG.max_strikes;

            var sec = remainingSec;
            var timerEl = document.getElementById('suspendTimerDisplay');
            timerEl.innerText = sec;

            if (suspendTimerInterval) clearInterval(suspendTimerInterval);

            suspendTimerInterval = setInterval(function() {
                sec--;
                timerEl.innerText = sec;
                if (sec <= 0) {
                    clearInterval(suspendTimerInterval);
                    suspendTimerInterval = null;
                    overlay.style.display = 'none';
                    isSuspended = false;
                    document.getElementById('examContent').style.display = 'block';
                    clearSuspend();
                }
            }, 1000);
        }

        function suspendWithPersistence(strikeData, type) {
            // Store suspend state in LocalStorage
            const suspendDuration = AC_CONFIG.suspend_timer || 30;
            try {
                localStorage.setItem(SUSPEND_KEY, JSON.stringify({
                    active: true,
                    startedAt: Date.now(),
                    duration: suspendDuration,
                    type: type
                }));
            } catch(e) {}

            startSuspendOverlay(strikeData, suspendDuration);
        }

        function handleViolation(type) {
            const strikeData = addStrike(type);

            if (strikeData.banned) {
                showBannedScreen(strikeData.strikes, 'Anda telah melebihi batas pelanggaran.', type);
                return;
            }

            suspendWithPersistence(strikeData, type);
        }

        function handleSuspendBypassOnLoad() {
            let activeSuspend = null;
            try {
                activeSuspend = JSON.parse(localStorage.getItem(SUSPEND_KEY) || 'null');
            } catch(e) {}

            if (!activeSuspend || !activeSuspend.active || isLocked) return;

            const newStrikeData = addStrike('suspend_bypass');
            reportCheatToServer('suspend_bypass');

            if (newStrikeData.banned) {
                showBannedScreen(newStrikeData.strikes, 'Anda mencoba melewati hukuman suspend.', 'suspend_bypass');
                return;
            }

            const fullDuration = AC_CONFIG.suspend_timer || 30;
            try {
                localStorage.setItem(SUSPEND_KEY, JSON.stringify({
                    active: true,
                    startedAt: Date.now(),
                    duration: fullDuration,
                    type: 'suspend_bypass'
                }));
            } catch(e) {}

            setTimeout(function() {
                startSuspendOverlay(newStrikeData, fullDuration);
            }, 300);
        }

        // ── Expose functions for server-authoritative sync ──
        window.__antiCheat = {
            showBannedScreen: showBannedScreen,
            loadStrikeData: loadStrikeData,
            addStrike: addStrike,
            reportCheatToServer: reportCheatToServer,
            syncFromServer: syncFromServer,
            revalidateFromServer: revalidateFromServer,
            restoreExamAfterUnban: restoreExamAfterUnban,
            handleSuspendBypassOnLoad: handleSuspendBypassOnLoad,
            maxStrikes: AC_CONFIG.max_strikes,
        };

        function showBannedScreen(strikes, reason, violationType) {
            isLocked = true;
            clearSuspend();
            
            // Immediately hide exam and show lock screen
            var examContent = document.getElementById('examContent');
            if (examContent) examContent.style.display = 'none';
            var loading = document.getElementById('loadingScreen');
            if (loading) loading.style.display = 'none';
            var suspendOverlay = document.getElementById('suspendOverlay');
            if (suspendOverlay) suspendOverlay.style.display = 'none';

            // Show persistent banned overlay
            var bannedOverlay = document.getElementById('bannedOverlay');
            if (bannedOverlay) {
                bannedOverlay.style.display = 'flex';
                document.getElementById('bannedStrikeCount').innerText = strikes;
                document.getElementById('bannedMaxStrikes').innerText = AC_CONFIG.max_strikes;
                
                // Reset status display
                document.getElementById('bannedSyncingStatus').style.display = 'block';
                document.getElementById('bannedErrorStatus').style.display = 'none';
            }

            // Immediately try to report with the actual violation type
            reportCheatToServer(violationType || 'tab_switch');
        }

        // ── Retry loop for pending reports (Background Sync) ──
        setInterval(async () => {
            const pendingType = localStorage.getItem(PENDING_REPORT_KEY);
            if (!pendingType) return;

            if (!(await ensureOnline())) return;

            if (isLocked) {
                const syncStatus = document.getElementById('bannedSyncingStatus');
                const errorStatus = document.getElementById('bannedErrorStatus');
                if (syncStatus) syncStatus.style.display = 'block';
                if (errorStatus) errorStatus.style.display = 'none';
                reportCheatToServer('banned_retry');
            } else {
                reportCheatToServer(pendingType);
            }
        }, 5000);

        // Offline-only: apply cached ban when server validation is unavailable
        document.addEventListener('exam-data-loaded', () => {
            if (window.__examData && window.__examData.user) return;
            const offlineData = loadStrikeData();
            if (offlineData.banned) {
                showBannedScreen(offlineData.strikes, 'Akun Anda telah dikunci karena pelanggaran berulang.', offlineData.lastType || 'offline_ban');
                return;
            }
            handleSuspendBypassOnLoad();
        }, { once: true });

        // ── Tab Switch Detection ──
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden || !examStarted || isLocked || isSuspended || window.isSubmitting) return;
            if (AC_CONFIG.enabled === false) return;

            const strikeData = addStrike('tab_switch');
            reportCheatToServer('tab_switch');

            if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});

            if (strikeData.banned) {
                // Reached max strikes — show persistent overlay
                showBannedScreen(strikeData.strikes, 'Anda terdeteksi membuka tab lain terlalu sering.', 'tab_switch');
            } else {
                // Warning only — stay in exam
                Swal.fire({
                    title: 'Peringatan!',
                    html: 'Anda terdeteksi membuka tab lain.<br>Pelanggaran: <strong>' + strikeData.strikes + '/' + AC_CONFIG.max_strikes + '</strong><br><br><small class="text-muted">Jika mencapai batas maksimal, ujian akan dikunci.</small>',
                    icon: 'warning',
                    confirmButtonText: 'Saya Mengerti'
                });
            }
        });

        // ── Fullscreen Exit Detection ──
        document.addEventListener('fullscreenchange', function() {
            if (document.fullscreenElement || !examStarted || isSuspended || isLocked || window.isSubmitting) return;

            if (AC_CONFIG.enabled === false) {
                reportCheatToServer('fullscreen_exit');
                return;
            }

            reportCheatToServer('fullscreen_exit');
            handleViolation('fullscreen_exit');
        });
    })();

    // ═══ BOOT ═══
    document.addEventListener('DOMContentLoaded', function() {
        initExam();

        const lightbox = document.getElementById('imageLightbox');
        const lightboxImg = document.getElementById('imageLightboxImg');
        
        const qContainer = document.querySelector('.question-container');
        if (qContainer) {
            qContainer.addEventListener('click', function(e) {
                if (e.target.tagName === 'IMG') {
                    lightboxImg.src = e.target.src;
                    lightbox.classList.add('active');
                }
            });
        }
        
        lightbox.addEventListener('click', function(e) {
            if (e.target !== lightboxImg) {
                lightbox.classList.remove('active');
                lightboxImg.src = '';
            }
        });
    });
    </script>
</body>
</html>

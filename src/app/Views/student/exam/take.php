<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
$secondaryColor = $settingModel->getValue('secondary_color', '#f4f6f9');
$textColor = $settingModel->getValue('text_color', '#212529');
$appLogo = $settingModel->getValue('app_logo', '');
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
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
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
            padding-bottom: 80px; /* Space for bottom nav */
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
            width: 40px; /* Trigger area */
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
            right: -360px; /* Hidden */
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
            right: 0; /* Slide in on hover */
        }
        .desktop-drawer-wrapper:hover {
            width: 360px; /* Expand hover area so drawer stays open */
        }
        
        .noselect { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }

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
            top: 61px; /* approximate height of topbar */
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
        /* Sidebar legend */
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="noselect">

    <!-- Image Lightbox Overlay -->
    <div class="image-lightbox" id="imageLightbox">
        <div class="image-lightbox-close" id="imageLightboxClose">&times;</div>
        <img src="" id="imageLightboxImg" alt="Preview">
    </div>

    <!-- ▼ EXAM CONTENT ▼ -->
    <div id="examContent" style="display:block;" x-data="examApp()">
    <div class="exam-layout">
    <div class="exam-main">
        
        <!-- Top Navigation -->
        <div class="exam-topbar">
            <div class="exam-title-area">
                <div class="exam-title-text"><?= esc($test->name) ?></div>
                <div class="exam-student-text"><?= esc(session('firstname') . ' ' . session('lastname')) ?></div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if ($test->duration_minutes > 0): ?>
                    <div class="exam-timer-chip" :class="{'danger': timeLeft <= 300000}">
                        <i class="bi bi-clock-history"></i> <span x-text="formatTime(timeLeft)">--:--:--</span>
                    </div>
                <?php endif; ?>
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

        <!-- Main Content -->
        <div class="question-container">
            <div class="question-label">Soal No. <span x-text="currentIndex + 1"></span> dari <?= count($questions) ?></div>
            
            <div class="question-text" x-html="currentQuestion.question_text"></div>
            
            <template x-if="currentQuestion.question_type == 1">
                <div>
                    <template x-for="(answer, i) in currentAnswers" :key="answer.answer_id">
                        <label class="answer-option" :class="{'selected': answer.is_selected == 1}" @click="selectRadio(answer.answer_id)">
                            <input type="radio" :name="'q_' + currentQuestion.log_id" class="form-check-input flex-shrink-0" :checked="answer.is_selected == 1">
                            <div class="answer-content" x-html="answer.answer_text"></div>
                        </label>
                    </template>
                </div>
            </template>
            
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
            
            <template x-if="currentQuestion.question_type == 3">
                <div>
                    <textarea class="form-control" rows="8" style="border-radius:12px;" x-model="currentQuestion.answer_text" @input.debounce.500ms="saveAnswer()" placeholder="Tulis jawaban Anda di sini..."></textarea>
                </div>
            </template>
            
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
                                            <input type="radio" :name="'tf_' + currentQuestion.log_id + '_' + i" value="Benar" :checked="pair.selected === 'Benar'" class="form-check-input" style="transform: scale(1.5);" @change="updateMatching(i, 'Benar')">
                                        </td>
                                        <td class="text-center py-3 border-bottom">
                                            <input type="radio" :name="'tf_' + currentQuestion.log_id + '_' + i" value="Salah" :checked="pair.selected === 'Salah'" class="form-check-input" style="transform: scale(1.5);" @change="updateMatching(i, 'Salah')">
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

        <!-- Mobile Offcanvas Sidebar (only visible on small screens) -->
        <div class="offcanvas offcanvas-end" tabindex="-1" id="questionGridSheet" aria-labelledby="questionGridSheetLabel">
            <div class="offcanvas-header border-bottom">
                <h5 class="offcanvas-title fw-bold" id="questionGridSheetLabel">Navigasi Soal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body">
                <div class="q-grid-container">
                    <template x-for="(q, idx) in questions" :key="q.log_id">
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

        <!-- Desktop Sidebar (always visible on lg+, opens on hover) -->
        </div><!-- /exam-main -->
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
                    <template x-for="(q, idx) in questions" :key="q.log_id">
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const RAW_QUESTIONS = <?= json_encode($questions) ?>;
        const RAW_ANSWERS = <?= json_encode($answers) ?>;
        const SAVE_URL = '<?= base_url('/student/exam/save-answer') ?>';
        const DASHBOARD_URL = "<?= base_url('/student/dashboard') ?>";
        const FINISH_URL = '<?= base_url('/student/exam/finish/' . $test->id) ?>';
        const ATTEMPT_ID = <?= (int) $attempt->id ?>;
        const STUDENT_NAME = <?= json_encode(session('firstname') . ' ' . session('lastname')) ?>;
        const ALLOW_NOANSWER = <?= (int) $test->allow_noanswer ?>;
        
        let durationMin = <?= (int) $test->duration_minutes ?>;
        const beginTimeMs = <?= strtotime($test->begin_time) * 1000 ?>;
        const startTime = <?= strtotime($attempt->started_at) * 1000 ?>;

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
            }
        });

        const FETCH_TIMEOUT_MS = 15000;
        const LOGIN_URL = '<?= base_url('/login') ?>';

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

        async function fetchWithRetry(url, options = {}, maxRetries = 3, timeoutMs = FETCH_TIMEOUT_MS) {
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

        async function logoutAndRedirect(loginUrl) {
            try {
                await fetchWithRetry('<?= base_url('/logout') ?>', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '<?= csrf_hash() ?>' },
                });
            } catch (e) {
                console.warn('Logout request failed, redirecting anyway:', e);
            }
            redirectReplace(loginUrl);
        }

        // Automatically update CSRF token on every AJAX response
        $(document).ajaxComplete(function(event, xhr, settings) {
            const csrfHeader = xhr.getResponseHeader('X-CSRF-TOKEN');
            if (csrfHeader) {
                $.ajaxSetup({
                    headers: {
                        'X-CSRF-TOKEN': csrfHeader
                    }
                });
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
                showSavedToast: false,
                timeLeft: 0,
                timerInterval: null,
                warningShown: false,

                init() {
                    // Parse Matching Options for Type 4 and Type 5
                    this.questions.forEach(q => {
                        q.is_flagged = false;
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
                    this.initWebSocket();

                    // ═══ Countdown Timer (if timed exam) ═══
                    if (durationMin > 0) {
                        this.endTimeMs = beginTimeMs + (durationMin * 60 * 1000);
                        this.timerInterval = setInterval(() => {
                            const now = new Date().getTime();
                            const distance = this.endTimeMs - now;

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
                 * Initialize WebSocket connection for real-time ban/kick detection.
                 * Automatically reconnects on connection loss.
                 */
                initWebSocket() {
                    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                    const wsHost = window.location.host;
                    const wsUrl = `${protocol}//${wsHost}/ws/?user_id=<?= session('user_id') ?>&attempt_id=${ATTEMPT_ID}`;
                    
                    this.connectWebSocket(wsUrl);
                },

                connectWebSocket(wsUrl) {
                    this.ws = new WebSocket(wsUrl);

                    this.ws.onopen = () => {
                        this.wsErrorCount = 0;
                        console.log('WebSocket connected');
                    };

                    this.ws.onmessage = (e) => {
                        const payload = JSON.parse(e.data);
                        const eventName = payload.event;
                        const data = payload.data || {};

                        if (eventName === 'ban') {
                            this.ws.close();
                            Swal.fire({
                                title: 'Akun Di-Ban',
                                text: data.message,
                                icon: 'error',
                                allowOutsideClick: false,
                                allowEscapeKey: false,
                                confirmButtonText: 'OK'
                            }).then(() => logoutAndRedirect(LOGIN_URL));
                        } 
                        else if (eventName === 'kick') {
                            this.ws.close();
                            Swal.fire('Sesi Dihentikan', data.message, 'error').then(() => logoutAndRedirect(LOGIN_URL));
                        }
                        else if (eventName === 'finished') {
                            this.ws.close();
                            Swal.fire('Ujian Selesai', data.message, 'info').then(() => {
                                window.location.href = DASHBOARD_URL;
                            });
                        }
                        else if (eventName === 'extend_time') {
                            if (data.test_id == <?= (int) $test->id ?>) {
                                durationMin = data.duration_minutes;
                                this.endTimeMs = beginTimeMs + (durationMin * 60 * 1000);
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
                            if (data.exam_mode === 'static' && data.static_page_path) {
                                window.isSubmitting = true;
                                window.location.href = '<?= base_url() ?>' + data.static_page_path;
                            }
                        }
                        // heartbeat event is ignored on client, but keeps connection alive
                    };

                    this.ws.onclose = (e) => {
                        console.warn('WebSocket closed', e);
                        this.reconnectWebSocket(wsUrl);
                    };

                    this.ws.onerror = (err) => {
                        console.error('WebSocket error', err);
                        this.ws.close(); // Triggers onclose which handles reconnect
                    };
                },

                reconnectWebSocket(wsUrl) {
                    if (!this.wsErrorCount) this.wsErrorCount = 0;
                    this.wsErrorCount++;
                    
                    if (this.wsErrorCount > 10) {
                        console.warn('WebSocket reconnect limit reached, relying on fallback');
                        return;
                    }
                    
                    const delay = Math.min(1000 * Math.pow(2, this.wsErrorCount), 30000);
                    console.log(`Reconnecting WebSocket in ${delay}ms...`);
                    
                    setTimeout(() => {
                        this.connectWebSocket(wsUrl);
                    }, delay);
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
                closeMobileSidebar() {
                    const el = document.getElementById('questionGridSheet');
                    if (el) {
                        const bs = bootstrap.Offcanvas.getInstance(el);
                        if (bs) bs.hide();
                    }
                },

                selectRadio(answerId) {
                    // Prevent saving if the answer is already selected
                    let currentSelected = this.currentAnswers.find(a => a.is_selected == 1);
                    if (currentSelected && currentSelected.answer_id == answerId) {
                        return; 
                    }
                    this.currentAnswers.forEach(a => { a.is_selected = (a.answer_id == answerId) ? 1 : 0; });
                    this.saveAnswer();
                },
                toggleCheckbox(answerId, isChecked) {
                    let ans = this.currentAnswers.find(a => a.answer_id == answerId);
                    if (ans) {
                        // Prevent saving if state is unchanged
                        if (ans.is_selected == (isChecked ? 1 : 0)) return;
                        ans.is_selected = isChecked ? 1 : 0;
                    }
                    this.saveAnswer();
                },
                updateMatching(index, value) {
                    // Prevent saving if value is unchanged
                    if (this.questions[this.currentIndex].matchingPairs[index].selected === value) return;
                    this.questions[this.currentIndex].matchingPairs[index].selected = value;
                    this.saveAnswer();
                },

                saveAnswer() {
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

                    this.isSaving = true;
                    $.post('<?= base_url('/student/exam/autosave') ?>', data)
                     .done((res) => {
                         this.isSaving = false;
                         this.showSavedToast = true;
                         setTimeout(() => { this.showSavedToast = false; }, 2000);

                         if (res.status === 'kicked') {
                             if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                             Swal.fire('Informasi', res.message, 'info').then(() => {
                                 redirectReplace(LOGIN_URL);
                             });
                         }
                     })
                     .fail((err) => {
                         this.isSaving = false;
                         console.error("Gagal menyimpan jawaban", err);

                         if (err.status === 401 || err.status === 403) {
                             if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                             Swal.fire('Sesi Berakhir', 'Sesi Anda telah habis atau dihentikan.', 'error').then(() => {
                                 redirectReplace(LOGIN_URL);
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

                countFlagged() {
                    return this.questions.filter(q => q.is_flagged).length;
                },

                toggleFlag() {
                    this.currentQuestion.is_flagged = !this.currentQuestion.is_flagged;
                },

                getGridButtonClass(idx) {
                    const q = this.questions[idx];
                    let classes = [];
                    if (q.is_flagged) {
                        classes.push('flagged');
                    } else {
                        let answered = false;
                        if (q.question_type == 3) answered = (q.answer_text && q.answer_text.trim() !== '');
                        else if (q.question_type == 4 || q.question_type == 5) answered = (q.matchingPairs && q.matchingPairs.every(p => p.selected !== ''));
                        else answered = (this.allAnswers[q.log_id]||[]).some(a => a.is_selected == 1);
                        classes.push(answered ? 'answered' : 'unanswered');
                    }
                    if (idx === this.currentIndex) classes.push('current');
                    return classes.join(' ');
                },

                confirmFinish() {
                    if (ALLOW_NOANSWER === 0 && this.countAnswered() < this.questions.length) {
                        new bootstrap.Modal(document.getElementById('unansweredRequiredModal')).show();
                        return;
                    }

                    this.isSaving = true;
                    $.post('<?= base_url('/student/exam/check-score') ?>', { attempt_id: ATTEMPT_ID })
                     .done((res) => {
                         this.isSaving = false;
                         if (res.status === 'success') {
                             if (res.score < <?= $test->passing_score ?>) {
                                 new bootstrap.Modal(document.getElementById('warningFinishModal')).show();
                             } else {
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
                        text: "Apakah Anda yakin ingin mengakhiri ujian?",
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
                        text: "Ujian akan diakhiri secara permanen. Lanjutkan?",
                        icon: 'error',
                        showCancelButton: true,
                        confirmButtonText: 'Akhiri Ujian',
                        cancelButtonText: 'Batal',
                        confirmButtonColor: '#d33'
                    });
                    if (w3.isConfirmed) {
                        document.querySelectorAll('.modal.show').forEach(m => {
                            bootstrap.Modal.getInstance(m)?.hide();
                        });
                        this.submitFinish();
                    }
                },

                async submitFinish() {
                    window.isSubmitting = true;
                    if (document.fullscreenElement) document.exitFullscreen().catch(function(){});

                    $.post(FINISH_URL, { attempt_id: ATTEMPT_ID })
                     .done((res) => {
                         if (res.redirect) {
                             window.location.href = res.redirect;
                         } else {
                             window.location.href = "<?= base_url('/student/results/view/' . $test->id) ?>";
                         }
                     })
                     .fail(() => {
                         window.isSubmitting = false;
                         Swal.fire('Error', 'Gagal menyelesaikan ujian. Silakan coba lagi.', 'error');
                     });
                }
            }));
        });

        // ═══════════════════════════════════════════════════════
        //  3. IMAGE LIGHTBOX PREVIEW
        // ═══════════════════════════════════════════════════════
        (function() {
            const lightbox = document.getElementById('imageLightbox');
            const lightboxImg = document.getElementById('imageLightboxImg');

            // Listen for clicks on images inside the question container
            const qContainer = document.querySelector('.question-container');
            if (qContainer) {
                qContainer.addEventListener('click', function(e) {
                    if (e.target.tagName === 'IMG') {
                        lightboxImg.src = e.target.src;
                        lightbox.classList.add('active');
                    }
                });
            }

            // Close lightbox when clicking the close button or outside the image
            lightbox.addEventListener('click', function(e) {
                if (e.target !== lightboxImg) {
                    lightbox.classList.remove('active');
                    lightboxImg.src = '';
                }
            });
        })();
    </script>
</body>
</html>


<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Review: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .review-header {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        border-radius: 16px;
        padding: 1.5rem 2rem;
        color: white;
        margin-bottom: 2rem;
    }
    .review-question-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        margin-bottom: 1rem;
        overflow: hidden;
        transition: border-color 0.2s;
    }
    .review-question-card:hover {
        border-color: var(--brand-color);
    }
    .question-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.25rem;
        cursor: pointer;
        user-select: none;
    }
    .question-number {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.95rem;
        flex-shrink: 0;
        color: white;
    }
    .question-number.correct { background: #198754; }
    .question-number.wrong { background: #dc3545; }
    .question-number.unanswered { background: #6c757d; }
    .question-number.partial { background: #fd7e14; }

    .question-meta {
        flex: 1;
        min-width: 0;
    }
    .question-meta .q-type {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--text-secondary);
        font-weight: 600;
    }
    .question-meta .q-preview {
        font-size: 0.88rem;
        color: var(--text-primary);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 500px;
    }
    .question-score-badge {
        padding: 0.3rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        flex-shrink: 0;
    }
    .question-score-badge.correct { background: rgba(25,135,84,0.1); color: #198754; }
    .question-score-badge.wrong { background: rgba(220,53,69,0.1); color: #dc3545; }
    .question-score-badge.unanswered { background: rgba(108,117,125,0.1); color: #6c757d; }

    .question-body {
        padding: 0 1.25rem 1.25rem;
        display: none;
    }
    .question-body.open { display: block; }

    .question-text {
        background: var(--bg-body);
        border-radius: 10px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
        font-size: 0.95rem;
        line-height: 1.6;
    }
    .question-text img { max-width: 100%; border-radius: 8px; }

    .answer-option {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: 10px;
        margin-bottom: 0.5rem;
        border: 1px solid var(--border-color);
        transition: background 0.15s;
    }
    .answer-option .answer-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        flex-shrink: 0;
        margin-top: 0.1rem;
    }
    .answer-option.selected-correct {
        background: rgba(25,135,84,0.08);
        border-color: #198754;
    }
    .answer-option.selected-correct .answer-icon {
        background: #198754;
        color: white;
    }
    .answer-option.selected-wrong {
        background: rgba(220,53,69,0.08);
        border-color: #dc3545;
    }
    .answer-option.selected-wrong .answer-icon {
        background: #dc3545;
        color: white;
    }
    .answer-option.correct-missed {
        background: rgba(255,193,7,0.08);
        border-color: #ffc107;
    }
    .answer-option.correct-missed .answer-icon {
        background: #ffc107;
        color: #000;
    }
    .answer-option.neutral .answer-icon {
        background: var(--bg-body);
        color: var(--text-secondary);
    }
    .answer-option .answer-text {
        font-size: 0.9rem;
        line-height: 1.5;
        flex: 1;
    }
    .answer-option .answer-label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 0.2rem;
    }

    .review-summary-bar {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
    }
    .review-summary-bar .summary-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.35rem 0.85rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-10">

        <!-- Header -->
        <div class="review-header d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-journal-text me-2"></i>Review Jawaban</h4>
                <p class="mb-0" style="opacity: 0.8;"><?= esc($test->name) ?></p>
            </div>
            <a href="<?= base_url('/student/results/view/' . $test->id) ?>" class="btn btn-light btn-sm fw-semibold">
                <i class="bi bi-arrow-left me-1"></i> Kembali
            </a>
        </div>

        <!-- Summary -->
        <?php
            $totalQ = count($logs);
            $correctQ = 0; $wrongQ = 0; $unansweredQ = 0;
            foreach ($logs as $log) {
                if ($log->score > 0) $correctQ++;
                elseif ($log->score === null || $log->score == 0) {
                    $hasAnswer = false;
                    $logAnswers = $answers[$log->log_id] ?? [];
                    if ($log->question_type == 3) {
                        $hasAnswer = !empty(trim($log->answer_text ?? ''));
                    } else {
                        foreach ($logAnswers as $a) {
                            if ($a->is_selected) { $hasAnswer = true; break; }
                        }
                    }
                    if ($hasAnswer) $wrongQ++; else $unansweredQ++;
                }
            }
        ?>
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 14px;">
            <div class="card-body p-3">
                <div class="review-summary-bar">
                    <span class="summary-chip" style="background: rgba(13,110,253,0.1); color: #0d6efd;">
                        <i class="bi bi-list-ol"></i> <?= $totalQ ?> Soal
                    </span>
                    <span class="summary-chip" style="background: rgba(25,135,84,0.1); color: #198754;">
                        <i class="bi bi-check-circle"></i> <?= $correctQ ?> Benar
                    </span>
                    <span class="summary-chip" style="background: rgba(220,53,69,0.1); color: #dc3545;">
                        <i class="bi bi-x-circle"></i> <?= $wrongQ ?> Salah
                    </span>
                    <span class="summary-chip" style="background: rgba(108,117,125,0.1); color: #6c757d;">
                        <i class="bi bi-dash-circle"></i> <?= $unansweredQ ?> Kosong
                    </span>
                    <?php if ($showCorrect): ?>
                    <span class="summary-chip" style="background: rgba(255,193,7,0.15); color: #b8860b;">
                        <i class="bi bi-key"></i> Kunci jawaban ditampilkan
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Questions -->
        <?php
            $typeLabels = [1 => 'Pilihan Ganda', 2 => 'Pilihan Ganda Kompleks', 3 => 'Essay', 4 => 'Menjodohkan', 5 => 'Benar/Salah'];
            $no = 0;
            foreach ($logs as $log):
                $no++;
                $logAnswers = $answers[$log->log_id] ?? [];
                $hasAnswer = false;
                if ($log->question_type == 3) {
                    $hasAnswer = !empty(trim($log->answer_text ?? ''));
                } else {
                    foreach ($logAnswers as $a) {
                        if ($a->is_selected) { $hasAnswer = true; break; }
                    }
                }

                if ($log->score > 0) {
                    $status = 'correct';
                } elseif (!$hasAnswer) {
                    $status = 'unanswered';
                } else {
                    $status = 'wrong';
                }
        ?>
        <div class="review-question-card">
            <div class="question-header" onclick="this.nextElementSibling.classList.toggle('open')">
                <div class="question-number <?= $status ?>"><?= $no ?></div>
                <div class="question-meta">
                    <div class="q-type"><?= $typeLabels[$log->question_type] ?? 'Soal' ?></div>
                    <div class="q-preview"><?= esc(strip_tags($log->question_text)) ?></div>
                </div>
                <div class="question-score-badge <?= $status ?>">
                    <?php if ($status === 'correct'): ?>
                        <i class="bi bi-check-circle-fill me-1"></i><?= number_format($log->score, 1) ?>
                    <?php elseif ($status === 'wrong'): ?>
                        <i class="bi bi-x-circle-fill me-1"></i><?= number_format($log->score, 1) ?>
                    <?php else: ?>
                        <i class="bi bi-dash me-1"></i>0
                    <?php endif; ?>
                </div>
                <i class="bi bi-chevron-down text-muted" style="transition: transform 0.2s;"></i>
            </div>

            <div class="question-body">
                <div class="question-text"><?= $log->question_text ?></div>

                <?php if ($log->question_type == 3): ?>
                    <!-- Essay -->
                    <div class="answer-option <?= $hasAnswer ? ($log->score > 0 ? 'selected-correct' : 'selected-wrong') : 'neutral' ?>">
                        <div class="answer-icon">
                            <?php if ($hasAnswer && $log->score > 0): ?>
                                <i class="bi bi-check"></i>
                            <?php elseif ($hasAnswer): ?>
                                <i class="bi bi-x"></i>
                            <?php else: ?>
                                <i class="bi bi-dash"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="answer-text"><?= $hasAnswer ? nl2br(esc($log->answer_text)) : '<em class="text-muted">Tidak dijawab</em>' ?></div>
                            <div class="answer-label <?= $hasAnswer ? ($log->score > 0 ? 'text-success' : 'text-danger') : 'text-muted' ?>">
                                Jawaban Anda
                            </div>
                        </div>
                    </div>
                    <?php if ($showCorrect && !empty($logAnswers)): ?>
                        <div class="answer-option correct-missed mt-2">
                            <div class="answer-icon"><i class="bi bi-key"></i></div>
                            <div>
                                <div class="answer-text"><?= esc($logAnswers[0]->answer_text) ?></div>
                                <div class="answer-label text-warning">Kunci Jawaban</div>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($log->question_type == 4 || $log->question_type == 5): ?>
                    <!-- Matching / True-False -->
                    <?php $studentAnswers = json_decode($log->answer_text, true) ?: []; ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th class="text-muted small">Pernyataan</th>
                                <th class="text-muted small">Jawaban Anda</th>
                                <?php if ($showCorrect): ?><th class="text-muted small">Kunci</th><?php endif; ?>
                                <th class="text-muted small text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logAnswers as $ans):
                                $parts = explode('|::|', $ans->answer_text);
                                $left = $parts[0] ?? '';
                                $right = $parts[1] ?? '';
                                $selected = $studentAnswers[$left] ?? '';
                                $isCorrect = ($selected === $right);
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= esc($left) ?></td>
                                <td><?= esc($selected ?: '-') ?></td>
                                <?php if ($showCorrect): ?><td class="text-success fw-semibold"><?= esc($right) ?></td><?php endif; ?>
                                <td class="text-center">
                                    <?php if (empty($selected)): ?>
                                        <span class="badge bg-secondary">Kosong</span>
                                    <?php elseif ($isCorrect): ?>
                                        <span class="badge bg-success">Benar</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Salah</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php else: ?>
                    <!-- Pilihan Ganda (Single / Multiple) -->
                    <?php foreach ($logAnswers as $ans):
                        $isSelected = $ans->is_selected == 1;
                        $isCorrect = $ans->is_correct == 1;

                        $optClass = 'neutral';
                        $optIcon = '<i class="bi bi-circle"></i>';
                        $optLabel = '';

                        if ($isSelected && $isCorrect) {
                            $optClass = 'selected-correct';
                            $optIcon = '<i class="bi bi-check-lg"></i>';
                            $optLabel = 'Jawaban Anda (Benar)';
                        } elseif ($isSelected && !$isCorrect) {
                            $optClass = 'selected-wrong';
                            $optIcon = '<i class="bi bi-x-lg"></i>';
                            $optLabel = 'Jawaban Anda (Salah)';
                        } elseif (!$isSelected && $isCorrect && $showCorrect) {
                            $optClass = 'correct-missed';
                            $optIcon = '<i class="bi bi-key"></i>';
                            $optLabel = 'Kunci Jawaban';
                        }
                    ?>
                        <div class="answer-option <?= $optClass ?>">
                            <div class="answer-icon"><?= $optIcon ?></div>
                            <div class="flex-grow-1">
                                <div class="answer-text"><?= $ans->answer_text ?></div>
                                <?php if ($optLabel): ?>
                                    <div class="answer-label <?= str_contains($optClass, 'correct') && !str_contains($optClass, 'wrong') ? 'text-success' : (str_contains($optClass, 'wrong') ? 'text-danger' : 'text-warning') ?>">
                                        <?= $optLabel ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Bottom Actions -->
        <div class="text-center mt-4 mb-5">
            <a href="<?= base_url('/student/results/view/' . $test->id) ?>" class="btn btn-primary fw-semibold px-4 me-2">
                <i class="bi bi-arrow-left me-1"></i> Kembali ke Hasil
            </a>
            <a href="<?= base_url('/student/dashboard') ?>" class="btn btn-outline-secondary fw-semibold px-4">
                <i class="bi bi-house-door me-1"></i> Dashboard
            </a>
        </div>

    </div>
</div>
<?= $this->endSection() ?>

<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?><?= lang('App.dashboard_student') ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .greeting-card {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: #fff;
        border-radius: 16px;
        padding: 24px 20px;
        margin-bottom: 24px;
        box-shadow: 0 4px 12px rgba(var(--color-primary-rgb), 0.3);
    }
    .greeting-name {
        font-size: 26px;
        font-weight: 700;
        letter-spacing: -0.02em;
        margin-bottom: 4px;
        text-wrap: balance;
    }
    .greeting-subtitle {
        font-size: 14px;
        opacity: 0.9;
        margin: 0;
    }
    .exam-card {
        background: var(--color-surface);
        border: none;
        border-radius: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        padding: 20px;
        margin-bottom: 16px;
        position: relative;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .exam-card:active {
        transform: scale(0.98);
    }
    .exam-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--color-text);
        margin-bottom: 8px;
        padding-right: 70px; /* space for badge */
    }
    .exam-desc {
        font-size: 13px;
        color: var(--color-text-muted);
        margin-bottom: 16px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .exam-schedule {
        font-size: 13px;
        color: var(--color-text-muted);
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 16px;
    }
    .duration-badge {
        position: absolute;
        top: 20px;
        right: 20px;
        background: rgba(var(--color-primary-rgb), 0.1);
        color: var(--color-primary);
        font-size: 12px;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 9999px;
    }
    .btn-exam-action {
        width: 100%;
        height: 52px;
        border-radius: 9999px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 15px;
        border: none;
        transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
    }
    .btn-exam-action:hover {
        transform: translateY(-1px);
        filter: brightness(1.04);
        box-shadow: 0 6px 16px -6px rgba(15, 23, 42, 0.25);
    }
    .btn-exam-action:active {
        transform: translateY(0) scale(0.98);
        filter: brightness(0.95);
    }
    .btn-exam-action.primary {
        background-color: var(--color-primary);
        color: #fff;
    }
    .btn-exam-action.warning {
        background-color: var(--color-warning);
        color: #000;
    }
    .btn-exam-action.success {
        background-color: #198754;
        color: #fff;
    }
    .btn-exam-action.disabled {
        background-color: #e9ecef;
        color: #adb5bd;
    }
    .empty-state {
        text-align: center;
        padding: 40px 20px;
    }
    .empty-icon {
        font-size: 64px;
        color: #dee2e6;
        margin-bottom: 16px;
    }
    .empty-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--color-text);
        margin-bottom: 8px;
    }
    .empty-desc {
        font-size: 14px;
        color: var(--color-text-muted);
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php if (session()->has('error')): ?>
    <div class="alert alert-danger shadow-sm rounded-3 mt-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= session('error') ?>
    </div>
<?php endif; ?>

<div class="greeting-card mt-3">
    <div class="greeting-name"><?= lang('App.hello') ?>, <?= esc(session('firstname')) ?>! 👋</div>
    <p class="greeting-subtitle"><?= lang('App.greeting_subtitle') ?></p>
</div>

<?php if (empty($availableTests)): ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-calendar-x"></i></div>
        <div class="empty-title"><?= lang('App.no_active_exam') ?></div>
        <div class="empty-desc"><?= lang('App.no_active_exam_desc') ?></div>
    </div>
<?php else: ?>
    <?php foreach ($availableTests as $test): ?>
        <div class="exam-card">
            <h2 class="exam-title"><?= esc($test->name) ?></h2>
            
            <?php if ($test->duration_minutes > 0): ?>
                <div class="duration-badge"><i class="bi bi-clock me-1"></i> <?= $test->duration_minutes ?><?= lang('App.minutes_abbr') ?></div>
            <?php endif; ?>
            
            <p class="exam-desc">
                <?= strip_tags($test->description) ?: lang('App.no_description') ?>
            </p>
            
            <div class="exam-schedule">
                <i class="bi bi-calendar2-event text-primary"></i>
                <?php if ($test->begin_time || $test->end_time): ?>
                    <span><?= $test->begin_time ? date('d/m/Y H:i', strtotime($test->begin_time)) : lang('App.now') ?> - <?= $test->end_time ? date('H:i', strtotime($test->end_time)) : lang('App.finished') ?></span>
                <?php else: ?>
                    <span><?= lang('App.available_anytime') ?></span>
                <?php endif; ?>
            </div>
            
            <?php 
                $status = $test->attempt_status ?? -1; 
                // 0=created, 1=active, 2=paused, 3=completed, 4=locked
                
                $isExpired = false;
                if ($test->end_time && time() > strtotime($test->end_time)) {
                    $isExpired = true;
                }
                
                $isUpcoming = false;
                if ($test->begin_time && time() < strtotime($test->begin_time)) {
                    $isUpcoming = true;
                }
            ?>
            
            <?php if ($status == 3 || ($isExpired && $status != -1)): ?>
                <?php 
                    $canShowScore = !empty($test->can_show_score);
                    $canAllowReview = !empty($test->can_allow_review);
                    $isRepeatable = !empty($test->is_repeatable) && !$isExpired;
                ?>
                <?php if ($canShowScore || $canAllowReview || $isRepeatable): ?>
                    <div class="d-flex flex-column gap-2">
                        <?php if ($canShowScore): ?>
                            <a href="<?= base_url('/student/results/view/' . $test->id) ?>" class="btn-exam-action success text-decoration-none">
                                <i class="bi bi-award-fill fs-5"></i> <?= lang('App.view_score') ?>
                            </a>
                        <?php elseif ($canAllowReview): ?>
                            <a href="<?= base_url('/student/results/view/' . $test->id) ?>" class="btn-exam-action primary text-decoration-none">
                                <i class="bi bi-journal-check fs-5"></i> <?= lang('App.review_questions') ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($isRepeatable): ?>
                            <a href="<?= base_url('/student/exam/prepare/' . $test->id) ?>" class="btn-exam-action warning text-decoration-none">
                                <i class="bi bi-arrow-repeat fs-5"></i> <?= lang('App.retry_exam') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <button class="btn-exam-action disabled" disabled>
                        <i class="bi bi-check-circle-fill fs-5"></i> <?= lang('App.finished') ?>
                    </button>
                <?php endif; ?>
            <?php elseif ($isExpired): ?>
                <button class="btn-exam-action disabled" disabled>
                    <i class="bi bi-lock-fill fs-5"></i> <?= lang('App.locked_time_up') ?>
                </button>
            <?php elseif ($isUpcoming): ?>
                <button class="btn-exam-action disabled" disabled>
                    <i class="bi bi-clock-fill fs-5"></i> <?= lang('App.not_started_yet') ?>
                </button>
            <?php elseif ($status == 1 || $status == 2): ?>
                <a href="<?= base_url('/student/exam/take/' . $test->id) ?>" class="btn-exam-action warning text-decoration-none">
                    <i class="bi bi-play-fill fs-5"></i> <?= lang('App.continue_exam') ?>
                </a>
            <?php else: ?>
                <a href="<?= base_url('/student/exam/prepare/' . $test->id) ?>" class="btn-exam-action primary text-decoration-none">
                    <i class="bi bi-play-fill fs-5"></i> <?= lang('App.start_exam') ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= $this->endSection() ?>

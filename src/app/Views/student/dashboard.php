<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Dashboard Siswa<?= $this->endSection() ?>

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
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 4px;
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
        transition: all 150ms ease;
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
    <div class="greeting-name">Halo, <?= esc(session('firstname')) ?>! 👋</div>
    <p class="greeting-subtitle">Siap untuk ujian hari ini? Berikut daftar ujianmu.</p>
</div>

<?php if (empty($availableTests)): ?>
    <div class="empty-state">
        <div class="empty-icon"><i class="bi bi-calendar-x"></i></div>
        <div class="empty-title">Tidak Ada Ujian Aktif</div>
        <div class="empty-desc">Belum ada ujian yang dijadwalkan untuk kelas Anda saat ini. Silakan cek kembali nanti.</div>
    </div>
<?php else: ?>
    <?php foreach ($availableTests as $test): ?>
        <div class="exam-card">
            <h2 class="exam-title"><?= esc($test->name) ?></h2>
            
            <?php if ($test->duration_minutes > 0): ?>
                <div class="duration-badge"><i class="bi bi-clock me-1"></i> <?= $test->duration_minutes ?>mnt</div>
            <?php endif; ?>
            
            <p class="exam-desc">
                <?= strip_tags($test->description) ?: 'Tidak ada deskripsi.' ?>
            </p>
            
            <div class="exam-schedule">
                <i class="bi bi-calendar2-event text-primary"></i>
                <?php if ($test->begin_time || $test->end_time): ?>
                    <span><?= $test->begin_time ? date('d/m/Y H:i', strtotime($test->begin_time)) : 'Sekarang' ?> - <?= $test->end_time ? date('H:i', strtotime($test->end_time)) : 'Selesai' ?></span>
                <?php else: ?>
                    <span>Tersedia kapan saja</span>
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
                <?php if (isset($test->results_visible) && $test->results_visible == 1): ?>
                    <a href="<?= base_url('/student/results/view/' . $test->id) ?>" class="btn-exam-action success text-decoration-none">
                        <i class="bi bi-award-fill fs-5"></i> Lihat Nilai
                    </a>
                <?php else: ?>
                    <button class="btn-exam-action disabled" disabled>
                        <i class="bi bi-check-circle-fill fs-5"></i> Selesai
                    </button>
                <?php endif; ?>
            <?php elseif ($isExpired): ?>
                <button class="btn-exam-action disabled" disabled>
                    <i class="bi bi-lock-fill fs-5"></i> Terkunci (Waktu Habis)
                </button>
            <?php elseif ($isUpcoming): ?>
                <button class="btn-exam-action disabled" disabled>
                    <i class="bi bi-clock-fill fs-5"></i> Belum Mulai
                </button>
            <?php elseif ($status == 1 || $status == 2): ?>
                <a href="<?= base_url('/student/exam/take/' . $test->id) ?>" class="btn-exam-action warning text-decoration-none">
                    <i class="bi bi-play-fill fs-5"></i> Lanjutkan Ujian
                </a>
            <?php else: ?>
                <a href="<?= base_url('/student/exam/prepare/' . $test->id) ?>" class="btn-exam-action primary text-decoration-none">
                    <i class="bi bi-play-fill fs-5"></i> Mulai Ujian
                </a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?= $this->endSection() ?>

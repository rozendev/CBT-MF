<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Hasil Ujian: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .result-hero {
        background: linear-gradient(135deg, <?= $isPassed ? '#0d9488, #14b8a6' : '#dc2626, #f87171' ?>);
        border-radius: 20px;
        padding: 3rem 2rem;
        text-align: center;
        color: white;
        position: relative;
        overflow: hidden;
        margin-bottom: 2rem;
    }
    .result-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -30%;
        width: 400px;
        height: 400px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
    }
    .result-hero::after {
        content: '';
        position: absolute;
        bottom: -40%;
        left: -20%;
        width: 300px;
        height: 300px;
        background: rgba(255,255,255,0.05);
        border-radius: 50%;
    }
    .result-hero * { position: relative; z-index: 1; }

    .score-circle {
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: rgba(255,255,255,0.15);
        backdrop-filter: blur(10px);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        border: 3px solid rgba(255,255,255,0.3);
    }
    .score-circle .score-value {
        font-size: 3rem;
        font-weight: 800;
        line-height: 1;
    }
    .score-circle .score-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.85;
        margin-top: 0.25rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1.5rem;
        border-radius: 50px;
        font-weight: 700;
        font-size: 1.1rem;
        background: rgba(255,255,255,0.2);
        backdrop-filter: blur(5px);
    }

    .stat-card {
        background: var(--bg-surface);
        border-radius: 14px;
        padding: 1.5rem;
        text-align: center;
        border: 1px solid var(--border-color);
        transition: transform 0.2s;
    }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-card .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.75rem;
        font-size: 1.3rem;
    }
    .stat-card .stat-value {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--text-primary);
        line-height: 1;
    }
    .stat-card .stat-label {
        font-size: 0.78rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 0.3rem;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.85rem 0;
        border-bottom: 1px solid var(--border-color);
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .info-label {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }
    .info-row .info-value {
        color: var(--text-primary);
        font-weight: 600;
        font-size: 0.9rem;
    }

    .action-card {
        background: var(--bg-surface);
        border-radius: 16px;
        padding: 2rem;
        border: 1px solid var(--border-color);
        text-align: center;
    }
    .action-card .action-icon {
        width: 56px;
        height: 56px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        font-size: 1.5rem;
    }

    .no-result-hero {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        border-radius: 20px;
        padding: 3rem 2rem;
        text-align: center;
        color: white;
        margin-bottom: 2rem;
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-in { animation: fadeInUp 0.5s ease forwards; }
    .animate-in-delay { animation: fadeInUp 0.5s ease 0.2s forwards; opacity: 0; }
    .animate-in-delay-2 { animation: fadeInUp 0.5s ease 0.4s forwards; opacity: 0; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8">

        <?php if ($showScore): ?>
        <!-- ── Score Hero ── -->
        <div class="result-hero animate-in">
            <p class="text-uppercase fw-bold mb-1" style="letter-spacing: 2px; font-size: 0.8rem; opacity: 0.85;">Hasil Ujian</p>
            <h3 class="fw-bold mb-4"><?= esc($test->name) ?></h3>

            <div class="score-circle">
                <div class="score-value"><?= number_format($attempt->score, $attempt->score == round($attempt->score) ? 0 : 1) ?></div>
                <div class="score-label">dari <?= $test->max_score ?></div>
            </div>

            <div class="status-badge">
                <?php if ($isPassed): ?>
                    <i class="bi bi-check-circle-fill"></i> LULUS
                <?php else: ?>
                    <i class="bi bi-x-circle-fill"></i> TIDAK LULUS
                <?php endif; ?>
            </div>

            <?php if (!$isPassed && $passingScore > 0): ?>
                <p class="mt-3 mb-0" style="opacity: 0.85; font-size: 0.9rem;">
                    Batas kelulusan: <strong><?= number_format($passingScore, $passingScore == round($passingScore) ? 0 : 1) ?></strong>
                </p>
            <?php endif; ?>
        </div>

        <!-- ── Stats Grid ── -->
        <div class="row g-3 mb-4 animate-in-delay">
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(13,110,253,0.1); color: #0d6efd;">
                        <i class="bi bi-list-ol"></i>
                    </div>
                    <div class="stat-value"><?= $totalQuestions ?></div>
                    <div class="stat-label">Total Soal</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(25,135,84,0.1); color: #198754;">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stat-value" style="color: #198754;"><?= $correctCount ?></div>
                    <div class="stat-label">Benar</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(220,53,69,0.1); color: #dc3545;">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stat-value" style="color: #dc3545;"><?= $wrongCount ?></div>
                    <div class="stat-label">Salah</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(108,117,125,0.1); color: #6c757d;">
                        <i class="bi bi-dash-circle"></i>
                    </div>
                    <div class="stat-value" style="color: #6c757d;"><?= $unansweredCount ?></div>
                    <div class="stat-label">Kosong</div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- ── No Score Hero ── -->
        <div class="no-result-hero animate-in">
            <div style="width: 80px; height: 80px; margin: 0 auto 1.5rem; background: rgba(255,255,255,0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                <i class="bi bi-check2-all" style="font-size: 2.5rem;"></i>
            </div>
            <h3 class="fw-bold mb-2">Ujian Berhasil Dikumpulkan</h3>
            <p class="mb-0" style="opacity: 0.85;"><?= esc($test->name) ?></p>
            <p class="mb-0 mt-3" style="opacity: 0.7; font-size: 0.9rem;">Skor untuk ujian ini belum ditampilkan. Hubungi pengawas untuk informasi lebih lanjut.</p>
        </div>
        <?php endif; ?>

        <!-- ── Exam Details ── -->
        <div class="card border-0 shadow-sm mb-4 animate-in-delay" style="border-radius: 16px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Detail Ujian</h6>
                <div class="info-row">
                    <span class="info-label">Waktu Selesai</span>
                    <span class="info-value"><?= $attempt->finished_at ? date('d M Y, H:i', strtotime($attempt->finished_at)) : '-' ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Waktu Mulai</span>
                    <span class="info-value"><?= $attempt->started_at ? date('d M Y, H:i', strtotime($attempt->started_at)) : '-' ?></span>
                </div>
                <?php if ($showScore): ?>
                <div class="info-row">
                    <span class="info-label">Batas Kelulusan</span>
                    <span class="info-value"><?= number_format($passingScore, $passingScore == round($passingScore) ? 0 : 1) ?> / <?= $test->max_score ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Akurasi</span>
                    <span class="info-value">
                        <?php
                            $accuracy = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100) : 0;
                        ?>
                        <?= $accuracy ?>%
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Actions ── -->
        <div class="row g-3 mb-4 animate-in-delay-2">
            <?php if ($allowReview): ?>
            <div class="col-md-6">
                <div class="action-card h-100">
                    <div class="action-icon" style="background: rgba(13,110,253,0.1); color: #0d6efd;">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <h6 class="fw-bold mb-1">Review Jawaban</h6>
                    <p class="text-muted small mb-3">Lihat kembali soal dan jawaban yang Anda pilih<?= $showCorrect ? ' beserta kunci jawaban' : '' ?>.</p>
                    <a href="<?= base_url('/student/results/review/' . $test->id) ?>" class="btn btn-primary fw-semibold px-4">
                        <i class="bi bi-arrow-right me-1"></i> Buka Review
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <div class="col-md-<?= $allowReview ? '6' : '12' ?>">
                <div class="action-card h-100">
                    <div class="action-icon" style="background: rgba(108,117,125,0.1); color: #6c757d;">
                        <i class="bi bi-house-door"></i>
                    </div>
                    <h6 class="fw-bold mb-1">Kembali ke Dashboard</h6>
                    <p class="text-muted small mb-3">Lihat ujian lain yang tersedia atau periksa jadwal ujian berikutnya.</p>
                    <a href="<?= base_url('/student/dashboard') ?>" class="btn btn-outline-secondary fw-semibold px-4">
                        <i class="bi bi-house-door me-1"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>
<?= $this->endSection() ?>

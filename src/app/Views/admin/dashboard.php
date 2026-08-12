<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Dashboard<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .hud-panel {
        position: relative;
        background:
            radial-gradient(90% 120% at 100% 0%, var(--brand-soft) 0%, transparent 55%),
            var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: var(--card-shadow);
        overflow: hidden;
    }
    .chart-wrap { position: relative; }
    .chart-center {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }
    .donut-col {
        position: relative;
        padding: clamp(1.5rem, 5vw, 3.5rem);
    }
    .donut-col::after {
        content: "";
        position: absolute;
        right: 0; top: 15%; bottom: 15%;
        width: 1px;
        background: linear-gradient(var(--border-color) 0%, transparent 100%);
    }
    .legend-row { display: flex; align-items: center; gap: 1rem; padding: 1.1rem 0; }
    .legend-row + .legend-row { border-top: 1px dashed var(--border-color); }
    .legend-dot {
        width: 10px; height: 10px; border-radius: 50%;
        flex: 0 0 auto;
    }
    .partition {
        border-top: 1px solid var(--border-color);
        padding: 1.35rem clamp(1.5rem, 5vw, 3.5rem);
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; flex-wrap: wrap;
        background: color-mix(in srgb, var(--bg-surface) 88%, transparent);
    }
    @media (max-width: 767.98px) {
        .donut-col::after { display: none; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php if (isset($redis_down) && $redis_down): ?>
<div class="alert alert-danger d-flex align-items-center rise" role="alert" style="border-radius: 16px;">
    <i class="bi bi-exclamation-triangle-fill fs-5 me-3"></i>
    <div>
        <strong class="d-block mb-1">Sistem anda tidak memiliki Redis!</strong>
        Performa akan turun signifikan dan beberapa fitur keamanan mungkin tidak berfungsi secara optimal.
    </div>
</div>
<?php endif; ?>

<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Overview · Partisipasi</div>
        <h1>Dashboard</h1>
        <p class="sub">Sebaran partisipasi siswa terhadap ujian secara langsung.</p>
    </div>
    <div class="actions">
        <a href="<?= base_url('/admin/logging') ?>" class="btn btn-ghost btn-sm">
            <i class="bi bi-journal-richtext me-1"></i> Logging Aktivitas
        </a>
        <a href="<?= base_url('/admin/tests/create') ?>" class="btn btn-accent btn-sm">
            <i class="bi bi-plus-circle me-1"></i> Buat Ujian
        </a>
    </div>
</div>

<?php
$chartArr = json_decode($chartData ?? '', true);
$chartVals = $chartArr['data'] ?? [0, 0, 0];
$chartTotal = array_sum($chartVals);
?>

<!-- Single HUD panel: donut + legend -->
<div class="hud-panel rise" style="--d:120ms">
    <div class="row g-0 align-items-stretch">
        <div class="col-md-5 donut-col">
            <div class="stat-label mb-1"><i class="bi bi-pie-chart me-1"></i> Rasio Partisipasi</div>
            <h6 class="fw-bold mb-4" style="letter-spacing:-0.02em;">Status Pengerjaan Ujian</h6>
            <div class="chart-wrap mx-auto" style="height: 300px; max-width: 360px;">
                <canvas id="examChart"></canvas>
                <div class="chart-center">
                    <div class="num fw-bold" style="font-size:2.2rem; letter-spacing:-0.04em; line-height:1;"><?= (int)$participationPercent ?>%</div>
                    <div class="mono" style="font-size:0.64rem; letter-spacing:0.16em; text-transform:uppercase; color:var(--text-tertiary); margin-top:4px;">Tuntas</div>
                </div>
            </div>
        </div>

        <div class="col-md-7 d-flex flex-column p-4">
            <div class="flex-grow-1 d-flex flex-column justify-content-center">
                <div class="legend-row">
                    <span class="legend-dot" style="background: var(--brand-color);"></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="color: var(--text-primary);">Sudah Mengerjakan</div>
                        <div class="small" style="color: var(--text-tertiary);">Attempt selesai dinilai</div>
                    </div>
                    <div class="num fw-bold" style="font-size:1.7rem; letter-spacing:-0.03em;"><?= esc($chartVals[0] ?? 0) ?></div>
                </div>
                <div class="legend-row">
                    <span class="legend-dot" style="background: var(--warn);"></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="color: var(--text-primary);">Sedang Mengerjakan</div>
                        <div class="small" style="color: var(--text-tertiary);">Dalam sesi aktif / paused</div>
                    </div>
                    <div class="num fw-bold" style="font-size:1.7rem; letter-spacing:-0.03em;"><?= esc($chartVals[1] ?? 0) ?></div>
                </div>
                <div class="legend-row">
                    <span class="legend-dot" style="background: var(--border-strong);"></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="color: var(--text-primary);">Belum Mengerjakan</div>
                        <div class="small" style="color: var(--text-tertiary);">Belum ada attempt aktif</div>
                    </div>
                    <div class="num fw-bold" style="font-size:1.7rem; letter-spacing:-0.03em;"><?= esc($chartVals[2] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="partition">
        <div class="mono" style="font-size:0.72rem; color: var(--text-tertiary);">
            <i class="bi bi-people me-1"></i> <?= esc($chartTotal) ?> siswa terdaftar
        </div>
        <div class="d-flex gap-2">
            <a href="<?= base_url('/admin/results') ?>" class="btn btn-ghost btn-sm"><i class="bi bi-bar-chart me-1"></i> Hasil</a>
            <a href="<?= base_url('/admin/reports') ?>" class="btn btn-ghost btn-sm"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Export</a>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartDataRaw = <?= $chartData ?? 'null' ?>;
    if (chartDataRaw) {
        const ctx = document.getElementById('examChart').getContext('2d');

        // Theme-aware colors from CSS tokens — no hardcoded palette
        const rootStyle = getComputedStyle(document.documentElement);
        const brandColor  = rootStyle.getPropertyValue('--brand-color').trim() || '#0e8a6b';
        const warnColor   = rootStyle.getPropertyValue('--warn').trim() || '#b07d1f';
        const trackColor  = rootStyle.getPropertyValue('--border-strong').trim() || '#d7dade';
        const mutedColor  = rootStyle.getPropertyValue('--text-tertiary').trim() || '#9aa0a8';

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartDataRaw.labels,
                datasets: [{
                    data: chartDataRaw.data,
                    backgroundColor: [brandColor, warnColor, trackColor],
                    borderWidth: 0,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '76%',
                animation: { animateRotate: true, duration: 1100, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(16, 18, 20, 0.92)',
                        titleColor: '#f2f4f6',
                        bodyColor: mutedColor,
                        borderColor: 'rgba(255,255,255,0.08)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 12,
                        usePointStyle: true,
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) label += ': ';
                                if (context.parsed !== null) label += context.parsed + ' Siswa';
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>
<?= $this->endSection() ?>

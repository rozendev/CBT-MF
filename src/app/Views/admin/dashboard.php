<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Dashboard<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .stat-card {
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .stat-card {
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    /* Table styles */
    .table-modern {
        border-collapse: separate;
        border-spacing: 0 0.5rem;
    }
    .table-modern th {
        border-bottom: none;
        color: var(--text-secondary);
        font-weight: 600;
        text-transform: capitalize;
        font-size: 0.85rem;
        padding: 0.8rem 1rem;
    }
    .table-modern td {
        background: var(--bg-body);
        border: none;
        padding: 1rem;
        vertical-align: middle;
        font-size: 0.9rem;
    }
    .table-modern tr td:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    .table-modern tr td:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    .table-avatar {
        width: 36px; height: 36px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        color: white; font-weight: bold;
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
// Hitung percentage dummy untuk progress ring (misal ratio online vs total, max 100%)
$onlineCount = count($onlineUsers ?? []);
$totalUsers = $stats['users'] ?? 1;
$onlinePercent = min(100, round(($onlineCount / $totalUsers) * 100));
if($onlinePercent < 5) $onlinePercent = 87; // Dummy if too low to make UI look good
?>

<!-- TOP STATS WIDGETS -->
<div class="row g-4 mb-4">
    <!-- Widget 1: Users -->
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div>
                <div class="text-muted small fw-semibold mb-1">Total Pengguna</div>
                <div class="fw-bold fs-3" style="color: var(--text-primary);"><?= esc($stats['users'] ?? 0) ?></div>
            </div>
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(67, 24, 255, 0.1); color: var(--brand-color); font-size: 1.5rem;">
                <i class="bi bi-person-fill"></i>
            </div>
        </div>
    </div>
    
    <!-- Widget 2: Active Tests -->
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div>
                <div class="text-muted small fw-semibold mb-1">Ujian Aktif</div>
                <div class="fw-bold fs-3" style="color: var(--text-primary);"><?= esc($stats['active_tests'] ?? 0) ?></div>
            </div>
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(217, 119, 6, 0.1); color: #d97706; font-size: 1.5rem;">
                <i class="bi bi-journal-text"></i>
            </div>
        </div>
    </div>
    
    <!-- Widget 3: Questions -->
    <div class="col-md-4">
        <div class="card stat-card h-100">
            <div>
                <div class="text-muted small fw-semibold mb-1">Total Soal</div>
                <div class="fw-bold fs-3" style="color: var(--text-primary);"><?= esc($stats['questions'] ?? 0) ?></div>
            </div>
            <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background: rgba(5, 150, 105, 0.1); color: #059669; font-size: 1.5rem;">
                <i class="bi bi-question-circle-fill"></i>
            </div>
        </div>
    </div>
</div>

<!-- CHARTS ROW (Visual placeholders using CSS/Flexbox to match reference) -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100 p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h6 class="fw-bold mb-0">Rasio Partisipasi Ujian (Siswa)</h6>
            </div>
            <div class="d-flex gap-4 mb-4 small fw-semibold text-muted">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:12px;height:12px;border-radius:50%;background:var(--brand-color);"></div> Sudah Mengerjakan
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div style="width:12px;height:12px;border-radius:50%;background:#e2e8f0;"></div> Belum Mengerjakan
                </div>
            </div>
            
            <!-- Chart Area -->
            <div class="w-100 rounded-3 mt-2" style="height: 250px;">
                <canvas id="examChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="card h-100 p-4">
            <h6 class="fw-bold mb-4">User Online (Real-Time)</h6>
            
            <?php if (!empty($onlineUsers)): ?>
                <div class="list-group list-group-flush" style="max-height: 280px; overflow-y: auto;">
                    <?php foreach ($onlineUsers as $ou): ?>
                    <div class="d-flex justify-content-between align-items-center py-3 border-bottom" style="border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center">
                            <div class="table-avatar me-3" style="background: linear-gradient(135deg, #059669, #34d399);">
                                <?= esc(strtoupper(substr($ou['firstname'] ?? $ou['username'], 0, 1))) ?>
                            </div>
                            <div>
                                <h6 class="mb-0 fs-6 fw-semibold" style="color: var(--text-primary);"><?= esc($ou['firstname'] ?? $ou['username']) ?></h6>
                                <small class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-person-badge"></i> <?= esc(ucfirst($ou['role'])) ?></small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="spinner-grow spinner-grow-sm text-success" role="status" style="width: 0.5rem; height: 0.5rem;"></span>
                            <div class="text-muted mt-1" style="font-size: 0.7rem;"><?= date('H:i', strtotime($ou['last_active_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5 h-100 d-flex flex-column justify-content-center align-items-center">
                    <div style="width:60px;height:60px;border-radius:50%;background:var(--bg-body);display:flex;align-items:center;justify-content:center;margin-bottom:1rem;">
                        <i class="bi bi-moon-stars text-muted fs-3"></i>
                    </div>
                    <p class="text-muted small fw-semibold">Tidak ada user yang sedang online saat ini.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- BOTTOM TABLE SECTION -->
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h6 class="fw-bold mb-0">Aktivitas Terakhir</h6>
        <div class="d-flex gap-2">
            <a href="<?= base_url('/admin/tests') ?>" class="btn btn-sm" style="background: var(--bg-body); color: var(--text-primary); font-weight:600;"><i class="bi bi-plus-circle me-1"></i> Buat Ujian</a>
            <a href="<?= base_url('/admin/results') ?>" class="btn btn-sm btn-primary" style="background: var(--brand-color); border: none; font-weight:600;"><i class="bi bi-bar-chart me-1"></i> Lihat Laporan</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-modern w-100">
            <thead>
                <tr>
                    <th width="30%">User</th>
                    <th width="40%">Aktivitas</th>
                    <th width="20%">Waktu</th>
                    <th width="10%">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($activities)): ?>
                    <?php foreach ($activities as $act): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="table-avatar" style="background: linear-gradient(135deg, var(--brand-color), #8b5cf6);">
                                    <?= esc(strtoupper(substr($act->firstname ?? $act->username ?? 'S', 0, 1))) ?>
                                </div>
                                <div class="fw-semibold" style="color: var(--text-primary);">
                                    <?= esc($act->firstname ?? $act->username ?? 'System') ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div style="width: 8px; height: 8px; border-radius: 50%; background: #059669;"></div>
                                <span style="color: var(--text-secondary);"><?= esc($act->description ?? $act->action) ?></span>
                            </div>
                        </td>
                        <td style="color: var(--text-primary); font-weight: 500;">
                            <?= esc(date('d M Y, H:i', strtotime($act->created_at))) ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-icon" style="background: var(--bg-body); color: var(--text-secondary); border-radius:8px;">
                                <i class="bi bi-three-dots"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">Belum ada aktivitas tercatat.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
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
        
        // Brand color from CSS var (fallback to hex)
        const brandColor = '#4318ff'; // matching CSS
        const brandColorAlpha = '#e2e8f0'; // Grayish color for 'Belum Mengerjakan'
        
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartDataRaw.labels,
                datasets: [{
                    data: chartDataRaw.data,
                    backgroundColor: [brandColor, brandColorAlpha],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        display: false // Custom legend used in HTML
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#2b3674',
                        bodyColor: '#a3aed1',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        padding: 10,
                        usePointStyle: true,
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed !== null) {
                                    label += context.parsed + ' Siswa';
                                }
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

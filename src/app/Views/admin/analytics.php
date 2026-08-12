<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Web Analytics<?= $this->endSection() ?>

<?= $this->section('content') ?>
<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Telemetri Lalu Lintas</div>
        <h1>Web Analytics</h1>
        <p class="sub">Statistik permintaan, tampilan halaman, bandwidth, dan ancaman yang diblokir Cloudflare.</p>
    </div>
    <?php if ($hasConfig): ?>
    <div class="actions">
        <select id="periodSelector" class="form-select form-select-sm" style="width: auto;" onchange="fetchAnalyticsData()">
            <option value="24h" selected>24 Jam Terakhir</option>
            <option value="7d">7 Hari Terakhir</option>
            <option value="30d">30 Hari Terakhir</option>
        </select>
        <button onclick="fetchAnalyticsData()" class="btn btn-ghost btn-sm">
            <i class="bi bi-arrow-clockwise" id="refreshIcon"></i>
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$hasConfig): ?>
<div class="card rise" style="--d:80ms">
    <div class="card-body p-5">
        <div class="empty">
            <div class="empty-icon"><i class="bi bi-cloud-slash"></i></div>
            <h4 class="mt-2 fw-bold">Cloudflare Analytics Belum Dikonfigurasi</h4>
            <p class="mx-auto">
                Sistem ujian Anda belum terhubung dengan API Cloudflare. Anda perlu mendapatkan API Token dan Zone ID dari Dasbor Cloudflare Anda untuk melihat statistik lalu lintas dan performa.
            </p>
        </div>
        <hr class="my-4" style="border-color: var(--border-color);">
        <div class="mx-auto p-4 rounded-4" style="max-width: 700px; background: var(--bg-soft); border: 1px solid var(--border-color);">
            <h6 class="fw-bold mb-3"><i class="bi bi-info-circle-fill me-2" style="color: var(--brand-color);"></i>Cara Mengatur API Cloudflare:</h6>
            <ol class="mb-0" style="line-height: 1.8; color: var(--text-secondary);">
                <li>Login ke Dasbor Cloudflare dan pilih domain/zona Anda.</li>
                <li>Di sidebar kanan bawah dasbor, salin <strong>Zone ID</strong>.</li>
                <li>Buka menu profil Anda di sudut kanan atas > <strong>My Profile</strong> > <strong>API Tokens</strong>.</li>
                <li>Klik <strong>Create Token</strong> > pilih <strong>Custom Token</strong>.</li>
                <li>Beri nama token. Pada <strong>Permissions</strong>, pilih <strong>Zone</strong> - <strong>Analytics</strong> - <strong>Read</strong>.</li>
                <li>Pada <strong>Zone Resources</strong>, pilih <strong>Include</strong> - <strong>Specific Zone</strong> - (Pilih Domain Anda).</li>
                <li>Lanjutkan ke ringkasan dan buat token. Salin <strong>API Token</strong> tersebut.</li>
                <li>Buka file <code>.env</code> di root folder proyek ini, dan tambahkan baris berikut:<br>
                    <code class="p-2 rounded d-block mt-2 mb-2 font-monospace" style="background: var(--rail); color: #d5e8e0;">
                        CLOUDFLARE_API_TOKEN="token_anda_disini"<br>
                        CLOUDFLARE_ZONE_ID="zone_id_anda_disini"
                    </code>
                </li>
                <li>Refresh halaman ini.</li>
            </ol>
        </div>
    </div>
</div>
<?php else: ?>

<!-- Dashboard Analytics -->
<div id="analyticsDashboard" style="display: none;">
    <!-- KPI strip -->
    <div class="statgrid rise" style="--d:60ms">
        <div class="stat">
            <div class="stat-label"><i class="bi bi-hdd-network"></i> Total Requests</div>
            <div class="stat-value" id="stat-requests">0</div>
        </div>
        <div class="stat">
            <div class="stat-label"><i class="bi bi-file-earmark-text"></i> Tampilan Halaman</div>
            <div class="stat-value" id="stat-visits">0</div>
        </div>
        <div class="stat">
            <div class="stat-label"><i class="bi bi-cloud-download"></i> Bandwidth Data</div>
            <div class="stat-value" id="stat-bandwidth">0 MB</div>
        </div>
        <div class="stat">
            <div class="stat-label"><i class="bi bi-shield-exclamation"></i> Ancaman &amp; Error</div>
            <div class="stat-value" id="stat-threats">0</div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <!-- Area Chart -->
        <div class="col-xl-8 col-lg-7">
            <div class="card h-100 rise" style="--d:120ms">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="stat-label mb-1"><i class="bi bi-graph-up me-1"></i> Grafik Trafik</div>
                            <h6 class="fw-bold mb-0" style="letter-spacing:-0.02em;">Permintaan per Periode</h6>
                        </div>
                    </div>
                    <div class="chart-area" style="height: 320px;">
                        <canvas id="trafficChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info / Security Chart -->
        <div class="col-xl-4 col-lg-5">
            <div class="card h-100 rise" style="--d:180ms">
                <div class="card-body p-4">
                    <div class="stat-label mb-1"><i class="bi bi-shield-check me-1"></i> WAF</div>
                    <h6 class="fw-bold mb-3" style="letter-spacing:-0.02em;">Keamanan (WAF)</h6>
                    <div class="chart-pie pt-2 pb-2" style="height: 240px;">
                        <canvas id="securityChart"></canvas>
                    </div>
                    <div class="mt-4 d-flex justify-content-center gap-4 small fw-semibold" style="color: var(--text-secondary);">
                        <span>
                            <span class="status-dot online" style="margin-right: 5px;"></span> Requests Aman
                        </span>
                        <span>
                            <span class="status-dot offline" style="margin-right: 5px;"></span> Ancaman Diblokir
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Skeleton -->
<div id="loadingSpinner" class="text-center py-5">
    <div class="d-inline-flex align-items-center gap-2 chip ghost px-3 py-2">
        <span class="spinner-border spinner-border-sm" role="status" style="color: var(--brand-color); width: 1rem; height: 1rem;"></span>
        Mengambil data dari Cloudflare...
    </div>
</div>

<!-- Error Message -->
<div id="errorMessage" class="alert alert-danger d-none" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i> <span id="errorText">Terjadi kesalahan</span>
</div>

<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?php if ($hasConfig): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    let trafficChartInstance = null;
    let securityChartInstance = null;

    document.addEventListener('DOMContentLoaded', function() {
        fetchAnalyticsData();
    });

    function fetchAnalyticsData() {
        const period = document.getElementById('periodSelector').value;
        const icon = document.getElementById('refreshIcon');
        
        icon.classList.add('fa-spin'); // if using fontawesome, or just add a custom spin class
        icon.style.transform = "rotate(180deg)";
        icon.style.transition = "transform 0.5s ease";
        
        document.getElementById('analyticsDashboard').style.display = 'none';
        document.getElementById('loadingSpinner').style.display = 'block';
        document.getElementById('errorMessage').classList.add('d-none');

        fetch(`<?= base_url('/admin/analytics/data') ?>?period=${period}`)
            .then(response => response.json())
            .then(data => {
                icon.style.transform = "rotate(0deg)";
                if (data.status === 400 || data.status === 500) {
                    throw new Error(data.messages?.error || data.message || 'Gagal mengambil data');
                }
                
                renderDashboard(data);
                
                document.getElementById('loadingSpinner').style.display = 'none';
                document.getElementById('analyticsDashboard').style.display = 'block';
            })
            .catch(error => {
                icon.style.transform = "rotate(0deg)";
                document.getElementById('loadingSpinner').style.display = 'none';
                document.getElementById('errorMessage').classList.remove('d-none');
                document.getElementById('errorText').textContent = error.message;
            });
    }

    function renderDashboard(data) {
        const totals = data.totals;
        const chartData = data.chart;

        // Theme-aware palette from CSS tokens
        const rootStyle = getComputedStyle(document.documentElement);
        const accent = rootStyle.getPropertyValue('--brand-color').trim() || '#0e8a6b';
        const danger = rootStyle.getPropertyValue('--danger').trim() || '#d64550';
        const gridColor = rootStyle.getPropertyValue('--border-color').trim() || 'rgba(230,232,234,1)';

        // Update Stats
        document.getElementById('stat-requests').textContent = totals.requests.toLocaleString();
        document.getElementById('stat-visits').textContent = totals.pageViews.toLocaleString();
        document.getElementById('stat-bandwidth').textContent = totals.bytesFormatted;
        document.getElementById('stat-threats').textContent = totals.threats.toLocaleString();

        // Render Traffic Chart
        const trafficCtx = document.getElementById("trafficChart").getContext('2d');
        if (trafficChartInstance) {
            trafficChartInstance.destroy();
        }

        trafficChartInstance = new Chart(trafficCtx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: "Requests",
                    lineTension: 0.35,
                    backgroundColor: accent + '14',
                    borderColor: accent,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: accent,
                    pointBorderColor: accent,
                    pointHoverRadius: 5,
                    pointHitRadius: 10,
                    pointBorderWidth: 2,
                    data: chartData.requests,
                    fill: true
                }]
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                scales: {
                    x: { grid: { display: false }, border: { color: gridColor } },
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor, borderDash: [3], drawBorder: false },
                        border: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(16, 18, 20, 0.92)',
                        titleColor: '#f2f4f6',
                        borderColor: 'rgba(255,255,255,0.08)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 12
                    }
                }
            }
        });

        // Render Security Chart
        const securityCtx = document.getElementById("securityChart").getContext('2d');
        if (securityChartInstance) {
            securityChartInstance.destroy();
        }

        const safeRequests = totals.requests - totals.threats;

        securityChartInstance = new Chart(securityCtx, {
            type: 'doughnut',
            data: {
                labels: ["Aman", "Ancaman"],
                datasets: [{
                    data: [safeRequests, totals.threats],
                    backgroundColor: [accent, danger],
                    hoverOffset: 6,
                    borderWidth: 0,
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '75%',
                animation: { animateRotate: true, duration: 900, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false }
                }
            },
        });
    }
</script>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Web Analytics<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="h3 mb-0 text-gray-800">Web Analytics (Cloudflare)</h2>
    <?php if ($hasConfig): ?>
    <div>
        <select id="periodSelector" class="form-select w-auto d-inline-block shadow-sm" onchange="fetchAnalyticsData()">
            <option value="24h" selected>24 Jam Terakhir</option>
            <option value="7d">7 Hari Terakhir</option>
            <option value="30d">30 Hari Terakhir</option>
        </select>
        <button onclick="fetchAnalyticsData()" class="btn btn-primary ms-2 shadow-sm">
            <i class="bi bi-arrow-clockwise" id="refreshIcon"></i>
        </button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$hasConfig): ?>
<div class="card shadow-sm border-0">
    <div class="card-body p-5 text-center">
        <i class="bi bi-cloud-slash text-muted" style="font-size: 4rem;"></i>
        <h4 class="mt-4 fw-bold">Cloudflare Analytics Belum Dikonfigurasi</h4>
        <p class="text-muted mx-auto" style="max-width: 600px;">
            Sistem ujian Anda belum terhubung dengan API Cloudflare. Anda perlu mendapatkan API Token dan Zone ID dari Dasbor Cloudflare Anda untuk melihat statistik lalu lintas dan performa.
        </p>
        <hr class="my-4">
        <div class="text-start mx-auto bg-light p-4 rounded-3 border" style="max-width: 700px;">
            <h6 class="fw-bold mb-3"><i class="bi bi-info-circle-fill text-primary me-2"></i>Cara Mengatur API Cloudflare:</h6>
            <ol class="mb-0 text-muted" style="line-height: 1.8;">
                <li>Login ke Dasbor Cloudflare dan pilih domain/zona Anda.</li>
                <li>Di sidebar kanan bawah dasbor, salin <strong>Zone ID</strong>.</li>
                <li>Buka menu profil Anda di sudut kanan atas > <strong>My Profile</strong> > <strong>API Tokens</strong>.</li>
                <li>Klik <strong>Create Token</strong> > pilih <strong>Custom Token</strong>.</li>
                <li>Beri nama token. Pada <strong>Permissions</strong>, pilih <strong>Zone</strong> - <strong>Analytics</strong> - <strong>Read</strong>.</li>
                <li>Pada <strong>Zone Resources</strong>, pilih <strong>Include</strong> - <strong>Specific Zone</strong> - (Pilih Domain Anda).</li>
                <li>Lanjutkan ke ringkasan dan buat token. Salin <strong>API Token</strong> tersebut.</li>
                <li>Buka file <code>.env</code> di root folder proyek ini, dan tambahkan baris berikut:<br>
                    <code class="bg-dark text-light p-2 rounded d-block mt-2 mb-2 font-monospace">
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
    <div class="row g-4 mb-4">
        <!-- Total Requests -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2" style="border-left: 4px solid #4e73df !important;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Requests</div>
                            <div class="h3 mb-0 fw-bold text-gray-800" id="stat-requests">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-hdd-network text-gray-300 fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Page Views -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2" style="border-left: 4px solid #1cc88a !important;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Tampilan Halaman</div>
                            <div class="h3 mb-0 fw-bold text-gray-800" id="stat-visits">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-file-earmark-text text-gray-300 fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bandwidth -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2" style="border-left: 4px solid #36b9cc !important;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Bandwidth Data</div>
                            <div class="h3 mb-0 fw-bold text-gray-800" id="stat-bandwidth">0 MB</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-cloud-download text-gray-300 fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Threats / Errors -->
        <div class="col-xl-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 py-2" style="border-left: 4px solid #e74a3b !important;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Ancaman & Error</div>
                            <div class="h3 mb-0 fw-bold text-gray-800" id="stat-threats">0</div>
                        </div>
                        <div class="col-auto">
                            <i class="bi bi-shield-exclamation text-gray-300 fs-1 opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Area Chart -->
        <div class="col-xl-8 col-lg-7">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between bg-white border-0 border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Grafik Trafik</h6>
                </div>
                <div class="card-body">
                    <div class="chart-area" style="height: 320px;">
                        <canvas id="trafficChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Info / Security Chart -->
        <div class="col-xl-4 col-lg-5">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between bg-white border-0 border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Keamanan (WAF)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-pie pt-4 pb-2" style="height: 250px;">
                        <canvas id="securityChart"></canvas>
                    </div>
                    <div class="mt-4 text-center small">
                        <span class="mr-2">
                            <i class="bi bi-circle-fill text-primary"></i> Requests Aman
                        </span>
                        <span class="mr-2 ms-3">
                            <i class="bi bi-circle-fill text-danger"></i> Ancaman Diblokir
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Loading Spinner -->
<div id="loadingSpinner" class="text-center py-5">
    <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
        <span class="visually-hidden">Loading...</span>
    </div>
    <p class="mt-3 text-muted">Mengambil data dari Cloudflare...</p>
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
                    lineTension: 0.3,
                    backgroundColor: "rgba(78, 115, 223, 0.05)",
                    borderColor: "rgba(78, 115, 223, 1)",
                    pointRadius: 3,
                    pointBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointBorderColor: "rgba(78, 115, 223, 1)",
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: "rgba(78, 115, 223, 1)",
                    pointHoverBorderColor: "rgba(78, 115, 223, 1)",
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
                    x: { grid: { display: false, drawBorder: false } },
                    y: { 
                        beginAtZero: true,
                        grid: { color: "rgb(234, 236, 244)", zeroLineColor: "rgb(234, 236, 244)", drawBorder: false, borderDash: [2], zeroLineBorderDash: [2] }
                    }
                },
                plugins: {
                    legend: { display: false }
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
                    backgroundColor: ['#4e73df', '#e74a3b'],
                    hoverBackgroundColor: ['#2e59d9', '#c43c30'],
                    hoverBorderColor: "rgba(234, 236, 244, 1)",
                }],
            },
            options: {
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: { display: false }
                }
            },
        });
    }
</script>
<?php endif; ?>
<?= $this->endSection() ?>

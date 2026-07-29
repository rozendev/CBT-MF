<?php
// Tentukan waktu greeting
$hour = date('H');
if ($hour < 11) $greeting = 'Pagi';
elseif ($hour < 15) $greeting = 'Siang';
elseif ($hour < 18) $greeting = 'Sore';
else $greeting = 'Malam';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->renderSection('page_title') ?> — Sistem Ujian</title>
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <!-- Menggunakan font modern (Outfit) -->
    <link href="<?= base_url('assets/css/outfit.css?v=1.1') ?>" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --topbar-height: 80px;
            
            /* Light Theme (Default) */
            --bg-body: #f4f7fe;
            --bg-surface: #ffffff;
            --text-primary: #2b3674;
            --text-secondary: #a3aed1;
            --border-color: #e2e8f0;
            --card-shadow: 0 4px 15px rgba(0,0,0,0.03);
            
            --sidebar-bg: #ffffff;
            --sidebar-text: #a3aed1;
            --sidebar-hover-bg: #f4f7fe;
            --sidebar-hover-text: #2b3674;
            --sidebar-active-bg: #f4e8ff; /* Soft purple */
            --sidebar-active-text: #4318ff; /* Deep purple */
            
            --topbar-bg: rgba(255, 255, 255, 0.85);
            --brand-color: #4318ff;
        }

        [data-theme="dark"] {
            /* Dark Theme */
            --bg-body: #13131a;
            --bg-surface: #1c1c24;
            --text-primary: #ffffff;
            --text-secondary: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.05);
            --card-shadow: 0 4px 15px rgba(0,0,0,0.2);
            
            --sidebar-bg: #1c1c24;
            --sidebar-text: #94a3b8;
            --sidebar-hover-bg: rgba(255,255,255,0.05);
            --sidebar-hover-text: #ffffff;
            --sidebar-active-bg: rgba(67, 24, 255, 0.15); 
            --sidebar-active-text: #7551ff;
            
            --topbar-bg: rgba(28, 28, 36, 0.85);
            --brand-color: #7551ff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            overflow-x: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* ── Sidebar ─────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s cubic-bezier(0.4,0,0.2,1), background 0.3s ease;
        }
        .sidebar-brand {
            padding: 1.5rem 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        .sidebar-brand .icon-box { 
            width: 32px; height: 32px;
            background: var(--brand-color);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 1.2rem;
        }
        .sidebar-brand h4 {
            margin: 0;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.2rem;
        }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 4px; }
        [data-theme="dark"] .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); }
        
        .nav-label {
            padding: 1.2rem 0.6rem 0.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--text-secondary);
            font-weight: 600;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.75rem 1rem;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            border-radius: 10px;
            margin-bottom: 0.2rem;
            transition: all 0.2s ease;
        }
        .nav-item:hover {
            background: var(--sidebar-hover-bg);
            color: var(--sidebar-hover-text);
        }
        .nav-item.active {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-active-text);
            font-weight: 600;
        }
        .nav-item i { font-size: 1.2rem; }

        /* ── Topbar ──────────────────────────────────────── */
        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--topbar-height);
            background: var(--topbar-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: flex;
            align-items: center;
            padding: 0 2rem;
            z-index: 1030;
            transition: left 0.3s cubic-bezier(0.4,0,0.2,1), background 0.3s ease;
        }
        .btn-toggle-sidebar {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--text-primary);
            cursor: pointer;
            padding: 0.25rem;
        }
        .topbar-icon-btn {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--bg-body);
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            border: none; cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .topbar-icon-btn:hover { color: var(--brand-color); }
        .user-profile-btn {
            display: flex; align-items: center; gap: 0.6rem;
            background: none; border: none; padding: 0; cursor: pointer;
        }
        .user-avatar {
            width: 40px; height: 40px; border-radius: 12px;
            background: linear-gradient(135deg, var(--brand-color), #bc95ff);
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 1rem;
        }

        /* ── Main Content ────────────────────────────────── */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            padding: 2rem;
            min-height: calc(100vh - var(--topbar-height));
            transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        
        /* Utility overrides for components */
        .card {
            background: var(--bg-surface);
            border: none;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            transition: background 0.3s, box-shadow 0.2s;
        }
        /* Global Text Overrides */
        body, h1, h2, h3, h4, h5, h6, p, label, .h1, .h2, .h3, .h4, .h5, .h6 { color: var(--text-primary); }
        .text-body { color: var(--text-primary) !important; }
        .text-dark { color: var(--text-primary) !important; }
        .text-muted { color: var(--text-secondary) !important; }
        .bg-white { background: var(--bg-surface) !important; }
        .bg-light { background: var(--bg-body) !important; }
        .border, .border-bottom, .border-top, .border-start, .border-end { border-color: var(--border-color) !important; }
        
        /* Buttons Overrides */
        [data-theme="dark"] .btn-outline-secondary { color: var(--text-secondary); border-color: var(--border-color); }
        [data-theme="dark"] .btn-outline-secondary:hover { background-color: rgba(255,255,255,0.1); color: var(--text-primary); }
        [data-theme="dark"] .btn-light { background-color: rgba(255,255,255,0.05); color: var(--text-primary); border-color: transparent; }
        [data-theme="dark"] .btn-light:hover { background-color: rgba(255,255,255,0.1); }
        [data-theme="dark"] .btn-outline-dark { color: var(--text-primary); border-color: var(--border-color); }
        [data-theme="dark"] .btn-outline-dark:hover { background-color: rgba(255,255,255,0.1); }
        
        /* Table overrides */
        .table { color: var(--text-primary) !important; }
        .table-light { background: var(--bg-body) !important; color: var(--text-primary) !important; }
        .table-light th { background: var(--bg-body) !important; color: var(--text-secondary) !important; border-bottom: none; }
        .table>:not(caption)>*>* { background-color: transparent !important; border-bottom-color: var(--border-color); color: var(--text-primary); }
        .table-striped>tbody>tr:nth-of-type(odd)>* { color: var(--text-primary); }
        [data-theme="dark"] .table-striped>tbody>tr:nth-of-type(odd)>* { background-color: rgba(255,255,255,0.02) !important; }
        .table-hover>tbody>tr:hover>* { color: var(--text-primary); }
        [data-theme="dark"] .table-hover>tbody>tr:hover>* { background-color: rgba(255,255,255,0.05) !important; }

        /* Form Controls & Modals & List Group */
        .form-control, .form-select { 
            background-color: var(--bg-body); 
            border-color: var(--border-color); 
            color: var(--text-primary); 
        }
        .form-control:focus, .form-select:focus { 
            background-color: var(--bg-body); 
            color: var(--text-primary); 
            border-color: var(--brand-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 24, 255, 0.25);
        }
        [data-theme="dark"] .form-control::placeholder { color: #64748b; }
        .modal-content { background-color: var(--bg-surface); color: var(--text-primary); border-color: var(--border-color); }
        .modal-header, .modal-footer { border-color: var(--border-color); }
        .list-group-item { background-color: transparent; color: var(--text-primary); border-color: var(--border-color); }
        .dropdown-menu { background-color: var(--bg-surface); border-color: var(--border-color); }
        .dropdown-item { color: var(--text-primary); }
        .dropdown-item:hover { background-color: var(--bg-body); color: var(--brand-color); }
        .alert-info { background-color: rgba(13, 110, 253, 0.1); color: #0d6efd; border: none; }
        [data-theme="dark"] .alert-info { background-color: rgba(13, 110, 253, 0.15); color: #6ea8fe; }

        /* ── Overlay for mobile ──────────────────────────── */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1035;
        }
        /* ── Responsive ──────────────────────────────────── */
        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .topbar { left: 0; padding: 0 1rem; }
            .main-content { margin-left: 0; padding: 1.5rem 1rem; }
            .btn-toggle-sidebar { display: block; }
            .greeting-text { display: none !important; }
        }
    </style>
    <script src="<?= base_url('vendor/sweetalert2/sweetalert2.min.js?v=1.1') ?>"></script>
    <script>
        // Init theme right away to prevent flash
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
    <?= $this->renderSection('styles') ?>
</head>
<body>
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="icon-box"><i class="bi bi-hexagon-fill"></i></div>
            <h4>Sistem Ujian</h4>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">MAIN</div>
            <?php
            $currentUrl = current_url();
            $isAdmin = session()->get('role') === 'admin';
            
            $mainNav = [
                ['url' => '/admin/dashboard', 'icon' => 'bi-house-door', 'label' => lang('App.menu_dashboard')],
                ['url' => '/admin/tests',     'icon' => 'bi-file-earmark-text', 'label' => lang('App.menu_exams')],
                ['url' => '/proctor',         'icon' => 'bi-broadcast text-danger', 'label' => lang('App.menu_proctoring')],
            ];
            foreach ($mainNav as $item):
                $active = str_contains($currentUrl, $item['url']) ? 'active' : '';
            ?>
                <a href="<?= base_url($item['url']) ?>" class="nav-item <?= $active ?>">
                    <i class="bi <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>

            <div class="nav-label">MANAGEMENT</div>
            <?php
            $mgtNav = [
                ['url' => '/admin/results',   'icon' => 'bi-bar-chart', 'label' => lang('App.menu_results')],
                ['url' => '/admin/reports',   'icon' => 'bi-file-earmark-spreadsheet', 'label' => lang('App.menu_reports')],
                ['url' => '/admin/questions', 'icon' => 'bi-journal-text', 'label' => lang('App.menu_questions')],
                ['url' => '/admin/subjects',  'icon' => 'bi-book', 'label' => lang('App.menu_subjects')],
                ['url' => '/admin/modules',   'icon' => 'bi-folder2', 'label' => lang('App.menu_modules')],
            ];
            if ($isAdmin) {
                array_unshift($mgtNav, ['url' => '/admin/users', 'icon' => 'bi-people', 'label' => lang('App.menu_users')]);
                $mgtNav[] = ['url' => '/admin/groups', 'icon' => 'bi-collection', 'label' => lang('App.menu_groups')];
            }
            foreach ($mgtNav as $item):
                $active = str_contains($currentUrl, $item['url']) ? 'active' : '';
            ?>
                <a href="<?= base_url($item['url']) ?>" class="nav-item <?= $active ?>">
                    <i class="bi <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>

            <?php if ($isAdmin): ?>
            <div class="nav-label">SYSTEM</div>
            <a href="<?= base_url('/admin/analytics') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/analytics') ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i> <?= lang('App.menu_analytics') ?>
            </a>
            <a href="<?= base_url('/admin/suspend') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/suspend') ? 'active' : '' ?>">
                <i class="bi bi-shield-lock"></i> <?= lang('App.menu_security') ?>
            </a>
            <a href="<?= base_url('/admin/settings') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/settings') ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> <?= lang('App.menu_settings') ?>
            </a>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Topbar -->
    <header class="topbar d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <button class="btn-toggle-sidebar" id="toggleSidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="greeting-text d-none d-md-block">
                <h5 class="mb-0 fw-bold" style="color: var(--text-primary);">Selamat <?= $greeting ?>, <?= esc(session()->get('firstname') ?? 'Admin') ?></h5>
                <small style="color: var(--text-secondary); font-size: 0.85rem;">Tanggal: <?= date('d M, Y') ?></small>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 gap-md-3">
            <!-- Language Dropdown -->
            <div class="dropdown">
                <button class="topbar-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= lang('App.language') ?>">
                    <i class="bi bi-globe"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm" style="border-radius: 12px; background: var(--bg-surface);">
                    <li><a class="dropdown-item py-2 <?= (session('lang') == 'id' || !session('lang')) ? 'active' : '' ?>" href="<?= base_url('lang/id') ?>" style="color: var(--text-primary);">Indonesia</a></li>
                    <li><a class="dropdown-item py-2 <?= session('lang') == 'en' ? 'active' : '' ?>" href="<?= base_url('lang/en') ?>" style="color: var(--text-primary);">English</a></li>
                </ul>
            </div>

            <!-- Theme Toggle -->
            <button class="topbar-icon-btn" id="btnThemeToggle" title="Toggle Theme">
                <i class="bi bi-moon-stars" id="themeIcon"></i>
            </button>
            
            <a href="<?= base_url('/admin/results') ?>" class="topbar-icon-btn" title="Laporan">
                <i class="bi bi-bell"></i>
            </a>

            <!-- User Profile Dropdown -->
            <div class="dropdown">
                <button class="user-profile-btn ms-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="d-none d-md-block text-end me-1">
                        <div class="fw-bold" style="font-size: 0.9rem; color: var(--text-primary); line-height:1.2;"><?= esc(session()->get('firstname') ?? 'User') ?></div>
                        <small style="font-size: 0.75rem; color: var(--text-secondary);"><?= esc(session()->get('username')) ?></small>
                    </div>
                    <div class="user-avatar">
                        <?= strtoupper(substr(session()->get('firstname') ?? 'A', 0, 1)) ?>
                    </div>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm" style="border-radius: 12px; background: var(--bg-surface);">
                    <li><a class="dropdown-item py-2" href="<?= base_url('/admin/tests') ?>" style="color: var(--text-primary);"><i class="bi bi-file-earmark-text me-2 text-muted"></i> <?= lang('App.exam_management') ?></a></li>
                    <li><a class="dropdown-item py-2" href="<?= base_url('/admin/results') ?>" style="color: var(--text-primary);"><i class="bi bi-bar-chart-fill me-2 text-muted"></i> <?= lang('App.exam_reports') ?></a></li>
                    <li><a class="dropdown-item py-2" href="<?= base_url('/admin/reports') ?>" style="color: var(--text-primary);"><i class="bi bi-file-earmark-spreadsheet-fill me-2 text-success"></i> <?= lang('App.menu_reports') ?></a></li>
                    <li><hr class="dropdown-divider" style="border-color: var(--border-color);"></li>
                    <li><a class="dropdown-item py-2 text-danger fw-semibold" href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();"><i class="bi bi-box-arrow-right me-2"></i> <?= lang('App.logout') ?></a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Flash Messages -->
        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= esc(session()->getFlashdata('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= esc(session()->getFlashdata('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>

    <script src="<?= base_url('vendor/bootstrap/js/bootstrap.bundle.min.js?v=1.1') ?>"></script>
    <script>
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('toggleSidebar');

        toggleBtn?.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });

        overlay?.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });

        // Auto-dismiss alerts
        document.querySelectorAll('.alert-dismissible').forEach(el => {
            setTimeout(() => {
                const bs = bootstrap.Alert.getOrCreateInstance(el);
                bs.close();
            }, 5000);
        });

        // Theme Toggle Logic
        const btnThemeToggle = document.getElementById('btnThemeToggle');
        const themeIcon = document.getElementById('themeIcon');
        
        function updateThemeIcon(theme) {
            if (theme === 'dark') {
                themeIcon.className = 'bi bi-sun-fill';
            } else {
                themeIcon.className = 'bi bi-moon-stars';
            }
        }
        
        updateThemeIcon(document.documentElement.getAttribute('data-theme'));

        btnThemeToggle.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
        });

        // Keep-Alive Ping
        setInterval(function() {
            fetch('<?= base_url('/api/keep-alive') ?>', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                }
            }).catch(e => console.error('Keep-alive failed:', e));
        }, 30000);

        // ── Proctor Report Polling ─────────────────────────
        // Allows admin to receive teacher reports from ANY admin page
        (function() {
            let lastCheckTime = sessionStorage.getItem('lastCheckTime') || '';
            let seenReportIds;
            try {
                seenReportIds = new Set(JSON.parse(sessionStorage.getItem('seenReportIds') || '[]'));
            } catch(e) {
                seenReportIds = new Set();
            }

            function checkProctorReports() {
                const url = '<?= base_url('admin/notifications/proctor-reports') ?>' + 
                            (lastCheckTime ? '?since=' + encodeURIComponent(lastCheckTime) : '');

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success' && res.reports && res.reports.length > 0) {
                        lastCheckTime = res.server_time;
                        sessionStorage.setItem('lastCheckTime', lastCheckTime);

                        res.reports.forEach(report => {
                            if (seenReportIds.has(report.id)) return;
                            seenReportIds.add(report.id);
                            sessionStorage.setItem('seenReportIds', JSON.stringify(Array.from(seenReportIds)));

                            try {
                                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                                [0, 0.15].forEach(delay => {
                                    const osc = ctx.createOscillator();
                                    const gain = ctx.createGain();
                                    osc.connect(gain);
                                    gain.connect(ctx.destination);
                                    osc.frequency.value = 880;
                                    gain.gain.value = 0.3;
                                    osc.start(ctx.currentTime + delay);
                                    osc.stop(ctx.currentTime + delay + 0.1);
                                });
                            } catch(e) {}

                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    title: '<i class="bi bi-flag-fill text-warning"></i> Laporan Pengawas!',
                                    html: `Pengawas <strong>${report.proctor_name}</strong> menyarankan tindakan <strong>${(report.suggested_action || 'ban').toUpperCase()}</strong>.<br><br>Alasan:<br><span class="text-danger fw-bold">"${report.reason}"</span>`,
                                    icon: 'warning',
                                    showConfirmButton: true,
                                    confirmButtonText: '<i class="bi bi-shield-lock"></i> Buka Suspend Menu',
                                    showCancelButton: true,
                                    cancelButtonText: 'Tutup',
                                    customClass: { popup: 'rounded-4' }
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        window.location.href = '<?= base_url('admin/suspend') ?>?search=' + encodeURIComponent(report.student_username || '');
                                    }
                                });
                            }
                        });
                    } else if (res.server_time) {
                        lastCheckTime = res.server_time;
                        sessionStorage.setItem('lastCheckTime', lastCheckTime);
                    }
                })
                .catch(() => {});
            }

            // Check immediately, then poll every 10 seconds
            checkProctorReports();
            setInterval(checkProctorReports, 10000);
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?= $this->renderSection('scripts') ?>
    <form id="logout-form" action="<?= base_url('logout') ?>" method="POST" style="display: none;">
        <?= csrf_field() ?>
    </form>
</body>
</html>

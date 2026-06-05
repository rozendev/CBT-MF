<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->renderSection('page_title') ?> — Sistem Ujian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-bg: #1e293b;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(79,70,229,0.15);
            --sidebar-accent: #4f46e5;
            --topbar-height: 60px;
            --content-bg: #f1f5f9;
            --card-bg: #ffffff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--content-bg);
            color: #1e293b;
            overflow-x: hidden;
        }
        /* ── Sidebar ─────────────────────────────────────── */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            color: #e2e8f0;
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .sidebar-brand {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .sidebar-brand .icon { font-size: 1.5rem; }
        .sidebar-brand h5 {
            margin: 0;
            font-weight: 700;
            font-size: 1.1rem;
            color: #f1f5f9;
        }
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 0.75rem 0;
        }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
        .nav-label {
            padding: 1rem 1.5rem 0.4rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #64748b;
            font-weight: 600;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 1.5rem;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s ease;
        }
        .nav-item:hover {
            background: var(--sidebar-hover);
            color: #e2e8f0;
        }
        .nav-item.active {
            background: var(--sidebar-active);
            color: #a5b4fc;
            border-left-color: var(--sidebar-accent);
        }
        .nav-item i { font-size: 1.1rem; width: 20px; text-align: center; }
        .sidebar-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.06);
        }
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .sidebar-user .avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: white;
        }
        .sidebar-user .info { flex: 1; min-width: 0; }
        .sidebar-user .name {
            font-weight: 600;
            font-size: 0.85rem;
            color: #f1f5f9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-user .role {
            font-size: 0.7rem;
            color: #64748b;
            text-transform: capitalize;
        }
        /* ── Topbar ──────────────────────────────────────── */
        .topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--topbar-height);
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 1030;
            transition: left 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        .topbar-title { font-weight: 600; font-size: 1.1rem; color: #1e293b; }
        .topbar-actions { display: flex; align-items: center; gap: 0.75rem; }
        .btn-toggle-sidebar {
            display: none;
            background: none;
            border: none;
            font-size: 1.3rem;
            color: #475569;
            cursor: pointer;
            padding: 0.25rem;
        }
        /* ── Main Content ────────────────────────────────── */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            padding: 1.5rem;
            min-height: calc(100vh - var(--topbar-height));
            transition: margin-left 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        /* ── Cards ───────────────────────────────────────── */
        .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        /* ── Overlay for mobile ──────────────────────────── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1035;
        }
        /* ── Responsive ──────────────────────────────────── */
        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .topbar { left: 0; }
            .main-content { margin-left: 0; }
            .btn-toggle-sidebar { display: block; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?= $this->renderSection('styles') ?>
</head>
<body>
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="icon">🎓</span>
            <h5>Sistem Ujian</h5>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Menu Utama</div>
            <?php
            $currentUrl = current_url();
            $isAdmin = session()->get('role') === 'admin';
            
            $navItems = [
                ['url' => '/admin/dashboard', 'icon' => 'bi-speedometer2',      'label' => 'Dashboard'],
            ];
            
            if ($isAdmin) {
                $navItems = array_merge($navItems, [
                    ['url' => '/admin/users',     'icon' => 'bi-people',            'label' => 'Pengguna'],
                    ['url' => '/admin/groups',    'icon' => 'bi-collection',        'label' => 'Grup'],
                    ['url' => '/admin/suspend',   'icon' => 'bi-shield-lock',       'label' => 'Suspend & Blokir'],
                ]);
            }

            $navItems = array_merge($navItems, [
                ['url' => '/admin/modules',   'icon' => 'bi-folder2',           'label' => 'Modul'],
                ['url' => '/admin/subjects',  'icon' => 'bi-book',              'label' => 'Subjek'],
                ['url' => '/admin/questions', 'icon' => 'bi-question-circle',   'label' => 'Bank Soal'],
                ['url' => '/admin/tests',     'icon' => 'bi-clipboard-check',   'label' => 'Ujian'],
                ['url' => '/admin/results',   'icon' => 'bi-graph-up',          'label' => 'Hasil'],
            ]);
            
            foreach ($navItems as $item):
                $active = str_contains($currentUrl, $item['url']) ? 'active' : '';
            ?>
                <a href="<?= base_url($item['url']) ?>" class="nav-item <?= $active ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <?= $item['label'] ?>
                </a>
            <?php endforeach; ?>

            <?php if ($isAdmin): ?>
            <div class="nav-label mt-2">Sistem</div>
            <a href="<?= base_url('/admin/settings') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/settings') ? 'active' : '' ?>">
                <i class="bi bi-gear"></i>
                Pengaturan
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="avatar">
                    <?= strtoupper(substr(session()->get('firstname') ?? 'A', 0, 1)) ?>
                </div>
                <div class="info">
                    <div class="name"><?= esc(session()->get('firstname') ?? 'User') ?></div>
                    <div class="role"><?= esc(session()->get('role') ?? '') ?></div>
                </div>
                <a href="<?= base_url('logout') ?>" class="text-decoration-none" title="Logout">
                    <i class="bi bi-box-arrow-right" style="color:#94a3b8;font-size:1.1rem;"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- Topbar -->
    <header class="topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="btn-toggle-sidebar" id="toggleSidebar">
                <i class="bi bi-list"></i>
            </button>
            <span class="topbar-title"><?= $this->renderSection('page_title') ?></span>
        </div>
        <div class="topbar-actions">
            <div class="dropdown">
                <button class="btn btn-sm btn-light rounded-pill dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle me-1"></i>
                    <?= esc(session()->get('firstname') ?? 'User') ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= base_url('/admin/tests') ?>"><i class="bi bi-file-earmark-text me-2"></i> Manajemen Ujian</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('/admin/results') ?>"><i class="bi bi-bar-chart-fill me-2"></i> Laporan Ujian</a></li>
                    <li><a class="dropdown-item" href="<?= base_url('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Flash Messages -->
        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?= esc(session()->getFlashdata('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?= esc(session()->getFlashdata('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = document.getElementById('toggleSidebar');

        toggle?.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });

        overlay?.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });

        // Sidebar Submenu Pinning via localStorage
        const openMenus = JSON.parse(localStorage.getItem('pinnedMenus') || '[]');
        
        // Restore pinned menus on load (if they aren't already open due to active route)
        openMenus.forEach(id => {
            const el = document.getElementById(id);
            if (el && !el.classList.contains('show')) {
                const bsCollapse = new bootstrap.Collapse(el, {toggle: false});
                bsCollapse.show();
                const trigger = document.querySelector(`[href="#${id}"]`);
                if(trigger) trigger.setAttribute('aria-expanded', 'true');
            }
        });

        // Save state on toggle
        document.querySelectorAll('.submenu-collapse').forEach(el => {
            el.addEventListener('shown.bs.collapse', e => {
                let current = JSON.parse(localStorage.getItem('pinnedMenus') || '[]');
                if (!current.includes(e.target.id)) {
                    current.push(e.target.id);
                    localStorage.setItem('pinnedMenus', JSON.stringify(current));
                }
                const trigger = document.querySelector(`[href="#${e.target.id}"] .submenu-icon`);
                if(trigger) trigger.classList.replace('bi-chevron-down', 'bi-chevron-up');
            });
            el.addEventListener('hidden.bs.collapse', e => {
                let current = JSON.parse(localStorage.getItem('pinnedMenus') || '[]');
                current = current.filter(id => id !== e.target.id);
                localStorage.setItem('pinnedMenus', JSON.stringify(current));
                
                const trigger = document.querySelector(`[href="#${e.target.id}"] .submenu-icon`);
                if(trigger) trigger.classList.replace('bi-chevron-up', 'bi-chevron-down');
            });
        });
        
        // Ensure chevron icons match initial state
        document.querySelectorAll('.submenu-collapse').forEach(el => {
            if (el.classList.contains('show')) {
                const trigger = document.querySelector(`[href="#${el.id}"] .submenu-icon`);
                if(trigger) trigger.classList.replace('bi-chevron-down', 'bi-chevron-up');
            }
        });
        
        // Auto-dismiss alerts after 5s
        document.querySelectorAll('.alert-dismissible').forEach(el => {
            setTimeout(() => {
                const bs = bootstrap.Alert.getOrCreateInstance(el);
                bs.close();
            }, 5000);
        });
    </script>
    <!-- Keep-Alive & Online Sync -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            setInterval(function() {
                fetch('<?= base_url('/api/keep-alive') ?>', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                    }
                }).catch(e => console.error('Keep-alive failed:', e));
            }, 30000); // every 30 seconds
        });
    </script>
    
    <?= $this->renderSection('scripts') ?>
</body>
</html>

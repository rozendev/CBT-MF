<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
$secondaryColor = $settingModel->getValue('secondary_color', '#f4f6f9');
$appLogo = $settingModel->getValue('app_logo', '');
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
$appDescription = $settingModel->getValue('app_description', 'Aplikasi Ujian Berbasis Komputer');
$siteAuthor = $settingModel->getValue('site_author', 'Sistem Ujian Online');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection('page_title') ?> - <?= esc($appName) ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-bg: <?= $secondaryColor ?>;
            --bs-primary: <?= $primaryColor ?>;
            --bs-primary-rgb: <?= sscanf($primaryColor, "#%02x%02x%02x")[0] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[1] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[2] ?>;
        }
        .bg-primary {
            background-color: var(--bs-primary) !important;
        }
        .text-primary {
            color: var(--bs-primary) !important;
        }
        .btn-primary {
            background-color: var(--bs-primary);
            border-color: var(--bs-primary);
        }
        .btn-outline-primary {
            color: var(--bs-primary);
            border-color: var(--bs-primary);
        }
        .btn-outline-primary:hover {
            background-color: var(--bs-primary);
            color: #fff;
        }
        body {
            background-color: var(--primary-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .main-content {
            min-height: calc(100vh - 140px);
            padding: 2rem 0;
        }
        .footer {
            background-color: #fff;
            border-top: 1px solid #e9ecef;
            padding: 1.5rem 0;
            margin-top: auto;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?= $this->renderSection('styles') ?>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Top Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?= base_url('/student') ?>">
                <?php if ($appLogo): ?>
                    <img src="<?= base_url($appLogo) ?>" alt="Logo" style="height: 30px; margin-right: 10px;">
                <?php else: ?>
                    <i class="bi bi-mortarboard-fill me-2 fs-4"></i>
                <?php endif; ?>
                <?= esc($appName) ?>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarStudent">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarStudent">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item me-3">
                        <span class="nav-link text-white-50"><i class="bi bi-person-circle me-1"></i> <?= esc(session('firstname') . ' ' . session('lastname')) ?></span>
                    </li>
                    <li class="nav-item">
                        <a href="<?= base_url('/logout') ?>" class="btn btn-outline-light btn-sm rounded-pill px-3">
                            Logout <i class="bi bi-box-arrow-right ms-1"></i>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <?= $this->renderSection('content') ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer text-center mt-auto">
        <div class="container">
            <span class="text-muted small">
                &copy; <?= date('Y') ?> <strong><?= esc($siteAuthor) ?></strong>. All rights reserved.<br>
                <?= esc($appDescription) ?>
            </span>
        </div>
    </footer>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
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

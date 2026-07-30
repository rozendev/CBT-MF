<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
$secondaryColor = $settingModel->getValue('secondary_color', '#f8fafc');
$textColor = $settingModel->getValue('text_color', '#212529');
$fontFamily = $settingModel->getValue('font_family', 'Inter');
$borderRadius = $settingModel->getValue('border_radius', '8');
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->renderSection('page_title') ?> — <?= esc($appName) ?></title>
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/inter.css?v=1.1') ?>" rel="stylesheet">
    <style>
        :root {
            --color-background: <?= $secondaryColor ?>;
            --color-primary: <?= $primaryColor ?>;
            --color-primary-rgb: <?= sscanf($primaryColor, "#%02x%02x%02x")[0] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[1] ?>, <?= sscanf($primaryColor, "#%02x%02x%02x")[2] ?>;
            --color-primary-dark: color-mix(in srgb, var(--color-primary) 85%, black);
            --color-surface: #ffffff;
            --color-text: <?= $textColor ?>;
            --color-text-muted: #6c757d;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
            --custom-radius: <?= esc($borderRadius) ?>px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: '<?= esc($fontFamily) ?>', sans-serif;
            -webkit-font-smoothing: antialiased;
            background: var(--color-background);
            color: var(--color-text);
            min-height: 100vh;
        }
        .btn, .card, .modal-content, .form-control, .form-select, .alert, .badge {
            border-radius: var(--custom-radius);
        }
        .bg-primary { background-color: var(--color-primary) !important; }
        .text-primary { color: var(--color-primary) !important; }
        .btn-primary { background-color: var(--color-primary); border-color: var(--color-primary); color: #fff; }
        .btn-outline-primary { color: var(--color-primary); border-color: var(--color-primary); }
        .btn-outline-primary:hover { background-color: var(--color-primary); color: #fff; }
        .exam-navbar {
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1.5rem;
            display: none; /* Will be replaced by custom navbar in take.php */
        }
        .exam-content {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        .timer-bar {
            position: fixed;
            top: 57px;
            left: 0;
            right: 0;
            z-index: 1020;
            display: none;
        }
    </style>
    <?= $this->renderSection('styles') ?>
</head>
<body>
    <nav class="exam-navbar d-flex justify-content-between align-items-center">
        <a href="<?= base_url('/exam') ?>" class="brand">
            <span>🎓</span> Sistem Ujian
        </a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small">
                <i class="bi bi-person-circle me-1"></i>
                <?= esc(session()->get('firstname') ?? 'User') ?>
            </span>
            <a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </nav>

    <!-- Timer placeholder -->
    <div class="timer-bar" id="timerBar">
        <?= $this->renderSection('timer') ?>
    </div>

    <div class="exam-content">
        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success alert-dismissible fade show mt-3">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?= esc(session()->getFlashdata('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-3">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?= esc(session()->getFlashdata('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </div>
    
    <!-- Footer -->
    <footer class="text-center mt-auto py-3">
        <span class="text-muted small">
            &copy; <?= date('Y') ?> <strong>CBT-MF</strong>. All rights reserved. | Ver 1.30
        </span>
    </footer>

    <script src="<?= base_url('vendor/bootstrap/js/bootstrap.bundle.min.js?v=1.1') ?>"></script>
    <?= $this->renderSection('scripts') ?>
    <form id="logout-form" action="<?= base_url('logout') ?>" method="POST" style="display: none;">
        <?= csrf_field() ?>
    </form>
</body>
</html>

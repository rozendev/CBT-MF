<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
$secondaryColor = $settingModel->getValue('secondary_color', '#f8fafc');
$textColor = $settingModel->getValue('text_color', '#212529');
$fontFamily = $settingModel->getValue('font_family', 'Inter');
$borderRadius = $settingModel->getValue('border_radius', '8');
$appLogo = $settingModel->getValue('app_logo', '');
$appFavicon = $settingModel->getValue('app_favicon', '');
$faviconUrl = !empty($appFavicon) ? base_url($appFavicon) : (!empty($appLogo) ? base_url($appLogo) : base_url('favicon.ico'));
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->renderSection('page_title') ?> — <?= esc($appName) ?></title>
    <link rel="icon" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" href="<?= $faviconUrl ?>">
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/' . ($fontFamily === 'Inter' ? 'inter' : 'outfit') . '.css?v=1.1') ?>" rel="stylesheet">
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
            text-rendering: optimizeLegibility;
            background: var(--color-background);
            color: var(--color-text);
            min-height: 100dvh;
        }
        h1, h2, h3, h4, .display-1, .display-2, .display-3 {
            text-wrap: balance;
        }
        :focus-visible {
            outline: 3px solid rgba(var(--color-primary-rgb), 0.35);
            outline-offset: 2px;
            border-radius: 4px;
        }
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 2000;
            background: var(--color-primary);
            color: #fff;
            padding: 0.6rem 1.2rem;
            border-radius: 0 0 12px 0;
            font-weight: 600;
            text-decoration: none;
        }
        .skip-link:focus {
            left: 0;
        }
        .btn, .card, .modal-content, .form-control, .form-select, .alert, .badge {
            border-radius: var(--custom-radius);
        }
        .bg-primary { background-color: var(--color-primary) !important; }
        .text-primary { color: var(--color-primary) !important; }
        .btn-primary { background-color: var(--color-primary); border-color: var(--color-primary); color: #fff; }
        .btn-outline-primary { color: var(--color-primary); border-color: var(--color-primary); }
        .btn-outline-primary:hover { background-color: var(--color-primary); color: #fff; }
        .exam-content {
            width: 100%;
            margin: 0;
            padding: 0;
        }
        .exam-topbar {
            background: var(--color-surface);
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 1030;
        }
    </style>
    <?= $this->renderSection('styles') ?>
    <?php include __DIR__ . '/_frontend_config.php'; ?>
</head>
<body>
    <a class="skip-link" href="#main-content">Lewati ke konten</a>

    <header class="exam-topbar d-flex justify-content-between align-items-center px-4 py-3">
        <span class="fw-bold" style="color: var(--color-primary);"><?= esc($appName) ?></span>
        <a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="btn btn-sm btn-outline-primary rounded-pill">
            <i class="bi bi-box-arrow-right me-1"></i>Keluar
        </a>
    </header>

    <div class="exam-content" id="main-content" tabindex="-1">
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
            &copy; <?= date('Y') ?> <strong><?= esc($appName) ?></strong>. All rights reserved. | Ver <?= esc(\App\Libraries\FrontendConfig::value('app_version', '1.30')) ?>
        </span>
    </footer>

    <script src="<?= base_url('vendor/bootstrap/js/bootstrap.bundle.min.js?v=1.1') ?>"></script>
    <?= $this->renderSection('scripts') ?>
    <form id="logout-form" action="<?= base_url('logout') ?>" method="POST" style="display: none;">
        <?= csrf_field() ?>
    </form>
</body>
</html>

<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#4f46e5');
$navbarColor = $settingModel->getValue('navbar_color', 'rgba(0,0,0,0.3)');
$textColor = $settingModel->getValue('text_color', '#212529');
$fontFamily = $settingModel->getValue('font_family', 'Outfit');
$appLogo = $settingModel->getValue('app_logo', '');
$appFavicon = $settingModel->getValue('app_favicon', '');
$faviconUrl = !empty($appFavicon) ? base_url($appFavicon) : (!empty($appLogo) ? base_url($appLogo) : base_url('favicon.ico'));
$appName = $settingModel->getValue('app_name', 'Sistem Ujian');
$appDesc = $settingModel->getValue('app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)');
$siteAuthor = $settingModel->getValue('site_author', 'Sekolah/Lembaga');
$bgImage = $settingModel->getValue('login_background', '');
$primaryRgb = sscanf($primaryColor, "#%02x%02x%02x");
$primaryRgbStr = $primaryRgb[0] . ',' . $primaryRgb[1] . ',' . $primaryRgb[2];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Antrean Masuk — <?= esc($appName) ?></title>
    <link rel="icon" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" href="<?= $faviconUrl ?>">
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/' . ($fontFamily === 'Inter' ? 'inter' : 'outfit') . '.css?v=1.1') ?>" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: <?= esc($primaryColor) ?>;
            --text-color: <?= esc($textColor) ?>;
            --navbar-bg: <?= esc($navbarColor) ?>;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :focus-visible {
            outline: 3px solid rgba(<?= $primaryRgbStr ?>, 0.35);
            outline-offset: 2px;
            border-radius: 4px;
        }
        body {
            font-family: '<?= esc($fontFamily) ?>', sans-serif;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            color: var(--text-color);
            text-rendering: optimizeLegibility;
            <?php if ($bgImage): ?>
            background: url('<?= base_url($bgImage) ?>') center center / cover no-repeat fixed;
            <?php else: ?>
            background: #e2e8f0;
            background: radial-gradient(120% 80% at 50% 0%, #eef2f7 0%, #e2e8f0 100%);
            <?php endif; ?>
        }
        
        /* Navbar */
        .login-navbar {
            background-color: var(--navbar-bg);
            padding: 10px 20px;
            display: flex;
            align-items: center;
        }
        .login-navbar .brand {
            color: #ffffff;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            text-decoration: none;
        }
        .login-navbar .brand i { font-size: 1.4rem; margin-right: 10px; }
        .login-navbar .brand img { height: 20px; margin-right: 10px; }
        
        /* Container */
        .login-wrapper {
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .login-card {
            background: #ffffff;
            width: 100%;
            max-width: 440px;
            border-radius: 16px;
            padding: 3rem 2.5rem;
            box-shadow: 0 24px 48px -16px rgba(15, 23, 42, 0.18);
            text-align: center;
        }
        
        /* Logo & Titles */
        .login-logo {
            margin-bottom: 1.5rem;
        }
        .login-logo img {
            max-height: 80px;
            margin-bottom: 1rem;
        }
        .login-logo h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
            text-wrap: balance;
        }
        
        .pulse-ring {
            display: inline-block;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(<?= $primaryRgbStr ?>, 0.15);
            animation: pulse 2s infinite;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(<?= $primaryRgbStr ?>, 0.6); }
            70% { transform: scale(1); box-shadow: 0 0 0 15px rgba(<?= $primaryRgbStr ?>, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(<?= $primaryRgbStr ?>, 0); }
        }
        
        .waiting-message {
            font-size: 1.1rem;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 2rem;
            font-weight: 500;
        }
        
        .btn-refresh {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
            border-radius: 20px;
            padding: 0.5rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-refresh:hover {
            background-color: var(--primary);
            color: white;
        }
        
        /* Footer */
        .login-footer {
            background: rgba(255, 255, 255, 0.6);
            padding: 10px;
            text-align: center;
            font-size: 0.8rem;
            color: #334155;
            backdrop-filter: blur(5px);
        }
    </style>
    <?php include __DIR__ . '/../layouts/_frontend_config.php'; ?>
</head>
<body>

    <!-- Navbar Atas -->
    <div class="login-navbar">
        <a href="#" class="brand">
            <i class="bi bi-list"></i>
            <?php if($appLogo): ?>
                <img src="<?= base_url($appLogo) ?>" alt="Logo">
            <?php else: ?>
                <span class="fw-bold fs-5 me-2">CBT</span>
            <?php endif; ?>
            <?= esc($appName) ?>
        </a>
    </div>

    <!-- Kontainer Login -->
    <div class="login-wrapper">
        <div class="login-card">
            <div class="pulse-ring">
                <div class="spinner-border text-primary" role="status" style="width: 2rem; height: 2rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
            
            <div class="login-logo">
                <h3>Harap Tunggu</h3>
            </div>
            
            <div class="waiting-message">
                <?= esc($message) ?>
            </div>
            
            <p class="small text-muted mb-4"><i class="bi bi-info-circle me-1"></i>Halaman ini akan otomatis dialihkan ketika antrean Anda sudah masuk.</p>
            
            <a href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();" class="btn-refresh">Batalkan & Keluar</a>
        </div>
    </div>

    <!-- Footer -->
    <div class="login-footer">
        Sistem Ujian CBT - Copyright &copy; <?= date('Y') ?> - this site is authored by <?= esc($siteAuthor) ?>
    </div>

    <script>
        $(document).ready(function() {
            // Setup CSRF
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                }
            });

            // Polling interval (5 seconds)
            const APP_CFG = window.APP_CONFIG || {};
            setInterval(function() {
                $.post('<?= base_url('/queue/ping') ?>')
                .done(function(res) {
                    if (res.status === 'ready') {
                        // Slot acquired, redirect to dashboard or login check
                        window.location.href = '<?= base_url('/login') ?>'; // Which will now redirect to dashboard
                    } else if (res.status === 'error') {
                        window.location.href = '<?= base_url('/login') ?>';
                    }
                })
                .fail(function() {
                    console.log("Connection error, waiting for next ping...");
                });
            }, APP_CFG.queue_poll_ms || 5000);
        });
    </script>
    <form id="logout-form" action="<?= base_url('logout') ?>" method="POST" style="display: none;">
        <?= csrf_field() ?>
    </form>
</body>
</html>

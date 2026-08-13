<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#1E293B');
$navbarColor  = $settingModel->getValue('navbar_color', 'rgba(0,0,0,0.3)');
$textColor    = $settingModel->getValue('text_color', '#0F172A');
$appLogo      = $settingModel->getValue('app_logo', '');
$appFavicon   = $settingModel->getValue('app_favicon', '');
$faviconUrl   = !empty($appFavicon) ? base_url($appFavicon) : (!empty($appLogo) ? base_url($appLogo) : base_url('favicon.ico'));
$appName      = $settingModel->getValue('app_name', 'E-EXAM');
$appDesc      = $settingModel->getValue('app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)');
$siteAuthor   = $settingModel->getValue('site_author', 'Sekolah/Lembaga');
$fontFamily   = $settingModel->getValue('font_family', 'Outfit');
$primaryRgb   = sscanf($primaryColor, "#%02x%02x%02x");
$primaryRgbStr = $primaryRgb[0] . ',' . $primaryRgb[1] . ',' . $primaryRgb[2];
$bgImage      = $settingModel->getValue('login_background', '');

// Only load SweetAlert2 when there is a flash notification to show
$hasToast = session()->getFlashdata('error') || session()->getFlashdata('success');

// Dynamic active session query
$db = \Config\Database::connect();
$now = date('Y-m-d H:i:s');
$activeTest = $db->table('tests')
    ->where('is_enabled', 1)
    ->where('begin_time <=', $now)
    ->where('end_time >=', $now)
    ->where('deleted_at', null)
    ->orderBy('begin_time', 'ASC')
    ->get()
    ->getRow();

$isUpcoming = false;
if (!$activeTest) {
    $todayEnd = date('Y-m-d 23:59:59');
    $activeTest = $db->table('tests')
        ->where('is_enabled', 1)
        ->where('begin_time >', $now)
        ->where('begin_time <=', $todayEnd)
        ->where('deleted_at', null)
        ->orderBy('begin_time', 'ASC')
        ->get()
        ->getRow();
    
    if ($activeTest) {
        $isUpcoming = true;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Halaman login <?= esc($appName) ?> — <?= esc($appDesc) ?>">
    <title>Login — <?= esc($appName) ?></title>
    <link rel="icon" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" href="<?= $faviconUrl ?>">

    <link href="<?= base_url('assets/css/' . ($fontFamily === 'Inter' ? 'inter' : 'outfit') . '.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    
    <?php if ($hasToast): ?>
    <link href="<?= base_url('vendor/sweetalert2/sweetalert2.min.css') ?>" rel="stylesheet">
    <?php endif; ?>

    <style>
        :root {
            --bg-color: #F8FAFC; /* Slate 50 */
            --surface: #FFFFFF;
            --text-main: <?= esc($textColor) ?>; 
            --text-muted: #64748B; 
            --border: #E2E8F0; 
            --primary: <?= esc($primaryColor) ?>; 
            --focus-ring: <?= esc($primaryColor) ?>;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        h1, h2, h3 { text-wrap: balance; }
        :focus-visible {
            outline: 3px solid rgba(<?= $primaryRgbStr ?>, 0.35);
            outline-offset: 2px;
            border-radius: 4px;
        }

        body {
            font-family: '<?= esc($fontFamily) ?>', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            <?php if ($bgImage): ?>
            background-image: url('<?= base_url($bgImage) ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            <?php endif; ?>
            color: var(--text-main);
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        .container {
            width: 100%;
            max-width: 440px;
            background-color: <?= $bgImage ? 'rgba(255, 255, 255, 0.95)' : 'transparent' ?>;
            <?php if ($bgImage): ?>
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: 0 24px 48px -16px rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            <?php endif; ?>
        }

        /* Header Section */
        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            font-size: 30px;
            font-weight: 700;
            letter-spacing: -0.75px;
            margin-bottom: 12px;
            color: var(--text-main);
        }

        .logo img {
            max-height: 60px;
            width: auto;
            margin-bottom: 10px;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 15px;
            line-height: 1.5;
        }

        /* Form Section */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-main);
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            font-size: 15px;
            font-family: inherit;
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            transition: border-color 0.15s, box-shadow 0.15s;
            outline: none;
            color: var(--text-main);
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--focus-ring);
            box-shadow: 0 0 0 3px rgba(<?= $primaryRgbStr ?>, 0.2);
        }

        /* Show/Hide Password Toggle */
        .password-wrap {
            position: relative;
        }

        .password-wrap input[type="password"] {
            padding-right: 44px;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            border: none;
            background: none;
            padding: 6px 8px;
            cursor: pointer;
            color: var(--text-muted);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s;
        }

        .toggle-password:hover {
            color: var(--text-main);
        }

        .toggle-password:focus-visible {
            outline: 2px solid rgba(<?= $primaryRgbStr ?>, 0.35);
        }

        /* Button */
        .btn-submit {
            width: 100%;
            padding: 13px;
            background-color: var(--primary);
            color: #FFFFFF;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            margin-top: 6px;
            box-shadow: 0 8px 20px -8px rgba(<?= $primaryRgbStr ?>, 0.5);
        }

        .btn-submit:hover {
            filter: brightness(0.94);
            box-shadow: 0 10px 24px -8px rgba(<?= $primaryRgbStr ?>, 0.6);
            transform: translateY(-1px);
        }

        .btn-submit:active {
            transform: translateY(0) scale(0.98);
            filter: brightness(0.9);
        }

        /* Spinner & Loading State */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #FFFFFF;
            animation: spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
            margin-top: -2px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .btn-submit.loading {
            opacity: 0.8;
            cursor: not-allowed;
            pointer-events: none; /* Mencegah klik ganda saat animasi */
        }

        /* Footer / Help */
        .help-link {
            text-align: center;
            margin-top: 32px;
            font-size: 14px;
            color: var(--text-muted);
        }

        .help-link a {
            color: var(--text-main);
            font-weight: 500;
            text-decoration: none;
        }

        .help-link a:hover {
            text-decoration: underline;
        }

        .alert-error {
            background-color: #FEF2F2;
            color: #B91C1C;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #FCA5A5;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            body {
                padding: 16px;
                justify-content: flex-start;
            }
            .container {
                margin-top: 40px;
                <?php if ($bgImage): ?>
                padding: 1.5rem;
                <?php endif; ?>
            }
        }
    </style>
    <?php include __DIR__ . '/../layouts/_frontend_config.php'; ?>
</head>
<body>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <?php if ($appLogo): ?>
                    <img src="<?= base_url($appLogo) ?>" alt="Logo"><br>
                <?php endif; ?>
                <?= esc($appName) ?>
            </div>
            <p class="subtitle">Selamat datang di Ujian Digital.<br>Masukkan kredensial untuk memulai.</p>
        </div>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert-error">
                <?= esc(session()->getFlashdata('error')) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="<?= base_url('login') ?>" method="POST">
            <?= csrf_field() ?>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= old('username') ?>" placeholder="Masukkan username" autocomplete="username" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" placeholder="Masukkan password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" data-target="password" tabindex="-1" aria-label="Tampilkan password">
                        <i class="bi bi-eye"></i>
                        <i class="bi bi-eye-slash d-none"></i>
                    </button>
                </div>
            </div>

            <button type="submit" id="btnSubmit" class="btn-submit">Masuk</button>
        </form>

        <!-- Help Section -->
        <div class="help-link">
            Butuh bantuan? <a href="#" id="helpLink">Hubungi panitia</a>
            <div id="helpHint" style="display: none; margin-top: 8px; padding: 10px 14px; background: #F1F5F9; border-radius: 10px; font-size: 13px; color: var(--text-muted);">
                Silakan hubungi panitia atau pengawas ujian di ruangan.
            </div>
        </div>
    </div>

    <?php if ($hasToast): ?>
    <script src="<?= base_url('vendor/sweetalert2/sweetalert2.min.js?v=1.1') ?>" defer></script>
    <script>
    window.addEventListener('load', function() {
        if (typeof Swal === 'undefined') return;
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        <?php if (session()->getFlashdata('success')): ?>
        Toast.fire({ icon: 'success', title: '<?= addslashes(session()->getFlashdata('success')) ?>' });
        <?php endif; ?>
    });
    </script>
    <?php endif; ?>

    <script>
    document.getElementById('helpLink').addEventListener('click', function(e) {
        e.preventDefault();
        var hint = document.getElementById('helpHint');
        hint.style.display = hint.style.display === 'none' ? 'block' : 'none';
    });

    document.querySelectorAll('.toggle-password').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var input = document.getElementById(btn.dataset.target);
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            var eye = btn.querySelector('.bi-eye');
            var eyeSlash = btn.querySelector('.bi-eye-slash');
            if (eye) eye.classList.toggle('d-none', show);
            if (eyeSlash) eyeSlash.classList.toggle('d-none', !show);
            btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
        });
    });

    document.querySelector('form').addEventListener('submit', function(e) {
        var btn = document.getElementById('btnSubmit');
        if (btn.classList.contains('loading')) {
            e.preventDefault(); // Batalkan submit jika sudah loading
            return;
        }
        
        // Tambahkan class loading dan ubah konten menjadi spinner
        btn.classList.add('loading');
        setTimeout(function() {
            btn.innerHTML = '<span class="spinner"></span> Memproses...';
            btn.disabled = true;
        }, 10);
    });
    </script>

</body>
</html>

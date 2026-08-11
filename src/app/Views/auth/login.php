<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#1E293B');
$navbarColor  = $settingModel->getValue('navbar_color', 'rgba(0,0,0,0.3)');
$textColor    = $settingModel->getValue('text_color', '#0F172A');
$appLogo      = $settingModel->getValue('app_logo', '');
$appName      = $settingModel->getValue('app_name', 'E-EXAM');
$appDesc      = $settingModel->getValue('app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)');
$siteAuthor   = $settingModel->getValue('site_author', 'Sekolah/Lembaga');
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
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

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-color);
            <?php if ($bgImage): ?>
            background-image: url('<?= base_url($bgImage) ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            <?php endif; ?>
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            width: 100%;
            max-width: 380px;
            background-color: <?= $bgImage ? 'rgba(255, 255, 255, 0.95)' : 'transparent' ?>;
            <?php if ($bgImage): ?>
            padding: 2.5rem;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(8px);
            <?php endif; ?>
        }

        /* Header Section */
        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
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
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
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
            border-radius: 8px;
            cursor: pointer;
            transition: opacity 0.15s, transform 0.1s;
            margin-top: 6px;
        }

        .btn-submit:hover {
            opacity: 0.9;
        }

        .btn-submit:active {
            transform: scale(0.98);
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
                <input type="password" id="password" name="password" placeholder="Masukkan password" autocomplete="current-password" required>
            </div>

            <button type="submit" id="btnSubmit" class="btn-submit">MASUK</button>
        </form>

        <!-- Help Section -->
        <div class="help-link">
            Butuh bantuan? <a href="#" onclick="alert('Hubungi panitia atau pengawas di ruangan.')">Hubungi panitia</a>
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

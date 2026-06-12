<?php
$settingModel = new \App\Models\SettingModel();
$primaryColor = $settingModel->getValue('primary_color', '#4f46e5');
$navbarColor  = $settingModel->getValue('navbar_color', 'rgba(0,0,0,0.3)');
$textColor    = $settingModel->getValue('text_color', '#212529');
$appLogo      = $settingModel->getValue('app_logo', '');
$appName      = $settingModel->getValue('app_name', 'Sistem Ujian');
$appDesc      = $settingModel->getValue('app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)');
$siteAuthor   = $settingModel->getValue('site_author', 'Sekolah/Lembaga');
$bgImage      = $settingModel->getValue('login_background', '');

// Only load SweetAlert2 when there is a flash notification to show
$hasToast = session()->getFlashdata('error') || session()->getFlashdata('success');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="Halaman login <?= esc($appName) ?> — <?= esc($appDesc) ?>">
    <title>Login — <?= esc($appName) ?></title>

    <!-- Preconnect: hint browser to open connections early -->
    <link rel="stylesheet" href="<?= base_url('assets/css/inter.css') ?>">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Preload critical images to avoid LCP penalty -->
    <?php if ($appLogo): ?>
    <link rel="preload" as="image" href="<?= base_url($appLogo) ?>">
    <?php endif; ?>
    <?php if ($bgImage): ?>
    <link rel="preload" as="image" href="<?= base_url($bgImage) ?>">
    <?php endif; ?>

    <!-- Google Fonts with display=swap to prevent FOIT (Flash of Invisible Text) -->


    <!-- Bootstrap CSS (render-critical, stays synchronous) -->
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css') ?>" rel="stylesheet">

    <style>
        :root {
            --primary: <?= esc($primaryColor) ?>;
            --text-color: <?= esc($textColor) ?>;
            --navbar-bg: <?= esc($navbarColor) ?>;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text-color);
            <?php if ($bgImage): ?>
            background: url('<?= base_url($bgImage) ?>') center center / cover no-repeat fixed;
            <?php else: ?>
            background: #e2e8f0;
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
            max-width: 420px;
            border-radius: 8px;
            padding: 2.5rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            text-align: center;
        }

        /* Logo & Titles */
        .login-logo { margin-bottom: 1.5rem; }
        .login-logo img {
            max-height: 80px;
            width: auto;
            height: auto;
            margin-bottom: 1rem;
        }
        .login-logo h2 {
            font-size: 1.1rem;
            font-weight: 400;
            color: var(--text-color);
            margin-bottom: 0.2rem;
        }
        .login-logo h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 1.5rem;
        }

        /* Alerts */
        .alert-info-custom {
            background-color: #e0e7ff;
            color: #3730a3;
            border-radius: 6px;
            padding: 0.6rem;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }
        .alert-help {
            background-color: #e0e7ff;
            color: #3730a3;
            border-radius: 6px;
            padding: 0.6rem;
            font-size: 0.9rem;
            margin-top: 1.5rem;
        }

        /* Inputs */
        .input-group-custom {
            position: relative;
            margin-bottom: 1.2rem;
        }
        .input-group-custom input {
            width: 100%;
            padding: 0.8rem 0;
            border: none;
            border-bottom: 1px solid #cbd5e1;
            background: transparent;
            font-size: 1rem;
            color: var(--text-color);
            outline: none;
            transition: border-color 0.3s;
        }
        .input-group-custom input:focus {
            border-bottom-color: var(--primary);
        }
        .input-group-custom .toggle-password {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
        }

        /* Button */
        .btn-login {
            background-color: var(--primary);
            color: white;
            width: 50%;
            border: none;
            border-radius: 20px;
            padding: 0.6rem;
            font-weight: 600;
            margin-top: 1rem;
            transition: background 0.3s;
        }
        .btn-login:hover { opacity: 0.9; }

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
</head>
<body>

    <!-- Navbar Atas -->
    <div class="login-navbar">
        <a href="#" class="brand">
            <i class="bi bi-list"></i>
            <?php if($appLogo): ?>
                <img src="<?= base_url($appLogo) ?>" alt="Logo <?= esc($appName) ?>" width="20" height="20">
            <?php else: ?>
                <span class="fw-bold fs-5 me-2">CBT</span>
            <?php endif; ?>
            <?= esc($appName) ?>
        </a>
    </div>

    <!-- Kontainer Login -->
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-logo">
                <?php if($appLogo): ?>
                    <img src="<?= base_url($appLogo) ?>" alt="Logo <?= esc($appName) ?>" width="80" height="80" style="object-fit: contain;">
                <?php endif; ?>
                <h2><?= esc($appDesc) ?></h2>
                <h3><?= esc($siteAuthor) ?></h3>
            </div>

            <div class="alert-info-custom">
                Gunakan akun Anda untuk login
            </div>

            <form action="<?= base_url('login') ?>" method="POST" id="loginForm">
                <?= csrf_field() ?>

                <div class="input-group-custom">
                    <input type="text" name="username" placeholder="Username" value="<?= old('username') ?>" required autocomplete="username" autofocus>
                </div>

                <div class="input-group-custom">
                    <input type="password" name="password" id="passwordField" placeholder="Password" required autocomplete="current-password">
                    <i class="bi bi-eye toggle-password" id="togglePassword"></i>
                </div>

                <button type="submit" class="btn-login" id="btnLogin">
                    <span class="btn-text">LOGIN</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                    </span>
                </button>
            </form>

            <div class="alert-help">
                Hubungi panitia ujian jika terjadi kendala
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="login-footer">
        Sistem Ujian CBT - Copyright &copy; <?= date('Y') ?> - this site is authored by <?= esc($siteAuthor) ?>
    </div>

    <!-- SweetAlert2 dimuat HANYA jika ada notifikasi yang perlu ditampilkan -->
    <?php if ($hasToast): ?>
    <script src="<?= base_url('vendor/sweetalert2/sweetalert2.min.js') ?>" defer></script>
    <?php endif; ?>

    <script>
        // Toggle password visibility
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#passwordField');

        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('bi-eye');
            this.classList.toggle('bi-eye-slash');
        });

        // Loading state on submit
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnLogin');
            btn.disabled = true;
            btn.querySelector('.btn-text').classList.add('d-none');
            btn.querySelector('.btn-loading').classList.remove('d-none');
        });

        <?php if ($hasToast): ?>
        // Notifications — run after SweetAlert2 (defer) has loaded
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

            <?php if (session()->getFlashdata('error')): ?>
            Toast.fire({ icon: 'error', title: '<?= addslashes(session()->getFlashdata('error')) ?>' });
            <?php endif; ?>

            <?php if (session()->getFlashdata('success')): ?>
            Toast.fire({ icon: 'success', title: '<?= addslashes(session()->getFlashdata('success')) ?>' });
            <?php endif; ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>

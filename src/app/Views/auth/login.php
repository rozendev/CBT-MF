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

    <!-- Preconnect: hint browser to open connections early -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>

    <!-- Preload critical images to avoid LCP penalty -->
    <?php if ($appLogo): ?>
    <link rel="preload" as="image" href="<?= base_url($appLogo) ?>">
    <?php endif; ?>
    <?php if ($bgImage): ?>
    <link rel="preload" as="image" href="<?= base_url($bgImage) ?>">
    <?php endif; ?>

    <!-- Bootstrap CSS (Bootstrap-icons only, using custom styles for layout) -->
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: <?= esc($primaryColor) ?>;
            --blue-deep:  #0D2B6B;
            --blue-mid:   <?= esc($primaryColor) ?>;
            --blue-light: #3B82F6;
            --yellow:     #F5C800;
            --red:        #E83A2F;
            --white:      #FFFFFF;
            --gray-50:    #F8FAFF;
            --gray-200:   #E2E8F0;
            --gray-400:   #94A3B8;
            --gray-600:   #475569;
            --gray-800:   #1E293B;
            --success:    #16A34A;
            --error:      #DC2626;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            overflow-x: hidden;
            position: relative;
            background: var(--blue-deep);
            color: var(--gray-800);
            <?php if ($bgImage): ?>
            background: url('<?= base_url($bgImage) ?>') center center / cover no-repeat fixed;
            <?php endif; ?>
        }

        /* ── Decorative background ── */
        .bg-shapes {
            position: fixed; inset: 0; pointer-events: none; z-index: 0;
            overflow: hidden;
        }
        .bg-shapes svg { width: 100%; height: 100%; }

        /* ── Left info panel ── */
        .side-panel {
            width: 42%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 3rem 4rem;
            position: relative;
            z-index: 1;
            background: rgba(13, 43, 107, 0.9);
            backdrop-filter: blur(10px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }
        .side-panel .school-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 3rem;
            text-align: left;
        }
        .logo-hex {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--blue-mid) 0%, #A78BFA 50%, #F472B6 100%);
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .logo-hex svg { width: 24px; height: 24px; fill: white; }
        .school-name { color: var(--white); }
        .school-name span { display: block; font-size: 0.72rem; font-weight: 500; opacity: .7; letter-spacing: .08em; text-transform: uppercase; }
        .school-name strong { display: block; font-size: 1.15rem; font-weight: 700; }

        .side-info h1 {
            font-size: clamp(1.6rem, 2.5vw, 2.4rem);
            font-weight: 700;
            color: var(--white);
            line-height: 1.25;
            margin-bottom: 1rem;
            text-align: left;
        }
        .side-info h1 em {
            font-style: normal;
            color: var(--yellow);
        }
        .side-info p {
            color: rgba(255,255,255,.65);
            font-size: .95rem;
            line-height: 1.7;
            max-width: 340px;
            margin-bottom: 2rem;
            text-align: left;
        }

        .info-cards { display: flex; flex-direction: column; gap: 12px; }
        .info-card {
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            text-align: left;
        }
        .info-card-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 1.1rem;
        }
        .info-card-icon.yellow { background: rgba(245,200,0,.18); }
        .info-card-icon.blue   { background: rgba(59,130,246,.2); }
        .info-card-icon.red    { background: rgba(232,58,47,.18); }
        .info-card-text strong { display: block; color: var(--white); font-size: .85rem; font-weight: 600; margin-bottom: 2px; }
        .info-card-text span   { color: rgba(255,255,255,.55); font-size: .78rem; }

        .side-footer {
            margin-top: 3rem;
            color: rgba(255,255,255,.35);
            font-size: .75rem;
            text-align: left;
        }
        .side-footer a { color: rgba(255,255,255,.5); text-decoration: none; }
        .side-footer a:hover { color: var(--yellow); }

        /* ── Login card ── */
        .card-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        .login-card {
            background: var(--white);
            border-radius: 20px;
            padding: 2.5rem 2.25rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 32px 80px rgba(0,0,0,.35);
        }

        .card-header {
            text-align: center;
            margin-bottom: 1.75rem;
        }
        .card-header .app-icon {
            width: 56px; height: 56px;
            background: linear-gradient(135deg, var(--blue-mid), #A78BFA);
            clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
        }
        .card-header .app-icon svg { width: 26px; height: 26px; fill: white; }
        .card-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 4px;
        }
        .card-header p {
            font-size: .82rem;
            color: var(--gray-400);
        }

        /* session badge */
        .session-badge {
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        .session-badge.session-upcoming {
            background: #FEF3C7;
            border: 1px solid #FDE68A;
        }
        .session-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 0 3px rgba(22,163,74,.2);
            flex-shrink: 0;
            animation: pulse 1.8s ease-in-out infinite;
        }
        .session-upcoming .session-dot {
            background: #D97706;
            box-shadow: 0 0 0 3px rgba(217,119,6,.2);
        }
        @keyframes pulse {
            0%,100% { box-shadow: 0 0 0 3px rgba(22,163,74,.2); }
            50%      { box-shadow: 0 0 0 6px rgba(22,163,74,.08); }
        }
        .session-text { flex: 1; text-align: left; }
        .session-text strong { display: block; font-size: .8rem; font-weight: 600; color: #1D4ED8; }
        .session-upcoming .session-text strong { color: #B45309; }
        .session-text span   { font-size: .74rem; color: var(--gray-600); }
        .session-time { font-size: .74rem; font-weight: 600; color: var(--gray-600); }

        /* form */
        .form-group { margin-bottom: 1.1rem; text-align: left; }
        .form-group label {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 6px;
        }
        .form-group .hint {
            font-size: .72rem;
            color: var(--gray-400);
            font-weight: 400;
            margin-left: 4px;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .input-wrap i.prefix {
            position: absolute; left: 13px;
            font-size: 1rem;
            color: var(--gray-400);
            pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 11px 40px 11px 38px;
            border: 1.5px solid var(--gray-200);
            border-radius: 10px;
            font-family: inherit;
            font-size: .88rem;
            color: var(--gray-800);
            background: var(--gray-50);
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .input-wrap input::placeholder { color: var(--gray-400); }
        .input-wrap input:focus {
            border-color: var(--blue-light);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }

        .toggle-pw {
            position: absolute; right: 12px;
            background: none; border: none; cursor: pointer;
            color: var(--gray-400); padding: 0; line-height: 0;
            transition: color .2s;
        }
        .toggle-pw:hover { color: var(--gray-600); }
        .toggle-pw i { font-size: 1.1rem; }

        /* login alerts */
        .login-alert {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: .78rem;
            color: #B91C1C;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            text-align: left;
        }

        .forgot-row {
            display: flex;
            justify-content: flex-end;
            margin-top: -4px;
            margin-bottom: 1.25rem;
        }
        .forgot-link {
            font-size: .78rem;
            color: var(--blue-mid);
            text-decoration: none;
            font-weight: 500;
            transition: color .2s;
        }
        .forgot-link:hover { color: var(--blue-deep); text-decoration: underline; }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--blue-mid) 0%, var(--blue-light) 100%);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 700;
            letter-spacing: .04em;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-login:hover { opacity: .92; }
        .btn-login:active { transform: scale(.98); }
        .btn-login:disabled { opacity: .6; cursor: not-allowed; }
        .btn-login .spinner-border {
            width: 1.2rem;
            height: 1.2rem;
        }

        .divider {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 1rem;
        }
        .divider hr { flex: 1; border: none; border-top: 1px solid var(--gray-200); }
        .divider span { font-size: .72rem; color: var(--gray-400); white-space: nowrap; }

        .btn-contact {
            width: 100%;
            padding: 11px;
            background: transparent;
            border: 1.5px solid var(--gray-200);
            border-radius: 10px;
            font-family: inherit;
            font-size: .83rem;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background .2s, border-color .2s;
            text-decoration: none;
        }
        .btn-contact:hover { background: var(--gray-50); border-color: var(--gray-400); }
        .btn-contact i { font-size: 1rem; }

        .card-footer {
            margin-top: 1.25rem;
            text-align: center;
            font-size: .72rem;
            color: var(--gray-400);
        }
        .card-footer a { color: var(--blue-mid); text-decoration: none; }
        .card-footer a:hover { text-decoration: underline; }

        /* ── Responsive ── */
        @media (max-width: 820px) {
            .side-panel { display: none; }
            .card-wrapper { background: var(--blue-deep); }
        }
        @media (max-width: 480px) {
            .login-card { padding: 2rem 1.5rem; border-radius: 16px; }
        }
    </style>
</head>
<body>

<!-- Background shapes -->
<div class="bg-shapes">
    <svg viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
        <path d="M0 200 Q300 80 600 250 T1200 200" stroke="#F5C800" stroke-width="3" fill="none" opacity=".18"/>
        <path d="M200 0 Q500 150 400 400" stroke="#E83A2F" stroke-width="2.5" fill="none" opacity=".15"/>
        <circle cx="1320" cy="220" r="55" fill="white" opacity=".08"/>
        <circle cx="80" cy="420" r="70" stroke="white" stroke-width="2" fill="none" opacity=".1"/>
        <circle cx="240" cy="560" r="90" fill="#F5C800" opacity=".08"/>
        <rect x="1240" y="580" width="120" height="18" rx="9" fill="#E83A2F" opacity=".2" transform="rotate(-20 1240 580)"/>
        <rect x="400" y="760" width="100" height="16" rx="8" fill="white" opacity=".1" transform="rotate(15 400 760)"/>
        <circle cx="1380" cy="520" r="7" fill="#3B82F6" opacity=".2"/>
        <circle cx="290" cy="470" r="5" fill="white" opacity=".2"/>
        <!-- dots grid -->
        <g fill="white" opacity=".08">
            <circle cx="30" cy="70" r="2.5"/><circle cx="60" cy="70" r="2.5"/><circle cx="90" cy="70" r="2.5"/>
            <circle cx="30" cy="100" r="2.5"/><circle cx="60" cy="100" r="2.5"/><circle cx="90" cy="100" r="2.5"/>
            <circle cx="30" cy="130" r="2.5"/><circle cx="60" cy="130" r="2.5"/><circle cx="90" cy="130" r="2.5"/>
            <circle cx="30" cy="160" r="2.5"/><circle cx="60" cy="160" r="2.5"/><circle cx="90" cy="160" r="2.5"/>
            <circle cx="30" cy="190" r="2.5"/><circle cx="60" cy="190" r="2.5"/>
        </g>
        <path d="M600 -10 Q620 100 580 200" stroke="#60A5FA" stroke-width="1.5" fill="none" opacity=".15"/>
    </svg>
</div>

<!-- Left info panel -->
<aside class="side-panel">
    <div class="school-logo">
        <?php if ($appLogo): ?>
            <img src="<?= base_url($appLogo) ?>" alt="Logo <?= esc($appName) ?>" style="max-height: 48px; width: auto; object-fit: contain;">
        <?php else: ?>
            <div class="logo-hex">
                <svg viewBox="0 0 24 24"><path d="M12 2l9 5v10l-9 5-9-5V7z"/></svg>
            </div>
        <?php endif; ?>
        <div class="school-name">
            <span>Aplikasi Ujian Berbasis Komputer</span>
            <strong>CBT · <?= esc($appName) ?></strong>
        </div>
    </div>

    <div class="side-info">
        <h1>Selamat datang di<br/><em>Ujian Digital</em><br/><?= esc($appName) ?></h1>
        <p>Masukkan kredensial akun Anda untuk mengikuti ujian. Pastikan koneksi internet stabil sebelum memulai.</p>

        <div class="info-cards">
            <div class="info-card">
                <div class="info-card-icon yellow">📋</div>
                <div class="info-card-text">
                    <strong>Username</strong>
                    <span>Gunakan Username / Nomor Peserta Anda yang valid</span>
                </div>
            </div>
            <div class="info-card">
                <div class="info-card-icon blue">🔑</div>
                <div class="info-card-text">
                    <strong>Password</strong>
                    <span>Password default diberikan oleh Panitia. Ganti demi keamanan.</span>
                </div>
            </div>
            <div class="info-card">
                <div class="info-card-icon red">🛟</div>
                <div class="info-card-text">
                    <strong>Butuh bantuan?</strong>
                    <span>Hubungi panitia di ruang pengawas jika mengalami kendala login</span>
                </div>
            </div>
        </div>
    </div>

    <footer class="side-footer">
        Sistem Ujian CBT &copy; <?= date('Y') ?> · <?= esc($siteAuthor) ?> &nbsp;|&nbsp;
        <a href="#" id="forgotLink2">Bantuan</a>
    </footer>
</aside>

<!-- Login card -->
<div class="card-wrapper">
    <div class="login-card">

        <div class="card-header">
            <?php if ($appLogo): ?>
                <img src="<?= base_url($appLogo) ?>" alt="Logo <?= esc($appName) ?>" style="max-height: 64px; width: auto; object-fit: contain; margin-bottom: 14px;">
            <?php else: ?>
                <div class="app-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2l9 5v10l-9 5-9-5V7z"/></svg>
                </div>
            <?php endif; ?>
            <h2>Masuk ke Akun Anda</h2>
            <p><?= esc($appName) ?> — <?= esc($appDesc) ?></p>
        </div>

        <!-- Active session badge -->
        <?php if ($activeTest): ?>
            <div class="session-badge <?= $isUpcoming ? 'session-upcoming' : '' ?>">
                <div class="session-dot"></div>
                <div class="session-text">
                    <strong><?= $isUpcoming ? 'Ujian Mendatang' : 'Sesi Ujian Aktif' ?></strong>
                    <span><?= esc($activeTest->name) ?></span>
                </div>
                <div class="session-time"><?= date('H:i', strtotime($activeTest->begin_time)) ?></div>
            </div>
        <?php endif; ?>

        <!-- Inline Error alert -->
        <?php if (session()->getFlashdata('error')): ?>
            <div class="login-alert" id="loginAlert">
                <i class="bi bi-exclamation-triangle-fill me-2" style="font-size: 1.1rem; flex-shrink: 0;"></i>
                <span id="alertMsg"><?= esc(session()->getFlashdata('error')) ?></span>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form action="<?= base_url('login') ?>" method="POST" id="loginForm">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrap">
                    <i class="bi bi-person prefix"></i>
                    <input type="text" name="username" id="username" placeholder="Masukkan username" value="<?= old('username') ?>" required autocomplete="username" autofocus>
                </div>
            </div>

            <div class="form-group">
                <label for="passwordField">Password</label>
                <div class="input-wrap">
                    <i class="bi bi-lock prefix"></i>
                    <input type="password" name="password" id="passwordField" placeholder="Masukkan password" required autocomplete="current-password">
                    <button class="toggle-pw" type="button" id="togglePassword" aria-label="Tampilkan password">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <div class="forgot-row">
                <a href="#" class="forgot-link" id="forgotLink">Lupa password?</a>
            </div>

            <button type="submit" class="btn-login" id="btnLogin">
                <span id="btnText">MASUK</span>
                <span class="spinner-border spinner-border-sm" id="spinner" role="status" style="display: none;"></span>
            </button>
        </form>

        <div class="divider">
            <hr/><span>atau</span><hr/>
        </div>

        <a href="https://wa.me/6281234567890" class="btn-contact" target="_blank" id="btnContactWa">
            <i class="bi bi-whatsapp"></i>
            Hubungi Panitia via WhatsApp
        </a>

        <div class="card-footer">
            Sistem Ujian CBT &copy; <?= date('Y') ?> · <?= esc($siteAuthor) ?>
        </div>
    </div>
</div>

<!-- Forgot password modal -->
<div id="forgotModal" style="display:none;position:fixed;inset:0;z-index:100;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;padding:2rem;max-width:360px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3); text-align: left;">
        <h3 style="font-size:1.05rem;font-weight:700;color:#1E293B;margin-bottom:.5rem;">Lupa Password?</h3>
        <p style="font-size:.83rem;color:#64748B;line-height:1.6;margin-bottom:1rem;">
            Jika Anda lupa password, silakan hubungi panitia ujian di ruang pengawas atau lewat WhatsApp.<br/><br/>
            📍 <strong>Ruang Pengawas</strong> – CBT Control Room<br/>
            📱 <strong>WhatsApp Panitia</strong> – Hubungi Administrator<br/><br/>
            Password default adalah <strong>tanggal lahir (DDMMYYYY)</strong>.
        </p>
        <button id="closeForgotBtn" style="width:100%;padding:10px;background:#1B4FD8;color:white;border:none;border-radius:9px;font-family:inherit;font-weight:600;cursor:pointer;font-size:.88rem;">Mengerti</button>
    </div>
</div>

<!-- SweetAlert2 loaded ONLY if toast notification is present -->
<?php if ($hasToast): ?>
<script src="<?= base_url('vendor/sweetalert2/sweetalert2.min.js?v=1.1') ?>" defer></script>
<?php endif; ?>

<script>
    // Toggle password visibility
    const togglePassword = document.querySelector('#togglePassword');
    const password = document.querySelector('#passwordField');
    const eyeIcon = document.querySelector('#eyeIcon');

    togglePassword.addEventListener('click', function () {
        const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
        password.setAttribute('type', type);
        eyeIcon.classList.toggle('bi-eye');
        eyeIcon.classList.toggle('bi-eye-slash');
    });

    // Loading state on submit
    document.getElementById('loginForm').addEventListener('submit', function() {
        const btn = document.getElementById('btnLogin');
        btn.disabled = true;
        document.getElementById('btnText').style.display = 'none';
        document.getElementById('spinner').style.display = 'inline-block';
    });

    // Forgot password modal
    const modal = document.getElementById('forgotModal');
    const forgotLinks = [document.getElementById('forgotLink'), document.getElementById('forgotLink2')];
    const closeForgotBtn = document.getElementById('closeForgotBtn');

    forgotLinks.forEach(link => {
        if (link) {
            link.addEventListener('click', e => {
                e.preventDefault();
                modal.style.display = 'flex';
            });
        }
    });

    if (closeForgotBtn) {
        closeForgotBtn.addEventListener('click', () => {
            modal.style.display = 'none';
        });
    }

    if (modal) {
        modal.addEventListener('click', e => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    }

    // Dynamic clock inside the session badge (if there is an active session)
    const timeEl = document.querySelector('.session-time');
    if (timeEl && !<?= $isUpcoming ? 'true' : 'false' ?>) {
        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2,'0');
            const m = String(now.getMinutes()).padStart(2,'0');
            const s = String(now.getSeconds()).padStart(2,'0');
            timeEl.textContent = `${h}:${m}:${s}`;
        }
        updateClock();
        setInterval(updateClock, 1000);
    }

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

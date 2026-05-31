<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Login — Sistem Ujian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --dark: #0f172a;
            --dark-card: rgba(30, 41, 59, 0.7);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
            overflow: hidden;
            position: relative;
        }
        /* Animated background orbs */
        .bg-orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.3;
            animation: float 20s infinite ease-in-out;
            z-index: 0;
        }
        .bg-orb:nth-child(1) {
            width: 400px; height: 400px;
            background: #4f46e5;
            top: -100px; left: -100px;
            animation-delay: 0s;
        }
        .bg-orb:nth-child(2) {
            width: 300px; height: 300px;
            background: #7c3aed;
            bottom: -50px; right: -50px;
            animation-delay: -5s;
        }
        .bg-orb:nth-child(3) {
            width: 200px; height: 200px;
            background: #06b6d4;
            top: 50%; left: 60%;
            animation-delay: -10s;
        }
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(30px, -50px) scale(1.05); }
            50% { transform: translate(-20px, 30px) scale(0.95); }
            75% { transform: translate(40px, 20px) scale(1.02); }
        }
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 420px;
            padding: 1.5rem;
        }
        .login-card {
            background: var(--dark-card);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
            animation: slideUp 0.6s ease-out;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-logo .icon {
            font-size: 3rem;
            display: inline-block;
            margin-bottom: 0.5rem;
            animation: bounce 2s infinite;
        }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        .login-logo h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0;
            letter-spacing: -0.5px;
        }
        .login-logo p {
            color: #94a3b8;
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }
        .form-floating {
            margin-bottom: 1rem;
        }
        .form-floating .form-control {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-radius: 12px;
            color: #f1f5f9;
            font-size: 0.95rem;
            padding: 1rem 1rem 1rem 2.8rem;
            height: 56px;
            transition: all 0.3s ease;
        }
        .form-floating .form-control:focus {
            background: rgba(15, 23, 42, 0.8);
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
            color: #f1f5f9;
        }
        .form-floating label {
            color: #94a3b8;
            padding-left: 2.8rem;
        }
        .form-floating .form-control:focus ~ label,
        .form-floating .form-control:not(:placeholder-shown) ~ label {
            color: #94a3b8;
        }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 1.1rem;
            z-index: 5;
            pointer-events: none;
            transition: color 0.3s;
        }
        .form-floating:focus-within .input-icon {
            color: var(--primary-light);
        }
        .btn-login {
            width: 100%;
            padding: 0.85rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4);
        }
        .btn-login:active {
            transform: translateY(0);
        }
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .btn-login .spinner-border {
            width: 1.2rem;
            height: 1.2rem;
            border-width: 2px;
        }
        .alert {
            border-radius: 12px;
            border: none;
            font-size: 0.85rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }
        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
        }
        .alert-success {
            background: rgba(34, 197, 94, 0.15);
            color: #86efac;
        }
        .footer-text {
            text-align: center;
            margin-top: 1.5rem;
            color: #475569;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <!-- Animated background orbs -->
    <div class="bg-orb"></div>
    <div class="bg-orb"></div>
    <div class="bg-orb"></div>

    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <div class="icon">🎓</div>
                <h1>Sistem Ujian</h1>
                <p>Masuk untuk melanjutkan</p>
            </div>

            <?php if (session()->getFlashdata('error')): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <?= esc(session()->getFlashdata('error')) ?>
                </div>
            <?php endif; ?>

            <?php if (session()->getFlashdata('success')): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    <?= esc(session()->getFlashdata('success')) ?>
                </div>
            <?php endif; ?>

            <form action="<?= base_url('login') ?>" method="POST" id="loginForm">
                <?= csrf_field() ?>

                <div class="form-floating position-relative">
                    <i class="bi bi-person input-icon"></i>
                    <input type="text" class="form-control" id="username" name="username"
                           placeholder="Username" value="<?= old('username') ?>"
                           autocomplete="username" required autofocus>
                    <label for="username">Username</label>
                </div>

                <div class="form-floating position-relative">
                    <i class="bi bi-lock input-icon"></i>
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="Password" autocomplete="current-password" required>
                    <label for="password">Password</label>
                </div>

                <button type="submit" class="btn btn-login mt-2" id="btnLogin">
                    <span class="btn-text">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
                    </span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-1" role="status"></span> Memproses...
                    </span>
                </button>
            </form>
        </div>

        <p class="footer-text">
            &copy; <?= date('Y') ?> Sistem Ujian &bull; Secure Login
        </p>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnLogin');
            btn.disabled = true;
            btn.querySelector('.btn-text').classList.add('d-none');
            btn.querySelector('.btn-loading').classList.remove('d-none');
        });
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Dalam Pemeliharaan — Sistem Ujian</title>
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/outfit.css?v=1.1') ?>" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #e2e8f0;
            overflow: hidden;
        }
        .maintenance-container {
            text-align: center;
            max-width: 520px;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }
        .maintenance-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 2rem;
            background: rgba(253, 126, 20, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: float 3s ease-in-out infinite;
        }
        .maintenance-icon i {
            font-size: 3rem;
            color: #fd7e14;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: #ffffff;
        }
        .maintenance-message {
            font-size: 1.05rem;
            line-height: 1.7;
            color: #94a3b8;
            margin-bottom: 2rem;
        }
        .maintenance-footer {
            font-size: 0.85rem;
            color: #64748b;
        }
        .pulse-ring {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 200px;
            height: 200px;
            margin: -100px 0 0 -100px;
            border: 2px solid rgba(253, 126, 20, 0.1);
            border-radius: 50%;
            animation: pulseRing 3s ease-out infinite;
        }
        .pulse-ring:nth-child(2) { animation-delay: 1s; }
        .pulse-ring:nth-child(3) { animation-delay: 2s; }
        @keyframes pulseRing {
            0% { transform: scale(0.5); opacity: 1; }
            100% { transform: scale(2); opacity: 0; }
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.5rem;
            background: rgba(255,255,255,0.08);
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 10px;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-back:hover {
            background: rgba(255,255,255,0.15);
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="pulse-ring"></div>
    <div class="pulse-ring"></div>
    <div class="pulse-ring"></div>

    <div class="maintenance-container">
        <div class="maintenance-icon">
            <i class="bi bi-cone-striped"></i>
        </div>
        <h1>Sistem Dalam Pemeliharaan</h1>
        <p class="maintenance-message">
            <?= esc($message ?? 'Sistem sedang dalam pemeliharaan. Silakan coba lagi beberapa saat lagi.') ?>
        </p>
        <a href="<?= base_url('/login') ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Kembali ke Login
        </a>
        <p class="maintenance-footer mt-4">
            &copy; <?= date('Y') ?> Sistem Ujian CBT
        </p>
    </div>
</body>
</html>

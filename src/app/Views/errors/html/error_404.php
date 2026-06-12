<?php
try {
    $settingModel = new \App\Models\SettingModel();
    $primaryColor = $settingModel->getValue('primary_color', '#0d6efd');
    $appName = $settingModel->getValue('app_name', 'Sistem Ujian');
    $appLogo = $settingModel->getValue('app_logo', '');
} catch (\Throwable $e) {
    $primaryColor = '#0d6efd';
    $appName = 'Sistem Ujian';
    $appLogo = '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Halaman Tidak Ditemukan - <?= esc($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --color-primary: <?= $primaryColor ?>;
            --color-primary-dark: color-mix(in srgb, var(--color-primary) 85%, black);
        }
        body {
            background-color: #f8f9fa;
            font-family: 'Inter', sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            background: #ffffff;
            padding: 50px 30px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.05);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        .error-code {
            font-size: 100px;
            font-weight: 900;
            color: var(--color-primary);
            line-height: 1;
            margin-bottom: 20px;
            text-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .error-title {
            font-size: 24px;
            font-weight: 700;
            color: #212529;
            margin-bottom: 15px;
        }
        .error-message {
            color: #6c757d;
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .btn-home {
            background-color: var(--color-primary);
            color: white;
            padding: 12px 30px;
            border-radius: 9999px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }
        .btn-home:hover {
            background-color: var(--color-primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            color: white;
        }
        .app-brand {
            margin-bottom: 30px;
        }
        .app-brand img {
            max-height: 50px;
        }
        .error-icon {
            font-size: 80px;
            color: var(--color-primary);
            opacity: 0.8;
            margin-bottom: -15px;
        }
    </style>
</head>
<body>

    <div class="error-container">
        <div class="app-brand">
            <?php if (!empty($appLogo)): ?>
                <img src="<?= base_url(esc($appLogo)) ?>" alt="Logo">
            <?php else: ?>
                <h4 class="fw-bold mb-0" style="color: var(--color-primary);"><?= esc($appName) ?></h4>
            <?php endif; ?>
        </div>

        <div class="error-icon">
            <i class="bi bi-compass"></i>
        </div>
        <div class="error-code">404</div>
        <div class="error-title">Halaman Tidak Ditemukan</div>
        <div class="error-message">
            <?php if (ENVIRONMENT !== 'production' && !empty($message)) : ?>
                <?= nl2br(esc($message)) ?>
            <?php else : ?>
                Maaf, halaman yang Anda cari tidak dapat ditemukan. Mungkin URL salah, atau halaman tersebut telah dihapus.
            <?php endif; ?>
        </div>

        <a href="javascript:history.back()" class="btn-home">
            <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
    </div>

</body>
</html>

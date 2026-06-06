<?php
session_start();
$envPath = __DIR__ . '/../../.env';
$isInstalled = false;

// Define base URL for installer
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseInstallerUrl = $protocol . '://' . $host . '/install/';

// Read .env into array helper
function getEnvVars($path) {
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $vars = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $vars[trim($key)] = trim($val);
        }
    }
    return $vars;
}

$envVars = getEnvVars($envPath);
$installerLocked = isset($envVars['INSTALLER_LOCKED']) && $envVars['INSTALLER_LOCKED'] === 'true';
if (isset($envVars['database.default.hostname']) && isset($envVars['database.default.username'])) {
    $isInstalled = true;
}

// 404 if locked
if ($isInstalled && $installerLocked) {
    header("HTTP/1.0 404 Not Found");
    echo "<h1>404 Not Found</h1>";
    echo "The page that you have requested could not be found.";
    exit;
}

// If installed but not locked, require admin login to continue
if ($isInstalled && !isset($_SESSION['installer_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['installer_login'])) {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        try {
            $pdo = new PDO(
                "mysql:host={$envVars['database.default.hostname']};port={$envVars['database.default.port']};dbname={$envVars['database.default.database']}",
                $envVars['database.default.username'],
                $envVars['database.default.password']
            );
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'admin'");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin && password_verify($password, $admin['password'])) {
                $_SESSION['installer_logged_in'] = true;
                header("Location: index.php?step=1");
                exit;
            } else {
                $loginError = "Username atau password Admin salah.";
            }
        } catch (PDOException $e) {
            $loginError = "Gagal terhubung ke database. Cek file .env Anda.";
        }
    }
    
    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installer Login - Sistem Ujian CBT</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
        <div class="card shadow-sm" style="width: 100%; max-width: 400px;">
            <div class="card-body p-4">
                <h4 class="text-center fw-bold mb-4">Autentikasi Installer</h4>
                <div class="alert alert-info small">Sistem sudah terinstal. Untuk melakukan konfigurasi ulang, Anda harus login sebagai Admin.</div>
                <?php if (isset($loginError)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="installer_login" value="1">
                    <div class="mb-3">
                        <label class="form-label">Username Admin</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Login ke Installer</button>
                    <a href="/" class="btn btn-link w-100 text-decoration-none mt-2">Kembali ke Aplikasi</a>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Router
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require __DIR__ . '/actions.php';
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Installer - Sistem Ujian CBT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 2rem; position: relative; }
        .step-indicator::before { content: ''; position: absolute; top: 15px; left: 0; right: 0; height: 2px; background: #e9ecef; z-index: 1; }
        .step { position: relative; z-index: 2; background: white; padding: 0 10px; text-align: center; color: #6c757d; font-weight: 600; }
        .step .circle { width: 32px; height: 32px; border-radius: 50%; background: #e9ecef; color: #6c757d; display: flex; align-items: center; justify-content: center; margin: 0 auto 5px; }
        .step.active .circle { background: #0d6efd; color: white; }
        .step.active { color: #0d6efd; }
        .step.completed .circle { background: #198754; color: white; }
    </style>
</head>
<body class="bg-light pb-5">
    <div class="container mt-5" style="max-width: 800px;">
        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
                <h3 class="fw-bold text-primary"><i class="bi bi-rocket-takeoff"></i> Instalasi CBT Sistem Ujian</h3>
                <p class="text-muted">Setup wizard untuk konfigurasi database dan sistem</p>
            </div>
            <div class="card-body p-4 p-md-5">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
                        <div class="circle"><i class="bi <?= $step > 1 ? 'bi-check' : 'bi-1-circle' ?>"></i></div>
                        <small>Cek Sistem</small>
                    </div>
                    <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
                        <div class="circle"><i class="bi <?= $step > 2 ? 'bi-check' : 'bi-2-circle' ?>"></i></div>
                        <small>Database & Redis</small>
                    </div>
                    <div class="step <?= $step >= 3 ? ($step > 3 ? 'completed' : 'active') : '' ?>">
                        <div class="circle"><i class="bi <?= $step > 3 ? 'bi-check' : 'bi-3-circle' ?>"></i></div>
                        <small>Cloudflare</small>
                    </div>
                    <div class="step <?= $step >= 4 ? ($step > 4 ? 'completed' : 'active') : '' ?>">
                        <div class="circle"><i class="bi <?= $step > 4 ? 'bi-check' : 'bi-4-circle' ?>"></i></div>
                        <small>Selesai</small>
                    </div>
                </div>

                <!-- Content -->
                <?php if ($step === 1): ?>
                    <?php require __DIR__ . '/steps/step1.php'; ?>
                <?php elseif ($step === 2): ?>
                    <?php require __DIR__ . '/steps/step2.php'; ?>
                <?php elseif ($step === 3): ?>
                    <?php require __DIR__ . '/steps/step3.php'; ?>
                <?php elseif ($step === 4): ?>
                    <?php require __DIR__ . '/steps/step4.php'; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <?= isset($extraScripts) ? $extraScripts : '' ?>
</body>
</html>

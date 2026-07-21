<?php
session_start();
header('Content-Type: application/json');

$envPath = __DIR__ . '/../../.env';
if (!function_exists('getEnvVars')) {
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
}

$envVars = getEnvVars($envPath);
$installerLocked = isset($envVars['INSTALLER_LOCKED']) && $envVars['INSTALLER_LOCKED'] === 'true';
$isInstalled = false;
if (isset($envVars['database.default.hostname']) && isset($envVars['database.default.username'])) {
    $isInstalled = true;
}

// 1. Block access completely if installer is locked
if ($isInstalled && $installerLocked) {
    header("HTTP/1.0 404 Not Found");
    echo json_encode(['status' => 'error', 'message' => 'Installer is locked.']);
    exit;
}

// 2. Block access if database is configured but admin session is not active
if ($isInstalled && !isset($_SESSION['installer_logged_in'])) {
    header("HTTP/1.0 403 Forbidden");
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak: Diperlukan login admin.']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'test_db') {
    $host = $_POST['db_host'] ?? 'localhost';
    $port = $_POST['db_port'] ?? '3306';
    $user = $_POST['db_user'] ?? 'root';
    $pass = $_POST['db_pass'] ?? '';
    $name = $_POST['db_name'] ?? 'sistem_ujian';
    
    $redis_host = $_POST['redis_host'] ?? '127.0.0.1';
    $redis_port = $_POST['redis_port'] ?? '6379';
    $redis_password = $_POST['redis_password'] ?? '';

    try {
        $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Try creating DB if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name`");
        $pdo->exec("USE `$name`");
        
        $redisOk = false;
        if (extension_loaded('redis')) {
            try {
                $redis = new Redis();
                if ($redis->connect($redis_host, (int)$redis_port, 2)) {
                    if (!empty($redis_password)) {
                        if (!$redis->auth($redis_password)) {
                            throw new Exception('Redis authentication failed');
                        }
                    }
                    if ($redis->ping()) {
                        $redisOk = true;
                    }
                }
            } catch (Exception $e) {
                $redisOk = false;
            }
        }

        if ($redisOk) {
            echo json_encode(['status' => 'success', 'message' => 'Koneksi MySQL dan Redis berhasil!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Koneksi MySQL berhasil, tapi koneksi Redis gagal! Pastikan Redis berjalan dan password benar.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Koneksi MySQL gagal: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_server') {
    $_SESSION['server_setup'] = $_POST;
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'save_db') {
    $_SESSION['db_setup'] = $_POST;
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'save_cf') {
    $_SESSION['cf_setup'] = $_POST;
    header("Location: index.php?step=5");
    exit;
}

if ($action === 'install') {
    $dbData = $_SESSION['db_setup'] ?? [];
    $cfData = $_SESSION['cf_setup'] ?? [];
    
    $adminUser = $_POST['admin_user'] ?? '';
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminFirst = $_POST['admin_firstname'] ?? '';
    $adminLast = $_POST['admin_lastname'] ?? '';
    $adminEmail = $_POST['admin_email'] ?? '';

    if (empty($dbData) || empty($adminUser) || empty($adminPass)) {
        echo json_encode(['status' => 'error', 'message' => 'Data konfigurasi tidak lengkap.']);
        exit;
    }

    $envPath = __DIR__ . '/../../.env';
    $envExamplePath = __DIR__ . '/../../env'; // CI4 default is env
    
    // Read base env content
    $envContent = file_exists($envExamplePath) ? file_get_contents($envExamplePath) : '';
    if (file_exists($envPath)) {
        $envContent = file_get_contents($envPath);
    }
    
    $serverData = $_SESSION['server_setup'] ?? [];
    $appUrl = $serverData['app_url'] ?? $baseInstallerUrl . '../';
    
    $redisPassword = $dbData['redis_password'] ?? '';
    $escapedRedisPassword = addslashes($redisPassword);
    $redisPasswordLine = !empty($redisPassword) ? "cache.redis.password = '{$escapedRedisPassword}'" : "# cache.redis.password = ''";
    
    // We will generate a fresh .env, initially setting it to development to bypass the interactive prompt for migrations
    $newEnv = <<<ENV
CI_ENVIRONMENT = development
app.baseURL = '{$appUrl}'
app.indexPage = ''
app.forceGlobalSecureRequests = false

database.default.hostname = '{$dbData['db_host']}'
database.default.database = '{$dbData['db_name']}'
database.default.username = '{$dbData['db_user']}'
database.default.password = '{$dbData['db_pass']}'
database.default.DBDriver = MySQLi
database.default.DBPrefix =
database.default.port = {$dbData['db_port']}

session.driver = 'CodeIgniter\Session\Handlers\RedisHandler'
session.cookieName = 'ci_session'
session.savePath = 'tcp://{$dbData['redis_host']}:{$dbData['redis_port']}'
session.matchIP = false

redis.host = '{$dbData['redis_host']}'
redis.port = {$dbData['redis_port']}

cache.handler = 'redis'
cache.redis.host = '{$dbData['redis_host']}'
cache.redis.port = {$dbData['redis_port']}
{$redisPasswordLine}

INSTALLER_LOCKED = true
ENV;

    if (!empty($redisPassword)) {
        $newEnv .= "\nREDIS_PASSWORD = '{$escapedRedisPassword}'\n";
    }

    if (!empty($cfData['cf_real_ip'])) {
        $newEnv .= "\nCLOUDFLARE_REAL_IP = true\n";
    }

    if (file_put_contents($envPath, $newEnv) === false) {
        echo json_encode(['status' => 'error', 'message' => 'Gagal menulis file .env. Pastikan folder root dapat ditulis (writable).']);
        exit;
    }

    // Now boot CI4 CLI to run migrations
    $fcpath = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR;
    chdir($fcpath);
    
    $output = [];
    $returnVar = 0;
    
    try {
        // Prepare DB connection directly using PDO to insert admin and run migrations via CLI if possible
        $pdo = new PDO(
            "mysql:host={$dbData['db_host']};port={$dbData['db_port']};dbname={$dbData['db_name']}",
            $dbData['db_user'],
            $dbData['db_pass']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Try running spark using PHP via shell_exec
        $phpBin = PHP_BINARY;
        if (empty($phpBin) || strpos(php_sapi_name(), 'apache') !== false || strpos(php_sapi_name(), 'fpm') !== false || strpos(php_sapi_name(), 'cgi') !== false) {
            $phpBin = 'php'; // Fallback to PATH php
        }
        
        $migrateCmd = escapeshellcmd($phpBin) . " spark migrate 2>&1";
        exec($migrateCmd, $output, $returnVar);
        
        // Check if `users` table exists. If shell_exec failed, the table won't exist.
        $tableExists = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
        
        if (!$tableExists) {
            echo json_encode(['status' => 'error', 'message' => "Gagal menjalankan migrasi (exec failed). Output: " . implode("\n", $output)]);
            exit;
        }

        // Switch back to production
        $finalEnv = str_replace("CI_ENVIRONMENT = development", "CI_ENVIRONMENT = production", file_get_contents($envPath));
        file_put_contents($envPath, $finalEnv);

        // Insert or update admin user
        $hashPass = password_hash($adminPass, PASSWORD_BCRYPT);
        
        // Check if admin already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$adminUser]);
        
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("UPDATE users SET password = ?, firstname = ?, lastname = ?, email = ?, role = 'admin' WHERE username = ?");
            $stmt->execute([$hashPass, $adminFirst, $adminLast, $adminEmail, $adminUser]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, firstname, lastname, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 'admin', 1, NOW())");
            $stmt->execute([$adminUser, $hashPass, $adminFirst, $adminLast, $adminEmail]);
        }
        
        // Ensure default settings exist
        $pdo->exec("INSERT IGNORE INTO settings (`key`, `value`, `type`, `description`) VALUES 
            ('app_name', 'Sistem Ujian', 'string', 'Nama Aplikasi'),
            ('app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)', 'text', 'Deskripsi Aplikasi'),
            ('primary_color', '#4f46e5', 'string', 'Warna Utama'),
            ('max_concurrent_connections', '1000', 'integer', 'Maksimal Slot Ujian'),
            ('queue_waiting_message', 'Server sedang penuh. Anda berada dalam antrean. Mohon tunggu tanpa menutup halaman ini.', 'string', 'Pesan Menunggu Slot Kosong dalam Ujian')
        ");
        
        $_SESSION['installer_logged_in'] = true; // Set them as logged into installer just in case
        
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan saat finalisasi: ' . $e->getMessage()]);
    }
    
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);

<?php

use CodeIgniter\Boot;
use Config\Paths;

/*
 *---------------------------------------------------------------
 * CHECK INSTALLER
 *---------------------------------------------------------------
 */
$envPath = __DIR__ . '/../.env';
$needsInstall = true;
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (strpos($envContent, 'INSTALLER_LOCKED=true') !== false || strpos($envContent, 'INSTALLER_LOCKED = true') !== false) {
        $needsInstall = false;
    }
}

// Allow health check endpoint even when installer is unlocked
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$healthPath = parse_url($requestUri, PHP_URL_PATH);
$isHealthCheck = ($healthPath === '/health');

if ($needsInstall && !$isHealthCheck) {
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Installation Required</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f8f9fa;color:#333}h1{color:#dc3545}.card{background:#fff;padding:30px;border-radius:8px;display:inline-block;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:500px}code{background:#eef;padding:4px 8px;border-radius:4px}</style></head><body><div class="card"><h1>Installation Required</h1><p>Aplikasi belum diinstall. Silakan jalankan installer via terminal:</p><p><code>./install.sh</code> atau <code>bash scripts/cbt.sh install</code></p></div></body></html>';
    exit(1);
}

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */

$minPhpVersion = '8.2'; // If you update this, don't forget to update `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 *---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// LOAD OUR PATHS CONFIG FILE
// This is the line that might need to be changed, depending on your folder structure.
require FCPATH . '../app/Config/Paths.php';
// ^^^ Change this line if you move your application folder

$paths = new Paths();

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));

<?php

declare(strict_types=1);

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */

if (version_compare(PHP_VERSION, '8.3', '<')) {
    exit('Your PHP version must be 8.3 or higher to run CBT Improved.');
}

/*
 *---------------------------------------------------------------
 * LOAD THE ENVIRONMENT
 *---------------------------------------------------------------
 */

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = new Dotenv\Dotenv(__DIR__ . '/..');
    $dotenv->load();
}

/*
 *---------------------------------------------------------------
 * DEFINE ENVIRONMENT CONSTANTS
 *---------------------------------------------------------------
 */

defined('ENVIRONMENT') || define('ENVIRONMENT', getenv('APP_ENV') ?: 'development');

switch (ENVIRONMENT) {
    case 'production':
        defined('SHOW_ERRORS') || define('SHOW_ERRORS', false);
        defined('DEBUG_LEVEL') || define('DEBUG_LEVEL', 0);
        break;

    case 'testing':
        defined('SHOW_ERRORS') || define('SHOW_ERRORS', true);
        defined('DEBUG_LEVEL') || define('DEBUG_LEVEL', 1);
        break;

    default: // development
        defined('SHOW_ERRORS') || define('SHOW_ERRORS', true);
        defined('DEBUG_LEVEL') || define('DEBUG_LEVEL', 4);
        break;
}

/*
 *---------------------------------------------------------------
 * SET ERROR REPORTING
 *---------------------------------------------------------------
 */

if (SHOW_ERRORS) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

/*
 *---------------------------------------------------------------
 * DEFINE APP PATHS
 *---------------------------------------------------------------
 */

defined('ROOTPATH') || define('ROOTPATH', realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR);
defined('APPPATH') || define('APPPATH', ROOTPATH . 'app' . DIRECTORY_SEPARATOR);
defined('SYSTEMPATH') || define('SYSTEMPATH', ROOTPATH . 'vendor' . DIRECTORY_SEPARATOR . 'codeigniter4' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR);
defined('WRITEPATH') || define('WRITEPATH', ROOTPATH . 'writable' . DIRECTORY_SEPARATOR);
defined('TESTPATH') || define('TESTPATH', ROOTPATH . 'tests' . DIRECTORY_SEPARATOR);
defined('PUBLICPATH') || define('PUBLICPATH', ROOTPATH . 'public' . DIRECTORY_SEPARATOR);

/*
 *---------------------------------------------------------------
 * TIMEZONE
 *---------------------------------------------------------------
 */

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

/*
 *---------------------------------------------------------------
 * ENCRYPTION KEY
 *---------------------------------------------------------------
 */

if (! getenv('APP_KEY')) {
    throw new \RuntimeException('You must set APP_KEY environment variable.');
}

/*
 *---------------------------------------------------------------
 * AUTOLOADER
 *---------------------------------------------------------------
 */

if (! file_exists(SYSTEMPATH . 'Autoloader/Autoloader.php')) {
    exit('Cannot find the CodeIgniter framework. Run composer install first.');
}

require_once SYSTEMPATH . 'Autoloader/Autoloader.php';
require_once SYSTEMPATH . 'Config/BaseService.php';
require_once SYSTEMPATH . 'Config/Services.php';

use CodeIgniter\Config\Services;

// Initialize and start the autoloader
$loader = Services::autoloader();
$loader->initialize(new Config\Autoload(), new Config\Modules());
$loader->register();

/*
 *---------------------------------------------------------------
 * LOAD COMMON FUNCTIONS
 *---------------------------------------------------------------
 */

if (file_exists(APPPATH . 'Common.php')) {
    require_once APPPATH . 'Common.php';
}

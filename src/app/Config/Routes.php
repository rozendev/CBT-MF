<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ── Health Check (public) ─────────────────────────
$routes->get('health', 'HealthController::index');

// ── Auth Routes (public) ────────────────────────────
$routes->get('login', 'Auth\AuthController::login');
$routes->post('login', 'Auth\AuthController::attemptLogin');
$routes->post('logout', 'Auth\AuthController::logout');
$routes->get('queue', 'Auth\QueueController::index');
$routes->post('queue/ping', 'Auth\QueueController::ping');
$routes->get('maintenance', 'Auth\AuthController::maintenance');

$routes->group('api', ['filter' => 'auth'], static function ($routes) {
    // @todo remove: legacy endpoint, controller no longer exists
    // $routes->get('test-scan', 'Api\TestCheatController::test');
    $routes->post('keep-alive', 'Api\SyncController::keepAlive');
    
    // Static Exam API
    $routes->post('exam/init', 'Api\ExamApiController::init');
    $routes->post('exam/autosave', 'Api\ExamApiController::autosave');
    $routes->post('exam/auto-sync', 'Api\ExamApiController::autoSync');
    $routes->post('exam/finish', 'Api\ExamApiController::finish');
    $routes->post('exam/check-score', 'Api\ExamApiController::checkScore');
    $routes->post('exam/report-cheat', 'Api\ExamApiController::reportCheat');
    $routes->get('exam/stream/(:num)', 'Api\ExamApiController::stream/$1');
    $routes->get('student/exams', 'Api\StudentApiController::exams');
    $routes->get('student/results', 'Api\StudentApiController::results');
    $routes->get('student/review', 'Api\StudentApiController::review');
});

// ── Kiosk & Intruder Routes (public, token/rate guarded) ──────────
$routes->group('api', static function ($routes) {
    $routes->get('kiosk/config', 'Api\KioskController::config');
    $routes->post('kiosk/verify-exit', 'Api\KioskController::verifyExit');
    $routes->post('kiosk/can-exit', 'Api\KioskController::canExit');
    $routes->post('intruder/report', 'Api\IntruderReportController::report');
});

// ── CORS Preflight (catch-all OPTIONS) ──────────────
// Preflight CORS: CorsApiFilter menangani header; rute ini memastikan
// OPTIONS ke path mana pun (login, api/*, dll) tidak 404 sebelum filter jalan.
$routes->options('(:any)', static function () {
    return \Config\Services::response()->setStatusCode(200);
});

// ── Admin Routes (role-protected) ───────────────────
$routes->group('admin', ['filter' => 'role:admin,guru'], static function ($routes) {
    $routes->get('/', 'Admin\DashboardController::index');
    $routes->get('dashboard', 'Admin\DashboardController::index');

    // ── Admin-Only Routes ───────────────────────────────
    $routes->group('', ['filter' => 'role:admin'], static function ($routes) {
        // User Management
        $routes->get('users', 'Admin\UserController::index');
        $routes->get('users/create', 'Admin\UserController::create');
        $routes->post('users/store', 'Admin\UserController::store');
        $routes->get('users/edit/(:num)', 'Admin\UserController::edit/$1');
        $routes->post('users/update/(:num)', 'Admin\UserController::update/$1');
        $routes->delete('users/delete/(:num)', 'Admin\UserController::delete/$1');
        $routes->post('users/bulk-delete', 'Admin\UserController::bulkDelete');
        $routes->post('users/unlock/(:num)', 'Admin\UserController::unlock/$1');
        $routes->get('users/template', 'Admin\UserController::template');
        $routes->post('users/import', 'Admin\UserController::import');
        $routes->post('users/import-batch', 'Admin\UserController::importBatch');
        
        $routes->get('users/print-cards', 'Admin\UserController::printCardsIndex');
        $routes->post('users/print-cards/process', 'Admin\UserController::printCardsProcess');
        
        // Suspend & Lock
        $routes->get('suspend', 'Admin\SuspendController::index');
        $routes->post('suspend/bulk-action', 'Admin\SuspendController::bulkAction');
        $routes->post('suspend/release/(:num)', 'Admin\SuspendController::release/$1');
        $routes->post('suspend/ban/(:num)', 'Admin\SuspendController::ban/$1');
        $routes->post('suspend/reset/(:num)', 'Admin\SuspendController::reset/$1');
        $routes->post('suspend/reset-login/(:num)', 'Admin\SuspendController::resetLogin/$1');
        $routes->get('suspend/user-attempts/(:num)', 'Admin\SuspendController::getUserAttempts/$1');
        $routes->post('suspend/reset-attempt/(:num)', 'Admin\SuspendController::resetAttempt/$1');

        // Groups
        $routes->get('groups', 'Admin\GroupController::index');
        $routes->get('groups/create', 'Admin\GroupController::create');
        $routes->post('groups/store', 'Admin\GroupController::store');
        $routes->get('groups/edit/(:num)', 'Admin\GroupController::edit/$1');
        $routes->post('groups/update/(:num)', 'Admin\GroupController::update/$1');
        $routes->delete('groups/delete/(:num)', 'Admin\GroupController::delete/$1');

        // Settings
        $routes->get('settings', 'Admin\SettingController::index');
        $routes->post('settings/update', 'Admin\SettingController::update');
        $routes->get('settings/system-info', 'Admin\SettingController::getSystemInfo');
        $routes->post('settings/clear-cache', 'Admin\SettingController::clearCache');
        $routes->post('settings/reset', 'Admin\SettingController::resetSettings');

        // Kiosk App Management (Independent Page)
        $routes->get('kiosk', 'Admin\KioskSettingsController::index');
        $routes->post('kiosk/update', 'Admin\KioskSettingsController::update');

        // Kiosk Live Monitoring
        $routes->get('kiosk/live', 'Admin\KioskLiveController::index');
        $routes->get('kiosk/live-data', 'Admin\KioskLiveController::data');

        // Analytics
        $routes->get('analytics', 'Admin\AnalyticsController::index');
        $routes->get('analytics/data', 'Admin\AnalyticsController::getData');

        // Logging / Aktivitas
        $routes->get('logging', 'Admin\LoggingController::index');
        $routes->post('logging/export', 'Admin\LoggingController::export');
        $routes->get('logging/intruders', 'Admin\LoggingController::intruders');
    });

    // Modules
    $routes->get('modules', 'Admin\ModuleController::index');
    $routes->get('modules/create', 'Admin\ModuleController::create');
    $routes->post('modules/store', 'Admin\ModuleController::store');
    $routes->get('modules/edit/(:num)', 'Admin\ModuleController::edit/$1');
    $routes->post('modules/update/(:num)', 'Admin\ModuleController::update/$1');
    $routes->delete('modules/delete/(:num)', 'Admin\ModuleController::delete/$1');

    // Subjects
    $routes->get('subjects', 'Admin\SubjectController::index');
    $routes->get('subjects/create', 'Admin\SubjectController::create');
    $routes->post('subjects/store', 'Admin\SubjectController::store');
    $routes->get('subjects/edit/(:num)', 'Admin\SubjectController::edit/$1');
    $routes->post('subjects/update/(:num)', 'Admin\SubjectController::update/$1');
    $routes->delete('subjects/delete/(:num)', 'Admin\SubjectController::delete/$1');

    // Questions (Bank Soal)
    $routes->get('questions', 'Admin\QuestionController::index');
    $routes->get('questions/topics', 'Admin\QuestionController::topicsBySubject');
    $routes->get('questions/create', 'Admin\QuestionController::create');
    $routes->post('questions/store', 'Admin\QuestionController::store');
    $routes->get('questions/edit/(:num)', 'Admin\QuestionController::edit/$1');
    $routes->post('questions/update/(:num)', 'Admin\QuestionController::update/$1');
    $routes->delete('questions/delete/(:num)', 'Admin\QuestionController::delete/$1');
    $routes->get('questions/word-import', 'Admin\WordImportController::index');
    $routes->get('questions/word-import/template', 'Admin\WordImportController::downloadTemplate');
    $routes->post('questions/word-import/process', 'Admin\WordImportController::process');
    $routes->get('questions/preview/(:num)', 'Admin\QuestionController::preview/$1');
    $routes->post('questions/bulk-delete', 'Admin\QuestionController::bulkDelete');
    $routes->post('questions/upload-image', 'Admin\QuestionController::uploadImage');

    $routes->get('tests', 'Admin\TestController::index');
    $routes->get('tests/create', 'Admin\TestController::create');
    $routes->post('tests/store', 'Admin\TestController::store');
    $routes->get('tests/edit/(:num)', 'Admin\TestController::edit/$1');
    $routes->post('tests/update/(:num)', 'Admin\TestController::update/$1');
    $routes->post('tests/extend-time/(:num)', 'Admin\TestController::extendTime/$1');
    $routes->delete('tests/delete/(:num)', 'Admin\TestController::delete/$1');
    
    // Test Configurations (Peserta & Set Soal)
    $routes->get('tests/config/(:num)', 'Admin\TestController::config/$1');
    $routes->post('tests/config/(:num)/groups', 'Admin\TestController::updateGroups/$1');
    $routes->post('tests/config/(:num)/subjects', 'Admin\TestController::addSubjectSet/$1');
    $routes->delete('tests/config/subjects/(:num)', 'Admin\TestController::deleteSubjectSet/$1');

    // Static Exam Generation
    $routes->post('tests/static/generate/(:num)', 'Admin\StaticExamController::generate/$1');
    $routes->post('tests/static/delete/(:num)', 'Admin\StaticExamController::delete/$1');

    // Export Reports
    $routes->get('reports', 'Admin\ReportController::index');
    $routes->post('reports/export', 'Admin\ReportController::export');

    // Results / Laporan Ujian
    $routes->get('results', 'Admin\ResultController::index');
    $routes->get('results/view/(:num)', 'Admin\ResultController::view/$1');
    $routes->get('results/detail/(:num)', 'Admin\ResultController::detail/$1');
    $routes->post('results/update-score', 'Admin\ResultController::updateManualScore');
    $routes->post('results/delete-attempt/(:num)', 'Admin\ResultController::deleteAttempt/$1');

    // Proctor Report Notifications (polling)
    $routes->get('notifications/proctor-reports', 'Admin\NotificationController::proctorReports');
});

// ── Student Routes (role-protected: siswa, admin, guru) ───────────
$routes->group('student', ['filter' => 'role:siswa,admin,guru'], static function ($routes) {
    $routes->get('/', 'Student\DashboardController::index');
    $routes->get('dashboard', 'Student\DashboardController::index');
    
    // Exam taking flows will go here
    $routes->get('exam/prepare/(:num)', 'Student\ExamController::prepare/$1');
    $routes->post('exam/start/(:num)', 'Student\ExamController::start/$1');
    $routes->group('exam', static function ($routes) {
        $routes->get('take/(:num)', 'Student\ExamController::take/$1');
        // @todo remove: legacy SSE endpoint, controller no longer exists
        // $routes->get('stream/(:num)', 'Student\SseController::stream/$1');
        $routes->post('autosave', 'Student\ExamController::saveAnswer');
        $routes->post('auto-sync', 'Student\ExamController::autoSync');
    });
    $routes->post('exam/report-cheat', 'Student\ExamController::reportCheat');

    $routes->post('exam/check-score', 'Student\ExamController::checkCurrentScore');
    $routes->post('exam/finish/(:num)', 'Student\ExamController::finish/$1');
    
    // Results / Feedback
    $routes->get('results/view/(:num)', 'Student\ResultController::view/$1');
    $routes->get('results/review/(:num)', 'Student\ResultController::review/$1');
});

// ── Proctor Routes (role-protected: guru, admin) ───
$routes->group('proctor', ['filter' => 'role:guru,admin'], static function ($routes) {
    $routes->get('/', 'Proctor\DashboardController::index');
    $routes->get('dashboard', 'Proctor\DashboardController::index');
    $routes->get('live/(:num)', 'Proctor\LiveController::monitor/$1');
    $routes->post('live/report-student', 'Proctor\LiveController::reportStudent');
});

// ── Default Route ───────────────────────────────────
$routes->get('/', 'Home::index');
$routes->get('lang/(:segment)', 'LanguageController::switchLanguage/$1');

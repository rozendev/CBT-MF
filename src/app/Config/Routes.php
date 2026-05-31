<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ── Auth Routes (public) ────────────────────────────
$routes->get('login', 'Auth\AuthController::login');
$routes->post('login', 'Auth\AuthController::attemptLogin');
$routes->get('logout', 'Auth\AuthController::logout');

// ── Admin Routes (role-protected) ───────────────────
$routes->group('admin', ['filter' => 'role:admin,guru'], static function ($routes) {
    $routes->get('/', 'Admin\DashboardController::index');
    $routes->get('dashboard', 'Admin\DashboardController::index');

    // User Management
    $routes->get('users', 'Admin\UserController::index');
    $routes->get('users/create', 'Admin\UserController::create');
    $routes->post('users/store', 'Admin\UserController::store');
    $routes->get('users/edit/(:num)', 'Admin\UserController::edit/$1');
    $routes->post('users/update/(:num)', 'Admin\UserController::update/$1');
    $routes->delete('users/delete/(:num)', 'Admin\UserController::delete/$1');
    
    // Suspend & Lock
    $routes->get('suspend', 'Admin\SuspendController::index');
    $routes->post('suspend/release/(:num)', 'Admin\SuspendController::release/$1');
    $routes->post('suspend/ban/(:num)', 'Admin\SuspendController::ban/$1');
    $routes->post('suspend/reset/(:num)', 'Admin\SuspendController::reset/$1');

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
    $routes->post('users/unlock/(:num)', 'Admin\UserController::unlock/$1');

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
    $routes->get('questions/create', 'Admin\QuestionController::create');
    $routes->post('questions/store', 'Admin\QuestionController::store');
    $routes->get('questions/edit/(:num)', 'Admin\QuestionController::edit/$1');
    $routes->post('questions/update/(:num)', 'Admin\QuestionController::update/$1');
    $routes->delete('questions/delete/(:num)', 'Admin\QuestionController::delete/$1');

    $routes->get('tests', 'Admin\TestController::index');
    $routes->get('tests/create', 'Admin\TestController::create');
    $routes->post('tests/store', 'Admin\TestController::store');
    $routes->get('tests/edit/(:num)', 'Admin\TestController::edit/$1');
    $routes->post('tests/update/(:num)', 'Admin\TestController::update/$1');
    $routes->delete('tests/delete/(:num)', 'Admin\TestController::delete/$1');
    
    // Test Configurations (Peserta & Set Soal)
    $routes->get('tests/config/(:num)', 'Admin\TestController::config/$1');
    $routes->post('tests/config/(:num)/groups', 'Admin\TestController::updateGroups/$1');
    $routes->post('tests/config/(:num)/subjects', 'Admin\TestController::addSubjectSet/$1');
    $routes->delete('tests/config/subjects/(:num)', 'Admin\TestController::deleteSubjectSet/$1');

    // Results / Laporan Ujian
    $routes->get('results', 'Admin\ResultController::index');
    $routes->get('results/view/(:num)', 'Admin\ResultController::view/$1');
    $routes->get('results/detail/(:num)', 'Admin\ResultController::detail/$1');
    $routes->post('results/grade-essay', 'Admin\ResultController::gradeEssay');
});

// ── Student Routes (role-protected: siswa) ───────────
$routes->group('student', ['filter' => 'role:siswa'], static function ($routes) {
    $routes->get('/', 'Student\DashboardController::index');
    $routes->get('dashboard', 'Student\DashboardController::index');
    
    // Exam taking flows will go here
    $routes->get('exam/prepare/(:num)', 'Student\ExamController::prepare/$1');
    $routes->post('exam/start/(:num)', 'Student\ExamController::start/$1');
    $routes->get('exam/take/(:num)', 'Student\ExamController::take/$1');
    $routes->post('exam/save-answer', 'Student\ExamController::saveAnswer');
    $routes->post('exam/report-cheat', 'Student\ExamController::reportCheat');
    $routes->get('exam/heartbeat', 'Student\ExamController::heartbeat');
    $routes->post('exam/finish/(:num)', 'Student\ExamController::finish/$1');
    
    // Results / Feedback
    $routes->get('results/view/(:num)', 'Student\ResultController::view/$1');
});

// ── Default Route ───────────────────────────────────
$routes->get('/', 'Home::index');

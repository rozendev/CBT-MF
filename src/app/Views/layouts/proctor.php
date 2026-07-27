<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection('title') ?> - Sistem Ujian (Proctor)</title>
    <meta name="csrf-header" content="<?= csrf_header() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    
    <!-- Load from local vendor like admin.php -->
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/outfit.css?v=1.1') ?>" rel="stylesheet">
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        :root {
            /* Neumorphism / Soft UI style from Admin */
            --bg-body: #f4f7fe;
            --bg-surface: #ffffff;
            --text-primary: #2b3674;
            --text-secondary: #a3aed1;
            --border-color: #e2e8f0;
            --card-shadow: 0 10px 30px rgba(0,0,0,0.03);
            --brand-color: #4318ff;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-primary);
        }

        .proctor-navbar {
            background-color: var(--bg-surface);
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            padding: 0.8rem 1rem;
        }

        .navbar-brand {
            color: var(--text-primary) !important;
            display: flex;
            align-items: center;
        }

        .navbar-brand .icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: var(--brand-color);
            color: white;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .card {
            border: 1px solid var(--border-color);
            border-radius: 16px;
            background: var(--bg-surface);
            box-shadow: var(--card-shadow);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
        }
    </style>
    
    <?= $this->renderSection('styles') ?>
</head>
<body>

    <nav class="navbar navbar-expand-lg proctor-navbar">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold" href="<?= base_url('proctor') ?>">
                <div class="icon-box me-2"><i class="bi bi-shield-check"></i></div>
                Sistem Ujian <span class="text-secondary fw-normal ms-2">| Live Proctor</span>
            </a>
            
            <div class="d-flex align-items-center">
                <span class="text-secondary fw-medium me-4"><i class="bi bi-person-circle me-1"></i> <?= esc($userName ?? session('username')) ?> (<?= esc($userRole ?? session('role')) ?>)</span>
                
                <?php if(session('role') === 'admin'): ?>
                    <a href="<?= base_url('admin/dashboard') ?>" class="btn btn-light text-primary btn-sm rounded-pill px-3 fw-medium me-2 border shadow-sm">
                        <i class="bi bi-arrow-left"></i> Panel Admin
                    </a>
                <?php endif; ?>
                
                <a href="<?= base_url('logout') ?>" class="btn btn-outline-danger btn-sm rounded-pill px-3 fw-medium">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4 px-4">
        <?php if(session()->getFlashdata('error')): ?>
            <div class="alert alert-danger border-0 shadow-sm rounded-3 alert-dismissible fade show" role="alert">
                <?= esc(session()->getFlashdata('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if(session()->getFlashdata('success')): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-3 alert-dismissible fade show" role="alert">
                <?= esc(session()->getFlashdata('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="<?= base_url('vendor/bootstrap/js/bootstrap.bundle.min.js?v=1.1') ?>"></script>
    
    <?= $this->renderSection('scripts') ?>
</body>
</html>

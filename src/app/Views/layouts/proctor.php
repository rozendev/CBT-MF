<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection('title') ?> - Sistem Ujian (Proctor)</title>
    <meta name="csrf-header" content="<?= csrf_header() ?>">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            background-color: #f8f9fa;
        }
        .proctor-navbar {
            background-color: #1e293b;
            color: white;
        }
    </style>
    
    <?= $this->renderSection('styles') ?>
</head>
<body>

    <nav class="navbar navbar-expand-lg proctor-navbar shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand text-white fw-bold" href="<?= base_url('proctor') ?>">
                <i class="bi bi-shield-check me-2"></i>Sistem Ujian - Live Proctor
            </a>
            
            <div class="d-flex align-items-center">
                <span class="text-light me-3"><i class="bi bi-person-circle me-1"></i> <?= esc($userName ?? session('username')) ?> (<?= esc($userRole ?? session('role')) ?>)</span>
                
                <?php if(session('role') === 'admin'): ?>
                    <a href="<?= base_url('admin/dashboard') ?>" class="btn btn-outline-light btn-sm me-2">
                        <i class="bi bi-arrow-left"></i> Kembali ke Admin
                    </a>
                <?php endif; ?>
                
                <a href="<?= base_url('logout') ?>" class="btn btn-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <?php if(session()->getFlashdata('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= esc(session()->getFlashdata('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if(session()->getFlashdata('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= esc(session()->getFlashdata('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?= $this->renderSection('scripts') ?>
</body>
</html>

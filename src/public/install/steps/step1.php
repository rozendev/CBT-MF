<?php
$phpVersionOk = version_compare(PHP_VERSION, '8.2.0', '>=');
$extPdo = extension_loaded('pdo_mysql');
$extIntl = extension_loaded('intl');
$extMbstring = extension_loaded('mbstring');
$extCurl = extension_loaded('curl');
$extRedis = extension_loaded('redis');
$extGd = extension_loaded('gd');

$envWritable = is_writable(__DIR__ . '/../../..') || is_writable(__DIR__ . '/../../../.env');
$writableDir = is_writable(__DIR__ . '/../../../writable');

$allOk = $phpVersionOk && $extPdo && $extIntl && $extMbstring && $extCurl && $extRedis && $extGd && $envWritable && $writableDir;
?>

<h5 class="fw-bold mb-4">Langkah 1: Pemeriksaan Sistem</h5>
<p class="text-muted mb-4">Pastikan server Anda memenuhi persyaratan minimum untuk menjalankan Sistem Ujian CBT.</p>

<ul class="list-group mb-4">
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>PHP Version &ge; 8.2 (Current: <?= PHP_VERSION ?>)</span>
        <?php if ($phpVersionOk): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> OK</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Failed</span><?php endif; ?>
    </li>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>PDO MySQL Extension</span>
        <?php if ($extPdo): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> OK</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Failed</span><?php endif; ?>
    </li>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>Redis Extension</span>
        <?php if ($extRedis): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> OK</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Failed</span><?php endif; ?>
    </li>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>GD Extension (Image Processing)</span>
        <?php if ($extGd): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> OK</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Failed</span><?php endif; ?>
    </li>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>Intl & Mbstring Extensions</span>
        <?php if ($extIntl && $extMbstring): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> OK</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Failed</span><?php endif; ?>
    </li>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>Direktori Writable (`/writable`)</span>
        <?php if ($writableDir): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> Writable</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Not Writable</span><?php endif; ?>
    </li>
    <li class="list-group-item d-flex justify-content-between align-items-center">
        <span>Izin Tulis Konfigurasi (`.env`)</span>
        <?php if ($envWritable): ?><span class="badge bg-success rounded-pill"><i class="bi bi-check"></i> Writable</span><?php else: ?><span class="badge bg-danger rounded-pill"><i class="bi bi-x"></i> Not Writable</span><?php endif; ?>
    </li>
</ul>

<div class="d-flex justify-content-end">
    <?php if ($allOk): ?>
        <a href="?step=2" class="btn btn-primary px-4">Lanjut ke Langkah 2 <i class="bi bi-arrow-right"></i></a>
    <?php else: ?>
        <button class="btn btn-secondary px-4" disabled>Perbaiki error di atas untuk lanjut</button>
        <a href="?step=1" class="btn btn-outline-primary ms-2"><i class="bi bi-arrow-clockwise"></i> Cek Ulang</a>
    <?php endif; ?>
</div>

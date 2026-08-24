<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Intruder - Monitoring Honeypot<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .toolbar {
        display: flex; align-items: flex-end; gap: 0.75rem; flex-wrap: wrap;
    }
    .toolbar .form-control, .toolbar .form-select { background: var(--bg-surface); border-color: var(--border-color); }
    .thumb {
        width: 52px; height: 52px; flex: 0 0 auto;
        border-radius: 10px; overflow: hidden;
        background: var(--bg-soft);
        border: 1px solid var(--border-color);
        cursor: zoom-in;
        display: flex; align-items: center; justify-content: center;
        color: var(--text-tertiary); font-size: 1.1rem;
    }
    .thumb img { width: 100%; height: 100%; object-fit: cover; }
    .stat-tile {
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: 14px; padding: 18px 20px;
        display: flex; align-items: center; gap: 14px;
    }
    .stat-tile .stat-ico {
        width: 42px; height: 42px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
    }
    .maps-link {
        color: var(--accent); text-decoration: none; font-size: 0.72rem;
    }
    .maps-link:hover { text-decoration: underline; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Honeypot · 403/404 Detection</div>
        <h1>Intruder</h1>
        <p class="sub">Percobaan akses ilegal yang menjebak halaman dekoy: foto, lokasi, dan identitas perangkat.</p>
    </div>
    <div class="actions">
        <a href="<?= base_url('/admin/logging') ?>" class="btn btn-ghost btn-sm">
            <i class="bi bi-journal-richtext me-1"></i> Logging &amp; Aktivitas
        </a>
    </div>
</div>

<!-- Stats -->
<div class="row g-4 mb-4 mt-1">
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-ico" style="background: rgba(239,68,68,.12); color: #ef4444;"><i class="bi bi-bug-fill"></i></div>
            <div>
                <div class="stat-label mb-1">Total Percobaan</div>
                <h4 class="fw-bold mb-0" style="letter-spacing:-0.02em;"><?= number_format($stats['total']) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-ico" style="background: rgba(251,191,36,.12); color: #f59e0b;"><i class="bi bi-calendar-event"></i></div>
            <div>
                <div class="stat-label mb-1">Hari Ini</div>
                <h4 class="fw-bold mb-0" style="letter-spacing:-0.02em;"><?= number_format($stats['today']) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile">
            <div class="stat-ico" style="background: rgba(52,211,153,.12); color: #10b981;"><i class="bi bi-camera-fill"></i></div>
            <div>
                <div class="stat-label mb-1">Dengan Foto</div>
                <h4 class="fw-bold mb-0" style="letter-spacing:-0.02em;"><?= number_format($stats['photo']) ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="card rise" style="--d:60ms">
    <div class="card-body p-4">
        <form method="GET" action="<?= base_url('/admin/logging/intruders') ?>" class="toolbar">
            <div>
                <label class="form-label small fw-semibold">Dari Tanggal</label>
                <input type="date" name="from" value="<?= esc($dateFrom) ?>" class="form-control" style="width:180px;">
            </div>
            <div>
                <label class="form-label small fw-semibold">Sampai Tanggal</label>
                <input type="date" name="to" value="<?= esc($dateTo) ?>" class="form-control" style="width:180px;">
            </div>
            <div style="flex:1; min-width:220px;">
                <label class="form-label small fw-semibold">Cari</label>
                <input type="text" name="search" value="<?= esc($search) ?>" class="form-control" placeholder="IP, user-agent, platform, URL yang dicoba...">
            </div>
            <button type="submit" class="btn btn-accent btn-sm">
                <i class="bi bi-funnel me-1"></i> Terapkan
            </button>
            <a href="<?= base_url('/admin/logging/intruders') ?>" class="btn btn-ghost btn-sm">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
            </a>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card rise mt-4" style="--d:120ms">
    <div class="card-header bg-transparent border-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <div class="stat-label mb-1"><i class="bi bi-shield-exclamation me-1"></i> Deteksi</div>
            <h6 class="fw-bold mb-0" style="letter-spacing:-0.02em;">Laporan Intruder</h6>
        </div>
        <?php if (($dateFrom !== '' || $dateTo !== '' || $search !== '') && !empty($reports)): ?>
            <span class="chip info"><i class="bi bi-funnel"></i> Sedang difilter</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Waktu</th>
                        <th>Foto</th>
                        <th>IP</th>
                        <th>Lokasi</th>
                        <th class="d-none d-lg-table-cell">URL Dicoba</th>
                        <th class="pe-4 d-none d-md-table-cell">Perangkat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <div class="empty-icon"><i class="bi bi-shield-check"></i></div>
                                    <h6>Belum ada intruder</h6>
                                    <p>Tidak ada laporan pada rentang & pencarian ini. Honeypot masih bekerja diam-diam.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $r): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="mono" style="font-size:0.76rem; color: var(--text-secondary); white-space:nowrap;"><?= esc(date('d M Y', strtotime($r->created_at))) ?></span>
                                    <span class="mono d-block" style="font-size:0.68rem; color: var(--text-tertiary);"><?= esc(date('H:i:s', strtotime($r->created_at))) ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($r->photo_path)): ?>
                                        <div class="thumb" data-bs-toggle="modal" data-bs-target="#photoModal" data-photo="<?= esc(base_url('uploads/intruder/' . $r->photo_path)) ?>" data-ip="<?= esc($r->ip_address ?? '-') ?>" data-time="<?= esc(date('d M Y H:i:s', strtotime($r->created_at))) ?>" data-lat="<?= esc($r->latitude ?? '') ?>" data-lng="<?= esc($r->longitude ?? '') ?>" data-ua="<?= esc($r->user_agent ?? '') ?>" data-uri="<?= esc($r->requested_uri ?? '') ?>" data-screen="<?= esc($r->screen ?? '') ?>" title="Lihat detail foto">
                                            <img src="<?= esc(base_url('uploads/intruder/' . $r->photo_path)) ?>" alt="Intruder photo">
                                        </div>
                                    <?php else: ?>
                                        <div class="thumb" title="Tidak ada foto"><i class="bi bi-person-slash"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mono" style="font-size:0.72rem; color: var(--text-primary);"><?= esc($r->ip_address ?? '-') ?></span>
                                    <div class="mono" style="font-size:0.64rem; color: var(--text-tertiary);"><?= esc($r->platform ?? '-') ?></div>
                                </td>
                                <td>
                                    <?php if ($r->latitude !== null && $r->longitude !== null): ?>
                                        <span class="mono" style="font-size:0.72rem; color: var(--text-secondary); white-space:nowrap;"><?= esc(number_format((float) $r->latitude, 5, '.', '')) ?>, <?= esc(number_format((float) $r->longitude, 5, '.', '')) ?></span>
                                        <a class="maps-link d-block" href="https://www.google.com/maps?q=<?= esc((float) $r->latitude) ?>,<?= esc((float) $r->longitude) ?>" target="_blank" rel="noopener">
                                            <i class="bi bi-geo-alt me-1"></i>Buka di Google Maps
                                        </a>
                                        <?php if ($r->accuracy !== null): ?>
                                            <span class="mono" style="font-size:0.62rem; color: var(--text-tertiary);">akurasi ±<?= esc((int) $r->accuracy) ?> m</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="mono" style="font-size:0.72rem; color: var(--text-tertiary);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-lg-table-cell small" style="color: var(--text-secondary); max-width: 260px;">
                                    <span class="d-inline-block text-truncate mono" style="max-width: 240px; font-size:0.72rem;"><?= esc($r->requested_uri ?? '-') ?></span>
                                </td>
                                <td class="pe-4 d-none d-md-table-cell">
                                    <span class="d-inline-block text-truncate" style="max-width: 180px; font-size:0.72rem; color: var(--text-tertiary);"><?= esc($r->user_agent ?? '-') ?></span>
                                    <?php if (!empty($r->screen)): ?>
                                        <span class="chip info d-inline-block mt-1"><i class="bi bi-display"></i> <?= esc($r->screen) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pager): ?>
    <div class="card-footer py-3 border-top d-flex justify-content-end">
        <?= $pager->links('default', 'bootstrap_pagination') ?>
    </div>
    <?php endif; ?>
</div>

<!-- Photo Detail Modal -->
<div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-bounding-box me-2 text-primary"></i>Detail Intruder</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4 align-items-start">
                    <div class="col-md-7">
                        <img id="m-photo" src="" alt="Foto intruder" class="img-fluid rounded-3 w-100" style="max-height: 420px; object-fit: contain; background: var(--bg-soft); border: 1px solid var(--border-color);">
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <a id="m-maps" href="#" target="_blank" rel="noopener" class="btn btn-ghost btn-sm"><i class="bi bi-geo-alt me-1"></i>Lokasi di Google Maps</a>
                            <span class="chip info" id="m-screen"><i class="bi bi-display"></i> <span id="m-screen-text">-</span></span>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <table class="table table-sm align-middle mb-0" style="font-size:0.82rem;">
                            <tbody>
                                <tr><td class="text-secondary" style="width:36%;">Waktu</td><td class="mono" id="m-time">-</td></tr>
                                <tr><td class="text-secondary">IP Address</td><td class="mono" id="m-ip">-</td></tr>
                                <tr><td class="text-secondary">Koordinat</td><td class="mono" id="m-coord">-</td></tr>
                                <tr><td class="text-secondary">URL Dicoba</td><td class="mono text-truncate" id="m-uri" style="max-width:220px;">-</td></tr>
                                <tr><td class="text-secondary">User Agent</td><td id="m-ua" style="word-break:break-word;">-</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('photoModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (e) {
        var el = e.relatedTarget;
        if (!el) return;
        document.getElementById('m-photo').src = el.dataset.photo || '';
        document.getElementById('m-time').textContent = el.dataset.time || '-';
        document.getElementById('m-ip').textContent = el.dataset.ip || '-';
        document.getElementById('m-ua').textContent = el.dataset.ua || '-';
        document.getElementById('m-uri').textContent = el.dataset.uri || '-';
        // textContent, bukan innerHTML: dataset mengembalikan nilai yang sudah
        // di-decode dari HTML entity, jadi esc() di atribut tidak menolong di sini.
        document.getElementById('m-screen-text').textContent = el.dataset.screen || '-';
        var lat = el.dataset.lat, lng = el.dataset.lng;
        var coordEl = document.getElementById('m-coord');
        var mapsEl = document.getElementById('m-maps');
        if (lat && lng) {
            coordEl.textContent = lat + ', ' + lng;
            mapsEl.style.display = '';
            mapsEl.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        } else {
            coordEl.textContent = '-';
            mapsEl.style.display = 'none';
        }
    });
});
</script>
<?= $this->endSection() ?>
<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Logging & Aktivitas<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .toolbar {
        display: flex; align-items: flex-end; gap: 0.75rem; flex-wrap: wrap;
    }
    .toolbar .form-control, .toolbar .form-select { background: var(--bg-surface); border-color: var(--border-color); }
    .live-orb { position: relative; }
    .live-orb::after {
        content: "";
        position: absolute; inset: -4px;
        border-radius: 50%;
        border: 1px solid var(--ok);
        opacity: 0;
        animation: orb 2.6s ease-out infinite;
    }
    @keyframes orb {
        0% { transform: scale(0.5); opacity: 0.7; }
        100% { transform: scale(1.6); opacity: 0; }
    }
    .act-icon {
        width: 34px; height: 34px; flex: 0 0 auto;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem;
        background: var(--bg-soft); color: var(--text-secondary);
        border: 1px solid var(--border-color);
    }
    .exp-col { width: 50%; }
    @media (max-width: 575.98px) { .exp-col { width: 100%; } }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Page Head -->
<div class="page-head rise">
    <div>
        <div class="eyebrow">Audit Trail · Realtime</div>
        <h1>Logging & Aktivitas</h1>
        <p class="sub">Jejak aktivitas pengguna, sesi online, dan export riwayat ke .xls.</p>
    </div>
    <div class="actions">
        <button type="button" class="btn btn-accent btn-sm" data-bs-toggle="modal" data-bs-target="#exportModal">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Unduh .xls
        </button>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="card rise" style="--d:60ms">
    <div class="card-body p-4">
        <form method="GET" action="<?= base_url('/admin/logging') ?>" class="toolbar">
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
                <input type="text" name="search" value="<?= esc($search) ?>" class="form-control" placeholder="Nama, username, aksi, deskripsi...">
            </div>
            <button type="submit" class="btn btn-accent btn-sm">
                <i class="bi bi-funnel me-1"></i> Terapkan
            </button>
            <a href="<?= base_url('/admin/logging') ?>" class="btn btn-ghost btn-sm">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
            </a>
        </form>
    </div>
</div>

<!-- Asymmetric: activity table (8) + realtime online (4) -->
<div class="row g-4 mt-1">
    <div class="col-lg-8">
        <div class="card rise" style="--d:120ms">
            <div class="card-header bg-transparent border-0 pt-4 pb-0 px-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="stat-label mb-1"><i class="bi bi-list-check me-1"></i> Riwayat</div>
                    <h6 class="fw-bold mb-0" style="letter-spacing:-0.02em;">Aktivitas Terkini</h6>
                </div>
                <?php if (($dateFrom !== '' || $dateTo !== '' || $search !== '') && !empty($activities)): ?>
                    <span class="chip info"><i class="bi bi-funnel"></i> Sedang difilter</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Waktu</th>
                                <th>Pengguna</th>
                                <th>Aksi</th>
                                <th class="d-none d-lg-table-cell">Detail</th>
                                <th class="pe-4 d-none d-md-table-cell">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activities)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty">
                                            <div class="empty-icon"><i class="bi bi-clock-history"></i></div>
                                            <h6>Tidak ada aktivitas</h6>
                                            <p>Tidak ada data pada rentang & pencarian ini. Ubah filter atau coba rentang waktu lain.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="mono" style="font-size:0.76rem; color: var(--text-secondary); white-space:nowrap;"><?= esc(date('d M Y', strtotime($act->created_at))) ?></span>
                                            <span class="mono d-block" style="font-size:0.68rem; color: var(--text-tertiary);"><?= esc(date('H:i:s', strtotime($act->created_at))) ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="avatar-tile ink"><?= esc(strtoupper(substr(($act->firstname ?? $act->username ?? 'S'), 0, 1))) ?></div>
                                                <div>
                                                    <div class="fw-medium small" style="color: var(--text-primary);"><?= esc(trim(($act->firstname ?? '') . ' ' . ($act->lastname ?? '')) ?: ($act->username ?? 'System')) ?></div>
                                                    <span class="mono" style="font-size:0.62rem; color: var(--text-tertiary); text-transform:uppercase;"><?= esc($act->role ?? 'sistem') ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="chip <?= $act->action ? 'info' : '' ?>">
                                                <i class="bi bi-dot"></i> <?= esc($act->action ?? '-') ?>
                                            </span>
                                        </td>
                                        <td class="d-none d-lg-table-cell small" style="color: var(--text-secondary); max-width: 300px;">
                                            <span class="d-inline-block text-truncate" style="max-width: 280px;"><?= esc($act->description ?? '-') ?></span>
                                        </td>
                                        <td class="pe-4 d-none d-md-table-cell">
                                            <span class="mono" style="font-size:0.72rem; color: var(--text-tertiary);"><?= esc($act->ip_address ?? '-') ?></span>
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
    </div>

    <div class="col-lg-4">
        <div class="card h-100 d-flex flex-column rise" style="--d:180ms">
            <div class="d-flex justify-content-between align-items-center p-4 pb-3">
                <div>
                    <div class="stat-label mb-1"><i class="bi bi-activity me-1"></i> Realtime</div>
                    <h6 class="fw-bold mb-0" style="letter-spacing:-0.02em;">User Online</h6>
                </div>
                <span class="chip ok"><span class="dot breathe"></span> Live</span>
            </div>

            <?php if (!empty($onlineUsers)): ?>
                <div class="px-2 flex-grow-1 overflow-auto" style="max-height: 460px;">
                    <?php foreach ($onlineUsers as $ou): ?>
                    <div class="d-flex justify-content-between align-items-center px-2 py-2 rounded-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-tile moss"><?= esc(strtoupper(substr($ou['firstname'] ?? $ou['username'], 0, 1))) ?></div>
                            <div>
                                <h6 class="mb-0 fs-6 fw-semibold" style="color: var(--text-primary);"><?= esc($ou['firstname'] ?? $ou['username']) ?></h6>
                                <small class="mono" style="font-size:0.68rem; color: var(--text-tertiary);"><?= esc(ucfirst($ou['role'])) ?></small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="live-orb d-inline-block" style="width:8px;height:8px;border-radius:50%;background:var(--ok);"></span>
                            <div class="mono mt-1" style="font-size:0.66rem; color: var(--text-tertiary);"><?= date('H:i', strtotime($ou['last_active_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="px-4 py-3 border-top d-flex align-items-center gap-2" style="border-color: var(--border-color) !important;">
                    <span class="mono" style="font-size:0.7rem; color: var(--text-tertiary);"><?= count($onlineUsers) ?> sesi aktif · 5 menit terakhir</span>
                </div>
            <?php else: ?>
                <div class="empty flex-grow-1">
                    <div class="empty-icon"><i class="bi bi-moon-stars"></i></div>
                    <h6>Tidak ada sesi aktif</h6>
                    <p>Belum ada user yang sedang online. Saat peserta masuk, mereka akan muncul di panel ini secara real-time.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= base_url('/admin/logging/export') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Unduh Log Aktivitas (.xls)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-1">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Dari Tanggal</label>
                            <input type="date" name="from" value="<?= esc($dateFrom) ?>" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Sampai Tanggal</label>
                            <input type="date" name="to" value="<?= esc($dateTo) ?>" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Cari (opsional)</label>
                            <input type="text" name="search" value="<?= esc($search) ?>" class="form-control" placeholder="Nama, username, aksi, deskripsi...">
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="form-label small fw-semibold mb-2">Pilih Kolom Data</div>
                        <div class="row g-2">
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="waktu" id="f_waktu" checked><label class="form-check-label small" for="f_waktu">Waktu</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="user" id="f_user" checked><label class="form-check-label small" for="f_user">Nama</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="username" id="f_username" checked><label class="form-check-label small" for="f_username">Username</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="role" id="f_role" checked><label class="form-check-label small" for="f_role">Role</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="aksi" id="f_aksi" checked><label class="form-check-label small" for="f_aksi">Aksi</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="deskripsi" id="f_deskripsi" checked><label class="form-check-label small" for="f_deskripsi">Deskripsi</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="entitas" id="f_entitas" checked><label class="form-check-label small" for="f_entitas">Entitas</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="ip" id="f_ip" checked><label class="form-check-label small" for="f_ip">IP Address</label></div></div>
                            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="fields[]" value="user_agent" id="f_user_agent"><label class="form-check-label small" for="f_user_agent">User Agent</label></div></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-accent btn-sm">
                        <i class="bi bi-download me-1"></i> Download .xls
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

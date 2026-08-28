<?= $this->extend('layouts/admin') ?>
<?= $this->section('page_title') ?>Perangkat Terkunci<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid" x-data="kioskDevices()">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="mb-1">Perangkat Terkunci</h5>
            <p class="text-muted small mb-0">
                Perangkat di daftar ini tidak dapat menjalankan aplikasi ujian sama sekali.
                Akun siswanya tidak ikut terkunci — mereka tetap bisa mengerjakan di perangkat lain.
            </p>
        </div>
        <a href="<?= base_url('/admin/kiosk/live') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Monitoring
        </a>
    </div>

    <div class="alert" :class="actionOk ? 'alert-success' : 'alert-danger'"
         x-show="actionMessage" x-text="actionMessage" style="display:none"></div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID Perangkat</th>
                        <th>Alasan</th>
                        <th>Dikunci oleh</th>
                        <th>Waktu</th>
                        <th>Pemakai terakhir</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td><code class="small" title="<?= esc($device->device_id, 'attr') ?>"><?= esc(substr($device->device_id, 0, 12)) ?>…</code></td>
                            <td><?= esc($device->reason) ?></td>
                            <td class="small"><?= esc($device->banned_by_name) ?></td>
                            <td class="small text-muted"><?= esc($device->banned_at) ?></td>
                            <td class="small"><?= esc($device->last_user_name) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-success"
                                        :disabled="busyDevice === '<?= esc($device->device_id, 'attr') ?>'"
                                        @click="unlock('<?= esc($device->device_id, 'attr') ?>')">
                                    <i class="bi bi-unlock me-1"></i>Buka Kunci
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($devices === []): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-shield-check fs-3 d-block mb-2"></i>
                                Tidak ada perangkat yang terkunci.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function kioskDevices() {
    return {
        busyDevice: null,
        actionMessage: '',
        actionOk: true,
        unlock(deviceId) {
            if (!window.confirm('Buka kunci perangkat ini?\n\nPerangkat akan langsung bisa menjalankan aplikasi ujian lagi.')) return;

            this.busyDevice = deviceId;
            fetch('<?= base_url('/admin/kiosk/devices/unlock') ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                },
                body: JSON.stringify({ device_id: deviceId })
            })
                .then(r => r.json())
                .then(d => {
                    this.actionOk = d.status === 'success';
                    this.actionMessage = d.message || (this.actionOk ? 'Berhasil.' : 'Gagal.');
                    if (this.actionOk) setTimeout(() => window.location.reload(), 800);
                })
                .catch(e => {
                    console.error('unlock failed:', e);
                    this.actionOk = false;
                    this.actionMessage = 'Gagal terkirim. Periksa koneksi lalu coba lagi.';
                })
                .finally(() => { this.busyDevice = null; });
        }
    }
}
</script>
<?= $this->endSection() ?>

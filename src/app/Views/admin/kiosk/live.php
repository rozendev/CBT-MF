<?= $this->extend('layouts/admin') ?>
<?= $this->section('page_title') ?>Monitoring EXAMBRO Real-Time<?= $this->endSection() ?>
<?= $this->section('styles') ?>
<style>
    .status-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
    .status-online { background: #22c55e; box-shadow: 0 0 0 4px rgba(34,197,94,.15); }
    .status-stale  { background: #f59e0b; box-shadow: 0 0 0 4px rgba(245,158,11,.15); }
    .status-offline{ background: #9ca3af; }
    .kiosk-outage-banner { border-left: 4px solid var(--danger, #dc3545); }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div x-data="kioskLive()" class="pb-5">
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h3 class="fw-bold mb-1"><i class="bi bi-phone me-2 text-primary"></i>Monitoring EXAMBRO Real-Time</h3>
            <p class="text-muted mb-0">Status perangkat EXAMBRO siswa per ujian. Data diperbarui otomatis tiap 10 detik.</p>
        </div>
        <div class="col-md-4 text-end">
            <select x-model="selectedTest" @change="loadData()" class="form-select d-inline-block w-auto">
                <option value="">— Pilih Ujian —</option>
                <template x-for="t in tests" :key="t.id">
                    <option :value="t.id" x-text="t.name + ' (' + t.attempt_count + ' peserta)'"></option>
                </template>
            </select>
        </div>
    </div>

    <div x-show="outageMessage" class="alert kiosk-outage-banner mb-4">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span x-text="outageMessage"></span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Status</th><th>Siswa</th><th>Baterai</th><th>Jaringan</th>
                            <th>Versi App</th><th>Device ID</th><th>Terakhir Terlihat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="s in students" :key="s.user_id">
                            <tr>
                                <td>
                                    <span class="status-dot" :class="'status-' + s.status"></span>
                                    <span class="ms-2 badge" :class="s.status==='online' ? 'bg-success' : (s.status==='stale' ? 'bg-warning text-dark' : 'bg-secondary')"
                                          x-text="s.status==='online' ? 'Online' : (s.status==='stale' ? 'Stale' : 'Offline')"></span>
                                </td>
                                <td><span class="fw-semibold" x-text="s.firstname + ' ' + s.lastname"></span><br>
                                    <small class="text-muted" x-text="s.username"></small></td>
                                <td>
                                    <template x-if="s.battery >= 0">
                                        <span><i class="bi" :class="s.charging ? 'bi-battery-charging text-success' : 'bi-battery-half'"></i>
                                            <span x-text="s.battery + '%'"></span></span>
                                    </template>
                                    <template x-if="s.battery < 0"><span class="text-muted">—</span></template>
                                </td>
                                <td>
                                    <i class="bi" :class="s.network==='wifi' ? 'bi-wifi text-primary' : (s.network==='mobile' ? 'bi-signal text-primary' : 'bi-x-circle text-muted')"></i>
                                    <span class="ms-1 text-capitalize" x-text="s.network === 'unknown' ? '—' : s.network"></span>
                                </td>
                                <td><span class="text-muted" x-text="s.app_version || '—'"></span></td>
                                <td><span class="text-muted small" x-text="s.device_id ? s.device_id.substring(0, 8) + '…' : '—'"></span></td>
                                <td><span class="text-muted small" x-text="s.last_seen || '—'"></span></td>
                            </tr>
                        </template>
                        <tr x-show="students.length === 0">
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                                <template x-if="!selectedTest">Pilih ujian untuk melihat status perangkat.</template>
                                <template x-if="selectedTest">Belum ada peserta aktif pada ujian ini.</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function kioskLive() {
    return {
        tests: <?= json_encode($activeTests ?? [], JSON_UNESCAPED_UNICODE) ?>,
        selectedTest: '',
        students: [],
        outageMessage: '',
        timer: null,
        init() {
            if (this.tests.length === 1) {
                this.selectedTest = String(this.tests[0].id);
                this.loadData();
            }
            this.checkOutage();
            this.timer = setInterval(() => {
                if (this.selectedTest) this.loadData();
                this.checkOutage();
            }, 10000);
        },
        loadData() {
            if (!this.selectedTest) return;
            const headers = {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
            };
            fetch('<?= base_url('/admin/kiosk/live-data') ?>?test_id=' + encodeURIComponent(this.selectedTest), { headers })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(d => { this.students = d.students || []; })
                .catch(e => console.error('kiosk live-data failed:', e));
        },
        checkOutage() {
            fetch('<?= base_url('/maintenance-check.php') ?>', { cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    if (d.mode === 'redis') {
                        this.outageMessage = 'Redis tidak tersedia — data mungkin kedaluwarsa hingga layanan pulih.';
                    } else if (d.mode === 'manual') {
                        this.outageMessage = 'Mode pemeliharaan manual aktif — data saat ini mungkin tidak lengkap.';
                    } else {
                        this.outageMessage = '';
                    }
                })
                .catch(() => {});
        }
    }
}
</script>
<?= $this->endSection() ?>
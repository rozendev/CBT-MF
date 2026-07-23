<?= $this->extend('layouts/proctor') ?>

<?= $this->section('title') ?>Live Proctor: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div x-data="proctorLiveDashboard()" x-init="initDashboard()" class="pb-5">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h3 class="fw-bold mb-1"><i class="bi bi-broadcast text-danger me-2"></i>Live Monitoring: <?= esc($test->name) ?></h3>
            <p class="text-muted mb-0">Pantau aktivitas siswa secara real-time. Layar akan berkedip merah jika siswa melakukan pelanggaran.</p>
        </div>
        <div class="col-md-4 text-end">
            <span class="badge" :class="isConnected ? 'bg-success' : 'bg-danger'">
                <i class="bi bi-wifi me-1"></i> <span x-text="isConnected ? 'Terhubung (Live)' : 'Terputus...'"></span>
            </span>
        </div>
    </div>

    <!-- Stats -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-white-50">Siswa Online</h6>
                    <h2 class="mb-0 fw-bold" x-text="onlineCount">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-warning text-dark border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-dark-50">Peringatan Kecurangan</h6>
                    <h2 class="mb-0 fw-bold" x-text="cheatAlertCount">0</h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-danger text-white border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-white-50">Siswa Terkunci (Banned)</h6>
                    <h2 class="mb-0 fw-bold" x-text="bannedCount">0</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Grid -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold"><i class="bi bi-people me-2"></i>Daftar Peserta Ujian</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Siswa</th>
                            <th>Status</th>
                            <th>Peringatan (Strikes)</th>
                            <th class="text-end pe-4">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="student in students" :key="student.user_id">
                            <tr :class="{ 'table-danger': student.flashing, 'table-warning': student.banned, 'table-success': student.status === 3 }">
                                <td class="ps-4">
                                    <div class="fw-bold" x-text="student.name"></div>
                                    <div class="text-muted small" x-text="student.username"></div>
                                </td>
                                <td>
                                    <span class="badge" 
                                          :class="{
                                            'bg-success': student.is_online && !student.banned && student.status !== 3, 
                                            'bg-secondary': !student.is_online && !student.banned && student.status !== 3,
                                            'bg-danger': student.banned,
                                            'bg-info': student.status === 3
                                          }">
                                        <i class="bi" :class="student.status === 3 ? 'bi-check-circle-fill' : (student.is_online ? 'bi-wifi' : 'bi-wifi-off')"></i> 
                                        <span x-text="student.banned ? 'Terkunci' : (student.status === 3 ? 'Selesai (Auto-Submit)' : (student.is_online ? 'Online' : 'Offline'))"></span>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-dark" x-text="student.strikes + ' / 2'"></span>
                                </td>
                                <td class="text-end pe-4">
                                    <?php if(session('role') === 'admin'): ?>
                                    <button class="btn btn-sm btn-outline-danger" @click="resetAttempt(student.attempt_id)" title="Buka Kunci / Hapus Attempt">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                                    </button>
                                    <?php endif; ?>
                                    
                                    <button x-show="!student.banned" class="btn btn-sm btn-danger ms-1" @click="lockAttempt(student.user_id)" title="Kunci Ujian Secara Manual">
                                        <i class="bi bi-lock-fill"></i> Kunci
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="students.length === 0">
                            <td colspan="4" class="text-center py-4 text-muted">Belum ada peserta yang memulai ujian ini.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function proctorLiveDashboard() {
    return {
        wsUrl: '<?= esc($wsUrl) ?>?proctor_token=<?= esc($proctorToken) ?>',
        ws: null,
        isConnected: false,
        reconnectTimer: null,
        students: [],
        
        get onlineCount() {
            return this.students.filter(s => s.is_online && !s.banned).length;
        },
        get bannedCount() {
            return this.students.filter(s => s.banned).length;
        },
        get cheatAlertCount() {
            return this.students.reduce((sum, s) => sum + s.strikes, 0);
        },

        initDashboard() {
            // Load initial data from PHP
            const initialData = <?= json_encode($attempts) ?>;
            this.students = initialData.map(a => ({
                attempt_id: parseInt(a.attempt_id),
                user_id: parseInt(a.user_id),
                name: a.firstname + ' ' + a.lastname,
                username: a.username,
                is_online: Boolean(a.is_online), // Hydrated from backend
                strikes: parseInt(a.cheat_strikes),
                banned: parseInt(a.status) === 2, // 2 = locked/banned
                status: parseInt(a.status),
                flashing: false
            }));

            this.connectWebSocket();
        },

        connectWebSocket() {
            try {
                this.ws = new WebSocket(this.wsUrl);

                this.ws.onopen = () => {
                    console.log('Proctor WebSocket connected');
                    this.isConnected = true;
                    if(this.reconnectTimer) clearInterval(this.reconnectTimer);
                };

                this.ws.onmessage = (e) => {
                    const res = JSON.parse(e.data);
                    this.handleWebSocketEvent(res);
                };

                this.ws.onclose = () => {
                    this.isConnected = false;
                    console.log('WebSocket disconnected. Reconnecting...');
                    this.reconnectTimer = setTimeout(() => this.connectWebSocket(), 3000);
                };

                this.ws.onerror = (err) => {
                    console.error('WebSocket Error:', err);
                };
            } catch(e) {
                console.error(e);
            }
        },

        handleWebSocketEvent(res) {
            const event = res.event;
            const data = res.data || {};
            
            if (event === 'student_connected') {
                this.updateStudentStatus(data.user_id, true);
            } else if (event === 'student_disconnected') {
                this.updateStudentStatus(data.user_id, false);
            } else if (event === 'proctor_alert') {
                // Nested data (from Redis publish)
                const realEvent = data.event;
                if (realEvent === 'ban') {
                    this.triggerCheatAlert(data.user_id);
                } else if (realEvent === 'auto_submit') {
                    this.triggerAutoSubmitAlert(data.user_id, data.reason);
                }
            }
        },

        updateStudentStatus(userId, isOnline) {
            const student = this.students.find(s => s.user_id === userId);
            if (student) {
                student.is_online = isOnline;
            }
        },

        triggerCheatAlert(userId) {
            const student = this.students.find(s => s.user_id === userId);
            if (student) {
                student.strikes++;
                student.banned = true;
                student.is_online = false;
                
                // Flash red effect
                student.flashing = true;
                setTimeout(() => {
                    student.flashing = false;
                }, 3000); // Stop flashing after 3s (remains banned color)
            }
        },

        triggerAutoSubmitAlert(userId, reason) {
            const student = this.students.find(s => s.user_id === userId);
            if (student) {
                student.strikes++;
                student.status = 3; // 3 = finished
                student.is_online = false;
                
                Swal.fire({
                    title: 'Auto-Submit Terdeteksi!',
                    text: `Siswa ${student.name} (${student.username}) terdeteksi ${reason || 'melakukan pelanggaran'} dan ujiannya telah otomatis dikumpulkan.`,
                    icon: 'warning',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 8000,
                    timerProgressBar: true
                });

                student.flashing = true;
                setTimeout(() => {
                    student.flashing = false;
                }, 4000);
            }
        },

        resetAttempt(attemptId) {
            if(!confirm('Yakin ingin mereset ujian siswa ini?')) return;
            const csrfHeader = document.querySelector('meta[name="csrf-header"]').getAttribute('content');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            
            fetch(`<?= base_url('admin/results/delete-attempt/') ?>${attemptId}`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json',
                    [csrfHeader]: csrfToken
                }
            }).then(r => r.json()).then(res => {
                if(res.status === 'success') {
                    // Remove from list
                    this.students = this.students.filter(s => s.attempt_id !== attemptId);
                } else {
                    alert('Gagal mereset.');
                }
            });
        },

        lockAttempt(userId) {
            if(!confirm('Yakin ingin MENGUNCI ujian siswa ini secara manual? Siswa tidak akan bisa melanjutkan ujian!')) return;
            
            const csrfHeader = document.querySelector('meta[name="csrf-header"]').getAttribute('content');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            
            const params = new URLSearchParams();
            params.append('user_id', userId);
            params.append('test_id', <?= esc($test->id) ?>);
            params.append('<?= csrf_token() ?>', csrfToken);
            
            fetch(`<?= base_url('proctor/live/lock-attempt') ?>`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    [csrfHeader]: csrfToken
                },
                body: params.toString()
            }).then(r => r.json()).then(res => {
                if(res.status === 'success') {
                    // Update UI manually or let WebSocket handle the trigger CheatAlert
                    this.triggerCheatAlert(userId);
                } else {
                    alert(res.message || 'Gagal mengunci ujian.');
                }
            });
        }
    }
}
</script>
<?= $this->endSection() ?>

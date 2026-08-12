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
            <button @click="toggleAudio()" class="btn btn-sm me-2" :class="audioEnabled ? 'btn-outline-primary' : 'btn-outline-secondary'" title="Nyalakan suara alarm">
                <i class="bi" :class="audioEnabled ? 'bi-volume-up-fill' : 'bi-volume-mute-fill'"></i>
                <span x-text="audioEnabled ? 'Sound On' : 'Sound Off'"></span>
            </button>
            <span class="badge" :class="isConnected ? 'bg-success' : 'bg-danger'">
                <i class="bi bi-wifi me-1"></i> <span x-text="isConnected ? 'Terhubung (Live)' : 'Terputus...'"></span>
            </span>
        </div>
    </div>

    <!-- Stats -->
    <div class="row mb-4 g-3">
        <div class="col-md-4">
            <div class="card h-100 border-0" style="background: linear-gradient(135deg, var(--brand-color), #2b1299); color: white;">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="me-3 bg-white bg-opacity-25 rounded-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="bi bi-people fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-white-50 mb-1 fw-medium">Siswa Online</h6>
                        <h2 class="mb-0 fw-bold" x-text="onlineCount">0</h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-0" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="me-3 bg-white bg-opacity-25 rounded-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="bi bi-exclamation-triangle fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-white-50 mb-1 fw-medium">Peringatan (Strikes)</h6>
                        <h2 class="mb-0 fw-bold" x-text="cheatAlertCount">0</h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 border-0" style="background: linear-gradient(135deg, #ef4444, #b91c1c); color: white;">
                <div class="card-body d-flex align-items-center p-4">
                    <div class="me-3 bg-white bg-opacity-25 rounded-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="bi bi-shield-lock fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-white-50 mb-1 fw-medium">Terkunci (Banned)</h6>
                        <h2 class="mb-0 fw-bold" x-text="bannedCount">0</h2>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Grid -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent py-3">
            <h5 class="mb-0 fw-bold"><i class="bi bi-people-fill text-primary me-2"></i>Daftar Peserta Ujian</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 border-top-0">
                    <thead style="background: rgba(0,0,0,0.02);">
                        <tr>
                            <th class="ps-4 border-0 text-secondary fw-medium py-3">Siswa</th>
                            <th class="border-0 text-secondary fw-medium py-3">Status</th>
                            <th class="border-0 text-secondary fw-medium py-3">Peringatan (Strikes)</th>
                            <th class="text-end pe-4 border-0 text-secondary fw-medium py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <template x-for="student in students" :key="student.user_id">
                            <tr :class="{ 'table-danger': student.flashing, 'table-warning': student.banned, 'table-success': student.status === ((window.APP_CONFIG||{}).status||{}).finished }">
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
                                            'bg-info': student.status === ((window.APP_CONFIG||{}).status||{}).finished
                                          }">
                                        <i class="bi" :class="student.status === ((window.APP_CONFIG||{}).status||{}).finished ? 'bi-check-circle-fill' : (student.is_online ? 'bi-wifi' : 'bi-wifi-off')"></i> 
                                        <span x-text="student.banned ? 'Terkunci' : (student.status === ((window.APP_CONFIG||{}).status||{}).finished ? 'Selesai (Auto-Submit)' : (student.is_online ? 'Online' : 'Offline'))"></span>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-dark" x-text="student.strikes + ' / 2'"></span>
                                </td>
                                <td class="text-end pe-4 border-0 text-secondary fw-medium py-3">
                                    <?php if(session('role') === 'admin'): ?>
                                    <a :href="'<?= base_url('admin/suspend') ?>?search=' + encodeURIComponent(student.username)" class="btn btn-sm btn-outline-danger" title="Tindak Lanjut / Blokir via Suspend">
                                        <i class="bi bi-shield-lock"></i> Suspend Menu
                                    </a>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-warning text-dark" @click="openReportModal(student)" title="Laporkan Siswa ke Admin">
                                        <i class="bi bi-flag-fill"></i> Lapor Admin
                                    </button>
                                    <?php endif; ?>
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

    <!-- Modal Lapor Admin -->
    <div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true" x-ref="reportModal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 16px;">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold" id="reportModalLabel"><i class="bi bi-flag text-warning me-2"></i>Lapor Siswa ke Admin</h5>
                    <button type="button" class="btn-close" @click="closeReportModal()"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Siswa terpilih: <strong x-text="reportData.studentName"></strong> (<span x-text="reportData.studentUsername"></span>)</p>
                    <div class="mb-3">
                        <label class="form-label fw-medium text-secondary small">Saran Tindakan</label>
                        <select class="form-select border-0 bg-light" x-model="reportData.action">
                            <option value="ban">Kunci Ujian (Ban / Suspend)</option>
                            <option value="reset">Hapus Ujian (Reset Attempt)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium text-secondary small">Alasan Kecurangan / Pelanggaran</label>
                        <textarea class="form-control border-0 bg-light" rows="3" x-model="reportData.reason" placeholder="Contoh: Siswa terlihat membuka buku, sering alt-tab, dll..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" @click="closeReportModal()">Batal</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" @click="submitReport()" :disabled="!reportData.reason.trim() || isSubmittingReport">
                        <span x-show="!isSubmittingReport">Kirim Laporan</span>
                        <span x-show="isSubmittingReport">Mengirim...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const APP_CFG = window.APP_CONFIG || {};
function proctorLiveDashboard() {
    return {
        get wsUrl() {
            let url = '<?= esc($wsUrl ?? '') ?>';
            if (!url) url = APP_CFG.websocket_url || '';
            if (!url || url.includes('localhost')) {
                const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                const host = window.location.host;
                url = `${protocol}//${host}/ws`;
            }
            return url.replace(/\/+$/, '') + '/?proctor_token=<?= esc($proctorToken) ?>';
        },
        ws: null,
        isConnected: false,
        reconnectTimer: null,
        students: [],
        audioEnabled: false,
        audioCtx: null,
        reportModalInstance: null,
        isSubmittingReport: false,
        reportData: {
            userId: null,
            studentName: '',
            studentUsername: '',
            action: 'ban',
            reason: ''
        },
        
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
                banned: parseInt(a.status) === ((window.APP_CONFIG||{}).status||{}).banned, // banned status
                status: parseInt(a.status),
                flashing: false
            }));

            // Initialize bootstrap modal
            this.reportModalInstance = new bootstrap.Modal(this.$refs.reportModal);

            this.connectWebSocket();
        },

        connectWebSocket() {
            try {
                this.ws = new WebSocket(this.wsUrl);

                this.ws.onopen = () => {
                    this.isConnected = true;
                    if(this.reconnectTimer) clearInterval(this.reconnectTimer);
                };

                this.ws.onmessage = (e) => {
                    const res = JSON.parse(e.data);
                    if (res.event === 'heartbeat') {
                        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                            this.ws.send(JSON.stringify({event: 'pong'}));
                        }
                        return;
                    }
                    this.handleWebSocketEvent(res);
                };

                this.ws.onclose = () => {
                    this.isConnected = false;
                    this.reconnectTimer = setTimeout(() => this.connectWebSocket(), APP_CFG.proctor_reconnect_ms || 3000);
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
                // Nested data (from Redis publish → broadcastToProctors)
                const realEvent = data.event;
                const innerData = data.data || data;

                if (realEvent === 'ban') {
                    this.triggerCheatAlert(data.user_id);
                } else if (realEvent === 'auto_submit') {
                    this.triggerAutoSubmitAlert(data.user_id, data.reason);
                } else if (realEvent === 'proctor_report_alert') {
                    this.handleProctorReport(innerData);
                }
            } else if (event === 'proctor_report_alert') {
                // Direct broadcast (from broadcastEvent else-block)
                const reportData = data.data || data;
                this.handleProctorReport(reportData);
            }
        },

        handleProctorReport(reportData) {
            <?php if(session('role') === 'admin'): ?>
            this.playBeep(true);
            Swal.fire({
                title: 'Laporan Pengawas!',
                html: `Pengawas <strong>${reportData.proctor_name || 'N/A'}</strong> menyarankan tindakan <strong>${(reportData.suggested_action || 'N/A').toUpperCase()}</strong> untuk siswa <strong>${reportData.student_username || 'N/A'}</strong>.<br><br>Alasan: <br><span class="text-danger">"${reportData.reason || 'N/A'}"</span>`,
                icon: 'warning',
                showConfirmButton: true,
                confirmButtonText: '<i class="bi bi-shield-lock"></i> Buka Suspend Menu',
                showCancelButton: true,
                cancelButtonText: 'Tutup'
            }).then((result) => {
                if(result.isConfirmed) {
                    window.location.href = '<?= base_url('admin/suspend') ?>?search=' + encodeURIComponent(reportData.student_username || '');
                }
            });
            <?php endif; ?>
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
                this.playBeep();
                
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
                this.playBeep();
                
                setTimeout(() => {
                    student.flashing = false;
                }, 4000);
            }
        },

        openReportModal(student) {
            this.reportData.userId = student.user_id;
            this.reportData.studentName = student.name;
            this.reportData.studentUsername = student.username;
            this.reportData.action = 'ban';
            this.reportData.reason = '';
            this.reportModalInstance.show();
        },

        closeReportModal() {
            this.reportModalInstance.hide();
        },

        submitReport() {
            if(!this.reportData.reason.trim()) return;
            
            this.isSubmittingReport = true;
            
            const csrfHeader = document.querySelector('meta[name="csrf-header"]').getAttribute('content');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            
            const params = new URLSearchParams();
            params.append('user_id', this.reportData.userId);
            params.append('test_id', <?= esc($test->id) ?>);
            params.append('student_username', this.reportData.studentUsername);
            params.append('action', this.reportData.action);
            params.append('reason', this.reportData.reason);
            params.append('<?= csrf_token() ?>', csrfToken);
            
            fetch(`<?= base_url('proctor/live/report-student') ?>`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    [csrfHeader]: csrfToken
                },
                body: params.toString()
            }).then(r => r.json()).then(res => {
                this.isSubmittingReport = false;
                if(res.status === 'success') {
                    this.closeReportModal();
                    Swal.fire({
                        title: 'Terkirim',
                        text: 'Laporan berhasil dikirim ke Admin.',
                        icon: 'success',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000
                    });
                } else {
                    Swal.fire({
                        title: 'Gagal Mengirim',
                        text: res.message || 'Gagal mengirim laporan.',
                        icon: 'error',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000
                    });
                }
            }).catch(() => {
                this.isSubmittingReport = false;
                Swal.fire({
                    title: 'Gagal Mengirim',
                    text: 'Terjadi kesalahan jaringan.',
                    icon: 'error',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000
                });
            });
        },

        toggleAudio() {
            this.audioEnabled = !this.audioEnabled;
            if (this.audioEnabled) {
                // Initialize context on user gesture to bypass browser autoplay blocks
                if (!this.audioCtx) {
                    this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (this.audioCtx.state === 'suspended') {
                    this.audioCtx.resume();
                }
                // Play short confirm sound
                this.playBeep(true);
            }
        },

        playBeep(isConfirm = false) {
            if (!this.audioEnabled || !this.audioCtx) return;
            
            try {
                const osc = this.audioCtx.createOscillator();
                const gainNode = this.audioCtx.createGain();
                
                osc.connect(gainNode);
                gainNode.connect(this.audioCtx.destination);
                
                if (isConfirm) {
                    // Short soft beep for toggle on
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(600, this.audioCtx.currentTime);
                    gainNode.gain.setValueAtTime(0, this.audioCtx.currentTime);
                    gainNode.gain.linearRampToValueAtTime(0.1, this.audioCtx.currentTime + 0.05);
                    gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + 0.1);
                    osc.start(this.audioCtx.currentTime);
                    osc.stop(this.audioCtx.currentTime + 0.1);
                } else {
                    // Loud alert double beep
                    osc.type = 'square';
                    osc.frequency.setValueAtTime(800, this.audioCtx.currentTime);
                    osc.frequency.setValueAtTime(1200, this.audioCtx.currentTime + 0.1);
                    
                    gainNode.gain.setValueAtTime(0, this.audioCtx.currentTime);
                    gainNode.gain.linearRampToValueAtTime(0.2, this.audioCtx.currentTime + 0.05);
                    gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + 0.2);
                    
                    gainNode.gain.linearRampToValueAtTime(0.2, this.audioCtx.currentTime + 0.3);
                    gainNode.gain.linearRampToValueAtTime(0, this.audioCtx.currentTime + 0.5);
                    
                    osc.start(this.audioCtx.currentTime);
                    osc.stop(this.audioCtx.currentTime + 0.6);
                }
            } catch (e) {
                console.error("Audio playback failed", e);
            }
        }

    }
}
</script>
<?= $this->endSection() ?>

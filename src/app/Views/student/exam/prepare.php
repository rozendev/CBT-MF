<?= $this->extend('layouts/student') ?>

<?= $this->section('page_title') ?>Persiapan Ujian: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-8 mt-4">
        <div class="card shadow border-0 rounded-4 overflow-hidden">
            
            <!-- Header Card -->
            <div class="bg-primary text-white p-4 text-center">
                <i class="bi bi-file-earmark-text display-4 mb-2 d-block opacity-75"></i>
                <h3 class="fw-bold mb-1"><?= esc($test->name) ?></h3>
                <p class="mb-0 text-white-50">Sistem Ujian Online</p>

                <?php if (!empty($test->auto_submit_on_cheat)): ?>
                <!-- Step Indicator Wizard -->
                <div class="d-flex justify-content-center align-items-center gap-3 mt-4" id="wizardHeader">
                    <div class="d-flex align-items-center gap-2">
                        <span id="badgeStep1" class="badge rounded-circle p-2 bg-white text-primary fw-bold" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">1</span>
                        <span id="titleStep1" class="small fw-semibold text-white">Petunjuk Ujian</span>
                    </div>
                    <i class="bi bi-chevron-right text-white-50 small"></i>
                    <div class="d-flex align-items-center gap-2">
                        <span id="badgeStep2" class="badge rounded-circle p-2 bg-primary-subtle text-white-50" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">2</span>
                        <span id="titleStep2" class="small fw-semibold text-white-50">Kesiapan Perangkat</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="card-body p-4 p-md-5">
                <?php if (session()->has('error')): ?>
                    <div class="alert alert-danger rounded-3 mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= session('error') ?>
                    </div>
                <?php endif; ?>

                <form action="<?= base_url('/student/exam/start/' . $test->id) ?>" method="POST" id="startForm">
                    <?= csrf_field() ?>

                    <!-- ═══════════════════════════════════════════════════════ -->
                    <!--  STEP 1: PETUNJUK & INFORMASI UJIAN                    -->
                    <!-- ═══════════════════════════════════════════════════════ -->
                    <div id="step1Container">
                        <div class="row mb-4 g-4 text-center">
                            <div class="col-sm-4">
                                <div class="p-3 bg-light rounded-3">
                                    <i class="bi bi-clock fs-3 text-primary mb-2 d-block"></i>
                                    <div class="small text-muted mb-1">Durasi</div>
                                    <div class="fw-bold"><?= $test->duration_minutes > 0 ? $test->duration_minutes . ' Menit' : 'Tanpa Batas' ?></div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="p-3 bg-light rounded-3">
                                    <i class="bi bi-journal-check fs-3 text-success mb-2 d-block"></i>
                                    <div class="small text-muted mb-1">Batas Lulus</div>
                                    <div class="fw-bold"><?= $test->passing_score ?> / <?= $test->max_score ?></div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="p-3 bg-light rounded-3">
                                    <i class="bi bi-arrow-repeat fs-3 text-warning mb-2 d-block"></i>
                                    <div class="small text-muted mb-1">Pengulangan</div>
                                    <div class="fw-bold"><?= $test->is_repeatable ? 'Diizinkan' : 'Satu Kali' ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">Petunjuk Ujian</h5>
                            <div class="text-muted" style="line-height: 1.7;">
                                <?= empty($test->description) ? '<p>Tidak ada petunjuk khusus untuk ujian ini.</p>' : $test->description ?>
                            </div>
                        </div>

                        <?php if (!empty($test->password)): ?>
                            <div class="mb-4 bg-light p-3 rounded-3 border border-warning">
                                <label class="form-label fw-bold text-dark"><i class="bi bi-lock-fill me-1 text-warning"></i> Masukkan Password Ujian</label>
                                <input type="password" class="form-control form-control-lg" name="password" required placeholder="Ketik password ujian...">
                                <div class="form-text mt-2 text-muted">Ujian ini dilindungi oleh password. Tanyakan kepada pengawas jika Anda belum mengetahuinya.</div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($test->auto_submit_on_cheat)): ?>
                            <div class="alert alert-danger border-danger rounded-3 mb-4">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-shield-exclamation fs-3 text-danger flex-shrink-0"></i>
                                    <div>
                                        <strong class="d-block text-danger mb-1">Mode Auto-Submit Terdeteksi Curang Aktif</strong>
                                        <span class="small text-dark">Ujian ini menerapkan sistem pengumpulan otomatis jika Anda terdeteksi keluar dari ujian walau hanya 1 kali. Tekan tombol <strong>"Lanjutkan"</strong> untuk memeriksa kesiapan perangkat Anda.</span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-primary btn-lg rounded-pill fw-bold" id="btnGoToStep2">
                                    Lanjutkan ke Kesiapan Perangkat <i class="bi bi-arrow-right-circle-fill ms-2"></i>
                                </button>
                                <a href="<?= base_url('/student/dashboard') ?>" class="btn btn-light rounded-pill mt-2">Batal dan Kembali</a>
                            </div>
                        <?php else: ?>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold" id="btnStart" onclick="this.innerHTML='<i class=\'bi bi-hourglass-split me-2\'></i>Mempersiapkan Soal...'; this.classList.add('disabled'); document.getElementById('startForm').submit();">
                                    <i class="bi bi-play-circle-fill me-2"></i> Mulai Kerjakan Ujian Sekarang
                                </button>
                                <a href="<?= base_url('/student/dashboard') ?>" class="btn btn-light rounded-pill mt-2">Batal dan Kembali</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════ -->
                    <!--  STEP 2: CEK KESIAPAN PERANGKAT & ADVICE BERSAHABAT     -->
                    <!-- ═══════════════════════════════════════════════════════ -->
                    <?php if (!empty($test->auto_submit_on_cheat)): ?>
                    <div id="step2Container" style="display: none;">
                        <h5 class="fw-bold text-dark border-bottom pb-2 mb-3">
                            <i class="bi bi-sliders text-primary me-2"></i>Checklist Kesiapan Perangkat
                        </h5>
                        <p class="text-muted small mb-4">Pastikan pengaturan perangkat Anda sudah sesuai agar ujian berjalan lancar tanpa terputus secara tidak sengaja.</p>

                        <div class="vstack gap-3 mb-4">
                            <!-- Checklist Item 1 -->
                            <div class="p-3 bg-light rounded-3 border border-secondary-subtle">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="bi bi-bell-slash fs-3 text-primary flex-shrink-0"></i>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-1">1. Aktifkan Mode Jangan Ganggu (Do Not Disturb / DND)</h6>
                                        <p class="text-muted small mb-0">Aktifkan mode DND/Silent di HP atau komputer Anda. Pop-up notifikasi masuk dari WhatsApp, Email, atau medsos dapat memicu perpindahan layar dan terdeteksi pelanggaran oleh sistem.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Checklist Item 2 -->
                            <div class="p-3 bg-light rounded-3 border border-secondary-subtle">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="bi bi-display fs-3 text-warning flex-shrink-0"></i>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-1">2. Atur Timeout Mati Layar Ke Paling Maksimal</h6>
                                        <p class="text-muted small mb-0">Setel batas waktu mati layar (screen timeout) ke durasi paling lama (misal 10–30 menit) dan matikan penghemat daya. Cegah layar mati sendiri saat Anda sedang berpikir mengerjakan soal.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Checklist Item 3 -->
                            <div class="p-3 bg-light rounded-3 border border-secondary-subtle">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="bi bi-wifi fs-3 text-success flex-shrink-0"></i>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-1">3. Pastikan Daya Baterai & Koneksi Stabil</h6>
                                        <p class="text-muted small mb-0">Pastikan baterai cukup dan Anda terhubung ke koneksi internet yang stabil selama ujian berlangsung.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 🚨 IRREVERSIBLE WARNING BANNER -->
                        <div class="p-4 rounded-4 mb-4" style="background: rgba(220, 53, 69, 0.05); border: 2px solid #dc3545;">
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px;">
                                    <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-danger mb-2">PERINGATAN MUTLAK: PROSES AUTO-SUBMIT BERSIFAT IRREVERSIBLE (TIDAK DAPAT DIBATALKAN)</h6>
                                    <p class="text-dark small mb-2" style="line-height: 1.7;">
                                        Sekali sistem mendeteksi kecurangan (berpindah tab, me-minimize browser, keluar layar penuh), ujian Anda akan <strong>LANGSUNG DISUBMIT DAN DINILAI SECARA PERMANEN</strong> oleh server saat itu juga.
                                    </p>
                                    <div class="p-3 bg-white rounded-3 border border-danger-subtle text-danger-emphasis small" style="line-height: 1.6;">
                                        <i class="bi bi-x-octagon-fill me-1"></i>
                                        <strong>Pilihan Anda Setelah Ter-Submit:</strong>
                                        <ul class="mb-1 mt-1 ps-3">
                                            <li><strong>Menerima skor apa adanya</strong> yang terhitung pada saat detik kecurangan terdeteksi, ATAU</li>
                                            <li><strong>Mengulang ujian dari awal</strong> (hanya jika ujian diizinkan diulang oleh kebijakan pengawas/sekolah).</li>
                                        </ul>
                                        <span class="d-block mt-2 text-danger fw-bold"><i class="bi bi-shield-x me-1"></i> Tidak ada tombol "Batal Submit" atau pemulihan sesi di tengah jalan. Keputusan server bersifat final!</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 💡 FRIENDLY ADVICE BOX (Nasihat Bersahabat bagi yang Penasaran) -->
                        <div class="p-4 rounded-4 mb-4" style="background: rgba(13, 110, 253, 0.05); border: 2px dashed #0d6efd;">
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 42px; height: 42px;">
                                    <i class="bi bi-lightbulb-fill fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-primary mb-2">Tips & Nasihat Bersahabat Bagi Siswa yang Penasaran</h6>
                                    <p class="text-dark small mb-2" style="line-height: 1.7;">
                                        Jika Anda masih merasa penasaran atau ingin tahu bagaimana cara kerja deteksi Anti-Cheat ini, 
                                        <strong>sangat disarankan untuk mengujinya langsung pada 5 detik pertama begitu ujian baru saja dimulai</strong> 
                                        (misalnya mencoba minimize browser atau pindah tab sebentar).
                                    </p>
                                    <div class="p-2 bg-white rounded-3 border border-primary-subtle text-primary-emphasis small">
                                        <i class="bi bi-info-circle-fill me-1"></i>
                                        <strong>Mengapa di awal?</strong> Jika Anda menguji di 5 detik pertama saat baru mulai, Anda belum kehilangan banyak waktu atau tenaga pengerjaan. <u>Jangan mencoba mengujinya di pertengahan atau akhir ujian</u> saat Anda sudah capek menjawab banyak soal, karena jika ter-auto submit saat itu, skor Anda akan langsung dikumpulkan!
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold" id="btnStart" onclick="this.innerHTML='<i class=\'bi bi-hourglass-split me-2\'></i>Mempersiapkan Soal...'; this.classList.add('disabled'); document.getElementById('startForm').submit();">
                                <i class="bi bi-play-circle-fill me-2"></i> Saya Paham & Mulai Kerjakan Ujian Sekarang
                            </button>
                            <button type="button" class="btn btn-light rounded-pill mt-1" id="btnBackToStep1">
                                <i class="bi bi-arrow-left me-1"></i> Kembali ke Petunjuk Ujian
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                </form>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnGoToStep2 = document.getElementById('btnGoToStep2');
    const btnBackToStep1 = document.getElementById('btnBackToStep1');
    const step1 = document.getElementById('step1Container');
    const step2 = document.getElementById('step2Container');
    const badge1 = document.getElementById('badgeStep1');
    const badge2 = document.getElementById('badgeStep2');
    const title1 = document.getElementById('titleStep1');
    const title2 = document.getElementById('titleStep2');

    if (btnGoToStep2) {
        btnGoToStep2.addEventListener('click', function(e) {
            e.preventDefault();

            // Validate password if required
            const passwordInput = document.querySelector('input[name="password"]');
            if (passwordInput && !passwordInput.checkValidity()) {
                passwordInput.reportValidity();
                return;
            }

            if (step1 && step2) {
                step1.style.display = 'none';
                step2.style.display = 'block';
            }

            if (badge1 && badge2) {
                badge1.className = 'badge rounded-circle p-2 bg-primary-subtle text-white-50';
                if (title1) title1.className = 'small fw-semibold text-white-50';

                badge2.className = 'badge rounded-circle p-2 bg-white text-primary fw-bold';
                if (title2) title2.className = 'small fw-semibold text-white';
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    if (btnBackToStep1) {
        btnBackToStep1.addEventListener('click', function(e) {
            e.preventDefault();

            if (step1 && step2) {
                step2.style.display = 'none';
                step1.style.display = 'block';
            }

            if (badge1 && badge2) {
                badge2.className = 'badge rounded-circle p-2 bg-primary-subtle text-white-50';
                if (title2) title2.className = 'small fw-semibold text-white-50';

                badge1.className = 'badge rounded-circle p-2 bg-white text-primary fw-bold';
                if (title1) title1.className = 'small fw-semibold text-white';
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
});
</script>
<?= $this->endSection() ?>

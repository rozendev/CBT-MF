<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Koreksi Cepat: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    /* Teks soal esai bisa panjang. Tanpa batas, select ini melar melebihi
       ruang yang ada dan teksnya terpotong tepat di bawah panah dropdown —
       terbaca seolah judul soalnya memang berhenti di tengah kata. Dibatasi
       lalu diberi ellipsis supaya pemotongannya terlihat sebagai pemotongan;
       teks utuhnya tetap bisa dibaca lewat tooltip dan saat dropdown dibuka. */
    .pemilih-soal {
        flex: 1 1 20rem;
        min-width: 0;
        max-width: 46rem;
        text-overflow: ellipsis;
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div x-data="gradeApp()" @keydown.window="onKey($event)" class="pb-5">

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4 bg-light rounded-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-1"><i class="bi bi-lightning-charge me-2 text-primary"></i>Koreksi Cepat</h5>
                <p class="text-muted mb-0"><?= esc($test->name) ?></p>
            </div>
            <a href="<?= base_url('/admin/results/view/' . $test->id) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Daftar Nilai</a>
        </div>
    </div>

    <div class="alert alert-danger" x-show="loadError" x-text="loadError"></div>

    <div class="card shadow-sm" x-show="loaded && !loadError">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <select x-model.number="currentQuestionId" @change="loadQuestion()"
                        class="form-select pemilih-soal" aria-label="Pilih soal"
                        :title="labelSoalTerpilih()">
                    <template x-for="q in questions" :key="q.id">
                        <option :value="q.id" x-text="'[' + q.pending + ' belum] ' + q.label"></option>
                    </template>
                </select>
                <span class="badge bg-primary fs-6" x-text="gradedCount() + '/' + students.length + ' terkoreksi'"></span>
            </div>
            <div class="alert alert-success mt-3 mb-0 py-2" x-show="allDone()">
                Semua esai sudah dikoreksi — nilai tetap bisa disesuaikan.
            </div>
        </div>

        <div class="card-body p-4" x-show="current">
            <template x-if="current">
                <div>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h6 class="m-0 fw-bold">
                            <span x-text="current.name"></span>
                            <span class="text-muted fw-normal" x-text="'(NIS ' + (current.nis || '-') + ')'"></span>
                            <span class="badge ms-2"
                                  :class="current._failed ? 'bg-danger' : (current.score === null ? 'bg-primary' : 'bg-success')"
                                  x-text="current._failed ? 'gagal simpan' : (current.score === null ? 'belum dinilai' : 'sudah dinilai')"></span>
                        </h6>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="prevStudent()" :disabled="qIndex <= 0">‹</button>
                            <span class="btn btn-sm btn-light disabled" x-text="(qIndex + 1) + ' / ' + students.length"></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="nextStudent()" :disabled="qIndex >= students.length - 1">›</button>
                        </div>
                    </div>

                    <div class="border rounded-3 p-3 mb-3">
                        <div class="text-muted small fw-bold mb-1">Jawaban Siswa</div>
                        <p class="mb-0" style="white-space: pre-wrap; min-height: 96px;" x-text="current.answer || 'Tidak diisi'"></p>
                    </div>

                    <div class="text-muted small mb-3" x-show="current.key">
                        <i class="bi bi-key me-1"></i>Kunci: <span x-text="current.key"></span>
                    </div>

                    <div class="text-center py-3 border rounded-3"
                         :class="saving ? 'bg-warning bg-opacity-10' : (current._failed ? 'bg-danger bg-opacity-10' : '')">
                        <div class="display-6 fw-bold" x-text="current.score === null ? '—' : current.score"></div>
                        <div class="text-muted small">dari maksimum <span x-text="maxPoints"></span> poin</div>
                        <div class="text-danger small mt-2" x-show="current._failed"
                             x-text="saveError + ' — tekan ulang aksinya.'"></div>
                    </div>

                    <div class="text-center text-muted small mt-3">
                        <i class="bi bi-keyboard me-1"></i>↑ penuh · ↓ nol · 1–9 parsial (%) · ←/→ pindah siswa · U urungkan
                    </div>
                </div>
            </template>
        </div>

        <div class="card-body text-center text-muted py-5" x-show="loaded && students.length === 0">
            Belum ada siswa yang menyelesaikan ujian ini dengan soal tersebut.
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<!-- Alpine.js: layouts/admin tidak memuatnya, jadi tiap halaman yang
     memakai x-data wajib menyertakan sendiri. Dipakai berkas vendor lokal
     (bukan CDN) agar halaman admin tetap hidup di server sekolah yang
     jaringan luarnya diblokir saat ujian berlangsung. -->
<script defer src="<?= base_url('vendor/alpinejs/alpine.min.js?v=3.14.8') ?>"></script>
<script>
function gradeApp() {
    return {
        csrfToken: '<?= csrf_hash() ?>',
        baseUrl: '<?= base_url('/admin/results') ?>',
        testId: <?= (int) $test->id ?>,
        initialQuestionId: <?= (int) $questionId ?>,

        loaded: false,
        loadError: '',
        saving: false,
        saveError: '',
        lastAction: null,

        questions: [],
        students: [],
        maxPoints: 0,
        currentQuestionId: null,
        qIndex: 0,

        async init() {
            await this.loadQuestion(this.initialQuestionId);
        },

        get current() { return this.students[this.qIndex] ?? null; },

        gradedCount() { return this.students.filter(s => s.score !== null).length; },
        allDone() { return this.students.length > 0 && this.gradedCount() === this.students.length; },

        // Judul lengkap untuk tooltip select — labelnya sendiri sudah dipotong
        // di layar, jadi teks penuhnya perlu tetap bisa dibaca.
        labelSoalTerpilih() {
            return this.questions.find(q => q.id === this.currentQuestionId)?.label ?? '';
        },

        async loadQuestion(questionId = null) {
            const id = questionId ?? this.currentQuestionId;
            this.loadError = '';
            try {
                const res = await fetch(`${this.baseUrl}/grade-data/${this.testId}/${id}`, {
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.message || 'Gagal memuat data.');
                this.questions = data.questions ?? [];
                this.students = data.students.map(s => ({ ...s, _failed: false }));
                this.maxPoints = parseFloat(data.question.max_points) || 0;
                this.currentQuestionId = parseInt(data.question.id, 10);
                const firstPending = this.students.findIndex(s => s.score === null);
                this.qIndex = firstPending >= 0 ? firstPending : 0;
                this.lastAction = null;
                this.loaded = true;
            } catch (e) {
                this.loadError = e.message || 'Gagal memuat data koreksi.';
                this.loaded = true;
            }
        },

        async applyScore(value) {
            const s = this.current;
            if (!s || this.saving) return;
            this.saving = true;
            this.saveError = '';
            const prev = s.score;
            try {
                const body = new FormData();
                body.append('log_id', s.log_id);
                body.append('score', value);
                await this.post(body);
                s.score = value;
                s._failed = false;
                this.lastAction = { log_id: s.log_id, prev };
                this.advance();
            } catch (e) {
                s._failed = true;
                this.saveError = e.message || 'Gagal menyimpan.';
            } finally {
                this.saving = false;
            }
        },

        // Setelah simpan, lompat ke siswa berikutnya yang BELUM dinilai.
        // Kalau tak ada lagi, lanjut index+1 biasa supaya review tetap urut.
        advance() {
            let next = -1;
            for (let i = this.qIndex + 1; i < this.students.length; i++) {
                if (this.students[i].score === null && !this.students[i]._failed) { next = i; break; }
            }
            if (next < 0) next = Math.min(this.qIndex + 1, this.students.length - 1);
            this.qIndex = next;
        },

        prevStudent() { if (this.qIndex > 0) this.qIndex--; },
        nextStudent() { if (this.qIndex < this.students.length - 1) this.qIndex++; },

        // Undo satu langkah: kembalikan simpanan terakhir ke nilai semula
        // ('' = NULL = belum dikoreksi), lalu kursor balik ke siswa itu.
        async undo() {
            const action = this.lastAction;
            if (!action || this.saving) return;
            const idx = this.students.findIndex(s => s.log_id === action.log_id);
            if (idx < 0) { this.lastAction = null; return; }
            const s = this.students[idx];
            this.saving = true;
            try {
                const body = new FormData();
                body.append('log_id', s.log_id);
                body.append('score', action.prev === null ? '' : action.prev);
                await this.post(body);
                s.score = action.prev;
                s._failed = false;
                this.lastAction = null;
                this.qIndex = idx;
            } catch (e) {
                s._failed = true;
                this.saveError = e.message || 'Gagal mengembalikan nilai.';
            } finally {
                this.saving = false;
            }
        },

        async post(body) {
            const res = await fetch(`${this.baseUrl}/grade-save`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || 'Permintaan gagal.');
            return data;
        },

        onKey(e) {
            if (!this.loaded || !this.current || this.saving) return;
            const tag = (e.target.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            // preventDefault hanya untuk tombol yang memang kita tangani,
            // supaya panah tidak menggulir halaman dan ketikan normal di
            // elemen lain tidak ikut terblokir.
            switch (e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    if (this.maxPoints > 0) this.applyScore(this.maxPoints);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    this.applyScore(0);
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    this.prevStudent();
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    this.nextStudent();
                    break;
                case 'u':
                case 'U':
                    e.preventDefault();
                    this.undo();
                    break;
                default:
                    if (/^[1-9]$/.test(e.key) && this.maxPoints > 0) {
                        e.preventDefault();
                        this.applyScore(Math.round(this.maxPoints * (parseInt(e.key, 10) / 10) * 100) / 100);
                    }
            }
        },
    };
}
</script>
<?= $this->endSection() ?>

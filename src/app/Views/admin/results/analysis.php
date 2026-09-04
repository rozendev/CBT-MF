<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Analisis Butir: <?= esc($test->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    /* layouts/admin tidak menyediakan aturan ini; tanpa x-cloak, blok yang
       kondisional sempat berkedip sebelum Alpine sempat jalan. */
    [x-cloak] { display: none !important; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div x-data="analysisApp()" class="pb-5">

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4 bg-light rounded-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold text-dark mb-1"><i class="bi bi-clipboard-data me-2 text-primary"></i>Analisis Butir Soal</h5>
                <p class="text-muted mb-0"><?= esc($test->name) ?></p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= base_url('/admin/results/analysis-export/' . $test->id) ?>"
                   class="btn btn-success" x-show="loaded && !loadError && hasil.butir_dianalisis > 0">
                    <i class="bi bi-download me-1"></i>Unduh CSV
                </a>
                <a href="<?= base_url('/admin/results/view/' . $test->id) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Daftar Nilai
                </a>
            </div>
        </div>
    </div>

    <div class="text-center py-5" x-show="!loaded">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="text-muted mt-3 mb-0">Menghitung statistik butir…</p>
    </div>

    <div class="alert alert-danger" x-show="loadError" x-text="loadError" x-cloak></div>

    <template x-if="loaded && !loadError">
        <div>
            <!-- Ringkasan tes -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="card shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Peserta</div>
                        <div class="fs-3 fw-bold" x-text="hasil.peserta"></div>
                        <div class="text-muted small">attempt selesai</div>
                    </div></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Butir Dianalisis</div>
                        <div class="fs-3 fw-bold" x-text="hasil.butir_dianalisis"></div>
                        <div class="text-muted small" x-text="hasil.butir_dikeluarkan.length + ' dikeluarkan'"></div>
                    </div></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Rata-rata</div>
                        <div class="fs-3 fw-bold" x-text="fmt(hasil.ringkasan.rata_rata_persen, 1) + '%'"></div>
                        <div class="text-muted small">
                            <span x-text="fmt(hasil.ringkasan.rata_rata, 2)"></span> dari
                            <span x-text="hasil.ringkasan.skor_maksimum"></span> ·
                            SB <span x-text="fmt(hasil.ringkasan.simpangan_baku, 2)"></span>
                        </div>
                    </div></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase fw-bold">Reliabilitas (α)</div>
                        <template x-if="hasil.ringkasan.alpha !== null">
                            <div>
                                <div class="fs-3 fw-bold" x-text="fmt(hasil.ringkasan.alpha, 3)"></div>
                                <div class="text-muted small">
                                    <span x-text="hasil.ringkasan.alpha_label"></span>
                                    · SEM <span x-text="fmt(hasil.ringkasan.sem, 2)"></span>
                                </div>
                            </div>
                        </template>
                        <template x-if="hasil.ringkasan.alpha === null">
                            <div>
                                <div class="fs-3 fw-bold text-muted">—</div>
                                <div class="text-muted small" x-text="hasil.ringkasan.alpha_alasan"></div>
                            </div>
                        </template>
                    </div></div>
                </div>
            </div>

            <!-- Catatan batas keberlakuan -->
            <template x-for="(c, i) in hasil.catatan" :key="i">
                <div class="alert alert-warning py-2 small d-flex gap-2">
                    <i class="bi bi-info-circle mt-1"></i><span x-text="c"></span>
                </div>
            </template>

            <div class="alert alert-secondary py-2 small" x-show="hasil.butir_dianalisis === 0" x-cloak>
                Tidak ada butir yang bisa dianalisis untuk ujian ini.
            </div>

            <!-- Tabel butir -->
            <div class="card shadow-sm mb-4" x-show="hasil.butir_dianalisis > 0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="m-0 fw-bold text-primary"><i class="bi bi-list-ol me-2"></i>Statistik per Butir</h6>
                    <div class="d-flex align-items-center gap-2">
                        <select x-model="filter" class="form-select form-select-sm w-auto" style="min-width: 11rem;" aria-label="Saring menurut rekomendasi">
                            <option value="">Semua rekomendasi</option>
                            <option value="Tolak">Hanya Tolak</option>
                            <option value="Revisi">Hanya Revisi</option>
                            <option value="Terima">Hanya Terima</option>
                        </select>
                        <select x-model="urut" class="form-select form-select-sm w-auto" style="min-width: 13rem;" aria-label="Urutkan butir">
                            <option value="nomor">Urut nomor</option>
                            <option value="d">Urut daya beda terburuk</option>
                            <option value="p">Urut tersukar</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">No</th>
                                    <th>Soal</th>
                                    <th class="text-center" title="Proporsi peserta yang menguasai butir ini">P</th>
                                    <th class="text-center" title="Selisih kelompok atas dan bawah 27%">D</th>
                                    <th class="text-center" title="Korelasi butir dengan total sisanya">r</th>
                                    <th class="text-center">B / Sb / S / K</th>
                                    <th>Rekomendasi</th>
                                    <th class="text-center pe-3"></th>
                                </tr>
                            </thead>
                            <template x-for="b in tampil()" :key="b.question_id">
                                <tbody>
                                    <tr :class="b.saran === 'Tolak' ? 'table-danger' : (b.saran === 'Revisi' ? 'table-warning' : '')">
                                        <td class="ps-3 fw-bold" x-text="b.nomor"></td>
                                        <td style="min-width: 260px;">
                                            <div class="small" x-text="b.teks"></div>
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis mt-1" x-text="labelTipe(b.tipe)"></span>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold" x-text="fmt(b.p, 2)"></div>
                                            <div class="small text-muted" x-text="b.p_label"></div>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold" :class="warnaD(b.d)" x-text="b.d === null ? '—' : fmt(b.d, 2)"></div>
                                            <div class="small text-muted" x-text="b.d_label ?? 'tidak dihitung'"></div>
                                        </td>
                                        <td class="text-center" x-text="b.rpb === null ? '—' : fmt(b.rpb, 2)"></td>
                                        <td class="text-center small text-nowrap">
                                            <span class="text-success fw-bold" x-text="b.cacah.benar"></span> /
                                            <span x-text="b.cacah.sebagian"></span> /
                                            <span class="text-danger" x-text="b.cacah.salah"></span> /
                                            <span class="text-muted" x-text="b.cacah.kosong"></span>
                                        </td>
                                        <td style="min-width: 220px;">
                                            <span class="badge" :class="warnaSaran(b.saran)" x-text="b.saran"></span>
                                            <div class="small text-muted mt-1" x-text="b.alasan"></div>
                                        </td>
                                        <td class="text-center pe-3">
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    x-show="b.pengecoh.length > 0"
                                                    @click="buka = (buka === b.question_id ? null : b.question_id)">
                                                <i class="bi" :class="buka === b.question_id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr x-show="buka === b.question_id" x-cloak>
                                        <td colspan="8" class="bg-light">
                                            <div class="p-2">
                                                <div class="fw-bold small mb-2">Efektivitas pengecoh — soal <span x-text="b.nomor"></span></div>
                                                <table class="table table-sm mb-0 bg-white">
                                                    <thead>
                                                        <tr class="small">
                                                            <th>Opsi</th>
                                                            <th class="text-center">Dipilih</th>
                                                            <th class="text-center">%</th>
                                                            <th class="text-center">Kel. atas</th>
                                                            <th class="text-center">Kel. bawah</th>
                                                            <th>Catatan</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template x-for="(o, oi) in b.pengecoh" :key="oi">
                                                            <tr class="small">
                                                                <td>
                                                                    <i class="bi bi-key-fill text-success me-1" x-show="o.kunci" title="Kunci jawaban"></i>
                                                                    <span x-text="o.teks"></span>
                                                                </td>
                                                                <td class="text-center" x-text="o.jumlah"></td>
                                                                <td class="text-center" x-text="fmt(o.proporsi * 100, 1) + '%'"></td>
                                                                <td class="text-center" x-text="o.atas === null ? '—' : o.atas"></td>
                                                                <td class="text-center" x-text="o.bawah === null ? '—' : o.bawah"></td>
                                                                <td>
                                                                    <template x-for="(t, ti) in o.tanda" :key="ti">
                                                                        <span class="badge bg-warning-subtle text-warning-emphasis me-1" x-text="t"></span>
                                                                    </template>
                                                                </td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white small text-muted">
                    <strong>P</strong> tingkat kesukaran (proporsi penguasaan) ·
                    <strong>D</strong> daya beda kelompok atas−bawah 27% ·
                    <strong>r</strong> korelasi butir dengan total di luar butirnya ·
                    <strong>B/Sb/S/K</strong> benar / sebagian / salah / kosong.
                    Nilai D negatif hampir selalu berarti kunci jawaban perlu diperiksa.
                </div>
            </div>

            <!-- Butir yang dikeluarkan -->
            <div class="card shadow-sm" x-show="hasil.butir_dikeluarkan.length > 0" x-cloak>
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 fw-bold text-secondary"><i class="bi bi-exclamation-triangle me-2"></i>Butir di Luar Perhitungan</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th class="ps-3">No</th><th>Soal</th><th class="pe-3">Alasan</th></tr>
                        </thead>
                        <tbody>
                            <template x-for="b in hasil.butir_dikeluarkan" :key="b.question_id">
                                <tr>
                                    <td class="ps-3" x-text="b.nomor"></td>
                                    <td class="small" x-text="b.teks"></td>
                                    <td class="pe-3 small text-muted" x-text="b.alasan"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<!-- Alpine.js: layouts/admin tidak memuatnya, jadi tiap halaman yang
     memakai x-data wajib menyertakan sendiri. Dipakai berkas vendor lokal
     (bukan CDN) agar halaman admin tetap hidup di server sekolah yang
     jaringan luarnya diblokir saat ujian berlangsung. -->
<script defer src="<?= base_url('vendor/alpinejs/alpine.min.js?v=3.14.8') ?>"></script>
<script>
function analysisApp() {
    return {
        dataUrl: '<?= base_url('/admin/results/analysis-data/' . $test->id) ?>',
        csrfToken: '<?= csrf_hash() ?>',

        loaded: false,
        loadError: '',
        hasil: null,
        filter: '',
        urut: 'nomor',
        buka: null,

        async init() {
            try {
                const res = await fetch(this.dataUrl, {
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.status !== 'success') throw new Error(data.message || 'Gagal memuat analisis.');
                this.hasil = data.hasil;
            } catch (e) {
                this.loadError = e.message || 'Gagal memuat analisis butir.';
            } finally {
                this.loaded = true;
            }
        },

        // null dirender '—', bukan 'NaN' atau '0'. Angka yang tidak dihitung
        // tidak boleh menyamar jadi angka yang kebetulan nol.
        fmt(v, digits) {
            if (v === null || v === undefined || Number.isNaN(v)) return '—';
            return Number(v).toFixed(digits);
        },

        labelTipe(t) {
            return { 1: 'PG', 2: 'PG Kompleks', 3: 'Esai', 4: 'Menjodohkan', 5: 'Benar/Salah' }[t] ?? 'Lainnya';
        },

        warnaD(d) {
            if (d === null) return 'text-muted';
            if (d < 0) return 'text-danger';
            if (d < 0.20) return 'text-danger';
            if (d < 0.30) return 'text-warning';
            return 'text-success';
        },

        warnaSaran(s) {
            return { 'Terima': 'bg-success', 'Revisi': 'bg-warning text-dark', 'Tolak': 'bg-danger' }[s] ?? 'bg-secondary';
        },

        tampil() {
            let rows = this.hasil.butir.slice();
            if (this.filter) rows = rows.filter(b => b.saran === this.filter);

            if (this.urut === 'd') {
                // Butir yang daya bedanya tidak dihitung ditaruh paling akhir,
                // supaya tidak menyamar sebagai butir terburuk.
                rows.sort((a, b) => (a.d ?? 99) - (b.d ?? 99));
            } else if (this.urut === 'p') {
                rows.sort((a, b) => a.p - b.p);
            } else {
                rows.sort((a, b) => a.nomor - b.nomor);
            }

            return rows;
        },
    };
}
</script>
<?= $this->endSection() ?>

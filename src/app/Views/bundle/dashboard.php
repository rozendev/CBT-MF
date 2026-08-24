<?= view('bundle/_head', ['pageTitle' => 'Beranda', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl, 'school' => $school]) ?>
<body>
<?= view('bundle/_appbar', ['school' => $school]) ?>
<div class="k-idbar">
    <div class="k-idbar__who">
        <div class="k-idbar__name" id="whoName">&nbsp;</div>
        <div class="k-idbar__sub" id="whoSub">memuat identitas…</div>
    </div>
    <button class="k-btn k-btn--ghost k-btn--sm" id="btnLogout" type="button">Keluar</button>
</div>

<div class="k-wrap">
    <div class="k-pagehead">
        <h1>Daftar Ujian</h1>
        <p>Pilih ujian yang akan dikerjakan.</p>
    </div>
    <div id="list" class="k-stack">
        <div class="k-card k-muted">Memuat daftar ujian…</div>
    </div>
</div>

<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var list = document.getElementById('list');

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined && text !== null) n.textContent = text;
            return n;
        }

        // Waktu ditampilkan apa adanya dari server; klien tidak menghitung
        // ulang jendela ujian karena jam perangkat kiosk tidak bisa dipercaya.
        function jam(iso) {
            if (!iso) return '';
            var d = new Date(String(iso).replace(' ', 'T'));
            if (isNaN(d)) return String(iso);
            return d.toLocaleString('id-ID', {
                day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit'
            });
        }

        var BADGE = {
            open:    ['Dapat dikerjakan', 'var(--kiosk-ok)',      'var(--kiosk-ok-bg)'],
            resume:  ['Belum selesai',    'var(--kiosk-warn)',    '#fffbeb'],
            done:    ['Sudah dikerjakan', 'var(--kiosk-muted)',   'var(--kiosk-surface-2)'],
            locked:  ['Dikunci',          'var(--kiosk-danger)',  'var(--kiosk-danger-bg)'],
            not_yet: ['Belum dibuka',     'var(--kiosk-muted)',   'var(--kiosk-surface-2)'],
            closed:  ['Sudah berakhir',   'var(--kiosk-danger)',  'var(--kiosk-danger-bg)']
        };

        function badge(status) {
            var b = BADGE[status] || BADGE.open;
            var n = el('span', null, b[0]);
            n.style.cssText = 'display:inline-block;padding:3px 10px;border-radius:999px;' +
                'font-size:13px;font-weight:600;color:' + b[1] + ';background:' + b[2];
            return n;
        }

        // Nama dan petunjuk ujian berasal dari input admin/guru — nama selalu
        // lewat textContent. Petunjuk sengaja innerHTML karena memang ditulis
        // guru lewat editor (gambar, penebalan), sama seperti di web.
        function examCard(t) {
            var av = t.availability || { status: 'open', message: '' };
            var card = el('div', 'k-card');

            var head = el('div');
            head.style.cssText = 'display:flex;gap:8px;align-items:flex-start;justify-content:space-between';
            var title = el('h3', null, t.name);
            title.style.cssText = 'margin:0;flex:1;min-width:0';
            head.appendChild(title);
            head.appendChild(badge(av.status));
            card.appendChild(head);

            var meta = [];
            meta.push(t.duration_minutes ? 'Durasi ' + t.duration_minutes + ' menit' : 'Tanpa batas waktu');
            if (t.max_score) meta.push('Batas lulus ' + t.passing_score + '/' + t.max_score);
            meta.push(t.is_repeatable ? 'Boleh diulang' : 'Sekali kerjakan');
            if (t.password_required) meta.push('Perlu token');
            var metaEl = el('p', 'k-muted', meta.join(' · '));
            metaEl.style.margin = '8px 0 0';
            card.appendChild(metaEl);

            if (t.begin_time || t.end_time) {
                var jendela = 'Dibuka ' + (t.begin_time ? jam(t.begin_time) : 'kapan saja') +
                              ' · Ditutup ' + (t.end_time ? jam(t.end_time) : 'tanpa batas');
                var w = el('p', 'k-muted', jendela);
                w.style.cssText = 'margin:4px 0 0;font-size:13px';
                card.appendChild(w);
            }

            if (av.message) {
                var note = el('p', 'k-muted', av.message);
                note.style.cssText = 'margin:10px 0 0;font-size:14px';
                card.appendChild(note);
            }

            var btn = el('button', 'k-btn');
            btn.type = 'button';
            btn.style.marginTop = '14px';

            if (av.status === 'resume') {
                btn.textContent = 'Lanjutkan Ujian';
                btn.onclick = function () { location.href = 'exam.html?test_id=' + t.id + '&resume=1'; };
            } else if (av.status === 'done') {
                btn.className = 'k-btn k-btn--ghost';
                btn.textContent = 'Lihat Hasil';
                btn.onclick = function () { location.href = 'results.html?test_id=' + t.id; };
            } else if (av.status === 'open') {
                btn.textContent = 'Kerjakan';
                btn.onclick = function () { location.href = 'exam.html?test_id=' + t.id; };
            } else {
                // not_yet / closed / locked: tombolnya mati sejak awal, supaya
                // siswa tidak masuk lalu terjebak di layar galat tanpa jalan
                // keluar -- persis keluhan yang muncul di lapangan.
                btn.className = 'k-btn k-btn--ghost';
                btn.textContent = av.status === 'not_yet' ? 'Belum dapat dikerjakan' : 'Tidak dapat dikerjakan';
                btn.disabled = true;
            }
            card.appendChild(btn);
            return card;
        }

        function showError(msg, retry) {
            list.innerHTML = '';
            var box = el('div', 'k-note k-error', msg);
            list.appendChild(box);
            if (retry) {
                var b = el('button', 'k-btn');
                b.type = 'button';
                b.textContent = 'Coba Lagi';
                b.onclick = function () { location.reload(); };
                list.appendChild(b);
            }
        }

        fetch(base + '/api/student/exams', { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { location.href = 'login.html'; return; }

                var u = j.user || {};
                var full = ((u.firstname || '') + ' ' + (u.lastname || '')).trim();
                document.getElementById('whoName').textContent = full || u.username || 'Siswa';
                document.getElementById('whoSub').textContent = u.username
                    ? u.username + ' · bukan Anda? tekan Keluar'
                    : 'bukan Anda? tekan Keluar';

                // Attempt aktif TIDAK me-redirect otomatis: siswa harus sempat
                // memastikan dia login sebagai dirinya sendiri sebelum masuk
                // ujian. Status "lanjutkan" kini datang dari availability
                // bikinan server, bukan ditebak ulang di sini.

                list.innerHTML = '';
                if (!j.exams || !j.exams.length) {
                    list.appendChild(el('div', 'k-card k-muted', 'Belum ada ujian yang tersedia untuk Anda.'));
                    return;
                }
                j.exams.forEach(function (t) {
                    list.appendChild(examCard(t));
                });

                // Sesudah ujian selesai, kiosk sengaja TIDAK lepas sendiri. Siswa
                // yang sudah balik ke beranda tetap butuh jalan keluar yang jelas.
                if (window.CommsBridge && window.CBTKioskHasFinishedExam && window.CBTKioskHasFinishedExam()) {
                    var exitCard = el('div', 'k-card');
                    exitCard.appendChild(el('h3', null, 'Selesai mengerjakan?'));
                    exitCard.appendChild(el('p', 'k-muted', 'Keluar dari mode ujian dan kembalikan perangkat kepada pengawas.'));
                    var exitBtn = el('button', 'k-btn');
                    exitBtn.type = 'button';
                    exitBtn.textContent = 'Keluar dari Ujian';
                    exitBtn.onclick = function () {
                        if (!window.CBTKioskRequestExit || !window.CBTKioskRequestExit()) return;
                        exitBtn.disabled = true;
                        exitBtn.textContent = 'Melepas kunci ujian…';
                        window.addEventListener('exit_denied', function onDenied() {
                            window.removeEventListener('exit_denied', onDenied);
                            exitBtn.disabled = false;
                            exitBtn.textContent = 'Keluar dari Ujian';
                            exitCard.appendChild(el('div', 'k-note k-error', 'Kunci ujian belum bisa dilepas. Minta bantuan pengawas.'));
                        });
                    };
                    exitCard.appendChild(exitBtn);
                    list.appendChild(exitCard);
                }
            })
            .catch(function () {
                showError('Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.', true);
            });

        document.getElementById('btnLogout').addEventListener('click', function () {
            if (!confirm('Keluar dari akun ini?')) return;
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Keluar…';
            fetch(base + '/logout', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'kiosk-bundle' }
            }).then(function () {
                location.href = 'login.html';
            }).catch(function () {
                // Sesi server mungkin sudah hilang; tetap kembalikan ke login.
                location.href = 'login.html';
            });
        });
    })();
</script>
</body></html>

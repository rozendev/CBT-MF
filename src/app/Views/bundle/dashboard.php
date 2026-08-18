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

        // Nama ujian berasal dari input admin/guru — selalu lewat textContent,
        // jangan innerHTML, agar tidak bisa menyuntik markup ke halaman ujian.
        function examCard(t, isResume) {
            var card = el('div', 'k-card');
            card.appendChild(el('h3', null, t.name));

            var meta = [];
            if (t.duration_minutes) meta.push('Durasi ' + t.duration_minutes + ' menit');
            if (t.password_required) meta.push('Perlu token ujian');
            if (t.attempt_status === 3) meta.push('Sudah dikerjakan');
            else if (isResume) meta.push('Belum selesai');
            card.appendChild(el('p', 'k-muted', meta.join(' · ')));

            var btn = el('button', 'k-btn');
            btn.type = 'button';
            if (t.attempt_status === 3) {
                btn.className = 'k-btn k-btn--ghost';
                btn.textContent = 'Lihat Hasil';
                btn.onclick = function () { location.href = 'results.html?test_id=' + t.id; };
            } else if (isResume) {
                btn.textContent = 'Lanjutkan Ujian';
                btn.onclick = function () { location.href = 'exam.html?test_id=' + t.id + '&resume=1'; };
            } else {
                btn.textContent = 'Kerjakan';
                btn.onclick = function () { location.href = 'exam.html?test_id=' + t.id; };
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

                // Attempt aktif TIDAK lagi me-redirect otomatis: siswa harus sempat
                // memastikan dia login sebagai dirinya sendiri sebelum masuk ujian.
                var resumeId = j.active_attempt ? j.active_attempt.test_id : null;

                list.innerHTML = '';
                if (!j.exams || !j.exams.length) {
                    list.appendChild(el('div', 'k-card k-muted', 'Belum ada ujian yang tersedia untuk Anda.'));
                    return;
                }
                j.exams.forEach(function (t) {
                    list.appendChild(examCard(t, resumeId !== null && t.id === resumeId));
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

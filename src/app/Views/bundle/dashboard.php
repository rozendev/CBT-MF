<?= view('bundle/_head', ['pageTitle' => 'Beranda', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div class="k-idbar">
    <div class="k-idbar__who">
        <div class="k-idbar__name" id="whoName">&nbsp;</div>
        <div class="k-idbar__sub" id="whoSub">memuat identitas…</div>
    </div>
    <button class="k-btn k-btn--ghost k-btn--sm" id="btnLogout" type="button">Keluar</button>
</div>

<div class="k-wrap">
    <h1>Daftar Ujian</h1>
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

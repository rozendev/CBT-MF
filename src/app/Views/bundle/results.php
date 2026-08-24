<?= view('bundle/_head', ['pageTitle' => 'Hasil', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl, 'school' => $school]) ?>
<body>
<?= view('bundle/_appbar', ['school' => $school]) ?>
<div class="k-wrap">
    <div class="k-pagehead">
        <h1 id="pageTitle">Hasil Ujian</h1>
        <p id="pageSubtitle">Ringkasan pengerjaan Anda.</p>
    </div>
    <div id="summary" class="k-card k-muted">Memuat hasil…</div>
    <div id="actions" class="k-stack" style="margin-top:16px"></div>
</div>

<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(location.search);
        var testId = params.get('test_id') || '';
        // finished=1 → siswa BARU saja menekan "Akhiri Ujian". Di titik ini kiosk
        // masih terkunci: siswa yang memilih mau lihat nilai/review dulu atau
        // langsung keluar, bukan aplikasi yang memutuskan.
        var justFinished = params.get('finished') === '1';
        var box = document.getElementById('summary');
        var actions = document.getElementById('actions');

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined && text !== null) n.textContent = text;
            return n;
        }

        function button(label, cls, onClick) {
            var b = el('button', cls || 'k-btn', label);
            b.type = 'button';
            b.onclick = onClick;
            return b;
        }

        function backButton(label) {
            return button(label || 'Kembali ke Beranda', 'k-btn k-btn--ghost', function () {
                location.href = 'dashboard.html';
            });
        }

        function inKiosk() {
            return !!window.CommsBridge;
        }

        function exitKiosk(btn) {
            if (!window.CBTKioskRequestExit || !window.CBTKioskRequestExit()) {
                alert('Aplikasi ujian tidak merespons. Panggil pengawas.');
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Melepas kunci ujian…';
            // Kalau server menolak, event exit_denied yang mengembalikan tombolnya.
            window.addEventListener('exit_denied', function onDenied() {
                window.removeEventListener('exit_denied', onDenied);
                btn.disabled = false;
                btn.textContent = 'Keluar dari Ujian';
                var note = el('div', 'k-note k-error', 'Kunci ujian belum bisa dilepas. Minta bantuan pengawas.');
                actions.insertBefore(note, actions.firstChild);
            });
        }

        function exitButton() {
            var b = button('Keluar dari Ujian', 'k-btn', function () { exitKiosk(b); });
            return b;
        }

        // Blok nilai dibangun terpisah supaya bisa disembunyikan sampai siswa
        // menekan "Lihat Nilai" pada layar sesudah ujian.
        function scoreBlock(summary) {
            var wrap = el('div');

            var score = el('div', null, String(summary.score));
            score.style.cssText = 'font-size:40px;font-weight:800;line-height:1.1';
            wrap.appendChild(score);
            wrap.appendChild(el('div', 'k-muted', 'dari ' + summary.max_score));

            var stats = el('div');
            stats.style.cssText = 'display:flex;gap:8px;margin-top:16px;text-align:center';
            [
                ['Benar', summary.correct, 'var(--kiosk-ok)'],
                ['Salah', summary.wrong, 'var(--kiosk-danger)'],
                ['Kosong', summary.unanswered, 'var(--kiosk-muted)']
            ].forEach(function (s) {
                var cell = el('div');
                cell.style.cssText = 'flex:1;border:1px solid var(--kiosk-border);border-radius:10px;padding:10px';
                var n = el('div', null, String(s[1]));
                n.style.cssText = 'font-size:22px;font-weight:700;color:' + s[2];
                cell.appendChild(n);
                cell.appendChild(el('div', 'k-muted', s[0]));
                stats.appendChild(cell);
            });
            wrap.appendChild(stats);
            return wrap;
        }

        function reviewButton() {
            return button('Review Jawaban', 'k-btn', function () {
                location.href = 'review.html?test_id=' + encodeURIComponent(testId);
            });
        }

        function render(j) {
            box.className = 'k-card';
            box.innerHTML = '';
            actions.innerHTML = '';

            if (j.status !== 'success') {
                box.appendChild(el('div', 'k-note k-error', j.message || 'Gagal memuat hasil.'));
                if (justFinished && inKiosk()) actions.appendChild(exitButton());
                actions.appendChild(backButton());
                return;
            }

            if (j.test && j.test.name) box.appendChild(el('h3', null, j.test.name));

            if (justFinished) {
                document.getElementById('pageTitle').textContent = 'Ujian Selesai';
                document.getElementById('pageSubtitle').textContent = 'Pilih tindakan sebelum keluar dari mode ujian.';
                box.appendChild(el('p', null, 'Jawaban Anda sudah tersimpan.'));

                if (!j.show_score && !j.allow_review) {
                    box.appendChild(el('p', 'k-muted', 'Nilai dan pembahasan tidak ditampilkan untuk ujian ini.'));
                } else {
                    box.appendChild(el('p', 'k-muted', 'Pilih tindakan selanjutnya di bawah.'));
                }

                if (j.show_score) {
                    var holder = el('div');
                    holder.style.marginTop = '16px';
                    holder.style.display = 'none';
                    holder.appendChild(scoreBlock(j.summary));
                    box.appendChild(holder);

                    var seeScore = button('Lihat Nilai', 'k-btn', function () {
                        holder.style.display = '';
                        seeScore.remove();
                    });
                    actions.appendChild(seeScore);
                }

                if (j.allow_review) actions.appendChild(reviewButton());
                if (inKiosk()) actions.appendChild(exitButton());
                else actions.appendChild(backButton('Kembali ke Beranda'));
                return;
            }

            // Dibuka lagi dari daftar ujian: tampilkan hasil apa adanya.
            if (j.show_score) {
                box.appendChild(scoreBlock(j.summary));
            } else {
                box.appendChild(el('p', null, 'Ujian Anda sudah selesai dan tersimpan.'));
                box.appendChild(el('p', 'k-muted', 'Nilai tidak ditampilkan untuk ujian ini.'));
            }

            if (j.allow_review) actions.appendChild(reviewButton());
            if (inKiosk() && window.CBTKioskHasFinishedExam && window.CBTKioskHasFinishedExam()) {
                actions.appendChild(exitButton());
            }
            actions.appendChild(backButton());
        }

        fetch(base + '/api/student/review?test_id=' + encodeURIComponent(testId), {
            credentials: 'include', headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () {
                box.className = 'k-card';
                box.innerHTML = '';
                box.appendChild(el('div', 'k-note k-error', 'Tidak dapat terhubung ke server.'));
                var retry = button('Coba Lagi', 'k-btn', function () { location.reload(); });
                retry.style.marginTop = '12px';
                box.appendChild(retry);
                actions.innerHTML = '';
                if (justFinished && inKiosk()) actions.appendChild(exitButton());
                actions.appendChild(backButton());
            });
    })();
</script>
</body></html>

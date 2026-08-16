<?= view('bundle/_head', ['pageTitle' => 'Hasil', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div class="k-wrap" style="padding-top:5vh">
    <h1>Hasil Ujian</h1>
    <div id="summary" class="k-card k-muted">Memuat hasil…</div>
    <div id="actions" class="k-stack" style="margin-top:16px"></div>
</div>

<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(location.search);
        var testId = params.get('test_id') || '';
        var box = document.getElementById('summary');
        var actions = document.getElementById('actions');

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined && text !== null) n.textContent = text;
            return n;
        }

        function backButton(label) {
            var b = el('button', 'k-btn k-btn--ghost', label || 'Kembali ke Beranda');
            b.type = 'button';
            b.onclick = function () { location.href = 'dashboard.html'; };
            return b;
        }

        fetch(base + '/api/student/review?test_id=' + encodeURIComponent(testId), {
            credentials: 'include', headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                box.className = 'k-card';
                box.innerHTML = '';

                if (j.status !== 'success') {
                    box.appendChild(el('div', 'k-note k-error', j.message || 'Gagal memuat hasil.'));
                    actions.appendChild(backButton());
                    return;
                }

                if (j.test && j.test.name) box.appendChild(el('h3', null, j.test.name));

                if (j.show_score) {
                    var score = el('div', null, null);
                    score.style.cssText = 'font-size:40px;font-weight:800;line-height:1.1';
                    score.textContent = j.summary.score;
                    box.appendChild(score);
                    box.appendChild(el('div', 'k-muted', 'dari ' + j.summary.max_score));

                    var stats = el('div', null, null);
                    stats.style.cssText = 'display:flex;gap:8px;margin-top:16px;text-align:center';
                    [
                        ['Benar', j.summary.correct, 'var(--kiosk-ok)'],
                        ['Salah', j.summary.wrong, 'var(--kiosk-danger)'],
                        ['Kosong', j.summary.unanswered, 'var(--kiosk-muted)']
                    ].forEach(function (s) {
                        var cell = el('div');
                        cell.style.cssText = 'flex:1;border:1px solid var(--kiosk-border);border-radius:10px;padding:10px';
                        var n = el('div', null, String(s[1]));
                        n.style.cssText = 'font-size:22px;font-weight:700;color:' + s[2];
                        cell.appendChild(n);
                        cell.appendChild(el('div', 'k-muted', s[0]));
                        stats.appendChild(cell);
                    });
                    box.appendChild(stats);
                } else {
                    box.appendChild(el('p', null, 'Ujian Anda sudah selesai dan tersimpan.'));
                    box.appendChild(el('p', 'k-muted', 'Nilai tidak ditampilkan untuk ujian ini.'));
                }

                if (j.allow_review) {
                    var rev = el('button', 'k-btn', 'Review Jawaban');
                    rev.type = 'button';
                    rev.onclick = function () { location.href = 'review.html?test_id=' + encodeURIComponent(testId); };
                    actions.appendChild(rev);
                }
                actions.appendChild(backButton());
            })
            .catch(function () {
                box.className = 'k-card';
                box.innerHTML = '';
                box.appendChild(el('div', 'k-note k-error', 'Tidak dapat terhubung ke server.'));
                var retry = el('button', 'k-btn', 'Coba Lagi');
                retry.type = 'button';
                retry.style.marginTop = '12px';
                retry.onclick = function () { location.reload(); };
                box.appendChild(retry);
                actions.appendChild(backButton());
            });
    })();
</script>
</body></html>

<?= view('bundle/_head', ['pageTitle' => 'Review', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div class="k-wrap" style="padding-top:5vh">
    <h1>Review Jawaban</h1>
    <div id="review" class="k-stack">
        <div class="k-card k-muted">Memuat review…</div>
    </div>
    <button class="k-btn k-btn--ghost" type="button" style="margin-top:16px"
            onclick="location.href='dashboard.html'">Kembali ke Beranda</button>
</div>

<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(location.search);
        var wrap = document.getElementById('review');

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined && text !== null) n.textContent = text;
            return n;
        }

        function note(cls, msg) {
            wrap.innerHTML = '';
            wrap.appendChild(el('div', 'k-note ' + cls, msg));
        }

        fetch(base + '/api/student/review?test_id=' + encodeURIComponent(params.get('test_id') || ''), {
            credentials: 'include', headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { note('k-error', j.message || 'Gagal memuat review.'); return; }
                if (!j.allow_review) { note('k-error', 'Review jawaban tidak tersedia untuk ujian ini.'); return; }

                wrap.innerHTML = '';
                j.questions.forEach(function (q, i) {
                    var card = el('div', 'k-card');

                    card.appendChild(el('div', 'k-muted', 'Soal ' + (i + 1) + ' dari ' + j.questions.length));

                    // question_text & answer_text berasal dari editor guru dan memang
                    // boleh mengandung markup (gambar, format) — innerHTML disengaja
                    // di sini, berbeda dengan nama ujian di dashboard yang teks polos.
                    var qt = el('div');
                    qt.style.cssText = 'margin:8px 0 14px;font-size:17px';
                    qt.innerHTML = q.question_text || '';
                    card.appendChild(qt);

                    var userAns = (q.user_answers || []).map(function (a) { return a.answer_text; });
                    var answered = userAns.length > 0;

                    var mine = el('div');
                    mine.style.cssText = 'border-left:4px solid ' +
                        (answered ? 'var(--kiosk-primary)' : 'var(--kiosk-border)') +
                        ';padding-left:12px;margin-bottom:8px';
                    mine.appendChild(el('div', 'k-muted', 'Jawaban Anda'));
                    var mineVal = el('div');
                    mineVal.style.cssText = 'font-weight:600';
                    if (answered) { mineVal.innerHTML = userAns.join(', '); }
                    else { mineVal.textContent = '(tidak dijawab)'; mineVal.style.color = 'var(--kiosk-muted)'; }
                    mine.appendChild(mineVal);
                    card.appendChild(mine);

                    var correct = (q.correct_answers || []).map(function (a) { return a.answer_text; });
                    if (j.show_correct && correct.length) {
                        var key = el('div');
                        key.style.cssText = 'border-left:4px solid var(--kiosk-ok);padding-left:12px';
                        key.appendChild(el('div', 'k-muted', 'Kunci jawaban'));
                        var keyVal = el('div');
                        keyVal.style.cssText = 'font-weight:600;color:var(--kiosk-ok)';
                        keyVal.innerHTML = correct.join(', ');
                        key.appendChild(keyVal);
                        card.appendChild(key);
                    }

                    wrap.appendChild(card);
                });
            })
            .catch(function () { note('k-error', 'Tidak dapat terhubung ke server.'); });
    })();
</script>
</body></html>

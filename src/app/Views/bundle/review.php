<?= view('bundle/_head', ['pageTitle' => 'Review', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:640px;margin:4vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Review Jawaban</h2>
        <div id="review">Memuat...</div>
        <button class="k-btn" style="margin-top:16px" onclick="window.location.href='dashboard.html'">Kembali</button>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        var params = new URLSearchParams(window.location.search);
        fetch(base + '/api/student/review?test_id=' + encodeURIComponent(params.get('test_id') || ''), { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { document.getElementById('review').innerHTML = '<p>' + (j.message || 'Gagal memuat review.') + '</p>'; return; }
                if (!j.allow_review) { document.getElementById('review').innerHTML = '<p>Review tidak tersedia.</p>'; return; }
                var el = document.getElementById('review');
                el.innerHTML = '';
                j.questions.forEach(function (q, i) {
                    var user = (q.user_answers || []).map(function (a) { return a.answer_text; }).join(', ') || '(kosong)';
                    var correct = (q.correct_answers || []).map(function (a) { return a.answer_text; }).join(', ');
                    var html = '<div style="border:1px solid var(--kiosk-border);border-radius:10px;padding:14px;margin-bottom:12px">' +
                        '<h3 style="margin:0 0 6px">' + (i + 1) + '. ' + q.question_text + '</h3>' +
                        '<p style="margin:4px 0;color:#475569">Jawaban Anda: <b>' + user + '</b></p>';
                    if (j.show_correct && correct) { html += '<p style="margin:4px 0;color:#15803d">Kunci: ' + correct + '</p>'; }
                    html += '</div>';
                    el.innerHTML += html;
                });
            })
            .catch(function () { document.getElementById('review').innerHTML = '<div class="k-error">Tidak dapat terhubung ke server.</div>'; });
    })();
</script>
</body></html>
<?= view('bundle/_head', ['pageTitle' => 'Hasil', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:640px;margin:4vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Hasil Ujian</h2>
        <div id="summary">Memuat...</div>
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
                if (j.status !== 'success') { document.getElementById('summary').innerHTML = '<p>' + (j.message || 'Gagal memuat hasil.') + '</p>'; return; }
                var el = document.getElementById('summary');
                if (j.show_score) {
                    el.innerHTML = '<p style="font-size:22px;margin:0">Skor: <b>' + j.summary.score + ' / ' + j.summary.max_score + '</b></p>' +
                        '<p style="color:#475569">Benar: ' + j.summary.correct + ' · Salah: ' + j.summary.wrong + ' · Kosong: ' + j.summary.unanswered + '</p>';
                } else {
                    el.innerHTML = '<p>Skor tidak ditampilkan.</p>';
                }
                if (j.allow_review) {
                    el.innerHTML += '<button class="k-btn" style="margin-top:12px" onclick="window.location.href=\'review.html?test_id=' + params.get('test_id') + '\'">Review Jawaban</button>';
                }
            })
            .catch(function () { document.getElementById('summary').innerHTML = '<div class="k-error">Tidak dapat terhubung ke server.</div>'; });
    })();
</script>
</body></html>
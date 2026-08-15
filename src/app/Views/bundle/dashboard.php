<?= view('bundle/_head', ['pageTitle' => 'Dashboard', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:640px;margin:4vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Daftar Ujian</h2>
        <div id="list">Memuat...</div>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        fetch(base + '/api/student/exams', { credentials: 'include', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.status !== 'success') { window.location.href = 'login.html'; return; }
                if (j.active_attempt) { window.location.href = 'exam.html?test_id=' + j.active_attempt.test_id + '&resume=1'; return; }
                var list = document.getElementById('list');
                if (!j.exams || !j.exams.length) { list.innerHTML = '<p>Tidak ada ujian tersedia.</p>'; return; }
                list.innerHTML = '';
                j.exams.forEach(function (t) {
                    var card = document.createElement('div');
                    card.style.cssText = 'border:1px solid var(--kiosk-border);border-radius:10px;padding:14px;margin-bottom:12px';
                    var status = t.attempt_status === 3 ? ' (Selesai)' : '';
                    var btn = '<button class="k-btn" style="width:auto;padding:10px 16px" onclick="window.location.href=\'exam.html?test_id=' + t.id + '\'">Kerjakan</button>';
                    if (t.attempt_status === 3) { btn = '<button class="k-btn" style="width:auto;padding:10px 16px;background:#64748b" onclick="window.location.href=\'results.html?test_id=' + t.id + '\'">Lihat Hasil</button>'; }
                    card.innerHTML = '<h3 style="margin:0 0 4px">' + t.name + status + '</h3>' +
                        '<p style="margin:0 0 10px;color:#475569;font-size:14px">Durasi: ' + t.duration_minutes + ' menit</p>' + btn;
                    list.appendChild(card);
                });
            })
            .catch(function () {
                document.getElementById('list').innerHTML =
                    '<div class="k-error">Tidak dapat terhubung ke server. <button class="k-btn" style="width:auto;margin-top:8px" onclick="location.reload()">Coba Lagi</button></div>';
            });
    })();
</script>
</body></html>

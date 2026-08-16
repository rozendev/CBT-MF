<?= view('bundle/_head', ['pageTitle' => 'Masuk', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div class="k-wrap" style="padding-top:6vh">
    <h1>Masuk Ujian</h1>
    <p class="k-muted" id="serverInfo" style="margin-top:-6px">&nbsp;</p>

    <div class="k-card">
        <div id="err" class="k-note k-error" style="display:none;margin-bottom:16px"></div>

        <form id="loginForm" class="k-stack" novalidate>
            <div>
                <label class="k-label" for="username">Username</label>
                <input class="k-input" id="username" name="username"
                       autocomplete="username" autocapitalize="none" autocorrect="off"
                       spellcheck="false" inputmode="text" required>
            </div>
            <div>
                <label class="k-label" for="password">Password</label>
                <input class="k-input" id="password" name="password" type="password"
                       autocomplete="current-password" required>
            </div>
            <button class="k-btn" id="btn" type="submit">Masuk</button>
        </form>
    </div>

    <p class="k-muted" style="text-align:center;margin-top:20px">
        Pastikan Anda masuk dengan akun sendiri.
    </p>
</div>

<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        localStorage.setItem('kiosk_base_url', base);

        // Tunjukkan server yang dituju: kalau perangkat salah diarahkan,
        // ini satu-satunya petunjuk yang siswa/pengawas punya sebelum login.
        try {
            document.getElementById('serverInfo').textContent = new URL(base).host;
        } catch (e) {
            document.getElementById('serverInfo').textContent = base || '';
        }

        var form = document.getElementById('loginForm');
        var btn = document.getElementById('btn');
        var err = document.getElementById('err');

        function fail(msg) {
            err.textContent = msg;
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Masuk';
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var u = document.getElementById('username').value.trim();
            var p = document.getElementById('password').value;
            if (!u || !p) { fail('Username dan password wajib diisi.'); return; }

            btn.disabled = true;
            btn.textContent = 'Memeriksa…';
            err.style.display = 'none';

            fetch(base + '/login', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'kiosk-bundle'
                },
                body: 'username=' + encodeURIComponent(u) + '&password=' + encodeURIComponent(p)
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, j: j }; });
            }).then(function (res) {
                if (res.j && res.j.status === 'success') {
                    location.href = 'dashboard.html';
                } else {
                    fail((res.j && res.j.message) || 'Login gagal.');
                }
            }).catch(function () {
                fail('Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.');
            });
        });
    })();
</script>
</body></html>

<?= view('bundle/_head', ['pageTitle' => 'Login', 'assetVersion' => $assetVersion, 'baseUrl' => $baseUrl]) ?>
<body>
<div style="max-width:420px;margin:8vh auto;padding:0 16px">
    <div class="k-card">
        <h2 style="margin-top:0">Login Ujian</h2>
        <p id="err" class="k-error" style="display:none"></p>
        <form id="loginForm">
            <label for="username">Username</label>
            <input class="k-input" id="username" name="username" autocomplete="username" required>
            <label for="password" style="margin-top:12px;display:block">Password</label>
            <input class="k-input" id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="k-btn" id="btn" style="margin-top:18px" type="submit">Masuk</button>
        </form>
    </div>
</div>
<script>
    (function () {
        var base = window.KIOSK_BASE_URL;
        localStorage.setItem('kiosk_base_url', base);
        var form = document.getElementById('loginForm');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('btn');
            var err = document.getElementById('err');
            btn.disabled = true; err.style.display = 'none';
            fetch(base + '/login', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'kiosk-bundle'
                },
                body: 'username=' + encodeURIComponent(document.getElementById('username').value) +
                      '&password=' + encodeURIComponent(document.getElementById('password').value)
            }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
              .then(function (res) {
                  if (res.j.status === 'success') {
                      location.href = 'dashboard.html';
                  } else {
                      err.textContent = res.j.message || 'Login gagal.';
                      err.style.display = 'block';
                      btn.disabled = false;
                  }
              }).catch(function () {
                  err.textContent = 'Tidak dapat terhubung ke server.';
                  err.style.display = 'block';
                  btn.disabled = false;
              });
        });
    })();
</script>
</body></html>
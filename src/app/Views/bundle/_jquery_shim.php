// Mini-shim jQuery untuk exam-app.js bundle mode — HANYA $.ajax dengan
// opsi success/error + chain .done()/.fail()/.always().
// Bukan pengganti jquery; data sudah FormData, proses JSON, credentials include.
window.$ = window.jQuery = {
    ajax: function (opts) {
        var headers = { 'Accept': 'application/json', 'X-Requested-With': 'kiosk-bundle' };
        if (typeof opts.data === 'string') { headers['Content-Type'] = 'application/x-www-form-urlencoded'; }
        var p = {
            done: function (cb) { p._done = cb; return p; },
            fail: function (cb) { p._fail = cb; return p; },
            always: function (cb) { p._always = cb; return p; },
            _settle: function (res) {
                if (opts.success) opts.success(res);
                if (p._done) p._done(res);
                if (p._always) p._always(res);
            },
            _error: function (err) {
                if (opts.error) opts.error(err);
                if (p._fail) p._fail(err);
                if (p._always) p._always(null);
            }
        };
        fetch(opts.url, {
            method: opts.type || 'GET',
            credentials: 'include',
            headers: headers,
            body: opts.data
        }).then(function (r) {
            return r.json().then(function (j) {
                return { ok: r.ok, status: r.status, json: j };
            });
        }).then(function (res) {
            if (res.ok) { p._settle(res.json); }
            else { p._error({ status: res.status, responseJSON: res.json }); }
        }).catch(function () {
            p._error({ status: 0, responseJSON: null });
        });
        return p;
    }
};
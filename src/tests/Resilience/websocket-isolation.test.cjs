const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');

const examFiles = ['public/assets/exam-app.js', 'app/Views/student/exam/take.php'];

function createExamFixture(file, options = {}) {
    const testId = options.testId || 5;
    const beginMs = options.beginTimeMs ?? 1_000_000;
    const source = readFileSync(resolve(__dirname, '../..', file), 'utf8').replace(/<\?=([\s\S]*?)\?>/g, (_, php) => {
        if (php.includes("base_url('/api/exam/status')")) return '/api/exam/status';
        if (php.includes('$wsUrl')) return 'ws://localhost';
        if (/base_url\(['"]\/?login['"]/.test(php)) return '/login';
        if (/base_url\(['"]\/?student\/dashboard['"]/.test(php)) return '/dashboard';
        if (php.includes('$test->id')) return String(testId);
        if (php.trim() === 'base_url()') return 'https://cbt.example.sch.id/';
        return '0';
    });

    const names = [
        'modeDriftTarget',
        'startTimer',
        'initWebSocket',
        'connectWebSocket',
        'reconnectWebSocket',
        'armWebSocketTimeout',
        'startStatusPolling',
        'stopStatusPolling',
        'pollExamStatus',
        'applyExamStatus',
        'stopRealtime',
    ];

    const methods = names.flatMap(name => {
        const m = new RegExp('^([ ]+)(?:async )?' + name + '\\(', 'm').exec(source);
        if (!m) return [];
        return [source.slice(m.index, source.indexOf('\n' + m[1] + '}', m.index) + m[1].length + 2)];
    });

    let now = options.initialNow ?? 3_700_000; // 45m after beginMs=1_000_000
    let serial = 0;
    const timers = new Map();
    const sockets = [];
    const messages = [];
    const redirects = [];

    class Socket {
        static OPEN = 1;
        constructor() {
            this.readyState = 0;
            sockets.push(this);
        }
        open() {
            this.readyState = 1;
            this.onopen();
            this.onmessage({ data: JSON.stringify({ event: 'connected', data: { attempt_id: 21 } }) });
        }
        close() {
            this.readyState = 3;
            this.onclose?.({});
        }
        send() {}
    }

    const context = {
        window: {
            APP_CONFIG: {},
            __KIOSK_BUNDLE__: !!options.kioskBundle,
            __examData: { wsToken: 'test-token', beginTimeMs: beginMs },
            location: { pathname: '/student/exam/take/' + testId, href: '' },
        },
        navigator: { onLine: true },
        document: { fullscreenElement: null },
        ATTEMPT_ID: 21,
        API: 'https://cbt.example.sch.id',
        EXAM_CONFIG: { testId, examMode: options.examMode || 'normal', wsUrl: 'ws://localhost' },
        LOGIN_URL: '/login',
        DASHBOARD_URL: '/dashboard',
        durationMin: 60,
        beginTimeMs: beginMs,
        WebSocket: Socket,
        AbortSignal,
        FormData,
        Date: class extends Date { static now() { return now; } },
        console: { log() {}, warn() {}, error() {} },
        setTimeout(callback, delay) {
            const id = ++serial;
            timers.set(id, { at: now + Math.max(0, delay || 0), callback });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
        setInterval() { return 1; },
        clearInterval() {},
        Swal: {
            fire(...args) {
                messages.push(args);
                return Promise.resolve();
            },
        },
        logoutAndRedirect(url) {
            redirects.push(url);
            context.window.location.href = url;
        },
        updateCsrf() {},
        buildFormData: x => x,
        fetch: async () => ({
            ok: true,
            status: 200,
            headers: new Headers(),
            json: async () => ({
                status: 'success',
                duration_minutes: 60,
                end_time_ms: beginMs + 3_600_000,
                server_time_ms: now,
                exam_mode: 'normal',
            }),
        }),
    };

    const begin = source.indexOf('    const APP_CFG =');
    const end = source.indexOf('async function logoutAndRedirect', begin);
    const app = vm.runInNewContext(source.slice(begin, end) + '\n({' + methods.join(',\n') + '})', context);

    Object.assign(app, {
        activeQueue: Promise.resolve(),
        durationMinutes: 60,
        beginTimeMs: 0,
        endTimeMs: beginMs + 3_600_000,
        showEjected(message) { app.ejected = message; },
    });

    if (typeof app.startTimer === 'function') {
        app.startTimer(beginMs, 0);
    }

    return { app, sockets, messages, redirects, context };
}

function createProctorFixture(options = {}) {
    const raw = readFileSync(resolve(__dirname, '../../app/Views/proctor/live.php'), 'utf8');
    const scriptStart = raw.indexOf('const APP_CFG = window.APP_CONFIG || {};');
    const scriptEnd = raw.indexOf('</script>', scriptStart);
    let script = raw.slice(scriptStart, scriptEnd);

    // Simulate admin or proctor PHP branch for handleProctorReport
    const isAdmin = options.role !== 'guru';
    script = script.replace(
        /<\?php if\s*\(\s*session\(['"]role['"]\)\s*===\s*['"]admin['"]\s*\)\s*:\s*\?>([\s\S]*?)<\?php endif;\s*\?>/g,
        (_, body) => (isAdmin ? body : '')
    );

    script = script.replace(/<\?=([\s\S]*?)\?>/g, (_, php) => {
        if (php.includes('$wsUrl')) return 'ws://localhost:8060';
        if (php.includes('$proctorToken')) return 'proctor-token-123';
        if (php.includes('json_encode($attempts)')) {
            return JSON.stringify(options.attempts || [
                {
                    attempt_id: 21,
                    user_id: 10,
                    firstname: 'Budi',
                    lastname: 'Santoso',
                    username: 'siswa01',
                    is_online: true,
                    cheat_strikes: 0,
                    status: 1,
                },
            ]);
        }
        if (/base_url\(['"]\/?login['"]\)/.test(php)) return '/login';
        if (php.includes("base_url('admin/suspend')")) return '/admin/suspend';
        return '0';
    });

    let now = 0;
    let serial = 0;
    const timers = new Map();
    const sockets = [];
    const swalCalls = [];

    class Socket {
        static OPEN = 1;
        constructor(url) {
            this.url = url;
            this.readyState = 0;
            sockets.push(this);
        }
        open() {
            this.readyState = 1;
            this.onopen?.();
        }
        close() {
            this.readyState = 3;
            this.onclose?.({});
        }
        send() {}
    }

    const context = {
        window: { APP_CONFIG: { status: { banned: 2 } }, location: { href: '/proctor/live/5' } },
        WebSocket: Socket,
        bootstrap: { Modal: class { show() {} hide() {} } },
        Swal: {
            fire(opts) {
                swalCalls.push(opts);
                return Promise.resolve({ isConfirmed: false });
            },
        },
        console: { log() {}, warn() {}, error() {} },
        setTimeout(cb, delay) {
            const id = ++serial;
            timers.set(id, { at: now + Math.max(0, delay || 0), cb });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
        setInterval() { return 1; },
        clearInterval(id) { timers.delete(id); },
    };

    const dashboard = vm.runInNewContext(script + '\nproctorLiveDashboard();', context);
    dashboard.$refs = { reportModal: {} };
    dashboard.playBeep = () => {};

    function advance(ms) {
        const until = now + ms;
        for (let i = 0; i < 100; i++) {
            const next = [...timers].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            timers.delete(next[0]);
            now = next[1].at;
            next[1].cb();
        }
        now = until;
    }

    return { dashboard, sockets, swalCalls, context, timers, advance };
}

for (const file of examFiles) {
    test(file + ': sync_mode ignores events from another test_id or missing test_id (WS-01)', () => {
        const f = createExamFixture(file, { testId: 5 });
        f.app.connectWebSocket('ws://localhost');
        f.sockets[0].open();

        // Different test_id (e.g. test 99 generated static page while student is taking test 5)
        f.sockets[0].onmessage({
            data: JSON.stringify({
                event: 'sync_mode',
                data: {
                    test_id: 99,
                    exam_mode: 'static',
                    static_page_path: 'static/2026-11-02/other-exam.html',
                },
            }),
        });
        assert.equal(f.context.window.location.href, '', 'sync_mode for another test_id must not redirect student');
        assert.ok(!f.context.window.isSubmitting);

        // Missing test_id must also be ignored
        f.sockets[0].onmessage({
            data: JSON.stringify({
                event: 'sync_mode',
                data: {
                    exam_mode: 'static',
                    static_page_path: 'static/2026-11-02/missing-id.html',
                },
            }),
        });
        assert.equal(f.context.window.location.href, '', 'sync_mode without test_id must not redirect student');

        // Matching test_id MUST redirect
        f.sockets[0].onmessage({
            data: JSON.stringify({
                event: 'sync_mode',
                data: {
                    test_id: 5,
                    exam_mode: 'static',
                    static_page_path: 'static/2026-11-02/test-5.html',
                },
            }),
        });
        assert.match(f.context.window.location.href, /static\/2026-11-02\/test-5\.html$/);
    });
}

test('public/assets/exam-app.js: extend_time computes new deadline from beginTimeMs rather than Date.now() (WS-01)', () => {
    // Exam started at 1,000,000ms (60m -> endTimeMs = 4,600,000ms).
    // Current clock is 3,700,000ms (45m elapsed). Admin extends total duration to 75m -> expected endTimeMs = 5,500,000ms.
    const f = createExamFixture('public/assets/exam-app.js', { testId: 5, beginTimeMs: 1_000_000, initialNow: 3_700_000 });
    f.app.connectWebSocket('ws://localhost');
    f.sockets[0].open();

    f.sockets[0].onmessage({
        data: JSON.stringify({
            event: 'extend_time',
            data: {
                test_id: 5,
                duration_minutes: 75,
            },
        }),
    });

    assert.equal(f.app.durationMinutes, 75);
    assert.equal(f.app.endTimeMs, 1_000_000 + (75 * 60 * 1000));
});

test('app/Views/proctor/live.php: handleProctorReport escapes HTML payloads against XSS (WS-04)', () => {
    const f = createProctorFixture({ role: 'admin' });
    f.dashboard.initDashboard();
    f.sockets[0].open();
    f.sockets[0].onmessage({ data: JSON.stringify({ event: 'connected', data: { message: 'ok' } }) });

    f.sockets[0].onmessage({
        data: JSON.stringify({
            event: 'proctor_alert',
            data: {
                event: 'proctor_report_alert',
                proctor_name: '<img src=x onerror=alert(1)>',
                suggested_action: '<script>evil()</script>',
                student_username: 'siswa"onload="alert(2)',
                reason: 'Alasan <svg onload=alert(3)> & "kutip"',
            },
        }),
    });

    assert.equal(f.swalCalls.length, 1);
    const html = f.swalCalls[0].html;
    assert.ok(!html.includes('<img'), 'Raw <img> tag must not appear in Swal html');
    assert.ok(!html.includes('<script>'), 'Raw <script> tag must not appear in Swal html');
    assert.ok(!html.includes('<svg'), 'Raw <svg> tag must not appear in Swal html');
    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
    assert.ok(html.includes('&lt;svg onload=alert(3)&gt;'));
});

test('app/Views/proctor/live.php: proctor_alert handles flat and nested auto_submit & modified_browser payloads (WS-04)', () => {
    const f = createProctorFixture({ role: 'admin' });
    f.dashboard.initDashboard();
    f.sockets[0].open();
    f.sockets[0].onmessage({ data: JSON.stringify({ event: 'connected', data: { message: 'ok' } }) });

    // Flat unwrapped payload from hardened WebSocketServerHandler
    f.sockets[0].onmessage({
        data: JSON.stringify({
            event: 'proctor_alert',
            data: {
                event: 'auto_submit',
                user_id: '10',
                test_id: 5,
                reason: 'membuka tab lain',
            },
        }),
    });

    assert.equal(f.dashboard.students[0].status, 3);
    assert.equal(f.dashboard.students[0].strikes, 1);
    assert.equal(f.swalCalls.length, 1);
    assert.match(f.swalCalls[0].text, /membuka tab lain/);

    // Nested payload shape for modified_browser must also mark student banned
    const fMod = createProctorFixture({ role: 'admin' });
    fMod.dashboard.initDashboard();
    fMod.sockets[0].open();
    fMod.sockets[0].onmessage({ data: JSON.stringify({ event: 'connected', data: { message: 'ok' } }) });
    fMod.sockets[0].onmessage({
        data: JSON.stringify({
            event: 'proctor_alert',
            data: {
                event: 'proctor_alert',
                data: {
                    event: 'modified_browser',
                    user_id: 10,
                    test_id: 5,
                },
            },
        }),
    });
    assert.equal(fMod.dashboard.students[0].banned, true);
    assert.equal(fMod.dashboard.students[0].strikes, 1);
});

test('app/Views/proctor/live.php: kick event and repeated unauthenticated closes stop reconnect loop and redirect to login (WS-07)', () => {
    // Case 1: Server sends kick frame when proctor credentials/role are revoked
    const fKick = createProctorFixture({ role: 'admin' });
    fKick.dashboard.initDashboard();
    fKick.sockets[0].open();
    fKick.sockets[0].onmessage({ data: JSON.stringify({ event: 'connected', data: { message: 'ok' } }) });
    fKick.sockets[0].onmessage({ data: JSON.stringify({ event: 'kick', data: { message: 'Sesi dicabut' } }) });
    fKick.advance(30_000);

    assert.equal(fKick.sockets.length, 1, 'Must not reconnect after kick');
    assert.equal(fKick.context.window.location.href, '/login');

    // Case 2: Token invalid/expired -> server closes socket before sending connected
    const fRejected = createProctorFixture({ role: 'admin' });
    fRejected.dashboard.initDashboard();
    for (let i = 0; i < 8; i++) {
        const latest = fRejected.sockets.at(-1);
        if (latest.readyState === 0) {
            latest.open();
            latest.close();
        }
        fRejected.advance(5000);
    }

    assert.ok(fRejected.sockets.length <= 6, 'Must stop infinite reconnect loop when token is rejected');
    assert.equal(fRejected.context.window.location.href, '/login');
});

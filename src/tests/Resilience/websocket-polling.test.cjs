const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');

const files = ['public/assets/exam-app.js', 'app/Views/student/exam/take.php'];
function fixture(file, options = {}) {
    const source = readFileSync(resolve(__dirname, '../..', file), 'utf8').replace(/<\?=([\s\S]*?)\?>/g, (_, php) => {
        if (php.includes("base_url('/api/exam/status')")) return '/api/exam/status';
        if (php.includes('$wsUrl')) return options.noUrl ? '' : 'ws://localhost';
        if (/base_url\(['"]\/?login['"]/.test(php)) return '/login';
        if (/base_url\(['"]\/?student\/dashboard['"]/.test(php)) return '/dashboard';
        return '0';
    });
    const names = ['startTimer', 'initWebSocket', 'connectWebSocket', 'reconnectWebSocket', 'armWebSocketTimeout', 'startStatusPolling', 'stopStatusPolling', 'pollExamStatus', 'applyExamStatus', 'stopRealtime'];
    const methods = names.flatMap(name => {
        const m = new RegExp('^([ ]+)(?:async )?' + name + '\\(', 'm').exec(source);
        if (!m) return [];
        return [source.slice(m.index, source.indexOf('\n' + m[1] + '}', m.index) + m[1].length + 2)];
    });
    let now = 0, serial = 0;
    const timers = new Map(), sockets = [], calls = [], messages = [], intervals = [];
    class Socket {
        static OPEN = 1;
        constructor() {
            if (options.constructorFails) throw new Error('WebSocket unavailable');
            this.readyState = 0; sockets.push(this);
        }
        open(authenticated = true) {
            this.readyState = 1; this.onopen();
            if (authenticated) this.onmessage({ data: JSON.stringify({ event: 'connected', data: { attempt_id: options.attemptId || 21 } }) });
        }
        close() { this.readyState = 3; this.onclose?.({}); }
        send() {}
    }
    const context = {
        window: { APP_CONFIG: {}, __examData: { wsToken: 'test-token' }, location: {} },
        navigator: { onLine: true }, document: { fullscreenElement: null },
        ATTEMPT_ID: options.attemptId || 21, API: '', EXAM_CONFIG: { testId: 5, wsUrl: options.noUrl ? '' : 'ws://localhost' },
        LOGIN_URL: '/login', DASHBOARD_URL: '/dashboard', durationMin: 60, beginTimeMs: 0,
        WebSocket: Socket, AbortSignal, FormData,
        Date: class extends Date { static now() { return now; } },
        console: { log() {}, warn() {}, error() {} },
        setTimeout(callback, delay) { const id = ++serial; timers.set(id, { at: now + delay, callback }); return id; },
        clearTimeout(id) { timers.delete(id); },
        setInterval(callback) { intervals.push(callback); return intervals.length; },
        clearInterval() {},
        Swal: { fire(...args) { messages.push(args); return Promise.resolve(); } },
        logoutAndRedirect(url) { context.window.location.href = url; },
        updateCsrf() {}, buildFormData: x => x,
        fetch: async (url, opts) => {
            calls.push({ at: now, url, opts });
            if (options.hold) await options.hold;
            const status = options.httpStatus || 200;
            return { ok: status < 400, status, headers: new Headers(options.retryAfter ? { 'Retry-After': options.retryAfter } : {}), json: async () => options.body || { status: 'success', duration_minutes: 60, end_time_ms: 3600000, server_time_ms: 0, exam_mode: 'normal' } };
        },
    };
    const begin = source.indexOf('    const APP_CFG =');
    const end = source.indexOf('async function logoutAndRedirect', begin);
    const app = vm.runInNewContext(source.slice(begin, end) + '\n({' + methods.join(',\n') + '})', context);
    Object.assign(app, { activeQueue: Promise.resolve(), durationMinutes: 60, modeDriftTarget: () => null, showEjected(message) { app.ejected = message; } });
    async function flush() { for (let i = 0; i < 30; i++) await Promise.resolve(); }
    async function advance(ms) {
        const until = now + ms;
        for (let i = 0; i < 100; i++) {
            await flush();
            const next = [...timers].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            timers.delete(next[0]); now = next[1].at; next[1].callback();
        }
        now = until; await flush();
    }
    return { app, calls, sockets, messages, context, advance, tick: () => intervals[0]() };
}

for (const file of files) {
    test(file + ': socket error immediately activates one staggered polling loop', async () => {
        const f = fixture(file);
        f.app.connectWebSocket('ws://localhost');
        f.sockets[0].onerror({});
        await f.advance(1000);
        assert.equal(f.calls.length, 1);
        assert.match(f.calls[0].url, /\/api\/exam\/status\/21$/);
        assert.equal(f.calls[0].opts.method, 'GET');
        await f.advance(15000);
        assert.equal(f.calls.length, 2, 'error and close must not create two polling loops');
    });

    test(file + ': constructor failure and unavailable URL both fall back', async () => {
        for (const options of [{ constructorFails: true }, { noUrl: true }]) {
            const f = fixture(file, options);
            assert.doesNotThrow(() => options.noUrl ? f.app.initWebSocket() : f.app.connectWebSocket('ws://localhost'));
            await f.advance(1000);
            assert.equal(f.calls.length, 1);
        }
    });

    test(file + ': handshake timeout and silent open socket activate polling', async () => {
        for (const open of [false, true]) {
            const f = fixture(file);
            f.app.connectWebSocket('ws://localhost');
            if (open) f.sockets[0].open();
            await f.advance(open ? 46000 : 16000);
            assert.equal(f.calls.length, 1);
        }
    });

    test(file + ': reopening WebSocket stops polling and ignores stale close callbacks', async () => {
        const f = fixture(file);
        f.app.connectWebSocket('ws://localhost');
        const old = f.sockets[0]; old.close();
        await f.advance(2500);
        const current = f.sockets.at(-1); current.open();
        old.onclose({});
        assert.equal(f.app.statusPolling, false);
        const count = f.calls.length;
        await f.advance(20000);
        assert.equal(f.calls.length, count);
        assert.equal(current.readyState, 1);
    });

    test(file + ': repeated socket failures stop reconnecting after three retries', async () => {
        const f = fixture(file);
        f.app.connectWebSocket('ws://localhost');
        for (let i = 0; i < 4; i++) {
            f.sockets.at(-1).onerror({});
            await f.advance(8000);
        }
        await f.advance(30000);
        assert.equal(f.sockets.length, 4);
        assert.ok(f.calls.length >= 3, 'Polling continues after the socket retry budget is exhausted');
    });

    test(file + ': sockets rejected after upgrade cannot reset retry budgets', async () => {
        const f = fixture(file, { httpStatus: 503 });
        f.app.connectWebSocket('ws://localhost');
        for (let i = 0; i < 8; i++) {
            const socket = f.sockets.at(-1);
            if (socket.readyState === 0) { socket.open(false); socket.close(); }
            await f.advance(8000);
        }
        assert.equal(f.sockets.length, 4);
        assert.equal(f.calls.length, 4);
    });

    test(file + ': polls are staggered per student and stay 10–15 seconds apart', async () => {
        const times = [];
        for (const id of [21, 22]) {
            const f = fixture(file, { attemptId: id });
            f.app.startStatusPolling(); await f.advance(31000);
            assert.equal(f.calls.length, 3);
            for (let i = 1; i < f.calls.length; i++) assert.ok(f.calls[i].at - f.calls[i - 1].at >= 10000 && f.calls[i].at - f.calls[i - 1].at <= 15000);
            times.push(f.calls[0].at);
        }
        assert.notEqual(times[0], times[1]);
    });

    test(file + ': HTTP outage stops after three retries and honors Retry-After', async () => {
        for (const httpStatus of [429, 503]) {
            const f = fixture(file, { httpStatus, retryAfter: httpStatus === 429 ? '30' : null });
            f.app.startStatusPolling(); await f.advance(180000);
            assert.equal(f.calls.length, 4);
            if (httpStatus === 429) for (let i = 1; i < 4; i++) assert.ok(f.calls[i].at - f.calls[i - 1].at >= 30000);
            f.app.startStatusPolling(); await f.advance(180000);
            assert.equal(f.calls.length, 4, 'An exhausted HTTP budget must not restart automatically');
        }
    });

    test(file + ': slow polling cannot overlap requests', async () => {
        let release;
        const f = fixture(file, { hold: new Promise(resolve => { release = resolve; }) });
        f.app.startStatusPolling(); await f.advance(60000);
        assert.equal(f.calls.length, 1);
        release(); await f.advance(16000);
        assert.equal(f.calls.length, 2);
    });

    test(file + ': recovery followed by another failure discards old status and keeps one polling loop', async () => {
        let release;
        const f = fixture(file, { hold: new Promise(resolve => { release = resolve; }) });
        f.app.connectWebSocket('ws://localhost'); f.sockets[0].close();
        await f.advance(2500);
        const recovered = f.sockets.at(-1); recovered.open();
        f.app.applyExamStatus({ status: 'success', duration_minutes: 120, end_time_ms: 7200000, server_time_ms: 2500, exam_mode: 'normal' });
        recovered.close();
        await f.advance(1000); // New generation's timer fires while the old request is held.
        release(); await f.advance(0);
        assert.equal(f.app.endTimeMs, 7200000, 'Old snapshot must not undo the extended deadline');
        await f.advance(31000);
        assert.equal(f.calls.length, 3);
        assert.ok(f.calls[2].at - f.calls[1].at >= 10000, 'Only one timer may schedule the new generation');
    });

    if (file.startsWith('public/')) test(file + ': server clock correction survives polling deadline updates', async () => {
        const f = fixture(file);
        f.app.startTimer(600000, 600000); // Device clock is ten minutes slow.
        await f.advance(1000);
        f.app.applyExamStatus({ status: 'success', duration_minutes: 60, end_time_ms: 4200000, server_time_ms: 601000, exam_mode: 'normal' });
        f.tick();
        assert.equal(f.app.timeLeft, 3599000);
    });

    test(file + ': malformed HTTP 200 stops polling instead of resetting the retry budget', async () => {
        const f = fixture(file, { body: { status: 'error' } });
        f.app.startStatusPolling(); await f.advance(60000);
        assert.equal(f.calls.length, 1);
        assert.equal(f.app.statusPolling, false);
    });

    test(file + ': permanent authentication failure stops requests and returns to login', async () => {
        const f = fixture(file, { httpStatus: 401 });
        f.app.startStatusPolling(); await f.advance(60000);
        assert.equal(f.calls.length, 1);
        assert.equal(f.context.window.location.href, '/login');
    });

    test(file + ': ban and completion stop polling and redirect once', async () => {
        for (const status of ['ban', 'finished']) {
            const f = fixture(file, { body: { status, message: 'Pengawas' } });
            f.app.startStatusPolling(); await f.advance(60000);
            assert.equal(f.calls.length, 1);
            assert.equal(f.app.realtimeStopped, true);
            assert.equal(f.messages.length, 1);
            assert.match(f.context.window.location.href, status === 'ban' ? /login$/ : /dashboard$/);
        }
    });

    test(file + ': polling applies the server deadline and stops on ejection', async () => {
        const f = fixture(file);
        f.app.startStatusPolling(); await f.advance(1000);
        assert.ok(f.app.endTimeMs >= 3600000 && f.app.endTimeMs <= 3601000);
        f.app.applyExamStatus({ status: 'ejected', message: 'Pengawas menghentikan ujian' });
        const count = f.calls.length;
        await f.advance(60000);
        assert.equal(f.calls.length, count);
        assert.equal(f.app.realtimeStopped, true);
        if (file.startsWith('public/')) assert.equal(f.app.ejected, 'Pengawas menghentikan ujian');
    });
}

const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');

const files = ['public/assets/exam-app.js', 'app/Views/student/exam/take.php'];

function fixture(file, options = {}) {
    const source = readFileSync(resolve(__dirname, '../..', file), 'utf8').replace(/<\?=([\s\S]*?)\?>/g, (_, php) => {
        if (php.includes("base_url('/api/exam/status')")) return '/api/exam/status';
        if (php.includes('$wsUrl')) return 'ws://localhost';
        if (/base_url\(['"]\/?login['"]/.test(php)) return '/login';
        if (/base_url\(['"]\/?student\/dashboard['"]/.test(php)) return '/dashboard';
        return '0';
    });

    const names = [
        'startTimer',
        'initWebSocket',
        'connectWebSocket',
        'reconnectWebSocket',
        'armWebSocketTimeout',
        'startStatusPolling',
        'stopStatusPolling',
        'pollExamStatus',
        'applyExamStatus',
        'handleCredentialGrace',
        'stopRealtime',
    ];

    const methods = names.flatMap(name => {
        const m = new RegExp('^([ ]+)(?:async )?' + name + '\\(', 'm').exec(source);
        if (!m) return [];
        return [source.slice(m.index, source.indexOf('\n' + m[1] + '}', m.index) + m[1].length + 2)];
    });

    let now = options.initialNow || 0;
    let serial = 0;
    const timers = new Map();
    const sockets = [];
    const calls = [];
    const messages = [];
    const redirects = [];
    const enqueued = [];
    let flushPendingCalls = 0;

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
            __examData: { wsToken: 'test-token' },
            location: {},
        },
        navigator: { onLine: true },
        document: { fullscreenElement: null },
        ATTEMPT_ID: 21,
        API: options.apiBase ?? '',
        EXAM_CONFIG: { testId: 5, wsUrl: 'ws://localhost' },
        LOGIN_URL: '/login',
        DASHBOARD_URL: '/dashboard',
        durationMin: 60,
        beginTimeMs: 0,
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
        fetch: async (url, opts) => {
            calls.push({ at: now, url, opts });
            const status = options.httpStatus || 200;
            return {
                ok: status < 400,
                status,
                headers: new Headers(),
                json: async () => options.body || {
                    status: 'success',
                    duration_minutes: 60,
                    end_time_ms: 3600000,
                    server_time_ms: now,
                    exam_mode: 'normal',
                },
            };
        },
    };

    const begin = source.indexOf('    const APP_CFG =');
    const end = source.indexOf('async function logoutAndRedirect', begin);
    const app = vm.runInNewContext(source.slice(begin, end) + '\n({' + methods.join(',\n') + '})', context);

    Object.assign(app, {
        activeQueue: Promise.resolve(),
        durationMinutes: 60,
        syncRevision: options.dirtySync ? 2 : 0,
        syncedRevision: 0,
        modeDriftTarget: () => null,
        showEjected(message) { app.ejected = message; },
        flushPendingSaves() { flushPendingCalls++; },
        enqueueRequest(action, params) {
            enqueued.push({ at: now, action, params });
            return app.activeQueue;
        },
    });

    async function flush() {
        for (let i = 0; i < 30; i++) await Promise.resolve();
    }

    async function advance(ms) {
        const until = now + ms;
        for (let i = 0; i < 200; i++) {
            await flush();
            const next = [...timers]
                .filter(([, t]) => t.at <= until)
                .sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            timers.delete(next[0]);
            now = next[1].at;
            next[1].callback();
        }
        now = until;
        await flush();
    }

    return {
        app,
        sockets,
        calls,
        messages,
        redirects,
        enqueued,
        getFlushPendingCalls: () => flushPendingCalls,
        context,
        advance,
    };
}

for (const file of files) {
    test(file + ': cred_updated WebSocket shows non-blocking toast, flushes 5s early, and logs out at 10m', async () => {
        const f = fixture(file, { dirtySync: true });
        f.app.connectWebSocket('ws://localhost');
        f.sockets[0].open();

        f.sockets[0].onmessage({
            data: JSON.stringify({
                event: 'cred_updated',
                data: {
                    grace_until_ms: 600000,
                    server_time_ms: 0,
                    message: 'Informasi akun Anda telah diperbarui.',
                },
            }),
        });

        assert.equal(f.messages.length, 1, 'Warning toast must be shown immediately');
        assert.equal(f.messages[0][0].toast, true, 'Warning must be non-blocking toast');
        assert.equal(f.redirects.length, 0, 'Must not log out before 10-minute TTL');
        assert.ok(!f.app.realtimeStopped);

        await f.advance(594000);
        assert.equal(f.getFlushPendingCalls(), 0);
        assert.equal(f.redirects.length, 0);

        // At 595s (5s before deadline), pending saves and dirty sync flush while session is still valid
        await f.advance(1000);
        assert.equal(f.getFlushPendingCalls(), 1);
        assert.ok(f.enqueued.some(e => e.action === 'sync'));
        assert.equal(f.redirects.length, 0);

        // At 600s, realtime stops and client logs out to /login
        await f.advance(5000);
        assert.equal(f.app.realtimeStopped, true);
        assert.deepEqual(f.redirects, ['/login']);
    });

    test(file + ': polling cred_grace_until_ms deduplicates warning and respects skewed device clock', async () => {
        // Device clock is 2 minutes ahead of server (now=120000, server_time_ms=0, grace_until_ms=600000 -> 600s left)
        const f = fixture(file, { initialNow: 120000 });

        f.app.applyExamStatus({
            status: 'success',
            duration_minutes: 60,
            end_time_ms: 3600000,
            server_time_ms: 0,
            exam_mode: 'normal',
            cred_grace_until_ms: 600000,
        });

        // Second poll with identical cred_grace_until_ms must NOT show duplicate toast
        f.app.applyExamStatus({
            status: 'success',
            duration_minutes: 60,
            end_time_ms: 3600000,
            server_time_ms: 10000,
            exam_mode: 'normal',
            cred_grace_until_ms: 600000,
        });

        assert.equal(f.messages.length, 1, 'Toast must only fire once per grace deadline');
        await f.advance(500000);
        assert.equal(f.redirects.length, 0, 'Skewed fast device clock must not expire 2 minutes early');

        await f.advance(100000);
        assert.deepEqual(f.redirects, ['/login']);
    });

    test(file + ': stopRealtime cancels pending credential grace timers', async () => {
        const f = fixture(file);
        f.app.connectWebSocket('ws://localhost');
        f.sockets[0].open();
        f.sockets[0].onmessage({
            data: JSON.stringify({
                event: 'cred_updated',
                data: { grace_until_ms: 600000, server_time_ms: 0, message: 'Informasi akun diperbarui' },
            }),
        });

        assert.equal(f.messages.length, 1);
        f.app.stopRealtime();
        await f.advance(610000);
        assert.equal(f.redirects.length, 0, 'Cancelled grace timer must not redirect after exam finished/stopped');
    });
}

test('public/assets/exam-app.js: kiosk bundle redirects to local login.html instead of remote /login', async () => {
    const f = fixture('public/assets/exam-app.js', { kioskBundle: true, apiBase: 'https://cbt.example.sch.id' });
    f.app.connectWebSocket('ws://localhost');
    f.sockets[0].open();
    f.sockets[0].onmessage({
        data: JSON.stringify({
            event: 'cred_updated',
            data: { grace_until_ms: 600000, server_time_ms: 0, message: 'Informasi akun diperbarui' },
        }),
    });

    await f.advance(600000);
    assert.deepEqual(f.redirects, ['login.html']);

    const f401 = fixture('public/assets/exam-app.js', { kioskBundle: true, apiBase: 'https://cbt.example.sch.id', httpStatus: 401 });
    f401.app.startStatusPolling();
    await f401.advance(60000);
    assert.deepEqual(f401.redirects, ['login.html']);
});

// Run: node --test src/tests/Resilience/exam-retry.test.cjs
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');

const files = ['public/assets/exam-app.js', 'app/Views/student/exam/take.php'];

function method(source, name) {
    const match = new RegExp('^([ ]+)(?:async )?' + name + '\\(', 'm').exec(source);
    assert.ok(match, 'Missing method ' + name);
    const end = source.indexOf('\n' + match[1] + '}', match.index);
    return source.slice(match.index, end + match[1].length + 2);
}

function fixture(file, responses, attemptId = 21) {
    const source = readFileSync(resolve(__dirname, '../..', file), 'utf8').replace(/<\?=[\s\S]*?\?>/g, '0');
    const begin = source.indexOf('    const APP_CFG =');
    const end = source.indexOf('async function logoutAndRedirect', begin);
    const timers = new Map();
    const calls = [];
    const delays = [];
    let now = 0, nextTimer = 0;
    const context = {
        window: { APP_CONFIG: {}, location: {} },
        navigator: { onLine: true }, document: { fullscreenElement: null },
        ATTEMPT_ID: attemptId, API: '', EXAM_CONFIG: {}, FINISH_URL: '/finish',
        FormData, AbortSignal, console: { log() {}, warn() {}, error() {} },
        Date: class extends Date { static now() { return now; } },
        setTimeout(callback, delay) {
            const id = ++nextTimer;
            delays.push(delay);
            timers.set(id, { callback, at: now + delay });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
        buildFormData: value => value, updateCsrf() {},
        Swal: { fire: () => Promise.resolve() },
    };
    function response() {
        calls.push(now);
        const value = responses[Math.min(calls.length - 1, responses.length - 1)];
        return { ...value, getResponseHeader: name => name === 'Retry-After' ? value.retryAfter || null : null };
    }
    context.fetch = async () => {
        const value = response();
        if (!value.status) throw new TypeError('Network error');
        return { ok: value.status < 400, status: value.status, headers: { get: name => value.getResponseHeader(name) } };
    };
    context.$ = { ajax(options) {
        const callbacks = {};
        const chain = {};
        for (const name of ['done', 'fail', 'always']) chain[name] = cb => { callbacks[name] = cb; return chain; };
        const value = response();
        Promise.resolve().then(() => {
            const ok = value.status >= 200 && value.status < 300;
            const data = value.body || { status: 'success' };
            if (ok) { options.success?.(data); callbacks.done?.(data); }
            else { options.error?.(value); callbacks.fail?.(value); }
            callbacks.always?.(ok ? data : value);
        });
        return chain;
    } };
    const names = ['performNetworkRequest', 'enqueueRequest', 'enterStorageDown', 'retryStorage', 'exitStorageDown', 'reconnectWebSocket', 'submitFinish'];
    for (const name of ['qKey', 'markSaved', 'markUnsaved', 'readServerMessage', 'scheduleStorageRetry']) {
        if (new RegExp('^ +(?:async )?' + name + '\\(', 'm').test(source)) names.push(name);
    }
    const code = source.slice(begin, end) + '\n({ fetchWithRetry, app: {' + names.map(name => method(source, name)).join(',\n') + '} })';
    const { app, fetchWithRetry } = vm.runInNewContext(code, context);
    Object.assign(app, {
        questions: [{ log_id: 31, question_id: 41, question_type: 3, answer_text: 'jawaban' }],
        currentQuestion: { log_id: 31 }, unsavedIds: {}, unsavedCount: 0,
        storagePending: {}, storageDown: false, storageRetrying: false,
        activeQueue: Promise.resolve(), saveLocalBackup() {}, scheduleAutoSync() {}, flushPendingSaves() {},
    });
    async function advance() {
        for (let i = 0; i < 20; i++) await Promise.resolve();
        if (!timers.size) return false;
        const [id, timer] = [...timers].sort((a, b) => a[1].at - b[1].at)[0];
        timers.delete(id);
        now = timer.at;
        timer.callback();
        return true;
    }
    async function settle(promise) {
        let done = false, error;
        Promise.resolve(promise).then(() => { done = true; }, err => { done = true; error = err; });
        for (let i = 0; i < 100 && !done; i++) await advance();
        assert.ok(done, 'Request did not settle');
        if (error) throw error;
    }
    async function drain() {
        for (let i = 0; i < 100; i++) if (!await advance()) return;
        assert.fail('Automatic retries never stop');
    }
    return { app, fetchWithRetry, calls, delays, settle, drain };
}

for (const file of files) {
    test(file + ': storage outage stops after three retries and retains the answer', async () => {
        const f = fixture(file, [{ status: 503 }]);
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        await f.drain();
        assert.equal(f.calls.length, 4);
        assert.equal(f.app.storageDown, true);
        assert.equal(f.app.questions[0].answer_text, 'jawaban');
        assert.equal(f.app.saveState, 'failed');
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        assert.equal(f.calls.length, 4, 'Queued saves must not restart the exhausted retry budget');
    });

    test(file + ': retries are deterministic and spread across student attempts', async () => {
        async function schedule(id) {
            const f = fixture(file, [{ status: 500 }], id);
            await assert.rejects(f.settle(f.fetchWithRetry('/test')));
            assert.equal(f.calls.length, 4);
            assert.ok(f.delays[0] >= 1000 && f.delays[0] <= 2000);
            assert.ok(f.delays[1] >= 2000 && f.delays[1] <= 4000);
            assert.ok(f.delays[2] >= 4000 && f.delays[2] <= 8000);
            return f.delays;
        }
        assert.deepEqual(await schedule(21), await schedule(21));
        const schedules = await Promise.all(Array.from({ length: 100 }, (_, i) => schedule(i + 1)));
        assert.ok(new Set(schedules.map(x => x[0])).size > 80, 'Students retry in the same time slot');
    });

    test(file + ': permanent fetch errors do not retry', async () => {
        const f = fixture(file, [{ status: 403 }]);
        await assert.rejects(f.settle(f.fetchWithRetry('/test')));
        assert.equal(f.calls.length, 1);
    });

    test(file + ': autosave respects Retry-After on 429', async () => {
        const f = fixture(file, [{ status: 429, retryAfter: '10' }, { status: 200 }]);
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        assert.equal(f.calls.length, 2);
        assert.ok(f.calls[1] >= 10000, 'Retry was sent before the rate limit window expired');
    });

    test(file + ': WebSocket reconnects stop at three', () => {
        const f = fixture(file, [{ status: 200 }]);
        for (let i = 0; i < 4; i++) f.app.reconnectWebSocket('ws://example.test');
        assert.equal(f.delays.length, 3);
    });

    test(file + ': manual recovery saves all pending answers once even on double click', async () => {
        const f = fixture(file, [503, 503, 503, 503, 200, 200].map(status => ({ status })));
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        f.app.questions.push({ log_id: 32, question_id: 42, question_type: 3, answer_text: 'kedua' });
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 32, retries: 3 }));
        await f.settle(Promise.all([f.app.retryStorage(), f.app.retryStorage()]));
        assert.equal(f.calls.length, 6);
        assert.equal(f.app.storageDown, false);
        assert.equal(f.app.storageRetrying, false);
        assert.equal(f.app.saveState, 'saved');
    });

    test(file + ': network failures remain bounded even with a larger requested budget', async () => {
        const f = fixture(file, [{ status: 0 }]);
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 99 }));
        await f.drain();
        assert.equal(f.calls.length, 4);
        assert.equal(f.app.saveState, 'failed');
    });

    test(file + ': rejected HTTP 200 response is never marked saved or retried', async () => {
        const f = fixture(file, [{ status: 200, body: { status: 'error', message: 'Jawaban tidak valid' } }]);
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        assert.equal(f.calls.length, 1);
        assert.equal(f.app.saveState, 'failed');
    });

    test(file + ': a new operation observes the rate limit cooldown after retries are exhausted', async () => {
        const f = fixture(file, [429, 429, 429, 429, 200].map(status => ({ status, retryAfter: '10' })));
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        await f.settle(f.fetchWithRetry('/another-operation'));
        assert.equal(f.calls.length, 5);
        assert.ok(f.calls[4] - f.calls[3] >= 10000);
    });

    test(file + ': invalid Retry-After still waits for the default rate limit window', async () => {
        const f = fixture(file, [{ status: 429, retryAfter: 'invalid' }, { status: 200 }]);
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        assert.ok(f.calls[1] >= 10000);
    });

    test(file + ': finish is blocked after retry exhaustion leaves unsaved answers', async () => {
        const f = fixture(file, [{ status: 503 }]);
        await f.settle(f.app.performNetworkRequest('autosave', { logId: 31, retries: 3 }));
        await f.settle(f.app.submitFinish());
        assert.equal(f.calls.length, 4, 'An exam with a failed save must not be submitted');
    });
}

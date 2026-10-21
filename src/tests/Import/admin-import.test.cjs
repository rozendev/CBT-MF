const { readFileSync, existsSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');
const path = resolve(__dirname, '../../public/assets/admin-import.js');

function fixture(responses, saved = new Map()) {
    const calls = [], delays = [];
    const context = {
        window: {}, FormData, AbortSignal, Uint8Array, crypto: require('node:crypto').webcrypto,
        localStorage: { get length() { return saved.size; }, key: i => [...saved.keys()][i], getItem: k => saved.get(k) || null, setItem: (k, v) => saved.set(k, v), removeItem: k => saved.delete(k) },
        setTimeout: (cb, ms) => { delays.push(ms); Promise.resolve().then(cb); },
        fetch: async (url, options = {}) => {
            calls.push({ url, options });
            const value = responses[Math.min(calls.length - 1, responses.length - 1)];
            if (value.error) throw new TypeError('Response lost');
            if (value.wait) await value.wait;
            return { ok: value.code < 400, status: value.code, headers: new Headers(value.headers || {}), json: async () => value.body };
        },
    };
    assert.ok(existsSync(path), 'Admin import polling helper is missing');
    vm.runInNewContext(readFileSync(path, 'utf8'), context);
    return { api: context.window.AdminImport, calls, delays, saved };
}

test('accepted imports only poll status; the browser never executes batches', async () => {
    const f = fixture([
        { code: 202, body: { job_id: 'abc', status: 'queued' } },
        { code: 200, body: { job_id: 'abc', status: 'running', progress: { processed: 5, total: 10 } } },
        { code: 200, body: { job_id: 'abc', status: 'completed', result: { status: 'success', success: 10 } } },
    ]);
    const updates = [];
    const result = await f.api.submit('/import', new FormData(), '/status/', 'owner:students', job => updates.push(job));
    assert.equal(result.success, 10);
    assert.deepEqual(f.calls.map(x => x.options.method || 'GET'), ['POST', 'GET', 'GET']);
    assert.equal(updates[1].progress.processed, 5);
    assert.equal(f.saved.size, 0);
});

test('status outage stops polling after three retries and keeps the accepted job for resume', async () => {
    const f = fixture([{ code: 202, body: { job_id: 'abc', status: 'queued' } }, { code: 503, body: { message: 'busy' } }]);
    await assert.rejects(f.api.submit('/import', new FormData(), '/status/', 'owner:students', () => {}));
    assert.equal(f.calls.length, 5);
    assert.ok([...f.saved.values()].some(value => JSON.parse(value).job_id === 'abc'));
});

test('polling 403 is permanent and does not retry', async () => {
    const f = fixture([{ code: 403, body: { message: 'Forbidden' } }]);
    f.saved.set('owner:students::req', JSON.stringify({ request_id: 'req', job_id: 'abc' }));
    await assert.rejects(f.api.resume('/status/', 'owner:students', () => {}));
    assert.equal(f.calls.length, 1);
    assert.equal(f.saved.size, 0);
});

test('a lost upload response can be resumed by saved request identity without reupload', async () => {
    const saved = new Map();
    const first = fixture([{ error: true }], saved);
    await assert.rejects(first.api.submit('/import', new FormData(), '/status/', 'owner:students', () => {}));
    const requestId = first.calls[0].options.body.get('import_request_id');
    assert.ok([...saved.values()].some(value => JSON.parse(value).request_id === requestId));
    const reopened = fixture([{ code: 200, body: { job_id: 'accepted', status: 'completed', result: { status: 'success' } } }], saved);
    await reopened.api.resume('/status/', 'owner:students', () => {});
    assert.equal(reopened.calls[0].url, '/request/' + requestId);
    assert.equal(reopened.calls[0].options.method || 'GET', 'GET');
    assert.equal(saved.size, 0);
});

test('concurrent tabs keep immutable job IDs and clear only their own stored job', async () => {
    const saved = new Map();
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    const first = fixture([
        { code: 202, body: { job_id: 'one', status: 'queued' } },
        { code: 200, wait: gate, body: { job_id: 'one', status: 'running' } },
        { code: 200, body: { job_id: 'one', status: 'completed', result: { status: 'success' } } },
    ], saved);
    const firstRun = first.api.submit('/import', new FormData(), '/status/', 'owner:students', () => {});
    for (let i = 0; i < 30; i++) await Promise.resolve();
    const second = fixture([{ code: 202, body: { job_id: 'two', status: 'completed', result: { status: 'success' } } }], saved);
    await second.api.submit('/import', new FormData(), '/status/', 'owner:students', () => {});
    assert.ok([...saved.values()].some(value => JSON.parse(value).job_id === 'one'));
    release(); await firstRun;
    assert.deepEqual(first.calls.slice(1).map(call => call.url), ['/status/one', '/status/one']);
    assert.equal(saved.size, 0);
});

test('rate limited submissions respect Retry-After and reuse one request identity', async () => {
    const f = fixture([{ code: 429, headers: { 'Retry-After': '10' }, body: {} }, { code: 202, body: { job_id: 'abc', status: 'completed', result: { status: 'success' } } }]);
    await f.api.submit('/import', new FormData(), '/status/', 'owner:students', () => {});
    assert.ok(f.delays[0] >= 10000);
    assert.equal(f.calls[0].options.body.get('import_request_id'), f.calls[1].options.body.get('import_request_id'));
});

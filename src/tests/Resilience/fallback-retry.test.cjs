const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');
const source = readFileSync(resolve(__dirname, '../../app/Views/admin/static/static_exam_template.php'), 'utf8');

function fixture(status, retryAfter = null) {
    const start = source.indexOf('        var ENGAGE_AFTER_MS');
    const end = source.indexOf('        function engage(', start);
    const saveStart = source.indexOf('        function doSave(');
    const saveEnd = source.indexOf('        function startHeartbeat(', saveStart);
    let calls = 0, now = 0;
    const delays = [], times = [];
    const context = {
        window: {}, FormData, AbortSignal, setInterval() {},
        Date: class extends Date { static now() { return now; } },
        currentQ: () => ({ question_id: 41, question_type: 3, answer_text: 'jawaban' }),
        setSaveStatus() {},
        setTimeout(callback, delay) {
            delays.push(delay);
            if (delays.length > 10) throw new Error('Retry storm');
            now += delay;
            Promise.resolve().then(callback);
        },
        fetch: async () => {
            calls++;
            times.push(now);
            const code = Array.isArray(status) ? status[Math.min(calls - 1, status.length - 1)] : status;
            return { ok: code < 400, status: code, headers: new Headers(retryAfter ? { 'Retry-After': retryAfter } : {}), json: async () => ({ status: code < 400 ? 'success' : 'error' }) };
        },
    };
    const heartbeatEnd = source.indexOf('        function tick()', saveEnd);
    const app = vm.runInNewContext(source.slice(start, end) + source.slice(saveStart, heartbeatEnd) + `
        engaged = true; state = { attemptId: 21, csrfHash: '', answers: {} };
        ({ doSave, startHeartbeat, failed: () => saveFailed, pending: () => Object.keys(pendingSaves) });`, context);
    return { app, context, delays, times, calls: () => calls };
}

test('fallback stops after three retries and does not restart for queued autosaves', async () => {
    const f = fixture(503);
    await f.app.doSave();
    for (let i = 0; i < 50; i++) await Promise.resolve();
    assert.equal(f.calls(), 4);
    assert.equal(f.app.failed(), true);
    await f.app.doSave();
    assert.equal(f.calls(), 4);
});

test('fallback honors Retry-After and never reports HTTP errors as saved', async () => {
    const f = fixture(429, '10');
    await f.app.doSave();
    assert.equal(f.app.failed(), true);
    assert.equal(f.calls(), 4);
    for (let i = 1; i < f.times.length; i++) assert.ok(f.times[i] - f.times[i - 1] >= 10000);
});

test('fallback retains a failed newer save after an earlier queued save succeeded', async () => {
    const f = fixture([200, 503, 503, 503, 503]);
    await Promise.all([f.app.doSave(), f.app.doSave()]);
    assert.equal(f.calls(), 5);
    assert.equal(f.app.pending().length, 1, 'Manual recovery must still have the failed question');
});

test('fallback sync is a separate task and stays idle until an answer changes',async()=>{
    const f=fixture(200), jobs=[];
    f.context.window.CbtPolling={pace:async()=>{},signal:()=>AbortSignal.timeout(20000),create(options){jobs.push(options);return{start(){return this;},stop(){}};}};
    f.app.startHeartbeat();
    const sync=jobs.find(job=>job.key==='fallback-sync');
    assert.ok(sync,'Fallback must register a separate change-driven sync task');
    assert.equal(sync.when(),false);
    await f.app.doSave();assert.equal(sync.when(),true);
    await sync.run();assert.equal(sync.when(),false);
    assert.equal(f.calls(),2);
});

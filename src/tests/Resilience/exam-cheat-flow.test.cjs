const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');

const files = ['app/Views/student/exam/take.php', 'public/assets/exam-app.js'];
function fixture(file) {
    const isStatic = file.endsWith('.js');
    let source = readFileSync(resolve(__dirname, '../..', file), 'utf8');
    source = source.replace(/<\?php if \(!\$isAntiCheatEnabled[\s\S]*?<\?php endif; \?>/g, '')
        .replace(/<\?=[\s\S]*?\?>/g, () => JSON.stringify({ enabled: true, max_strikes: 3, auto_submit_on_cheat: false }))
        .replace(/<\?php[\s\S]*?\?>/g, '');
    const marker = isStatic ? '// ═══ ANTI-CHEAT ENGINE' : '//  3. ANTI-CHEAT ENGINE';
    const begin = source.indexOf('(function() {', source.indexOf(marker));
    const end = isStatic ? source.indexOf('// ═══ BOOT', begin) : source.indexOf('//  4. IMAGE LIGHTBOX', begin);
    const handlers = { document: {}, window: {} }, calls = [], alerts = [], redirects = [];
    const timers = new Map(); let serial = 0, now = 100000;
    const elements = Object.fromEntries(['examContent', 'suspendOverlay', 'strikeCount', 'maxStrikes', 'suspendTimerDisplay'].map(key => [key, { style: { display: key === 'suspendOverlay' ? 'none' : 'block' } }]));
    const c = {
        examStarted: true, isSuspended: false, isLocked: false, isSubmitting: false,
        ATTEMPT_ID: 21, API: '', REPORT_CHEAT_URL: '/report', LOGIN_URL: '/login', DASHBOARD_URL: '/dashboard', FETCH_TIMEOUT_MS: 15000,
        EXAM_CONFIG: { antiCheat: { enabled: true, max_strikes: 3 } }, APP_CONFIG: {},
        screen: { width: 1280, height: 720 }, innerWidth: 1280, innerHeight: 720,
        document: {
            hidden: false, visibilityState: 'visible', fullscreenElement: {},
            hasFocus: () => true, getElementById: id => elements[id],
            addEventListener: (name, callback) => (handlers.document[name] ??= []).push(callback),
            exitFullscreen() { this.fullscreenElement = null; fire('document', 'fullscreenchange'); return Promise.resolve(); },
        },
        addEventListener: (name, callback) => (handlers.window[name] ??= []).push(callback),
        Date: class extends Date { static now() { return now; } }, AbortSignal, FormData,
        requestAnimationFrame() {},
        setTimeout(fn, delay) { const id = ++serial; timers.set(id, { fn, at: now + delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        setInterval(fn, delay) { const id = ++serial; timers.set(id, { fn, at: now + delay, interval: delay }); return id; },
        clearInterval(id) { timers.delete(id); },
        $: { ajax(opts) { calls.push({ opts }); } },
        fetch(url, opts) { return new Promise(resolve => calls.push({ url, opts, resolve })); },
        buildFormData: value => value, updateCsrf() {},
        Swal: { fire(...args) { alerts.push(args); return Promise.resolve(); } },
        logoutAndRedirect(url) { redirects.push(url); return Promise.resolve(); },
        redirectReplace: url => redirects.push(url), location: { href: '', replace: url => redirects.push(url) },
        console: { warn() {}, error() {}, log() {} },
    };
    c.window = c;
    vm.createContext(c);
    if (isStatic) {
        const helpers = source.slice(source.indexOf('    const APP_CFG ='), source.indexOf('    async function logoutAndRedirect'));
        vm.runInContext(helpers, c);
    }
    vm.runInContext(source.slice(begin, end).replace(/\/\/ ═+\s*$/, ''), c);
    const methods = ['initRealtimeLifecycle', 'stopRealtime'].map(name => {
        const match = new RegExp('^([ ]+)' + name + '\\(', 'm').exec(source);
        return source.slice(match.index, source.indexOf('\n' + match[1] + '}', match.index) + match[1].length + 2);
    });
    const realtime = vm.runInContext('({' + methods.join(',\n') + '})', c);
    Object.assign(realtime, { stopStatusPolling() {}, initWebSocket() {}, scheduleAutoSync() {}, enqueueRequest() {} });
    function fire(target, name) { for (const fn of handlers[target][name] || []) fn(); }
    async function flush() { for (let i = 0; i < 40; i++) await Promise.resolve(); }
    async function respond(data, status = 200) {
        await flush();
        const call = calls.at(-1);
        if (isStatic) call.resolve({ ok: status < 400, status, headers: new Headers(), json: async () => data });
        else if (status < 400) call.opts.success(data);
        else call.opts.error({ status, responseJSON: data });
        await flush();
    }
    async function advance(ms) {
        const until = now + ms;
        for (let i = 0; i < 500; i++) {
            await flush();
            const next = [...timers].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            const [id, timer] = next; now = timer.at;
            if (timer.interval) timer.at += timer.interval; else timers.delete(id);
            timer.fn();
        }
        now = until; await flush();
    }
    return { c, calls, alerts, redirects, elements, fire, flush, respond, advance, realtime };
}

for (const file of files) {
    test(file + ': warning 1/3 suspends then resumes without logout', async () => {
        const f = fixture(file); f.fire('window', 'blur'); await f.flush();
        await f.respond({ status: 'success', action: 'suspend', current_strikes: 1, max_strikes: 3, strike: 1, timer: 30 });
        assert.equal(f.elements.suspendOverlay.style.display, 'flex');
        assert.equal(String(f.elements.strikeCount.innerText), '1');
        assert.deepEqual(f.redirects, []);
        await f.advance(30000);
        assert.equal(f.elements.suspendOverlay.style.display, 'none');
        assert.equal(f.elements.examContent.style.display, 'block');
        assert.equal(f.calls.length, 1);
    });
    test(file + ': one focus loss burst produces one request', async () => {
        const f = fixture(file); f.c.document.hidden = true; f.c.document.visibilityState = 'hidden';
        f.fire('document', 'visibilitychange'); f.fire('window', 'blur'); f.fire('document', 'fullscreenchange');
        await f.flush(); assert.equal(f.calls.length, 1);
    });
    test(file + ': a later separate violation still reports after suspend', async () => {
        const f = fixture(file); f.fire('window', 'blur'); await f.flush();
        await f.respond({ status: 'success', action: 'suspend', current_strikes: 1, max_strikes: 3, timer: 2 });
        await f.advance(3000); f.fire('window', 'blur'); await f.flush();
        assert.equal(f.calls.length, 2);
    });
    test(file + ': a recorded fullscreen exit does not become modified-browser during suspend', async () => {
        const f = fixture(file); f.fire('document', 'fullscreenchange');
        f.c.document.fullscreenElement = null; f.fire('document', 'fullscreenchange'); await f.flush();
        await f.respond({ status: 'success', action: 'suspend', current_strikes: 1, max_strikes: 3, strike: 1, timer: 30 });
        await f.advance(20000); assert.equal(f.calls.length, 1);
    });
    test(file + ': storage failure neither retries violation nor claims a ban', async () => {
        const f = fixture(file); f.fire('window', 'blur'); await f.flush();
        await f.respond({ status: 'error', message: 'write failed' }, 503);
        await f.advance(60000);
        assert.equal(f.calls.length, 1); assert.deepEqual(f.redirects, []);
        assert.equal(f.elements.examContent.style.display, 'block');
        assert.ok(f.alerts.some(args => JSON.stringify(args).includes('Laporan')));
    });
    test(file + ': terminal event wins over a late warning response', async () => {
        const f = fixture(file); f.fire('window', 'blur'); await f.flush();
        f.c.__antiCheat.stop();
        await f.respond({ status: 'success', action: 'suspend', current_strikes: 1, timer: 2 });
        await f.advance(3000);
        assert.equal(f.elements.examContent.style.display, 'none');
        assert.equal(f.elements.suspendOverlay.style.display, 'none');
        f.fire('window', 'blur'); await f.flush(); assert.equal(f.calls.length, 1);
    });
    test(file + ': pagehide and BFCache restore keep the exam usable', async () => {
        const f = fixture(file); f.realtime.initRealtimeLifecycle();
        f.fire('window', 'pagehide'); f.fire('window', 'pageshow');
        assert.equal(f.elements.examContent.style.display, 'block');
        f.fire('window', 'blur'); await f.flush(); assert.equal(f.calls.length, 1);
        f.realtime.stopRealtime();
        assert.equal(f.elements.examContent.style.display, 'none');
    });
    test(file + ': BFCache return preserves an ongoing suspend', async () => {
        const f = fixture(file); f.realtime.initRealtimeLifecycle();
        f.fire('window', 'blur'); await f.flush();
        await f.respond({ status: 'success', action: 'suspend', current_strikes: 1, timer: 30 });
        f.fire('window', 'pagehide'); f.fire('window', 'pageshow');
        assert.equal(f.elements.suspendOverlay.style.display, 'flex');
        await f.advance(30000);
        assert.equal(f.elements.examContent.style.display, 'block');
        assert.equal(f.calls.length, 1);
    });
    test(file + ': none keeps working, lock logs out, auto-submit goes to results', async () => {
        for (const action of ['none', 'lock', 'auto_submitted']) {
            const f = fixture(file); f.fire('window', 'blur'); await f.flush();
            await f.respond({ status: 'success', action, current_strikes: action === 'lock' ? 3 : 0, max_strikes: 3, message: 'Server decision', redirect: '/results' });
            if (action === 'none') assert.deepEqual(f.redirects, []);
            else if (action === 'lock') assert.deepEqual(f.redirects, ['/login']);
            else assert.ok(f.redirects.includes('/results') || f.c.location.href === '/results');
        }
    });
}

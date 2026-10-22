/* Page-owned polling: no service worker and no polling after the page leaves. */
(function () {
    if (window.CbtPolling) return;
    const jobs = new Set();
    const csrfName = document.currentScript?.dataset.csrfName;
    let seed = document.currentScript?.dataset.client;
    if (!seed) {
        try {
            seed = sessionStorage.getItem('cbt_poll_client') || crypto.randomUUID();
            sessionStorage.setItem('cbt_poll_client', seed);
        } catch (_) { seed = window.location.pathname + ':' + Date.now(); }
    }
    let lastActivity = Date.now(), suspended = false, page = new AbortController();
    let nextStart = 0, cooldown = 0, paceQueue = Promise.resolve(), csrf = null;
    const aborted = () => new DOMException('Page no longer active', 'AbortError');
    function jitter(key, maximum) {
        let hash = 2166136261;
        for (const char of seed + ':' + key) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619);
        return (hash >>> 0) % (maximum + 1);
    }
    function delay(task, key, base, initial = false) {
        if (document.visibilityState === 'hidden') return 180000 + jitter(task + ':' + key, 30000);
        if (Date.now() - lastActivity >= 120000) {
            const idle = task === 'exam-status' || task === 'live' ? 30000 : task === 'import' ? 15000 : 60000;
            base = Math.max(base, idle);
        }
        return base + jitter(task + ':' + key, initial ? 1000 : Math.min(1000, Math.floor(base / 10)));
    }
    function sleep(ms, signal) {
        return new Promise((resolve, reject) => {
            if (signal?.aborted) return reject(aborted());
            const finish = () => { signal?.removeEventListener('abort', cancel); resolve(); };
            const timer = setTimeout(finish, Math.max(0, ms));
            const cancel = () => { clearTimeout(timer); reject(aborted()); };
            signal?.addEventListener('abort', cancel, { once: true });
        });
    }
    function pace(signal = page.signal) {
        const result = paceQueue.then(async () => {
            if (signal.aborted) throw aborted();
            while (Math.max(nextStart, cooldown) > Date.now()) await sleep(Math.max(nextStart, cooldown) - Date.now(), signal);
            if (signal.aborted) throw aborted();
            nextStart = Date.now() + 500; // Shared by page polls and exam writes: <= 20 starts/10s.
        });
        paceQueue = result.catch(() => {});
        return result;
    }
    function defer(header, status = 429) {
        let ms = header ? (/^\d+$/.test(header) ? Number(header) * 1000 : Date.parse(header) - Date.now()) : NaN;
        if (!Number.isFinite(ms)) ms = status === 429 ? 10000 : 0;
        cooldown = Math.max(cooldown, Date.now() + Math.max(0, ms));
        return Math.max(0, ms);
    }
    function signal(timeout = 15000, extra) {
        return AbortSignal.any([page.signal, ...(extra ? [extra] : []), AbortSignal.timeout(timeout)]);
    }
    async function request(url, options = {}) {
        const lifecycle = AbortSignal.any([page.signal, ...(options.signal ? [options.signal] : [])]);
        await pace(lifecycle);
        const headers = new Headers(options.headers || {});
        if (csrf && headers.has('X-CSRF-TOKEN')) headers.set('X-CSRF-TOKEN', csrf);
        const response = await fetch(url, { ...options, headers, signal: signal(options.timeout || 15000, lifecycle) });
        csrf = response.headers?.get('X-CSRF-TOKEN') || csrf;
        if (csrf && csrfName) for (const field of document.querySelectorAll?.('input') || []) {
            if (field.name === csrfName) field.value = csrf;
        }
        if (!response.ok) {
            const retryAfter = response.headers.get('Retry-After');
            if (response.status === 429 || retryAfter) defer(retryAfter, response.status);
            throw Object.assign(new Error('HTTP ' + response.status), { status: response.status, headers: response.headers, retryAfter });
        }
        return response;
    }
    function create(options) {
        let wanted = false, running = false, timer = null, controller = null, failures = 0;
        const task = options.task || 'dashboard';
        const base = () => typeof options.interval === 'function' ? options.interval() : options.interval || 10000;
        const pause = () => { clearTimeout(timer); timer = null; controller?.abort(); };
        const schedule = (wait = base(), initial = false) => {
            clearTimeout(timer); timer = null;
            if (!wanted || running || suspended || navigator.onLine === false) return;
            timer = setTimeout(tick, Math.max(delay(task, options.key, wait, initial), cooldown - Date.now()));
        };
        async function tick() {
            timer = null;
            if (!wanted || running || suspended || navigator.onLine === false) return;
            if (options.when && !options.when()) { schedule(); return; }
            running = true; controller = new AbortController();
            let wait;
            try { await options.run(controller.signal); failures = 0; }
            catch (error) {
                if (!wanted || suspended || navigator.onLine === false || controller.signal.aborted) return;
                failures++;
                const status = error.status || 0;
                const retryAfter = error.retryAfter || error.headers?.get('Retry-After');
                if (status === 429 || retryAfter) defer(retryAfter, status);
                if (failures > 3 || !(status === 0 || status === 408 || status === 429 || status >= 500)) {
                    job.stop(); options.onError?.(error); return;
                }
                const backoff = 1000 * 2 ** (failures - 1);
                wait = Math.max(backoff + jitter(options.key + ':' + failures, backoff), cooldown - Date.now());
            } finally { running = false; if (wanted) schedule(wait); }
        }
        const job = {
            start() { if (!wanted) { failures = 0; wanted = true; jobs.add(job); schedule(options.immediate ? 0 : base(), !!options.immediate); } return job; },
            stop() { wanted = false; jobs.delete(job); pause(); },
            trigger() { if (wanted) schedule(0, true); },
            reschedule: () => schedule(), pause,
        };
        return job;
    }
    function refresh() { for (const job of jobs) job.reschedule(); }
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'hidden') lastActivity = Date.now();
        refresh();
    });
    window.addEventListener('focus', () => { lastActivity = Date.now(); refresh(); });
    for (const name of ['pointerdown', 'keydown', 'input']) document.addEventListener(name, () => {
        const wasIdle = Date.now() - lastActivity >= 120000;
        lastActivity = Date.now(); if (wasIdle) refresh();
    }, { passive: true });
    window.addEventListener('offline', () => { for (const job of jobs) job.pause(); });
    window.addEventListener('online', () => { lastActivity = Date.now(); refresh(); });
    window.addEventListener('pagehide', () => { suspended = true; page.abort(); for (const job of jobs) job.pause(); });
    window.addEventListener('pageshow', () => { if (suspended) { suspended = false; page = new AbortController(); lastActivity = Date.now(); refresh(); } });
    window.CbtPolling = {
        create, delay, pace, request, signal, defer,
        suspended: () => suspended || navigator.onLine === false,
        wait(task, key, interval) {
            return new Promise(resolve => {
                const job = create({ task, key, interval, run() { job.stop(); resolve(); } }).start();
            });
        },
    };
})();

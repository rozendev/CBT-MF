/* Admin uploads create one durable job; subsequent requests only read progress. */
(function () {
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const active = new Map();
    // One record per request: tabs cannot overwrite or clear each other's jobs.
    const memory = new Map();
    const storage = {
        save(key, state) {
            const name = key + '::' + state.request_id;
            const value = JSON.stringify(state);
            memory.set(name, value);
            try { localStorage.setItem(name, value); } catch (_) {}
        },
        clear(key, state) {
            const name = key + '::' + state.request_id;
            memory.delete(name);
            try { localStorage.removeItem(name); } catch (_) {}
        },
        latest(key) {
            const records = new Map();
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const name = localStorage.key(i);
                    if (name?.startsWith(key + '::')) records.set(name, localStorage.getItem(name));
                }
            } catch (_) { for (const pair of memory) records.set(...pair); }
            let latest = null;
            for (const [name, value] of records) {
                if (!name.startsWith(key + '::')) continue;
                try {
                    const state = JSON.parse(value);
                    if (!latest || (state.created || 0) >= (latest.created || 0)) latest = state;
                } catch (_) {}
            }
            return latest;
        },
    };

    function delay(key, attempt, response) {
        const base = 1000 * Math.pow(2, attempt);
        let hash = 2166136261;
        for (const char of key + ':' + attempt) hash = Math.imul(hash ^ char.charCodeAt(0), 16777619);
        const header = response?.headers.get('Retry-After');
        let wait = header ? (/^\d+$/.test(header) ? Number(header) * 1000 : Math.max(0, Date.parse(header) - Date.now())) : NaN;
        if (!Number.isFinite(wait)) wait = response?.status === 429 ? 10000 : 0;
        return Math.max(base, wait) + (hash >>> 0) % (base + 1);
    }

    async function request(url, options, seed) {
        for (let retry = 0; retry <= 3; retry++) {
            let response, data, error;
            try {
                if (window.CbtPolling) await window.CbtPolling.pace();
                response = await fetch(url, {
                    ...options, credentials: 'same-origin', cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', ...options.headers },
                    signal: window.CbtPolling ? window.CbtPolling.signal(30000) : AbortSignal.timeout(30000),
                });
                data = await response.json().catch(() => ({}));
                if (response.ok) return data;
                error = new Error(data.message || 'Server impor tidak dapat dihubungi.');
                error.status = response.status;
            } catch (failure) { error = failure; }
            if (window.CbtPolling?.suspended()) throw error;
            if (response?.status === 429) window.CbtPolling?.defer(response.headers.get('Retry-After'));
            const transient = !response || response.status === 408 || response.status === 429 || response.status >= 500;
            if (!transient || retry === 3) throw error;
            if (window.CbtPolling) await window.CbtPolling.wait('import', seed, delay(seed, retry, response));
            else await sleep(delay(seed, retry, response));
        }
    }

    async function poll(statusBase, key, update, state, first) {
        let job = first;
        const jobId = state.job_id; // Never reread a shared, mutable browser key while polling.
        try {
            if (!job && typeof document !== 'undefined' && document.visibilityState === 'hidden' && window.CbtPolling) await window.CbtPolling.wait('import', jobId, 0);
            while (true) {
                if (!job) job = await request(statusBase + encodeURIComponent(jobId), {}, jobId);
                update(job);
                if (job.status === 'completed') { storage.clear(key, state); return job.result; }
                if (job.status === 'failed') { storage.clear(key, state); throw new Error(job.message || 'Impor gagal.'); }
                if (!['queued', 'running', 'retrying'].includes(job.status)) throw new Error(job.message || 'Respons progres impor tidak valid.');
                if (window.CbtPolling) await window.CbtPolling.wait('import', jobId, job.status === 'running' ? 3000 : 10000);
                else await sleep((job.status === 'queued' ? 10000 : 3000) + delay(jobId, 0, null) % 1000);
                job = null;
            }
        } catch (error) {
            if ([401, 403, 404].includes(error.status)) storage.clear(key, state);
            throw error;
        }
    }

    function single(key, work) {
        if (active.has(key)) return active.get(key);
        const promise = work().finally(() => active.delete(key));
        active.set(key, promise);
        return promise;
    }

    window.AdminImport = {
        pending: key => { const state = storage.latest(key); return !!state && !state.needs_upload; },
        resume(statusBase, key, update) {
            const state = storage.latest(key);
            if (!state) return Promise.resolve(null);
            return single(key, async () => {
                if (state.job_id) return poll(statusBase, key, update, state, null);
                let job;
                try {
                    const lookup = statusBase.replace(/status\/$/, 'request/');
                    job = await request(lookup + encodeURIComponent(state.request_id), {}, state.request_id);
                } catch (error) {
                    if (error.status === 404) { state.needs_upload = true; storage.save(key, state); }
                    if ([401, 403].includes(error.status)) storage.clear(key, state);
                    throw error;
                }
                state.job_id = job.job_id;
                state.needs_upload = false;
                storage.save(key, state);
                return poll(statusBase, key, update, state, job);
            });
        },
        submit(url, formData, statusBase, key, update) {
            return single(key, async () => {
                const previous = storage.latest(key);
                const bytes = crypto.getRandomValues(new Uint8Array(16));
                const requestId = previous?.needs_upload ? previous.request_id
                    : Array.from(bytes, n => n.toString(16).padStart(2, '0')).join('');
                const state = { request_id: requestId, created: Date.now() };
                storage.save(key, state); // Durable BEFORE upload, including lost HTTP responses.
                formData.set('import_request_id', requestId);
                let job;
                try { job = await request(url, { method: 'POST', body: formData }, requestId); }
                catch (error) {
                    if (error.status && error.status < 500 && error.status !== 429) storage.clear(key, state);
                    throw error;
                }
                if (!job.job_id) { storage.clear(key, state); throw new Error(job.message || 'Server tidak memberikan ID impor.'); }
                state.job_id = job.job_id;
                storage.save(key, state);
                return poll(statusBase, key, update, state, job);
            });
        },
    };
})();

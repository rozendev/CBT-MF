const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');

function fixture() {
    let now = 0, id = 0;
    const timers = new Map(), events = { document: {}, window: {} }, calls = [];
    const document = { visibilityState: 'visible', currentScript: { dataset: { client: '21', csrfName: 'csrf_test_name' } }, addEventListener(name, fn) { (events.document[name] ||= []).push(fn); } };
    const window = { location: { pathname: '/student' }, addEventListener(name, fn) { (events.window[name] ||= []).push(fn); } };
    const navigator = { onLine: true };
    const context = { document, window, navigator, AbortSignal, AbortController, DOMException, Headers,
        Date: class extends Date { static now() { return now; } },
        setTimeout(fn, ms) { const n = ++id; timers.set(n, { fn, at: now + ms }); return n; },
        clearTimeout(n) { timers.delete(n); },
        fetch: async (url, options) => { calls.push({ at: now, url, options }); return { ok: true, status: 200 }; },
    };
    vm.runInNewContext(readFileSync(resolve(__dirname, '../../public/assets/activity-polling.js'), 'utf8'), context);
    async function flush() { for (let n = 0; n < 30; n++) await Promise.resolve(); }
    async function advance(ms) {
        const end = now + ms;
        for (let n = 0; n < 200; n++) {
            await flush();
            const next = [...timers].filter(([, t]) => t.at <= end).sort((a,b) => a[1].at-b[1].at)[0];
            if (!next) break;
            timers.delete(next[0]); now=next[1].at; next[1].fn();
        }
        now=end; await flush();
    }
    function emit(surface, name) { for (const fn of events[surface][name] || []) fn({ persisted: true }); }
    return { api: window.CbtPolling, calls, document, navigator, context, advance, emit, now: () => now };
}

test('task cadence differs and hidden pages slow to minutes', async () => {
    const f = fixture();
    const dashboard = f.api.delay('dashboard', '21', 10000);
    const progress = f.api.delay('import', 'job', 3000);
    assert.ok(dashboard >= 10000 && dashboard <= 11000);
    assert.ok(progress >= 3000 && progress <= 4000);
    f.document.visibilityState='hidden'; f.emit('document','visibilitychange');
    for (const task of ['dashboard','import','exam-status']) {
        const delay=f.api.delay(task, '21', 10000);
        assert.ok(delay >= 180000 && delay <= 210000);
    }
});

test('jitter is deterministic per task/client and spreads clients', () => {
    const f=fixture();
    assert.equal(f.api.delay('dashboard','21',10000),f.api.delay('dashboard','21',10000));
    assert.notEqual(f.api.delay('dashboard','21',10000),f.api.delay('dashboard','22',10000));
});

test('visible idle page slows after two minutes and input restores cadence', async () => {
    const f=fixture(); await f.advance(121000);
    assert.ok(f.api.delay('dashboard','21',10000) >= 60000);
    f.emit('document','keydown');
    assert.ok(f.api.delay('dashboard','21',10000) <= 11000);
    await f.advance(121000);f.emit('window','focus');
    assert.ok(f.api.delay('dashboard','21',10000) <= 11000);
    f.document.visibilityState='hidden';f.emit('document','visibilitychange');await f.advance(180000);
    f.document.visibilityState='visible';f.emit('document','visibilitychange');
    assert.ok(f.api.delay('dashboard','21',10000) <= 11000);
});

test('one slow task never overlaps itself', async () => {
    const f=fixture(); let release; const at=[];
    const hold=new Promise(resolve=>{release=resolve;});
    const job=f.api.create({key:'slow',interval:10000,run:async()=>{at.push(f.now());await hold;}}).start();
    await f.advance(60000); assert.equal(at.length,1);
    release(); await f.advance(12000); assert.equal(at.length,2); job.stop();
});

test('hiding cancels a near-term tick and closing cancels requests until BFCache restore', async () => {
    const f=fixture(); let count=0, requestSignal;
    const job=f.api.create({key:'page',interval:10000,run:async signal=>{requestSignal=signal;count++;}}).start();
    await f.advance(9000); f.document.visibilityState='hidden'; f.emit('document','visibilitychange');
    await f.advance(20000); assert.equal(count,0);
    f.document.visibilityState='visible'; f.emit('document','visibilitychange');
    await f.advance(12000); assert.equal(count,1);
    f.emit('window','pagehide'); assert.equal(requestSignal.aborted,true);
    await f.advance(600000); assert.equal(count,1);
    f.emit('window','pageshow'); await f.advance(12000); assert.equal(count,2);
    job.stop(); await f.advance(600000); assert.equal(count,2);
});

test('offline pages send nothing and resume with a stagger', async () => {
    const f=fixture(); let count=0; f.navigator.onLine=false;
    const job=f.api.create({key:'online',interval:10000,run:()=>{count++;}}).start();
    await f.advance(600000); assert.equal(count,0);
    f.navigator.onLine=true; f.emit('window','online'); await f.advance(12000); assert.equal(count,1);job.stop();
});

test('request starts across tasks share a rate budget', async () => {
    const f=fixture();
    const pending=Promise.all(Array.from({length:5},(_,i)=>f.api.request('/task/'+i)));
    await f.advance(3000); await pending;
    assert.equal(f.calls.length,5);
    for(let i=1;i<5;i++) assert.ok(f.calls[i].at-f.calls[i-1].at >= 500);
});

test('failing polling stops after three retries and visibility cannot restart it', async () => {
    const f=fixture(); let count=0;
    f.api.create({key:'failure',interval:10000,run:()=>{count++;throw Object.assign(new Error('busy'),{status:503});}}).start();
    await f.advance(120000);assert.equal(count,4);
    f.emit('document','visibilitychange');await f.advance(600000);assert.equal(count,4);
});

test('Retry-After also delays other tasks and manual checks', async () => {
    const f=fixture(); let count=0;
    f.api.create({key:'limited',interval:10000,run:()=>{count++;throw Object.assign(new Error('limited'),{status:429,retryAfter:'30'});}}).start();
    await f.advance(12000); assert.equal(count,1);
    const waiting=f.api.request('/manual');
    await f.advance(20000);assert.equal(f.calls.length,0);
    await f.advance(12000);await waiting;assert.equal(f.calls.length,1);
});

test('layout presence recognizes both dashboard aliases and base paths',async()=>{
    for(const layout of ['student','admin'])for(const path of ['/'+layout,'/'+layout+'/dashboard','/school/'+layout+'/dashboard']){
        const f=fixture();f.context.window.location.pathname=path;f.context.CbtPolling=f.api;
        const src=readFileSync(resolve(__dirname,'../../app/Views/layouts/'+layout+'.php'),'utf8');
        const start=src.indexOf('const dashboardPath'),end=src.indexOf('}).start();',start)+11;
        const js=src.slice(start,end).replace(/<\?=[\s\S]*?\?>/g,'/api/keep-alive');
        vm.runInNewContext(js,f.context);await f.advance(12000);
        assert.equal(f.calls.length,1,path+' must use dashboard cadence');
    }
});

test('periodic POSTs reuse the latest server CSRF token',async()=>{
    const f=fixture();
    const field={name:'csrf_test_name',value:'initial'};
    f.document.querySelectorAll=()=>[field];
    f.context.fetch=async(url,options)=>{f.calls.push(options);return{ok:true,status:200,headers:new Headers({'X-CSRF-TOKEN':'renewed'})};};
    const one=f.api.request('/presence',{method:'POST',headers:{'X-CSRF-TOKEN':'initial'}});await f.advance(1000);await one;
    const two=f.api.request('/presence',{method:'POST',headers:{'X-CSRF-TOKEN':'initial'}});await f.advance(1000);await two;
    assert.equal(new Headers(f.calls[1].headers).get('X-CSRF-TOKEN'),'renewed');
    assert.equal(field.value,'renewed','Keep logout and upload forms valid after periodic POSTs');
});

test('network timeout starts after Retry-After waiting, not while queued',async()=>{
    const f=fixture();
    f.context.AbortSignal={any:AbortSignal.any,timeout(ms){const controller=new AbortController();f.context.setTimeout(()=>controller.abort(new DOMException('Timed out','TimeoutError')),ms);return controller.signal;}};
    f.api.defer('30');let failure;
    const pending=f.api.request('/after-cooldown').catch(error=>{failure=error;});
    await f.advance(31000);await pending;
    assert.equal(failure,undefined);assert.equal(f.calls.length,1);
});

test('503 Retry-After is respected by every polling job',async()=>{
    const f=fixture();const calls=[];
    f.api.create({key:'maintenance',immediate:true,run(){calls.push(f.now());throw Object.assign(new Error('down'),{status:503,retryAfter:'30'});}}).start();
    await f.advance(95000);assert.equal(calls.length,4);
    for(let i=1;i<4;i++)assert.ok(calls[i]-calls[i-1]>=30000);
});

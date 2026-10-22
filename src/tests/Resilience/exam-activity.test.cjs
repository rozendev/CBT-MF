const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');
const files=['public/assets/exam-app.js','app/Views/student/exam/take.php'];
function fixture(file, failSync=false) {
    let now=0,id=0;const timers=new Map(),calls=[],events={document:{},window:{}};
    const surface=name=>({addEventListener(key,fn){(events[name][key]||=[]).push(fn);}});
    const window={...surface('window'),location:{pathname:'/student/exam/take/5'},APP_CONFIG:{},__examData:{wsToken:'token'}};
    const document={...surface('document'),visibilityState:'visible',currentScript:{dataset:{client:'21'}}};
    const context={window,document,navigator:{onLine:true},AbortSignal,AbortController,DOMException,Headers,FormData,
        ATTEMPT_ID:21,API:'',EXAM_CONFIG:{testId:5,wsUrl:'ws://test'},LOGIN_URL:'/login',DASHBOARD_URL:'/dashboard',
        Date:class extends Date{static now(){return now;}},console:{log(){},warn(){},error(){}},
        setTimeout(fn,ms){const n=++id;timers.set(n,{fn,at:now+ms});return n;},clearTimeout(n){timers.delete(n);},
        fetch:async(url,opts)=>{calls.push({url,at:now,method:opts.method});return{ok:true,status:200,json:async()=>({status:'success',exam_mode:'static',static_page_path:'static/test.html'})};},
        buildFormData:x=>x,updateCsrf(){},Swal:{fire(){return Promise.resolve();}},
        WebSocket:class {constructor(){this.readyState=0;}close(){this.readyState=3;this.onclose?.({});}}
    };
    context.$={ajax(opts){const cb={},chain={};for(const name of ['done','fail','always'])chain[name]=fn=>{cb[name]=fn;return chain;};
        calls.push({url:opts.url,at:now,method:opts.type});
        Promise.resolve().then(()=>{const failed=failSync&&opts.url.includes('auto-sync');const value=failed?{status:503}:{status:'success'};
            if(failed){opts.error?.(value);cb.fail?.(value);}else{opts.success?.(value);cb.done?.(value);}cb.always?.(value);});return chain;}};
    const src=readFileSync(resolve(__dirname,'../..',file),'utf8').replace(/<\?=([\s\S]*?)\?>/g,(_,php)=>php.includes('auto-sync')?'/student/exam/auto-sync':php.includes('autosave')?'/student/exam/autosave':php.includes('/api/exam/status')?'/api/exam/status':'0');
    vm.runInNewContext(readFileSync(resolve(__dirname,'../../public/assets/activity-polling.js'),'utf8'),context);
    const names=['initRealtimeLifecycle','initWebSocket','connectWebSocket','reconnectWebSocket','armWebSocketTimeout','startStatusPolling','stopStatusPolling','pollExamStatus','applyExamStatus','stopRealtime','scheduleAutoSync','enqueueRequest','performNetworkRequest','qKey','markUnsaved'];
    const methods=names.flatMap(name=>{const m=new RegExp('^([ ]+)(?:async )?'+name+'\\(','m').exec(src);return m?[src.slice(m.index,src.indexOf('\n'+m[1]+'}',m.index)+m[1].length+2)]:[];});
    const begin=src.indexOf('    const APP_CFG ='),end=src.indexOf('async function logoutAndRedirect',begin);
    const app=vm.runInNewContext(src.slice(begin,end)+'\n({'+methods.join(',\n')+'})',context);
    Object.assign(app,{activeQueue:Promise.resolve(),syncRevision:0,syncedRevision:0,lastSyncTime:0,unsavedIds:{},storagePending:{},questions:[{log_id:31,question_id:41,question_type:3,answer_text:'a'}],allAnswers:{},saveLocalBackup(){},exitStorageDown(){},markSaved(){},modeDriftTarget:()=>null});
    async function advance(ms){const until=now+ms;for(let n=0;n<200;n++){for(let j=0;j<30;j++)await Promise.resolve();const next=[...timers].filter(([,t])=>t.at<=until).sort((a,b)=>a[1].at-b[1].at)[0];if(!next)break;timers.delete(next[0]);now=next[1].at;next[1].fn();}now=until;for(let j=0;j<30;j++)await Promise.resolve();}
    const emit=(s,key)=>{for(const fn of events[s][key]||[])fn({persisted:true});};
    return{app,calls,advance,emit,document,context};
}
for(const file of files){
    test(file+': idle exam never performs a periodic database sync',async()=>{
        const f=fixture(file);f.app.scheduleAutoSync();await f.advance(600000);assert.equal(f.calls.length,0);
    });
    test(file+': answer changes schedule one sync and successful sync does not repeat while idle',async()=>{
        const f=fixture(file);const saved=f.app.enqueueRequest('autosave',{logId:31});await f.advance(65000);await saved;
        assert.equal(f.calls.filter(c=>c.url.includes('auto-sync')).length,1);
        await f.advance(600000);assert.equal(f.calls.filter(c=>c.url.includes('auto-sync')).length,1);
    });
    test(file+': autosave and sync share actual request pacing',async()=>{
        const f=fixture(file);const pending=Promise.all(Array.from({length:8},()=>f.app.enqueueRequest('autosave',{logId:31})));
        await f.advance(5000);await pending;assert.equal(f.calls.length,8);
        for(let i=1;i<8;i++)assert.ok(f.calls[i].at-f.calls[i-1].at>=500);
    });
    test(file+': failed sync stops after three retries instead of becoming an idle loop',async()=>{
        const f=fixture(file,true);f.app.enqueueRequest('autosave',{logId:31});await f.advance(180000);
        assert.equal(f.calls.filter(c=>c.url.includes('auto-sync')).length,4);
        await f.advance(600000);assert.equal(f.calls.filter(c=>c.url.includes('auto-sync')).length,4);
    });
    test(file+': hidden exam polls only after minutes and pagehide stops it entirely',async()=>{
        const f=fixture(file);f.app.initWebSocket();f.app.ws.close();await f.advance(1000);
        assert.equal(f.calls.length,1);
        f.document.visibilityState='hidden';f.emit('document','visibilitychange');
        await f.advance(60000);assert.equal(f.calls.length,1);
        await f.advance(155000);assert.ok(f.calls.length<=2);
        f.emit('window','pagehide');const count=f.calls.length;await f.advance(600000);assert.equal(f.calls.length,count);
    });
    test(file+': cancelling a sync retry cannot wedge later saves after BFCache restore',async()=>{
        const f=fixture(file,true);f.app.initRealtimeLifecycle();f.app.lastSyncTime=-60000;
        f.app.enqueueRequest('autosave',{logId:31});await f.advance(600);
        f.context.window.CbtPolling.defer('30');await f.advance(2000);
        f.emit('window','pagehide');await f.advance(0);f.emit('window','pageshow');
        const pending=f.app.enqueueRequest('autosave',{logId:31});await f.advance(31000);
        assert.equal(f.calls.filter(c=>c.url.includes('autosave')).length,2);
        await pending;
    });
    test(file+': cancelled autosave stays pending and is replayed after BFCache restore',async()=>{
        const f=fixture(file);f.app.initRealtimeLifecycle();f.context.window.CbtPolling.defer('30');
        f.app.enqueueRequest('autosave',{logId:31});await f.advance(500);
        f.emit('window','pagehide');await f.advance(0);
        assert.equal(f.app.pausedSaves?.[31],true,'Cancelled answer must remain pending');
        f.emit('window','pageshow');await f.advance(31000);
        assert.equal(f.calls.filter(c=>c.url.includes('autosave')).length,1);
        assert.equal(f.app.pausedSaves[31],undefined);
    });
}

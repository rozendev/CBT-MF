/* Static exam main app (sector 2 — persistent cache).
 * Alpine exam app + anti-cheat engine + init flow.
 * Dieksekusi via <script defer> setelah seluruh HTML ter-parse;
 * EXAM_CONFIG (inline di HTML) dijamin sudah ada.
 * Jangan edit manual — hasil ekstraksi static_exam_template.php. */
    let examStarted = false;
    let ATTEMPT_ID  = null;
    let USER_ID     = null;
    let CSRF_NAME   = '';
    let CSRF_HASH   = '';
    let STUDENT_NAME = '';
    let START_TIME  = 0;
    let lastModeCheck = 0;

    let API = (typeof EXAM_CONFIG !== 'undefined' && EXAM_CONFIG.apiBaseUrl) ? EXAM_CONFIG.apiBaseUrl : '';

    // Setup global jQuery ($.ajaxSetup + auto-renuew CSRF) dipindah ke
    // <script defer> setelah tag jQuery — lihat bagian bawah body.

    function buildFormData(obj) {
        const fd = new FormData();
        if (CSRF_NAME) fd.append(CSRF_NAME, CSRF_HASH);
        for (const key in obj) {
            const val = obj[key];
            // Field yang tidak ada JANGAN dikirim: FormData mengubah undefined/null
            // jadi string "undefined"/"null", dan server memperlakukannya sebagai
            // nilai valid (mis. generated_at → dianggap kadaluarsa, jawaban ditolak).
            if (val === undefined || val === null) continue;
            if (Array.isArray(val)) {
                val.forEach(v => fd.append(key + '[]', v));
            } else {
                fd.append(key, val);
            }
        }
        return fd;
    }

    function updateCsrf(res) {
        if (res) {
            if (res.csrf_name) CSRF_NAME = res.csrf_name;
            if (res.csrf_token) CSRF_HASH = res.csrf_token;
            if (res.csrf_hash) CSRF_HASH = res.csrf_hash;
        }
    }

    const APP_CFG = window.APP_CONFIG || {};

    const FETCH_TIMEOUT_MS = APP_CFG.fetch_timeout_ms || 15000;
    const FETCH_MAX_RETRIES = 3;

    /* Peredam aksi beruntun (tap cepat / spam) pada satu soal.
       AUTOSAVE_DEBOUNCE_MS menahan pengiriman sampai siswa berhenti mengubah,
       sehingga yang naik ke server adalah STATE TERAKHIR — bukan satu request
       per tap yang menumpuk di antrean. */
    const AUTOSAVE_DEBOUNCE_MS = 700;
    const RATE_WINDOW_MS = 3000;
    const RATE_MAX_ACTIONS = 8;
    const RATE_TOAST_COOLDOWN_MS = 4000;

    function redirectReplace(url) {
        window.location.replace(url);
    }

    async function fetchWithTimeout(url, options = {}, timeoutMs = FETCH_TIMEOUT_MS) {
        return fetch(url, {
            ...options,
            credentials: 'include',
            signal: AbortSignal.timeout(timeoutMs),
        });
    }

    async function fetchWithRetry(url, options = {}, maxRetries = FETCH_MAX_RETRIES, timeoutMs = FETCH_TIMEOUT_MS) {
        let lastError;
        for (let attempt = 0; attempt <= maxRetries; attempt++) {
            try {
                const res = await fetchWithTimeout(url, options, timeoutMs);
                if (res.ok) return res;
                lastError = new Error('HTTP ' + res.status);
            } catch (err) {
                lastError = err;
            }
            if (attempt < maxRetries) {
                await new Promise(r => setTimeout(r, 1000 * (attempt + 1)));
            }
        }
        throw lastError;
    }

    async function logoutAndRedirect(loginUrl) {
        try {
            const fd = buildFormData({});
            await fetchWithRetry(API + '/logout', { method: 'POST', body: fd });
        } catch (e) {
            console.warn('Logout request failed, redirecting anyway:', e);
        }
        redirectReplace(loginUrl);
    }

    // ═══ INIT FLOW ═══
    async function initExam() {
        if (window.__KIOSK_BUNDLE__) {
            // Bundle mode: data ujian disediakan exam.html via __bundleConfigPromise —
            // init internal (boot-first / need_prepare / anti-cheat UI) dilewati.
            try {
                const ok = await window.__bundleConfigPromise;
                if (!ok) { window.location.href = 'login.html'; return; }
            } catch (e) {
                console.error('Bundle init gagal:', e);
                return; // pesan error sudah ditampilkan exam.html di loading-screen
            }
            updateCsrf(window.__bundleCsrf || {});
            ATTEMPT_ID = EXAM_CONFIG.attemptId || null;
            if (EXAM_CONFIG.apiBaseUrl) API = EXAM_CONFIG.apiBaseUrl;
            return; // config sudah diset exam.html: lanjut render Alpine
        }

        const loading = document.getElementById('loadingScreen');
        loading.style.display = 'flex';

        try {
            // Boot-first: data ujian sudah diambil oleh script <head>
            // (window.__bootPromise) PARALEL dengan unduhan aset, tanpa jQuery.
            const res = await window.__bootPromise;

            if (!res) {
                loading.style.display = 'none';
                Swal.fire('Error', 'Tidak dapat terhubung ke server. Periksa koneksi Anda.', 'error');
                return;
            }

            updateCsrf(res);

            if (res.status === 'need_prepare') {
                window.location.href = API + '/student/exam/prepare/' + EXAM_CONFIG.testId;
                return;
            }

            if (res.status === 'error') {
                loading.style.display = 'none';
                if (res.action === 'logout') {
                    await logoutAndRedirect(API + '/login');
                    return;
                }
                Swal.fire('Error', res.message || 'Terjadi kesalahan saat memuat ujian.', 'error');
                return;
            }

            if (res.status === 'success') {
                if (res.test && (res.test.exam_mode !== 'static' || !res.test.static_page_path)) {
                    window.location.href = API + '/student/exam/take/' + EXAM_CONFIG.testId;
                    return;
                }

                ATTEMPT_ID   = res.attempt_id;
                USER_ID      = res.user ? res.user.id : null;
                STUDENT_NAME = res.user ? (res.user.firstname + ' ' + res.user.lastname).trim() : '';
                START_TIME   = (res.test && res.test.started_at_ms) ? res.test.started_at_ms : Date.now();
                const serverNow = (res.test && res.test.server_now_ms) ? res.test.server_now_ms : Date.now();
                const timeOffset = serverNow - Date.now();

                const mergedQuestions = JSON.parse(JSON.stringify(EXAM_CONFIG.questionsData));
                const mergedAnswers = JSON.parse(JSON.stringify(EXAM_CONFIG.answersData));

                if (res.answers) {
                    for (const [qId, savedAnswers] of Object.entries(res.answers)) {
                        if (mergedAnswers[qId]) {
                            const savedMap = {};
                            savedAnswers.forEach(sa => { savedMap[sa.answer_id] = sa.is_selected; });
                            mergedAnswers[qId].forEach(ma => {
                                if (savedMap[ma.answer_id] !== undefined) ma.is_selected = savedMap[ma.answer_id];
                            });
                        }
                    }
                }
                
                if (res.questions) {
                    const qSavedMap = {};
                    res.questions.forEach(q => { qSavedMap[q.question_id] = q; });
                    mergedQuestions.forEach(mq => {
                        if (qSavedMap[mq.question_id]) {
                            mq.answer_text = qSavedMap[mq.question_id].answer_text || '';
                        }
                    });
                }

                window.__examData = {
                    questions: mergedQuestions,
                    answers: mergedAnswers,
                    attemptId: ATTEMPT_ID,
                    studentName: STUDENT_NAME,
                    beginTimeMs: res.test.begin_time_ms,
                    timeOffset: timeOffset,
                    antiCheat: res.anti_cheat || null,
                    user: res.user || null,
                    wsToken: res.ws_token || null,
                };

                document.dispatchEvent(new CustomEvent('exam-data-loaded'));

                if (res.anti_cheat) {
                    EXAM_CONFIG.antiCheat = res.anti_cheat;
                    // Ensure auto_submit_on_cheat is always present (fallback from test object)
                    if (EXAM_CONFIG.antiCheat.auto_submit_on_cheat === undefined && res.test) {
                        EXAM_CONFIG.antiCheat.auto_submit_on_cheat = !!(res.test.auto_submit_on_cheat);
                    }
                    document.getElementById('antiCheatTitle').textContent = res.anti_cheat.title || 'Peringatan Kecurangan!';
                    document.getElementById('antiCheatMessage').textContent = res.anti_cheat.message || 'Sistem mendeteksi Anda meninggalkan halaman ujian.';
                    if (res.anti_cheat.suspend_timer) document.getElementById('suspendTimerDisplay').textContent = res.anti_cheat.suspend_timer;
                    if (res.anti_cheat.max_strikes) document.getElementById('maxStrikes').textContent = res.anti_cheat.max_strikes;
                    
                    const logoImg = document.getElementById('antiCheatLogoImg');
                    if (res.anti_cheat.logo) {
                        logoImg.src = EXAM_CONFIG.appBaseUrl + res.anti_cheat.logo;
                        logoImg.style.display = 'inline-block';
                    } else {
                        logoImg.style.display = 'none';
                    }
                }

                // Aset besar (jQuery/Alpine) belum siap? Jangan sembunyikan
                // loading dan jangan tampilkan UI Alpine yang belum berfungsi —
                // fallback renderer akan mengambil alih lewat poller di bawah.
                if (window.__KIOSK_BUNDLE__ || (window.jQuery && window.Alpine)) {
                    loading.style.display = 'none';
                    document.getElementById('examContent').style.display = 'block';
                } else {
                    const bootStatus = document.getElementById('bootStatus');
                    if (bootStatus) bootStatus.textContent = 'Aset belum siap — menyiapkan mode ringan…';
                }
                examStarted = true;
            }
        } catch (err) {
            loading.style.display = 'none';
            const msg = (err.responseJSON && err.responseJSON.message) ? err.responseJSON.message : 'Gagal menghubungi server. Periksa koneksi Anda.';
            Swal.fire('Error', msg, 'error');
        }
    }

    // ═══ AUTO FULLSCREEN ═══
    ['click', 'touchstart', 'keydown'].forEach(evt => {
        document.addEventListener(evt, function() {
            if (EXAM_CONFIG.antiCheat && EXAM_CONFIG.antiCheat.enabled === false && !EXAM_CONFIG.antiCheat.auto_submit_on_cheat) return;
            if (!document.fullscreenElement && examStarted && !window.isSubmitting) {
                const el = document.documentElement;
                const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
                if (rfs) rfs.call(el).catch(()=>{});
            }
        });
    });

    // ═══ ALPINE.JS EXAM APP ═══
    document.addEventListener('alpine:init', () => {
        Alpine.data('examApp', () => ({
            questions: [],
            allAnswers: {},
            currentIndex: 0,
            isSaving: false,
            showSavedToast: false,
            showErrorToast: false,
            // Status simpan yang MENETAP (tidak hilang sendiri seperti toast 2 detik).
            // 'idle' | 'saving' | 'saved' | 'failed' — state 'failed' sengaja tidak
            // pernah hilang otomatis: kegagalan simpan harus terus terlihat siswa.
            saveState: 'idle',
            saveErrorMsg: '',
            unsavedCount: 0,
            unsavedIds: {},
            saveTimers: {},
            actionTimes: [],
            lastRateToast: 0,
            showRateToast: false,
            rateToastMsg: '',
            timeLeft: 0,
            timerInterval: null,
            warningShown: false,
            testName: '',
            studentName: '',
            durationMinutes: 0,
            sseSource: null,
            sseErrorCount: 0,
            syncInterval: null,
            isOffline: !navigator.onLine,
            ready: false,
            
            activeQueue: Promise.resolve(),
            syncTimeout: null,
            lastSyncTime: Date.now(),

            /* Kunci soal untuk mencari opsi jawaban dan sebagai identitas
               autosave. Halaman static memanggang question_id ke dalam HTML-nya,
               sedangkan ujian normal (soal ditarik dari bank soal) hanya punya
               log_id: satu baris test_logs per soal per attempt. log_id
               didahulukan karena question_id yang sama bisa muncul dua kali
               dalam satu attempt bila dua set subjek beririsan. Tanpa helper ini
               lookup jatuh ke allAnswers[undefined] dan SELURUH pilihan jawaban
               hilang tanpa satu pun error di console. */
            qKey(q) {
                if (!q) return undefined;
                return (q.log_id !== undefined && q.log_id !== null) ? q.log_id : q.question_id;
            },

            /* Penjaga "mode ujian berubah di tengah jalan". Halaman static di web
               hanya sah selama test masih exam_mode=static, jadi di sana perilaku
               lama dipertahankan. Bundle kiosk merender KEDUA mode, sehingga ia
               hanya perlu memuat ulang kalau modenya benar-benar berubah --
               tanpa pembedaan ini setiap auto-sync pada ujian normal memicu
               reload berulang dan siswa tidak pernah sempat mengerjakan. */
            modeDriftTarget(examMode, staticPagePath) {
                if (window.__KIOSK_BUNDLE__) {
                    const known = EXAM_CONFIG.examMode;
                    if (!known || known === examMode) return null;
                    return 'exam.html?test_id=' + EXAM_CONFIG.testId + '&resume=1';
                }
                if (examMode !== 'static' || !staticPagePath) {
                    return API + '/student/exam/take/' + EXAM_CONFIG.testId;
                }
                const expectedUrl = API + '/' + staticPagePath;
                if (!expectedUrl.includes(window.location.pathname)) return expectedUrl;
                return null;
            },

            parseMatching() {
                this.questions.forEach(q => {
                    q.is_flagged = false;
                    if (q.question_type == 4 || q.question_type == 5) {
                        q.matchingPairs = [];
                        let rights = [];
                        let savedMatching = {};
                        try { if (q.answer_text) savedMatching = JSON.parse(q.answer_text); } catch(e) {}

                        let ansList = this.allAnswers[this.qKey(q)] || [];
                        ansList.forEach(a => {
                            let parts = (a.answer_text || '').split('|::|');
                            let left  = parts[0] || '';
                            let right = parts[1] || '';
                            if (left && right) {
                                q.matchingPairs.push({ left, right, selected: savedMatching[left] || '' });
                                rights.push(right);
                            }
                        });
                        q.matchingOptions = rights.sort(() => 0.5 - Math.random());
                    }
                });
            },

            async init() {
                if (window.__KIOSK_BUNDLE__) {
                    // Bundle mode: tunggu config yang diset exam.html
                    // (EXAM_CONFIG wajib ada saat data komponen dibuat).
                    let cfgOk = false;
                    try { cfgOk = await window.__bundleConfigPromise; } catch (e) {}
                    if (!cfgOk) return; // error ditampilkan exam.html di loading-screen
                    if (EXAM_CONFIG.apiBaseUrl) API = EXAM_CONFIG.apiBaseUrl;
                    this.ready = true; // x-show="ready" di exam.html
                    if (window.CommsBridge) window.CommsBridge.setExamActive(true);
                }
                window.addEventListener('offline', () => this.isOffline = true);
                window.addEventListener('online', () => this.isOffline = false);
                
                this.$watch('currentIndex', (val) => {
                    setTimeout(() => { if (typeof window.renderMath === 'function') window.renderMath(); }, 50);
                    if (ATTEMPT_ID && val !== undefined && val !== null) {
                        localStorage.setItem('current_question_index_' + ATTEMPT_ID, val);
                    }
                });
                
                this.questions = JSON.parse(JSON.stringify(EXAM_CONFIG.questionsData || []));
                this.allAnswers = JSON.parse(JSON.stringify(EXAM_CONFIG.answersData || {}));
                this.parseMatching();

                if (window.__KIOSK_BUNDLE__) {
                    // Tanpa event exam-data-loaded: set sendiri data yang
                    // biasanya datang dari sana (nama + timer berjalan).
                    // CATATAN: factory komponen tidak boleh membaca EXAM_CONFIG
                    // (alpine.min.js bundle auto-start sebelum fetch init selesai);
                    // semua nilai dibaca di sini — setelah __bundleConfigPromise.
                    this.studentName = EXAM_CONFIG.studentName || '';
                    this.testName = EXAM_CONFIG.testName || '';
                    this.durationMinutes = EXAM_CONFIG.durationMinutes || 0;
                    this.timeLeft = this.durationMinutes > 0 ? this.durationMinutes * 60 * 1000 : 0;
                    if (this.durationMinutes > 0) {
                        this.startTimer(EXAM_CONFIG.beginTimeMs || Date.now(), (EXAM_CONFIG.serverNowMs || Date.now()) - Date.now());
                    }
                }

                document.addEventListener('exam-data-loaded', () => {
                    const data = window.__examData || {};
                    this.questions  = data.questions  || this.questions;
                    this.allAnswers = data.answers     || this.allAnswers;

                    // ─── Per-Attempt Reorder (Anti-Cheat) ───
                    // Sort questions by server-assigned display_order (unique per attempt)
                    if (this.questions && this.questions.length > 0 && this.questions[0].display_order !== undefined) {
                        this.questions.sort((a, b) => (a.display_order || 0) - (b.display_order || 0));
                        // Re-number visual display_order sequentially (1, 2, 3...)
                        this.questions.forEach((q, i) => { q.display_order = i + 1; });
                    }
                    // Sort answer options by server-assigned display_order (unique per attempt)
                    if (this.allAnswers) {
                        for (const qId in this.allAnswers) {
                            if (Array.isArray(this.allAnswers[qId]) && this.allAnswers[qId].length > 0 && this.allAnswers[qId][0].display_order !== undefined) {
                                this.allAnswers[qId].sort((a, b) => (a.display_order || 0) - (b.display_order || 0));
                            }
                        }
                    }

                    this.studentName = data.studentName || '';
                    this.parseMatching();
                    this.restoreLocalBackup();

                    // Restore saved question index from localStorage if any
                    if (ATTEMPT_ID) {
                        const savedIndex = localStorage.getItem('current_question_index_' + ATTEMPT_ID);
                        if (savedIndex !== null) {
                            const parsed = parseInt(savedIndex, 10);
                            if (!isNaN(parsed) && parsed >= 0 && parsed < this.questions.length) {
                                this.currentIndex = parsed;
                            }
                        }
                    }

                    this.initWebSocket();

                    this.startTimer(data.beginTimeMs || Date.now(), data.timeOffset || 0);
                });

                // Init Auto Sync (Debounce + Max-Wait Hybrid)
                this.scheduleAutoSync();

                // Fallback saat tab ditutup/refresh
                window.addEventListener('beforeunload', (e) => {
                    if (this.isSaving) {
                        e.preventDefault();
                        e.returnValue = ''; // Memicu konfirmasi penutupan pada browser modern
                    }
                    if (ATTEMPT_ID) {
                        const fd = buildFormData({ attempt_id: ATTEMPT_ID });
                        navigator.sendBeacon(API + '/api/exam/auto-sync', fd);
                    }
                });
            },

            startTimer(beginTimeMs, timeOffset) {
                if (this.durationMinutes <= 0) return;
                this.endTimeMs = beginTimeMs + (this.durationMinutes * 60 * 1000);

                this.timerInterval = setInterval(() => {
                    const now = Date.now() + timeOffset;
                    const distance = this.endTimeMs - now;

                    if (distance <= 0) {
                        clearInterval(this.timerInterval);
                        this.timeLeft = 0;
                        Swal.fire('Waktu Habis!', 'Waktu Anda telah habis! Ujian akan disubmit otomatis.', 'info').then(() => {
                            this.submitFinish();
                        });
                    } else {
                        this.timeLeft = distance;
                        if (distance <= (APP_CFG.warning_threshold_ms || 300000) && !this.warningShown) {
                            this.warningShown = true;
                            Swal.fire({
                                title: 'Peringatan Waktu!',
                                text: 'Waktu ujian Anda tersisa 5 menit lagi.',
                                icon: 'warning',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 5000,
                                timerProgressBar: true
                            });
                        }
                    }
                }, 1000);
            },

            initWebSocket() {
                if (!ATTEMPT_ID) {
                    return;
                }
                const wsToken = window.__examData ? window.__examData.wsToken : null;
                if (!wsToken) {
                    console.error('No WebSocket token available');
                    return;
                }
                
                let wsUrl = EXAM_CONFIG.wsUrl;
                if (!wsUrl) wsUrl = APP_CFG.websocket_url || '';
                if (!wsUrl || wsUrl.includes('localhost')) {
                    const urlObj = new URL(API);
                    const protocol = urlObj.protocol === 'https:' ? 'wss:' : 'ws:';
                    const wsHost = urlObj.host;
                    if (wsHost.includes(':8080')) {
                        wsUrl = `${protocol}//${wsHost.replace(':8080', ':8060')}`;
                    } else {
                        wsUrl = `${protocol}//${wsHost}/ws`;
                    }
                }
                wsUrl = wsUrl.replace(/\/+$/, '') + `/?ws_token=${wsToken}`;
                
                this.connectWebSocket(wsUrl);
            },

            connectWebSocket(wsUrl) {
                this.ws = new WebSocket(wsUrl);

                this.ws.onopen = () => {
                    this.wsErrorCount = 0;
                    console.log('WebSocket connected');
                };

                this.ws.onmessage = (e) => {
                    const payload = JSON.parse(e.data);
                    const eventName = payload.event;
                    const d = payload.data || {};

                    if (eventName === 'ban') {
                        this.ws.close();
                        Swal.fire({ title:'Akun Di-Ban', text:d.message, icon:'error', allowOutsideClick:false, allowEscapeKey:false, confirmButtonText:'OK' })
                            .then(() => logoutAndRedirect(API + '/login'));
                    }
                    else if (eventName === 'kick') {
                        this.ws.close();
                        Swal.fire('Sesi Dihentikan', d.message, 'error')
                            .then(() => logoutAndRedirect(API + '/login'));
                    }
                    else if (eventName === 'finished') {
                        this.ws.close();
                        Swal.fire('Ujian Selesai', d.message, 'info')
                            .then(() => { window.location.href = API + '/student/dashboard'; });
                    }
                    else if (eventName === 'extend_time') {
                        if (d.test_id == EXAM_CONFIG.testId) {
                            this.durationMinutes = d.duration_minutes;
                            this.endTimeMs = Date.now() + (this.durationMinutes * 60 * 1000);
                            this.warningShown = false;
                            
                            Swal.fire({
                                title: 'Waktu Ditambahkan!',
                                text: 'Admin telah menambahkan waktu ujian. Silakan periksa sisa waktu Anda.',
                                icon: 'success',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 5000,
                                timerProgressBar: true
                            });
                        }
                    }
                    else if (eventName === 'sync_mode') {
                        const driftTarget = this.modeDriftTarget(d.exam_mode, d.static_page_path);
                        if (driftTarget) {
                            window.isSubmitting = true;
                            window.location.href = driftTarget;
                        }
                    }
                    else if (eventName === 'heartbeat') {
                        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                            this.ws.send(JSON.stringify({event: 'pong'}));
                        }
                    }
                };

                this.ws.onclose = (e) => {
                    console.warn('WebSocket closed', e);
                    this.reconnectWebSocket(wsUrl);
                };

                this.ws.onerror = (err) => {
                    console.error('WebSocket error', err);
                    this.ws.close();
                };
            },

            reconnectWebSocket(wsUrl) {
                if (!this.wsErrorCount) this.wsErrorCount = 0;
                this.wsErrorCount++;
                
                if (this.wsErrorCount > 10) {
                    console.warn('WebSocket reconnect limit reached');
                    return;
                }
                
                const delay = Math.min((APP_CFG.ws_reconnect_base_ms || 1000) * Math.pow(2, this.wsErrorCount), APP_CFG.ws_reconnect_cap_ms || 30000);
                console.log(`Reconnecting WebSocket in ${delay}ms...`);
                
                setTimeout(() => {
                    this.connectWebSocket(wsUrl);
                }, delay);
            },

            get currentQuestion() { return this.questions[this.currentIndex] || {}; },
            get currentAnswers()  { return this.allAnswers[this.qKey(this.currentQuestion)] || []; },

            formatTime(ms) {
                if (ms <= 0) return "00:00:00";
                let h = Math.floor(ms / 3600000);
                let m = Math.floor((ms % 3600000) / 60000);
                let s = Math.floor((ms % 60000) / 1000);
                return (h<10?"0"+h:h)+":"+(m<10?"0"+m:m)+":"+(s<10?"0"+s:s);
            },

            nextQuestion() {
                if (this.currentIndex < this.questions.length - 1) this.currentIndex++;
                else this.confirmFinish();
                this.checkExamMode();
            },
            prevQuestion() { 
                if (this.currentIndex > 0) this.currentIndex--; 
                this.checkExamMode();
            },
            goToQuestion(idx) { 
                this.currentIndex = idx; 
                if (ATTEMPT_ID) {
                    localStorage.setItem('current_question_index_' + ATTEMPT_ID, idx);
                }
                this.checkExamMode();
            },
            closeMobileSidebar() {
                const el = document.getElementById('questionGridSheet');
                if (el) {
                    const bs = bootstrap.Offcanvas.getInstance(el);
                    if (bs) bs.hide();
                }
            },

            checkExamMode() {
                if (!ATTEMPT_ID) return;
                // Throttle: navigasi antar-soal tidak boleh memicu request.
                // Mode-switch terdeteksi maks 20 detik kemudian (atau via
                // scheduleAutoSync 60s yang tetap berjalan).
                const now = Date.now();
                if (now - lastModeCheck < 20000) return;
                lastModeCheck = now;
                const fd = buildFormData({ attempt_id: ATTEMPT_ID });
                $.ajax({
                    url: API + '/api/exam/auto-sync',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: (res) => { 
                        updateCsrf(res); 
                        if (res.exam_mode !== undefined) {
                            const driftTarget = this.modeDriftTarget(res.exam_mode, res.static_page_path);
                            if (driftTarget) {
                                window.isSubmitting = true;
                                window.location.href = driftTarget;
                            }
                        }
                    }
                });
            },

            selectRadio(answerId) {
                let currentSelected = this.currentAnswers.find(a => a.is_selected == 1);
                if (currentSelected && currentSelected.answer_id == answerId) return;
                this.currentAnswers.forEach(a => { a.is_selected = (a.answer_id == answerId) ? 1 : 0; });
                
                // Meng-update jadwal sync setiap ada interaksi user
                this.scheduleAutoSync();

                // Masukkan ke antrean tunggal
                this.enqueueRequest('autosave', { logId: this.qKey(this.currentQuestion), retries: 3 });
            },
            toggleCheckbox(answerId, isChecked) {
                let ans = this.currentAnswers.find(a => a.answer_id == answerId);
                if (ans) {
                    if (ans.is_selected == (isChecked ? 1 : 0)) return;
                    ans.is_selected = isChecked ? 1 : 0;
                }
                this.scheduleAutoSync();
                this.enqueueRequest('autosave', { logId: this.qKey(this.currentQuestion), retries: 3 });
            },
            updateMatching(index, value) {
                if (this.questions[this.currentIndex].matchingPairs[index].selected === value) return;
                this.questions[this.currentIndex].matchingPairs[index].selected = value;
                this.scheduleAutoSync();
                this.enqueueRequest('autosave', { logId: this.qKey(this.currentQuestion), retries: 3 });
            },

            scheduleAutoSync() {
                if (this.syncTimeout) clearTimeout(this.syncTimeout);
                
                const timeSinceLastSync = Date.now() - this.lastSyncTime;
                const MAX_WAIT = APP_CFG.auto_sync_max_wait_ms || 180000; // 3 menit
                
                if (timeSinceLastSync > MAX_WAIT) {
                    this.enqueueRequest('sync');
                } else {
                    this.syncTimeout = setTimeout(() => {
                        this.enqueueRequest('sync');
                    }, APP_CFG.auto_sync_debounce_ms || 60000); // 60 detik debounce
                }
            },

            enqueueRequest(action, params = {}) {
                if (!this.activeQueue) this.activeQueue = Promise.resolve();
                this.activeQueue = this.activeQueue.then(() => {
                    return this.performNetworkRequest(action, params);
                }).catch((err) => {
                    console.warn("Recovered from queue error:", err);
                    return Promise.resolve();
                });
            },

            performNetworkRequest(action, params) {
                return new Promise((resolve) => {
                    if (action === 'sync') {
                        if (!ATTEMPT_ID) return resolve();
                        const fd = buildFormData({ attempt_id: ATTEMPT_ID });
                        $.ajax({
                            url: API + '/api/exam/auto-sync',
                            type: 'POST',
                            data: fd,
                            processData: false,
                            contentType: false,
                            dataType: 'json'
                        }).always((res) => {
                            if (res && res.csrf_hash) updateCsrf(res);
                            if (res && res.exam_mode !== undefined) {
                                const driftTarget = this.modeDriftTarget(res.exam_mode, res.static_page_path);
                                if (driftTarget) {
                                    window.isSubmitting = true;
                                    window.location.href = driftTarget;
                                }
                            }
                            this.lastSyncTime = Date.now();
                            this.scheduleAutoSync();
                            resolve();
                        });
                    } else if (action === 'autosave') {
                        const { logId, retries } = params;
                        const q = this.questions.find(x => String(this.qKey(x)) === String(logId));
                        if (!q) return resolve();

                        this.saveLocalBackup();

                        // Server menerima log_id ATAU (question_id + attempt_id).
                        // Mengirim log_id lewat field question_id akan mencocokkan
                        // baris test_logs milik soal LAIN, jadi nama fieldnya harus
                        // mengikuti id yang benar-benar dipegang soal ini.
                        let passedData = { attempt_id: ATTEMPT_ID, question_type: q.question_type, generated_at: EXAM_CONFIG.generatedAt };
                        if (q.log_id !== undefined && q.log_id !== null) {
                            passedData.log_id = q.log_id;
                        } else {
                            passedData.question_id = q.question_id;
                        }
                        if (q.question_type == 3) {
                            passedData.answer_text = q.answer_text;
                        } else if (q.question_type == 4 || q.question_type == 5) {
                            let matches = {};
                            q.matchingPairs.forEach(p => { matches[p.left] = p.selected; });
                            passedData.matching_answers_json = JSON.stringify(matches);
                        } else {
                            const ansList = this.allAnswers[logId] || [];
                            passedData.selected_answers = ansList.filter(a => a.is_selected == 1).map(a => a.answer_id);
                        }

                        this.isSaving = true;
                        this.showErrorToast = false;
                        this.saveState = 'saving';
                        const fd = buildFormData(passedData);

                        $.ajax({
                            url: API + '/api/exam/autosave',
                            type: 'POST',
                            data: fd,
                            processData: false,
                            contentType: false,
                            dataType: 'json',
                            success: (res) => {
                                updateCsrf(res);

                                // HTTP 200 TIDAK berarti tersimpan: server bisa menolak
                                // dengan {status:'error'}. Menampilkan toast "tersimpan"
                                // di sini membuat jawaban hilang tanpa disadari siswa.
                                if (res && res.status === 'error') {
                                    if (retries > 0) {
                                        setTimeout(() => {
                                            this.performNetworkRequest('autosave', { logId, retries: retries - 1 }).then(resolve);
                                        }, 2500);
                                    } else {
                                        this.isSaving = false;
                                        this.showErrorToast = true;
                                        setTimeout(() => { this.showErrorToast = false; }, 4000);
                                        this.markUnsaved(logId, res.message || 'Server menolak menyimpan jawaban ini.');
                                        resolve();
                                    }
                                    return;
                                }

                                this.isSaving = false;
                                this.markSaved(logId);
                                this.showSavedToast = true;
                                setTimeout(() => { this.showSavedToast = false; }, 2000);

                                if (res.status === 'kicked') {
                                    if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                                    Swal.fire('Informasi', res.message, 'info').then(() => {
                                        window.location.href = API + '/login';
                                    });
                                }
                                resolve();
                            },
                            error: (err) => {
                                if (err.status === 401 || err.status === 403) {
                                    this.isSaving = false;
                                    if (document.fullscreenElement) document.exitFullscreen().catch(function(){});
                                    Swal.fire('Sesi Berakhir', 'Sesi Anda telah habis atau dihentikan.', 'error').then(() => {
                                        window.location.href = API + '/login';
                                    });
                                    resolve();
                                } else {
                                    if (retries > 0) {
                                        setTimeout(() => {
                                            this.performNetworkRequest('autosave', { logId, retries: retries - 1 }).then(resolve);
                                        }, 2500);
                                    } else {
                                        this.isSaving = false;
                                        this.showErrorToast = true;
                                        setTimeout(() => { this.showErrorToast = false; }, 4000);
                                        this.markUnsaved(logId, 'Koneksi ke server gagal.');
                                        resolve();
                                    }
                                }
                            }
                        });
                    } else {
                        resolve();
                    }
                });
            },

            /* Status simpan menetap: 'failed' tidak pernah hilang sendiri selama
               masih ada jawaban yang belum tersimpan, supaya siswa tidak
               menyelesaikan ujian dengan mengira semuanya aman. */
            markUnsaved(logId, message) {
                this.unsavedIds[logId] = true;
                this.unsavedCount = Object.keys(this.unsavedIds).length;
                this.saveErrorMsg = message || '';
                this.saveState = 'failed';
            },

            markSaved(logId) {
                if (this.unsavedIds[logId]) delete this.unsavedIds[logId];
                this.unsavedCount = Object.keys(this.unsavedIds).length;
                if (this.unsavedCount === 0) {
                    this.saveErrorMsg = '';
                    this.saveState = 'saved';
                } else {
                    this.saveState = 'failed';
                }
            },

            saveAnswer(qIdToSave = null) {
                let logId = qIdToSave;
                if (!logId && this.currentQuestion) {
                    logId = this.qKey(this.currentQuestion);
                }
                if (!logId) return;

                this.scheduleAutoSync();
                this.queueAutosave(logId);
            },

            /* Peredam per-soal. Timer di-reset tiap perubahan, jadi burst tap
               hanya menghasilkan SATU request berisi state terakhir.
               Yang dibatasi adalah jumlah request, BUKAN perubahan jawaban:
               perubahan terakhir selalu ikut terkirim, sehingga pembatasan ini
               tidak akan pernah menghilangkan jawaban siswa. */
            queueAutosave(logId) {
                // Cadangan lokal ditulis segera (bukan ikut ditunda), agar
                // jawaban tetap ada walau aplikasi mati sebelum timer jalan.
                this.saveLocalBackup();
                this.noteAction();
                if (this.saveTimers[logId]) clearTimeout(this.saveTimers[logId]);
                this.saveTimers[logId] = setTimeout(() => {
                    delete this.saveTimers[logId];
                    this.enqueueRequest('autosave', { logId: logId, retries: 3 });
                }, AUTOSAVE_DEBOUNCE_MS);
            },

            /* Wajib dipanggil sebelum menyelesaikan ujian: timer yang masih
               menunggu belum masuk antrean, jadi tanpa flush ini jawaban
               terakhir bisa hilang justru karena debounce. */
            flushPendingSaves() {
                Object.keys(this.saveTimers).forEach((logId) => {
                    clearTimeout(this.saveTimers[logId]);
                    delete this.saveTimers[logId];
                    this.enqueueRequest('autosave', { logId: isNaN(logId) ? logId : Number(logId), retries: 3 });
                });
            },

            noteAction() {
                const now = Date.now();
                this.actionTimes = this.actionTimes.filter(t => now - t < RATE_WINDOW_MS);
                this.actionTimes.push(now);
                if (this.actionTimes.length > RATE_MAX_ACTIONS &&
                    now - this.lastRateToast > RATE_TOAST_COOLDOWN_MS) {
                    this.lastRateToast = now;
                    this.notifyRateLimited();
                }
            },

            notifyRateLimited() {
                const msg = 'Terlalu banyak aksi. Jawaban terakhir Anda tetap tersimpan.';
                try {
                    if (window.CommsBridge && typeof window.CommsBridge.toast === 'function') {
                        window.CommsBridge.toast(msg);
                        return;
                    }
                } catch (e) { /* di luar kiosk: pakai toast halaman */ }
                this.rateToastMsg = msg;
                this.showRateToast = true;
                setTimeout(() => { this.showRateToast = false; }, 3000);
            },

            saveLocalBackup() {
                if (!ATTEMPT_ID) return;
                const backupData = {
                    questions: this.questions.map(q => ({
                        question_id: q.question_id,
                        log_id: q.log_id,
                        question_type: q.question_type,
                        answer_text: q.answer_text || '',
                        is_flagged: q.is_flagged || false,
                        matchingPairs: q.matchingPairs ? q.matchingPairs.map(p => ({ left: p.left, selected: p.selected })) : null
                    })),
                    answers: this.allAnswers
                };
                localStorage.setItem("cbt_backup_attempt_" + ATTEMPT_ID, JSON.stringify(backupData));
            },

            restoreLocalBackup() {
                if (!ATTEMPT_ID) return;
                const raw = localStorage.getItem("cbt_backup_attempt_" + ATTEMPT_ID);
                if (!raw) return;
                try {
                    const backup = JSON.parse(raw);
                    if (backup && backup.questions && backup.answers) {
                        let needsSaveIds = [];

                        this.questions.forEach((q, idx) => {
                            const bq = backup.questions.find(x => String(this.qKey(x)) === String(this.qKey(q)));
                            if (!bq) return;

                            q.is_flagged = bq.is_flagged || q.is_flagged;

                            if (q.question_type == 3) {
                                if (bq.answer_text && (!q.answer_text || q.answer_text.trim() === '')) {
                                    q.answer_text = bq.answer_text;
                                    needsSaveIds.push(idx);
                                }
                            } else if (q.question_type == 4 || q.question_type == 5) {
                                let backupMatching = {};
                                bq.matchingPairs.forEach(p => { backupMatching[p.left] = p.selected; });

                                let changed = false;
                                q.matchingPairs.forEach(p => {
                                    if (backupMatching[p.left] && !p.selected) {
                                        p.selected = backupMatching[p.left];
                                        changed = true;
                                    }
                                });
                                if (changed) {
                                    needsSaveIds.push(idx);
                                }
                            } else {
                                const serverAnswers = this.allAnswers[this.qKey(q)] || [];
                                const backupAnswers = backup.answers[this.qKey(q)] || [];

                                let changed = false;
                                serverAnswers.forEach(sa => {
                                    const ba = backupAnswers.find(x => x.answer_id === sa.answer_id);
                                    if (ba && ba.is_selected == 1 && sa.is_selected == 0) {
                                        sa.is_selected = 1;
                                        changed = true;
                                    }
                                });
                                if (changed) {
                                    needsSaveIds.push(idx);
                                }
                            }
                        });

                        // Sequentially sync any unsaved offline answers back to the server
                        if (needsSaveIds.length > 0) {
                            let saveSequence = Promise.resolve();
                            needsSaveIds.forEach(idx => {
                                saveSequence = saveSequence.then(() => {
                                    return new Promise((resolve) => {
                                        const prevIndex = this.currentIndex;
                                        this.currentIndex = idx;
                                        this.saveAnswer();
                                        setTimeout(() => {
                                            this.currentIndex = prevIndex;
                                            resolve();
                                        }, 250);
                                    });
                                });
                            });
                        }
                    }
                } catch (e) {
                    console.error("Failed to restore local backup", e);
                }
            },

            countAnswered() {
                let count = 0;
                this.questions.forEach(q => {
                    if (q.question_type == 3) {
                        if (q.answer_text && q.answer_text.trim() !== '') count++;
                    } else if (q.question_type == 4 || q.question_type == 5) {
                        if (q.matchingPairs && q.matchingPairs.every(p => p.selected !== '')) count++;
                    } else {
                        if ((this.allAnswers[this.qKey(q)] || []).some(a => a.is_selected == 1)) count++;
                    }
                });
                return count;
            },
            countFlagged() {
                return this.questions.filter(q => q.is_flagged).length;
            },

            toggleFlag() {
                this.currentQuestion.is_flagged = !this.currentQuestion.is_flagged;
            },

            getGridButtonClass(idx) {
                const q = this.questions[idx];
                if (!q) return 'unanswered';
                
                let classes = [];
                if (q.is_flagged) {
                    classes.push('flagged');
                } else {
                    let answered = false;
                    if (q.question_type == 3) answered = (q.answer_text && q.answer_text.trim() !== '');
                    else if (q.question_type == 4 || q.question_type == 5) answered = (q.matchingPairs && q.matchingPairs.every(p => p.selected !== ''));
                    else answered = (this.allAnswers[this.qKey(q)] || []).some(a => a.is_selected == 1);
                    classes.push(answered ? 'answered' : 'unanswered');
                }
                
                if (idx === this.currentIndex) classes.push('current');
                return classes.join(' ');
            },

            async confirmFinish() {
                if (EXAM_CONFIG.allowNoanswer === 0 && this.countAnswered() < this.questions.length) {
                    new bootstrap.Modal(document.getElementById('unansweredRequiredModal')).show();
                    return;
                }

                if (this.activeQueue) {
                    try { await this.activeQueue; } catch(e) {}
                }

                this.isSaving = true;
                const fd = buildFormData({ attempt_id: ATTEMPT_ID });

                $.ajax({
                    url: API + '/api/exam/check-score',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                })
                .done((res) => {
                    this.isSaving = false;
                    updateCsrf(res);
                    if (res.status === 'success' && res.score < EXAM_CONFIG.passingScore) {
                        new bootstrap.Modal(document.getElementById('warningFinishModal')).show();
                    } else {
                        new bootstrap.Modal(document.getElementById('finishModal')).show();
                    }
                })
                .fail(() => {
                    this.isSaving = false;
                    new bootstrap.Modal(document.getElementById('finishModal')).show();
                });
            },

            async forceSubmit() {
                const w1 = await Swal.fire({
                    title: 'Peringatan 1',
                    text: "Apakah Anda yakin ingin mengakhiri ujian?",
                    icon: 'warning', showCancelButton: true, confirmButtonText: 'Yakin', cancelButtonText: 'Batal'
                });
                if (!w1.isConfirmed) return;

                const w2 = await Swal.fire({
                    title: 'Peringatan 2',
                    text: "Anda masih memiliki waktu. Yakin ingin benar-benar menyerah?",
                    icon: 'warning', showCancelButton: true, confirmButtonText: 'Yakin Menyerah', cancelButtonText: 'Batal'
                });
                if (!w2.isConfirmed) return;

                const w3 = await Swal.fire({
                    title: 'Peringatan Terakhir',
                    text: "Ujian akan diakhiri secara permanen. Lanjutkan?",
                    icon: 'error', showCancelButton: true, confirmButtonText: 'Akhiri Ujian',
                    cancelButtonText: 'Batal', confirmButtonColor: '#d33'
                });
                if (w3.isConfirmed) {
                    document.querySelectorAll('.modal.show').forEach(m => {
                        bootstrap.Modal.getInstance(m)?.hide();
                    });
                    this.submitFinish();
                }
            },

            async submitFinish() {
                window.isSubmitting = true;
                if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});

                // URUTAN PENTING: dorong dulu simpanan yang masih tertahan timer
                // debounce ke antrean, BARU tunggu antreannya habis. Kebalikannya
                // akan mengirim "selesai" sementara jawaban terakhir belum naik.
                this.flushPendingSaves();

                if (this.activeQueue) {
                    try { await this.activeQueue; } catch(e) {}
                }

                const fd = buildFormData({ test_id: EXAM_CONFIG.testId, attempt_id: ATTEMPT_ID });

                $.ajax({
                    url: API + '/api/exam/finish',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                })
                .done((res) => {
                    updateCsrf(res);
                    // Simpan token supaya halaman hasil bisa meminta lepas kiosk
                    // nanti, saat siswa menekan tombol keluar.
                    try {
                        const wsToken = window.__examData ? (window.__examData.wsToken || '') : '';
                        if (wsToken) sessionStorage.setItem('cbt_kiosk_ws_token', wsToken);
                        sessionStorage.setItem('cbt_kiosk_exam_finished', '1');
                    } catch (e) {}

                    if (window.__KIOSK_BUNDLE__) {
                        // JANGAN langsung requestExit: kiosk akan lepas seketika dan
                        // siswa terlempar ke layar setup sebelum sempat melihat apa
                        // pun. Pelepasan kiosk sekarang dipicu tombol di results.html.
                        if (window.CommsBridge) window.CommsBridge.setExamActive(false);
                        window.location.href = 'results.html?test_id=' + EXAM_CONFIG.testId + '&finished=1';
                    } else if (window.CommsBridge) {
                        window.CommsBridge.requestExit(window.__examData ? (window.__examData.wsToken || '') : '');
                        if (res.redirect) {
                            window.location.href = res.redirect;
                        } else {
                            window.location.href = API + '/student/results/view/' + EXAM_CONFIG.testId;
                        }
                    } else if (res.redirect) {
                        window.location.href = res.redirect;
                    } else {
                        window.location.href = API + '/student/results/view/' + EXAM_CONFIG.testId;
                    }
                })
                .fail((err) => {
                    this.isSaving = false;
                    window.isSubmitting = false;
                    const msg = (err.responseJSON && err.responseJSON.message) ? err.responseJSON.message : 'Gagal menyelesaikan ujian.';
                    Swal.fire('Error', msg, 'error');
                });
            }
        }));
    });

    // ═══ ANTI-CHEAT ENGINE (SERVER-AUTHORITATIVE) ═══
    (function() {
        let isSuspended = false;
        let isLocked    = false;
        let suspendTimerInterval = null;
        let strikes = 0;

        // Use a getter so we always read the LATEST antiCheat config
        // (initExam overwrites EXAM_CONFIG.antiCheat after API response)
        window.getAC = function() { return (typeof EXAM_CONFIG !== 'undefined' && EXAM_CONFIG.antiCheat) || {}; };

        function clearSuspend() {
            isSuspended = false;
            if (suspendTimerInterval) {
                clearInterval(suspendTimerInterval);
                suspendTimerInterval = null;
            }
        }

        function showSuspendOverlay(currentStrikes, remainingSec) {
            isSuspended = true;
            document.getElementById('examContent').style.display = 'none';
            var overlay = document.getElementById('suspendOverlay');
            overlay.style.display = 'flex';

            document.getElementById('strikeCount').innerText = currentStrikes;
            document.getElementById('maxStrikes').innerText = getAC().max_strikes;

            var sec = remainingSec;
            var timerEl = document.getElementById('suspendTimerDisplay');
            timerEl.innerText = sec;

            if (suspendTimerInterval) clearInterval(suspendTimerInterval);

            suspendTimerInterval = setInterval(function() {
                sec--;
                timerEl.innerText = sec;
                if (sec <= 0) {
                    clearInterval(suspendTimerInterval);
                    suspendTimerInterval = null;
                    overlay.style.display = 'none';
                    isSuspended = false;
                    document.getElementById('examContent').style.display = 'block';
                    clearSuspend();
                }
            }, 1000);
        }

        async function reportCheat(type) {
            if (!ATTEMPT_ID) return;

            try {
                const fd = buildFormData({ attempt_id: ATTEMPT_ID, type: type });
                const res = await fetchWithRetry(API + '/api/exam/report-cheat', {
                    method: 'POST',
                    body: fd
                }, 2, 5000);

                const data = await res.json();
                updateCsrf(data);

                if (data.current_strikes !== undefined) {
                    strikes = data.current_strikes;
                }

                if (data.action === 'auto_submitted') {
                    isLocked = true;
                    clearSuspend();
                    document.getElementById('examContent').style.display = 'none';
                    await Swal.fire({
                        title: 'Ujian Dikumpulkan Otomatis',
                        html: (data.message || 'Ujian dikumpulkan otomatis oleh server.') + '<br><br>Menuju halaman hasil...',
                        icon: 'warning',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        confirmButtonText: 'Lihat Hasil Ujian',
                        confirmButtonColor: '#dc3545'
                    });
                    window.location.href = data.redirect || (API + '/student/dashboard');
                    return;
                }

                if (data.action === 'lock') {
                    isLocked = true;
                    clearSuspend();
                    document.getElementById('examContent').style.display = 'none';
                    await Swal.fire({
                        title: 'Ujian Dikunci Permanen',
                        html: (data.message || 'Ujian dikunci oleh server.') + '<br><br>Pelanggaran: <strong>' + strikes + '/' + getAC().max_strikes + '</strong><br><br>Akun Anda telah <strong>dinonaktifkan</strong>. Menuju halaman login...',
                        icon: 'error',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true
                    });
                    await logoutAndRedirect(API + '/login');
                } else if (data.action === 'suspend') {
                    showSuspendOverlay(data.current_strikes || data.strike || strikes, data.timer || 30);
                }
            } catch (err) {
                console.error('Failed to report cheat to server:', err);
            }
        }



        window.__antiCheat = {
            maxStrikes: getAC().max_strikes,
        };

        // ── Tab Switch Detection ──
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden || !examStarted || isLocked || isSuspended || window.isSubmitting) return;
            if (getAC().enabled === false && !getAC().auto_submit_on_cheat) return;

            if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});

            reportCheat('tab_switch');
        });

        // ── Window Focus Loss (Alt-Tab / Minimize) ──
        window.addEventListener('blur', function() {
            if (!examStarted || isLocked || isSuspended || window.isSubmitting) return;
            if (getAC().enabled === false && !getAC().auto_submit_on_cheat) return;

            if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});

            reportCheat('tab_switch'); // Treat focus loss as tab switch
        });

        // ── Fullscreen Exit Detection ──
        document.addEventListener('fullscreenchange', function() {
            if (document.fullscreenElement || !examStarted || isSuspended || isLocked || window.isSubmitting) return;

            if (getAC().enabled === false && !getAC().auto_submit_on_cheat) {
                return;
            }

            reportCheat('fullscreen_exit');
        });
    })();

    // ═══ BROWSER INTEGRITY MONITOR ═══
    // Detects modified browsers (floating bubble, split-screen bypass, event suppression)
    (function() {
        let integrityReported = false;
        let rafTimestamps = [];
        let slowRafCount = 0;
        let eventLog = { blur: 0, visibilitychange: 0, fullscreenchange: 0 };
        let expectedScreenW = 0, expectedScreenH = 0;

        // Track when security events actually fire
        window.addEventListener('blur', function() { eventLog.blur = Date.now(); }, true);
        document.addEventListener('visibilitychange', function() { eventLog.visibilitychange = Date.now(); }, true);
        document.addEventListener('fullscreenchange', function() {
            eventLog.fullscreenchange = Date.now();
            if (document.fullscreenElement) {
                expectedScreenW = screen.width;
                expectedScreenH = screen.height;
            }
        }, true);

        // rAF timing monitor
        let lastRafTime = 0;
        function rafLoop(timestamp) {
            if (lastRafTime > 0) {
                const delta = timestamp - lastRafTime;
                rafTimestamps.push(delta);
                if (rafTimestamps.length > 30) rafTimestamps.shift();
            }
            lastRafTime = timestamp;
            if (!integrityReported) requestAnimationFrame(rafLoop);
        }
        requestAnimationFrame(rafLoop);

        async function reportModifiedBrowser(detail) {
            if (integrityReported || !examStarted || window.isSubmitting) return;
            integrityReported = true;

            try {
                const fd = buildFormData({ attempt_id: ATTEMPT_ID, type: 'modified_browser', detail: detail });
                const res = await fetchWithRetry(API + '/api/exam/report-cheat', {
                    method: 'POST',
                    body: fd
                }, 2, 5000);

                const data = await res.json();
                if (data.action === 'lock') {
                    window.isSubmitting = true;
                    if (document.fullscreenElement) document.exitFullscreen().catch(()=>{});
                    document.getElementById('examContent').style.display = 'none';

                    await Swal.fire({
                        title: 'Akun Dikunci',
                        html: (data.message || 'Browser modifikasi terdeteksi.') + '<br><br>Menuju halaman login...',
                        icon: 'error',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        timer: 5000,
                        timerProgressBar: true
                    });
                    await logoutAndRedirect(API + '/login');
                }
            } catch (err) {
                console.error('Failed to report integrity violation:', err);
            }
        }

        // Main integrity check — runs every 10 seconds
        setInterval(function() {
            if (integrityReported || !examStarted || window.isSubmitting) return;
            if (getAC().enabled === false && !getAC().auto_submit_on_cheat) return;
            var now = Date.now();

            // ── Layer 1: Window Dimension Integrity ──
            if (document.fullscreenElement && expectedScreenW > 0) {
                var wDiff = Math.abs(window.innerWidth - expectedScreenW);
                var hDiff = Math.abs(window.innerHeight - expectedScreenH);
                if (wDiff > 100 || hDiff > 100) {
                    reportModifiedBrowser('dimension_mismatch:' + window.innerWidth + 'x' + window.innerHeight + '_vs_' + expectedScreenW + 'x' + expectedScreenH);
                    return;
                }
            }

            // ── Layer 2: Event Suppression Cross-Check ──
            if (!document.hasFocus() && (now - eventLog.blur > 15000)) {
                reportModifiedBrowser('focus_loss_no_blur_event');
                return;
            }
            if (document.visibilityState === 'hidden' && (now - eventLog.visibilitychange > 15000)) {
                reportModifiedBrowser('hidden_no_visibility_event');
                return;
            }
            if (expectedScreenW > 0 && !document.fullscreenElement && (now - eventLog.fullscreenchange > 15000)) {
                reportModifiedBrowser('fullscreen_exit_no_event');
                return;
            }

            // ── Layer 3: rAF Timing Analysis ──
            if (rafTimestamps.length >= 10) {
                var sum = 0;
                for (var i = 0; i < rafTimestamps.length; i++) sum += rafTimestamps[i];
                var avgDelta = sum / rafTimestamps.length;
                if (avgDelta > 200) {
                    slowRafCount++;
                    if (slowRafCount >= 3) {
                        reportModifiedBrowser('raf_timing_anomaly:avg_' + Math.round(avgDelta) + 'ms');
                        return;
                    }
                } else {
                    slowRafCount = 0;
                }
            }
        }, 10000);
    })();

    // ═══ BOOT ═══
    document.addEventListener('DOMContentLoaded', function() {
        initExam();

        const lightbox = document.getElementById('imageLightbox');
        const lightboxImg = document.getElementById('imageLightboxImg');
        
        const qContainer = document.querySelector('.question-container');
        if (qContainer) {
            qContainer.addEventListener('click', function(e) {
                if (e.target.tagName === 'IMG') {
                    lightboxImg.src = e.target.src;
                    lightbox.classList.add('active');
                }
            });
        }
        
        lightbox.addEventListener('click', function(e) {
            if (e.target !== lightboxImg) {
                lightbox.classList.remove('active');
                lightboxImg.src = '';
            }
        });
    });
// Penanda bahwa app logic sudah dieksekusi penuh. Fallback renderer
// mengeceknya via assetsReady(): kalau app.js hang (bukan error),
// fallback tetap bisa mengambil alih setelah window waktu lewat.
window.__appReady = true;

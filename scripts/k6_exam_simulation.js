import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

/**
 * ============================================================================
 * CBT-MF: k6 End-to-End Student Exam Load Testing Script
 * ============================================================================
 * Simulates 10 - 50 Concurrent Students completing an exam lifecycle:
 * 1. Login to CBT-MF Student Portal
 * 2. Navigate to Exam Preparation & 2-Step Wizard Page
 * 3. Start Exam & Initialize Attempt
 * 4. Load Question Bank & Exam State
 * 5. Iteratively Answer Questions with realistic student think-times
 * 6. Execute Periodic Auto-Sync (Write-Behind Cache)
 * 7. Submit Final Exam & Verify Result Page
 * ============================================================================
 */

// Custom Performance Metrics
const LoginDuration = new Trend('cbt_login_duration_ms');
const SaveAnswerDuration = new Trend('cbt_save_answer_duration_ms');
const FinishExamDuration = new Trend('cbt_finish_exam_duration_ms');
const SuccessRate = new Rate('cbt_success_rate');
const TotalAnswersSubmitted = new Counter('cbt_total_answers_submitted');

// Configuration Options
export const options = {
    scenarios: {
        exam_simulation: {
            executor: 'ramping-vus',
            startVUs: 5,
            stages: [
                { duration: '30s', target: 10 }, // Ramp-up to 10 students
                { duration: '1m',  target: 30 }, // Ramp-up to 30 students
                { duration: '2m',  target: 50 }, // Sustained load at 50 students
                { duration: '30s', target: 0  }, // Ramp-down
            ],
            gracefulStop: '30s',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.05'],       // HTTP Error Rate < 5%
        http_req_duration: ['p(95)<1500'],    // 95% of requests should complete within 1.5s
        cbt_save_answer_duration_ms: ['p(95)<500'], // 95% of answer saves < 500ms
        cbt_success_rate: ['rate>0.95'],      // Overall test scenario success rate > 95%
    },
};

// Target Host & Test Configuration
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const TEST_ID = __ENV.TEST_ID || '1';

// Student Credentials Array (SAS202608001 to SAS202608050, password = username)
const STUDENTS = __ENV.STUDENTS_JSON 
    ? JSON.parse(__ENV.STUDENTS_JSON)
    : Array.from({ length: 50 }, (_, i) => {
        const u = `SAS2026080${String(i + 1).padStart(2, '0')}`;
        return { username: u, password: u };
    });

/**
 * Helper to extract CSRF token from HTML body or cookies
 */
function extractCsrfToken(html) {
    const match = html.match(/name="csrf_test_name"\s+value="([^"]+)"/);
    if (match) return match[1];
    const hashMatch = html.match(/csrf_hash\s*=\s*'([^']+)'/);
    if (hashMatch) return hashMatch[1];
    return '';
}

export default function () {
    // Determine student credential based on Virtual User (VU) ID
    const vuIndex = (__VU - 1) % STUDENTS.length;
    const student = STUDENTS[vuIndex];

    const jar = http.cookieJar();
    let currentCsrfToken = '';

    // ──────────────────────────────────────────────────────────────────────────
    // STEP 1: LOGIN
    // ──────────────────────────────────────────────────────────────────────────
    group('01_Login', function () {
        // 1a. Fetch Login Page to get initial Session Cookie & CSRF Token
        const getLoginRes = http.get(`${BASE_URL}/login`, { jar });
        check(getLoginRes, { 'login_page_loaded': (r) => r.status === 200 });

        currentCsrfToken = extractCsrfToken(getLoginRes.body);

        // 1b. Submit Login Form
        const loginStartTime = new Date().getTime();
        const loginPayload = {
            username: student.username,
            password: student.password,
            csrf_test_name: currentCsrfToken,
        };

        const loginRes = http.post(`${BASE_URL}/login`, loginPayload, {
            jar,
            headers: { 
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': currentCsrfToken
            },
            redirects: 5,
        });

        LoginDuration.add(new Date().getTime() - loginStartTime);

        // Update CSRF token from login response
        const newCsrf = extractCsrfToken(loginRes.body);
        if (newCsrf) currentCsrfToken = newCsrf;

        // Check if login actually landed on /student or /admin dashboard instead of back at /login
        const isRedirectedToLogin = loginRes.url.includes('/login');
        const loginSuccess = check(loginRes, {
            'login_successful': (r) => r.status === 200 && !isRedirectedToLogin && (r.url.includes('/student') || r.body.includes('Logout') || r.body.includes('dashboard')),
        });

        console.log(`[DEBUG VU ${__VU}] Step 1 Login Result -> Final URL: ${loginRes.url}, Status: ${loginRes.status}, IsLoginURL: ${isRedirectedToLogin}`);
        if (isRedirectedToLogin) {
            console.error(`[DEBUG VU ${__VU}] LOGIN FAILED! Server redirected back to /login. Body snippet: ${loginRes.body ? loginRes.body.substring(0, 300) : ''}`);
        }

        SuccessRate.add(loginSuccess);
        if (!loginSuccess) {
            return;
        }

        sleep(1 + Math.random() * 2);
    });

    // ──────────────────────────────────────────────────────────────────────────
    // STEP 2: PREPARE EXAM (2-Step Wizard)
    // ──────────────────────────────────────────────────────────────────────────
    group('02_Prepare_Exam', function () {
        const prepRes = http.get(`${BASE_URL}/student/exam/prepare/${TEST_ID}`, { jar, redirects: 5 });
        const prepOk = check(prepRes, {
            'prepare_page_loaded': (r) => r.status === 200 && (r.body.includes('Persiapan') || r.body.includes('exam') || r.url.includes('take') || r.url.includes('prepare')),
        });

        const newCsrf = extractCsrfToken(prepRes.body);
        if (newCsrf) currentCsrfToken = newCsrf;

        console.log(`[DEBUG VU ${__VU}] Step 2 Prepare Result -> Final URL: ${prepRes.url}, Status: ${prepRes.status}`);
        if (!prepOk) {
            console.error(`[DEBUG VU ${__VU}] PREPARE FAILED! Body snippet: ${prepRes.body ? prepRes.body.substring(0, 300) : ''}`);
        }
        SuccessRate.add(prepOk);

        sleep(2 + Math.random() * 3);
    });

    // ──────────────────────────────────────────────────────────────────────────
    // STEP 3: START EXAM (Initialize Attempt)
    // ──────────────────────────────────────────────────────────────────────────
    let attemptId = null;
    group('03_Start_Exam', function () {
        const startPayload = {
            csrf_test_name: currentCsrfToken,
            password: '',
        };

        const startRes = http.post(`${BASE_URL}/student/exam/start/${TEST_ID}`, startPayload, {
            jar,
            redirects: 5,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': currentCsrfToken
            },
        });

        const startOk = check(startRes, {
            'exam_started': (r) => r.status === 200 && (r.url.includes('/exam/take/') || r.body.includes('exam-layout') || r.body.includes('RAW_QUESTIONS') || r.body.includes('soal')),
        });

        const newCsrf = extractCsrfToken(startRes.body);
        if (newCsrf) currentCsrfToken = newCsrf;

        console.log(`[DEBUG VU ${__VU}] Step 3 Start Result -> Final URL: ${startRes.url}, Status: ${startRes.status}`);
        if (!startOk) {
            console.error(`[DEBUG VU ${__VU}] START FAILED! Body snippet: ${startRes.body ? startRes.body.substring(0, 300) : ''}`);
        }
        SuccessRate.add(startOk);

        // Extract ATTEMPT_ID from JavaScript in take.php
        const attemptMatch = startRes.body.match(/ATTEMPT_ID\s*=\s*(\d+)/);
        if (attemptMatch) {
            attemptId = attemptMatch[1];
        }

        sleep(1 + Math.random() * 2);
    });

    // ──────────────────────────────────────────────────────────────────────────
    // STEP 4: TAKE EXAM & ANSWER QUESTIONS
    // ──────────────────────────────────────────────────────────────────────────
    group('04_Answer_Questions', function () {
        const totalQuestionsToAnswer = 10;

        for (let qNum = 1; qNum <= totalQuestionsToAnswer; qNum++) {
            const answerStartTime = new Date().getTime();
            const chosenAnswerId = Math.floor(Math.random() * 4) + 1;
            
            const savePayload = {
                csrf_test_name: currentCsrfToken,
                log_id: qNum,
                question_type: 1,
                answer_id: chosenAnswerId,
                is_unsure: 0,
            };

            const saveRes = http.post(`${BASE_URL}/student/exam/autosave`, savePayload, {
                jar,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': currentCsrfToken,
                },
            });

            SaveAnswerDuration.add(new Date().getTime() - answerStartTime);

            const saveOk = check(saveRes, {
                'answer_saved': (r) => r.status === 200 && (r.json('status') === 'success' || r.json('status') === 'ok'),
            });

            if (saveOk) {
                TotalAnswersSubmitted.add(1);
            } else {
                console.error(`[DEBUG VU ${__VU}] SAVE ANSWER FAILED at Q${qNum}! Status: ${saveRes.status}, Body: ${saveRes.body ? saveRes.body.substring(0, 200) : ''}`);
            }

            // Periodic auto-sync every 5 questions
            if (qNum % 5 === 0 && attemptId) {
                http.post(`${BASE_URL}/student/exam/auto-sync`, { 
                    csrf_test_name: currentCsrfToken, 
                    attempt_id: attemptId 
                }, {
                    jar,
                    headers: { 
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': currentCsrfToken,
                    },
                });
            }

            sleep(3 + Math.random() * 3);
        }
    });

    // ──────────────────────────────────────────────────────────────────────────
    // STEP 5: SUBMIT EXAM & VERIFY RESULT
    // ──────────────────────────────────────────────────────────────────────────
    group('05_Submit_Exam', function () {
        const finishStartTime = new Date().getTime();

        const finishRes = http.post(`${BASE_URL}/student/exam/finish/${TEST_ID}`, {
            csrf_test_name: currentCsrfToken,
            attempt_id: attemptId || '',
        }, {
            jar,
            redirects: 5,
            headers: { 
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': currentCsrfToken
            },
        });

        FinishExamDuration.add(new Date().getTime() - finishStartTime);

        const finishOk = check(finishRes, {
            'exam_finished': (r) => r.status === 200 && (r.url.includes('/results/view/') || r.body.includes('Hasil Ujian')),
        });

        if (!finishOk) {
            console.error(`[DEBUG VU ${__VU}] FINISH FAILED! Status: ${finishRes.status}, URL: ${finishRes.url}, Snippet: ${finishRes.body ? finishRes.body.substring(0, 300) : ''}`);
        }

        SuccessRate.add(finishOk);
        sleep(2);
    });
}

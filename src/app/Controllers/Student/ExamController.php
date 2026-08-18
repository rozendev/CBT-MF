<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\TestSubjectSetModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Models\TestLogModel;
use App\Models\TestLogAnswerModel;
use App\Models\ActivityLogModel;

class ExamController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
        $this->activityLog = new ActivityLogModel();
    }

    /**
     * Show Preparation Screen (T&C, Start Button)
     */
    public function prepare($id)
    {
        $test = $this->testModel->findCached($id);
        if (!$test || !$test->is_enabled) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak tersedia.');
        }

        // prepare()/start() adalah pintu masuk web desktop; aplikasi kiosk tidak
        // pernah lewat sini (bundle langsung ke /api/exam/init + /api/exam/start).
        // Jadi ujian ber-require_kiosk ditolak di sini tanpa perlu menunggu
        // heartbeat -- siswa dapat pesannya sebelum menjawab satu soal pun.
        if (\App\Libraries\KioskPresence::isRequired($test)) {
            \App\Libraries\KioskPresence::audit((int) $id, (int) session('user_id'), 'web_entry', 'student/exam/prepare');
            return redirect()->to('/student/dashboard')->with('error', \App\Libraries\KioskPresence::message());
        }

        // Check for active attempt
        $activeAttempt = $this->attemptModel->getActiveAttemptCached($id, session('user_id'));
        if ($activeAttempt) {
            if ($test->exam_mode == 'static' && !empty($test->static_page_path)) {
                return redirect()->to(base_url($test->static_page_path));
            }
            return redirect()->to('/student/exam/take/' . $id)->with('info', 'Anda memiliki ujian yang sedang berlangsung.');
        }

        // Check if student already finished and exam is not repeatable
        if (empty($test->is_repeatable)) {
            $completed = $this->attemptModel->where('user_id', session('user_id'))
                                             ->where('test_id', $id)
                                             ->whereIn('status', [3, 4])
                                             ->first();
            if ($completed) {
                return redirect()->to('/student/results/view/' . $id)->with('error', 'Anda telah menyelesaikan ujian ini.');
            }
        }

        // Check time boundaries
        $now = date('Y-m-d H:i:s');
        if ($test->begin_time && $now < $test->begin_time) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian ini belum dimulai.');
        }
        if ($test->end_time && $now > $test->end_time) {
            return redirect()->to('/student/dashboard')->with('error', 'Waktu ujian ini telah berakhir.');
        }

        return view('student/exam/prepare', [
            'test' => $test,
            'isAntiCheatEnabled' => (new \App\Models\SettingModel())->getValue('anti_cheat_enabled', false),
        ]);
    }

    /**
     * Generate the exam for the student
     */
    public function start($id)
    {
        $test = $this->testModel->findCached($id);
        if (!$test || !$test->is_enabled) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak valid.');
        }

        if (\App\Libraries\KioskPresence::isRequired($test)) {
            \App\Libraries\KioskPresence::audit((int) $id, (int) session('user_id'), 'web_entry', 'student/exam/start');
            return redirect()->to('/student/dashboard')->with('error', \App\Libraries\KioskPresence::message());
        }

        $userId = session('user_id');

        // Verify password if any
        $password = $this->request->getPost('password');
        if (!empty($test->password) && !hash_equals($test->password, (string)$password)) {
            return redirect()->back()->with('error', 'Password ujian salah.');
        }

        // Check for active attempt again
        $activeAttempt = $this->attemptModel->getActiveAttemptCached($id, $userId);
        if ($activeAttempt) {
            return redirect()->to('/student/exam/take/' . $id);
        }

        // Check is_repeatable: if not repeatable and student already completed an attempt, block start
        if (empty($test->is_repeatable)) {
            $completed = $this->attemptModel->where('user_id', $userId)
                                             ->where('test_id', $id)
                                             ->whereIn('status', [3, 4])
                                             ->first();
            if ($completed) {
                return redirect()->to('/student/results/view/' . $id)->with('error', 'Ujian ini hanya dapat dikerjakan satu kali.');
            }
        }

        $examService = new \App\Libraries\ExamService();
        $attempt = $examService->generateAttempt((int)$id, (int)$userId, $this->request->getIPAddress());

        if (!$attempt) {
            return redirect()->to('/student/results/view/' . $id)->with('error', 'Ujian ini hanya dapat dikerjakan satu kali atau terjadi kesalahan saat menyiapkan ujian.');
        }

        if ($test->exam_mode === 'static' && !empty($test->static_page_path)) {
            return redirect()->to(base_url($test->static_page_path));
        }
        return redirect()->to('/student/exam/take/' . $id);
    }

    /**
     * Exam Taking Interface
     */
    public function take($id)
    {
        $userId = session('user_id');
        $attempt = $this->attemptModel->getActiveAttemptCached($id, $userId);
        
        if (!$attempt) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian telah selesai atau belum dimulai.');
        }

        $test = $this->testModel->findCached($id);

        $gate = \App\Libraries\KioskPresence::check($test, $attempt, (int) $userId);
        if (!$gate['ok']) {
            \App\Libraries\KioskPresence::audit((int) $id, (int) $userId, $gate['reason'], 'student/exam/take');
            return redirect()->to('/student/dashboard')->with('error', \App\Libraries\KioskPresence::message());
        }

        if ($test->exam_mode == 'static' && !empty($test->static_page_path)) {
            return redirect()->to(base_url($test->static_page_path));
        }

        // Fetch questions generated for this attempt
        $db = \Config\Database::connect();
        $cache = service('cache');
        
        $questionsKey = "attempt_questions_{$attempt->id}";
        $questions = $cache->get($questionsKey);
        if (empty($questions)) {
            // Whitelist kolom: jangan pernah expose score/reaction_time/comment ke client
            $sql = "
                SELECT tl.id as log_id, tl.test_attempt_id, tl.question_id,
                       tl.question_text, tl.question_type, tl.question_difficulty,
                       tl.display_order, tl.num_answers, tl.answer_text, tl.is_unsure
                FROM test_logs tl
                WHERE tl.test_attempt_id = ?
                ORDER BY tl.display_order ASC
            ";
            $questions = $db->query($sql, [$attempt->id])->getResult();
            if (!empty($questions)) {
                try {
                    $cache->save($questionsKey, $questions, 7200); // 2 hours
                } catch (\Exception $e) {}
            }
        }

        if (empty($questions)) {
            return redirect()->to('/student/dashboard')->with('error', 'Gagal memuat soal ujian. Belum ada soal yang tersimpan untuk sesi ujian ini.');
        }

        // Fetch answers for all these questions
        $logIds = array_column($questions, 'log_id');
        $answers = [];
        if (!empty($logIds)) {
            $answersKey = "attempt_answers_{$attempt->id}";
            $answers = $cache->get($answersKey);
            if (empty($answers)) {
                // Whitelist kolom: is_correct (kunci jawaban) tidak boleh pernah sampai ke client
                $ansSql = "
                    SELECT tla.id as log_answer_id, tla.test_log_id, tla.answer_id,
                           tla.answer_text, tla.is_selected, tla.display_order, tla.position
                    FROM test_log_answers tla
                    WHERE tla.test_log_id IN ?
                    ORDER BY tla.display_order ASC
                ";
                $rawAnswers = $db->query($ansSql, [$logIds])->getResult();
                
                $answers = [];
                // Group answers by test_log_id
                foreach ($rawAnswers as $ans) {
                    $answers[$ans->test_log_id][] = $ans;
                }
                if (!empty($answers)) {
                    try {
                        $cache->save($answersKey, $answers, 7200); // 2 hours
                    } catch (\Exception $e) {}
                }
            }
        }

        // Merge Redis answers if any
        $redis = \App\Libraries\RedisClient::getInstance();
        try {
            if ($redis) {
                $redisKey = "exam_answers:{$attempt->id}";
                $redisAnswers = $redis->hGetAll($redisKey);
                
                if (!empty($redisAnswers)) {
                    foreach ($questions as &$q) {
                        if (isset($redisAnswers[$q->log_id])) {
                            $saved = json_decode($redisAnswers[$q->log_id], true);
                            
                            if ($q->question_type == 3) {
                                // Essay override
                                $q->answer_text = $saved['answer_text'] ?? '';
                            } elseif ($q->question_type == 4 || $q->question_type == 5) {
                                // Matching override
                                $q->answer_text = json_encode($saved['matching_answers'] ?? []);
                            } else {
                                // Multiple Choice override
                                $selectedIds = $saved['selected_answers'] ?? [];
                                if (isset($answers[$q->log_id])) {
                                    foreach ($answers[$q->log_id] as &$ans) {
                                        $ans->is_selected = in_array($ans->answer_id, $selectedIds) ? 1 : 0;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // If Redis fails, gracefully fall back to DB only
        }

        $settingModel = new \App\Models\SettingModel();
        $isAntiCheatEnabled = (bool)$settingModel->getValue('anti_cheat_enabled', false) || !empty($test->auto_submit_on_cheat);

        $wsToken = bin2hex(random_bytes(16));
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->setex("ws_student_token:{$wsToken}", 14400, json_encode([
                    'user_id' => (int)$userId,
                    'attempt_id' => (int)$attempt->id,
                    'test_id' => (int)$id
                ]));
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error generating ws_student_token: ' . $e->getMessage());
        }

        return view('student/exam/take', [
            'test' => $test,
            'attempt' => $attempt,
            'questions' => $questions,
            'answers' => $answers,
            'isAntiCheatEnabled' => $isAntiCheatEnabled,
            'wsToken' => $wsToken,
            'wsUrl' => $settingModel->getValue('websocket_url', '')
        ]);
    }


    /**
     * Save an answer via AJAX
     */
    public function saveAnswer()
    {
        if (!$this->request->isAJAX()) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Bad request']);
        }

        $logId = $this->request->getPost('log_id');
        $questionType = $this->request->getPost('question_type');
        
        $testLogModel = new TestLogModel();
        $log = $testLogModel->findCached($logId);
        if (!$log) return $this->response->setJSON(['status' => 'error']);

        $attemptModel = new \App\Models\TestAttemptModel();
        $attempt = $attemptModel->findCached($log->test_attempt_id);
        if (!$attempt || $attempt->user_id !== session('user_id')) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Unauthorized']);
        }

        if (!$this->passesKioskGate((int) $attempt->test_id, $attempt, (int) session('user_id'), 'student/exam/autosave')) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => \App\Libraries\KioskPresence::message(),
                'reason'  => 'kiosk_required',
            ]);
        }

        // Check if student was kicked/banned/finished
        if ($attempt->status == 3) {
            return $this->response->setJSON(['status' => 'kicked', 'message' => 'Ujian Anda telah diselesaikan (waktu habis/dikumpulkan).']);
        }
        if ($attempt->status == 4) {
            return $this->response->setJSON(['status' => 'kicked', 'message' => 'Ujian Anda telah dikunci karena melanggar aturan.']);
        }

        // Server-side time validation: reject saves after duration expires
        $test = $this->testModel->findCached($attempt->test_id);
        if ($test && $test->duration_minutes > 0 && $attempt->started_at) {
            $elapsedSeconds = time() - strtotime($attempt->started_at);
            $allowedSeconds = ($test->duration_minutes * 60) + 60; // 60 seconds grace period
            if ($elapsedSeconds > $allowedSeconds) {
                return $this->response->setJSON(['status' => 'kicked', 'message' => 'Waktu ujian Anda telah habis.']);
            }
        }

        // Release session lock early to prevent Redis bottleneck and CSRF token loss on concurrent AJAX
        session_write_close();

        $payload = [];
        if ($questionType == 3) {
            $answerText = $this->request->getPost('answer_text') ?? '';
            $sanitized = strip_tags($answerText);
            $payload['answer_text'] = mb_substr($sanitized, 0, 5000);
        } elseif ($questionType == 4 || $questionType == 5) {
            $payload['matching_answers'] = json_decode($this->request->getPost('matching_answers_json') ?? '{}', true) ?: [];
        } else {
            $selectedIds = $this->request->getPost('selected_answers') ?? [];
            if (!is_array($selectedIds)) {
                $selectedIds = [$selectedIds];
            }
            $payload['selected_answers'] = $selectedIds;
        }

        // Save payload to Redis (Write-Behind Cache)
        $attemptId = $log->test_attempt_id;
        $redisKey = "exam_answers:{$attemptId}";
        
        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->hSet($redisKey, $logId, json_encode($payload));
                // Set expiry of the entire hash to 24 hours just in case of orphaned attempts
                $redis->expire($redisKey, 86400); 
            } else {
                // FAIL-CLOSED: Redis unavailable — refuse to silently drop the answer.
                log_message('critical', "[FAIL-CLOSED] saveAnswer: Redis unavailable for attempt #{$attemptId}, log #{$logId}. Answer NOT saved.");
                return $this->response->setStatusCode(503)->setJSON([
                    'status'  => 'error',
                    'message' => 'Sistem penyimpanan sementara tidak dapat diakses. Jawaban Anda belum tersimpan. Mohon tunggu sebentar dan coba kirim ulang.',
                ]);
            }
        } catch (\Exception $e) {
            log_message('critical', "[FAIL-CLOSED] saveAnswer: Redis exception for attempt #{$attemptId}, log #{$logId}: " . $e->getMessage());
            return $this->response->setStatusCode(503)->setJSON([
                'status'  => 'error',
                'message' => 'Sistem penyimpanan sementara tidak dapat diakses. Jawaban Anda belum tersimpan. Mohon tunggu sebentar dan coba kirim ulang.',
            ]);
        }

        // Update answered_at timestamp directly on DB for simple tracking
        // (This is lightweight enough, but if extreme concurrency is needed, this can be moved to Redis too)
        $testLogModel->update($logId, ['answered_at' => date('Y-m-d H:i:s')]);

        try {
            $cache = service('cache');
            $cache->delete("attempt_questions_{$attemptId}");
            $cache->delete("attempt_answers_{$attemptId}");
        } catch (\Exception $e) {}

        return $this->response->setJSON(['status' => 'success']);
    }

    /**
     * Finish and Submit Exam
     */
    public function finish($id)
    {
        $userId = session('user_id');
        $attempt = $this->attemptModel->getActiveAttemptCached($id, $userId);
        
        if ($attempt && !$this->passesKioskGate((int) $id, $attempt, (int) $userId, 'student/exam/finish')) {
            return redirect()->to('/student/dashboard')->with('error', \App\Libraries\KioskPresence::message());
        }

        if ($attempt) {
            $this->flushRedisAnswersToDb($attempt->id);

            $scorer = new \App\Libraries\ScoringEngine();
            $scorer->calculateAndSaveScore($attempt->id);
            
            $this->activityLog->log('finish_test', $userId, 'test', $id, "Menyelesaikan ujian");
            $this->attemptModel->clearCacheForAttempt($attempt->id, (int)$id, (int)$userId);
        }

        if ($this->request->isAJAX()) {
            return $this->response->setJSON([
                'status' => 'success',
                'redirect' => site_url('/student/results/view/' . $id)
            ]);
        }

        return redirect()->to('/student/results/view/' . $id);
    }

    public function reportCheat()
    {
        $userId = session('user_id');
        $attemptId = $this->request->getPost('attempt_id') ?? $this->request->getGet('attempt_id');
        $cheatType = $this->request->getPost('type') ?? 'unknown';

        $examService = new \App\Libraries\ExamService();
        $result = $examService->handleCheat((int)$attemptId, (int)$userId, $cheatType);

        if (isset($result['action']) && $result['action'] === 'lock') {
            session()->destroy();
        }

        return $this->response->setJSON($result);
    }


    /**
     * Gerbang kiosk untuk jalur web desktop. Lihat App\Libraries\KioskPresence.
     *
     * $userId dioper eksplisit: autoSync() memanggil session_write_close() lebih
     * dulu, jadi jangan mengandalkan helper session di dalam sini.
     */
    private function passesKioskGate(int $testId, $attempt, int $userId, string $endpoint): bool
    {
        $test = $this->testModel->findCached($testId);
        $gate = \App\Libraries\KioskPresence::check($test, $attempt, $userId);
        if (!$gate['ok']) {
            \App\Libraries\KioskPresence::audit($testId, $userId, $gate['reason'], $endpoint);
            return false;
        }
        return true;
    }

    /**
     * Helper to automatically flush Redis cached answers to MySQL every 1 minute
     */
    public function autoSync()
    {
        $attemptId = $this->request->getPost('attempt_id');
        $userId = session('user_id');

        // Release session lock early
        session_write_close();

        $attempt = $this->attemptModel->findCached($attemptId);
        if (!$attempt || (string)$attempt->user_id !== (string)$userId) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Invalid attempt.']);
        }

        if (!$this->passesKioskGate((int) $attempt->test_id, $attempt, (int) $userId, 'student/exam/auto-sync')) {
            return $this->response->setStatusCode(403)->setJSON([
                'status'  => 'error',
                'message' => \App\Libraries\KioskPresence::message(),
                'reason'  => 'kiosk_required',
            ]);
        }

        // Flush redis cache 
        $this->flushRedisAnswersToDb($attemptId);

        return $this->response->setJSON(['status' => 'success']);
    }

    /**
     * Helper to flush Redis cached answers to MySQL and check score
     */
    public function checkCurrentScore()
    {
        $attemptId = $this->request->getPost('attempt_id');
        $userId = session('user_id');

        // Release session lock early
        session_write_close();

        $attemptModel = new \App\Models\TestAttemptModel();
        $attempt = $attemptModel->findCached($attemptId);
        if (!$attempt || (string)$attempt->user_id !== (string)$userId) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'Invalid attempt.']);
        }

        // Flush redis cache first
        $this->flushRedisAnswersToDb($attemptId);

        // Preview score
        $scoringEngine = new \App\Libraries\ScoringEngine();
        $score = $scoringEngine->calculateScorePreview($attemptId);

        return $this->response->setJSON([
            'status' => 'success',
            'score' => $score
        ]);
    }

    private function flushRedisAnswersToDb($attemptId)
    {
        $examService = new \App\Libraries\ExamService();
        $examService->flushRedisAnswersToDb((int)$attemptId);
    }
}

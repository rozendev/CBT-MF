<?php

namespace App\Controllers\Student;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\SettingModel;

class ResultController extends BaseController
{
    protected TestModel $testModel;
    protected TestAttemptModel $attemptModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
    }

    private function resolveSetting($testValue, string $globalKey, $default = false)
    {
        if ($testValue !== null) {
            return (bool) $testValue;
        }
        $settingModel = new SettingModel();
        return (bool) $settingModel->getValue($globalKey, $default);
    }

    public function view($testId)
    {
        $userId = session('user_id');
        $test = $this->testModel->find($testId);

        if (!$test) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak ditemukan.');
        }

        $attempt = $this->attemptModel->where('test_id', $testId)
                                      ->where('user_id', $userId)
                                      ->orderBy('id', 'DESC')
                                      ->first();

        if (!$attempt) {
            return redirect()->to('/student/dashboard')->with('error', 'Anda belum mengerjakan ujian ini.');
        }

        if ($attempt->status != 3) {
            return redirect()->to('/student/exam/take/' . $testId)->with('info', 'Ujian ini belum selesai.');
        }

        $showScore = $this->resolveSetting($test->show_score_after_exam, 'show_score_after_exam', true);
        $showCorrect = $this->resolveSetting($test->show_correct_answers, 'show_correct_answers', false);
        $allowReview = $this->resolveSetting($test->allow_review, 'allow_review', true);

        $totalQuestions = 0;
        $correctCount = 0;
        $wrongCount = 0;
        $unansweredCount = 0;

        if ($showScore) {
            $db = \Config\Database::connect();
            $logs = $db->table('test_logs')
                        ->where('test_attempt_id', $attempt->id)
                        ->get()->getResult();
            $totalQuestions = count($logs);

            foreach ($logs as $log) {
                if ($log->question_type == 3) {
                    if (empty(trim($log->answer_text ?? ''))) {
                        $unansweredCount++;
                    } elseif ($log->score > 0) {
                        $correctCount++;
                    } else {
                        $wrongCount++;
                    }
                } else {
                    $answered = $db->table('test_log_answers')
                                    ->where('test_log_id', $log->id)
                                    ->where('is_selected', 1)
                                    ->countAllResults();
                    if ($answered == 0) {
                        $unansweredCount++;
                    } elseif ($log->score > 0) {
                        $correctCount++;
                    } else {
                        $wrongCount++;
                    }
                }
            }
        }

        $passingScore = $test->passing_score ?: 0;
        $score = $attempt->score ?? 0;
        $isPassed = $score >= $passingScore;

        return view('student/exam/result', [
            'test'            => $test,
            'attempt'         => $attempt,
            'showScore'       => $showScore,
            'showCorrect'     => $showCorrect,
            'allowReview'     => $allowReview,
            'totalQuestions'  => $totalQuestions,
            'correctCount'    => $correctCount,
            'wrongCount'      => $wrongCount,
            'unansweredCount' => $unansweredCount,
            'passingScore'    => $passingScore,
            'isPassed'        => $isPassed,
        ]);
    }

    public function review($testId)
    {
        $userId = session('user_id');
        $test = $this->testModel->find($testId);

        if (!$test) {
            return redirect()->to('/student/dashboard')->with('error', 'Ujian tidak ditemukan.');
        }

        $allowReview = $this->resolveSetting($test->allow_review, 'allow_review', true);
        if (!$allowReview) {
            return redirect()->to('/student/results/view/' . $testId)->with('error', 'Review tidak diizinkan untuk ujian ini.');
        }

        $showCorrect = $this->resolveSetting($test->show_correct_answers, 'show_correct_answers', false);

        $attempt = $this->attemptModel->where('test_id', $testId)
                                      ->where('user_id', $userId)
                                      ->where('status', 3)
                                      ->orderBy('id', 'DESC')
                                      ->first();

        if (!$attempt) {
            return redirect()->to('/student/dashboard')->with('error', 'Anda belum menyelesaikan ujian ini.');
        }

        $db = \Config\Database::connect();
        $sql = "
            SELECT tl.*, tl.id as log_id
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
            ORDER BY tl.display_order ASC
        ";
        $logs = $db->query($sql, [$attempt->id])->getResult();

        $logIds = array_column($logs, 'log_id');
        $answers = [];
        if (!empty($logIds)) {
            $ansSql = "
                SELECT tla.*
                FROM test_log_answers tla
                WHERE tla.test_log_id IN ?
                ORDER BY tla.display_order ASC
            ";
            $rawAnswers = $db->query($ansSql, [$logIds])->getResult();
            foreach ($rawAnswers as $ans) {
                $answers[$ans->test_log_id][] = $ans;
            }
        }

        return view('student/exam/review', [
            'test'        => $test,
            'attempt'     => $attempt,
            'logs'        => $logs,
            'answers'     => $answers,
            'showCorrect' => $showCorrect,
        ]);
    }
}

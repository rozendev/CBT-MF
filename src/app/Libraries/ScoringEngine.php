<?php

namespace App\Libraries;

use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\TestLogModel;

class ScoringEngine
{
    /**
     * Calculates and saves the score for a test attempt.
     * Implements optimistic locking and batch-updates logs.
     */
    public function calculateAndSaveScore(int $attemptId)
    {
        $db = \Config\Database::connect();
        $testAttemptModel = new TestAttemptModel();
        $testModel = new TestModel();
        $testLogModel = new TestLogModel();

        // Optimistic locking check: only score if attempt is in active/paused state (status 0, 1, 2)
        $db->table('test_attempts')
           ->where('id', $attemptId)
           ->whereIn('status', [0, 1, 2])
           ->update([
               'status' => 3,
               'finished_at' => date('Y-m-d H:i:s')
           ]);

        if ($db->affectedRows() == 0) {
            // Already scored, locked, or completed by another concurrent request
            return false;
        }

        $attempt = $testAttemptModel->find($attemptId);
        if (!$attempt) return false;

        $test = $testModel->find($attempt->test_id);
        if (!$test) return false;

        // Fetch all test logs for this attempt in a single query
        $sqlLogs = "
            SELECT tl.id as log_id, tl.question_id, tl.answer_text, tl.question_type
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
        ";
        $logs = $db->query($sqlLogs, [$attemptId])->getResult();

        // Pre-fetch all answers for the entire attempt in a single query (resolves N+1 queries)
        $sqlAnswers = "
            SELECT tla.*
            FROM test_log_answers tla
            JOIN test_logs tl ON tl.id = tla.test_log_id
            WHERE tl.test_attempt_id = ?
            ORDER BY tla.display_order ASC
        ";
        $rawAnswers = $db->query($sqlAnswers, [$attemptId])->getResult();

        $answersByLogId = [];
        foreach ($rawAnswers as $ans) {
            $answersByLogId[$ans->test_log_id][] = $ans;
        }

        $totalScorePoints = 0;
        $maxPossiblePoints = 0;
        $logsUpdateBatch = [];

        foreach ($logs as $log) {
            $questionScore = 0;
            $logAnswers = $answersByLogId[$log->log_id] ?? [];

            if ($log->question_type == 1) {
                // Single Choice
                $questionScore = $this->evaluateSingleChoice($logAnswers, $test);
                $maxPossiblePoints += $test->score_right;
                
            } elseif ($log->question_type == 2) {
                // Multiple Choice (Multiple Correct)
                $questionScore = $this->evaluateMultipleChoice($logAnswers, $test);
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 3) {
                // Essay string matching
                $questionScore = $this->evaluateEssay($logAnswers, $log->answer_text, $test);
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 4 || $log->question_type == 5) {
                // Menjodohkan (Matching) or Pilihan Ganda Kompleks (True/False)
                $correctPairs = 0;
                $totalPairs = 0;
                
                $studentAnswers = [];
                if ($log->answer_text) {
                    $studentAnswers = json_decode($log->answer_text, true) ?: [];
                }

                foreach ($logAnswers as $ans) {
                    $parts = explode('|::|', $ans->answer_text);
                    if (count($parts) >= 2) {
                        $left = $parts[0];
                        $right = $parts[1];
                        $totalPairs++;
                        
                        if (isset($studentAnswers[$left]) && $studentAnswers[$left] === $right) {
                            $correctPairs++;
                        }
                    }
                }
                
                if ($totalPairs > 0) {
                    $questionScore = ($correctPairs / $totalPairs) * $test->score_right;
                } else {
                    $questionScore = 0;
                }
                $maxPossiblePoints += $test->score_right;
            }

            // Collect score for batch update of test_logs
            $logsUpdateBatch[] = [
                'id' => $log->log_id,
                'score' => $questionScore
            ];
            
            $totalScorePoints += $questionScore;
        }

        // Perform batch update to save question scores (highly optimized DB write)
        if (!empty($logsUpdateBatch)) {
            $testLogModel->updateBatch($logsUpdateBatch, 'id');
        }

        // Calculate Final Scaled Score
        $finalScore = 0;
        if ($maxPossiblePoints > 0) {
            $finalScore = ($totalScorePoints / $maxPossiblePoints) * $test->max_score;
        }

        if ($finalScore < 0) $finalScore = 0;

        // Update attempt record with score
        return $testAttemptModel->update($attemptId, [
            'score' => round($finalScore, 3)
        ]);
    }

    /**
     * Calculates the current score without saving to the database or finishing the attempt.
     */
    public function calculateScorePreview(int $attemptId)
    {
        $db = \Config\Database::connect();
        $testAttemptModel = new TestAttemptModel();
        $testModel = new TestModel();
        
        $attempt = $testAttemptModel->find($attemptId);
        if (!$attempt) return 0;

        $test = $testModel->find($attempt->test_id);
        if (!$test) return 0;

        $sqlLogs = "
            SELECT tl.id as log_id, tl.question_id, tl.answer_text, tl.question_type
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
        ";
        $logs = $db->query($sqlLogs, [$attemptId])->getResult();

        // Pre-fetch all answers for the entire attempt in a single query (resolves N+1 queries)
        $sqlAnswers = "
            SELECT tla.*
            FROM test_log_answers tla
            JOIN test_logs tl ON tl.id = tla.test_log_id
            WHERE tl.test_attempt_id = ?
            ORDER BY tla.display_order ASC
        ";
        $rawAnswers = $db->query($sqlAnswers, [$attemptId])->getResult();

        $answersByLogId = [];
        foreach ($rawAnswers as $ans) {
            $answersByLogId[$ans->test_log_id][] = $ans;
        }

        $totalScorePoints = 0;
        $maxPossiblePoints = 0;

        foreach ($logs as $log) {
            $questionScore = 0;
            $logAnswers = $answersByLogId[$log->log_id] ?? [];

            if ($log->question_type == 1) {
                $questionScore = $this->evaluateSingleChoice($logAnswers, $test);
                $maxPossiblePoints += $test->score_right;
                
            } elseif ($log->question_type == 2) {
                $questionScore = $this->evaluateMultipleChoice($logAnswers, $test);
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 3) {
                $questionScore = $this->evaluateEssay($logAnswers, $log->answer_text, $test);
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 4 || $log->question_type == 5) {
                $correctPairs = 0;
                $totalPairs = 0;
                
                $studentAnswers = [];
                if ($log->answer_text) {
                    $studentAnswers = json_decode($log->answer_text, true) ?: [];
                }

                foreach ($logAnswers as $ans) {
                    $parts = explode('|::|', $ans->answer_text);
                    if (count($parts) >= 2) {
                        $left = $parts[0];
                        $right = $parts[1];
                        $totalPairs++;
                        if (isset($studentAnswers[$left]) && $studentAnswers[$left] === $right) {
                            $correctPairs++;
                        }
                    }
                }
                
                if ($totalPairs > 0) {
                    $questionScore = ($correctPairs / $totalPairs) * $test->score_right;
                }
                $maxPossiblePoints += $test->score_right;
            }
            $totalScorePoints += $questionScore;
        }

        $finalScore = 0;
        if ($maxPossiblePoints > 0) {
            $finalScore = ($totalScorePoints / $maxPossiblePoints) * $test->max_score;
        }

        if ($finalScore < 0) $finalScore = 0;

        return round($finalScore, 3);
    }

    /**
     * Evaluate single choice.
     */
    private function evaluateSingleChoice(array $answers, $test)
    {
        $answered = false;
        foreach ($answers as $ans) {
            if ($ans->is_selected == 1) {
                $answered = true;
                if ($ans->is_correct == 1) {
                    return $test->score_right;
                }
            }
        }
        return $answered ? $test->score_wrong : $test->score_unanswered;
    }

    /**
     * Evaluate multiple choice.
     */
    private function evaluateMultipleChoice(array $answers, $test)
    {
        $totalCorrectOptions = 0;
        $totalSelected = 0;
        $selectedCorrect = 0;
        $selectedWrong = 0;

        foreach ($answers as $ans) {
            if ($ans->is_correct == 1) $totalCorrectOptions++;
            
            if ($ans->is_selected == 1) {
                $totalSelected++;
                if ($ans->is_correct == 1) {
                    $selectedCorrect++;
                } else {
                    $selectedWrong++;
                }
            }
        }

        if ($totalSelected == 0) {
            return $test->score_unanswered;
        }

        if (!$test->mcma_partial_score) {
            if ($selectedCorrect == $totalCorrectOptions && $selectedWrong == 0) {
                return $test->score_right;
            } else {
                return $test->score_wrong;
            }
        }

        if ($totalCorrectOptions > 0) {
            $scorePerCorrect = $test->score_right / $totalCorrectOptions;
            $points = ($selectedCorrect * $scorePerCorrect) - ($selectedWrong * $scorePerCorrect);
            
            if ($points < $test->score_wrong) {
                $points = $test->score_wrong;
            }
            return $points;
        }

        return $test->score_wrong;
    }

    /**
     * Evaluate essay.
     */
    private function evaluateEssay(array $answers, $studentAnswer, $test)
    {
        if (empty(trim($studentAnswer ?? ''))) return 0;
        
        $correctAnswer = reset($answers);
        if (!$correctAnswer || empty(trim($correctAnswer->answer_text ?? ''))) return 0;

        $correctStr = strtolower(trim($correctAnswer->answer_text));
        $studentStr = strtolower(trim($studentAnswer));

        return ($correctStr === $studentStr) ? $test->score_right : 0;
    }
}

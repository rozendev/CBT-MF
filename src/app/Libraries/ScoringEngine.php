<?php

namespace App\Libraries;

use App\Models\TestModel;
use App\Models\TestAttemptModel;
use App\Models\TestLogModel;
use App\Models\TestLogAnswerModel;

class ScoringEngine
{
    public function calculateAndSaveScore(int $attemptId)
    {
        $db = \Config\Database::connect();
        $testAttemptModel = new TestAttemptModel();
        $testModel = new TestModel();
        $testLogModel = new TestLogModel();
        
        $attempt = $testAttemptModel->find($attemptId);
        if (!$attempt) return false;

        $test = $testModel->find($attempt->test_id);
        if (!$test) return false;

        // Fetch all test logs for this attempt with question details
        $sql = "
            SELECT tl.id as log_id, tl.question_id, tl.answer_text, tl.question_type
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
        ";
        $logs = $db->query($sql, [$attemptId])->getResult();

        $totalScorePoints = 0;
        $maxPossiblePoints = 0; // The theoretical max points if all right

        foreach ($logs as $log) {
            $questionScore = 0;

            if ($log->question_type == 1) {
                // Single Choice
                $isCorrect = $this->isSingleChoiceCorrect($log->log_id);
                if ($isCorrect === true) {
                    $questionScore = $test->score_right;
                } elseif ($isCorrect === false) {
                    $questionScore = $test->score_wrong;
                } else {
                    $questionScore = $test->score_unanswered;
                }
                $maxPossiblePoints += $test->score_right;
                
            } elseif ($log->question_type == 2) {
                // Multiple Choice (Multiple Correct)
                $scoreResult = $this->calculateMultipleChoiceScore($log->log_id, $test);
                $questionScore = $scoreResult['score'];
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 3) {
                // Essay string matching
                $isCorrect = $this->isEssayCorrect($log->log_id, $log->answer_text);
                if ($isCorrect) {
                    $questionScore = $test->score_right;
                } else {
                    $questionScore = 0; // Or score_wrong, depending on requirements, but essay usually 0
                }
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 4 || $log->question_type == 5) {
                // Menjodohkan (Matching) or Pilihan Ganda Kompleks (True/False)
                $correctPairs = 0;
                $totalPairs = 0;
                
                $originalAnswers = $db->table('test_log_answers')
                                      ->where('test_log_id', $log->log_id)
                                      ->get()
                                      ->getResult();
                                      
                $studentAnswers = [];
                if ($log->answer_text) {
                    $studentAnswers = json_decode($log->answer_text, true) ?: [];
                }

                foreach ($originalAnswers as $ans) {
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

            // Save partial score to test_log
            $testLogModel->update($log->log_id, ['score' => $questionScore]);
            
            $totalScorePoints += $questionScore;
        }

        // Calculate Final Scaled Score
        // formula: (totalScorePoints / maxPossiblePoints) * test_max_score
        $finalScore = 0;
        if ($maxPossiblePoints > 0) {
            $finalScore = ($totalScorePoints / $maxPossiblePoints) * $test->max_score;
        }

        // Prevent negative final score if configured that way (optional, but standard is to allow or cap at 0)
        if ($finalScore < 0) $finalScore = 0;

        // Update attempt record
        return $testAttemptModel->update($attemptId, [
            'status' => 3, 
            'score' => round($finalScore, 3),
            'finished_at' => date('Y-m-d H:i:s')
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

        $sql = "
            SELECT tl.id as log_id, tl.question_id, tl.answer_text, tl.question_type
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
        ";
        $logs = $db->query($sql, [$attemptId])->getResult();

        $totalScorePoints = 0;
        $maxPossiblePoints = 0;

        foreach ($logs as $log) {
            $questionScore = 0;

            if ($log->question_type == 1) {
                $isCorrect = $this->isSingleChoiceCorrect($log->log_id);
                if ($isCorrect === true) {
                    $questionScore = $test->score_right;
                } elseif ($isCorrect === false) {
                    $questionScore = $test->score_wrong;
                } else {
                    $questionScore = $test->score_unanswered;
                }
                $maxPossiblePoints += $test->score_right;
                
            } elseif ($log->question_type == 2) {
                $scoreResult = $this->calculateMultipleChoiceScore($log->log_id, $test);
                $questionScore = $scoreResult['score'];
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 3) {
                $isCorrect = $this->isEssayCorrect($log->log_id, $log->answer_text);
                if ($isCorrect) {
                    $questionScore = $test->score_right;
                } else {
                    $questionScore = 0;
                }
                $maxPossiblePoints += $test->score_right;

            } elseif ($log->question_type == 4 || $log->question_type == 5) {
                $correctPairs = 0;
                $totalPairs = 0;
                
                $originalAnswers = $db->table('test_log_answers')
                                      ->where('test_log_id', $log->log_id)
                                      ->get()
                                      ->getResult();
                                      
                $studentAnswers = [];
                if ($log->answer_text) {
                    $studentAnswers = json_decode($log->answer_text, true) ?: [];
                }

                foreach ($originalAnswers as $ans) {
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
     * Checks if single choice is correct.
     * Returns true (correct), false (incorrect), or null (unanswered)
     */
    private function isSingleChoiceCorrect($logId)
    {
        $db = \Config\Database::connect();
        $sql = "
            SELECT tla.is_selected, tla.is_correct
            FROM test_log_answers tla
            WHERE tla.test_log_id = ?
        ";
        $answers = $db->query($sql, [$logId])->getResult();

        $answered = false;
        foreach ($answers as $ans) {
            if ($ans->is_selected == 1) {
                $answered = true;
                if ($ans->is_correct == 1) return true;
            }
        }

        return $answered ? false : null;
    }

    /**
     * Checks if essay answer matches the saved correct answer exactly (case-insensitive).
     */
    private function isEssayCorrect($logId, $studentAnswer)
    {
        if (empty(trim($studentAnswer ?? ''))) return false;

        $db = \Config\Database::connect();
        $sql = "SELECT answer_text FROM test_log_answers WHERE test_log_id = ? LIMIT 1";
        $correctAnswer = $db->query($sql, [$logId])->getRow();
        
        if (!$correctAnswer || empty(trim($correctAnswer->answer_text ?? ''))) return false;

        $correctStr = strtolower(trim($correctAnswer->answer_text));
        $studentStr = strtolower(trim($studentAnswer));

        return $correctStr === $studentStr;
    }

    /**
     * Calculates score for Multiple Choice Multiple Answers.
     * Handles partial scoring if enabled.
     */
    private function calculateMultipleChoiceScore($logId, $test)
    {
        $db = \Config\Database::connect();
        $sql = "
            SELECT tla.is_selected, tla.is_correct
            FROM test_log_answers tla
            WHERE tla.test_log_id = ?
        ";
        $answers = $db->query($sql, [$logId])->getResult();

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
            return ['score' => $test->score_unanswered];
        }

        // Strict Mode: Must get ALL correct options AND ZERO wrong options.
        // Memaksa siswa untuk berhati-hati dan melarang eksploitasi "centang semua".
        if (!$test->mcma_partial_score) {
            if ($selectedCorrect == $totalCorrectOptions && $selectedWrong == 0) {
                return ['score' => $test->score_right];
            } else {
                return ['score' => $test->score_wrong];
            }
        }

        // Partial scoring logic
        // E.g., if 3 correct options exist, each is worth (score_right / 3)
        // Selecting a wrong option penalizes by (score_right / 3) or uses score_wrong logic
        if ($totalCorrectOptions > 0) {
            $scorePerCorrect = $test->score_right / $totalCorrectOptions;
            $points = ($selectedCorrect * $scorePerCorrect) - ($selectedWrong * $scorePerCorrect);
            
            if ($points < $test->score_wrong) {
                $points = $test->score_wrong; // floor it at score_wrong
            }
            return ['score' => $points];
        }

        return ['score' => $test->score_wrong];
    }
}

<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TestModel;

class StaticExamController extends BaseController
{
    protected TestModel $testModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
    }

    /**
     * Generate static HTML page for a test
     * POST /admin/tests/static/generate/{testId}
     */
    public function generate($testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->back()->with('error', 'Ujian tidak ditemukan.');
        }

        // Determine the base URL for API calls
        $baseUrl = rtrim(base_url(), '/');

        // Fetch questions deterministically for static exam
        $db = \Config\Database::connect();
        $sets = $db->table('test_subject_sets')->where('test_id', $test->id)->get()->getResult();
        $answerModel = new \App\Models\AnswerModel();

        $questionsData = [];
        $answersData = [];
        $displayOrder = 1;

        foreach ($sets as $set) {
            $subjects = $db->table('test_subjects')->where('test_subject_set_id', $set->id)->get()->getResultArray();
            $subjectIds = array_column($subjects, 'subject_id');
            if (empty($subjectIds)) continue;

            $qBuilder = $db->table('questions')->whereIn('subject_id', $subjectIds)->where('is_enabled', 1);
            if ($set->question_type != 0) $qBuilder->where('type', $set->question_type);
            if ($set->difficulty != 0) $qBuilder->where('difficulty', $set->difficulty);

            // Deterministic selection using test->id as seed
            $questions = $qBuilder->orderBy("RAND({$test->id})")->limit($set->quantity)->get()->getResult();

            foreach ($questions as $q) {
                $questionsData[] = [
                    'question_id' => (int)$q->id,
                    'question_text' => $q->description,
                    'question_type' => (int)$q->type,
                    'display_order' => $displayOrder,
                    'num_answers' => (int)$set->num_answers ?: 0,
                    'answer_text' => '',
                    'is_unsure' => 0,
                ];

                $answers = $answerModel->getAnswersByQuestion($q->id);
                if ($test->random_answers && in_array($q->type, [1, 2])) {
                    mt_srand($test->id + $q->id);
                    shuffle($answers);
                    mt_srand();
                }

                $ansOrder = 1;
                $aData = [];
                foreach ($answers as $ans) {
                    $aData[] = [
                        'answer_id' => (int)$ans->id,
                        'answer_text' => $ans->description,
                        'display_order' => $ansOrder,
                        'is_selected' => 0,
                    ];
                    $ansOrder++;
                }
                $answersData[$q->id] = $aData;
                $displayOrder++;
            }
        }

        // We do NOT shuffle questions here because it would bake the random order into the static HTML
        // for ALL students. Shuffling is handled per-attempt dynamically via the init API.
        /*
        if ($test->random_questions) {
            mt_srand($test->id);
            shuffle($questionsData);
            mt_srand();
            $newOrder = 1;
            foreach ($questionsData as &$qd) {
                $qd['display_order'] = $newOrder++;
            }
        }
        */

        // Render the static template
        $html = view('admin/static/static_exam_template', [
            'test' => $test,
            'apiBaseUrl' => $baseUrl,
            'questionsData' => $questionsData,
            'answersData' => $answersData,
            'generatedAt' => time(),
        ]);

        // Create output directory
        $dateDir = date('Y-m-d_H-i');
        $outputDir = FCPATH . "static/{$dateDir}/";
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        // Generate slug from test name
        $slug = url_title($test->name, '-', true);
        $filename = "{$slug}.html";
        $filepath = $outputDir . $filename;

        // Write file
        if (file_put_contents($filepath, $html) === false) {
            return redirect()->back()->with('error', 'Gagal menulis file statis. Periksa izin folder.');
        }

        // Update test record
        $relativePath = "static/{$dateDir}/{$filename}";
        $this->testModel->update($testId, [
            'exam_mode' => 'static',
            'static_page_path' => $relativePath,
            'static_generated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->publish('exam_events', json_encode([
                    'event' => 'sync_mode',
                    'exam_mode' => 'static',
                    'static_page_path' => $relativePath
                ]));
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error publishing sync_mode: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', "Halaman statis berhasil di-generate: /{$relativePath}");
    }

    /**
     * Delete static HTML page
     * POST /admin/tests/static/delete/{testId}
     */
    public function delete($testId)
    {
        $test = $this->testModel->find($testId);
        if (!$test) {
            return redirect()->back()->with('error', 'Ujian tidak ditemukan.');
        }

        if ($test->static_page_path) {
            // Canonicalize and verify directory boundary
            if (!is_dir(FCPATH . 'static')) {
                mkdir(FCPATH . 'static', 0755, true);
            }
            $allowedBase = realpath(FCPATH . 'static');
            $fullPath = FCPATH . $test->static_page_path;
            $safePath = realpath($fullPath);

            if ($safePath && $allowedBase && str_starts_with($safePath, $allowedBase)) {
                if (file_exists($safePath)) {
                    unlink($safePath);
                    
                    // Try to remove empty directory
                    $dir = dirname($safePath);
                    if (is_dir($dir) && count(scandir($dir)) <= 2) {
                        rmdir($dir);
                    }
                }
            } else {
                log_message('error', 'Suspicious static_page_path traversal attempt: ' . $test->static_page_path);
            }
        }

        $this->testModel->update($testId, [
            'exam_mode' => 'normal',
            'static_page_path' => null,
            'static_generated_at' => null,
        ]);

        try {
            $redis = \App\Libraries\RedisClient::getInstance();
            if ($redis) {
                $redis->publish('exam_events', json_encode([
                    'event' => 'sync_mode',
                    'exam_mode' => 'normal',
                    'static_page_path' => null
                ]));
            }
        } catch (\Exception $e) {
            log_message('error', 'Redis error publishing sync_mode: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Halaman statis berhasil dihapus. Mode dikembalikan ke Normal.');
    }
}

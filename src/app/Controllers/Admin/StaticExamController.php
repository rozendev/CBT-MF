<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\TestModel;
use App\Models\SettingModel;

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

        // Load settings for anti-cheat config
        $settingModel = new SettingModel();
        $antiCheat = [
            'enabled' => (bool)$settingModel->getValue('anti_cheat_enabled', false),
            'auto_submit_on_cheat' => (bool)($test->auto_submit_on_cheat ?? false),
            'max_strikes' => (int)$settingModel->getValue('max_cheat_strikes', 2),
            'suspend_timer' => (int)$settingModel->getValue('suspend_timer_seconds', 30),
            'title' => $settingModel->getValue('anti_cheat_title', '⚠️ Peringatan Kecurangan!'),
            'message' => $settingModel->getValue('anti_cheat_message', 'Sistem mendeteksi Anda meninggalkan halaman ujian.'),
            'logo' => $settingModel->getValue('anti_cheat_logo', ''),
        ];

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

            // Guard: set yang dibatasi topik hanya boleh menarik dari subject pemilik topik.
            // Mencegah exclusion diam-diam soal dari subject lain yang tidak punya topic ini.
            if (!empty($set->topic_id)) {
                $topicOwner = $db->table('topics')
                                 ->select('subject_id')
                                 ->where('id', $set->topic_id)
                                 ->where('deleted_at', null)
                                 ->get()->getRow();
                if ($topicOwner) {
                    $subjectIds = [(int) $topicOwner->subject_id];
                }
            }

            $qBuilder = $db->table('questions')
                           ->select('id')
                           ->whereIn('subject_id', $subjectIds)
                           ->where('is_enabled', 1)
                           ->orderBy('id', 'ASC');

            // Jika set dibatasi ke topik/bab tertentu, ambil hanya dari topik itu
            if (!empty($set->topic_id)) {
                $qBuilder->where('topic_id', $set->topic_id);
            }

            if ($set->question_type != 0) $qBuilder->where('type', $set->question_type);
            if ($set->difficulty != 0) $qBuilder->where('difficulty', $set->difficulty);

            $questionRows = $qBuilder->get()->getResult();
            $questionIds = array_column($questionRows, 'id');
            if (empty($questionIds)) continue;

            // Deterministic PHP shuffle using test->id + set->id to match ExamService
            mt_srand($test->id * 100000 + $set->id);
            shuffle($questionIds);
            mt_srand(); // reset seed

            // Slice to get the required quantity
            $selectedIds = array_slice($questionIds, 0, $set->quantity);
            if (empty($selectedIds)) continue;

            // Fetch full rows for the selected IDs
            $questions = $db->table('questions')
                            ->whereIn('id', $selectedIds)
                            ->get()
                            ->getResult();

            // To preserve the shuffled order, sort the fetched questions based on the position in $selectedIds
            $idToIndex = array_flip($selectedIds);
            usort($questions, function ($a, $b) use ($idToIndex) {
                return $idToIndex[$a->id] <=> $idToIndex[$b->id];
            });

            foreach ($questions as $q) {
                $questionsData[] = [
                    'question_id' => (int)$q->id,
                    'question_text' => $this->extractBase64Images($q->description),
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
                        'answer_text' => $this->extractBase64Images($ans->description),
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

        $settingModel = new \App\Models\SettingModel();

        // Sector 2 assets (exam-app.js/css): versi = content hash, sehingga
        // cache immutable 1 tahun aman — URL berubah hanya saat isi berubah.
        $assetVersion = [];
        foreach (['app' => 'assets/exam-app.js', 'css' => 'assets/exam-app.css'] as $key => $relPath) {
            $absPath = FCPATH . $relPath;
            $assetVersion[$key] = file_exists($absPath) ? substr(hash_file('sha256', $absPath), 0, 12) : 'dev';
        }

        // Render the static template
        $html = view('admin/static/static_exam_template', [
            'test' => $test,
            'antiCheat' => $antiCheat,
            'apiBaseUrl' => $baseUrl,
            'questionsData' => $questionsData,
            'answersData' => $answersData,
            'generatedAt' => time(),
            'wsUrl' => \App\Libraries\WebSocketUrl::resolve($settingModel),
            'assetVersion' => $assetVersion,
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

        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['status' => 'success', 'message' => "Halaman statis berhasil di-generate: /{$relativePath}"]);
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

        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['status' => 'success', 'message' => 'Halaman statis berhasil dihapus. Mode dikembalikan ke Normal.']);
        }

        return redirect()->back()->with('success', 'Halaman statis berhasil dihapus. Mode dikembalikan ke Normal.');
    }

    /**
     * Extract base64 images from HTML, save to physical assets, and return updated HTML
     */
    private function extractBase64Images($html)
    {
        if (empty($html)) return $html;

        // Find all img tags with data:image src
        return preg_replace_callback('/<img\s+[^>]*src=["\'](data:image\/([^;]+);base64,([^"\']+)?)["\'][^>]*>/i', function($matches) {
            $fullMatch = $matches[0];
            $ext = strtolower($matches[2]); // e.g., png, jpeg
            $allowedImageExts = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
            if (!in_array($ext, $allowedImageExts, true)) {
                return $fullMatch;
            }
            $base64Data = $matches[3];

            // Decode base64
            $imageData = base64_decode($base64Data);
            if ($imageData === false) return $fullMatch;

            // Generate deterministic filename based on content hash to avoid duplicates
            $hash = md5($imageData);
            $filename = "extracted_{$hash}.{$ext}";
            $uploadPath = FCPATH . 'uploads/questions/';
            $filePath = $uploadPath . $filename;

            // Create dir if not exists
            if (!is_dir($uploadPath)) {
                @mkdir($uploadPath, 0755, true);
            }

            // Save file if it doesn't already exist
            if (!file_exists($filePath)) {
                @file_put_contents($filePath, $imageData);
            }

            // Generate new URL (relative to root so it works across hostnames)
            $newUrl = '/uploads/questions/' . $filename;

            // Replace the base64 src with the new URL
            return str_replace($matches[1], $newUrl, $fullMatch);
        }, $html);
    }
}

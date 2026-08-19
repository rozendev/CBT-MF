<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Models\SubjectModel;
use App\Models\ModuleModel;
use App\Models\TopicModel;
use App\Models\ActivityLogModel;

class QuestionController extends BaseController
{
    protected QuestionModel $questionModel;
    protected AnswerModel $answerModel;
    protected SubjectModel $subjectModel;
    protected ModuleModel $moduleModel;
    protected ActivityLogModel $activityLog;

    public function __construct()
    {
        $this->questionModel = new QuestionModel();
        $this->answerModel   = new AnswerModel();
        $this->subjectModel  = new SubjectModel();
        $this->moduleModel   = new ModuleModel();
        $this->activityLog   = new ActivityLogModel();
    }

    public function index()
    {
        $subjectId = $this->request->getGet('subject_id');
        
        $questions = $this->questionModel->getQuestionsWithDetails((int)$subjectId)->paginate(15);
        $pager     = $this->questionModel->pager;
        
        // Fetch subjects grouped by module for the filter dropdown
        $dbSubjects = $this->subjectModel->select('subjects.*, modules.name as module_name')
                                         ->join('modules', 'modules.id = subjects.module_id')
                                         ->orderBy('modules.name', 'ASC')
                                         ->orderBy('subjects.name', 'ASC')
                                         ->findAll();
        
        $subjectsByModule = [];
        foreach ($dbSubjects as $sub) {
            $subjectsByModule[$sub->module_name][] = $sub;
        }

        return view('admin/questions/index', [
            'questions'        => $questions,
            'pager'            => $pager,
            'subjectsByModule' => $subjectsByModule,
            'subjectId'        => $subjectId,
        ]);
    }

    public function create()
    {
        $dbSubjects = $this->subjectModel->select('subjects.*, modules.name as module_name')
                                         ->join('modules', 'modules.id = subjects.module_id')
                                         ->orderBy('modules.name', 'ASC')
                                         ->orderBy('subjects.name', 'ASC')
                                         ->findAll();
        
        $subjectsByModule = [];
        foreach ($dbSubjects as $sub) {
            $subjectsByModule[$sub->module_name][] = $sub;
        }

        return view('admin/questions/form', [
            'question'         => null,
            'answers'          => [],
            'subjectsByModule' => $subjectsByModule,
            'subjectId'        => $this->request->getGet('subject_id'),
            'topics'           => $this->request->getGet('subject_id')
                ? (new TopicModel())->getTopicsBySubject((int) $this->request->getGet('subject_id'))
                : []
        ]);
    }

    /**
     * AJAX: daftar topik untuk sebuah subjek (dropdown dinamis Topik/Bab)
     */
    public function topicsBySubject()
    {
        $subjectId = (int) $this->request->getGet('subject_id');
        if ($subjectId <= 0) {
            return $this->response->setJSON([]);
        }

        $topics = (new TopicModel())->getTopicsBySubject($subjectId);

        return $this->response->setJSON($topics);
    }

    public function store()
    {
        $rules = [
            'subject_id'  => 'required|is_natural_no_zero',
            'topic_id'    => 'permit_empty|is_natural_no_zero',
            'type'        => 'required|in_list[1,2,3,4,5]',
            'answer_mode' => 'permit_empty|in_list[exact,manual]',
            'description' => 'required',
            'difficulty'  => 'required|is_natural_no_zero',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $type = (int) $this->request->getPost('type');

        // Gambar yang ditempel langsung ke editor masuk sebagai data URI base64
        // dan ikut tersalin ke test_logs setiap attempt. Keluarkan jadi berkas
        // sebelum menyentuh database.
        $img = new \App\Libraries\InlineImageExtractor();

        // Insert Question
        $data = [
            'subject_id'     => $this->request->getPost('subject_id'),
            'topic_id'       => $this->request->getPost('topic_id') ?: null,
            'type'           => $type,
            // Hanya bermakna untuk tipe 3. Tipe lain dikunci ke 'exact' supaya
            // nilainya tidak menyesatkan kalau tipenya diubah belakangan.
            'answer_mode'    => $type === 3
                ? ($this->request->getPost('answer_mode') === 'manual' ? 'manual' : 'exact')
                : 'exact',
            'description'    => $img->process($this->request->getPost('description')),
            'explanation'    => $img->process($this->request->getPost('explanation')),
            'difficulty'     => $this->request->getPost('difficulty'),
            'is_enabled'     => $this->request->getPost('is_enabled') ? 1 : 0,
        ];

        if ($this->questionModel->skipValidation(true)->insert($data)) {
            $questionId = $this->questionModel->getInsertID();
            
            // Handle Answers based on type
            $this->_saveAnswers($questionId, $type);

            $this->activityLog->log('create', session('user_id'), 'question', $questionId, "Membuat soal ID: {$questionId}");
            return redirect()->to('/admin/questions?subject_id=' . $data['subject_id'])->with('success', 'Soal berhasil ditambahkan.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal menyimpan soal.');
    }

    public function preview($id)
    {
        $question = $this->questionModel->select('questions.*, subjects.name as subject_name, modules.name as module_name')
            ->join('subjects', 'subjects.id = questions.subject_id')
            ->join('modules', 'modules.id = subjects.module_id')
            ->where('questions.id', $id)
            ->first();

        if (!$question) {
            return '<div class="p-4 text-center text-danger"><i class="bi bi-exclamation-triangle-fill fs-1"></i><br>Soal tidak ditemukan.</div>';
        }

        $answers = $this->answerModel->getAnswersByQuestion($id);

        return view('admin/questions/preview', [
            'question' => $question,
            'answers'  => $answers
        ]);
    }

    public function edit($id)
    {
        $question = $this->questionModel->find($id);
        if (!$question) {
            return redirect()->to('/admin/questions')->with('error', 'Soal tidak ditemukan.');
        }

        $answers = $this->answerModel->getAnswersByQuestion($id);

        $dbSubjects = $this->subjectModel->select('subjects.*, modules.name as module_name')
                                         ->join('modules', 'modules.id = subjects.module_id')
                                         ->orderBy('modules.name', 'ASC')
                                         ->orderBy('subjects.name', 'ASC')
                                         ->findAll();
        
        $subjectsByModule = [];
        foreach ($dbSubjects as $sub) {
            $subjectsByModule[$sub->module_name][] = $sub;
        }

        return view('admin/questions/form', [
            'question'         => $question,
            'answers'          => $answers,
            'subjectsByModule' => $subjectsByModule,
            'subjectId'        => $question->subject_id,
            'topics'           => (new TopicModel())->getTopicsBySubject($question->subject_id)
        ]);
    }

    public function update($id)
    {
        $question = $this->questionModel->find($id);
        if (!$question) {
            return redirect()->to('/admin/questions')->with('error', 'Soal tidak ditemukan.');
        }

        $rules = [
            'subject_id'  => 'required|is_natural_no_zero',
            'topic_id'    => 'permit_empty|is_natural_no_zero',
            'type'        => 'required|in_list[1,2,3,4,5]',
            'answer_mode' => 'permit_empty|in_list[exact,manual]',
            'description' => 'required',
            'difficulty'  => 'required|is_natural_no_zero',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $type = (int) $this->request->getPost('type');
        $img = new \App\Libraries\InlineImageExtractor();

        $data = [
            'subject_id'     => $this->request->getPost('subject_id'),
            'topic_id'       => $this->request->getPost('topic_id') ?: null,
            'type'           => $type,
            // Hanya bermakna untuk tipe 3. Tipe lain dikunci ke 'exact' supaya
            // nilainya tidak menyesatkan kalau tipenya diubah belakangan.
            'answer_mode'    => $type === 3
                ? ($this->request->getPost('answer_mode') === 'manual' ? 'manual' : 'exact')
                : 'exact',
            'description'    => $img->process($this->request->getPost('description')),
            'explanation'    => $img->process($this->request->getPost('explanation')),
            'difficulty'     => $this->request->getPost('difficulty'),
            'is_enabled'     => $this->request->getPost('is_enabled') ? 1 : 0,
        ];

        if ($this->questionModel->skipValidation(true)->update($id, $data)) {
            // Delete old answers and recreate new ones for simplicity 
            // This is now safe because we use a Snapshot Architecture (no FK restrict on test_log_answers)
            $this->answerModel->where('question_id', $id)->delete();
            
            $this->_saveAnswers($id, $type);

            $this->activityLog->log('update', session('user_id'), 'question', $id, "Mengupdate soal ID: {$id}");
            return redirect()->to('/admin/questions?subject_id=' . $data['subject_id'])->with('success', 'Soal berhasil diperbarui.');
        }

        return redirect()->back()->withInput()->with('error', 'Gagal memperbarui soal.');
    }

    public function delete($id)
    {
        $question = $this->questionModel->find($id);
        if (!$question) {
            return redirect()->back()->with('error', 'Soal tidak ditemukan.');
        }

        if ($this->questionModel->forceDeleteWithAnswers($id)) {
            $this->activityLog->log('delete', session('user_id'), 'question', $id, "Menghapus soal ID: {$id}");
            return redirect()->back()->with('success', 'Soal berhasil dihapus.');
        }

        return redirect()->back()->with('error', 'Gagal menghapus soal.');
    }

    public function bulkDelete()
    {
        $ids = $this->request->getPost('question_ids');
        
        if (empty($ids) || !is_array($ids)) {
            return redirect()->back()->with('error', 'Tidak ada soal yang dipilih untuk dihapus.');
        }

        $deletedCount = 0;
        foreach ($ids as $id) {
            if ($this->questionModel->forceDeleteWithAnswers($id)) {
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $this->activityLog->log('delete', session('user_id'), 'question', 0, "Menghapus $deletedCount soal sekaligus");
            return redirect()->back()->with('success', "$deletedCount soal berhasil dihapus secara permanen.");
        }

        return redirect()->back()->with('error', 'Gagal menghapus soal.');
    }

    public function uploadImage()
    {
        $file = $this->request->getFile('image');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->response->setJSON(['status' => 'error', 'message' => 'File tidak valid']);
        }

        $rules = [
            'image' => 'is_image[image]|ext_in[image,png,jpg,jpeg,gif,webp]|max_size[image,5120]'
        ];

        if (!$this->validate($rules)) {
            return $this->response->setJSON(['status' => 'error', 'message' => implode(', ', $this->validator->getErrors())]);
        }

        $newName = $file->getRandomName();
        $uploadPath = FCPATH . 'uploads/questions';
        
        // Create directory if not exists
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
        
        $file->move($uploadPath, $newName);
        $filePath = $uploadPath . '/' . $newName;
        
        // Auto-resize if image is too large (max 1920px width/height)
        $maxDimension = 1920;
        $imageInfo = getimagesize($filePath);
        
        if ($imageInfo !== false) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            
            // Resize if either dimension exceeds max
            if ($width > $maxDimension || $height > $maxDimension) {
                // Calculate new dimensions maintaining aspect ratio
                $ratio = min($maxDimension / $width, $maxDimension / $height);
                $newWidth = (int)($width * $ratio);
                $newHeight = (int)($height * $ratio);
                
                // Create image resource based on type
                $source = null;
                switch ($mimeType) {
                    case 'image/jpeg':
                        $source = imagecreatefromjpeg($filePath);
                        break;
                    case 'image/png':
                        $source = imagecreatefrompng($filePath);
                        break;
                    case 'image/gif':
                        $source = imagecreatefromgif($filePath);
                        break;
                    case 'image/webp':
                        $source = imagecreatefromwebp($filePath);
                        break;
                }
                
                if ($source !== null) {
                    // Create resized image
                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    
                    // Preserve transparency for PNG, GIF, and WebP
                    if (in_array($mimeType, ['image/png', 'image/gif', 'image/webp'])) {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
                    }
                    
                    // Resize with high quality
                    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    
                    // Save resized image with compression
                    switch ($mimeType) {
                        case 'image/jpeg':
                            imagejpeg($resized, $filePath, 85); // 85% quality
                            break;
                        case 'image/png':
                            imagepng($resized, $filePath, 6); // Compression level 6
                            break;
                        case 'image/gif':
                            imagegif($resized, $filePath);
                            break;
                        case 'image/webp':
                            imagewebp($resized, $filePath, 85); // 85% quality
                            break;
                    }
                    
                    // Free memory
                    imagedestroy($source);
                    imagedestroy($resized);
                }
            }
        }
        
        $url = base_url('uploads/questions/' . $newName);

        return $this->response->setJSON([
            'status' => 'success',
            'url' => $url,
            'csrf_token' => csrf_hash()
        ]);
    }

    /**
     * Helper to parse and save answers from the POST request
     */
    private function _saveAnswers(int $questionId, int $type)
    {
        $answersData = $this->request->getPost('answers') ?? [];
        $correctIds  = $this->request->getPost('correct_answers') ?? [];
        
        if (!is_array($correctIds)) {
            $correctIds = [$correctIds];
        }

        $position = 1;
        foreach ($answersData as $key => $answerText) {
            if (trim($answerText) === '') continue;

            $isCorrect = in_array($key, $correctIds) ? 1 : 0;
            
            // If Text/Essay (3) or Menjodohkan (4), all options might be implicitly correct or just reference
            if ($type == 3 || $type == 4) {
                $isCorrect = 1; // It's just a reference answer
            }

            $this->answerModel->skipValidation(true)->insert([
                'question_id' => $questionId,
                'description' => (new \App\Libraries\InlineImageExtractor())->process($answerText),
                'is_correct'  => $isCorrect,
                'is_enabled'  => 1,
                'position'    => $position,
            ]);
            $position++;
        }
    }
}

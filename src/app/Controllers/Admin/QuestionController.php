<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Models\SubjectModel;
use App\Models\ModuleModel;
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
            'subjectId'        => $this->request->getGet('subject_id')
        ]);
    }

    public function store()
    {
        $rules = [
            'subject_id'  => 'required|is_natural_no_zero',
            'type'        => 'required|in_list[1,2,3,4]',
            'description' => 'required',
            'difficulty'  => 'required|is_natural_no_zero',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $type = (int) $this->request->getPost('type');
        
        // Insert Question
        $data = [
            'subject_id'     => $this->request->getPost('subject_id'),
            'type'           => $type,
            'description'    => $this->request->getPost('description'),
            'explanation'    => $this->request->getPost('explanation'),
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
            'subjectId'        => $question->subject_id
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
            'type'        => 'required|in_list[1,2,3,4]',
            'description' => 'required',
            'difficulty'  => 'required|is_natural_no_zero',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $type = (int) $this->request->getPost('type');

        $data = [
            'subject_id'     => $this->request->getPost('subject_id'),
            'type'           => $type,
            'description'    => $this->request->getPost('description'),
            'explanation'    => $this->request->getPost('explanation'),
            'difficulty'     => $this->request->getPost('difficulty'),
            'is_enabled'     => $this->request->getPost('is_enabled') ? 1 : 0,
        ];

        if ($this->questionModel->skipValidation(true)->update($id, $data)) {
            // Delete old answers and recreate new ones for simplicity 
            // In a real app we might want to update existing answers to preserve stats, 
            // but for exams, recreating is often safer if the structure changes.
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
            
            // If Text/Essay (3), there might be no specific correct answer checkbox
            if ($type == 3) {
                $isCorrect = 1; // It's just a reference answer
            }

            $this->answerModel->skipValidation(true)->insert([
                'question_id' => $questionId,
                'description' => $answerText,
                'is_correct'  => $isCorrect,
                'is_enabled'  => 1,
                'position'    => $position,
            ]);
            $position++;
        }
    }
}

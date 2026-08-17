<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ModuleModel;
use App\Models\SubjectModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
use App\Libraries\WordImport\WordBlockExtractor;
use App\Libraries\WordImport\WordQuestionParser;
use App\Libraries\WordImport\WordImportValidator;
use PhpOffice\PhpWord\IOFactory;

class WordImportController extends BaseController
{
    protected $moduleModel;
    protected $subjectModel;
    protected $questionModel;
    protected $answerModel;

    public function __construct()
    {
        $this->moduleModel = new ModuleModel();
        $this->subjectModel = new SubjectModel();
        $this->questionModel = new QuestionModel();
        $this->answerModel = new AnswerModel();
    }

    public function index()
    {
        $data = [
            'title' => 'Import Soal dari Word',
            'modules' => $this->moduleModel->findAll()
        ];
        return view('admin/questions/word_import', $data);
    }

    public function process()
    {
        $rules = [
            'module_id' => 'required',
            'subject_name' => 'required',
            'word_file' => 'uploaded[word_file]|ext_in[word_file,docx]|max_size[word_file,5120]'
        ];

        if (!$this->validate($rules)) {
            $errs = array_values($this->validator->getErrors());
            return $this->response->setJSON([
                'status' => 'validation_error',
                'errors' => $errs
            ]);
        }

        $moduleId = $this->request->getPost('module_id');
        if ($moduleId == 'new') {
            $newModuleName = trim($this->request->getPost('new_module_name') ?? '');
            if (empty($newModuleName)) {
                return $this->response->setJSON([
                    'status' => 'validation_error',
                    'errors' => ['Nama modul baru harus diisi.']
                ]);
            }
            
            $existsMod = $this->moduleModel->withDeleted()->where('name', $newModuleName)->first();
            if ($existsMod) {
                if ($existsMod->deleted_at !== null) {
                    $this->moduleModel->reuseDeletedModule($existsMod->id, [
                        'user_id'    => session('user_id'),
                        'is_enabled' => 1
                    ]);
                }
                $moduleId = $existsMod->id;
            } else {
                $moduleId = $this->moduleModel->insert([
                    'name'       => $newModuleName,
                    'is_enabled' => 1,
                    'user_id'    => session('user_id')
                ]);
            }
        }

        $subjectName = trim($this->request->getPost('subject_name') ?? '');
        
        $existsSub = $this->subjectModel->withDeleted()->where('module_id', $moduleId)->where('name', $subjectName)->first();
        if ($existsSub) {
            if ($existsSub->deleted_at !== null) {
                // Restore soft-deleted subject
                $this->subjectModel->reuseDeletedSubject($existsSub->id, [
                    'user_id'    => session('user_id'),
                    'is_enabled' => 1
                ]);
            }
            $subjectId = $existsSub->id;
        } else {
            // Insert new subject
            $subjectId = $this->subjectModel->insert([
                'module_id'  => $moduleId,
                'name'       => $subjectName,
                'is_enabled' => 1,
                'user_id'    => session('user_id')
            ]);
        }

        $file = $this->request->getFile('word_file');
        $filepath = $file->getTempName();

        try {
            $phpWord = IOFactory::load($filepath);

            $blocks = (new WordBlockExtractor())->extract($phpWord);
            $parsedQuestions = (new WordQuestionParser())->parse($blocks);

            // ─── DRY-RUN VALIDATION ───
            $validationErrors = (new WordImportValidator())->validate($parsedQuestions);
            if (!empty($validationErrors)) {
                return $this->response->setJSON([
                    'status' => 'validation_error',
                    'errors' => $validationErrors
                ]);
            }

            $db = \Config\Database::connect();
            $db->transStart();

            $insertedCount = 0;
            foreach ($parsedQuestions as $q) {
                $questionId = $this->questionModel->insert([
                    'subject_id'  => $subjectId,
                    'type'        => $q['type'],
                    'description' => $q['question'],
                    'difficulty'  => 1,
                    'is_enabled'  => 1
                ]);

                if ($questionId === false) {
                    $db->transRollback();
                    $errors = implode(', ', $this->questionModel->errors());
                    return $this->response->setJSON([
                        'status'  => 'error',
                        'message' => 'Gagal menyimpan soal ke database: ' . $errors
                    ]);
                }

                $position = 1;

                if ($q['type'] == 1 || $q['type'] == 2) {
                    foreach ($q['options'] as $letter => $text) {
                        $isCorrect = in_array($letter, $q['correct'], true) ? 1 : 0;
                        $this->answerModel->skipValidation(true)->insert([
                            'question_id' => $questionId,
                            'description' => $text,
                            'is_correct'  => $isCorrect,
                            'is_enabled'  => 1,
                            'position'    => $position
                        ]);
                        $position++;
                    }
                } elseif ($q['type'] == 3) {
                    $this->answerModel->skipValidation(true)->insert([
                        'question_id' => $questionId,
                        'description' => $q['answer_key'],
                        'is_correct'  => 1,
                        'is_enabled'  => 1,
                        'position'    => 1
                    ]);
                } elseif ($q['type'] == 4 || $q['type'] == 5) {
                    foreach ($q['matches'] as $pair) {
                        $this->answerModel->skipValidation(true)->insert([
                            'question_id' => $questionId,
                            'description' => $pair['left'] . '|::|' . $pair['right'],
                            'is_correct'  => 1,
                            'is_enabled'  => 1,
                            'position'    => $position
                        ]);
                        $position++;
                    }
                }

                $insertedCount++;
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->response->setJSON([
                    'status'  => 'error',
                    'message' => 'Terjadi kesalahan saat menyimpan ke database.'
                ]);
            }

            return $this->response->setJSON([
                'status'  => 'success',
                'message' => "$insertedCount soal berhasil diimport ke Subjek '$subjectName'."
            ]);

        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Gagal memproses file Word: ' . $e->getMessage()
            ]);
        }
    }

    public function downloadTemplate()
    {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $section = $phpWord->addSection();

        $fontStyleTitle = ['bold' => true, 'size' => 14];
        $fontStyleNormal = ['size' => 12];

        $section->addText('TEMPLATE IMPORT SOAL PILIHAN GANDA', $fontStyleTitle);
        $section->addTextBreak(1);

        $section->addText('Q:1) Siapa penemu bola lampu?', $fontStyleNormal);
        $section->addText('A:) Albert Einstein', $fontStyleNormal);
        $section->addText('B:) Thomas Alva Edison', $fontStyleNormal);
        $section->addText('C:) Isaac Newton', $fontStyleNormal);
        $section->addText('D:) Nikola Tesla', $fontStyleNormal);
        $section->addText('E:) Galileo Galilei', $fontStyleNormal);
        $section->addText('RIGHT:B', $fontStyleNormal);
        $section->addTextBreak(1);

        $section->addText('Q:2) Apa nama ibukota Indonesia saat ini?', $fontStyleNormal);
        $section->addText('A:) Bandung', $fontStyleNormal);
        $section->addText('B:) Surabaya', $fontStyleNormal);
        $section->addText('C:) Jakarta', $fontStyleNormal);
        $section->addText('D:) Medan', $fontStyleNormal);
        $section->addText('E:) Semarang', $fontStyleNormal);
        $section->addText('RIGHT:C', $fontStyleNormal);
        $section->addTextBreak(1);

        $section->addText('Q:3) Contoh Soal Pilihan Ganda Kompleks (Banyak Jawaban)', $fontStyleNormal);
        $section->addText('Pilihlah semua jawaban yang merupakan nama benua:', $fontStyleNormal);
        $section->addText('A:) Asia', $fontStyleNormal);
        $section->addText('B:) Pasifik', $fontStyleNormal);
        $section->addText('C:) Eropa', $fontStyleNormal);
        $section->addText('D:) Hindia', $fontStyleNormal);
        $section->addText('E:) Afrika', $fontStyleNormal);
        $section->addText('RIGHT:A,C,E', $fontStyleNormal);
        $section->addTextBreak(1);

        $section->addText('Q:4) Siapa nama presiden pertama Republik Indonesia?', $fontStyleNormal);
        $section->addText('TYPE:ESSAY', $fontStyleNormal);
        $section->addText('RIGHT:Ir. Soekarno', $fontStyleNormal);
        $section->addTextBreak(1);

        $section->addText('Q:5) Pasangkan negara berikut dengan ibukotanya!', $fontStyleNormal);
        $section->addText('TYPE:MATCHING', $fontStyleNormal);
        $section->addText('MATCH:Indonesia|::|Jakarta', $fontStyleNormal);
        $section->addText('MATCH:Jepang|::|Tokyo', $fontStyleNormal);
        $section->addText('MATCH:Korea Selatan|::|Seoul', $fontStyleNormal);
        $section->addTextBreak(1);

        $section->addText('Q:6) Tentukan benar atau salah untuk pernyataan berikut!', $fontStyleNormal);
        $section->addText('TYPE:TRUEFALSE', $fontStyleNormal);
        $section->addText('MATCH:Matahari terbit dari timur|::|Benar', $fontStyleNormal);
        $section->addText('MATCH:Bumi itu berbentuk datar|::|Salah', $fontStyleNormal);
        $section->addTextBreak(1);

        $section->addText('Q:7) Soal dengan Tabel:', $fontStyleNormal);
        $tableStyle = array('borderSize' => 6, 'borderColor' => '999999');
        $table = $section->addTable($tableStyle);
        $table->addRow();
        $table->addCell(2000)->addText('Nama', $fontStyleTitle);
        $table->addCell(2000)->addText('Usia', $fontStyleTitle);
        $table->addRow();
        $table->addCell(2000)->addText('Andi', $fontStyleNormal);
        $table->addCell(2000)->addText('15 Tahun', $fontStyleNormal);
        $section->addText('Berdasarkan tabel di atas, berapakah usia Andi?', $fontStyleNormal);
        $section->addText('A:) 10 Tahun', $fontStyleNormal);
        $section->addText('B:) 15 Tahun', $fontStyleNormal);
        $section->addText('C:) 20 Tahun', $fontStyleNormal);
        $section->addText('RIGHT:B', $fontStyleNormal);

        $fileName = 'Template_Import_Soal_CBT.docx';
        $tempFile = tempnam(sys_get_temp_dir(), 'phpword');
        
        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tempFile);

        return $this->response->download($tempFile, null)->setFileName($fileName);
    }
}

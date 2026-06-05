<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ModuleModel;
use App\Models\SubjectModel;
use App\Models\QuestionModel;
use App\Models\AnswerModel;
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
            return redirect()->back()->withInput()->with('error', 'Validasi gagal. Pastikan form diisi dan file berupa .docx maksimal 5MB.');
        }

        $moduleId = $this->request->getPost('module_id');
        if ($moduleId == 'new') {
            $newModuleName = $this->request->getPost('new_module_name');
            if (empty($newModuleName)) {
                return redirect()->back()->withInput()->with('error', 'Nama modul baru harus diisi.');
            }
            $moduleId = $this->moduleModel->insert([
                'name' => $newModuleName,
                'user_id' => session('user_id')
            ]);
        }

        $subjectName = $this->request->getPost('subject_name');
        
        // Create subject
        $subjectId = $this->subjectModel->insert([
            'module_id' => $moduleId,
            'name' => $subjectName,
            'user_id' => session('user_id')
        ]);

        $file = $this->request->getFile('word_file');
        $filepath = $file->getTempName();

        try {
            // Read document using PhpWord to support Images and Tables
            $phpWord = IOFactory::load($filepath);
            $blocks = $this->extractTextUsingPhpWord($phpWord);

            $parsedQuestions = $this->parseBlocks($blocks);
            
            if (empty($parsedQuestions)) {
                return redirect()->back()->with('error', 'Tidak ada soal yang terdeteksi. Pastikan format penulisan sudah sesuai dengan aturan.');
            }

            $db = \Config\Database::connect();
            $db->transStart();

            $insertedCount = 0;
            foreach ($parsedQuestions as $q) {
                // Determine question type
                if (!empty($q['explicit_type'])) {
                    if ($q['explicit_type'] === 'ESSAY') {
                        $type = 3;
                    } elseif ($q['explicit_type'] === 'MATCHING') {
                        $type = 4;
                    } elseif ($q['explicit_type'] === 'TRUEFALSE') {
                        $type = 5;
                    } else {
                        $type = 1;
                    }
                } else {
                    $correctAnswers = explode(',', $q['answer']);
                    $type = count($correctAnswers) > 1 ? 2 : 1; // 1: PG Tunggal, 2: PG Kompleks
                }

                // Insert Question
                $questionId = $this->questionModel->insert([
                    'subject_id' => $subjectId,
                    'type' => $type,
                    'description' => $q['question'],
                    'difficulty' => 1,
                    'is_enabled' => 1
                ]);

                if ($questionId === false) {
                    $db->transRollback();
                    $errors = implode(', ', $this->questionModel->errors());
                    return redirect()->back()->with('error', 'Gagal menyimpan soal ke database. Cek format Anda. Error: ' . $errors);
                }

                // Insert Options
                $position = 1;
                
                if ($type == 1 || $type == 2) {
                    $correctAnswers = explode(',', $q['answer']);
                    foreach ($q['options'] as $letter => $text) {
                        $isCorrect = in_array(strtoupper($letter), $correctAnswers) ? 1 : 0;
                        $this->answerModel->skipValidation(true)->insert([
                            'question_id' => $questionId,
                            'description' => $text,
                            'is_correct'  => $isCorrect,
                            'is_enabled'  => 1,
                            'position'    => $position
                        ]);
                        $position++;
                    }
                } elseif ($type == 3) {
                    // Essay
                    $this->answerModel->skipValidation(true)->insert([
                        'question_id' => $questionId,
                        'description' => $q['answer'], // RIGHT:text
                        'is_correct'  => 1,
                        'is_enabled'  => 1,
                        'position'    => 1
                    ]);
                } elseif ($type == 4 || $type == 5) {
                    // Matching / TrueFalse
                    foreach ($q['matches'] as $matchText) {
                        $this->answerModel->skipValidation(true)->insert([
                            'question_id' => $questionId,
                            'description' => $matchText, // left|::|right
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
                return redirect()->back()->with('error', 'Terjadi kesalahan saat menyimpan ke database.');
            }

            return redirect()->to('/admin/questions')->with('success', "$insertedCount soal berhasil diimport ke Subjek '$subjectName'.");

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal memproses file Word: ' . $e->getMessage());
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

    private function extractTextUsingPhpWord($phpWord)
    {
        $blocks = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $blocks = array_merge($blocks, $this->processPhpWordElement($element));
            }
        }
        return $blocks;
    }

    private function processPhpWordElement($element)
    {
        $blocks = [];
        
        if ($element instanceof \PhpOffice\PhpWord\Element\TextRun || method_exists($element, 'getElements')) {
            $paragraphText = '';
            foreach ($element->getElements() as $textElement) {
                if (method_exists($textElement, 'getText')) {
                    $paragraphText .= htmlspecialchars($textElement->getText(), ENT_QUOTES, 'UTF-8');
                } elseif (get_class($textElement) === 'PhpOffice\PhpWord\Element\TextBreak') {
                    $paragraphText .= "\n";
                } elseif ($textElement instanceof \PhpOffice\PhpWord\Element\Image) {
                    $raw = $textElement->getImageStringData();
                    if ($raw) {
                        $ext = $textElement->getImageExtension();
                        $filename = uniqid('img_') . '.' . $ext;
                        $uploadPath = FCPATH . 'uploads/questions/';
                        if (!is_dir($uploadPath)) {
                            @mkdir($uploadPath, 0777, true);
                        }
                        @file_put_contents($uploadPath . $filename, $raw);
                        $paragraphText .= "<br><img src=\"" . base_url('uploads/questions/' . $filename) . "\" style=\"max-width:100%; height:auto; margin:10px 0;\" class=\"img-fluid rounded shadow-sm\"><br>";
                    }
                }
            }
            $lines = explode("\n", $paragraphText);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $blocks[] = $line;
                }
            }
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $html = '<div class="table-responsive my-3"><table class="table table-bordered table-sm" style="border-collapse: collapse; width: 100%;" border="1">';
            foreach ($element->getRows() as $row) {
                $html .= '<tr>';
                foreach ($row->getCells() as $cell) {
                    $html .= '<td style="padding: 8px; border: 1px solid #dee2e6;">';
                    foreach ($cell->getElements() as $cellElement) {
                        $cellBlocks = $this->processPhpWordElement($cellElement);
                        $html .= implode('<br>', $cellBlocks);
                    }
                    $html .= '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table></div>';
            $blocks[] = $html;
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\Image) {
            $raw = $element->getImageStringData();
            if ($raw) {
                $ext = $element->getImageExtension();
                $filename = uniqid('img_') . '.' . $ext;
                $uploadPath = FCPATH . 'uploads/questions/';
                if (!is_dir($uploadPath)) {
                    @mkdir($uploadPath, 0777, true);
                }
                @file_put_contents($uploadPath . $filename, $raw);
                $blocks[] = "<img src=\"" . base_url('uploads/questions/' . $filename) . "\" style=\"max-width:100%; height:auto; margin:10px 0;\" class=\"img-fluid rounded shadow-sm\">";
            }
        } elseif (method_exists($element, 'getText')) {
            $text = trim($element->getText());
            if (!empty($text)) {
                $blocks[] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        
        return $blocks;
    }

    private function parseBlocks($blocks)
    {
        $questions = [];
        $currentQuestion = null;

        foreach ($blocks as $text) {
            // Ignore MODULE:= and TOPIC:= if they exist
            if (preg_match('/^(?:MODULE|TOPIC)\s*:=/i', $text)) {
                continue;
            }

            // Match Q:1) Question Text
            if (preg_match('/^Q:\s*\d+\)(.*)/i', $text, $matches)) {
                if ($currentQuestion && !empty($currentQuestion['question'])) {
                    $questions[] = $currentQuestion;
                }
                $currentQuestion = [
                    'question' => trim($matches[1]),
                    'options' => [],
                    'answer' => '',
                    'explicit_type' => '',
                    'matches' => []
                ];
            }
            // Match A:) Option Text
            elseif (preg_match('/^([A-Z]):\s*\)(.*)/i', $text, $matches)) {
                if ($currentQuestion) {
                    $letter = strtoupper($matches[1]);
                    $currentQuestion['options'][$letter] = trim($matches[2]);
                }
            }
            // Match RIGHT:A or RIGHT:A,B,C or RIGHT:Essay Text
            elseif (preg_match('/^RIGHT:\s*(.*)/i', $text, $matches)) {
                if ($currentQuestion) {
                    // For multiple choice, it's comma separated letters. For essay, it's just text.
                    $currentQuestion['answer'] = trim($matches[1]);
                    // Remove spaces only if it looks like a multiple choice answer (e.g., A,B,C)
                    if (preg_match('/^[A-Z, ]+$/i', $currentQuestion['answer']) && empty($currentQuestion['explicit_type'])) {
                        $currentQuestion['answer'] = strtoupper(str_replace(' ', '', $currentQuestion['answer']));
                    }
                }
            }
            // Match TYPE:ESSAY / MATCHING / TRUEFALSE
            elseif (preg_match('/^TYPE:\s*(ESSAY|MATCHING|TRUEFALSE)/i', $text, $matches)) {
                if ($currentQuestion) {
                    $currentQuestion['explicit_type'] = strtoupper(trim($matches[1]));
                }
            }
            // Match MATCH:Left|::|Right
            elseif (preg_match('/^MATCH:\s*(.*)/i', $text, $matches)) {
                if ($currentQuestion) {
                    $currentQuestion['matches'][] = trim($matches[1]);
                }
            }
            // Continuation of question text or option text
            else {
                if ($currentQuestion) {
                    if (empty($currentQuestion['options'])) {
                        // Append to question
                        if (!empty($currentQuestion['question'])) {
                            $currentQuestion['question'] .= '<br>' . $text;
                        } else {
                            $currentQuestion['question'] = $text;
                        }
                    } else {
                        // Append to last option
                        $lastOptionKey = array_key_last($currentQuestion['options']);
                        if ($lastOptionKey) {
                            $currentQuestion['options'][$lastOptionKey] .= '<br>' . $text;
                        }
                    }
                }
            }
        }
        
        if ($currentQuestion && !empty($currentQuestion['question'])) {
            $questions[] = $currentQuestion;
        }

        return $questions;
    }
}

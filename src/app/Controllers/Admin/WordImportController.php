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
            // Read document using PhpWord to support Images and Tables
            $phpWord = IOFactory::load($filepath);
            $blocks = $this->extractTextUsingPhpWord($phpWord);

            $parsedQuestions = $this->parseBlocks($blocks);
            
            // ─── DRY-RUN VALIDATION ───
            $validationErrors = $this->validateParsedQuestions($parsedQuestions);
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
                    'subject_id'  => $subjectId,
                    'type'        => $type,
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

                // Insert Options
                $position = 1;
                
                if ($type == 1 || $type == 2) {
                    $correctAnswers = array_map('trim', explode(',', $q['answer']));
                    foreach ($q['options'] as $letter => $text) {
                        $isCorrect = in_array(strtoupper($letter), $correctAnswers, true) ? 1 : 0;
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
                        $allowedImageExts = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
                        if (!in_array(strtolower($ext), $allowedImageExts, true)) {
                            continue; // skip non-image embedded objects
                        }
                        $filename = uniqid('img_') . '.' . $ext;
                        $uploadPath = FCPATH . 'uploads/questions/';
                        if (!is_dir($uploadPath)) {
                            @mkdir($uploadPath, 0755, true);
                        }
                        @file_put_contents($uploadPath . $filename, $raw);
                        $paragraphText .= "<br><img src=\"/uploads/questions/" . $filename . "\" style=\"max-width:100%; height:auto; margin:10px 0;\" class=\"img-fluid rounded shadow-sm\"><br>";
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
                $allowedImageExts = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
                if (!in_array(strtolower($ext), $allowedImageExts, true)) {
                    return $blocks; // skip non-image embedded objects
                }
                $filename = uniqid('img_') . '.' . $ext;
                $uploadPath = FCPATH . 'uploads/questions/';
                if (!is_dir($uploadPath)) {
                    @mkdir($uploadPath, 0755, true);
                }
                @file_put_contents($uploadPath . $filename, $raw);
                $blocks[] = "<img src=\"/uploads/questions/" . $filename . "\" style=\"max-width:100%; height:auto; margin:10px 0;\" class=\"img-fluid rounded shadow-sm\">";
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
        $currentSection = 'none'; // 'question', 'option', 'none'
        $lastOptionKey = null;

        foreach ($blocks as $text) {
            $trimmed = trim($text);
            if (empty($trimmed)) {
                continue;
            }

            // Ignore MODULE:= and TOPIC:= if present
            if (preg_match('/^(?:MODULE|TOPIC)\s*:=/i', $trimmed)) {
                continue;
            }

            // 1. Question Prefix: Q:1), Q: 1., Q.1), Q1), Q1., Q:, SOAL 1:, QUESTION 1:
            if (preg_match('/^(?:Q|SOAL|QUESTION)(?:\s*[:.\-)]|\s*\d+[\s:.\-)]*)\s*(.*)/i', $trimmed, $matches)) {
                if ($currentQuestion && !empty($currentQuestion['question'])) {
                    $questions[] = $currentQuestion;
                }
                $currentQuestion = [
                    'question'      => trim($matches[1]),
                    'options'       => [],
                    'answer'        => '',
                    'explicit_type' => '',
                    'matches'       => []
                ];
                $currentSection = 'question';
                $lastOptionKey = null;
                continue;
            }

            if (!$currentQuestion) {
                continue;
            }

            // 2. Question Type: TYPE: ESSAY / MATCHING / TRUEFALSE / PG / PGK
            if (preg_match('/^(?:TYPE|TIPE)\s*[:=]\s*(ESSAY|MATCHING|TRUEFALSE|PGK|PG)/i', $trimmed, $matches)) {
                $typeStr = strtoupper(trim($matches[1]));
                if ($typeStr === 'PG' || $typeStr === 'PGK') {
                    $currentQuestion['explicit_type'] = '';
                } else {
                    $currentQuestion['explicit_type'] = $typeStr;
                }
                $currentSection = 'none';
                continue;
            }

            // 3. Right Answer: RIGHT: A or RIGHT: A,B,C or KUNCI: A
            if (preg_match('/^(?:RIGHT|KUNCI|JAWABAN)\s*[:=]\s*(.*)/i', $trimmed, $matches)) {
                $currentQuestion['answer'] = trim($matches[1]);
                if (preg_match('/^[A-Z, ]+$/i', $currentQuestion['answer']) && empty($currentQuestion['explicit_type'])) {
                    $currentQuestion['answer'] = strtoupper(str_replace(' ', '', $currentQuestion['answer']));
                }
                $currentSection = 'none';
                continue;
            }

            // 4. Match Pair: MATCH: Left|::|Right or PASANGAN: Left|::|Right
            if (preg_match('/^(?:MATCH|PASANGAN)\s*[:=]\s*(.*)/i', $trimmed, $matches)) {
                $currentQuestion['matches'][] = trim($matches[1]);
                $currentSection = 'none';
                continue;
            }

            // 5. Option Prefix: A:), A.), A., A), A:, A -
            if (preg_match('/^([A-Z])\s*[:.\-)]+\s*(.*)/i', $trimmed, $matches)) {
                $letter = strtoupper($matches[1]);
                $optionText = trim($matches[2]);
                $currentQuestion['options'][$letter] = $optionText;
                $currentSection = 'option';
                $lastOptionKey = $letter;
                continue;
            }

            // 6. Continuation of multi-line question text or option text
            if ($currentSection === 'question') {
                if (!empty($currentQuestion['question'])) {
                    $currentQuestion['question'] .= '<br>' . $trimmed;
                } else {
                    $currentQuestion['question'] = $trimmed;
                }
            } elseif ($currentSection === 'option' && $lastOptionKey !== null) {
                if (!empty($currentQuestion['options'][$lastOptionKey])) {
                    $currentQuestion['options'][$lastOptionKey] .= '<br>' . $trimmed;
                } else {
                    $currentQuestion['options'][$lastOptionKey] = $trimmed;
                }
            }
        }

        if ($currentQuestion && !empty($currentQuestion['question'])) {
            $questions[] = $currentQuestion;
        }

        return $questions;
    }

    private function validateParsedQuestions(array $questions): array
    {
        $errors = [];

        if (empty($questions)) {
            return ['Tidak ada soal yang terdeteksi. Pastikan format dokumen sesuai (contoh: Q:1) Teks Soal).'];
        }

        foreach ($questions as $index => $q) {
            $no = $index + 1;
            $qText = trim(strip_tags($q['question'], '<img><table><tr><td><th><br><p><b><i><strong><em>'));

            if (empty($qText)) {
                $errors[] = "Soal No. #{$no}: Teks soal tidak boleh kosong.";
            }

            $explicitType = strtoupper(trim($q['explicit_type'] ?? ''));

            if ($explicitType === 'ESSAY') {
                if (empty(trim($q['answer']))) {
                    $errors[] = "Soal No. #{$no} (Essay): Kunci jawaban (RIGHT:) belum diisi.";
                }
            } elseif ($explicitType === 'MATCHING' || $explicitType === 'TRUEFALSE') {
                if (empty($q['matches'])) {
                    $errors[] = "Soal No. #{$no} ({$explicitType}): Tidak ada pasangan jawaban (MATCH:) yang ditemukan.";
                } else {
                    foreach ($q['matches'] as $mIdx => $mText) {
                        if (!str_contains($mText, '|::|')) {
                            $errors[] = "Soal No. #{$no} ({$explicitType}): Format baris MATCH " . ($mIdx + 1) . " ('{$mText}') salah. Harus menggunakan separator '|::|'.";
                        } else {
                            $parts = explode('|::|', $mText);
                            if (empty(trim($parts[0])) || empty(trim($parts[1]))) {
                                $errors[] = "Soal No. #{$no} ({$explicitType}): Sisi kiri atau kanan pada MATCH " . ($mIdx + 1) . " tidak boleh kosong.";
                            }
                        }
                    }
                }
            } else {
                // Multiple Choice / PG / PGK
                if (empty($q['options']) || count($q['options']) < 2) {
                    $errors[] = "Soal No. #{$no} (Pilihan Ganda): Harus memiliki minimal 2 pilihan jawaban (A, B, dst).";
                }

                if (empty(trim($q['answer']))) {
                    $errors[] = "Soal No. #{$no} (Pilihan Ganda): Kunci jawaban (RIGHT:) belum ditentukan.";
                } else {
                    $correctAnswers = array_map('trim', explode(',', $q['answer']));
                    $availableOptions = array_keys($q['options']);

                    foreach ($correctAnswers as $ansKey) {
                        $ansKeyUpper = strtoupper($ansKey);
                        if (!in_array($ansKeyUpper, $availableOptions, true)) {
                            $optStr = implode(', ', $availableOptions);
                            $errors[] = "Soal No. #{$no}: Kunci jawaban '{$ansKeyUpper}' tidak cocok dengan opsi yang tersedia ({$optStr}).";
                        }
                    }
                }
            }
        }

        return $errors;
    }
}

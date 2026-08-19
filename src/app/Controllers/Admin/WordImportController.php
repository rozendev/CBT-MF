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

            $blocks = (new WordBlockExtractor())->extract($phpWord, $filepath);
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
                    'answer_mode' => $q['answer_mode'] ?? 'exact',
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

        } catch (\Throwable $e) {
            log_message('critical', 'WordImportController::process gagal: {exception}', ['exception' => $e]);
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => 'Gagal memproses file Word: ' . $e->getMessage()
            ]);
        }
    }

    public function downloadTemplate()
    {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();

        // Numbering style-nya wajib didaftarkan: kalau tidak, addListItem()
        // menulis <w:numId w:val=""/> yang tidak menunjuk ke definisi apa pun,
        // jadi contoh "list bawaan Word"-nya bukan list beneran di Word.
        // Level 0 bernomor angka (soal), level 1 berhuruf (opsi) -- sama persis
        // dengan yang dihasilkan AutoCorrect Word saat user mengetik sendiri.
        $phpWord->addNumberingStyle('templateList', [
            'type'   => 'multilevel',
            'levels' => [
                ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
                ['format' => 'upperLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
            ],
        ]);

        $section = $phpWord->addSection();

        $fontTitle  = ['bold' => true, 'size' => 14];
        $fontNormal = ['size' => 12];
        $tableStyle = ['borderSize' => 6, 'borderColor' => '999999'];

        $section->addText('TEMPLATE IMPORT SOAL (FORMAT BARU)', $fontTitle);
        $section->addTextBreak(1);

        // 1) PG Tunggal - angka & huruf polos, jawaban ditandai *
        $section->addText('1. Siapa penemu bola lampu?', $fontNormal);
        $section->addText('A. Albert Einstein', $fontNormal);
        $section->addText('*B. Thomas Alva Edison', $fontNormal);
        $section->addText('C. Isaac Newton', $fontNormal);
        $section->addText('D. Nikola Tesla', $fontNormal);
        $section->addTextBreak(1);

        // 2) PG Kompleks - lebih dari satu opsi ber-bintang
        $section->addText('2. Pilihlah semua jawaban yang merupakan nama benua:', $fontNormal);
        $section->addText('*A. Asia', $fontNormal);
        $section->addText('B. Pasifik', $fontNormal);
        $section->addText('*C. Eropa', $fontNormal);
        $section->addText('D. Hindia', $fontNormal);
        $section->addText('*E. Afrika', $fontNormal);
        $section->addTextBreak(1);

        // 3) Soal & opsi lewat fitur List/Numbering bawaan Word
        // Catatan penjelas TIDAK ditulis sebagai paragraf terpisah: baris bebas
        // di antara opsi soal sebelumnya dan soal berikutnya akan ikut nyambung
        // ke opsi terakhir yang sedang berjalan (lihat "Multi line question and
        // option text is joined with br" di WordQuestionParser). Makanya
        // penjelasannya digabung ke teks soal lewat list item ini sendiri.
        $section->addListItem('Ibukota Jepang adalah? (soal dan opsi ini ditulis lewat fitur List/Numbering bawaan Word, tanpa mengetik angka/huruf)', 0, $fontNormal, 'templateList');
        $section->addListItem('Osaka', 1, $fontNormal, 'templateList');
        $section->addListItem('*Tokyo', 1, $fontNormal, 'templateList');
        $section->addListItem('Kyoto', 1, $fontNormal, 'templateList');
        $section->addTextBreak(1);

        // 4) Esai dengan kunci jawaban opsional
        $section->addText('4. Siapa nama presiden pertama Republik Indonesia?', $fontNormal);
        $section->addText('Jawaban: Ir. Soekarno', $fontNormal);
        $section->addTextBreak(1);

        // 5) Esai tanpa kunci sama sekali - tetap valid
        $section->addText('5. Jelaskan pendapatmu tentang pentingnya menjaga lingkungan.', $fontNormal);
        $section->addTextBreak(1);

        // 6) Menjodohkan lewat tabel
        $section->addText('6. Pasangkan negara berikut dengan ibukotanya!', $fontNormal);
        $section->addText('Tipe: Menjodohkan', $fontNormal);
        $table1 = $section->addTable($tableStyle);
        $table1->addRow();
        $table1->addCell(2500)->addText('Negara', $fontTitle);
        $table1->addCell(2500)->addText('Ibukota', $fontTitle);
        $table1->addRow();
        $table1->addCell(2500)->addText('Indonesia', $fontNormal);
        $table1->addCell(2500)->addText('Jakarta', $fontNormal);
        $table1->addRow();
        $table1->addCell(2500)->addText('Jepang', $fontNormal);
        $table1->addCell(2500)->addText('Tokyo', $fontNormal);
        $table1->addRow();
        $table1->addCell(2500)->addText('Korea Selatan', $fontNormal);
        $table1->addCell(2500)->addText('Seoul', $fontNormal);
        $section->addTextBreak(1);

        // 7) Benar/Salah lewat tabel
        $section->addText('7. Tentukan benar atau salah untuk pernyataan berikut!', $fontNormal);
        $section->addText('Tipe: Benar/Salah', $fontNormal);
        $table2 = $section->addTable($tableStyle);
        $table2->addRow();
        $table2->addCell(4000)->addText('Pernyataan', $fontTitle);
        $table2->addCell(2000)->addText('Jawaban', $fontTitle);
        $table2->addRow();
        $table2->addCell(4000)->addText('Matahari terbit dari timur', $fontNormal);
        $table2->addCell(2000)->addText('Benar', $fontNormal);
        $table2->addRow();
        $table2->addCell(4000)->addText('Bumi itu berbentuk datar', $fontNormal);
        $table2->addCell(2000)->addText('Salah', $fontNormal);
        $section->addTextBreak(1);

        // 8) Soal dengan tabel data referensi biasa (BUKAN tabel pasangan, tanpa "Tipe:")
        $section->addText('8. Soal dengan tabel data:', $fontNormal);
        $table3 = $section->addTable($tableStyle);
        $table3->addRow();
        $table3->addCell(2000)->addText('Nama', $fontTitle);
        $table3->addCell(2000)->addText('Usia', $fontTitle);
        $table3->addRow();
        $table3->addCell(2000)->addText('Andi', $fontNormal);
        $table3->addCell(2000)->addText('15 Tahun', $fontNormal);
        $section->addText('Berdasarkan tabel di atas, berapakah usia Andi?', $fontNormal);
        $section->addText('A. 10 Tahun', $fontNormal);
        $section->addText('*B. 15 Tahun', $fontNormal);
        $section->addText('C. 20 Tahun', $fontNormal);

        $fileName = 'Template_Import_Soal_CBT.docx';
        $tempFile = tempnam(sys_get_temp_dir(), 'phpword');

        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($tempFile);

        return $this->response->download($tempFile, null)->setFileName($fileName);
    }
}

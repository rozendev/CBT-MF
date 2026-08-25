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
use App\Libraries\WordImport\WordImportException;
use App\Libraries\WordImport\WordImportTriage;
use PhpOffice\PhpWord\IOFactory;

class WordImportController extends BaseController
{
    /** Sepanjang kolom name di tabel modules maupun subjects. */
    private const MAX_NAME_LENGTH = 255;

    /**
     * Banyaknya soal per sekali insert. Dokumen berisi ratusan soal ditulis
     * per rombongan supaya database tidak menerima ratusan statement terpisah
     * dalam satu transaksi panjang.
     */
    private const BATCH_SIZE = 100;

    /** Batas jumlah baris yang dikirim ke modal hasil, supaya responsnya wajar. */
    private const MAX_REPORTED_ROWS = 100;

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
            return $this->validationError($this->validator->getErrors());
        }

        $moduleId      = $this->request->getPost('module_id');
        $newModuleName = trim((string) ($this->request->getPost('new_module_name') ?? ''));
        $subjectName   = trim((string) ($this->request->getPost('subject_name') ?? ''));

        // Modul/subjek tujuan diperiksa lebih dulu tapi belum ditulis: dokumen
        // yang gagal dibaca atau gagal divalidasi tidak boleh meninggalkan modul
        // dan subjek kosong, apalagi menghidupkan lagi yang sudah dihapus guru.
        $targetErrors = $this->validateTarget($moduleId, $newModuleName, $subjectName);
        if ($targetErrors !== []) {
            return $this->validationError($targetErrors);
        }

        $extractor = new WordBlockExtractor();

        try {
            $parsedQuestions = $this->readQuestions($this->request->getFile('word_file'), $extractor);
        } catch (\Throwable $e) {
            log_message('critical', 'WordImportController::process gagal membaca dokumen: {exception}', ['exception' => $e]);
            return $this->failure('File Word tidak bisa dibaca. Pastikan file benar-benar berformat .docx dan tidak rusak.');
        }

        // Dokumen yang sama sekali tidak berisi soal tetap ditolak utuh: tidak
        // ada yang bisa dilaporkan per soal.
        if ($parsedQuestions === []) {
            return $this->validationError((new WordImportValidator())->validate([]));
        }

        // ─── DRY-RUN: pilah mana yang masuk, kembar, dan ditolak ───
        $triage = (new WordImportTriage())->classify(
            $parsedQuestions,
            $this->existingQuestionTexts($this->findTargetSubjectId($moduleId, $newModuleName, $subjectName))
        );

        // Tidak ada satu pun soal yang bisa masuk: jangan sentuh database, jangan
        // tulis gambar, dan jangan bikin modul/subjek baru hanya untuk diisi nol.
        if ($triage['accepted'] === []) {
            return $this->summary($subjectName, 0, $triage);
        }

        $db = \Config\Database::connect();
        $db->transBegin();
        $writtenImages = [];

        try {
            // Gambar baru menyentuh disk setelah dokumennya dinyatakan sah, dan
            // sebelum soalnya disimpan: soal yang tersimpan tidak boleh menunjuk
            // gambar yang gagal ditulis.
            $writtenImages = $extractor->flushImages($this->referencedImages($triage['accepted']));

            $moduleId  = $moduleId === 'new' ? $this->resolveNewModule($newModuleName) : (int) $moduleId;
            $subjectId = $this->resolveSubject($moduleId, $subjectName);

            $insertedCount = $this->insertQuestions($subjectId, $triage['accepted']);

            $db->transCommit();
        } catch (WordImportException $e) {
            $db->transRollback();
            $this->discardImages($writtenImages);
            return $this->failure($e->getMessage());
        } catch (\Throwable $e) {
            $db->transRollback();
            $this->discardImages($writtenImages);
            log_message('critical', 'WordImportController::process gagal menyimpan: {exception}', ['exception' => $e]);
            return $this->failure('Terjadi kesalahan saat menyimpan ke database. Tidak ada data yang tersimpan.');
        }

        return $this->summary($subjectName, $insertedCount, $triage);
    }

    /**
     * Ringkasan hasil impor untuk modal: berapa yang masuk, kembar, dan ditolak,
     * lengkap dengan daftarnya.
     *
     * @param array{duplicates: array, rejected: array} $triage
     */
    private function summary(string $subjectName, int $inserted, array $triage)
    {
        $duplicates = $triage['duplicates'];
        $rejected = $triage['rejected'];

        return $this->response->setJSON([
            'status'  => 'success',
            'subject' => $subjectName,
            'summary' => [
                'total'    => $inserted + count($duplicates) + count($rejected),
                'masuk'    => $inserted,
                'duplikat' => count($duplicates),
                'ditolak'  => count($rejected),
            ],
            'duplicates'      => array_slice($duplicates, 0, self::MAX_REPORTED_ROWS),
            'rejected'        => array_slice($rejected, 0, self::MAX_REPORTED_ROWS),
            'duplicates_more' => max(0, count($duplicates) - self::MAX_REPORTED_ROWS),
            'rejected_more'   => max(0, count($rejected) - self::MAX_REPORTED_ROWS),
        ]);
    }

    /**
     * Id subjek yang akan diisi, kalau subjeknya memang sudah ada. Termasuk saat
     * guru memilih "buat modul baru" tapi nama modulnya ternyata sudah dipakai:
     * impornya mendarat di modul yang sama, jadi soal kembarnya harus tetap
     * ketahuan.
     *
     * Modul atau subjek yang sudah dihapus sengaja tidak dihitung -- soal di
     * dalamnya ikut terhapus dan tidak kelihatan lagi oleh guru, jadi
     * mengunggahnya ulang bukan penggandaan.
     */
    private function findTargetSubjectId($moduleId, string $newModuleName, string $subjectName): ?int
    {
        if ($moduleId === 'new') {
            $module = $this->moduleModel->where('name', $newModuleName)->first();
            if ($module === null) {
                return null;
            }
            $moduleId = (int) $module->id;
        }

        $subject = $this->subjectModel->where('module_id', (int) $moduleId)->where('name', $subjectName)->first();

        return $subject === null ? null : (int) $subject->id;
    }

    /**
     * Teks soal yang sudah ada di subjek tujuan, untuk pembanding kembar.
     *
     * @return string[]
     */
    private function existingQuestionTexts(?int $subjectId): array
    {
        if ($subjectId === null) {
            return [];
        }

        return array_column(
            $this->questionModel->select('description')->where('subject_id', $subjectId)->findAll(),
            'description'
        );
    }

    /**
     * Memeriksa modul/subjek tujuan tanpa menulis apa pun ke database.
     *
     * @return string[]
     */
    private function validateTarget($moduleId, string $newModuleName, string $subjectName): array
    {
        if (mb_strlen($subjectName) > self::MAX_NAME_LENGTH) {
            return ['Nama subjek maksimal ' . self::MAX_NAME_LENGTH . ' karakter.'];
        }

        if ($moduleId === 'new') {
            if ($newModuleName === '') {
                return ['Nama modul baru harus diisi.'];
            }
            if (mb_strlen($newModuleName) > self::MAX_NAME_LENGTH) {
                return ['Nama modul baru maksimal ' . self::MAX_NAME_LENGTH . ' karakter.'];
            }
            return [];
        }

        if (!is_string($moduleId) || !ctype_digit($moduleId) || (int) $moduleId < 1) {
            return ['Modul yang dipilih tidak valid.'];
        }
        if ($this->moduleModel->find((int) $moduleId) === null) {
            return ['Modul yang dipilih tidak ditemukan atau sudah dihapus.'];
        }

        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private function readQuestions($file, WordBlockExtractor $extractor): array
    {
        $filepath = $file->getTempName();
        $phpWord = IOFactory::load($filepath);

        $blocks = $extractor->extract($phpWord, $filepath);

        return (new WordQuestionParser())->parse($blocks);
    }

    /**
     * Nama file gambar yang benar-benar diacu soal yang akan disimpan. Gambar
     * milik soal yang ditolak atau kembar tidak perlu ikut ditulis ke disk.
     *
     * @param array<int, array<string, mixed>> $questions
     *
     * @return string[]
     */
    private function referencedImages(array $questions): array
    {
        $names = [];
        foreach ($questions as $q) {
            $html = $q['question'] . ' ' . implode(' ', $q['options'] ?? []);
            if (preg_match_all('/src="[^"]*\/([^\/"]+)"/', $html, $matches)) {
                foreach ($matches[1] as $name) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Membuang gambar yang terlanjur ditulis waktu impornya batal, supaya
     * folder upload tidak menyimpan file yang tidak diacu soal mana pun.
     *
     * @param string[] $paths
     */
    private function discardImages(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function resolveNewModule(string $name): int
    {
        $existing = $this->moduleModel->withDeleted()->where('name', $name)->first();
        if ($existing) {
            if ($existing->deleted_at !== null) {
                $this->moduleModel->reuseDeletedModule($existing->id, [
                    'user_id'    => session('user_id'),
                    'is_enabled' => 1
                ]);
            }
            return (int) $existing->id;
        }

        $id = $this->moduleModel->insert([
            'name'       => $name,
            'is_enabled' => 1,
            'user_id'    => session('user_id')
        ]);

        if ($id === false) {
            throw new WordImportException('Gagal membuat modul baru: ' . implode(', ', $this->moduleModel->errors()));
        }

        return (int) $id;
    }

    private function resolveSubject(int $moduleId, string $name): int
    {
        $existing = $this->subjectModel->withDeleted()->where('module_id', $moduleId)->where('name', $name)->first();
        if ($existing) {
            if ($existing->deleted_at !== null) {
                // Restore soft-deleted subject
                $this->subjectModel->reuseDeletedSubject($existing->id, [
                    'user_id'    => session('user_id'),
                    'is_enabled' => 1
                ]);
            }
            return (int) $existing->id;
        }

        $id = $this->subjectModel->insert([
            'module_id'  => $moduleId,
            'name'       => $name,
            'is_enabled' => 1,
            'user_id'    => session('user_id')
        ]);

        if ($id === false) {
            throw new WordImportException('Gagal membuat subjek: ' . implode(', ', $this->subjectModel->errors()));
        }

        return (int) $id;
    }

    /**
     * Menyimpan soal per rombongan BATCH_SIZE: satu statement untuk soalnya,
     * satu statement untuk seluruh jawabannya. Dokumen berisi ratusan soal jadi
     * beberapa statement, bukan ratusan.
     *
     * @param array<int, array<string, mixed>> $questions
     */
    private function insertQuestions(int $subjectId, array $questions): int
    {
        $insertedCount = 0;
        foreach (array_chunk($questions, self::BATCH_SIZE) as $chunk) {
            $insertedCount += $this->insertChunk($subjectId, $chunk);
        }

        return $insertedCount;
    }

    /** @param array<int, array<string, mixed>> $chunk */
    private function insertChunk(int $subjectId, array $chunk): int
    {
        $db = \Config\Database::connect();

        $rows = [];
        foreach ($chunk as $q) {
            $rows[] = [
                'subject_id'  => $subjectId,
                'type'        => $q['type'],
                'answer_mode' => $q['answer_mode'] ?? 'exact',
                'description' => $q['question'],
                'difficulty'  => 1,
                'is_enabled'  => 1
            ];
        }

        // Batas atas id sebelum insert: id auto-increment selalu menaik, jadi
        // semua baris hasil rombongan ini pasti berada di atasnya. Baris dari
        // transaksi lain yang belum commit tidak terlihat dari sini.
        $maxIdBefore = (int) ($db->table('questions')->selectMax('id')->get()->getRow()->id ?? 0);

        if ($this->questionModel->insertBatch($rows, null, self::BATCH_SIZE) === false) {
            throw new WordImportException(
                'Gagal menyimpan soal ke database: ' . implode(', ', $this->questionModel->errors())
            );
        }

        // insertBatch tidak mengembalikan id per baris, jadi soal yang baru masuk
        // dipetakan lagi lewat teksnya. Aman karena soal berteks kembar sudah
        // disingkirkan lebih dulu oleh triase.
        $idByDescription = [];
        $newRows = $db->table('questions')
            ->select('id, description')
            ->where('subject_id', $subjectId)
            ->where('id >', $maxIdBefore)
            ->get()
            ->getResult();
        foreach ($newRows as $row) {
            $idByDescription[$row->description] = (int) $row->id;
        }

        $answers = [];
        foreach ($chunk as $q) {
            $questionId = $idByDescription[$q['question']] ?? null;
            if ($questionId === null) {
                throw new WordImportException(
                    'Soal yang baru disimpan tidak bisa dicocokkan dengan jawabannya. Impor dibatalkan.'
                );
            }
            foreach ($this->answerRows($questionId, $q) as $answer) {
                $answers[] = $answer;
            }
        }

        if ($answers !== [] && $this->answerModel->skipValidation(true)->insertBatch($answers, null, self::BATCH_SIZE) === false) {
            throw new WordImportException('Gagal menyimpan pilihan jawaban ke database.');
        }

        return count($chunk);
    }

    /**
     * Baris tabel answers untuk satu soal, sesuai tipenya.
     *
     * @param array<string, mixed> $q
     *
     * @return array<int, array<string, mixed>>
     */
    private function answerRows(int $questionId, array $q): array
    {
        $rows = [];
        $position = 1;

        if ($q['type'] == 1 || $q['type'] == 2) {
            foreach ($q['options'] as $letter => $text) {
                $rows[] = [
                    'question_id' => $questionId,
                    'description' => $text,
                    'is_correct'  => in_array($letter, $q['correct'], true) ? 1 : 0,
                    'is_enabled'  => 1,
                    'position'    => $position
                ];
                $position++;
            }
        } elseif ($q['type'] == 3) {
            $rows[] = [
                'question_id' => $questionId,
                'description' => $q['answer_key'],
                'is_correct'  => 1,
                'is_enabled'  => 1,
                'position'    => 1
            ];
        } elseif ($q['type'] == 4 || $q['type'] == 5) {
            foreach ($q['matches'] as $pair) {
                $rows[] = [
                    'question_id' => $questionId,
                    'description' => $pair['left'] . '|::|' . $pair['right'],
                    'is_correct'  => 1,
                    'is_enabled'  => 1,
                    'position'    => $position
                ];
                $position++;
            }
        }

        return $rows;
    }

    /** @param string[] $errors */
    private function validationError(array $errors)
    {
        return $this->response->setJSON([
            'status' => 'validation_error',
            'errors' => array_values($errors)
        ]);
    }

    private function failure(string $message)
    {
        return $this->response->setJSON([
            'status'  => 'error',
            'message' => $message
        ]);
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

        // Ditulis ke buffer, bukan ke tempnam(): file sementaranya tidak pernah
        // dihapus dan menumpuk di direktori temp setiap kali template diunduh.
        // PhpWord tetap memakai temp file internal untuk zip-nya, tapi yang itu
        // dibersihkan sendiri olehnya.
        ob_start();
        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        } catch (\Throwable $e) {
            ob_end_clean();
            log_message('critical', 'WordImportController::downloadTemplate gagal: {exception}', ['exception' => $e]);
            return redirect()->back()->with('error', 'Gagal membuat file template. Silakan coba lagi.');
        }
        $content = (string) ob_get_clean();

        // download() mengembalikan null kalau datanya string kosong, dan
        // controller yang balas null bikin browser menerima halaman kosong
        // tanpa penjelasan apa pun.
        if ($content === '') {
            log_message('critical', 'WordImportController::downloadTemplate menghasilkan dokumen kosong.');
            return redirect()->back()->with('error', 'Gagal membuat file template. Silakan coba lagi.');
        }

        return $this->response->download($fileName, $content);
    }
}

<?php
namespace App\Libraries\Import;

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

final class WordImporter
{
    private int $actorId;
    private array $insertedIds = [];
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

    public function process(array $job, callable $checkpoint): array
    {
        $db = \Config\Database::connect();
        $db->resetTransStatus();
        // Written BEFORE commit: a replay can distinguish rollback from a committed
        // import even for image-only questions, without changing normal triage/schema.
        $receipt = $job['progress']['_word_receipt'] ?? null;
        if ($receipt) {
            $found = 0;
            foreach (array_chunk($receipt['ids'], 100) as $ids) $found += $db->table('questions')->whereIn('id', $ids)->countAllResults();
            if ($found === count($receipt['ids'])) return $receipt['result'];
            if ($found !== 0) throw new ImportFailure('Hasil impor sebelumnya telah berubah. Periksa bank soal sebelum mengunggah ulang.');
            $this->discardImages($receipt['images']);
        }
        $this->insertedIds = [];
        $this->actorId = (int) $job['owner'];
        $moduleId = (string) ($job['options']['module_id'] ?? '');
        $newModuleName = trim((string) ($job['options']['new_module_name'] ?? ''));
        $subjectName = trim((string) ($job['options']['subject_name'] ?? ''));
        if ($subjectName === '') return $this->validationError(['Nama subjek wajib diisi.']);
        $errors = $this->validateTarget($moduleId, $newModuleName, $subjectName);
        if ($errors !== []) return $this->validationError($errors);
        ImportUpload::checkArchive($job['source']);
        $checkpoint(['phase' => 'parsing', 'processed' => 0]);
        $extractor = new WordBlockExtractor();
        try {
            $parsed = $this->readQuestions($job['source'], $extractor);
        } catch (\Throwable $error) {
            throw new ImportFailure('File Word tidak bisa dibaca. Periksa format dan isi dokumen.');
        }
        if ($parsed === []) return $this->validationError((new WordImportValidator())->validate([]));
        $triage = (new WordImportTriage())->classify($parsed, $this->existingQuestionTexts($this->findTargetSubjectId($moduleId, $newModuleName, $subjectName)));
        if ($triage['accepted'] === []) return $this->summary($subjectName, 0, $triage);
        $checkpoint(['phase' => 'saving', 'processed' => 0, 'total' => count($parsed)]);
        $writtenImages = [];
        $started = false;
        $committing = false;
        try {
            $writtenImages = $extractor->flushImages($this->referencedImages($triage['accepted']));
            $db->transException(true);
            if (!$db->transBegin()) throw new ImportFailure('Database sementara tidak tersedia.', true);
            $started = true;
            $moduleId = $moduleId === 'new' ? $this->resolveNewModule($newModuleName) : (int) $moduleId;
            $subjectId = $this->resolveSubject($moduleId, $subjectName);
            $inserted = $this->insertQuestions($subjectId, $triage['accepted']);
            $receipt = ['ids' => $this->insertedIds, 'images' => $writtenImages, 'result' => $this->summary($subjectName, $inserted, $triage)];
            $checkpoint(['phase' => 'committing', 'processed' => 0, 'total' => count($parsed), '_word_receipt' => $receipt]);
            $committing = true;
            if (!$db->transStatus() || !$db->transCommit()) throw new ImportFailure('Transaksi soal belum berhasil disimpan.', true);
            $started = false;
        } catch (\Throwable $error) {
            if ($started) { try { $db->transRollback(); } catch (\Throwable $ignored) {} }
            if (!$committing) $this->discardImages($writtenImages);
            if ($error instanceof WordImportException) return $this->failure($error->getMessage());
            throw $error;
        }
        $checkpoint(['phase' => 'completed', 'processed' => count($parsed), 'total' => count($parsed), '_word_receipt' => $receipt]);
        return $this->summary($subjectName, $inserted, $triage);
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

        return [
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
        ];
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
    private function readQuestions(string $filepath, WordBlockExtractor $extractor): array
    {
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
                    'user_id'    => $this->actorId,
                    'is_enabled' => 1
                ]);
            }
            return (int) $existing->id;
        }

        $id = $this->moduleModel->insert([
            'name'       => $name,
            'is_enabled' => 1,
            'user_id'    => $this->actorId
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
                    'user_id'    => $this->actorId,
                    'is_enabled' => 1
                ]);
            }
            return (int) $existing->id;
        }

        $id = $this->subjectModel->insert([
            'module_id'  => $moduleId,
            'name'       => $name,
            'is_enabled' => 1,
            'user_id'    => $this->actorId
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
            $this->insertedIds[] = $questionId;
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
        return [
            'status' => 'validation_error',
            'errors' => array_values($errors)
        ];
    }

    private function failure(string $message)
    {
        return [
            'status'  => 'error',
            'message' => $message
        ];
    }

}

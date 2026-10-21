<?php
namespace Tests\ImportIntegration;

use App\Libraries\Import\StudentImporter;
use App\Libraries\Import\WordImporter;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

/** Existing dev schema only; every fixture and import write is rolled back. */
final class ImportProcessorTest extends CIUnitTestCase
{
    private int $owner;
    private int $group;
    private string $prefix;
    private string $originalGroup;
    private array $originalConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGroup = config('Database')->defaultGroup;
        config('Database')->defaultGroup = 'default';
        $this->db = \Config\Database::connect('default');
        $this->db->resetTransStatus();
        $connections = new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->originalConnections = $connections->getValue();
        // CI forces null-group model connections to "tests" in ENVIRONMENT=testing.
        // Alias that name to the same transaction; never create/migrate a schema.
        $connections->setValue(null, array_merge($this->originalConnections, ['tests' => $this->db]));
        $this->db->transBegin();
        $this->prefix = 'import_test_' . bin2hex(random_bytes(6));
        $this->db->table('users')->insert(['username' => $this->prefix, 'password' => password_hash('test-only-password', PASSWORD_BCRYPT, ['cost' => 4]), 'firstname' => 'Fixture', 'role' => 'admin', 'is_active' => 1]);
        $this->owner = (int) $this->db->insertID();
        $this->db->table('groups')->insert(['name' => $this->prefix, 'is_active' => 1]);
        $this->group = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $this->db->transRollback();
        $this->db->transException(false);
        $this->db->resetTransStatus();
        $this->assertSame(0, $this->db->table('users')->like('username', $this->prefix, 'after')->countAllResults(), 'Fixture user escaped rollback');
        $this->assertSame(0, $this->db->table('groups')->where('name', $this->prefix)->countAllResults(), 'Fixture group escaped rollback');
        $this->assertSame(0, $this->db->table('modules')->where('name', $this->prefix)->countAllResults(), 'Fixture module escaped rollback');
        config('Database')->defaultGroup = $this->originalGroup;
        (new \ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->originalConnections);
        parent::tearDown();
    }

    public function testStudentWriterKeepsHashingMembershipAndDuplicateSemantics(): void
    {
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet->getActiveSheet()->fromArray([['Username'], [$this->prefix . '_student', 'a-long-password', 'Siswa']]);
        $path = tempnam(sys_get_temp_dir(), 'cbt-students-') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);
        $job = ['source' => $path, 'options' => ['group_id' => $this->group], 'progress' => []];
        try {
            $importer = new StudentImporter(null, 0);
            $result = $importer->process($job, static function () {});
            $this->assertSame(1, $result['success']);
            $user = $this->db->table('users')->where('username', $this->prefix . '_student')->get()->getRow();
            $this->assertTrue(password_verify('a-long-password', $user->password));
            $this->assertSame(1, $this->db->table('user_groups')->where('user_id', $user->id)->where('group_id', $this->group)->countAllResults());
            $again = $importer->process($job, static function () {});
            $this->assertSame(1, $again['duplicates']);
            $this->assertSame(1, $this->db->table('users')->where('username', $user->username)->countAllResults());
        } finally { unlink($path); unlink(substr($path, 0, -5)); $sheet->disconnectWorksheets(); }
    }

    public function testWordWorkerKeepsTriageSummaryAndDoesNotDuplicateOnReplay(): void
    {
        $word = new PhpWord();
        $section = $word->addSection();
        foreach (['1. ' . $this->prefix . ' soal?', '*A. Benar', 'B. Salah'] as $line) $section->addText($line);
        $path = tempnam(sys_get_temp_dir(), 'cbt-word-') . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $job = ['source' => $path, 'owner' => $this->owner, 'options' => ['module_id' => 'new', 'new_module_name' => $this->prefix, 'subject_name' => $this->prefix], 'progress' => []];
        try {
            $importer = new WordImporter();
            $first = $importer->process($job, static function () {});
            $this->assertSame('success', $first['status']);
            $this->assertSame(1, $first['summary']['masuk']);
            $second = $importer->process($job, static function () {});
            $this->assertSame(0, $second['summary']['masuk']);
            $this->assertSame(1, $second['summary']['duplikat']);
            $module = $this->db->table('modules')->where('name', $this->prefix)->get()->getRow();
            $this->assertSame($this->owner, (int) $module->user_id);
        } finally { unlink($path); unlink(substr($path, 0, -5)); }
    }

    public function testFailedTransactionDoesNotPoisonLaterStudentImport(): void
    {
        // Independent transaction after a DB error, on the same persistent connection.
        $this->db->transException(true);
        // CI's debug error handler rolls back ALL nesting levels. Disable that
        // handler for this deliberate constraint error so fixture rollback stays intact.
        $debug = new \ReflectionProperty(\CodeIgniter\Database\BaseConnection::class, 'DBDebug');
        $originalDebug = $debug->getValue($this->db);
        $debug->setValue($this->db, false);
        try { $this->assertFalse($this->db->table('user_groups')->insert(['user_id' => -999, 'group_id' => $this->group])); }
        finally { $debug->setValue($this->db, $originalDebug); }
        $this->assertFalse($this->db->transStatus());
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet->getActiveSheet()->fromArray([['Username'], [$this->prefix . '_after_error', 'a-long-password', 'Siswa']]);
        $path = tempnam(sys_get_temp_dir(), 'cbt-recover-') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);
        try {
            $result = (new StudentImporter(null, 0))->process(['source' => $path, 'options' => [], 'progress' => []], static function () {});
            $this->assertSame(1, $result['success']);
        } finally { unlink($path); unlink(substr($path, 0, -5)); $sheet->disconnectWorksheets(); }
    }

    public function testWordCommitReceiptPreventsReplayOfImageOnlyQuestions(): void
    {
        $image = tempnam(sys_get_temp_dir(), 'cbt-image-') . '.png';
        file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $word = new PhpWord();
        $section = $word->addSection();
        $run = $section->addTextRun(); $run->addText('1. '); $run->addImage($image);
        $section->addText('*A. Benar'); $section->addText('B. Salah');
        $path = tempnam(sys_get_temp_dir(), 'cbt-image-word-') . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $job = ['source' => $path, 'owner' => $this->owner, 'options' => ['module_id' => 'new', 'new_module_name' => $this->prefix, 'subject_name' => $this->prefix], 'progress' => []];
        $receipt = []; $images = [];
        try {
            $first = (new WordImporter())->process($job, function ($progress) use (&$receipt, &$images) {
                if (isset($progress['_word_receipt'])) { $receipt = $progress; $images = $progress['_word_receipt']['images']; }
            });
            $this->assertSame(1, $first['summary']['masuk']);
            $this->assertNotEmpty($receipt, 'Receipt must be durable before committing questions');
            $job['progress'] = $receipt;
            $second = (new WordImporter())->process($job, static function () {});
            $this->assertSame($first, $second);
            $subject = $this->db->table('subjects')->where('name', $this->prefix)->get()->getRow();
            $this->assertSame(1, $this->db->table('questions')->where('subject_id', $subject->id)->countAllResults());
        } finally {
            foreach ($images as $written) if (is_file($written)) unlink($written);
            unlink($path); unlink(substr($path, 0, -5)); unlink($image); unlink(substr($image, 0, -4));
        }
    }
}

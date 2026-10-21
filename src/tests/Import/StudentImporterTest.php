<?php
namespace Tests\Import;

use App\Libraries\Import\StudentImporter;
use App\Libraries\Import\ImportFailure;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

final class StudentImporterTest extends TestCase
{
    public function testRowsAreParsedInWorkerAndCheckpointSkipsCommittedRows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cbt-import-') . '.xlsx';
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([
            ['Username', 'Password', 'Nama Depan', 'Nama Belakang', 'Email'],
            ['student001', 'password-one', 'Satu', '', ''],
            ['student002', 'password-two', 'Dua', 'Belakang', 'dua@example.test'],
            ['', '', '', '', ''],
            ['invalid', '', 'Tidak', '', ''],
        ]);
        (new Xlsx($sheet))->save($path);
        $written = [];
        $processor = new StudentImporter(function ($row, $group) use (&$written): string {
            $written[] = [$row, $group];
            return 'success';
        }, 0);
        $progress = [];
        try {
            $result = $processor->process([
                'source' => $path, 'options' => ['group_id' => 12],
                'progress' => ['cursor' => 2, 'processed' => 1, 'success' => 1, 'duplicates' => 0, 'failed' => 0],
            ], function ($value) use (&$progress) { $progress = $value; });
            $this->assertCount(1, $written);
            $this->assertSame('student002', $written[0][0]['username']);
            $this->assertSame(12, $written[0][1]);
            $this->assertSame(2, $result['success']);
            $this->assertSame(1, $result['failed']);
            $this->assertSame(5, $progress['cursor']);
            $this->assertSame($progress['total'], $progress['processed']);
        } finally { unlink($path); unlink(substr($path, 0, -5)); $sheet->disconnectWorksheets(); }
    }

    public function testTemporaryDatabaseFailurePreservesLastCheckpointForReplay(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cbt-import-') . '.xlsx';
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->fromArray([['Username'], ['student001', 'password-one', 'Satu'], ['student002', 'password-two', 'Dua']]);
        (new Xlsx($sheet))->save($path);
        $progress = [];
        $importer = new StudentImporter(function ($row): string {
            if ($row['username'] === 'student002') throw new ImportFailure('Database unavailable', true);
            return 'success';
        }, 0);
        try {
            $importer->process(['source' => $path, 'options' => [], 'progress' => []], function ($value) use (&$progress) { $progress = $value; });
            $this->fail('Transient failure must be retried by the worker');
        } catch (ImportFailure $error) {
            $this->assertTrue($error->retryable);
            $this->assertSame(2, $progress['cursor']);
            $this->assertSame(1, $progress['success']);
        } finally { unlink($path); unlink(substr($path, 0, -5)); $sheet->disconnectWorksheets(); }
    }

    public function testFormattedAndFormulaGeneratedStudentIdentifiersKeepTheirPreviousMeaning(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cbt-format-') . '.xlsx';
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Username'], ['=TEXT(F2,"0000")', 'password-one', 'Formula', '', '', 7], [9, 'password-two', 'Format']]);
        $sheet->getStyle('A3')->getNumberFormat()->setFormatCode('0000');
        (new Xlsx($book))->save($path);
        $names = [];
        try {
            (new StudentImporter(function ($row) use (&$names): string { $names[] = $row['username']; return 'success'; }, 0))
                ->process(['source' => $path, 'options' => [], 'progress' => []], static function () {});
            $this->assertSame(['0007', '0009'], $names);
        } finally { unlink($path); unlink(substr($path, 0, -5)); $book->disconnectWorksheets(); }
    }
}

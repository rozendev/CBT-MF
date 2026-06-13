<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\GroupModel;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ReportController extends BaseController
{
    protected $userModel;
    protected $groupModel;
    protected $testModel;
    protected $attemptModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->groupModel = new GroupModel();
        $this->testModel = new TestModel();
        $this->attemptModel = new TestAttemptModel();
    }

    public function index()
    {
        $students = $this->userModel->where('role', 'siswa')->orderBy('firstname', 'ASC')->findAll();
        $groups = $this->groupModel->orderBy('name', 'ASC')->findAll();

        $db = \Config\Database::connect();
        $tests = $db->query("
            SELECT t.id, t.name, COUNT(ta.id) as attempt_count
            FROM tests t
            LEFT JOIN test_attempts ta ON ta.test_id = t.id AND ta.status = 3
            WHERE t.deleted_at IS NULL
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ")->getResult();

        return view('admin/reports/index', [
            'students' => $students,
            'groups' => $groups,
            'tests' => $tests,
        ]);
    }

    public function export()
    {
        $type = $this->request->getPost('report_type');

        return match ($type) {
            'student' => $this->exportStudentReport(),
            'group' => $this->exportGroupReport(),
            'test' => $this->exportTestReport(),
            'test_detail' => $this->exportTestDetailReport(),
            default => redirect()->back()->with('error', 'Jenis laporan tidak valid.'),
        };
    }

    // ═══════════════════════════════════════════
    // Shared Styles
    // ═══════════════════════════════════════════

    private function headerStyle(): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4318FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }

    private function statHeaderStyle(): array
    {
        return [
            'font' => ['bold' => true, 'color' => ['argb' => 'FF4318FF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8DEFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
    }

    private function applyBorders(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function autoSizeColumns(Worksheet $sheet, int $fromCol, int $toCol): void
    {
        for ($i = $fromCol; $i <= $toCol; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
    }

    private function applyPassFailConditional(Worksheet $sheet, string $range, float $passingScore): void
    {
        $passCondition = new Conditional();
        $passCondition->setConditionType(Conditional::CONDITION_CELLIS);
        $passCondition->setOperatorType(Conditional::OPERATOR_GREATERTHANOREQUAL);
        $passCondition->addCondition($passingScore);
        $passCondition->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC6EFCE');
        $passCondition->getStyle()->getFont()->getColor()->setARGB('FF006100');

        $failCondition = new Conditional();
        $failCondition->setConditionType(Conditional::CONDITION_CELLIS);
        $failCondition->setOperatorType(Conditional::OPERATOR_LESSTHAN);
        $failCondition->addCondition($passingScore);
        $failCondition->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
        $failCondition->getStyle()->getFont()->getColor()->setARGB('FF9C0006');

        $sheet->getStyle($range)->setConditionalStyles([$passCondition, $failCondition]);
    }

    // ═══════════════════════════════════════════
    // 1. Student Report (Enhanced)
    // ═══════════════════════════════════════════

    private function exportStudentReport()
    {
        $userId = $this->request->getPost('user_id');
        if (!$userId) return redirect()->back()->with('error', 'Pilih siswa terlebih dahulu.');

        $student = $this->userModel->find($userId);
        if (!$student) return redirect()->back()->with('error', 'Siswa tidak ditemukan.');

        $db = \Config\Database::connect();

        $sql = "
            SELECT ta.*, t.name as test_name, t.max_score as test_max_score, t.passing_score
            FROM test_attempts ta
            JOIN tests t ON t.id = ta.test_id
            WHERE ta.user_id = ? AND ta.status = 3
            ORDER BY ta.finished_at DESC
        ";
        $attempts = $db->query($sql, [$userId])->getResult();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Siswa');

        $sheet->setCellValue('A1', 'LAPORAN HASIL UJIAN SISWA');
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Nama Siswa:');
        $sheet->setCellValue('B3', $student->firstname . ' ' . $student->lastname);
        $sheet->setCellValue('A4', 'NIS / Username:');
        $sheet->setCellValue('B4', $student->username);

        $headers = ['No', 'Nama Ujian', 'Waktu Mulai', 'Waktu Selesai', 'Nilai', 'Skala Nilai', 'Status'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '6', $header);
        }
        $sheet->getStyle('A6:G6')->applyFromArray($this->headerStyle());

        $row = 7;
        $no = 1;
        foreach ($attempts as $attempt) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, $attempt->test_name);
            $sheet->setCellValue('C' . $row, $attempt->started_at);
            $sheet->setCellValue('D' . $row, $attempt->finished_at);
            $sheet->setCellValue('E' . $row, $attempt->score);
            $sheet->setCellValue('F' . $row, $attempt->test_max_score);
            $passed = $attempt->score >= $attempt->passing_score;
            $sheet->setCellValue('G' . $row, $passed ? 'LULUS' : 'TIDAK LULUS');

            if ($passed) {
                $sheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC6EFCE');
                $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FF006100');
            } else {
                $sheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
                $sheet->getStyle('G' . $row)->getFont()->getColor()->setARGB('FF9C0006');
            }

            $this->applyBorders($sheet, 'A' . $row . ':G' . $row);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        if (count($attempts) > 0) {
            $this->applyPassFailConditional($sheet, 'E7:E' . ($row - 1), 0);
        }

        $this->autoSizeColumns($sheet, 1, 7);

        // Statistics Sheet
        if (count($attempts) > 0) {
            $this->buildStudentStatsSheet($spreadsheet, $attempts);
        }

        $filename = 'Laporan_Siswa_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $student->username) . '_' . date('Ymd_His') . '.xlsx';
        $this->downloadSpreadsheet($spreadsheet, $filename);
        exit;
    }

    private function buildStudentStatsSheet(Spreadsheet $spreadsheet, array $attempts): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Statistik');

        $sheet->setCellValue('A1', 'STATISTIK SISWA');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:B1');

        $dataRow = count($attempts) + 6;
        $lastDataRow = $dataRow;

        $stats = [
            'Total Ujian Diikuti' => count($attempts),
            'Rata-rata Nilai' => "=AVERAGE('Laporan Siswa'!E7:E{$lastDataRow})",
            'Nilai Tertinggi' => "=MAX('Laporan Siswa'!E7:E{$lastDataRow})",
            'Nilai Terendah' => "=MIN('Laporan Siswa'!E7:E{$lastDataRow})",
            'Standar Deviasi' => "=STDEV('Laporan Siswa'!E7:E{$lastDataRow})",
            'Jumlah Lulus' => "=COUNTIF('Laporan Siswa'!G7:G{$lastDataRow},\"LULUS\")",
            'Jumlah Tidak Lulus' => "=COUNTIF('Laporan Siswa'!G7:G{$lastDataRow},\"TIDAK LULUS\")",
            'Persentase Kelulusan' => "=IF(COUNTA('Laporan Siswa'!G7:G{$lastDataRow})>0,COUNTIF('Laporan Siswa'!G7:G{$lastDataRow},\"LULUS\")/COUNTA('Laporan Siswa'!G7:G{$lastDataRow})*100,0)",
        ];

        $row = 3;
        foreach ($stats as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $sheet->getStyle('A' . $row)->applyFromArray($this->statHeaderStyle());
            $this->applyBorders($sheet, 'A' . $row . ':B' . $row);
            $row++;
        }

        $sheet->getStyle('B' . ($row - 1))->getNumberFormat()->setFormatCode('0.00"%"');

        $this->autoSizeColumns($sheet, 1, 2);
    }

    // ═══════════════════════════════════════════
    // 2. Group Report (Enhanced)
    // ═══════════════════════════════════════════

    private function exportGroupReport()
    {
        $groupId = $this->request->getPost('group_id');
        if (!$groupId) return redirect()->back()->with('error', 'Pilih grup terlebih dahulu.');

        $group = $this->groupModel->find($groupId);
        if (!$group) return redirect()->back()->with('error', 'Grup tidak ditemukan.');

        $db = \Config\Database::connect();

        $students = $this->userModel->getUsersInGroup($groupId);
        if (empty($students)) {
            return redirect()->back()->with('error', 'Grup ini tidak memiliki siswa.');
        }

        $studentIds = array_column($students, 'id');

        $sqlTests = "
            SELECT DISTINCT t.id, t.name, t.max_score, t.passing_score
            FROM tests t
            JOIN test_attempts ta ON ta.test_id = t.id
            WHERE ta.user_id IN ? AND ta.status = 3
            ORDER BY t.created_at ASC
        ";
        $tests = $db->query($sqlTests, [$studentIds])->getResult();

        if (empty($tests)) {
            return redirect()->back()->with('error', 'Siswa di grup ini belum pernah menyelesaikan ujian apapun.');
        }

        $sqlAttempts = "
            SELECT user_id, test_id, MAX(score) as best_score
            FROM test_attempts
            WHERE user_id IN ? AND status = 3
            GROUP BY user_id, test_id
        ";
        $attempts = $db->query($sqlAttempts, [$studentIds])->getResult();

        $scoreMap = [];
        foreach ($attempts as $att) {
            $scoreMap[$att->user_id][$att->test_id] = $att->best_score;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Nilai');

        $sheet->setCellValue('A1', 'REKAP NILAI GRUP: ' . strtoupper($group->name));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $sheet->setCellValue('A3', 'No');
        $sheet->setCellValue('B3', 'Nama Siswa');
        $sheet->setCellValue('C3', 'NIS / Username');

        $colIndex = 4;
        foreach ($tests as $test) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($colLetter . '3', $test->name);
            $colIndex++;
        }

        $totalCols = $colIndex - 1;
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);
        $sheet->getStyle('A3:' . $lastColLetter . '3')->applyFromArray($this->headerStyle());
        $sheet->mergeCells('A1:' . $lastColLetter . '1');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 4;
        $no = 1;
        foreach ($students as $student) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, trim($student->firstname . ' ' . $student->lastname));
            $sheet->setCellValueExplicit('C' . $row, $student->username, DataType::TYPE_STRING);

            $colIndex = 4;
            foreach ($tests as $test) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                $score = $scoreMap[$student->id][$test->id] ?? '-';
                $sheet->setCellValue($colLetter . $row, $score);
                $sheet->getStyle($colLetter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $colIndex++;
            }

            $this->applyBorders($sheet, 'A' . $row . ':' . $lastColLetter . $row);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $lastDataRow = $row - 1;

        // Conditional formatting per test column
        $colIndex = 4;
        foreach ($tests as $test) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            if ($lastDataRow >= 4) {
                $this->applyPassFailConditional($sheet, $colLetter . '4:' . $colLetter . $lastDataRow, (float)$test->passing_score);
            }
            $colIndex++;
        }

        // Statistics rows below data
        $statRow = $row + 1;
        $statLabels = ['Rata-rata', 'Nilai Tertinggi', 'Nilai Terendah', 'Standar Deviasi'];
        $statFormulas = ['AVERAGE', 'MAX', 'MIN', 'STDEV'];

        foreach ($statLabels as $idx => $label) {
            $sheet->setCellValue('A' . $statRow, '');
            $sheet->setCellValue('B' . $statRow, $label);
            $sheet->setCellValue('C' . $statRow, '');
            $sheet->getStyle('B' . $statRow)->applyFromArray($this->statHeaderStyle());

            $colIndex = 4;
            foreach ($tests as $test) {
                $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                $sheet->setCellValue($colLetter . $statRow, "={$statFormulas[$idx]}({$colLetter}4:{$colLetter}{$lastDataRow})");
                $sheet->getStyle($colLetter . $statRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle($colLetter . $statRow)->getFont()->setBold(true);
                $colIndex++;
            }

            $this->applyBorders($sheet, 'A' . $statRow . ':' . $lastColLetter . $statRow);
            $statRow++;
        }

        $this->autoSizeColumns($sheet, 1, $totalCols);

        // Statistics Sheet
        $this->buildGroupStatsSheet($spreadsheet, $tests, $students, $scoreMap);

        $filename = 'Laporan_Grup_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $group->name) . '_' . date('Ymd_His') . '.xlsx';
        $this->downloadSpreadsheet($spreadsheet, $filename);
        exit;
    }

    private function buildGroupStatsSheet(Spreadsheet $spreadsheet, array $tests, array $students, array $scoreMap): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Statistik');

        $sheet->setCellValue('A1', 'STATISTIK GRUP');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Ujian', 'Peserta', 'Rata-rata', 'Tertinggi', 'Terendah', 'Std Dev', 'Lulus', 'Tidak Lulus', 'Pass Rate (%)'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '3', $h);
        }
        $sheet->getStyle('A3:I3')->applyFromArray($this->headerStyle());

        $row = 4;
        foreach ($tests as $test) {
            $scores = [];
            $passCount = 0;
            $failCount = 0;

            foreach ($students as $student) {
                if (isset($scoreMap[$student->id][$test->id])) {
                    $score = $scoreMap[$student->id][$test->id];
                    $scores[] = $score;
                    if ($score >= $test->passing_score) {
                        $passCount++;
                    } else {
                        $failCount++;
                    }
                }
            }

            $sheet->setCellValue('A' . $row, $test->name);
            $sheet->setCellValue('B' . $row, count($scores));
            $sheet->setCellValue('C' . $row, count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : '-');
            $sheet->setCellValue('D' . $row, count($scores) > 0 ? max($scores) : '-');
            $sheet->setCellValue('E' . $row, count($scores) > 0 ? min($scores) : '-');
            $sheet->setCellValue('F' . $row, count($scores) > 1 ? round($this->calculateStdDev($scores), 2) : '-');
            $sheet->setCellValue('G' . $row, $passCount);
            $sheet->setCellValue('H' . $row, $failCount);
            $total = $passCount + $failCount;
            $sheet->setCellValue('I' . $row, $total > 0 ? round(($passCount / $total) * 100, 1) : 0);

            $this->applyBorders($sheet, 'A' . $row . ':I' . $row);
            foreach (range('B', 'I') as $col) {
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $row++;
        }

        $this->autoSizeColumns($sheet, 1, 9);
    }

    // ═══════════════════════════════════════════
    // 3. Test Report (NEW)
    // ═══════════════════════════════════════════

    private function exportTestReport()
    {
        $testId = $this->request->getPost('test_id');
        if (!$testId) return redirect()->back()->with('error', 'Pilih ujian terlebih dahulu.');

        $test = $this->testModel->find($testId);
        if (!$test) return redirect()->back()->with('error', 'Ujian tidak ditemukan.');

        $groupId = $this->request->getPost('group_id');
        $db = \Config\Database::connect();

        $sql = "
            SELECT ta.*, u.firstname, u.lastname, u.username, u.registration_number,
                   GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names
            FROM test_attempts ta
            JOIN users u ON u.id = ta.user_id
            LEFT JOIN user_groups ug ON ug.user_id = u.id
            LEFT JOIN groups g ON g.id = ug.group_id
            WHERE ta.test_id = ? AND ta.status = 3
        ";
        $params = [$testId];

        if ($groupId) {
            $sql .= " AND u.id IN (SELECT user_id FROM user_groups WHERE group_id = ?)";
            $params[] = $groupId;
        }

        $sql .= " GROUP BY ta.id ORDER BY ta.score DESC, ta.finished_at ASC";
        $attempts = $db->query($sql, $params)->getResult();

        if (empty($attempts)) {
            return redirect()->back()->with('error', 'Belum ada siswa yang menyelesaikan ujian ini.');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daftar Nilai');

        $sheet->setCellValue('A1', 'HASIL UJIAN: ' . strtoupper($test->name));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->mergeCells('A1:I1');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Batas Lulus:');
        $sheet->setCellValue('B3', $test->passing_score . ' / ' . $test->max_score);
        $sheet->setCellValue('D3', 'Jumlah Peserta:');
        $sheet->setCellValue('E3', count($attempts));

        $headers = ['No', 'Nama Lengkap', 'NIS / Username', 'Grup', 'Waktu Mulai', 'Waktu Selesai', 'Durasi', 'Nilai', 'Status'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '5', $h);
        }
        $sheet->getStyle('A5:I5')->applyFromArray($this->headerStyle());

        $row = 6;
        $no = 1;
        foreach ($attempts as $attempt) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, trim($attempt->firstname . ' ' . $attempt->lastname));
            $sheet->setCellValueExplicit('C' . $row, $attempt->registration_number ?: $attempt->username, DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $row, $attempt->group_names ?: '-');
            $sheet->setCellValue('E' . $row, $attempt->started_at);
            $sheet->setCellValue('F' . $row, $attempt->finished_at);

            $duration = '';
            if ($attempt->started_at && $attempt->finished_at) {
                $start = strtotime($attempt->started_at);
                $end = strtotime($attempt->finished_at);
                $diff = $end - $start;
                $hours = floor($diff / 3600);
                $mins = floor(($diff % 3600) / 60);
                $secs = $diff % 60;
                $duration = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
            }
            $sheet->setCellValue('G' . $row, $duration);
            $sheet->setCellValue('H' . $row, $attempt->score);

            $passed = $attempt->score >= $test->passing_score;
            $sheet->setCellValue('I' . $row, $passed ? 'LULUS' : 'TIDAK LULUS');

            if ($passed) {
                $sheet->getStyle('I' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC6EFCE');
                $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('FF006100');
            } else {
                $sheet->getStyle('I' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
                $sheet->getStyle('I' . $row)->getFont()->getColor()->setARGB('FF9C0006');
            }

            $this->applyBorders($sheet, 'A' . $row . ':I' . $row);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('I' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $lastDataRow = $row - 1;
        if ($lastDataRow >= 6) {
            $this->applyPassFailConditional($sheet, 'H6:H' . $lastDataRow, (float)$test->passing_score);
        }

        $this->autoSizeColumns($sheet, 1, 9);

        // Statistics Sheet
        $this->buildTestStatsSheet($spreadsheet, $test, $attempts);

        $filename = 'Hasil_Ujian_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $test->name) . '_' . date('Ymd_His') . '.xlsx';
        $this->downloadSpreadsheet($spreadsheet, $filename);
        exit;
    }

    private function buildTestStatsSheet(Spreadsheet $spreadsheet, $test, array $attempts): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Statistik');

        $sheet->setCellValue('A1', 'STATISTIK UJIAN');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:B1');

        $scores = array_map(fn($a) => (float)$a->score, $attempts);
        $passCount = count(array_filter($scores, fn($s) => $s >= $test->passing_score));
        $total = count($scores);

        $sheet->setCellValue('A3', 'Nama Ujian');
        $sheet->setCellValue('B3', $test->name);
        $sheet->setCellValue('A4', 'Batas Lulus');
        $sheet->setCellValue('B4', $test->passing_score);
        $sheet->setCellValue('A5', 'Skala Nilai Maks');
        $sheet->setCellValue('B5', $test->max_score);

        $sheet->getStyle('A3:A5')->applyFromArray($this->statHeaderStyle());

        $sheet->setCellValue('A7', 'RINGKASAN');
        $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(12);

        $stats = [
            'Jumlah Peserta' => $total,
            'Rata-rata Nilai' => "=AVERAGE('Daftar Nilai'!H6:H" . (5 + $total) . ")",
            'Median' => "=MEDIAN('Daftar Nilai'!H6:H" . (5 + $total) . ")",
            'Nilai Tertinggi' => "=MAX('Daftar Nilai'!H6:H" . (5 + $total) . ")",
            'Nilai Terendah' => "=MIN('Daftar Nilai'!H6:H" . (5 + $total) . ")",
            'Standar Deviasi' => "=STDEV('Daftar Nilai'!H6:H" . (5 + $total) . ")",
            'Jumlah Lulus' => $passCount,
            'Jumlah Tidak Lulus' => $total - $passCount,
            'Persentase Kelulusan (%)' => $total > 0 ? round(($passCount / $total) * 100, 1) : 0,
        ];

        $row = 9;
        foreach ($stats as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $sheet->getStyle('A' . $row)->applyFromArray($this->statHeaderStyle());
            $this->applyBorders($sheet, 'A' . $row . ':B' . $row);
            $row++;
        }

        // Score distribution
        $row += 2;
        $sheet->setCellValue('A' . $row, 'DISTRIBUSI NILAI');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $row++;

        $maxScore = (float)$test->max_score;
        $bucketSize = max(1, $maxScore / 10);
        $buckets = [];
        for ($i = 0; $i < 10; $i++) {
            $low = round($i * $bucketSize, 1);
            $high = round(($i + 1) * $bucketSize, 1);
            $label = "{$low} - {$high}";
            $count = 0;
            foreach ($scores as $s) {
                if ($s >= $low && ($s < $high || ($i === 9 && $s <= $high))) {
                    $count++;
                }
            }
            $buckets[] = ['range' => $label, 'count' => $count];
        }

        $sheet->setCellValue('A' . $row, 'Range Nilai');
        $sheet->setCellValue('B' . $row, 'Jumlah Siswa');
        $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($this->headerStyle());
        $row++;

        foreach ($buckets as $bucket) {
            $sheet->setCellValue('A' . $row, $bucket['range']);
            $sheet->setCellValue('B' . $row, $bucket['count']);
            $sheet->getStyle('B' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->applyBorders($sheet, 'A' . $row . ':B' . $row);
            $row++;
        }

        $this->autoSizeColumns($sheet, 1, 2);
    }

    // ═══════════════════════════════════════════
    // 4. Test Detail Report (NEW)
    // ═══════════════════════════════════════════

    private function exportTestDetailReport()
    {
        $testId = $this->request->getPost('test_id');
        if (!$testId) return redirect()->back()->with('error', 'Pilih ujian terlebih dahulu.');

        $test = $this->testModel->find($testId);
        if (!$test) return redirect()->back()->with('error', 'Ujian tidak ditemukan.');

        $db = \Config\Database::connect();

        // Get all finished attempts
        $sqlAttempts = "
            SELECT ta.id, ta.user_id, ta.score, u.firstname, u.lastname, u.username, u.registration_number
            FROM test_attempts ta
            JOIN users u ON u.id = ta.user_id
            WHERE ta.test_id = ? AND ta.status = 3
            ORDER BY ta.score DESC, ta.finished_at ASC
        ";
        $attempts = $db->query($sqlAttempts, [$testId])->getResult();

        if (empty($attempts)) {
            return redirect()->back()->with('error', 'Belum ada siswa yang menyelesaikan ujian ini.');
        }

        // Get question template from first attempt
        $firstAttemptId = $attempts[0]->id;
        $sqlQuestions = "
            SELECT tl.id, tl.question_id, tl.question_text, tl.question_type, tl.display_order, tl.question_difficulty
            FROM test_logs tl
            WHERE tl.test_attempt_id = ?
            ORDER BY tl.display_order ASC
        ";
        $questionTemplate = $db->query($sqlQuestions, [$firstAttemptId])->getResult();

        if (empty($questionTemplate)) {
            return redirect()->back()->with('error', 'Tidak ada data soal untuk ujian ini.');
        }

        $questionCount = count($questionTemplate);

        // Get all scores for all attempts
        $attemptIds = array_map(fn($a) => $a->id, $attempts);
        $sqlScores = "
            SELECT tl.test_attempt_id, tl.display_order, tl.score, tl.question_type
            FROM test_logs tl
            WHERE tl.test_attempt_id IN ?
            ORDER BY tl.test_attempt_id, tl.display_order ASC
        ";
        $allScores = $db->query($sqlScores, [$attemptIds])->getResult();

        // Map: [attempt_id][display_order] => score
        $scoreMatrix = [];
        $analysisCorrect = [];
        $analysisWrong = [];
        $analysisUnanswered = [];

        foreach ($allScores as $s) {
            $scoreMatrix[$s->test_attempt_id][$s->display_order] = (float)$s->score;

            if (!isset($analysisCorrect[$s->display_order])) {
                $analysisCorrect[$s->display_order] = 0;
                $analysisWrong[$s->display_order] = 0;
                $analysisUnanswered[$s->display_order] = 0;
            }

            if ((float)$s->score > 0) {
                $analysisCorrect[$s->display_order]++;
            } elseif ((float)$s->score < 0) {
                $analysisWrong[$s->display_order]++;
            } else {
                $analysisUnanswered[$s->display_order]++;
            }
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Detail Jawaban');

        $sheet->setCellValue('A1', 'DETAIL JAWABAN: ' . strtoupper($test->name));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        // Row 3: headers
        $sheet->setCellValue('A3', 'No');
        $sheet->setCellValue('B3', 'Nama Siswa');
        $sheet->setCellValue('C3', 'NIS / Username');

        $typeLabels = [1 => 'PG', 2 => 'PG Kompleks', 3 => 'Esai', 4 => 'Menjodohkan', 5 => 'B/S'];

        foreach ($questionTemplate as $i => $q) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 4);
            $sheet->setCellValue($colLetter . '3', 'Soal ' . ($i + 1));
        }

        $scoreColIndex = $questionCount + 4;
        $scoreColLetter = Coordinate::stringFromColumnIndex($scoreColIndex);
        $sheet->setCellValue($scoreColLetter . '3', 'Nilai Akhir');

        $lastColLetter = $scoreColLetter;
        $sheet->getStyle('A3:' . $lastColLetter . '3')->applyFromArray($this->headerStyle());
        $sheet->mergeCells('A1:' . $lastColLetter . '1');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Row 4: sub-headers (question type + difficulty)
        $sheet->setCellValue('A4', '');
        $sheet->setCellValue('B4', '');
        $sheet->setCellValue('C4', '');
        foreach ($questionTemplate as $i => $q) {
            $colLetter = Coordinate::stringFromColumnIndex($i + 4);
            $typeStr = $typeLabels[$q->question_type] ?? '?';
            $diffStr = 'Lv.' . ($q->question_difficulty ?? 0);
            $sheet->setCellValue($colLetter . '4', $typeStr . ' | ' . $diffStr);
        }
        $sheet->setCellValue($scoreColLetter . '4', 'Skala: ' . $test->max_score);
        $subHeaderStyle = [
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF666666']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF5F5F5']],
        ];
        $sheet->getStyle('A4:' . $lastColLetter . '4')->applyFromArray($subHeaderStyle);

        // Data rows
        $row = 5;
        $no = 1;
        foreach ($attempts as $attempt) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, trim($attempt->firstname . ' ' . $attempt->lastname));
            $sheet->setCellValueExplicit('C' . $row, $attempt->registration_number ?: $attempt->username, DataType::TYPE_STRING);

            foreach ($questionTemplate as $i => $q) {
                $colLetter = Coordinate::stringFromColumnIndex($i + 4);
                $score = $scoreMatrix[$attempt->id][$q->display_order] ?? null;

                if ($score !== null) {
                    $sheet->setCellValue($colLetter . $row, $score);

                    if ($score >= $test->score_right && $test->score_right > 0) {
                        $sheet->getStyle($colLetter . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC6EFCE');
                    } elseif ($score > 0) {
                        $sheet->getStyle($colLetter . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');
                    } elseif ($score < 0) {
                        $sheet->getStyle($colLetter . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
                    } else {
                        $sheet->getStyle($colLetter . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
                    }
                } else {
                    $sheet->setCellValue($colLetter . $row, '-');
                }

                $sheet->getStyle($colLetter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $sheet->setCellValue($scoreColLetter . $row, $attempt->score);
            $sheet->getStyle($scoreColLetter . $row)->getFont()->setBold(true);
            $sheet->getStyle($scoreColLetter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $this->applyBorders($sheet, 'A' . $row . ':' . $lastColLetter . $row);
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $lastDataRow = $row - 1;
        if ($lastDataRow >= 5) {
            $this->applyPassFailConditional($sheet, $scoreColLetter . '5:' . $scoreColLetter . $lastDataRow, (float)$test->passing_score);
        }

        $totalCols = Coordinate::columnIndexFromString($lastColLetter);
        $this->autoSizeColumns($sheet, 1, $totalCols);

        // Question Analysis Sheet
        $this->buildQuestionAnalysisSheet($spreadsheet, $questionTemplate, $typeLabels, $analysisCorrect, $analysisWrong, $analysisUnanswered, count($attempts), $test);

        $filename = 'Detail_Jawaban_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $test->name) . '_' . date('Ymd_His') . '.xlsx';
        $this->downloadSpreadsheet($spreadsheet, $filename);
        exit;
    }

    private function buildQuestionAnalysisSheet(
        Spreadsheet $spreadsheet,
        array $questions,
        array $typeLabels,
        array $correct,
        array $wrong,
        array $unanswered,
        int $totalStudents,
        $test
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Analisis Soal');

        $sheet->setCellValue('A1', 'ANALISIS BUTIR SOAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:H1');

        $headers = ['Soal', 'Tipe', 'Kesulitan', 'Benar', 'Salah', 'Tidak Dijawab', 'Total Peserta', 'Ketuntasan (%)'];
        foreach ($headers as $i => $h) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '3', $h);
        }
        $sheet->getStyle('A3:H3')->applyFromArray($this->headerStyle());

        $row = 4;
        foreach ($questions as $i => $q) {
            $order = $q->display_order;
            $c = $correct[$order] ?? 0;
            $w = $wrong[$order] ?? 0;
            $u = $unanswered[$order] ?? 0;
            $mastery = $totalStudents > 0 ? round(($c / $totalStudents) * 100, 1) : 0;

            $sheet->setCellValue('A' . $row, 'Soal ' . ($i + 1));
            $sheet->setCellValue('B' . $row, $typeLabels[$q->question_type] ?? '?');
            $sheet->setCellValue('C' . $row, 'Level ' . ($q->question_difficulty ?? 0));
            $sheet->setCellValue('D' . $row, $c);
            $sheet->setCellValue('E' . $row, $w);
            $sheet->setCellValue('F' . $row, $u);
            $sheet->setCellValue('G' . $row, $totalStudents);
            $sheet->setCellValue('H' . $row, $mastery);

            if ($mastery >= 75) {
                $sheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC6EFCE');
                $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF006100');
            } elseif ($mastery >= 50) {
                $sheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFEB9C');
                $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF9C6500');
            } else {
                $sheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFC7CE');
                $sheet->getStyle('H' . $row)->getFont()->getColor()->setARGB('FF9C0006');
            }

            $this->applyBorders($sheet, 'A' . $row . ':H' . $row);
            foreach (range('B', 'H') as $col) {
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $row++;
        }

        $this->autoSizeColumns($sheet, 1, 8);
    }

    // ═══════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════

    private function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count < 2) return 0;

        $mean = array_sum($values) / $count;
        $variance = 0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        return sqrt($variance / ($count - 1));
    }

    private function downloadSpreadsheet($spreadsheet, $filename)
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Cache-Control: max-age=1');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }
}

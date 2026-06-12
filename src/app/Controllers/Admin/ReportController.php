<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\GroupModel;
use App\Models\TestModel;
use App\Models\TestAttemptModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

        return view('admin/reports/index', [
            'students' => $students,
            'groups' => $groups
        ]);
    }

    public function export()
    {
        $type = $this->request->getPost('report_type');

        if ($type === 'student') {
            return $this->exportStudentReport();
        } elseif ($type === 'group') {
            return $this->exportGroupReport();
        }

        return redirect()->back()->with('error', 'Jenis laporan tidak valid.');
    }

    private function exportStudentReport()
    {
        $userId = $this->request->getPost('user_id');
        if (!$userId) return redirect()->back()->with('error', 'Pilih siswa terlebih dahulu.');

        $student = $this->userModel->find($userId);
        if (!$student) return redirect()->back()->with('error', 'Siswa tidak ditemukan.');

        $db = \Config\Database::connect();
        
        // Get all completed attempts for this student
        $sql = "
            SELECT ta.*, t.name as test_name, t.max_score as test_max_score
            FROM test_attempts ta
            JOIN tests t ON t.id = ta.test_id
            WHERE ta.user_id = ? AND ta.status = 3
            ORDER BY ta.finished_at DESC
        ";
        $attempts = $db->query($sql, [$userId])->getResult();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Siswa');

        // Header Styling
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4318FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        // Title
        $sheet->setCellValue('A1', 'LAPORAN HASIL UJIAN SISWA');
        $sheet->mergeCells('A1:F1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Nama Siswa:');
        $sheet->setCellValue('B3', $student->firstname . ' ' . $student->lastname);
        $sheet->setCellValue('A4', 'NIS / Username:');
        $sheet->setCellValue('B4', $student->username);

        // Table Header
        $headers = ['No', 'Nama Ujian', 'Waktu Mulai', 'Waktu Selesai', 'Nilai', 'Skala Nilai'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '6', $header);
            $col++;
        }
        $sheet->getStyle('A6:F6')->applyFromArray($headerStyle);

        // Data Rows
        $row = 7;
        $no = 1;
        foreach ($attempts as $attempt) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, $attempt->test_name);
            $sheet->setCellValue('C' . $row, $attempt->started_at);
            $sheet->setCellValue('D' . $row, $attempt->finished_at);
            $sheet->setCellValue('E' . $row, $attempt->score);
            $sheet->setCellValue('F' . $row, $attempt->test_max_score);
            
            $sheet->getStyle('A'.$row.':F'.$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        // Auto size columns
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Laporan_Siswa_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $student->username) . '_' . date('Ymd_His') . '.xlsx';
        $this->downloadSpreadsheet($spreadsheet, $filename);
        exit;
    }

    private function exportGroupReport()
    {
        $groupId = $this->request->getPost('group_id');
        if (!$groupId) return redirect()->back()->with('error', 'Pilih grup terlebih dahulu.');

        $group = $this->groupModel->find($groupId);
        if (!$group) return redirect()->back()->with('error', 'Grup tidak ditemukan.');

        $db = \Config\Database::connect();

        // 1. Get all students in this group
        $students = $this->userModel->getUsersInGroup($groupId);
        if (empty($students)) {
            return redirect()->back()->with('error', 'Grup ini tidak memiliki siswa.');
        }

        $studentIds = array_column($students, 'id');

        // 2. Get all distinct tests taken by students in this group
        $sqlTests = "
            SELECT DISTINCT t.id, t.name, t.max_score
            FROM tests t
            JOIN test_attempts ta ON ta.test_id = t.id
            WHERE ta.user_id IN ? AND ta.status = 3
            ORDER BY t.created_at ASC
        ";
        $tests = $db->query($sqlTests, [$studentIds])->getResult();

        if (empty($tests)) {
            return redirect()->back()->with('error', 'Siswa di grup ini belum pernah menyelesaikan ujian apapun.');
        }

        // 3. Get all attempts for these students
        $sqlAttempts = "
            SELECT user_id, test_id, MAX(score) as best_score
            FROM test_attempts
            WHERE user_id IN ? AND status = 3
            GROUP BY user_id, test_id
        ";
        $attempts = $db->query($sqlAttempts, [$studentIds])->getResult();
        
        // Map scores: [user_id][test_id] = score
        $scoreMap = [];
        foreach ($attempts as $att) {
            $scoreMap[$att->user_id][$att->test_id] = $att->best_score;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Grup');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4318FF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        // Title
        $sheet->setCellValue('A1', 'REKAP NILAI GRUP: ' . strtoupper($group->name));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        // Header Row
        $sheet->setCellValue('A3', 'No');
        $sheet->setCellValue('B3', 'Nama Siswa');
        $sheet->setCellValue('C3', 'NIS / Username');

        // Dynamically add test names as columns
        $colIndex = 4; // Column D
        foreach ($tests as $test) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($colLetter . '3', $test->name);
            $colIndex++;
        }

        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex - 1);
        $sheet->getStyle('A3:' . $lastColLetter . '3')->applyFromArray($headerStyle);
        $sheet->mergeCells('A1:' . $lastColLetter . '1');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Data Rows
        $row = 4;
        $no = 1;
        foreach ($students as $student) {
            $sheet->setCellValue('A' . $row, $no++);
            $sheet->setCellValue('B' . $row, trim($student->firstname . ' ' . $student->lastname));
            $sheet->setCellValueExplicit('C' . $row, $student->username, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $colIndex = 4;
            foreach ($tests as $test) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex);
                $score = isset($scoreMap[$student->id][$test->id]) ? $scoreMap[$student->id][$test->id] : '-';
                $sheet->setCellValue($colLetter . $row, $score);
                $sheet->getStyle($colLetter . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $colIndex++;
            }

            $sheet->getStyle('A'.$row.':'.$lastColLetter.$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        // Auto size columns
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setAutoSize(true);
        for ($i = 4; $i < $colIndex; $i++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            // Don't auto size test name columns too much if they are very long, but let's try
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $filename = 'Laporan_Grup_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $group->name) . '_' . date('Ymd_His') . '.xlsx';
        $this->downloadSpreadsheet($spreadsheet, $filename);
        exit;
    }

    private function downloadSpreadsheet($spreadsheet, $filename)
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        // If serving to IE 9, then the following may be needed
        header('Cache-Control: max-age=1');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
    }
}

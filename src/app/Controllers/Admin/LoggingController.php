<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ActivityLogModel;
use App\Models\IntruderReportModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class LoggingController extends BaseController
{
    /**
     * Halaman logging: aktivitas terkini + user online real-time
     */
    public function index()
    {
        $dateFrom = trim((string) $this->request->getGet('from'));
        $dateTo   = trim((string) $this->request->getGet('to'));
        $search   = trim((string) $this->request->getGet('search'));

        $db = \Config\Database::connect();

        // User online (last_active_at dalam 5 menit)
        $fiveMinsAgo = date('Y-m-d H:i:s', time() - 300);
        $onlineUsers = $db->table('users')
                          ->where('last_active_at >=', $fiveMinsAgo)
                          ->where('deleted_at', null)
                          ->orderBy('last_active_at', 'DESC')
                          ->limit(50)
                          ->get()
                          ->getResultArray();

        $activityModel = new ActivityLogModel();
        $activityModel->select('activity_logs.*, users.username, users.firstname, users.lastname, users.role')
                      ->join('users', 'users.id = activity_logs.user_id', 'left');

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $activityModel->where('activity_logs.created_at >=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $activityModel->where('activity_logs.created_at <=', $dateTo . ' 23:59:59');
        }
        if ($search !== '') {
            $activityModel->groupStart()
                ->like('users.firstname', $search)
                ->orLike('users.lastname', $search)
                ->orLike('users.username', $search)
                ->orLike('activity_logs.action', $search)
                ->orLike('activity_logs.description', $search)
                ->groupEnd();
        }

        $activityModel->orderBy('activity_logs.created_at', 'DESC');
        $activities = $activityModel->paginate(25, 'default');
        $pager      = $activityModel->pager;

        return view('admin/logging/index', [
            'activities'  => $activities,
            'pager'       => $pager,
            'onlineUsers' => $onlineUsers,
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
            'search'      => $search,
        ]);
    }

    /**
     * Halaman intruder: laporan honeypot 403/404 (foto + lokasi)
     */
    public function intruders()
    {
        $dateFrom = trim((string) $this->request->getGet('from'));
        $dateTo   = trim((string) $this->request->getGet('to'));
        $search   = trim((string) $this->request->getGet('search'));

        $model = new IntruderReportModel();

        // Statistik
        $todayStart = date('Y-m-d 00:00:00');
        $stats = [
            'total' => (int) $model->countAll(),
            'today' => (int) $model->where('created_at >=', $todayStart)->countAllResults(),
            'photo' => (int) $model->where('photo_path IS NOT NULL', null, false)->countAllResults(),
        ];

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $model->where('created_at >=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $model->where('created_at <=', $dateTo . ' 23:59:59');
        }
        if ($search !== '') {
            $model->groupStart()
                ->like('ip_address', $search)
                ->orLike('user_agent', $search)
                ->orLike('requested_uri', $search)
                ->orLike('platform', $search)
                ->groupEnd();
        }

        $model->orderBy('created_at', 'DESC');
        $reports = $model->paginate(25, 'default');
        $pager   = $model->pager;

        return view('admin/logging/intruders', [
            'reports'   => $reports,
            'pager'     => $pager,
            'stats'     => $stats,
            'dateFrom'  => $dateFrom,
            'dateTo'    => $dateTo,
            'search'    => $search,
        ]);
    }

    /**
     * Export aktivitas ke file .xls dengan rentang waktu & kolom pilihan
     */
    public function export()
    {
        $dateFrom = trim((string) $this->request->getPost('from'));
        $dateTo   = trim((string) $this->request->getPost('to'));
        $search   = trim((string) $this->request->getPost('search'));
        $fields   = $this->request->getPost('fields');

        $fieldDefs = [
            'waktu'     => 'Waktu',
            'user'      => 'Nama',
            'username'  => 'Username',
            'role'      => 'Role',
            'aksi'      => 'Aksi',
            'deskripsi' => 'Deskripsi',
            'entitas'   => 'Entitas',
            'ip'        => 'IP Address',
            'user_agent'=> 'User Agent',
        ];

        if (!is_array($fields) || empty($fields)) {
            $fields = array_keys($fieldDefs);
        }
        $fields = array_values(array_intersect($fields, array_keys($fieldDefs)));

        $activityModel = new ActivityLogModel();
        $activityModel->select('activity_logs.*, users.username, users.firstname, users.lastname, users.role')
                      ->join('users', 'users.id = activity_logs.user_id', 'left');

        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $activityModel->where('activity_logs.created_at >=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $activityModel->where('activity_logs.created_at <=', $dateTo . ' 23:59:59');
        }
        if ($search !== '') {
            $activityModel->groupStart()
                ->like('users.firstname', $search)
                ->orLike('users.lastname', $search)
                ->orLike('users.username', $search)
                ->orLike('activity_logs.action', $search)
                ->orLike('activity_logs.description', $search)
                ->groupEnd();
        }

        $rows = $activityModel->orderBy('activity_logs.created_at', 'DESC')
                              ->findAll();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // ── Judul & metadata ──
        $sheet->setCellValue('A1', 'LOG AKTIVITAS SISTEM');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $meta = 'Rentang: ' . ($dateFrom !== '' ? $dateFrom : 'Semua') . ' s.d. ' . ($dateTo !== '' ? $dateTo : 'Sekarang');
        if ($search !== '') {
            $meta .= ' | Pencarian: ' . $search;
        }
        $meta .= ' | Jumlah data: ' . count($rows);
        $sheet->setCellValue('A2', $meta);
        $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);

        // ── Header ──
        $headerRow = 4;
        $col = 1;
        foreach ($fields as $key) {
            $cell = Coordinate::stringFromColumnIndex($col) . $headerRow;
            $sheet->setCellValue($cell, $fieldDefs[$key]);
            $sheet->getStyle($cell)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($cell)->getFill()
                  ->setFillType(Fill::FILL_SOLID)
                  ->getStartColor()->setARGB('FF0E8A6B');
            $sheet->getStyle($cell)->getAlignment()
                  ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $col++;
        }
        $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // ── Data ──
        $row = $headerRow + 1;
        foreach ($rows as $act) {
            $col = 1;
            foreach ($fields as $key) {
                $value = match ($key) {
                    'waktu'      => $act->created_at ?? '',
                    'user'       => trim(($act->firstname ?? '') . ' ' . ($act->lastname ?? '')),
                    'username'   => $act->username ?? '',
                    'role'       => $act->role ? ucfirst($act->role) : 'Sistem',
                    'aksi'       => $act->action ?? '',
                    'deskripsi'  => $act->description ?? '',
                    'entitas'    => (!empty($act->entity_type) ? $act->entity_type . ($act->entity_id ? '#' . $act->entity_id : '') : ''),
                    'ip'         => $act->ip_address ?? '',
                    'user_agent' => $act->user_agent ?? '',
                    default      => '',
                };
                $cell = Coordinate::stringFromColumnIndex($col) . $row;
                $sheet->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $col++;
            }
            $row++;
        }

        // ── Rapi: autosize, freeze, zebra ──
        foreach ($fields as $i => $key) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
        $sheet->getStyle('A' . $headerRow . ':' . Coordinate::stringFromColumnIndex(count($fields)) . $headerRow)
              ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A' . ($headerRow + 1));

        $filename = 'log_aktivitas_' . date('Ymd_His') . '.xls';
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xls($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
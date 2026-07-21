<?php

namespace App\Controllers\Proctor;

use App\Controllers\BaseController;
use App\Models\TestModel;

class DashboardController extends BaseController
{
    protected TestModel $testModel;

    public function __construct()
    {
        $this->testModel = new TestModel();
    }

    public function index()
    {
        $session = session();
        
        $activeExams = $this->testModel->where('is_enabled', 1)->findAll();

        $data = [
            'title'       => 'Proctor Dashboard',
            'userRole'    => $session->get('role'),
            'userName'    => $session->get('firstname') . ' ' . $session->get('lastname'),
            'activeExams' => $activeExams
        ];

        return view('proctor/dashboard', $data);
    }
}

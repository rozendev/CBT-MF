<?php

namespace App\Controllers\Exam;

use App\Controllers\BaseController;

class ExamController extends BaseController
{
    public function index()
    {
        // TODO: Phase 3 — Fetch available tests for current user
        $tests = [];

        return view('exam/index', [
            'tests' => $tests,
        ]);
    }
}

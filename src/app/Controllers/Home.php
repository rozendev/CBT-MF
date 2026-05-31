<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        if (session()->get('logged_in')) {
            $role = session()->get('role');
            return match ($role) {
                'admin', 'guru' => redirect()->to('/admin/dashboard'),
                default         => redirect()->to('/exam'),
            };
        }

        return redirect()->to('/login');
    }
}

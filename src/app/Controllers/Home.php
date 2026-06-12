<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        if (session()->get('logged_in')) {
            if (session()->get('role') === 'admin') {
                return redirect()->to(base_url('/admin'));
            } else {
                return redirect()->to(base_url('/student'));
            }
        }
        
        return redirect()->to(base_url('login'));
    }
}

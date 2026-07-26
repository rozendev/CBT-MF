<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class LanguageController extends BaseController
{
    public function switchLanguage($locale)
    {
        $supportedLocales = config('App')->supportedLocales;
        if (in_array($locale, $supportedLocales)) {
            session()->set('lang', $locale);
        }
        return redirect()->back();
    }
}

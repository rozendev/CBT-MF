<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Controllers\Admin\StaticExamController;

class RegenStaticTemp extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:regen-static-temp';
    protected $description = 'Temporary: regenerate static exam page for a test id via console';

    public function run(array $params)
    {
        $testId = (int) CLI::getOption('test') ?: (int) ($params[0] ?? 0);
        if ($testId <= 0) {
            CLI::error('Usage: spark cbt:regen-static-temp <testId>');
            return;
        }

        $request = new \CodeIgniter\HTTP\IncomingRequest(
            new \Config\App(),
            new \CodeIgniter\HTTP\URI(site_url("admin/tests/static/generate/{$testId}")),
            null,
            new \CodeIgniter\HTTP\UserAgent()
        );

        $controller = new StaticExamController();
        $controller->initController($request, \Config\Services::response(), service('logger'));
        $result = $controller->generate($testId);

        $body = '';
        if ($result instanceof \CodeIgniter\HTTP\RedirectResponse) {
            $body = print_r($result->getHeaderLine('Location'), true);
        } else if (is_object($result) && method_exists($result, 'getBody')) {
            $body = $result->getBody();
        } else {
            $body = print_r($result, true);
        }
        CLI::write('Result: ' . $body);
    }
}
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\UiBundleBuilder;

class BuildUiBundle extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:build-ui-bundle';
    protected $description = 'Generate kiosk UI bundle (5 pages + assets + manifest + zip) ke public/ui-bundle/';

    public function run(array $params)
    {
        try {
            (new UiBundleBuilder())->build();
        } catch (\Throwable $e) {
            CLI::error('Build gagal: ' . $e->getMessage());
            exit(1);
        }
    }
}
<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Libraries\UiBundleBuilder;
use App\Models\SettingModel;

class BuildUiBundle extends BaseCommand
{
    protected $group       = 'Tools';
    protected $name        = 'cbt:build-ui-bundle';
    protected $description = 'Generate kiosk UI bundle (5 pages + assets + manifest + zip) ke public/ui-bundle/';
    protected $usage       = 'cbt:build-ui-bundle [--logo <path relatif ke public/>]';
    protected $options     = [
        '--logo' => 'Path gambar relatif terhadap public/, disimpan sebagai setelan kiosk_logo.',
    ];

    public function run(array $params)
    {
        try {
            $logo = CLI::getOption('logo');
            if (is_string($logo) && $logo !== '') {
                $logo = ltrim($logo, '/');
                if (!is_file(FCPATH . $logo)) {
                    CLI::error("Berkas logo tidak ditemukan: public/{$logo}");
                    exit(1);
                }
                // Lewat model, bukan UPDATE mentah: SettingModel menyimpan
                // cache di Redis dan berkas, jadi tulisan langsung ke tabel
                // akan meninggalkan nilai basi.
                (new SettingModel())->setValue('kiosk_logo', $logo, 'string', 'kiosk');
                CLI::write("Setelan kiosk_logo diarahkan ke public/{$logo}", 'green');
            }

            (new UiBundleBuilder())->build();
        } catch (\Throwable $e) {
            CLI::error('Build gagal: ' . $e->getMessage());
            exit(1);
        }
    }
}

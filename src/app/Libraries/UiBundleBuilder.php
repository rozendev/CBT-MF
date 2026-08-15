<?php

namespace App\Libraries;

use CodeIgniter\CLI\CLI;

class UiBundleBuilder
{
    public const OUT_DIR = 'ui-bundle';
    public const SIZE_BUDGET_BYTES = 300 * 1024; // < 300KB zip = gate

    /**
     * @return array{version:string, size:int, path:string}
     */
    public function build(): array
    {
        $outDir = FCPATH . self::OUT_DIR;
        $assetsDir = $outDir . '/assets';
        $assetsSrc = FCPATH . 'assets';
        $vendorSrc = FCPATH . 'vendor/alpinejs';

        @mkdir($outDir, 0755, true);
        @mkdir($assetsDir, 0755, true);

        $baseUrl = rtrim(base_url(), '/');
        $assetVersion = substr(hash_file('sha256', $assetsSrc . '/exam-app.js'), 0, 12);

        // 1) render halaman
        $pages = [
            'login.html'    => 'bundle/login',
            'dashboard.html' => 'bundle/dashboard',
            'exam.html'     => 'bundle/exam',
            'results.html'  => 'bundle/results',
            'review.html'   => 'bundle/review',
        ];
        foreach ($pages as $file => $view) {
            $html = view($view, ['baseUrl' => $baseUrl, 'assetVersion' => $assetVersion]);
            file_put_contents("$outDir/$file", $html);
        }

        // 2) copy assets: exam-app.js, exam-app.css, alpine.min.js, sweetalert2.min.js,
        //    kiosk-integration.js + jquery shim (di-render dari view)
        copy($assetsSrc . '/exam-app.js', "$assetsDir/exam-app.js");
        copy($assetsSrc . '/exam-app.css', "$assetsDir/exam-app.css");
        copy($vendorSrc . '/alpine.min.js', "$assetsDir/alpine.min.js");
        copy(FCPATH . 'vendor/sweetalert2/sweetalert2.min.js', "$assetsDir/sweetalert2.min.js");
        copy(FCPATH . 'js/kiosk-integration.js', "$assetsDir/kiosk-integration.js");
        file_put_contents("$assetsDir/jquery-shim.js", view('bundle/_jquery_shim'));

        // 3) manifest per-file sha256 + version (hash canonical manifest)
        $fileHashes = [];
        $files = array_merge(array_keys($pages), [
            'assets/exam-app.js',
            'assets/exam-app.css',
            'assets/alpine.min.js',
            'assets/sweetalert2.min.js',
            'assets/kiosk-integration.js',
            'assets/jquery-shim.js',
        ]);
        sort($files);
        foreach ($files as $rel) {
            $fileHashes[$rel] = hash_file('sha256', "$outDir/$rel");
        }
        $manifest = ['files' => $fileHashes];
        $manifest['version'] = hash('sha256', json_encode($fileHashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents("$outDir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 4) zip (manifest ikut di dalam zip; verify app = per-file hash vs manifest.files)
        $zip = new \ZipArchive();
        $zipPath = "$outDir/ui-bundle.zip";
        @unlink($zipPath);
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal membuat zip bundle.');
        }
        foreach (array_merge($files, ['manifest.json']) as $rel) {
            $zip->addFile("$outDir/$rel", $rel);
        }
        $zip->close();

        $size = (int) filesize($zipPath);
        $version = $manifest['version'];

        CLI::write("Bundle version: {$version}");
        CLI::write(sprintf('Bundle zip size: %d bytes (%d KB)', $size, (int) round($size / 1024)));

        if ($size > self::SIZE_BUDGET_BYTES) {
            throw new \RuntimeException(sprintf('SIZE BUDGET FAILED: %d bytes > %d bytes. Kurangi aset bundle.', $size, self::SIZE_BUDGET_BYTES));
        }
        CLI::write('SIZE BUDGET OK (< 300KB).', 'green');

        return ['version' => $version, 'size' => $size, 'path' => $outDir];
    }
}

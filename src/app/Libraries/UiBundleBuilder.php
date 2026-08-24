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

        // Identitas sekolah dipanggang ke dalam bundle, bukan diambil saat
        // halaman dibuka: bundle harus tetap utuh saat jaringan sekolah putus,
        // dan logo dari host server akan diblokir kalau perangkat offline.
        // Mengubah nama/logo sekolah mengubah hash bundle, jadi perangkat
        // otomatis menarik versi baru lewat jalur update yang sudah ada.
        $school = $this->schoolIdentity();

        // 1) render halaman
        $pages = [
            'login.html'    => 'bundle/login',
            'dashboard.html' => 'bundle/dashboard',
            'exam.html'     => 'bundle/exam',
            'results.html'  => 'bundle/results',
            'review.html'   => 'bundle/review',
        ];
        // 2) copy assets: exam-app.js, exam-app.css, alpine.min.js, sweetalert2.min.js,
        //    kiosk-integration.js + jquery shim (di-render dari view)
        copy($assetsSrc . '/exam-app.js', "$assetsDir/exam-app.js");
        copy($assetsSrc . '/exam-app.css', "$assetsDir/exam-app.css");
        copy($vendorSrc . '/alpine.min.js', "$assetsDir/alpine.min.js");
        copy(FCPATH . 'vendor/sweetalert2/sweetalert2.min.js', "$assetsDir/sweetalert2.min.js");
        copy(FCPATH . 'js/kiosk-integration.js', "$assetsDir/kiosk-integration.js");
        file_put_contents("$assetsDir/jquery-shim.js", view('bundle/_jquery_shim'));
        $school['logo'] = $this->writeLogo($school['logo'], $assetsDir);

        foreach ($pages as $file => $view) {
            $html = view($view, [
                'baseUrl'      => $baseUrl,
                'assetVersion' => $assetVersion,
                'school'       => $school,
                // Default saat perangkat offline. Saat online, /api/exam/init
                // mengirim ws_url yang menang atas nilai panggangan ini, sehingga
                // bundle lama tetap ikut perubahan setting tanpa rebuild.
                'wsUrl'        => \App\Libraries\WebSocketUrl::resolve(),
            ]);
            file_put_contents("$outDir/$file", $html);
        }

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
        if ($school['logo'] !== '') {
            $files[] = $school['logo'];
        }
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

    /**
     * Nama, tagline, dan logo sekolah untuk header bundle.
     *
     * 'logo' di sini masih path setelan mentah; writeLogo() yang mengubahnya
     * jadi file di dalam bundle. Menautkan ke host server bukan pilihan:
     * headernya rusak begitu perangkat offline.
     *
     * @return array{name:string, tagline:string, logo:string}
     */
    private function schoolIdentity(): array
    {
        $settings = new \App\Models\SettingModel();

        return [
            'name'    => trim((string) $settings->getValue('app_name', 'CBT')) ?: 'CBT',
            'tagline' => trim((string) $settings->getValue('app_description', '')),
            // kiosk_logo menang; app_logo jadi cadangan supaya instalasi yang
            // sudah berjalan tidak kehilangan logonya tanpa menyetel apa pun.
            'logo'    => (string) ($settings->getValue('kiosk_logo', '') ?: $settings->getValue('app_logo', '')),
        ];
    }

    /**
     * Tulis logo yang sudah dikecilkan ke assets/ bundle, kembalikan path
     * relatifnya. Sengaja BUKAN data URI: satu logo yang ditanam di lima
     * halaman terhitung lima kali dan sendirian menaikkan zip bundle dari
     * 83 KB ke 254 KB. Sebagai file, ia dihitung sekali dan tetap offline-safe
     * karena disajikan dari dalam bundle itu sendiri.
     */
    private function writeLogo(string $relativePath, string $assetsDir): string
    {
        $binary = $this->renderLogoPng($relativePath);
        if ($binary === '') {
            return '';
        }
        file_put_contents($assetsDir . '/school-logo.png', $binary);

        return 'assets/school-logo.png';
    }

    private function renderLogoPng(string $relativePath): string
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '') {
            return '';
        }
        $path = FCPATH . $relativePath;
        if (!is_file($path) || !extension_loaded('gd')) {
            return '';
        }

        try {
            $info = getimagesize($path);
            if ($info === false) {
                return '';
            }
            $src = match ($info[2]) {
                IMAGETYPE_PNG  => imagecreatefrompng($path),
                IMAGETYPE_JPEG => imagecreatefromjpeg($path),
                IMAGETYPE_WEBP => imagecreatefromwebp($path),
                IMAGETYPE_GIF  => imagecreatefromgif($path),
                default        => null,
            };
            if (!$src) {
                return '';
            }

            $max = 128;
            $ratio = min($max / max(1, $info[0]), $max / max(1, $info[1]), 1.0);
            $w = max(1, (int) round($info[0] * $ratio));
            $h = max(1, (int) round($info[1] * $ratio));

            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            imagedestroy($src);

            ob_start();
            imagepng($dst, null, 9);
            $binary = (string) ob_get_clean();
            imagedestroy($dst);

            return $binary;
        } catch (\Throwable $e) {
            log_message('error', 'UiBundleBuilder: logo gagal diproses: ' . $e->getMessage());
            return '';
        }
    }
}

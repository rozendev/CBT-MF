<?php

$fonts = [
    'inter' => 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
    'outfit' => 'https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap'
];

$baseDir = __DIR__ . '/../src/public/assets';
if (!is_dir($baseDir . '/css')) mkdir($baseDir . '/css', 0777, true);

// Fake user agent so google fonts returns woff2 instead of ttf
$options = [
    'http' => [
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);

foreach ($fonts as $name => $url) {
    echo "Processing font: $name\n";
    $css = file_get_contents($url, false, $context);
    if (!$css) {
        echo "Failed to download CSS for $name\n";
        continue;
    }

    $fontDir = $baseDir . '/fonts/' . $name;
    if (!is_dir($fontDir)) mkdir($fontDir, 0777, true);

    // Find all urls
    preg_match_all('/url\((https:\/\/fonts\.gstatic\.com\/s\/[^\)]+\.woff2)\)/', $css, $matches);
    
    if (!empty($matches[1])) {
        $urls = array_unique($matches[1]);
        foreach ($urls as $woffUrl) {
            $filename = basename($woffUrl);
            $localPath = $fontDir . '/' . $filename;
            
            if (!file_exists($localPath)) {
                echo "Downloading $filename...\n";
                $woffData = file_get_contents($woffUrl);
                file_put_contents($localPath, $woffData);
            }

            // Replace in CSS
            $css = str_replace($woffUrl, '../fonts/' . $name . '/' . $filename, $css);
        }
    }

    file_put_contents($baseDir . '/css/' . $name . '.css', $css);
    echo "Saved $name.css\n";
}
echo "Done.\n";

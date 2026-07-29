<?php
$dir = __DIR__ . '/public/static/';

if (!is_dir($dir)) {
    echo "Static directory not found.\n";
    exit;
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$count = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'html') {
        $content = file_get_contents($file->getPathname());
        
        $target = "if (getAC().enabled === false && !getAC().auto_submit_on_cheat) {
                reportCheat('fullscreen_exit');
                return;
            }";
            
        $replacement = "if (getAC().enabled === false && !getAC().auto_submit_on_cheat) {
                return;
            }";
            
        if (strpos($content, "reportCheat('fullscreen_exit');") !== false) {
            $newContent = str_replace($target, $replacement, $content);
            if ($newContent !== $content) {
                file_put_contents($file->getPathname(), $newContent);
                echo "Patched cheat redundant call in: " . $file->getFilename() . "\n";
                $count++;
            }
        }
    }
}
echo "Total files patched for cheat logic: $count\n";

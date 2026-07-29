<?php
$dir = __DIR__ . '/public/static/';

if (!is_dir($dir)) {
    echo "Directory not found.\n";
    exit;
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$countScope = 0;
$countRedundant = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'html') {
        $content = file_get_contents($file->getPathname());
        $changed = false;
        
        // 1. Fix getAC scoping
        if (strpos($content, "function getAC() { return EXAM_CONFIG.antiCheat || {}; }") !== false) {
            $content = str_replace(
                "function getAC() { return EXAM_CONFIG.antiCheat || {}; }",
                "window.getAC = function() { return EXAM_CONFIG.antiCheat || {}; };",
                $content
            );
            $countScope++;
            $changed = true;
        }

        // 2. Fix redundant cheat call
        $target = "if (getAC().enabled === false && !getAC().auto_submit_on_cheat) {\n                reportCheat('fullscreen_exit');\n                return;\n            }";
        $replacement = "if (getAC().enabled === false && !getAC().auto_submit_on_cheat) {\n                return;\n            }";
            
        if (strpos($content, $target) !== false) {
            $content = str_replace($target, $replacement, $content);
            $countRedundant++;
            $changed = true;
        }
        
        if ($changed) {
            file_put_contents($file->getPathname(), $content);
            echo "Patched: " . $file->getFilename() . "\n";
        }
    }
}
echo "Total static files patched for getAC scope: $countScope\n";
echo "Total static files patched for redundant API call: $countRedundant\n";

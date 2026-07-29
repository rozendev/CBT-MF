<?php
$directory = '/var/www/html/public/static';
$oldText = "        try {\n            const body = { test_id: EXAM_CONFIG.testId };\n            if (CSRF_NAME) body[CSRF_NAME] = CSRF_HASH;";
$newText = "        try {\n            // Because this is a static page, we must fetch the CSRF token first\n            try {\n                const csrfRes = await fetch(API + '/health', { credentials: 'same-origin' });\n                const csrfToken = csrfRes.headers.get('X-CSRF-TOKEN');\n                if (csrfToken) {\n                    CSRF_HASH = csrfToken;\n                    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': csrfToken } });\n                }\n            } catch(e) {}\n\n            const body = { test_id: EXAM_CONFIG.testId };\n            if (CSRF_NAME) body[CSRF_NAME] = CSRF_HASH;";

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'html') {
        $filepath = $file->getPathname();
        $content = file_get_contents($filepath);
        if (strpos($content, $oldText) !== false) {
            $content = str_replace($oldText, $newText, $content);
            file_put_contents($filepath, $content);
            echo "Updated {$filepath}\n";
        }
    }
}

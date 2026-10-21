<?php
// CLI-only development probe; reads /health and never creates exam/user data.
if (PHP_SAPI !== 'cli') exit(1);
$times = [];
$errors = 0;
for ($i = 0; $i < 20; $i++) {
    $start = microtime(true);
    $body = @file_get_contents('http://nginx/health', false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
    $times[] = (microtime(true) - $start) * 1000;
    if ($body === false || (json_decode($body, true)['status'] ?? '') !== 'ok') $errors++;
    usleep(100000);
}
sort($times);
echo json_encode(['samples' => count($times), 'errors' => $errors, 'p50_ms' => round($times[9], 2), 'p95_ms' => round($times[18], 2)]) . PHP_EOL;
exit($errors > 0 ? 1 : 0);

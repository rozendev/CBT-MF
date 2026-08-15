<?php
/**
 * Bundle page head — kiosk-integration.js PERTAMA, lalu aset lokal bundle.
 * Variabel: $pageTitle, $assetVersion (sha256 12-char file assets), $baseUrl (server base).
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= esc($pageTitle) ?> — Kiosk CBT</title>
    <script src="<?= $baseUrl ?>/js/kiosk-integration.js"></script>
    <link rel="stylesheet" href="assets/exam-app.css?v=<?= esc($assetVersion) ?>">
    <script>
        window.KIOSK_BUNDLE = true;
        window.__KIOSK_BUNDLE__ = true;
        window.KIOSK_BASE_URL = <?= json_encode($baseUrl) ?>;
    </script>
    <style>
        :root { --kiosk-bg: #f1f5f9; --kiosk-card: #ffffff; --kiosk-ink: #0f172a;
                --kiosk-primary: #2563eb; --kiosk-border: #e2e8f0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
               background: var(--kiosk-bg); color: var(--kiosk-ink); }
        .k-card { background: var(--kiosk-card); border: 1px solid var(--kiosk-border); border-radius: 12px; padding: 20px; }
        .k-btn { display: inline-block; background: var(--kiosk-primary); color: #fff; border: 0;
                 border-radius: 10px; padding: 12px 18px; font-size: 16px; width: 100%; }
        .k-btn:disabled { opacity: .5; }
        .k-input { width: 100%; padding: 12px; border: 1px solid var(--kiosk-border); border-radius: 10px; font-size: 16px; }
        .k-error { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px; }
    </style>
</head>
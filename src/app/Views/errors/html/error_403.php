<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Akses Ditolak</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: #e2e8f0;
            margin: 0;
        }
        .container { text-align: center; }
        .code { font-size: 6rem; font-weight: 700; color: #ef4444; line-height: 1; }
        h2 { margin: 0.5rem 0; font-size: 1.5rem; }
        p { color: #94a3b8; margin: 1rem 0 2rem; }
        .btn {
            display: inline-block;
            padding: 0.7rem 2rem;
            background: #4f46e5;
            color: white;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn:hover { background: #6366f1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="code">403</div>
        <h2>Akses Ditolak</h2>
        <p><?= $message ?? 'Anda tidak memiliki izin untuk mengakses halaman ini.' ?></p>
        <a href="javascript:void(0)" onclick="goBack()" class="btn">← Kembali</a>
    </div>

    <script>
        function goBack() {
            if (document.referrer === "" || document.referrer === window.location.href) {
                window.location.href = '/';
            } else {
                window.history.back();
            }
        }
    </script>
</body>
</html>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Kartu Ujian</title>
    <link href="<?= base_url('assets/css/outfit.css?v=1.1') ?>" rel="stylesheet">
    <style>
        
        * {
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            margin: 0;
            padding: 20px;
            background: #f0f2f5;
        }

        .page {
            width: 210mm;
            /* height: 297mm; A4 */
            padding: 10mm;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            page-break-after: always;
        }

        .cards-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-auto-rows: 65mm;
            gap: 5mm;
        }

        .card {
            border: 2px solid #000;
            border-radius: 8px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .card-header {
            display: flex;
            align-items: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .card-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            margin-right: 10px;
        }

        .card-title-group {
            flex-grow: 1;
            text-align: center;
        }

        .card-title {
            font-size: 14px;
            font-weight: 800;
            margin: 0;
            text-transform: uppercase;
        }

        .card-subtitle {
            font-size: 11px;
            margin: 2px 0 0 0;
            font-weight: 600;
        }

        .card-body {
            display: flex;
            flex-grow: 1;
        }

        .card-photo {
            width: 30mm;
            height: 40mm;
            border: 1px dashed #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: #666;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .card-details {
            flex-grow: 1;
            font-size: 11px;
        }

        .detail-row {
            margin-bottom: 5px;
        }

        .detail-label {
            font-weight: 600;
            display: inline-block;
            width: 60px;
        }

        .detail-value {
            font-weight: 700;
            font-family: monospace;
            font-size: 13px;
        }

        .card-footer {
            margin-top: auto;
            display: flex;
            justify-content: flex-end;
        }

        .signature-box {
            text-align: center;
            font-size: 10px;
            width: 80px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-top: 25px;
        }

        @media print {
            body {
                background: none;
                padding: 0;
            }
            .page {
                box-shadow: none;
                margin: 0;
                padding: 5mm;
                width: auto;
                height: auto;
            }
            .card {
                break-inside: avoid;
            }
        }
        
        .print-btn {
            display: block;
            width: 200px;
            margin: 0 auto 20px auto;
            padding: 10px;
            text-align: center;
            background: #0d6efd;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            border: none;
        }
        
        @media print {
            .print-btn {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="print-btn">🖨️ Cetak Kartu Sekarang</button>

    <?php 
    $cardsPerPage = 8;
    $chunks = array_chunk($students, $cardsPerPage);
    
    foreach ($chunks as $chunkIndex => $chunk): ?>
        <div class="page">
            <div class="cards-container">
                <?php foreach ($chunk as $student): ?>
                    <div class="card">
                        <div class="card-header">
                            <?php if (!empty($appLogo)): ?>
                                <img src="<?= base_url($appLogo) ?>" alt="Logo" class="card-logo">
                            <?php endif; ?>
                            <div class="card-title-group">
                                <h1 class="card-title">KARTU PESERTA UJIAN</h1>
                                <p class="card-subtitle"><?= esc($schoolName) ?></p>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="card-photo">
                                Pas Foto<br>3x4
                            </div>
                            <div class="card-details">
                                <div class="detail-row">
                                    <span class="detail-label">Nama</span>
                                    <span>: <strong><?= esc($student['name']) ?></strong></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">NISN</span>
                                    <span>: <span class="detail-value"><?= esc($student['username']) ?></span></span>
                                </div>
                                <div class="detail-row" style="margin-top: 10px;">
                                    <span class="detail-label">Password</span>
                                    <span>: <span class="detail-value"><?= esc($student['password']) ?></span></span>
                                </div>
                                <div class="card-footer">
                                    <div class="signature-box">
                                        Peserta Ujian
                                        <div class="signature-line"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <script>
        // Auto trigger print dialog when page loads
        window.onload = function() {
            setTimeout(() => {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>

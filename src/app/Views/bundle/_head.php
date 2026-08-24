<?php
/**
 * Bundle page head — kiosk-integration.js PERTAMA, lalu aset lokal bundle.
 * Variabel: $pageTitle, $assetVersion (sha256 12-char file assets), $baseUrl (server base),
 * $school (name/tagline/logo data URI, dipanggang UiBundleBuilder saat build).
 */
$school = ($school ?? []) + ['name' => 'CBT', 'tagline' => '', 'logo' => ''];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= esc($pageTitle) ?> — <?= esc($school['name']) ?></title>
    <script src="<?= $baseUrl ?>/js/kiosk-integration.js"></script>
    <link rel="stylesheet" href="assets/exam-app.css?v=<?= esc($assetVersion) ?>">
    <script>
        window.KIOSK_BUNDLE = true;
        window.__KIOSK_BUNDLE__ = true;
        window.KIOSK_BASE_URL = <?= json_encode($baseUrl) ?>;
        window.KIOSK_WS_URL = <?= json_encode($wsUrl ?? '') ?>;
    </script>
    <style>
        /* Design system kiosk — kontras tinggi, target sentuh besar.
           Sasaran: HP kelas bawah (mis. Redmi 10A 720x1600) dengan layar redup,
           dipakai siswa yang gugup. Prioritas: keterbacaan > dekorasi.
           Tanpa webfont (anggaran bundle); hanya system font stack. */
        :root {
            --kiosk-bg: #f1f5f9; --kiosk-card: #ffffff; --kiosk-ink: #0f172a;
            --kiosk-muted: #475569; --kiosk-primary: #1d4ed8; --kiosk-primary-ink: #ffffff;
            --kiosk-border: #cbd5e1; --kiosk-danger: #b91c1c; --kiosk-danger-bg: #fef2f2;
            --kiosk-ok: #15803d; --kiosk-ok-bg: #f0fdf4; --kiosk-warn: #a16207;
            --kiosk-ink-soft: #334155; --kiosk-surface-2: #f8fafc;
            --kiosk-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 8px 24px rgba(15, 23, 42, .06);
            /* Target sentuh minimum Android 48dp; 56px untuk aksi utama. */
            --k-tap: 56px; --k-radius: 12px; --k-gap: 16px;
            /* Poni dan bilah gestur Android: tanpa ini header nyelip di balik
               status bar dan tombol terakhir ketiban bilah navigasi. */
            --k-safe-top: env(safe-area-inset-top, 0px);
            --k-safe-bottom: env(safe-area-inset-bottom, 0px);
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            margin: 0; background: var(--kiosk-bg); color: var(--kiosk-ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 17px; line-height: 1.55;
            -webkit-text-size-adjust: 100%;
        }
        .k-wrap { max-width: 560px; margin: 0 auto; padding: var(--k-gap);
                  padding-bottom: calc(var(--k-gap) + var(--k-safe-bottom)); }
        .k-card { background: var(--kiosk-card); border: 1px solid var(--kiosk-border);
                  border-radius: var(--k-radius); padding: 20px; box-shadow: var(--kiosk-shadow); }

        /* ── App bar: identitas sekolah, sama di semua halaman ──────────────
           Alasannya bukan hiasan. Perangkat ujian dipegang siswa yang gugup di
           ruangan asing; layar yang tidak menyebut sekolahnya terasa seperti
           halaman uji coba, bukan lembar ujian resmi. */
        .k-appbar {
            display: flex; align-items: center; gap: 12px;
            padding: calc(10px + var(--k-safe-top)) var(--k-gap) 10px;
            background: var(--kiosk-card); border-bottom: 1px solid var(--kiosk-border);
        }
        .k-appbar__logo { width: 38px; height: 38px; flex: 0 0 auto;
                          object-fit: contain; border-radius: 8px; }
        .k-appbar__id { flex: 1; min-width: 0; }
        .k-appbar__school { font-weight: 700; font-size: 16px; line-height: 1.25;
                            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .k-appbar__tagline { color: var(--kiosk-muted); font-size: 12px; letter-spacing: .02em;
                             white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Judul halaman: konteks "saya sedang di mana" tanpa memakan satu layar. */
        .k-pagehead { margin-bottom: var(--k-gap); }
        .k-pagehead h1 { margin: 0 0 2px; }
        .k-pagehead p { margin: 0; color: var(--kiosk-muted); font-size: 15px; }
        h1, h2, h3 { margin: 0 0 12px; line-height: 1.3; }
        h1 { font-size: 24px; } h2 { font-size: 20px; } h3 { font-size: 18px; }
        .k-muted { color: var(--kiosk-muted); font-size: 15px; }

        /* Tombol: tinggi penuh, teks 17px, tidak pernah mengecil di layar sempit. */
        .k-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; min-height: var(--k-tap); padding: 12px 18px;
            background: var(--kiosk-primary); color: var(--kiosk-primary-ink);
            border: 0; border-radius: var(--k-radius);
            font-size: 17px; font-weight: 600; font-family: inherit;
            cursor: pointer; text-decoration: none;
        }
        .k-btn:active { filter: brightness(.92); }
        .k-btn:disabled { opacity: .5; }
        .k-btn--ghost { background: transparent; color: var(--kiosk-primary);
                        border: 2px solid var(--kiosk-border); }
        .k-btn--danger { background: var(--kiosk-danger); }
        .k-btn--sm { min-height: 48px; font-size: 16px; width: auto; padding: 10px 16px; }

        .k-input {
            width: 100%; min-height: var(--k-tap); padding: 14px;
            border: 2px solid var(--kiosk-border); border-radius: var(--k-radius);
            font-size: 17px; font-family: inherit; background: #fff; color: inherit;
        }
        .k-input:focus { outline: none; border-color: var(--kiosk-primary); }
        .k-label { display: block; font-weight: 600; font-size: 15px; margin-bottom: 6px; }

        /* Kotak status: dipakai untuk error/sukses/info yang harus terbaca jelas. */
        .k-note { border-radius: 10px; padding: 14px; font-size: 16px; border: 1px solid; }
        .k-error { color: var(--kiosk-danger); background: var(--kiosk-danger-bg); border-color: #fecaca; }
        .k-ok { color: var(--kiosk-ok); background: var(--kiosk-ok-bg); border-color: #bbf7d0; }

        /* Bar identitas: siswa harus selalu tahu login sebagai siapa. */
        .k-idbar {
            display: flex; align-items: center; gap: 12px;
            background: var(--kiosk-card); border-bottom: 1px solid var(--kiosk-border);
            padding: 12px var(--k-gap);
        }
        .k-idbar__who { flex: 1; min-width: 0; }
        .k-idbar__name { font-weight: 700; font-size: 16px;
                         white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .k-idbar__sub { color: var(--kiosk-muted); font-size: 13px;
                        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Banner status simpan — menempel di atas viewport dan TIDAK hilang
           sendiri; satu-satunya cara lenyap adalah jawaban berhasil tersimpan. */
        /* sticky, bukan fixed: sebagai fixed banner ini menimpa bagian atas
           soal justru ketika siswa paling perlu membacanya. Sticky tetap
           menempel saat digulir tapi ikut memakan ruang, jadi tidak ada yang
           tertutup. */
        .k-savebar {
            position: sticky; top: 0; z-index: 9999;
            padding: 12px var(--k-gap); border-bottom: 3px solid;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .18);
        }
        .k-savebar--bad { background: #fee2e2; border-color: var(--kiosk-danger); color: #7f1d1d; }
        .k-savebar__title { font-weight: 700; font-size: 17px; }
        .k-savebar__msg { font-size: 14px; margin-top: 2px; }

        /* Toast peredam aksi (cadangan non-kiosk) — melayang di bawah agar
           tidak menutupi soal maupun banner status simpan di atas. */
        .k-ratetoast {
            position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%);
            z-index: 9998; max-width: 88vw;
            background: rgba(15, 23, 42, .94); color: #fff;
            padding: 12px 18px; border-radius: 999px;
            font-size: 15px; text-align: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .25);
        }

        /* Konten dari editor guru (soal, jawaban esai, kunci) bebas isinya:
           gambar berukuran asli, tabel lebar, kata panjang tanpa spasi. Tanpa
           pagar ini semuanya menembus kartu dan bikin halaman melar ke samping. */
        .k-card { overflow-wrap: anywhere; }
        .k-card img, .k-note img {
            max-width: 100%; height: auto; display: block;
            margin: 12px 0; border: 1px solid var(--kiosk-border);
            border-radius: 10px; background: #fff;
        }
        /* Tabel lebar digulir di dalam kartunya sendiri, bukan menggeser halaman. */
        .k-card table { max-width: 100%; border-collapse: collapse; }
        .k-card .table-responsive { max-width: 100%; overflow-x: auto; }
        .k-card pre { max-width: 100%; overflow-x: auto; }

        .k-row { display: flex; gap: 12px; }
        .k-row > * { flex: 1; }
        .k-stack > * + * { margin-top: 12px; }
    </style>
</head>

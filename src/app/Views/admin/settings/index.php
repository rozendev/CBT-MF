<?= $this->extend('layouts/admin') ?>

<?= $this->section('page_title') ?>Pengaturan Sistem<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    /* ════════════════════════════════════════════════════════════
       PENGATURAN — "Panel" surface
       Row-list settings · sticky rail · floating save bar
       ════════════════════════════════════════════════════════════ */

    .settings-grid {
        display: grid;
        grid-template-columns: 264px minmax(0, 1fr);
        gap: 1.5rem;
        align-items: start;
    }
    .settings-grid > * { min-width: 0; }

    /* ── Left rail ─────────────────────────────────────────── */
    .settings-rail {
        position: sticky;
        top: calc(var(--topbar-height) + 1.5rem);
    }
    .rail-card {
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: var(--card-shadow);
        overflow: hidden;
    }
    .rail-card .rail-head {
        padding: 1.1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }
    .rail-card .rail-head i { color: var(--brand-color); font-size: 1rem; }
    .rail-card .rail-head span {
        font-family: var(--mono);
        font-size: 0.64rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--text-tertiary);
        font-weight: 600;
    }
    .rail-nav { padding: 0.6rem; display: flex; flex-direction: column; gap: 2px; }
    .rail-item {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.55rem 0.7rem;
        border: 0;
        background: none;
        border-radius: 12px;
        color: var(--text-secondary);
        font-weight: 500;
        font-size: 0.88rem;
        text-align: left;
        cursor: pointer;
        position: relative;
        transition: background 0.2s var(--ease), color 0.2s var(--ease);
    }
    .rail-item::before {
        content: "";
        position: absolute;
        left: 0; top: 50%;
        transform: translateY(-50%) scaleY(0);
        width: 3px; height: 55%;
        border-radius: 3px;
        background: var(--brand-color);
        transition: transform 0.25s var(--ease);
    }
    .rail-item:hover { background: var(--bg-soft); color: var(--text-primary); }
    .rail-item.active { background: var(--brand-softer); color: var(--brand-strong); font-weight: 600; }
    .rail-item.active::before { transform: translateY(-50%) scaleY(1); }
    .rail-item .ri-icon {
        width: 34px; height: 34px;
        border-radius: 10px;
        display: grid;
        place-items: center;
        font-size: 0.95rem;
        background: var(--bg-soft);
        color: var(--text-secondary);
        flex-shrink: 0;
        transition: background 0.2s var(--ease), color 0.2s var(--ease), box-shadow 0.2s var(--ease);
    }
    .rail-item.active .ri-icon {
        background: var(--brand-color);
        color: #fff;
        box-shadow: 0 6px 14px -6px rgba(14, 138, 107, 0.55);
    }
    .rail-item .ri-text { flex: 1; min-width: 0; line-height: 1.15; }
    .rail-item .ri-text b { display: block; font-weight: 600; font-size: 0.85rem; }
    .rail-item .ri-text small {
        display: block;
        font-size: 0.64rem;
        font-weight: 500;
        color: var(--text-tertiary);
        margin-top: 1px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .rail-item.active .ri-text small { color: var(--brand-strong); opacity: 0.8; }
    .rail-item .ri-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: transparent;
        flex-shrink: 0;
        transition: background 0.2s, box-shadow 0.2s;
    }
    .rail-item.dirty .ri-dot {
        background: var(--warn);
        box-shadow: 0 0 8px rgba(176, 125, 31, 0.55);
    }
    .rail-foot {
        padding: 0.8rem 1.25rem 1.05rem;
        border-top: 1px solid var(--border-color);
        font-size: 0.72rem;
        color: var(--text-tertiary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .rail-foot .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 6px rgba(14, 138, 107, 0.5); }

    /* ── Stage / pane head ─────────────────────────────────── */
    .pane-head { margin-bottom: 1.5rem; }
    .pane-kicker {
        font-family: var(--mono);
        font-size: 0.64rem;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--brand-color);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin-bottom: 0.55rem;
    }
    .pane-kicker::after { content: ""; width: 26px; height: 1px; background: var(--brand-color); opacity: 0.4; }
    .pane-title {
        font-size: 1.45rem;
        font-weight: 700;
        letter-spacing: -0.025em;
        margin: 0;
        color: var(--text-primary);
        text-wrap: balance;
    }
    .pane-desc {
        color: var(--text-secondary);
        font-size: 0.88rem;
        margin: 0.35rem 0 0;
        max-width: 68ch;
    }

    /* ── Panels & rows ─────────────────────────────────────── */
    .s-panel {
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: var(--card-shadow);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }
    .s-panel-head {
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
        padding: 1.1rem 1.4rem;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-raised);
    }
    .s-panel-head .ph-icon {
        width: 38px; height: 38px;
        border-radius: 11px;
        display: grid;
        place-items: center;
        background: var(--brand-softer);
        color: var(--brand-color);
        font-size: 1.05rem;
        flex-shrink: 0;
    }
    .s-panel-head h6 { font-weight: 650; font-size: 0.95rem; margin: 0; color: var(--text-primary); letter-spacing: -0.01em; }
    .s-panel-head p { margin: 3px 0 0; font-size: 0.79rem; color: var(--text-secondary); line-height: 1.45; }

    .s-row {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        padding: 1.05rem 1.4rem;
        border-top: 1px solid var(--border-color);
        position: relative;
        transition: background 0.15s ease;
    }
    .s-row:first-child { border-top: 0; }
    .s-row:hover { background: var(--bg-raised); }
    .s-row.stack { flex-direction: column; align-items: stretch; gap: 0.9rem; }
    .s-row.is-dirty { box-shadow: inset 3px 0 0 var(--warn); }
    .s-row.is-dirty .s-label::after {
        content: "";
        display: inline-block;
        width: 6px; height: 6px;
        border-radius: 50%;
        background: var(--warn);
        margin-left: 0.45rem;
        vertical-align: middle;
    }
    .s-main { flex: 1; min-width: 0; }
    .s-label { font-weight: 600; font-size: 0.92rem; color: var(--text-primary); display: block; }
    .s-desc { font-size: 0.8rem; color: var(--text-secondary); margin: 3px 0 0; line-height: 1.45; max-width: 68ch; }
    .s-ctrl { flex-shrink: 0; display: flex; align-items: center; gap: 0.6rem; }
    .s-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.9rem; }
    .s-grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.9rem; }
    .s-field label {
        display: block;
        font-size: 0.74rem;
        font-weight: 600;
        color: var(--text-tertiary);
        margin-bottom: 0.35rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .s-field .form-control, .s-field .form-select { font-size: 0.88rem; }

    /* Number input with unit suffix */
    .s-unit { position: relative; }
    .s-unit .form-control { padding-right: 2.8rem; }
    .s-unit .unit {
        position: absolute;
        right: 0.8rem; top: 50%;
        translate: 0 -50%;
        font-family: var(--mono);
        font-size: 0.7rem;
        color: var(--text-tertiary);
        pointer-events: none;
    }

    /* ── Toggle switch ─────────────────────────────────────── */
    .toggle { position: relative; width: 52px; height: 28px; flex-shrink: 0; }
    .toggle input {
        position: absolute; inset: 0;
        width: 100%; height: 100%;
        opacity: 0; margin: 0;
        cursor: pointer; z-index: 1;
    }
    .toggle .track {
        position: absolute; inset: 0;
        background: var(--border-strong);
        border-radius: 999px;
        transition: background 0.2s var(--ease), box-shadow 0.2s var(--ease);
    }
    .toggle .track::after {
        content: "";
        position: absolute;
        top: 3px; left: 3px;
        width: 22px; height: 22px;
        border-radius: 50%;
        background: #fff;
        box-shadow: 0 1px 4px rgba(15, 23, 42, 0.3);
        transition: transform 0.22s var(--ease);
    }
    .toggle input:checked ~ .track { background: var(--brand-color); }
    .toggle input:checked ~ .track::after { transform: translateX(24px); }
    .toggle input:focus-visible ~ .track { box-shadow: 0 0 0 3px var(--brand-ring); }
    .toggle input:disabled ~ .track { opacity: 0.5; cursor: not-allowed; }

    /* ── Color fields ──────────────────────────────────────── */
    .swatch-row { display: flex; align-items: center; gap: 0.6rem; }
    .swatch-row input[type="color"] {
        width: 42px; height: 42px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 3px;
        background: var(--bg-raised);
        cursor: pointer;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .swatch-row input[type="color"]:hover { border-color: var(--brand-color); }
    .swatch-row input[type="color"]:focus-visible { box-shadow: 0 0 0 3px var(--brand-ring); outline: none; }
    .swatch-row .hex {
        font-family: var(--mono);
        font-size: 0.8rem;
        background: var(--bg-soft);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 0.45rem 0.7rem;
        color: var(--text-secondary);
        width: 96px;
        text-align: center;
    }

    /* ── Range slider ──────────────────────────────────────── */
    .range-wrap { flex: 1; }
    .range-value {
        font-family: var(--mono);
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--brand-strong);
        background: var(--brand-softer);
        border-radius: 999px;
        padding: 0.3rem 0.8rem;
        min-width: 58px;
        text-align: center;
    }

    /* ── Upload tiles ──────────────────────────────────────── */
    .upload-tile {
        border: 1px dashed var(--border-strong);
        border-radius: var(--radius-md);
        padding: 1.1rem;
        text-align: center;
        transition: border-color 0.2s var(--ease), background 0.2s;
    }
    .upload-tile:hover { border-color: var(--brand-color); background: var(--brand-softer); }
    .upload-tile .ut-preview {
        height: 72px;
        display: grid;
        place-items: center;
        margin-bottom: 0.8rem;
    }
    .upload-tile .ut-preview img { max-height: 72px; max-width: 100%; object-fit: contain; }
    .upload-tile .ut-placeholder {
        width: 46px; height: 46px;
        border-radius: 14px;
        background: var(--bg-soft);
        color: var(--text-tertiary);
        display: grid;
        place-items: center;
        font-size: 1.3rem;
    }
    .upload-tile .ut-name { font-weight: 600; font-size: 0.85rem; color: var(--text-primary); margin-bottom: 0.2rem; }
    .upload-tile .ut-desc { font-size: 0.72rem; color: var(--text-tertiary); margin-bottom: 0.8rem; }
    .upload-tile .form-control { font-size: 0.78rem; }

    /* ── System metrics ────────────────────────────────────── */
    .sys-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.9rem; }
    .sys-tile {
        background: var(--bg-raised);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 1.05rem 1.15rem;
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
    }
    .sys-tile .st-value {
        font-family: var(--mono);
        font-size: 1.2rem;
        font-weight: 600;
        letter-spacing: -0.01em;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 1.6rem;
    }
    .sys-tile .st-label {
        font-family: var(--mono);
        font-size: 0.64rem;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--text-tertiary);
        display: flex;
        align-items: center;
        gap: 0.45rem;
    }
    .sys-tile .st-label i { font-size: 0.8rem; color: var(--brand-color); }
    .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .status-dot.online { background: var(--ok); box-shadow: 0 0 6px rgba(14, 138, 107, 0.45); }
    .status-dot.offline { background: var(--danger); box-shadow: 0 0 6px rgba(214, 69, 80, 0.45); }
    .status-dot.loading { background: var(--text-tertiary); animation: pulse 1s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }

    /* ── Live preview frame ────────────────────────────────── */
    .preview-frame {
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        background: var(--bg-surface);
        box-shadow: var(--card-shadow);
        overflow: hidden;
    }
    .preview-frame .pf-bar {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.7rem 1rem;
        border-bottom: 1px solid var(--border-color);
    }
    .preview-frame .pf-bar .dot { width: 9px; height: 9px; border-radius: 50%; }
    .preview-frame .pf-bar .dot:nth-child(1) { background: #f2695d; }
    .preview-frame .pf-bar .dot:nth-child(2) { background: #f2b84b; }
    .preview-frame .pf-bar .dot:nth-child(3) { background: #58bb74; }
    .preview-frame .pf-bar span { margin-left: auto; font-family: var(--mono); font-size: 0.62rem; color: var(--text-tertiary); }
    .preview-frame .pf-body { padding: 1.1rem; }

    /* ── Floating save bar ─────────────────────────────────── */
    .savebar {
        position: fixed;
        left: 50%;
        bottom: 1.4rem;
        transform: translateX(-50%) translateY(12px);
        z-index: 1050;
        display: flex;
        align-items: center;
        gap: 0.9rem;
        padding: 0.5rem 0.5rem 0.5rem 1.2rem;
        background: rgba(255, 255, 255, 0.82);
        -webkit-backdrop-filter: blur(16px) saturate(1.4);
        backdrop-filter: blur(16px) saturate(1.4);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        box-shadow: 0 18px 44px -18px rgba(16, 24, 40, 0.35);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.25s var(--ease), transform 0.25s var(--ease);
    }
    .savebar.visible { opacity: 1; pointer-events: auto; transform: translateX(-50%) translateY(0); }
    .savebar .sb-status {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: var(--text-secondary);
        white-space: nowrap;
    }
    .savebar .sb-status .dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        background: var(--warn);
        box-shadow: 0 0 8px rgba(176, 125, 31, 0.55);
        animation: pulse 1.2s infinite;
    }
    .savebar .btn { border-radius: 999px; padding: 0.5rem 1.3rem; font-weight: 600; }

    /* ── Mobile ────────────────────────────────────────────── */
    @media (max-width: 991.98px) {
        .settings-grid { grid-template-columns: 1fr; gap: 1rem; }
        .settings-rail { position: sticky; top: calc(var(--topbar-height) + 0.75rem); z-index: 40; }
        .rail-card { border-radius: var(--radius-md); box-shadow: 0 12px 28px -18px rgba(16, 24, 40, 0.28); }
        .rail-card .rail-head, .rail-card .rail-foot { display: none; }
        .rail-nav {
            flex-direction: row;
            overflow-x: auto;
            padding: 0.5rem;
            gap: 0.4rem;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .rail-nav::-webkit-scrollbar { display: none; }
        .rail-item {
            flex: 0 0 auto;
            gap: 0.5rem;
            padding: 0.5rem 0.85rem;
            border: 1px solid var(--border-color);
            background: var(--bg-raised);
            border-radius: 999px;
        }
        .rail-item::before { display: none; }
        .rail-item .ri-icon { width: 26px; height: 26px; font-size: 0.85rem; border-radius: 8px; }
        .rail-item.active {
            background: var(--brand-color);
            border-color: var(--brand-color);
            color: #fff;
            box-shadow: 0 6px 16px -6px rgba(14, 138, 107, 0.5);
        }
        .rail-item.active .ri-icon { background: rgba(255, 255, 255, 0.18); color: #fff; box-shadow: none; }
        .rail-item.active .ri-text small { color: rgba(255, 255, 255, 0.8); }
        .rail-item .ri-text small { display: none; }
        .rail-item .ri-text b { font-size: 0.8rem; white-space: nowrap; }
        .rail-item .ri-dot { display: none; }
        .pane-head { margin-bottom: 1.1rem; }
        .pane-title { font-size: 1.2rem; }
        .s-grid-3, .s-grid-2, .sys-grid { grid-template-columns: 1fr; }
        .s-panel-head { padding: 1rem 1.1rem; }
        .s-row { padding: 1rem 1.1rem; }
        .savebar { width: calc(100% - 2rem); justify-content: space-between; border-radius: 18px; }
        .savebar .sb-status { font-size: 0.75rem; }
    }

    @media (max-width: 575.98px) {
        .s-row { flex-direction: column; align-items: stretch; gap: 0.75rem; }
        .s-ctrl { width: 100%; flex-wrap: wrap; }
        .s-ctrl .form-control, .s-ctrl .form-select { width: 100%; }
        .range-wrap .form-range { width: 100%; }
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
    function settingVal($grouped, $group, $key, $default = '') {
        return isset($grouped[$group][$key]) ? $grouped[$group][$key]['value'] : $default;
    }
    function settingChecked($grouped, $group, $key) {
        return isset($grouped[$group][$key]) && $grouped[$group][$key]['value'] == '1' ? 'checked' : '';
    }
?>

<form action="<?= base_url('/admin/settings/update') ?>" method="POST" enctype="multipart/form-data" id="settingsForm">
    <?= csrf_field() ?>

    <div class="settings-grid">

        <!-- ══════════ LEFT RAIL ══════════ -->
        <aside class="settings-rail">
            <div class="rail-card">
                <div class="rail-head">
                    <i class="bi bi-sliders2-vertical"></i>
                    <span>Konfigurasi</span>
                </div>
                <nav class="rail-nav" aria-label="Bagian pengaturan">
                    <button type="button" class="rail-item active" data-bs-target="#tab-website" aria-controls="tab-website">
                        <span class="ri-icon"><i class="bi bi-globe2"></i></span>
                        <span class="ri-text"><b>Website</b><small>Identitas & zona waktu</small></span>
                        <span class="ri-dot"></span>
                    </button>
                    <button type="button" class="rail-item" data-bs-target="#tab-appearance" aria-controls="tab-appearance">
                        <span class="ri-icon"><i class="bi bi-palette2"></i></span>
                        <span class="ri-text"><b>Logo &amp; Tampilan</b><small>Warna, font & pratinjau</small></span>
                        <span class="ri-dot"></span>
                    </button>
                    <button type="button" class="rail-item" data-bs-target="#tab-exam" aria-controls="tab-exam">
                        <span class="ri-icon"><i class="bi bi-card-checklist"></i></span>
                        <span class="ri-text"><b>Ujian</b><small>Durasi, skor & pengacakan</small></span>
                        <span class="ri-dot"></span>
                    </button>
                    <button type="button" class="rail-item" data-bs-target="#tab-security" aria-controls="tab-security">
                        <span class="ri-icon"><i class="bi bi-shield-lock"></i></span>
                        <span class="ri-text"><b>Keamanan</b><small>Anti-cheat & akses</small></span>
                        <span class="ri-dot"></span>
                    </button>
                    <button type="button" class="rail-item" data-bs-target="#tab-system" aria-controls="tab-system">
                        <span class="ri-icon"><i class="bi bi-cpu"></i></span>
                        <span class="ri-text"><b>Sistem</b><small>Status server & pemeliharaan</small></span>
                        <span class="ri-dot"></span>
                    </button>
                </nav>
                <div class="rail-foot">
                    <span class="dot"></span>
                    <span>Perubahan tersimpan otomatis saat disimpan</span>
                </div>
            </div>
        </aside>

        <!-- ══════════ STAGE ══════════ -->
        <div class="tab-content">

            <!-- ─────────── TAB: WEBSITE ─────────── -->
            <div class="tab-pane fade show active" id="tab-website">
                <div class="pane-head">
                    <div class="pane-kicker">Website</div>
                    <h4 class="pane-title">Pengaturan Website</h4>
                    <p class="pane-desc">Identitas aplikasi dan institusi yang tampil di header, tab browser, dan footer.</p>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-building"></i></div>
                        <div>
                            <h6>Identitas Aplikasi</h6>
                            <p>Informasi dasar yang ditampilkan ke seluruh pengguna.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="appName">Nama Aplikasi</label>
                            <p class="s-desc">Tampil di header halaman, tab browser, dan logo sidebar.</p>
                        </div>
                        <div class="s-ctrl">
                            <input type="text" class="form-control" id="appName" name="settings[app_name]" value="<?= esc(settingVal($groupedSettings, 'general', 'app_name', 'Sistem Ujian')) ?>"  maxlength="60">
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="appDesc">Deskripsi Aplikasi</label>
                            <p class="s-desc">Penjelasan singkat tentang sistem, muncul di footer halaman.</p>
                        </div>
                        <div class="s-ctrl">
                            <input type="text" class="form-control" id="appDesc" name="settings[app_description]" value="<?= esc(settingVal($groupedSettings, 'general', 'app_description', 'Aplikasi Ujian Berbasis Komputer (CBT)')) ?>"  maxlength="120">
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="siteAuthor">Nama Institusi / Penyelenggara</label>
                            <p class="s-desc">Nama lembaga penyelenggara untuk hak cipta di footer.</p>
                        </div>
                        <div class="s-ctrl">
                            <input type="text" class="form-control" id="siteAuthor" name="settings[site_author]" value="<?= esc(settingVal($groupedSettings, 'general', 'site_author', 'Sekolah/Lembaga')) ?>"  maxlength="80">
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="timezone">Zona Waktu</label>
                            <p class="s-desc">Zona waktu yang digunakan untuk semua timestamp di sistem.</p>
                        </div>
                        <div class="s-ctrl">
                            <select class="form-select" id="timezone" name="settings[timezone]" >
                                <option value="Asia/Jakarta" <?= settingVal($groupedSettings, 'general', 'timezone') == 'Asia/Jakarta' ? 'selected' : '' ?>>WIB (Asia/Jakarta)</option>
                                <option value="Asia/Makassar" <?= settingVal($groupedSettings, 'general', 'timezone') == 'Asia/Makassar' ? 'selected' : '' ?>>WITA (Asia/Makassar)</option>
                                <option value="Asia/Jayapura" <?= settingVal($groupedSettings, 'general', 'timezone') == 'Asia/Jayapura' ? 'selected' : '' ?>>WIT (Asia/Jayapura)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─────────── TAB: LOGO & TAMPILAN ─────────── -->
            <div class="tab-pane fade" id="tab-appearance">
                <div class="pane-head">
                    <div class="pane-kicker">Logo &amp; Tampilan</div>
                    <h4 class="pane-title">Tampilan Halaman Peserta</h4>
                    <p class="pane-desc">Kustomisasi visual untuk halaman login dan ujian. Perubahan berlaku real-time pada pratinjau.</p>
                </div>

                <div class="row g-4">
                    <div class="col-xl-7">
                        <div class="s-panel">
                            <div class="s-panel-head">
                                <div class="ph-icon"><i class="bi bi-image"></i></div>
                                <div>
                                    <h6>Gambar &amp; Logo</h6>
                                    <p>Aset yang dipakai di seluruh halaman publik.</p>
                                </div>
                            </div>
                            <div class="s-row">
                                <div class="s-grid-3 w-100">
                                    <div class="upload-tile">
                                        <?php $logoPath = settingVal($groupedSettings, 'logo', 'app_logo'); ?>
                                        <div class="ut-preview">
                                            <?php if ($logoPath): ?>
                                                <img src="<?= base_url($logoPath) ?>" alt="Logo utama">
                                            <?php else: ?>
                                                <div class="ut-placeholder"><i class="bi bi-image"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ut-name">Logo Utama</div>
                                        <div class="ut-desc">Tampil di header aplikasi &amp; sidebar</div>
                                        <input type="file" class="form-control" name="app_logo" accept="image/png, image/jpeg, image/jpg, image/svg+xml">
                                    </div>
                                    <div class="upload-tile">
                                        <?php $faviconPath = settingVal($groupedSettings, 'logo', 'app_favicon'); ?>
                                        <div class="ut-preview">
                                            <?php if ($faviconPath): ?>
                                                <img src="<?= base_url($faviconPath) ?>" alt="Favicon">
                                            <?php else: ?>
                                                <div class="ut-placeholder"><i class="bi bi-star"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ut-name">Favicon</div>
                                        <div class="ut-desc">Icon tab browser (.ico, .png, .svg)</div>
                                        <input type="file" class="form-control" name="app_favicon" accept="image/x-icon, image/png, image/jpeg, image/jpg, image/svg+xml">
                                    </div>
                                    <div class="upload-tile">
                                        <?php $bgPath = settingVal($groupedSettings, 'logo', 'login_background'); ?>
                                        <div class="ut-preview">
                                            <?php if ($bgPath): ?>
                                                <img src="<?= base_url($bgPath) ?>" alt="Background login">
                                            <?php else: ?>
                                                <div class="ut-placeholder"><i class="bi bi-card-image"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ut-name">Background Login</div>
                                        <div class="ut-desc">Gambar latar halaman login</div>
                                        <input type="file" class="form-control" name="login_background" accept="image/png, image/jpeg, image/jpg">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="s-panel">
                            <div class="s-panel-head">
                                <div class="ph-icon"><i class="bi bi-palette"></i></div>
                                <div>
                                    <h6>Theme Customizer</h6>
                                    <p>Warna dan tipografi halaman ujian peserta.</p>
                                </div>
                            </div>
                            <?php
                                $pColor = settingVal($groupedSettings, 'logo', 'primary_color', '#0d6efd');
                                $sColor = settingVal($groupedSettings, 'logo', 'secondary_color', '#f4f6f9');
                                $tColor = settingVal($groupedSettings, 'logo', 'text_color', '#212529');
                            ?>
                            <div class="s-row">
                                <div class="s-main">
                                    <label class="s-label" for="customFont">Jenis Huruf</label>
                                    <p class="s-desc">Font yang digunakan di halaman ujian peserta.</p>
                                </div>
                                <div class="s-ctrl">
                                    <select class="form-select" id="customFont" name="settings[font_family]" >
                                        <?php $currentFont = settingVal($groupedSettings, 'logo', 'font_family', 'Inter'); ?>
                                        <option value="Inter" <?= $currentFont=='Inter'?'selected':'' ?>>Inter (Clean)</option>
                                        <option value="Outfit" <?= $currentFont=='Outfit'?'selected':'' ?>>Outfit (Modern)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="s-row">
                                <div class="s-main">
                                    <label class="s-label" for="customRadius">Lengkungan Sudut</label>
                                    <p class="s-desc">Seberapa bulat sudut elemen UI di halaman peserta.</p>
                                </div>
                                <div class="s-ctrl range-wrap">
                                    <input type="range" class="form-range" id="customRadius" name="settings[border_radius]" min="0" max="24" value="<?= esc(settingVal($groupedSettings, 'logo', 'border_radius', '8')) ?>" >
                                </div>
                                <div class="s-ctrl">
                                    <span id="radiusValue" class="range-value"><?= esc(settingVal($groupedSettings, 'logo', 'border_radius', '8')) ?>px</span>
                                </div>
                            </div>
                            <div class="s-row stack">
                                <div class="s-grid-3 w-100">
                                    <div class="s-field">
                                        <label for="customPrimary">Warna Utama</label>
                                        <div class="swatch-row">
                                            <input type="color" id="customPrimary" class="form-control form-control-color" name="settings[primary_color]" value="<?= esc($pColor) ?>">
                                            <input type="text" class="hex" id="primaryHex" value="<?= esc($pColor) ?>" disabled>
                                        </div>
                                    </div>
                                    <div class="s-field">
                                        <label for="customSecondary">Warna Latar</label>
                                        <div class="swatch-row">
                                            <input type="color" id="customSecondary" class="form-control form-control-color" name="settings[secondary_color]" value="<?= esc($sColor) ?>">
                                            <input type="text" class="hex" id="secondaryHex" value="<?= esc($sColor) ?>" disabled>
                                        </div>
                                    </div>
                                    <div class="s-field">
                                        <label for="customTextColor">Warna Teks</label>
                                        <div class="swatch-row">
                                            <input type="color" id="customTextColor" class="form-control form-control-color" name="settings[text_color]" value="<?= esc($tColor) ?>">
                                            <input type="text" class="hex" id="textHex" value="<?= esc($tColor) ?>" disabled>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-5">
                        <div class="position-sticky" style="top: calc(var(--topbar-height) + 1.5rem);">
                            <div class="preview-frame">
                                <div class="pf-bar">
                                    <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                                    <span>pratinjau langsung</span>
                                </div>
                                <div class="pf-body">
                                    <div id="livePreviewBox" class="border overflow-hidden" style="border-radius: 12px; --bs-primary: <?= esc($pColor) ?>; --bs-secondary: <?= esc($sColor) ?>; --custom-font: '<?= esc($currentFont) ?>', sans-serif; --custom-radius: <?= esc(settingVal($groupedSettings, 'logo', 'border_radius', '8')) ?>px; --custom-text: <?= esc($tColor) ?>; color: var(--custom-text);">
                                        <div id="previewNavbar" class="d-flex justify-content-between align-items-center px-3 py-2 text-white" style="background: var(--bs-primary); transition: background 0.3s;">
                                            <div class="d-flex align-items-center gap-2 fw-bold" style="font-size:0.85rem; font-family: var(--custom-font);">
                                                <i class="bi bi-mortarboard-fill"></i> Sistem Ujian
                                            </div>
                                            <div style="font-size: 0.75rem; font-family: var(--custom-font);">
                                                <i class="bi bi-person-circle"></i> Siswa
                                            </div>
                                        </div>
                                        <div id="previewBody" class="p-3" style="background: var(--bs-secondary); min-height: 200px; transition: background 0.3s;">
                                            <div class="bg-white p-3 shadow-sm mb-2" style="border-radius: var(--custom-radius); transition: border-radius 0.3s;">
                                                <h6 class="fw-bold mb-1" style="font-size:0.8rem; font-family: var(--custom-font); color: var(--custom-text);">Ujian Akhir Semester</h6>
                                                <p class="small mb-2" style="font-family: var(--custom-font); opacity: 0.7; font-size:0.7rem;">Durasi: 90 Menit</p>
                                                <button type="button" class="btn btn-sm text-white w-100 fw-bold border-0" style="background: var(--bs-primary); border-radius: var(--custom-radius); font-family: var(--custom-font); font-size:0.75rem;">Mulai Ujian</button>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-muted small mt-2 mb-0 text-center"><i class="bi bi-info-circle me-1"></i>Perubahan tampil secara real-time</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─────────── TAB: UJIAN ─────────── -->
            <div class="tab-pane fade" id="tab-exam">
                <div class="pane-head">
                    <div class="pane-kicker">Ujian</div>
                    <h4 class="pane-title">Pengaturan Ujian</h4>
                    <p class="pane-desc">Nilai default yang dipakai saat membuat ujian baru. Setiap ujian tetap bisa menimpa pengaturan ini.</p>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <h6>Durasi &amp; Waktu</h6>
                            <p>Kontrol waktu pengerjaan ujian.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="defaultDuration">Durasi Default Ujian</label>
                            <p class="s-desc">Durasi ujian default jika tidak diatur manual saat membuat ujian baru.</p>
                        </div>
                        <div class="s-ctrl">
                            <div class="s-unit">
                                <input type="number" class="form-control text-center" id="defaultDuration" name="settings[default_duration]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_duration', '90')) ?>" min="5" max="600" style="width: 120px;">
                                <span class="unit">menit</span>
                            </div>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="autoSubmit">Auto-Submit Saat Waktu Habis</label>
                            <p class="s-desc">Otomatis kumpulkan ujian ketika timer mencapai nol. Jawaban yang sudah tersimpan akan dinilai.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="autoSubmit" name="settings[auto_submit]" value="1" <?= settingChecked($groupedSettings, 'exam', 'auto_submit') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-star"></i></div>
                        <div>
                            <h6>Skor &amp; Penilaian</h6>
                            <p>Aturan perhitungan nilai dan kelulusan.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="passingGrade">Passing Grade (KKM) Default</label>
                            <p class="s-desc">Nilai minimum untuk dinyatakan lulus. Ujian bisa override nilai ini.</p>
                        </div>
                        <div class="s-ctrl">
                            <div class="s-unit">
                                <input type="number" class="form-control text-center" id="passingGrade" name="settings[default_passing_grade]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_passing_grade', '75')) ?>" min="0" max="100" style="width: 110px;">
                                <span class="unit">%</span>
                            </div>
                        </div>
                    </div>
                    <div class="s-row stack">
                        <div class="s-grid-3 w-100">
                            <div class="s-field">
                                <label for="scoreRight">Poin Jawaban Benar</label>
                                <input type="number" class="form-control" id="scoreRight" name="settings[default_score_right]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_score_right', '1')) ?>" min="0" max="100" step="0.5">
                            </div>
                            <div class="s-field">
                                <label for="scoreWrong">Poin Jawaban Salah</label>
                                <input type="number" class="form-control" id="scoreWrong" name="settings[default_score_wrong]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_score_wrong', '0')) ?>" min="-100" max="0" step="0.5">
                            </div>
                            <div class="s-field">
                                <label for="scoreUnanswered">Poin Tidak Dijawab</label>
                                <input type="number" class="form-control" id="scoreUnanswered" name="settings[default_score_unanswered]" value="<?= esc(settingVal($groupedSettings, 'exam', 'default_score_unanswered', '0')) ?>" min="-100" max="0" step="0.5">
                            </div>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="partialScore">Skor Parsial (Pilihan Ganda Kompleks)</label>
                            <p class="s-desc">Siswa mendapat poin sebagian untuk setiap opsi benar yang dipilih pada soal multiple-correct. Jika nonaktif, harus memilih semua jawaban benar untuk poin penuh.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="partialScore" name="settings[mcma_partial_score]" value="1" <?= settingChecked($groupedSettings, 'exam', 'mcma_partial_score') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-shuffle"></i></div>
                        <div>
                            <h6>Pengacakan Soal</h6>
                            <p>Mengurangi peluang kecurangan antar peserta.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="randomQuestions">Acak Urutan Soal Secara Default</label>
                            <p class="s-desc">Setiap siswa mendapat urutan soal yang berbeda. Bisa di-override per ujian.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="randomQuestions" name="settings[default_random_questions]" value="1" <?= settingChecked($groupedSettings, 'exam', 'default_random_questions') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="randomAnswers">Acak Urutan Jawaban Secara Default</label>
                            <p class="s-desc">Opsi jawaban pilihan ganda diacak urutannya. Bisa di-override per ujian.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="randomAnswers" name="settings[default_random_answers]" value="1" <?= settingChecked($groupedSettings, 'exam', 'default_random_answers') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-eye"></i></div>
                        <div>
                            <h6>Setelah Ujian Selesai</h6>
                            <p>Informasi yang boleh dilihat peserta pasca pengumpulan.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="showScore">Tampilkan Skor Setelah Selesai</label>
                            <p class="s-desc">Siswa langsung melihat skor mereka setelah mengumpulkan ujian.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="showScore" name="settings[show_score_after_exam]" value="1" <?= settingChecked($groupedSettings, 'exam', 'show_score_after_exam') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="showCorrect">Tampilkan Kunci Jawaban</label>
                            <p class="s-desc">Siswa bisa melihat jawaban yang benar saat review hasil ujian.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="showCorrect" name="settings[show_correct_answers]" value="1" <?= settingChecked($groupedSettings, 'exam', 'show_correct_answers') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="allowReview">Izinkan Review Ujian</label>
                            <p class="s-desc">Siswa bisa membuka kembali halaman review untuk melihat soal dan jawaban mereka.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="allowReview" name="settings[allow_review]" value="1" <?= settingChecked($groupedSettings, 'exam', 'allow_review') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─────────── TAB: KEAMANAN ─────────── -->
            <div class="tab-pane fade" id="tab-security">
                <div class="pane-head">
                    <div class="pane-kicker">Keamanan</div>
                    <h4 class="pane-title">Keamanan &amp; Anti-Cheat</h4>
                    <p class="pane-desc">Proteksi ujian dari kecurangan dan kontrol akses ke sistem.</p>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-shield-check"></i></div>
                        <div>
                            <h6>Proteksi Dasar</h6>
                            <p>Lapisan pertama pengamanan sesi ujian.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="antiCheatToggle">Deteksi Kecurangan Sederhana</label>
                            <p class="s-desc">Kunci halaman ujian jika peserta berpindah tab/aplikasi lain atau keluar dari mode fullscreen.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="antiCheatToggle" name="settings[anti_cheat_enabled]" value="1" <?= settingChecked($groupedSettings, 'security', 'anti_cheat_enabled') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="multiLoginToggle">Cegah Multi-Login</label>
                            <p class="s-desc">Satu akun hanya bisa login di satu perangkat. Login di tempat lain akan ditolak selama sesi aktif.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="multiLoginToggle" name="settings[prevent_multi_login]" value="1" <?= settingChecked($groupedSettings, 'security', 'prevent_multi_login') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="forceLogoutToggle">Paksa Logout &amp; Kunci Akun</label>
                            <p class="s-desc">Pelanggaran langsung menyebabkan logout paksa dan akun dikunci. Memerlukan reset manual oleh admin.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="forceLogoutToggle" name="settings[anti_cheat_force_logout]" value="1" <?= settingChecked($groupedSettings, 'security', 'anti_cheat_force_logout') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="s-panel" id="acPanel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-sliders2"></i></div>
                        <div>
                            <h6>Konfigurasi Anti-Cheat</h6>
                            <p>Toleransi, penalti, dan pesan yang ditampilkan saat pelanggaran.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-grid-3 w-100">
                            <div class="s-field">
                                <label for="maxStrikes">Toleransi Pelanggaran</label>
                                <input type="number" class="form-control" id="maxStrikes" name="settings[max_cheat_strikes]" value="<?= esc(settingVal($groupedSettings, 'security', 'max_cheat_strikes', '2')) ?>" min="1" max="10">
                            </div>
                            <div class="s-field">
                                <label for="suspendTimer">Waktu Suspend</label>
                                <div class="s-unit">
                                    <input type="number" class="form-control" id="suspendTimer" name="settings[suspend_timer_seconds]" value="<?= esc(settingVal($groupedSettings, 'security', 'suspend_timer_seconds', '180')) ?>" min="10" max="600">
                                    <span class="unit">detik</span>
                                </div>
                            </div>
                            <div class="s-field">
                                <label for="maxConcurrent">Maksimal Slot Ujian</label>
                                <div class="s-unit">
                                    <input type="number" class="form-control" id="maxConcurrent" name="settings[max_concurrent_connections]" value="<?= esc(settingVal($groupedSettings, 'security', 'max_concurrent_connections', '1000')) ?>" min="1" max="10000">
                                    <span class="unit">user</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="s-row stack">
                        <div class="s-grid-2 w-100">
                            <div class="s-field">
                                <label for="antiCheatTitle">Judul Peringatan</label>
                                <input type="text" class="form-control" id="antiCheatTitle" name="settings[anti_cheat_title]" value="<?= esc(settingVal($groupedSettings, 'security', 'anti_cheat_title', 'Maaf ya')) ?>">
                            </div>
                            <div class="s-field">
                                <label for="antiCheatMessage">Isi Pesan</label>
                                <input type="text" class="form-control" id="antiCheatMessage" name="settings[anti_cheat_message]" value="<?= esc(settingVal($groupedSettings, 'security', 'anti_cheat_message', 'Halaman ujian sementara waktu ditutup karena kami mendeteksi adanya pelanggaran pada akun Anda. Halaman ujian akan kembali terbuka setelah hitungan mundur selesai.')) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label">Logo Peringatan (SVG)</label>
                            <p class="s-desc">Logo SVG yang tampil di atas pesan peringatan kecurangan.</p>
                        </div>
                        <div class="s-ctrl">
                            <?php $cheatLogoPath = settingVal($groupedSettings, 'security', 'anti_cheat_logo'); ?>
                            <?php if ($cheatLogoPath): ?>
                                <div class="p-2 rounded shadow-sm" style="background: var(--bg-soft);">
                                    <img src="<?= base_url($cheatLogoPath) ?>" alt="Logo peringatan" style="max-height: 44px;">
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control form-control-sm" name="anti_cheat_logo" accept="image/svg+xml" style="max-width: 240px;">
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-people"></i></div>
                        <div>
                            <h6>Kapasitas &amp; Antrean</h6>
                            <p>Manajemen beban server saat ujian berlangsung.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="maxConcurrentSlots">Maksimal Slot Ujian</label>
                            <p class="s-desc">Batas jumlah user login bersamaan.</p>
                        </div>
                        <div class="s-ctrl">
                            <div class="s-unit">
                                <input type="number" class="form-control text-center" id="maxConcurrentSlots" name="settings[max_concurrent_connections]" value="<?= esc(settingVal($groupedSettings, 'security', 'max_concurrent_connections', '1000')) ?>" min="1" max="10000" style="width: 120px;">
                                <span class="unit">user</span>
                            </div>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="queueMessage">Pesan Antrean Slot</label>
                            <p class="s-desc">Pesan yang tampil pada peserta yang sedang menunggu slot kosong.</p>
                        </div>
                    </div>
                    <div class="s-row stack">
                        <input type="text" class="form-control" id="queueMessage" name="settings[queue_waiting_message]" value="<?= esc(settingVal($groupedSettings, 'security', 'queue_waiting_message', 'Server sedang penuh. Anda berada dalam antrean. Mohon tunggu tanpa menutup halaman ini.')) ?>">
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-tools"></i></div>
                        <div>
                            <h6>Akses Sistem</h6>
                            <p>Kunci akses teknis dan mode pemeliharaan.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="installerLockToggle">Kunci Akses Web Installer</label>
                            <p class="s-desc">Cegah akses ke <code>/install</code>. Nonaktifkan hanya saat perlu rekonfigurasi database.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <?php $installerLocked = env('INSTALLER_LOCKED', false); ?>
                                <input type="checkbox" id="installerLockToggle" name="settings[installer_locked]" value="1" <?= $installerLocked ? 'checked' : '' ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="maintenanceToggle">Aktifkan Mode Maintenance</label>
                            <p class="s-desc">Semua siswa dialihkan ke halaman maintenance. Admin tetap bisa login normal.</p>
                        </div>
                        <div class="s-ctrl">
                            <label class="toggle">
                                <input type="checkbox" id="maintenanceToggle" name="settings[maintenance_mode]" value="1" <?= settingChecked($groupedSettings, 'security', 'maintenance_mode') ?>>
                                <span class="track"></span>
                            </label>
                        </div>
                    </div>
                    <div class="s-row stack" id="maintenanceMsgRow" style="display: none;">
                        <label class="form-label small text-muted mb-0" for="maintenanceMessage">Pesan Maintenance</label>
                        <textarea class="form-control" id="maintenanceMessage" name="settings[maintenance_message]" rows="2" placeholder="Sistem sedang dalam pemeliharaan. Silakan coba lagi nanti."><?= esc(settingVal($groupedSettings, 'security', 'maintenance_message', 'Sistem sedang dalam pemeliharaan. Silakan coba lagi beberapa saat lagi.')) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ─────────── TAB: SISTEM ─────────── -->
            <div class="tab-pane fade" id="tab-system">
                <div class="pane-head">
                    <div class="pane-kicker">Sistem</div>
                    <h4 class="pane-title">Sistem &amp; Maintenance</h4>
                    <p class="pane-desc">Informasi server, status layanan, dan alat pemeliharaan.</p>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-broadcast"></i></div>
                        <div>
                            <h6>Real-Time</h6>
                            <p>Kanal WebSocket untuk halaman ujian, EXAMBRO, dan dashboard pengawas.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label" for="websocket_url">URL WebSocket</label>
                            <p class="s-desc">
                                <strong>Kosongkan</strong> agar diturunkan otomatis dari alamat aplikasi.
                                Isi hanya kalau server WebSocket berada di host atau path yang berbeda.
                                Nilai yang dipakai saat ini:
                                <code><?= esc(\App\Libraries\WebSocketUrl::resolve()) ?></code>
                            </p>
                        </div>
                        <div class="s-ctrl">
                            <input type="text" class="form-control" id="websocket_url" name="settings[websocket_url]"
                                   value="<?= esc(settingVal($groupedSettings, 'system', 'websocket_url', '')) ?>"
                                   placeholder="<?= esc(\App\Libraries\WebSocketUrl::derive((string) base_url())) ?>"
                                   maxlength="255">
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-speedometer2"></i></div>
                        <div>
                            <h6>Status Layanan</h6>
                            <p>Kesehatan komponen yang menopang aplikasi.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="sys-grid w-100">
                            <div class="sys-tile">
                                <div class="st-value" id="infoPhp"><span class="status-dot loading"></span>...</div>
                                <div class="st-label"><i class="bi bi-braces"></i> PHP Version</div>
                            </div>
                            <div class="sys-tile">
                                <div class="st-value" id="infoCi"><span class="status-dot loading"></span>...</div>
                                <div class="st-label"><i class="bi bi-stack"></i> CodeIgniter</div>
                            </div>
                            <div class="sys-tile">
                                <div class="st-value" id="infoDb"><span class="status-dot loading"></span>...</div>
                                <div class="st-label"><i class="bi bi-database"></i> Database</div>
                            </div>
                            <div class="sys-tile">
                                <div class="st-value" id="infoRedis"><span class="status-dot loading"></span>...</div>
                                <div class="st-label"><i class="bi bi-lightning-charge"></i> Redis</div>
                            </div>
                            <div class="sys-tile">
                                <div class="st-value" id="infoSessions"><span class="status-dot loading"></span>...</div>
                                <div class="st-label"><i class="bi bi-people"></i> Sesi Aktif</div>
                            </div>
                            <div class="sys-tile">
                                <div class="st-value" id="infoDisk"><span class="status-dot loading"></span>...</div>
                                <div class="st-label"><i class="bi bi-hdd"></i> Disk Usage</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-wrench-adjustable"></i></div>
                        <div>
                            <h6>Aksi Sistem</h6>
                            <p>Operasi pemeliharaan yang memengaruhi seluruh aplikasi.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="s-main">
                            <label class="s-label">Bersihkan Cache</label>
                            <p class="s-desc">Hapus semua cache CI dan Redis. Berguna setelah update atau perubahan konfigurasi.</p>
                        </div>
                        <div class="s-ctrl">
                            <button type="button" class="btn btn-ghost fw-semibold" id="btnClearCache">
                                <i class="bi bi-trash3 me-1"></i> Bersihkan
                            </button>
                        </div>
                    </div>
                    <div class="s-row" style="border-top: 1px dashed var(--border-color);">
                        <div class="s-main">
                            <label class="s-label" style="color: var(--danger);">Reset ke Pengaturan Default</label>
                            <p class="s-desc">Kembalikan semua pengaturan ke nilai awal instalasi. Tindakan ini tidak bisa dibatalkan.</p>
                        </div>
                        <div class="s-ctrl">
                            <button type="button" class="btn btn-outline-danger fw-semibold" id="btnResetSettings">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Semua
                            </button>
                        </div>
                    </div>
                </div>

                <div class="s-panel">
                    <div class="s-panel-head">
                        <div class="ph-icon"><i class="bi bi-hexagon"></i></div>
                        <div>
                            <h6>Tentang Aplikasi</h6>
                            <p>Informasi rilis dan lingkungan berjalan.</p>
                        </div>
                    </div>
                    <div class="s-row">
                        <div class="d-flex align-items-center gap-3 w-100">
                            <div class="setting-card-icon bg-purple" style="width: 52px; height: 52px; font-size: 1.5rem; border-radius: 14px; background: var(--brand-soft); color: var(--brand-color); display: grid; place-items: center; flex-shrink: 0;">
                                <i class="bi bi-hexagon-fill"></i>
                            </div>
                            <div class="s-main">
                                <h6 class="fw-bold mb-1" style="font-size: 0.95rem;"><?= esc(settingVal($groupedSettings, 'general', 'app_name', 'Sistem Ujian CBT')) ?></h6>
                                <p class="s-desc mb-1">Versi <?= esc(settingVal($groupedSettings, 'general', 'app_version', '1.0.0')) ?> &middot; PHP <?= PHP_VERSION ?> &middot; CodeIgniter <?= \CodeIgniter\CodeIgniter::CI_VERSION ?></p>
                                <p class="s-desc mb-0">Aplikasi ujian berbasis komputer dengan fitur bank soal, anti-cheat, dan scoring otomatis.</p>
                            </div>
                        </div>
                    </div>
        </div>
    </div>

    <!-- ══════════ FLOATING SAVE BAR ══════════ -->
    <div class="savebar" id="saveBar" role="status" aria-live="polite">
        <span class="sb-status"><span class="dot"></span><span id="saveStatusText">Ada perubahan yang belum disimpan</span></span>
        <button type="submit" class="btn btn-accent" id="btnSave">
            <i class="bi bi-check2 me-1"></i> Simpan Perubahan
        </button>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function() {
    const formEl = document.getElementById('settingsForm');
    const saveBar = document.getElementById('saveBar');
    const btnSave = document.getElementById('btnSave');

    // ── Rail tab switching ──
    // Rail is a custom component (not Bootstrap .nav), so tabs are handled
    // manually instead of relying on data-bs-toggle="pill".
    const railButtons = document.querySelectorAll('.rail-item');
    railButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const pane = document.querySelector(this.dataset.bsTarget);
            if (!pane) return;
            document.querySelectorAll('.rail-item').forEach(function(b) {
                b.classList.toggle('active', b === btn);
            });
            document.querySelectorAll('.tab-pane').forEach(function(p) {
                p.classList.toggle('active', p === pane);
                p.classList.toggle('show', p === pane);
            });
            btn.dispatchEvent(new Event('shown.bs.tab'));
        });
    });

    // ── Dirty tracking: row highlight + rail dot + save bar ──
    let dirty = false;
    function markDirty(target) {
        if (!target) return;
        if (!dirty) {
            dirty = true;
            saveBar.classList.add('visible');
        }
        const row = target.closest('.s-row');
        if (row) row.classList.add('is-dirty');
        const pane = target.closest('.tab-pane');
        if (pane) {
            const railItem = document.querySelector('.rail-item[data-bs-target="#' + pane.id + '"]');
            if (railItem) railItem.classList.add('dirty');
        }
    }
    formEl.addEventListener('input', function(e) {
        if (e.target.matches('input, select, textarea')) markDirty(e.target);
    });
    formEl.addEventListener('change', function(e) {
        if (e.target.matches('input[type="checkbox"], input[type="file"]')) markDirty(e.target);
    });

    // ── Save button feedback ──
    formEl.addEventListener('submit', function() {
        btnSave.disabled = true;
        btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyimpan...';
    });

    // ── Conditional blocks ──
    const acToggle = document.getElementById('antiCheatToggle');
    const acPanel = document.getElementById('acPanel');
    if (acToggle && acPanel) {
        const syncAC = () => acPanel.classList.toggle('d-none', !acToggle.checked);
        acToggle.addEventListener('change', syncAC);
        syncAC();
    }
    const mntToggle = document.getElementById('maintenanceToggle');
    const mntRow = document.getElementById('maintenanceMsgRow');
    if (mntToggle && mntRow) {
        const syncMnt = () => mntRow.style.display = mntToggle.checked ? '' : 'none';
        mntToggle.addEventListener('change', syncMnt);
        syncMnt();
    }

    // ── Color Picker Sync ──
    document.querySelectorAll('input[type="color"]').forEach(function(picker) {
        picker.addEventListener('input', function() {
            const hexInput = this.parentElement.querySelector('input[type="text"]');
            if (hexInput) hexInput.value = this.value;
            updatePreview();
        });
    });

    // ── Radius Slider ──
    const radiusInput = document.getElementById('customRadius');
    const radiusValue = document.getElementById('radiusValue');
    if (radiusInput && radiusValue) {
        radiusInput.addEventListener('input', function() {
            radiusValue.textContent = this.value + 'px';
            updatePreview();
        });
    }

    // ── Font Selector ──
    const fontSelect = document.getElementById('customFont');
    if (fontSelect) fontSelect.addEventListener('change', updatePreview);

    // ── Live Preview ──
    const previewBox = document.getElementById('livePreviewBox');
    function updatePreview() {
        if (!previewBox) return;
        const font = fontSelect ? fontSelect.value : 'Inter';
        previewBox.style.setProperty('--custom-font', font + ', sans-serif');
        const radius = radiusInput ? radiusInput.value + 'px' : '8px';
        previewBox.style.setProperty('--custom-radius', radius);
        const primary = document.getElementById('customPrimary');
        const secondary = document.getElementById('customSecondary');
        const textColor = document.getElementById('customTextColor');
        if (primary) previewBox.style.setProperty('--bs-primary', primary.value);
        if (secondary) previewBox.style.setProperty('--bs-secondary', secondary.value);
        if (textColor) previewBox.style.setProperty('--custom-text', textColor.value);
    }

    // ── System Info (AJAX) ──
    let sysLoaded = false;
    document.querySelector('[data-bs-target="#tab-system"]')?.addEventListener('shown.bs.tab', function() {
        if (sysLoaded) return;
        sysLoaded = true;
        fetch('<?= base_url('/admin/settings/system-info') ?>', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('infoPhp').innerHTML = '<span class="status-dot online"></span>' + data.php_version;
            document.getElementById('infoCi').innerHTML = '<span class="status-dot online"></span>' + data.ci_version;
            document.getElementById('infoDb').innerHTML = '<span class="status-dot ' + (data.db_connected ? 'online' : 'offline') + '"></span>' + (data.db_connected ? 'Connected' : 'Error');
            document.getElementById('infoRedis').innerHTML = '<span class="status-dot ' + (data.redis_connected ? 'online' : 'offline') + '"></span>' + (data.redis_connected ? 'Connected' : 'Offline');
            document.getElementById('infoSessions').innerHTML = '<span class="status-dot online"></span>' + data.active_sessions;
            document.getElementById('infoDisk').innerHTML = '<span class="status-dot online"></span>' + data.disk_usage;
        })
        .catch(() => {
            document.querySelectorAll('.sys-grid .st-value').forEach(el => {
                el.innerHTML = '<span class="status-dot offline"></span>Error';
            });
        });
    });

    // ── Clear Cache ──
    document.getElementById('btnClearCache')?.addEventListener('click', function() {
        Swal.fire({
            title: 'Bersihkan Cache?',
            text: 'Semua cache CI dan Redis akan dihapus.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, bersihkan',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#0e8a6b'
        }).then(result => {
            if (result.isConfirmed) {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Membersihkan...';
                fetch('<?= base_url('/admin/settings/clear-cache') ?>', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('input[name="<?= csrf_token() ?>"]').value
                    }
                })
                .then(r => r.json())
                .then(data => {
                    Swal.fire({
                        icon: data.status === 'success' ? 'success' : 'error',
                        title: data.status === 'success' ? 'Berhasil' : 'Gagal',
                        text: data.message,
                        confirmButtonColor: '#0e8a6b'
                    });
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-trash3 me-1"></i> Bersihkan';
                });
            }
        });
    });

    // ── Reset Settings ──
    document.getElementById('btnResetSettings')?.addEventListener('click', function() {
        Swal.fire({
            title: 'Reset Semua Pengaturan?',
            html: 'Semua pengaturan akan dikembalikan ke nilai default.<br><strong class="text-danger">Tindakan ini tidak bisa dibatalkan.</strong>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, reset semua',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#d64550'
        }).then(result => {
            if (result.isConfirmed) {
                const btn = this;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mereset...';
                fetch('<?= base_url('/admin/settings/reset') ?>', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('input[name="<?= csrf_token() ?>"]').value
                    }
                })
                .then(r => r.json())
                .then(data => {
                    Swal.fire({
                        icon: data.status === 'success' ? 'success' : 'error',
                        title: data.status === 'success' ? 'Berhasil' : 'Gagal',
                        text: data.message,
                        confirmButtonColor: '#0e8a6b'
                    }).then(() => {
                        if (data.status === 'success') window.location.reload();
                    });
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i> Reset Semua';
                });
            }
        });
    });

    updatePreview();
})();
</script>
<?= $this->endSection() ?>

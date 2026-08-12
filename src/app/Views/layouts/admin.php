<?php
// Tentukan waktu greeting
$hour = date('H');
if ($hour < 11) $greeting = 'Pagi';
elseif ($hour < 15) $greeting = 'Siang';
elseif ($hour < 18) $greeting = 'Sore';
else $greeting = 'Malam';

$settingModel = new \App\Models\SettingModel();
$appName = $settingModel->getValue('app_name', 'CBT-MF');
$appLogo = $settingModel->getValue('app_logo', '');
$appFavicon = $settingModel->getValue('app_favicon', '');
$faviconUrl = !empty($appFavicon) ? base_url($appFavicon) : (!empty($appLogo) ? base_url($appLogo) : base_url('favicon.ico'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $this->renderSection('page_title') ?> — <?= esc($appName) ?></title>
    <link rel="icon" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" href="<?= $faviconUrl ?>">
    <link href="<?= base_url('vendor/bootstrap/css/bootstrap.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('vendor/bootstrap-icons/font/bootstrap-icons.min.css?v=1.1') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/outfit.css?v=1.1') ?>" rel="stylesheet">
    <style>
        /* ════════════════════════════════════════════════════════════
           DESIGN TOKENS — "Ink & Moss"
           Dark ink rail · zinc canvas · single emerald accent
           ════════════════════════════════════════════════════════════ */
        :root {
            --sidebar-width: 268px;
            --sidebar-collapsed-width: 76px;
            --topbar-height: 72px;

            /* Light */
            --bg-body: #f4f5f6;
            --bg-surface: #ffffff;
            --bg-raised: #fbfcfc;
            --bg-soft: #eef0f1;
            --text-primary: #131517;
            --text-secondary: #5c626a;
            --text-tertiary: #9aa0a8;
            --border-color: #e6e8ea;
            --border-strong: #d7dade;
            --card-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 16px 40px -24px rgba(16, 24, 40, 0.12);

            /* Accent — desaturated emerald (no purple) */
            --brand-color: #0e8a6b;
            --brand-strong: #0b7259;
            --brand-soft: rgba(14, 138, 107, 0.10);
            --brand-softer: rgba(14, 138, 107, 0.06);
            --brand-ring: rgba(14, 138, 107, 0.35);

            --danger: #d64550;
            --danger-soft: rgba(214, 69, 80, 0.10);
            --warn: #b07d1f;
            --warn-soft: rgba(176, 125, 31, 0.12);
            --info: #1479a8;
            --info-soft: rgba(20, 121, 168, 0.10);
            --ok: #0e8a6b;
            --ok-soft: rgba(14, 138, 107, 0.10);

            /* Ink rail — light */
            --rail: #ffffff;
            --rail-line: rgba(16, 24, 40, 0.08);
            --rail-text: #5c626a;
            --rail-heading: #131517;
            --rail-label: rgba(19, 21, 23, 0.42);
            --rail-hover: rgba(14, 138, 107, 0.06);
            --rail-active: rgba(14, 138, 107, 0.10);
            --rail-active-text: #0b7259;
            --rail-accent: #0e8a6b;

            --mono: ui-monospace, "SF Mono", "JetBrains Mono", "Cascadia Mono", Menlo, Consolas, monospace;
            --ease: cubic-bezier(0.16, 1, 0.3, 1);
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
        }

        [data-theme="dark"] {
            --bg-body: #0a0b0d;
            --bg-surface: #121417;
            --bg-raised: #16191d;
            --bg-soft: #1b1e23;
            --text-primary: #eceef0;
            --text-secondary: #9ba2ab;
            --text-tertiary: #6a7078;
            --border-color: rgba(255, 255, 255, 0.07);
            --border-strong: rgba(255, 255, 255, 0.12);
            --card-shadow: 0 1px 2px rgba(0, 0, 0, 0.4), 0 20px 48px -28px rgba(0, 0, 0, 0.6);

            /* Ink rail — dark */
            --rail: #0b0d0f;
            --rail-line: rgba(255, 255, 255, 0.07);
            --rail-text: #7d838c;
            --rail-heading: #f2f4f6;
            --rail-label: rgba(255, 255, 255, 0.32);
            --rail-hover: rgba(255, 255, 255, 0.055);
            --rail-active: rgba(14, 138, 107, 0.14);
            --rail-active-text: #6fdfb8;
            --rail-accent: #34c79b;

            --brand-color: #34c79b;
            --brand-strong: #4ad4ab;
            --brand-soft: rgba(52, 199, 155, 0.13);
            --brand-softer: rgba(52, 199, 155, 0.07);
            --brand-ring: rgba(52, 199, 155, 0.4);

            --danger: #ef6b74;
            --danger-soft: rgba(239, 107, 116, 0.13);
            --warn: #d9a441;
            --warn-soft: rgba(217, 164, 65, 0.13);
            --info: #4aa6d4;
            --info-soft: rgba(74, 166, 212, 0.13);
            --ok: #34c79b;
            --ok-soft: rgba(52, 199, 155, 0.13);
        }

        /* ════════════════════════════════════════════════════════════
           BASE
           ════════════════════════════════════════════════════════════ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; }
        h1, h2, h3, h4, .display-1, .display-2, .display-3 { text-wrap: balance; }
        :focus-visible {
            outline: 3px solid var(--brand-ring);
            outline-offset: 2px;
            border-radius: 4px;
        }
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 2000;
            background: var(--brand-color);
            color: #fff;
            padding: 0.6rem 1.2rem;
            border-radius: 0 0 12px 0;
            font-weight: 600;
            text-decoration: none;
        }
        .skip-link:focus { left: 0; }
        body {
            font-family: 'Outfit', system-ui, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            overflow-x: hidden;
            transition: background 0.35s var(--ease), color 0.35s var(--ease);
            font-size: 0.95rem;
        }
        ::selection { background: var(--brand-soft); color: var(--brand-strong); }
        ::-webkit-scrollbar { width: 9px; height: 9px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 8px; border: 2px solid transparent; background-clip: content-box; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-tertiary); border: 2px solid transparent; background-clip: content-box; }

        .mono, .num { font-family: var(--mono); font-variant-numeric: tabular-nums; }

        /* Entrance choreography — staggered rise */
        @keyframes rise {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .rise { animation: rise 0.6s var(--ease) both; animation-delay: var(--d, 0ms); }

        @keyframes breathe {
            0%, 100% { transform: scale(1); opacity: 1; }
            50%      { transform: scale(0.78); opacity: 0.55; }
        }
        .breathe { animation: breathe 2.4s ease-in-out infinite; }

        @keyframes shimmer {
            0%   { background-position: -480px 0; }
            100% { background-position: 480px 0; }
        }

        /* ════════════════════════════════════════════════════════════
           SIDEBAR — the dark ink rail
           ════════════════════════════════════════════════════════════ */
        .sidebar {
            position: fixed;
            top: 0; left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            height: 100dvh;
            background:
                radial-gradient(120% 60% at 20% 0%, rgba(14, 138, 107, 0.06) 0%, transparent 55%),
                var(--rail);
            border-right: 1px solid var(--rail-line);
            z-index: 1040;
            display: flex;
            flex-direction: column;
            transition: width 0.35s var(--ease), transform 0.35s var(--ease);
        }
        .sidebar-brand {
            padding: 1.4rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            overflow: hidden;
            white-space: nowrap;
            border-bottom: 1px solid var(--rail-line);
            min-height: var(--topbar-height);
        }
        .sidebar-brand .icon-box {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, var(--rail-accent), #0e8a6b);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #06251c;
            font-size: 1.15rem;
            flex-shrink: 0;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.25), 0 6px 16px -6px rgba(14,138,107,0.55);
        }
        .sidebar-brand .brand-logo {
            width: 38px; height: 38px;
            object-fit: contain;
            border-radius: 12px;
            flex-shrink: 0;
        }
        .sidebar-brand h4.brand-name {
            margin: 0;
            font-weight: 650;
            font-size: 1.08rem;
            letter-spacing: -0.02em;
            color: var(--rail-heading);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 1.1rem 0.9rem 1.5rem;
            scrollbar-width: thin;
            scrollbar-color: var(--rail-line) transparent;
        }
        .sidebar-nav::-webkit-scrollbar { width: 5px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: var(--rail-line); border: none; }

        .nav-label {
            padding: 1.35rem 0.9rem 0.45rem;
            font-family: var(--mono);
            font-size: 0.62rem;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--rail-label);
            white-space: nowrap;
            overflow: hidden;
        }
        .nav-item {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.62rem 0.85rem;
            margin-bottom: 2px;
            color: var(--rail-text);
            text-decoration: none;
            font-size: 0.895rem;
            font-weight: 500;
            border-radius: 11px;
            white-space: nowrap;
            overflow: hidden;
            transition: background 0.2s var(--ease), color 0.2s var(--ease);
        }
        .nav-item::before {
            content: "";
            position: absolute;
            left: -1px; top: 50%;
            transform: translateY(-50%) scaleY(0);
            width: 3px; height: 55%;
            border-radius: 3px;
            background: var(--rail-accent);
            box-shadow: 0 0 10px rgba(52, 199, 155, 0.7);
            transition: transform 0.25s var(--ease);
        }
        .nav-item:hover {
            background: var(--rail-hover);
            color: var(--rail-heading);
        }
        .nav-item.active {
            background: var(--rail-active);
            color: var(--rail-active-text);
            font-weight: 600;
        }
        .nav-item.active::before { transform: translateY(-50%) scaleY(1); }
        .nav-item i { font-size: 1.08rem; flex-shrink: 0; opacity: 0.9; }
        .nav-item .nav-text {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Collapsed (desktop) */
        html.sidebar-collapsed body .sidebar,
        body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed-width); }
        html.sidebar-collapsed body .topbar,
        body.sidebar-collapsed .topbar { left: var(--sidebar-collapsed-width); }
        html.sidebar-collapsed body .main-content,
        body.sidebar-collapsed .main-content { margin-left: var(--sidebar-collapsed-width); }
        html.sidebar-collapsed body .sidebar-brand,
        body.sidebar-collapsed .sidebar-brand { padding: 1.4rem 0.7rem; justify-content: center; border-bottom-color: transparent; }
        html.sidebar-collapsed body .sidebar-brand .brand-name,
        body.sidebar-collapsed .brand-name,
        html.sidebar-collapsed body .nav-label,
        body.sidebar-collapsed .nav-label,
        html.sidebar-collapsed body .nav-item .nav-text,
        body.sidebar-collapsed .nav-item .nav-text { display: none; }
        html.sidebar-collapsed body .sidebar-nav,
        body.sidebar-collapsed .sidebar-nav { padding: 1.1rem 0.55rem; }
        html.sidebar-collapsed body .nav-item,
        body.sidebar-collapsed .nav-item { justify-content: center; padding: 0.68rem 0; gap: 0; }

        /* ════════════════════════════════════════════════════════════
           TOPBAR — glass strip
           ════════════════════════════════════════════════════════════ */
        .topbar {
            position: fixed;
            top: 0; left: var(--sidebar-width); right: 0;
            height: var(--topbar-height);
            background: var(--bg-body);
            background: color-mix(in srgb, var(--bg-body) 72%, transparent);
            backdrop-filter: blur(16px) saturate(1.4);
            -webkit-backdrop-filter: blur(16px) saturate(1.4);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            padding: 0 1.9rem;
            z-index: 1030;
            transition: left 0.35s var(--ease), background 0.3s ease;
        }
        .btn-toggle-sidebar {
            display: flex; align-items: center; justify-content: center;
            background: transparent;
            border: 1px solid var(--border-color);
            width: 40px; height: 40px;
            border-radius: 12px;
            font-size: 1.15rem;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s var(--ease);
        }
        .btn-toggle-sidebar:hover { color: var(--brand-color); border-color: var(--brand-ring); background: var(--brand-softer); }
        .btn-toggle-sidebar:active { transform: scale(0.96); }
        .topbar-icon-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: 12px;
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all 0.2s var(--ease);
            text-decoration: none;
            font-size: 1.05rem;
        }
        .topbar-icon-btn:hover { color: var(--brand-color); background: var(--brand-softer); border-color: var(--brand-ring); }

        /* ── Theme toggle ripple ── */
        #btnThemeToggle { overflow: hidden; }
        #btnThemeToggle .ripple {
            position: absolute;
            border-radius: 50%;
            transform: scale(0);
            background: var(--brand-color);
            opacity: 0.4;
            pointer-events: none;
            z-index: 0;
            animation: ripple-expand 0.6s var(--ease) forwards;
        }
        #btnThemeToggle .ripple ~ i { position: relative; z-index: 1; }
        #themeIcon {
            transition: transform 0.45s var(--ease);
            will-change: transform;
        }
        @keyframes ripple-expand {
            to { transform: scale(1); opacity: 0; }
        }

        /* ── Theme ripple: View Transitions API ──
           Matikan cross-fade default browser; hanya animasi clip-path
           circle (WAAPI) di pseudo-element yang berjalan. */
        ::view-transition-old(root),
        ::view-transition-new(root) {
            animation: none;
            mix-blend-mode: normal;
        }
        ::view-transition-old(root) { z-index: 1; }
        ::view-transition-new(root) { z-index: 9999; }
        .topbar-icon-btn:active { transform: scale(0.94); }
        .topbar-icon-btn .live-dot {
            position: absolute; top: 8px; right: 9px;
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--danger);
            box-shadow: 0 0 0 3px var(--bg-body);
        }
        .user-profile-btn {
            display: flex; align-items: center; gap: 0.65rem;
            background: none; border: none; padding: 0; cursor: pointer;
            border-radius: 14px;
        }
        .user-avatar {
            width: 40px; height: 40px;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--brand-color), #4ad4ab);
            color: #06251c;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.95rem;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.3);
        }
        .greeting-text h5 { letter-spacing: -0.02em; }
        .greeting-text .date-mono {
            font-family: var(--mono);
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            color: var(--text-tertiary);
            text-transform: uppercase;
        }

        /* ════════════════════════════════════════════════════════════
           MAIN CONTENT
           ════════════════════════════════════════════════════════════ */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--topbar-height);
            padding: 2.2rem 2.4rem 3rem;
            min-height: calc(100vh - var(--topbar-height));
            min-height: calc(100dvh - var(--topbar-height));
            transition: margin-left 0.35s var(--ease);
        }
        .main-content > * { max-width: 1500px; }
        footer.main-footer {
            border-top: 1px solid var(--border-color);
            margin-top: 3.5rem;
            padding-top: 1.4rem;
            color: var(--text-tertiary);
            font-size: 0.8rem;
        }

        /* ── Page head ─────────────────────────────────────── */
        .page-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1.5rem;
            margin-bottom: 1.8rem;
            flex-wrap: wrap;
        }
        .page-head .eyebrow {
            font-family: var(--mono);
            font-size: 0.66rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--brand-color);
            font-weight: 600;
            margin-bottom: 0.45rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .page-head .eyebrow::after {
            content: "";
            width: 26px; height: 1px;
            background: var(--brand-color);
            opacity: 0.4;
        }
        .page-head h1 {
            font-size: 1.72rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            line-height: 1.1;
            margin: 0;
        }
        .page-head .sub { color: var(--text-secondary); font-size: 0.92rem; margin-top: 0.5rem; max-width: 56ch; }
        .page-head .actions { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }

        /* ════════════════════════════════════════════════════════════
           SURFACES & COMPONENTS
           ════════════════════════════════════════════════════════════ */
        .card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--card-shadow);
            transition: background 0.3s, border-color 0.25s var(--ease), box-shadow 0.25s var(--ease);
        }
        .card:hover { border-color: var(--border-strong); }
        .card > .card-header { background: transparent; border-bottom: 1px solid var(--border-color); }
        .card > .card-footer { background: transparent; border-top: 1px solid var(--border-color); }

        /* Stat grid — logic-grouped with hairlines, not boxes */
        .statgrid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .statgrid .stat {
            padding: 1.5rem 1.7rem;
            position: relative;
        }
        .statgrid .stat + .stat { border-left: 1px solid var(--border-color); }
        .stat .stat-label {
            font-family: var(--mono);
            font-size: 0.66rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text-tertiary);
            display: flex; align-items: center; gap: 0.45rem;
        }
        .stat .stat-label i { font-size: 0.8rem; color: var(--brand-color); }
        .stat .stat-value {
            font-family: var(--mono);
            font-variant-numeric: tabular-nums;
            font-size: 2rem;
            font-weight: 600;
            letter-spacing: -0.03em;
            line-height: 1.15;
            margin-top: 0.4rem;
            color: var(--text-primary);
        }
        .stat .stat-delta {
            font-family: var(--mono);
            font-size: 0.7rem;
            margin-top: 0.5rem;
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.18rem 0.55rem;
            border-radius: 999px;
            background: var(--brand-softer);
            color: var(--brand-color);
        }

        /* Section label */
        .sect {
            font-family: var(--mono);
            font-size: 0.64rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--text-tertiary);
            margin: 2rem 0 0.85rem;
            display: flex; align-items: center; gap: 0.6rem;
        }
        .sect::after { content: ""; flex: 1; height: 1px; background: var(--border-color); }

        /* Buttons */
        .btn { border-radius: 11px; font-weight: 600; transition: all 0.2s var(--ease); }
        .btn:active { transform: scale(0.97); }
        .btn-accent, .btn-primary {
            --bs-btn-bg: var(--brand-color);
            --bs-btn-border-color: var(--brand-color);
            --bs-btn-hover-bg: var(--brand-strong);
            --bs-btn-hover-border-color: var(--brand-strong);
            --bs-btn-active-bg: var(--brand-strong);
            --bs-btn-active-border-color: var(--brand-strong);
            background: var(--brand-color);
            border-color: var(--brand-color);
            color: #fff;
        }
        .btn-accent:hover, .btn-primary:hover { background: var(--brand-strong); border-color: var(--brand-strong); color: #fff; }
        .btn-ghost {
            background: var(--bg-soft);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        .btn-ghost:hover { background: var(--border-color); color: var(--text-primary); }
        .btn-danger-soft { background: var(--danger-soft); border-color: transparent; color: var(--danger); }
        .btn-danger-soft:hover { background: var(--danger); border-color: var(--danger); color: #fff; }
        .btn-outline-danger { color: var(--danger); border-color: var(--danger); }
        .btn-outline-danger:hover { background: var(--danger); border-color: var(--danger); }
        .btn-outline-primary { color: var(--brand-color); border-color: var(--brand-strong); }
        .btn-outline-primary:hover { background: var(--brand-color); border-color: var(--brand-color); color: #fff; }
        .btn-outline-success { color: var(--ok); border-color: var(--ok); }
        .btn-outline-success:hover { background: var(--ok); border-color: var(--ok); }
        .btn-outline-warning { color: var(--warn); border-color: var(--warn); }
        .btn-outline-warning:hover { background: var(--warn); border-color: var(--warn); }
        .btn-outline-info { color: var(--info); border-color: var(--info); }
        .btn-outline-info:hover { background: var(--info); border-color: var(--info); }
        .btn-light, .btn-outline-secondary {
            background: var(--bg-soft);
            border-color: var(--border-color);
            color: var(--text-primary);
        }
        .btn-light:hover, .btn-outline-secondary:hover { background: var(--border-strong); border-color: var(--border-strong); color: var(--text-primary); }
        .btn-danger { background: var(--danger); border-color: var(--danger); }
        .btn-danger:hover { background: #c13a45; border-color: #c13a45; }
        .btn-success { background: var(--ok); border-color: var(--ok); }
        .btn-success:hover { background: var(--brand-strong); border-color: var(--brand-strong); }
        .btn-warning { background: var(--warn); border-color: var(--warn); color: #fff; }
        .btn-warning:hover { background: #967017; border-color: #967017; color: #fff; }
        .btn-info { background: var(--info); border-color: var(--info); color: #fff; }
        .btn-info:hover { background: #0f6792; border-color: #0f6792; color: #fff; }

        /* Chips / badges */
        .badge { font-weight: 600; font-size: 0.72rem; padding: 0.34em 0.7em; border-radius: 999px; letter-spacing: 0.01em; }
        .chip {
            display: inline-flex; align-items: center; gap: 0.4rem;
            font-size: 0.74rem; font-weight: 600;
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: var(--bg-soft);
            color: var(--text-secondary);
        }
        .chip.ok   { background: var(--ok-soft);   border-color: transparent; color: var(--ok); }
        .chip.danger { background: var(--danger-soft); border-color: transparent; color: var(--danger); }
        .chip.warn { background: var(--warn-soft); border-color: transparent; color: var(--warn); }
        .chip.info { background: var(--info-soft); border-color: transparent; color: var(--info); }
        .chip.ghost { background: transparent; border-color: var(--border-color); color: var(--text-secondary); }
        .chip .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

        /* Tables — hairline discipline */
        .table { color: var(--text-primary); --bs-table-hover-bg: var(--brand-softer); }
        .table thead th {
            font-family: var(--mono);
            font-size: 0.66rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--text-tertiary);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            padding: 0.9rem 1rem;
            white-space: nowrap;
        }
        .table td {
            border-bottom: 1px solid var(--border-color);
            padding: 1rem;
            vertical-align: middle;
        }
        .table tbody tr:last-child td { border-bottom: none; }
        .table > :not(caption) > * > * { background-color: transparent !important; border-bottom-color: var(--border-color); color: var(--text-primary); }
        .table-hover > tbody > tr:hover > * { background-color: var(--brand-softer) !important; }
        .table-light th { background: var(--bg-soft) !important; color: var(--text-tertiary) !important; }
        [data-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) > * { background-color: rgba(255,255,255,0.02) !important; }
        .table-responsive { border-radius: var(--radius-md); }
        .row-meta { font-family: var(--mono); font-size: 0.76rem; color: var(--text-tertiary); }

        /* Forms */
        .form-control, .form-select {
            background-color: var(--bg-raised);
            border-color: var(--border-color);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.55rem 0.85rem;
            font-size: 0.9rem;
            transition: border-color 0.2s var(--ease), box-shadow 0.2s var(--ease);
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--bg-raised);
            color: var(--text-primary);
            border-color: var(--brand-color);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }
        .form-control::placeholder { color: var(--text-tertiary); }
        [data-theme="dark"] .form-control::placeholder { color: var(--text-tertiary); }
        .form-label { font-size: 0.83rem; font-weight: 600; color: var(--text-primary); }
        .form-text { color: var(--text-tertiary); font-size: 0.78rem; }
        .input-group-text {
            background: var(--bg-soft);
            border-color: var(--border-color);
            color: var(--text-tertiary);
            border-radius: 10px !important;
        }
        .form-check-input:checked { background-color: var(--brand-color); border-color: var(--brand-color); }
        .form-check-input:focus { box-shadow: 0 0 0 0.25rem var(--brand-ring); }
        .form-switch .form-check-input {
            width: 2.8rem; height: 1.55rem; cursor: pointer;
            background-color: var(--border-strong);
            border-color: var(--border-strong);
        }
        .form-switch .form-check-input:checked { background-color: var(--brand-color); }
        .form-range::-webkit-slider-thumb { background: var(--brand-color); }
        .form-range::-moz-range-thumb { background: var(--brand-color); }
        .form-control-color { background: transparent; }

        /* Modals / dropdowns / offcanvas */
        .modal-content {
            background-color: var(--bg-surface);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 22px;
            box-shadow: 0 40px 80px -30px rgba(0,0,0,0.35);
        }
        .modal-header, .modal-footer { border-color: var(--border-color); }
        .modal-backdrop.show { opacity: 0.55; }
        .dropdown-menu {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 24px 48px -16px rgba(16, 24, 40, 0.18);
            padding: 0.4rem;
            color: var(--text-primary);
        }
        .dropdown-item { color: var(--text-primary); border-radius: 10px; font-size: 0.88rem; }
        .dropdown-item:hover { background-color: var(--bg-soft); color: var(--brand-color); }
        .dropdown-divider { border-color: var(--border-color); }
        .offcanvas { background-color: var(--bg-surface); color: var(--text-primary); border-color: var(--border-color); }
        .offcanvas-bottom { border-radius: 24px 24px 0 0; }
        .btn-close { filter: none; }
        [data-theme="dark"] .btn-close { filter: invert(1) grayscale(1) brightness(1.6); }

        /* Alerts */
        .alert { border: 1px solid var(--border-color); border-radius: 16px; }
        .alert-success { background: var(--ok-soft); color: var(--ok); border-color: transparent; }
        .alert-danger { background: var(--danger-soft); color: var(--danger); border-color: transparent; }
        .alert-warning { background: var(--warn-soft); color: var(--warn); border-color: transparent; }
        .alert-info { background: var(--info-soft); color: var(--info); border-color: transparent; }

        /* List group */
        .list-group-item { background-color: transparent; color: var(--text-primary); border-color: var(--border-color); }

        /* Avatar tiles */
        .avatar-tile {
            width: 38px; height: 38px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 0.85rem;
            color: #06251c;
            background: linear-gradient(135deg, #34c79b, #0e8a6b);
            flex-shrink: 0;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.28);
        }
        .avatar-tile.ink { background: linear-gradient(135deg, #3d4450, #171a20); color: #dfe3e8; box-shadow: inset 0 1px 0 rgba(255,255,255,0.12); }
        .avatar-tile.moss { background: linear-gradient(135deg, #6fb97f, #2d7a4f); color: #08130c; }

        /* Status dot */
        .status-dot {
            display: inline-block;
            width: 7px; height: 7px;
            border-radius: 50%;
            margin-right: 6px;
            background: var(--text-tertiary);
            vertical-align: middle;
        }
        .status-dot.online { background: var(--ok); box-shadow: 0 0 8px rgba(52, 199, 155, 0.6); }
        .status-dot.offline { background: var(--danger); box-shadow: 0 0 8px rgba(214, 69, 80, 0.5); }
        .status-dot.loading { background: var(--text-tertiary); animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* Empty state */
        .empty {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center;
            padding: 3.5rem 1.5rem;
        }
        .empty .empty-icon {
            width: 64px; height: 64px;
            border-radius: 20px;
            background: var(--bg-soft);
            border: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: center;
            color: var(--text-tertiary);
            font-size: 1.5rem;
            margin-bottom: 1.1rem;
        }
        .empty h6 { font-weight: 650; letter-spacing: -0.01em; }
        .empty p { color: var(--text-secondary); font-size: 0.87rem; max-width: 40ch; margin-bottom: 0; }

        /* Pagination */
        .pagination { --bs-pagination-color: var(--text-secondary); --bs-pagination-bg: transparent; --bs-pagination-border-color: var(--border-color); --bs-pagination-hover-color: var(--brand-color); --bs-pagination-hover-bg: var(--brand-softer); --bs-pagination-hover-border-color: var(--brand-ring); --bs-pagination-focus-color: var(--brand-color); --bs-pagination-active-bg: var(--brand-color); --bs-pagination-active-border-color: var(--brand-color); }
        .page-link { border-radius: 9px !important; margin: 0 2px; font-family: var(--mono); font-size: 0.78rem; }

        /* Progress */
        .progress { background-color: var(--bg-soft); border-radius: 999px; }
        .progress-bar { background: var(--brand-color); }

        /* Toast / Swal2 premium skin */
        .swal2-popup { border-radius: 22px !important; font-family: 'Outfit', sans-serif; }
        .swal2-title { font-weight: 700 !important; letter-spacing: -0.02em; color: var(--text-primary) !important; font-size: 1.35rem !important; }
        .swal2-html-container { color: var(--text-secondary) !important; }
        .swal2-confirm.swal2-styled { border-radius: 12px !important; font-weight: 600 !important; }
        .swal2-cancel.swal2-styled { border-radius: 12px !important; background: var(--bg-soft) !important; color: var(--text-primary) !important; }
        .swal2-timer-progress-bar { background: var(--brand-color) !important; }

        /* Overlay mobile */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(8, 9, 11, 0.6); backdrop-filter: blur(3px); z-index: 1035; }

        /* Text utility sync */
        body, h1, h2, h3, h4, h5, h6, p, label, .h1, .h2, .h3, .h4, .h5, .h6 { color: var(--text-primary); }
        .text-body { color: var(--text-primary) !important; }
        .text-dark { color: var(--text-primary) !important; }
        .text-muted { color: var(--text-secondary) !important; }
        .text-secondary { color: var(--text-secondary) !important; }
        .text-primary { color: var(--brand-color) !important; }
        .text-success { color: var(--ok) !important; }
        .text-danger { color: var(--danger) !important; }
        .text-warning { color: var(--warn) !important; }
        .text-info { color: var(--info) !important; }
        .bg-white { background: var(--bg-surface) !important; }
        .bg-light { background: var(--bg-soft) !important; }
        .bg-primary { background: var(--brand-color) !important; border-color: var(--brand-color) !important; }
        .bg-success { background: var(--ok) !important; border-color: var(--ok) !important; }
        .bg-danger { background: var(--danger) !important; border-color: var(--danger) !important; }
        .bg-warning { background: var(--warn) !important; border-color: var(--warn) !important; }
        .bg-info { background: var(--info) !important; border-color: var(--info) !important; }
        .bg-success-subtle, .bg-primary-subtle, .bg-danger-subtle, .bg-warning-subtle, .bg-info-subtle { background: transparent !important; }
        .bg-opacity-10.bg-primary { background: var(--brand-soft) !important; }
        .bg-opacity-10.bg-danger { background: var(--danger-soft) !important; }
        .bg-opacity-10.bg-success { background: var(--ok-soft) !important; }
        .bg-opacity-10.bg-warning { background: var(--warn-soft) !important; }
        .bg-opacity-10.bg-info { background: var(--info-soft) !important; }
        .bg-opacity-10.bg-secondary { background: var(--bg-soft) !important; }
        .border, .border-bottom, .border-top, .border-start, .border-end { border-color: var(--border-color) !important; }
        .rounded-3 { border-radius: var(--radius-sm) !important; }
        .rounded-4 { border-radius: var(--radius-md) !important; }
        .shadow-sm { box-shadow: 0 1px 2px rgba(16,24,40,0.05) !important; }

        /* ════════════════════════════════════════════════════════════
           RESPONSIVE
           ════════════════════════════════════════════════════════════ */
        @media (max-width: 991.98px) {
            html.sidebar-collapsed body .sidebar, body.sidebar-collapsed .sidebar { width: var(--sidebar-width); }
            html.sidebar-collapsed body .sidebar-brand, body.sidebar-collapsed .sidebar-brand { justify-content: flex-start; padding: 1.4rem 1.5rem; }
            html.sidebar-collapsed body .sidebar-brand .brand-name, body.sidebar-collapsed .brand-name,
            html.sidebar-collapsed body .nav-label, body.sidebar-collapsed .nav-label,
            html.sidebar-collapsed body .nav-item .nav-text, body.sidebar-collapsed .nav-item .nav-text { display: block; }
            html.sidebar-collapsed body .nav-item, body.sidebar-collapsed .nav-item { justify-content: flex-start; padding: 0.62rem 0.85rem; gap: 0.8rem; }

            .sidebar { transform: translateX(-100%); width: var(--sidebar-width) !important; box-shadow: 24px 0 60px rgba(0,0,0,0.35); }
            .sidebar.show { transform: translateX(0); }
            .sidebar-overlay.show { display: block; }
            .topbar { left: 0 !important; padding: 0 1rem; }
            .main-content { margin-left: 0 !important; padding: 1.5rem 1rem 2.5rem; }
            .greeting-text { display: none !important; }
            .statgrid { grid-template-columns: 1fr; }
            .statgrid .stat + .stat { border-left: none; border-top: 1px solid var(--border-color); }
        }
    </style>
    <script src="<?= base_url('vendor/sweetalert2/sweetalert2.min.js?v=1.1') ?>"></script>
    <script>
        const savedTheme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth >= 992) {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    </script>
    <?php include __DIR__ . '/_frontend_config.php'; ?>
    <?= $this->renderSection('styles') ?>
</head>
<body>
    <a class="skip-link" href="#main-content">Lewati ke konten</a>

    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar — Ink Rail -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <?php if (!empty($appLogo)): ?>
                <img src="<?= base_url(esc($appLogo)) ?>" alt="Logo" class="brand-logo">
            <?php else: ?>
                <div class="icon-box"><i class="bi bi-hexagon-fill"></i></div>
            <?php endif; ?>
            <h4 class="brand-name"><?= esc($appName) ?></h4>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Utama</div>
            <?php
            $currentUrl = current_url();
            $userRole = session()->get('role');
            $isAdmin = $userRole === 'admin';
            $isAdminOrGuru = in_array($userRole, ['admin', 'guru']);

            $mainNav = [
                ['url' => '/admin/dashboard', 'icon' => 'bi-grid-1x2', 'label' => lang('App.menu_dashboard')],
                ['url' => '/admin/tests',     'icon' => 'bi-file-earmark-text', 'label' => lang('App.menu_exams')],
                ['url' => '/proctor',         'icon' => 'bi-broadcast', 'label' => lang('App.menu_proctoring')],
            ];

            if ($isAdminOrGuru) {
                $mainNav[] = ['url' => '/student/dashboard', 'icon' => 'bi-mortarboard', 'label' => 'Student/Siswa'];
            }

            foreach ($mainNav as $item):
                $active = str_contains($currentUrl, $item['url']) ? 'active' : '';
            ?>
                <a href="<?= base_url($item['url']) ?>" class="nav-item <?= $active ?>" title="<?= esc($item['label']) ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <span class="nav-text"><?= esc($item['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <div class="nav-label">Manajemen</div>
            <?php
            $mgtNav = [
                ['url' => '/admin/results',   'icon' => 'bi-bar-chart', 'label' => lang('App.menu_results')],
                ['url' => '/admin/reports',   'icon' => 'bi-file-earmark-spreadsheet', 'label' => lang('App.menu_reports')],
                ['url' => '/admin/questions', 'icon' => 'bi-journal-text', 'label' => lang('App.menu_questions')],
                ['url' => '/admin/subjects',  'icon' => 'bi-book', 'label' => lang('App.menu_subjects')],
                ['url' => '/admin/modules',   'icon' => 'bi-folder2', 'label' => lang('App.menu_modules')],
            ];
            if ($isAdmin) {
                array_unshift($mgtNav, ['url' => '/admin/users', 'icon' => 'bi-people', 'label' => lang('App.menu_users')]);
                $mgtNav[] = ['url' => '/admin/groups', 'icon' => 'bi-collection', 'label' => lang('App.menu_groups')];
            }
            foreach ($mgtNav as $item):
                $active = str_contains($currentUrl, $item['url']) ? 'active' : '';
            ?>
                <a href="<?= base_url($item['url']) ?>" class="nav-item <?= $active ?>" title="<?= esc($item['label']) ?>">
                    <i class="bi <?= $item['icon'] ?>"></i>
                    <span class="nav-text"><?= esc($item['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($isAdmin): ?>
            <div class="nav-label">Sistem</div>
            <a href="<?= base_url('/admin/analytics') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/analytics') ? 'active' : '' ?>" title="<?= esc(lang('App.menu_analytics')) ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span class="nav-text"><?= lang('App.menu_analytics') ?></span>
            </a>
            <a href="<?= base_url('/admin/logging') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/logging') && !str_contains($currentUrl, '/admin/logging/intruders') ? 'active' : '' ?>" title="<?= esc(lang('App.menu_logging')) ?>">
                <i class="bi bi-journal-richtext"></i>
                <span class="nav-text"><?= lang('App.menu_logging') ?></span>
            </a>
            <a href="<?= base_url('/admin/logging/intruders') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/logging/intruders') ? 'active' : '' ?>" title="<?= esc(lang('App.menu_intruder')) ?>">
                <i class="bi bi-bug"></i>
                <span class="nav-text"><?= lang('App.menu_intruder') ?></span>
            </a>
            <a href="<?= base_url('/admin/suspend') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/suspend') ? 'active' : '' ?>" title="<?= esc(lang('App.menu_security')) ?>">
                <i class="bi bi-shield-lock"></i>
                <span class="nav-text"><?= lang('App.menu_security') ?></span>
            </a>
            <a href="<?= base_url('/admin/kiosk') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/kiosk') ? 'active' : '' ?>" title="App Kiosk Android">
                <i class="bi bi-phone"></i>
                <span class="nav-text">App Kiosk Android</span>
            </a>
            <a href="<?= base_url('/admin/settings') ?>" class="nav-item <?= str_contains($currentUrl, '/admin/settings') ? 'active' : '' ?>" title="<?= esc(lang('App.menu_settings')) ?>">
                <i class="bi bi-gear"></i>
                <span class="nav-text"><?= lang('App.menu_settings') ?></span>
            </a>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- Topbar — Glass Strip -->
    <header class="topbar d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <button class="btn-toggle-sidebar" id="toggleSidebar" title="Toggle Sidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="greeting-text d-none d-md-block">
                <h5 class="mb-0 fw-bold" style="color: var(--text-primary);">Selamat <?= $greeting ?>, <?= esc(session()->get('firstname') ?? 'Admin') ?></h5>
                <span class="date-mono" style="color: var(--text-tertiary);"><?= date('D, d M Y') ?></span>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2 gap-md-3">
            <!-- Language Dropdown -->
            <div class="dropdown">
                <button class="topbar-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= lang('App.language') ?>">
                    <i class="bi bi-globe2"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm" style="border-radius: 14px; background: var(--bg-surface);">
                    <li><a class="dropdown-item py-2 <?= (session('lang') == 'id' || !session('lang')) ? 'active' : '' ?>" href="<?= base_url('lang/id') ?>" style="color: var(--text-primary);">Indonesia</a></li>
                    <li><a class="dropdown-item py-2 <?= session('lang') == 'en' ? 'active' : '' ?>" href="<?= base_url('lang/en') ?>" style="color: var(--text-primary);">English</a></li>
                </ul>
            </div>

            <!-- Theme Toggle -->
            <button class="topbar-icon-btn" id="btnThemeToggle" title="Toggle Theme">
                <i class="bi bi-moon-stars" id="themeIcon"></i>
            </button>

            <a href="<?= base_url('/admin/results') ?>" class="topbar-icon-btn" title="Laporan">
                <i class="bi bi-bell"></i>
            </a>

            <!-- User Profile Dropdown -->
            <div class="dropdown">
                <button class="user-profile-btn ms-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="d-none d-md-block text-end me-1">
                        <div class="fw-bold" style="font-size: 0.88rem; color: var(--text-primary); line-height:1.2;"><?= esc(session()->get('firstname') ?? 'User') ?></div>
                        <small style="font-size: 0.72rem; color: var(--text-tertiary); font-family: var(--mono);">@<?= esc(session()->get('username')) ?></small>
                    </div>
                    <div class="user-avatar">
                        <?= strtoupper(substr(session()->get('firstname') ?? 'A', 0, 1)) ?>
                    </div>
                </button>
                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-sm" style="border-radius: 14px; background: var(--bg-surface);">
                    <li><a class="dropdown-item py-2" href="<?= base_url('/admin/tests') ?>" style="color: var(--text-primary);"><i class="bi bi-file-earmark-text me-2 text-muted"></i> <?= lang('App.exam_management') ?></a></li>
                    <li><a class="dropdown-item py-2" href="<?= base_url('/admin/results') ?>" style="color: var(--text-primary);"><i class="bi bi-bar-chart-fill me-2 text-muted"></i> <?= lang('App.exam_reports') ?></a></li>
                    <li><a class="dropdown-item py-2" href="<?= base_url('/admin/reports') ?>" style="color: var(--text-primary);"><i class="bi bi-file-earmark-spreadsheet-fill me-2 text-success"></i> <?= lang('App.menu_reports') ?></a></li>
                    <li><hr class="dropdown-divider" style="border-color: var(--border-color);"></li>
                    <li><a class="dropdown-item py-2 text-danger fw-semibold" href="#" onclick="event.preventDefault(); document.getElementById('logout-form').submit();"><i class="bi bi-box-arrow-right me-2"></i> <?= lang('App.logout') ?></a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content" id="main-content" tabindex="-1">
        <!-- Flash Messages -->
        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert" style="border-radius: 16px;">
                <i class="bi bi-check-circle-fill me-2"></i>
                <span><?= esc(session()->getFlashdata('success')) ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert" style="border-radius: 16px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <span><?= esc(session()->getFlashdata('error')) ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $this->renderSection('content') ?>

        <!-- Footer -->
        <footer class="main-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>&copy; <?= date('Y') ?> <strong style="color: var(--text-primary);"><?= esc($appName) ?></strong>. Hak cipta dilindungi.</span>
            <span class="mono" style="font-size: 0.72rem;">v<?= esc(\App\Libraries\FrontendConfig::value('app_version', '1.30')) ?> · <?= date('Y.m.d') ?></span>
        </footer>
    </main>

    <script src="<?= base_url('vendor/bootstrap/js/bootstrap.bundle.min.js?v=1.1') ?>"></script>
    <script>
        // Sidebar toggle logic (Desktop collapse & Mobile offcanvas)
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('toggleSidebar');

        toggleBtn?.addEventListener('click', () => {
            if (window.innerWidth >= 992) {
                document.body.classList.toggle('sidebar-collapsed');
                document.documentElement.classList.toggle('sidebar-collapsed');
                const isCollapsed = document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
            } else {
                sidebar.classList.toggle('show');
                overlay.classList.toggle('show');
            }
        });

        overlay?.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });

        // Auto-dismiss alerts
        document.querySelectorAll('.alert-dismissible').forEach(el => {
            setTimeout(() => {
                const bs = bootstrap.Alert.getOrCreateInstance(el);
                bs.close();
            }, 5000);
        });

        // Theme Toggle Logic
        const btnThemeToggle = document.getElementById('btnThemeToggle');
        const themeIcon = document.getElementById('themeIcon');
        let themeRippleBusy = false; // guard: satu ripple per klik

        function updateThemeIcon(theme) {
            themeIcon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-stars';
        }

        updateThemeIcon(document.documentElement.getAttribute('data-theme'));

        /**
         * Circular ripple reveal via native View Transitions API.
         * Browser tanpa dukungan (fallback) → swap instan tanpa animasi.
         */
        function toggleThemeRipple(x, y, applyTheme) {
            if (!document.startViewTransition) {
                applyTheme(); // fallback: instant swap, no animation
                return;
            }
            if (themeRippleBusy) return;
            themeRippleBusy = true;

            const transition = document.startViewTransition(() => {
                applyTheme();
            });

            transition.ready.then(() => {
                const endRadius = Math.hypot(
                    Math.max(x, innerWidth - x),
                    Math.max(y, innerHeight - y)
                );

                document.documentElement.animate(
                    {
                        clipPath: [
                            `circle(0px at ${x}px ${y}px)`,
                            `circle(${endRadius}px at ${x}px ${y}px)`,
                        ],
                    },
                    {
                        duration: 600,
                        delay: 100,                                // "jeda": beat sebelum gelombang membesar
                        fill: 'backwards',                        // tahan circle(0px) selama delay
                        easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)', // back-out: overshoot → bounce
                        pseudoElement: '::view-transition-new(root)',
                    }
                );
            });

            transition.finished.catch(() => {}).finally(() => {
                themeRippleBusy = false;
            });

            // Safety net guard reset
            setTimeout(() => { themeRippleBusy = false; }, 1400);
        }

        btnThemeToggle.addEventListener('click', (e) => {
            // ── Micro-ripple tactile di tombol (terpisah dari tema) ──
            const rect = btnThemeToggle.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height) * 2;
            const fromCenter = !e.clientX && !e.clientY; // keyboard activation
            const bx = fromCenter ? rect.width / 2 : e.clientX - rect.left;
            const by = fromCenter ? rect.height / 2 : e.clientY - rect.top;

            const ripple = document.createElement('span');
            ripple.className = 'ripple';
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (bx - size / 2) + 'px';
            ripple.style.top = (by - size / 2) + 'px';
            btnThemeToggle.appendChild(ripple);
            ripple.addEventListener('animationend', () => ripple.remove());

            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            const applyTheme = () => {
                document.documentElement.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                updateThemeIcon(newTheme);
            };

            // Aksesibilitas: prefers-reduced-motion → swap instan
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                applyTheme();
                return;
            }

            // Origin ripple = posisi klik pada tombol (bukan tengah layar)
            const x = e.clientX || window.innerWidth / 2;
            const y = e.clientY || window.innerHeight / 2;
            toggleThemeRipple(x, y, applyTheme);
        });

        // Keep-Alive Ping
        const APP_CFG = window.APP_CONFIG || {};
        setInterval(function() {
            fetch('<?= base_url('/api/keep-alive') ?>', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
                }
            }).catch(e => console.error('Keep-alive failed:', e));
        }, APP_CFG.keep_alive_ms || 30000);

        // ── Proctor Report Polling ─────────────────────────
        (function() {
            let lastCheckTime = sessionStorage.getItem('lastCheckTime') || '';
            let seenReportIds;
            try {
                seenReportIds = new Set(JSON.parse(sessionStorage.getItem('seenReportIds') || '[]'));
            } catch(e) {
                seenReportIds = new Set();
            }

            function checkProctorReports() {
                const url = '<?= base_url('admin/notifications/proctor-reports') ?>' +
                            (lastCheckTime ? '?since=' + encodeURIComponent(lastCheckTime) : '');

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success' && res.reports && res.reports.length > 0) {
                        lastCheckTime = res.server_time;
                        sessionStorage.setItem('lastCheckTime', lastCheckTime);

                        res.reports.forEach(report => {
                            if (seenReportIds.has(report.id)) return;
                            seenReportIds.add(report.id);
                            sessionStorage.setItem('seenReportIds', JSON.stringify(Array.from(seenReportIds)));

                            try {
                                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                                [0, 0.15].forEach(delay => {
                                    const osc = ctx.createOscillator();
                                    const gain = ctx.createGain();
                                    osc.connect(gain);
                                    gain.connect(ctx.destination);
                                    osc.frequency.value = 880;
                                    gain.gain.value = 0.3;
                                    osc.start(ctx.currentTime + delay);
                                    osc.stop(ctx.currentTime + delay + 0.1);
                                });
                            } catch(e) {}

                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    title: '<i class="bi bi-flag-fill" style="color: var(--warn);"></i> Laporan Pengawas!',
                                    html: `Pengawas <strong>${report.proctor_name}</strong> menyarankan tindakan <strong>${(report.suggested_action || 'ban').toUpperCase()}</strong>.<br><br>Alasan:<br><span class="text-danger fw-bold">"${report.reason}"</span>`,
                                    icon: 'warning',
                                    showConfirmButton: true,
                                    confirmButtonText: '<i class="bi bi-shield-lock"></i> Buka Suspend Menu',
                                    showCancelButton: true,
                                    cancelButtonText: 'Tutup',
                                    customClass: { popup: 'rounded-4' }
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        window.location.href = '<?= base_url('admin/suspend') ?>?search=' + encodeURIComponent(report.student_username || '');
                                    }
                                });
                            }
                        });
                    } else if (res.server_time) {
                        lastCheckTime = res.server_time;
                        sessionStorage.setItem('lastCheckTime', lastCheckTime);
                    }
                })
                .catch(() => {});
            }

            checkProctorReports();
            setInterval(checkProctorReports, APP_CFG.proctor_poll_ms || 10000);
        })();
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?= $this->renderSection('scripts') ?>
    <form id="logout-form" action="<?= base_url('logout') ?>" method="POST" style="display: none;">
        <?= csrf_field() ?>
    </form>
</body>
</html>

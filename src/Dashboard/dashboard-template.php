<?php
/**
 * Template Name: Arshline Dashboard Fullscreen
 * Description: قالب اختصاصی و تمام‌صفحه برای داشبورد عرشلاین (بدون هدر و فوتر پوسته)
 */

// جلوگیری از بارگذاری مستقیم
if (!defined('ABSPATH')) exit;

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>داشبورد عرشلاین</title>
    <link rel="icon" href="<?php echo esc_url( plugins_url('favicon.ico', dirname(__DIR__, 2).'/arshline.php') ); ?>" type="image/x-icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Cyberpunk/Futuristic (light) */
            --primary: #1e40af;     /* Indigo 800 (deeper) */
            --primary-600: #1d4ed8;
            --secondary: #0e7490;   /* Cyan 700 (deeper) */
            --hot: #a21caf;         /* Fuchsia 700 (deeper) */
            --accent: #047857;      /* Emerald 800 (deeper) */
            --text: #0b1220;
            --muted: #64748b;
            --bg-surface: #f5f7fb;
            --surface: #ffffff;
            --card: #ffffff;
            --border: #e2e8f0;
            --sidebar: #ffffff;
            --shadow-primary: 0 16px 36px rgba(30,64,175,.28);
            --shadow-card: 0 8px 20px rgba(0,0,0,.10);
            --grad-primary: #1e40af; /* solid */
            --grad-accent: #047857;  /* solid */
            /* Sporty solids for feature cards */
            --grad-blue: #1e40af;
            --grad-green: #047857;
            --grad-pink: #a21caf;
            --grad-cyan-magenta: #0e7490;
            /* Glassmorphism (light) */
            --glass-a: rgba(255,255,255,.6);
            --glass-b: rgba(255,255,255,.22);
            --glass-border: rgba(255,255,255,.55);
            /* Glow */
            --glow-primary: 0 0 0 3px rgba(37,99,255,.22), 0 10px 28px rgba(37,99,255,.28);
            --glow-accent: 0 0 0 3px rgba(0,255,149,.18), 0 10px 28px rgba(0,229,255,.26);
        }
        body.dark {
            /* Cyberpunk (dark) */
            --text: #e5e7eb;
            --muted: #94a3b8;
            --bg-surface: #0c111f;
            --surface: #0d1321;
            --card: #121a2a;
            --border: #1f2a44;
            --sidebar: #0d1321; /* solid */
            --shadow-primary: 0 18px 42px rgba(30,64,175,.35);
            --shadow-card: 0 12px 28px rgba(0,0,0,.55);
            --grad-primary: #1e40af; /* solid */
            --grad-accent: #047857;  /* solid */
            /* Glassmorphism (dark) */
            --glass-a: rgba(255,255,255,.1);
            --glass-b: rgba(255,255,255,.05);
            --glass-border: rgba(255,255,255,.2);
            /* Glow */
            --glow-primary: 0 0 0 3px rgba(37,99,255,.28), 0 14px 34px rgba(37,99,255,.38);
            --glow-accent: 0 0 0 3px rgba(0,255,149,.22), 0 14px 34px rgba(0,229,255,.34);
        }
        body {
            margin: 0; padding: 0; font-family: 'Vazirmatn', system-ui, -apple-system, Segoe UI, Roboto, 'Inter', sans-serif; 
            background: var(--bg-surface);
            color: var(--text); transition: background .3s, color .3s;
        }
        .arshline-dashboard-root {
            display: flex; min-height: 100vh; width: 100vw;
        }
        .arshline-sidebar {
            width: 280px; background: var(--sidebar); border-inline-start: 1px solid var(--border);
            backdrop-filter: blur(8px); display: flex; flex-direction: column; transition: width .3s;
        }
        .arshline-sidebar.closed { width: 64px; }
        .arshline-sidebar .logo {
            font-size: 1.4rem; font-weight: 700; color: var(--primary); padding: 1.75rem 1.25rem 1rem 1.25rem; text-align: right; display:flex; align-items:center; gap:.6rem;
        }
        .arshline-sidebar nav {
            flex: 1; display: flex; flex-direction: column; gap: 1rem; padding: 1rem 0;
        }
        .arshline-sidebar nav a {
            display: flex; align-items: center; gap: .75rem; color: var(--muted); text-decoration: none; padding: .7rem 1.1rem; border-radius: 12px; transition: background .2s, color .2s, transform .2s, box-shadow .2s;
        }
    .arshline-sidebar nav a svg { transition: transform .2s ease, filter .2s ease; }
    .arshline-sidebar nav a:hover svg { transform: translateX(-4px) scale(1.02); filter: drop-shadow(0 6px 10px rgba(37,99,255,.3)); }
	.arshline-sidebar nav a.active { background: var(--primary); color: #fff; border: 0; box-shadow: 0 6px 16px rgba(0,0,0,.12); }
	.arshline-sidebar nav a:hover { background: rgba(37,99,255,.18); color: #0b1220; box-shadow: 0 8px 20px rgba(37,99,255,.18); }
        .arshline-sidebar .toggle {
            margin: 1rem 1.25rem; cursor: pointer; color: var(--muted); font-size: 1.2rem; text-align: right;
        }
        .arshline-main {
            flex: 1; padding: 2.2rem 2rem; min-height: 100vh; transition: background .3s;
        }
        .arshline-header {
            display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;
        }
        .arshline-header .mode-switch { cursor: pointer; font-size: 1.2rem; color: #fff; background: var(--primary); padding:.55rem .8rem; border-radius:12px; border:0; box-shadow: 0 6px 16px rgba(0,0,0,.12); transition: transform .2s ease, box-shadow .2s ease; }
        .arshline-header .mode-switch:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(0,0,0,.18); }
    .ar-btn { cursor:pointer; font-weight:600; border:0; border-radius:12px; background: var(--primary); color:#fff; padding:.5rem .9rem; box-shadow: 0 6px 16px rgba(0,0,0,.12); transition: transform .2s ease, box-shadow .2s ease; font-family: inherit; }
    .ar-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(0,0,0,.18); }
    .ar-btn--muted { background:#64748b; }
    .ar-input { padding:.5rem .6rem; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-family: inherit; font-size: 1rem; }
    .ar-select { padding:.45rem .5rem; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-family: inherit; font-size: 1rem; }
    .ar-dnd-handle { cursor: grab; user-select: none; display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; color:#fff; background: var(--primary); margin-inline-end:.5rem; }
    .ar-dnd-ghost { opacity:.6; }
    .ar-dnd-over { outline: 2px dashed var(--primary); outline-offset: 4px; }
    .ar-tool { font-family: inherit; font-size:.95rem; background: var(--accent); }
    .ar-dnd-placeholder { border:1px solid var(--border); border-radius:10px; margin:.4rem 0; background: var(--surface); opacity:.35; padding:.5rem .8rem; pointer-events:none; }
    .ar-dnd-ghost-proxy { position: fixed; top:-9999px; left:-9999px; pointer-events:none; padding:.3rem .6rem; border-radius:8px; background:var(--primary); color:#fff; font-family: inherit; font-size:.9rem; box-shadow: var(--shadow-card); }
    /* Preview-only mode */
    body.preview-only .arshline-sidebar, body.preview-only .arshline-header { display:none !important; }
    body.preview-only .arshline-main { padding: 1.2rem; }
        /* دارک مود */
        body.dark { background: var(--bg-surface); color: var(--text); }
    body.dark .arshline-main { color: var(--text); }
    body.dark .arshline-sidebar nav a { color: var(--muted); }
    body.dark .arshline-sidebar nav a.active { background: var(--primary); color: #fff; box-shadow: 0 10px 22px rgba(0,0,0,.35); }
    body.dark .arshline-sidebar nav a:hover { background: rgba(30,64,175,.22); color: #fff; box-shadow: 0 8px 18px rgba(0,0,0,.25); }

        /* Glass utility */
    .glass { background: var(--glass-a); backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border: 1px solid var(--glass-border); box-shadow: 0 1px 0 rgba(255,255,255,.12) inset; }

        /* کارت‌ها */
        .card {
            background: var(--card); border: 1px solid var(--border); border-radius: 18px; box-shadow: 0 6px 16px rgba(0,0,0,.08);
            padding: 1.4rem 1.2rem; min-width: 220px; flex: 1 1 220px; text-align: center; position: relative; overflow: hidden;
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .card:after { content:''; position:absolute; inset:-1px; border-radius:inherit; pointer-events:none; opacity:0; transition: opacity .25s ease; }
        .card:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(0,0,0,.12); border-color: rgba(37,99,255,.35); }
        .card:hover:after { opacity:.6; }
        .card .title { font-size: 1.12rem; font-weight: 800; color: #0f172a; text-shadow: none; }
    .card .hint { margin-top: .8rem; color: var(--muted); }
        body.dark .card .title { color: #f3f6fb; text-shadow: none; }

    /* Feature variants: sporty gradient accents */
    .card[class*="card--"] { position: relative; color:#fff; border: 0; }
    .card[class*="card--"]::before { content:""; position:absolute; inset:0; background: var(--accent-grad, var(--grad-primary)); }
    .card[class*="card--"] > * { position: relative; z-index:1; }
    .card[class*="card--"]::after { content:""; position:absolute; inset:0; background: rgba(255,255,255,.08); opacity:.15; }
    .card[class*="card--"] .title { color: #fff; }
    .card[class*="card--"] .hint { color: #ffffffd0; }
    .card--builder { --accent-grad: var(--grad-blue); }
    .card--submissions { --accent-grad: var(--grad-green); }
    .card--analytics { --accent-grad: var(--grad-pink); }
    .card--ai { --accent-grad: var(--grad-cyan-magenta); }
    .card[class*="card--"]:hover { box-shadow: 0 10px 26px rgba(0,0,0,.18); }
        /* views + micro-animations */
        .view { opacity: 0; transform: translateY(10px) scale(.98); animation: enter .35s ease forwards; }
        @keyframes enter { to { opacity: 1; transform: translateY(0) scale(1); } }
        .tagline { text-align:center; font-size:1.05rem; font-weight:700; color: var(--text); margin-bottom: 1rem; }
        body.dark .tagline { color: #e5e7eb; }
        /* Modern solid cards (no gradients) */
        .ar-modern-cards { display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:30px; padding: 10px 0 30px; }
        .ar-card { position:relative; width:320px; height:450px; background:#1e40af; border-radius:20px; border-bottom-left-radius:160px; border-bottom-right-radius:160px; display:flex; justify-content:center; align-items:flex-start; overflow:hidden; box-shadow: 0 12px 0 #fff, inset 0 -10px 0 rgba(255,255,255,.18), 0 36px 0 rgba(0,0,0,.12); }
        .ar-card::before { content:""; position:absolute; top:-140px; left:-40%; width:100%; height:120%; background: rgba(255,255,255,.06); transform: rotate(35deg); pointer-events:none; filter: blur(5px); }
        .ar-card .icon { position:relative; width:140px; height:120px; background:#0d1321; border-bottom-left-radius:100px; border-bottom-right-radius:100px; box-shadow: 0 12px 0 rgba(0,0,0,.1), inset 0 -8px 0 #fff; z-index:2; display:flex; justify-content:center; align-items:flex-start; }
        .ar-card .icon::before { content:""; position:absolute; top:0; left:-50px; width:50px; height:50px; background:transparent; border-top-right-radius:50px; box-shadow: 15px -15px 0 15px #0d1321; }
        .ar-card .icon::after { content:""; position:absolute; top:0; right:-50px; width:50px; height:50px; background:transparent; border-top-left-radius:50px; box-shadow: -15px -15px 0 15px #0d1321; }
        .ar-card .icon ion-icon { color:#fff; position:relative; font-size:6em; --ionicon-stroke-width:24px; }
        .ar-card .content { position:absolute; width:100%; padding:30px; padding-top:150px; text-align:center; z-index:1; }
        .ar-card .content h2 { font-size:1.4rem; color:#fff; margin-bottom:12px; }
        .ar-card .content p { color:#f1f5f9; line-height:1.6; font-size:.95rem; }
        .ar-card--blue { background:#1e40af; }
        .ar-card--amber { background:#b45309; }
        .ar-card--violet { background:#6d28d9; }
        body.dark .ar-card { box-shadow: 0 12px 0 #0d1321, inset 0 -10px 0 rgba(255,255,255,.12), 0 36px 0 rgba(0,0,0,.35); }
        /* دسترسی: کاهش حرکت */
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }

        /* Toasts */
        .ar-toast-wrap { position: fixed; left: 20px; bottom: 20px; display: flex; flex-direction: column; gap: 8px; z-index: 9999; }
        .ar-toast { background: var(--surface); color: var(--text); border:1px solid var(--border); border-radius: 10px; padding: .55rem .8rem; box-shadow: var(--shadow-card); min-width: 200px; max-width: 360px; }
        .ar-toast--success { border-color: #16a34a; }
        .ar-toast--error { border-color: #b91c1c; }
    </style>
</head>
<body>
<div class="arshline-dashboard-root">
    <aside class="arshline-sidebar glass" id="arshlineSidebar">
        <div class="logo">عرشلاین</div>
        <nav>
            <a href="#" class="active" data-tab="dashboard"><svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" fill="currentColor"/></svg>داشبورد</a>
            <a href="#" data-tab="forms"><svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z" fill="currentColor"/></svg>فرم‌ها</a>
            <a href="#" data-tab="submissions"><svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 8h14v-2H7v2zm0-4h14v-2H7v2zm0-6v2h14V7H7z" fill="currentColor"/></svg>پاسخ‌ها</a>
            <a href="#" data-tab="reports"><svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z" fill="currentColor"/></svg>گزارشات</a>
            <a href="#" data-tab="users"><svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" fill="currentColor"/></svg>کاربران</a>
        </nav>
        <div class="toggle" onclick="toggleSidebar()"><svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z" fill="currentColor"/></svg></div>
    </aside>
    <main class="arshline-main">
        <div class="arshline-header">
            <div style="font-size:1.5rem;font-weight:600;">داشبورد عرشلاین <span style="font-size:.95rem;color:#00e5ff;opacity:.9">v<?php echo \Arshline\Dashboard\Dashboard::VERSION; ?></span></div>
            <div class="mode-switch" onclick="toggleMode()"><svg id="modeIcon" width="22" height="22" fill="none" viewBox="0 0 24 24"><path d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364-6.364l-1.414 1.414M6.343 17.657l-1.414 1.414m12.728 0l-1.414-1.414M6.343 6.343L4.929 4.929" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></div>
        </div>
        <div id="arshlineDashboardContent" class="view"></div>
    </main>
</div>
<script>
// WP REST nonce for authenticated requests
var ARSHLINE_NONCE = '<?php echo wp_create_nonce('wp_rest'); ?>';
var ARSHLINE_REST = '<?php echo esc_js( rest_url('arshline/v1/') ); ?>';
var ARSHLINE_CAN_MANAGE = <?php echo ( current_user_can('edit_posts') || current_user_can('manage_options') ) ? 'true' : 'false'; ?>;
// apply saved theme preference on load (default: light)
(function() {
    var saved = localStorage.getItem('arshlineTheme');
    if (saved === 'dark') document.body.classList.add('dark');
    updateModeIcon();
})();

function toggleSidebar() {
    var sidebar = document.getElementById('arshlineSidebar');
    sidebar.classList.toggle('closed');
}
function toggleMode() {
    var dark = document.body.classList.toggle('dark');
    localStorage.setItem('arshlineTheme', dark ? 'dark' : 'light');
    updateModeIcon();
}
function updateModeIcon() {
    var icon = document.getElementById('modeIcon');
    if (!icon) return;
    if (document.body.classList.contains('dark')) {
        icon.innerHTML = '<path d="M21.64 13.64A9 9 0 1110.36 2.36 7 7 0 0021.64 13.64z" fill="currentColor"/>';
    } else {
        icon.innerHTML = '<path d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.364-6.364l-1.414 1.414M6.343 17.657l-1.414 1.414m12.728 0l-1.414-1.414M6.343 6.343L4.929 4.929" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
    }
}

// Tabs: render placeholder content per menu item
document.addEventListener('DOMContentLoaded', function() {
    var nav = document.querySelector('.arshline-sidebar nav');
    var links = nav ? nav.querySelectorAll('a[data-tab]') : [];
    var content = document.getElementById('arshlineDashboardContent');

    function setActive(tab) {
        links.forEach(function(a){
            if (a.getAttribute('data-tab') === tab) a.classList.add('active'); else a.classList.remove('active');
        });
    }

    function card(title, hint, variant) {
        var cls = 'card glass' + (variant ? (' card--' + variant) : '');
        return '<div class="' + cls + '"><span class="title">' + title + '</span><div class="hint">' + hint + '</div></div>';
    }

    function renderTab(tab) {
        if (!content) return;
        setActive(tab);
    if (tab === 'dashboard') {
            content.innerHTML = '<div class="tagline">عرش لاین ، سیستم هوشمند فرم، آزمون، گزارش گیری</div>' +
            '<div class="ar-modern-cards">\
                            <div class="ar-card ar-card--blue">\
                                <div class="icon"><ion-icon name="globe-outline"></ion-icon></div>\
                                <div class="content"><h2>فرم‌ساز پیشرفته</h2><p>(در حال توسعه)</p></div>\
                            </div>\
                            <div class="ar-card ar-card--amber">\
                                <div class="icon"><ion-icon name="diamond-outline"></ion-icon></div>\
                                <div class="content"><h2>مدیریت پاسخ‌ها</h2><p>(در حال توسعه)</p></div>\
                            </div>\
                            <div class="ar-card ar-card--violet">\
                                <div class="icon"><ion-icon name="rocket-outline"></ion-icon></div>\
                                <div class="content"><h2>تحلیل و گزارش</h2><p>(در حال توسعه)</p></div>\
                            </div>\
                        </div>';
        } else if (tab === 'forms') {
                        content.innerHTML = '<div class="card glass" style="padding:1rem;">\
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">\
                  <span class="title">فرم‌ها</span>\
                  <div style="display:flex;align-items:center;gap:.5rem;">\
                                        <button id="arCreateFormBtn" class="ar-btn" style="font-size:.95rem;">+ فرم جدید</button>\
                    <div id="arCreateInline" style="display:none;align-items:center;gap:.4rem;">\
                                            <input id="arNewFormTitle" type="text" placeholder="عنوان فرم..." class="ar-input" style="min-width:220px;" />\
                                            <button id="arCreateFormSubmit" class="ar-btn" style="font-size:.9rem;">ایجاد</button>\
                                            <button id="arCreateFormCancel" class="ar-btn ar-btn--muted" style="font-size:.9rem;">انصراف</button>\
                    </div>\
                  </div>\
                </div>\
                                <div id="arFormsList" class="hint">در حال بارگذاری...</div>\
                                <div id="arBuilder" style="margin-top:1rem;display:none;">\
                                    <div class="card glass" style="padding:1rem;">\
                                        <div style="display:flex;gap:1rem;align-items:flex-start;">\
                                            <div style="min-width:200px;">\
                                                <div class="title" style="margin-bottom:.6rem;">ابزارها</div>\
                                                <div id="arPalette" class="hint">\
                                                    <button data-type="short_text" class="ar-btn ar-tool" draggable="true" style="display:block;width:100%;margin-bottom:.5rem;">متن کوتاه</button>\
                                                </div>\
                                            </div>\
                                            <div style="flex:1;">\
                                                <div class="title" style="margin-bottom:.6rem;">بوم فرم</div>\
                                                <div id="arCanvas" style="min-height:160px;border:1px dashed var(--border);border-radius:12px;padding:10px;"></div>\
                                                <div style="margin-top:.8rem;text-align:left;">\
                                                    <button id="arSaveFields" class="ar-btn" style="font-size:.9rem;">ذخیره</button>\
                                                </div>\
                                            </div>\
                                        </div>\
                                    </div>\
                                </div>\
            </div>';
            fetch(ARSHLINE_REST + 'forms')
            .then(async r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); })
            .then(function(rows){
                var box = document.getElementById('arFormsList');
                if (!rows || rows.length===0) { box.textContent = 'فرمی یافت نشد.'; return; }
                                                                var html = rows.map(function(it){
                                        return '<div style="display:flex;justify-content:space-between;align-items:center;padding=.6rem 0;border-bottom:1px dashed var(--border);">\
                                                <div>\
                                                    <b style="color:var(--text)">'+(it.title||'بدون عنوان')+'</b>\
                                                    <span class="hint" style="margin-inline-start:.6rem">#'+it.id+' · '+it.status+'</span>\
                                                </div>\
                                                <div style="display:flex;gap:.8rem;align-items:center;">\
                                                        <a href="#" data-id="'+it.id+'" class="hint arPreviewForm">نمایش</a>\
                                                        '+ (ARSHLINE_CAN_MANAGE ? ('<a href="#" data-id="'+it.id+'" class="hint arEditForm">ویرایش</a>') : '') +'\
                                                </div>\
                                        </div>';
                }).join('');
                box.innerHTML = html;
                box.querySelectorAll('.arEditForm').forEach(function(a){
                                        a.addEventListener('click', function(e){
                                                e.preventDefault();
                                                var id = parseInt(a.getAttribute('data-id'));
                                                openBuilder(id);
                                        });
                                });
                box.querySelectorAll('.arPreviewForm').forEach(function(a){
                    a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); renderFormPreview(id); });
                });
            }).catch((err)=>{
                var box = document.getElementById('arFormsList');
                if (box) box.textContent = 'خطا در بارگذاری فرم‌ها. لطفاً وارد شوید یا مجوز دسترسی را بررسی کنید.';
                notify('خطا در بارگذاری فرم‌ها', 'error');
            });
            var btn = document.getElementById('arCreateFormBtn');
            var inlineWrap = null;
            if (btn) btn.addEventListener('click', function(){
                inlineWrap = inlineWrap || document.getElementById('arCreateInline');
                if (!inlineWrap) return;
                var showing = inlineWrap.style.display !== 'none';
                inlineWrap.style.display = showing ? 'none' : 'flex';
                if (!showing) {
                    var input = document.getElementById('arNewFormTitle');
                    if (input) { input.value = ''; input.focus(); }
                }
            });
            if (!ARSHLINE_CAN_MANAGE && btn){ btn.style.display = 'none'; }
            var submitBtn = document.getElementById('arCreateFormSubmit');
            var cancelBtn = document.getElementById('arCreateFormCancel');
            if (cancelBtn) cancelBtn.addEventListener('click', function(){
                var w = document.getElementById('arCreateInline');
                if (w) w.style.display = 'none';
            });
            if (submitBtn) submitBtn.addEventListener('click', function(){
                var titleEl = document.getElementById('arNewFormTitle');
                var title = (titleEl && titleEl.value.trim()) || 'فرم جدید';
                fetch(ARSHLINE_REST + 'forms', { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ title: title }) })
                    .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                    .then(function(){ notify('فرم ایجاد شد', 'success'); renderTab('forms'); })
                    .catch(function(){ notify('ایجاد فرم ناموفق بود. لطفاً دسترسی را بررسی کنید.', 'error'); });
            });
                        function openBuilder(id){
                                var holder = document.getElementById('arBuilder');
                                holder.style.display = 'block';
                                holder.dataset.formId = String(id);
                if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); return; }
                                fetch(ARSHLINE_REST + 'forms/'+id)
                                        .then(r=>r.json()).then(function(data){
                                                var canvas = document.getElementById('arCanvas');
                                                canvas.innerHTML = '';
                                                (data.fields||[]).forEach(function(f){ addFieldToCanvas(f.props||f); });
                                        });
                                var palette = document.getElementById('arPalette');
                                palette.querySelectorAll('button[data-type]').forEach(function(b){
                                        b.onclick = function(){ addFieldToCanvas({ type: 'short_text', label: 'متن کوتاه', format: 'free_text' }); };
                                        b.addEventListener('dragstart', function(e){
                                            e.dataTransfer.effectAllowed = 'copy';
                                            e.dataTransfer.setData('text/arshline-tool', JSON.stringify({ type: 'short_text', label: 'متن کوتاه', format: 'free_text' }));
                                        });
                                });
                                var canvasEl = document.getElementById('arCanvas');
                                if (!canvasEl._dndBound) {
                                    canvasEl.addEventListener('dragover', function(e){
                                        e.preventDefault();
                                        var dragging = canvasEl._dragging;
                                        var y = e.clientY;
                                        // Ensure placeholder exists and sized
                                        var ph = canvasEl._placeholder || document.createElement('div');
                                        ph.className = 'ar-dnd-placeholder';
                                        if (!ph.parentNode) canvasEl.appendChild(ph);
                                        if (dragging) ph.style.height = dragging.offsetHeight + 'px';
                                        // find beforeNode by cursor
                                        var beforeNode = null;
                                        Array.from(canvasEl.children).some(function(ch){ if(ch===ph) return false; var r=ch.getBoundingClientRect(); if (y < r.top + r.height/2){ beforeNode = ch; return true;} return false; });
                                        if (dragging) { ph.innerHTML = dragging.innerHTML; }
                                        if (beforeNode) canvasEl.insertBefore(ph, beforeNode); else canvasEl.appendChild(ph);
                                        canvasEl._placeholder = ph;
                                    });
                                    canvasEl.addEventListener('drop', function(e){
                                        e.preventDefault();
                                        // Drop from palette (new tool)
                                        var data = '';
                                        try { data = e.dataTransfer.getData('text/arshline-tool'); } catch(_){ data=''; }
                                        if (data) {
                                            try {
                                                var props = JSON.parse(data);
                                                var el = addFieldToCanvas(props);
                                                var phx = canvasEl._placeholder;
                                                if (phx && phx.parentNode) {
                                                    canvasEl.insertBefore(el, phx);
                                                    phx.parentNode.removeChild(phx);
                                                    canvasEl._placeholder = null;
                                                }
                                                return;
                                            } catch(_){}
                                        }
                                        // Reorder using placeholder position
                                        var dragging = canvasEl._dragging;
                                        var ph = canvasEl._placeholder;
                                        if (dragging && ph && ph.parentNode === canvasEl) {
                                            canvasEl.insertBefore(dragging, ph);
                                        }
                                        if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
                                    });
                                    canvasEl._dndBound = true;
                                }
                                document.getElementById('arSaveFields').onclick = function(){ saveFields(); };
                        }
            function suggestPlaceholder(fmt){
                switch(fmt){
                    case 'email': return 'example@mail.com';
                    case 'mobile_ir': return '09123456789';
                    case 'mobile_intl': return '+14155552671';
                    case 'tel': return '021-12345678';
                    case 'numeric': return '123456';
                    case 'fa_letters': return 'مثال فارسی';
                    case 'en_letters': return 'Sample text';
                    case 'ip': return '192.168.1.1';
                    case 'time': return '14:30';
                    case 'date_jalali': return '1403/01/15';
                    case 'date_greg': return '2025-09-22';
                    case 'regex': return 'مطابق الگو';
                    case 'free_text':
                    default: return '';
                }
            }
            function addFieldToCanvas(props){
                                var canvas = document.getElementById('arCanvas');
                                var item = document.createElement('div');
                item.style.cssText = 'display:flex;align-items:center;justify-content:space-between;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.5rem .8rem;margin:.4rem 0;';
                item.setAttribute('draggable','true');
                var fmt = props.format || 'free_text';
                item.innerHTML = '<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;flex:1 1 auto;">\
                                                    <span class="ar-dnd-handle" title="جابجایی" draggable="true">⋮⋮</span>\
                                                    <span class="hint">(متن کوتاه)</span>\
                                                    <input type="text" data-prop="label" value="'+ (props.label || 'متن کوتاه') +'" placeholder="برچسب" class="ar-input" style="min-width:160px;"/>\
                                                    <input type="text" data-prop="placeholder" value="'+ (props.placeholder || '') +'" placeholder="در صورت تمایل متن نمایش در باکس" class="ar-input" style="min-width:220px;"/>\
                                                    <select class="ar-select" data-prop="format">\
                                                        <option value="free_text"'+(fmt==='free_text'?' selected':'')+'>متن آزاد</option>\
                                                        <option value="email"'+(fmt==='email'?' selected':'')+'>ایمیل</option>\
                                                        <option value="mobile_ir"'+(fmt==='mobile_ir'?' selected':'')+'>موبایل ایران</option>\
                                                        <option value="mobile_intl"'+(fmt==='mobile_intl'?' selected':'')+'>موبایل بین‌المللی</option>\
                                                        <option value="tel"'+(fmt==='tel'?' selected':'')+'>تلفن</option>\
                                                        <option value="numeric"'+(fmt==='numeric'?' selected':'')+'>فقط عددی</option>\
                                                        <option value="fa_letters"'+(fmt==='fa_letters'?' selected':'')+'>حروف فارسی</option>\
                                                        <option value="en_letters"'+(fmt==='en_letters'?' selected':'')+'>حروف انگلیسی</option>\
                                                        <option value="ip"'+(fmt==='ip'?' selected':'')+'>IP</option>\
                                                        <option value="time"'+(fmt==='time'?' selected':'')+'>زمان</option>\
                                                        <option value="date_jalali"'+(fmt==='date_jalali'?' selected':'')+'>تاریخ شمسی</option>\
                                                        <option value="date_greg"'+(fmt==='date_greg'?' selected':'')+'>تاریخ میلادی</option>\
                                                        <option value="regex"'+(fmt==='regex'?' selected':'')+'>الگوی دلخواه</option>\
                                                    </select>\
                                                    <input type="text" data-prop="regex" value="'+ (props.regex || '') +'" placeholder="/الگو/" class="ar-input" style="min-width:140px;display:'+(fmt==='regex'?'inline-block':'none')+';" />\
                                                    <label class="hint" style="display:inline-flex;align-items:center;gap:.3rem;">\
                                                        <input type="checkbox" data-prop="required" '+ (props.required ? 'checked' : '') +'> اجباری\
                                                    </label>\
                                                    <input class="ar-input ar-live-sample" type="text" style="min-width:240px;opacity:.9;" placeholder="" title="نمونه ورودی"/>\
                                                </div>\
                                                <div>\
                                                    <button class="ar-btn" data-act="remove" style="padding:.2rem .5rem;font-size:.8rem;line-height:1;background:#b91c1c;">حذف</button>\
                                                </div>';
                                item.dataset.props = JSON.stringify(props);
                // DnD events
                item.addEventListener('dragstart', function(e){
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', 'drag');
                    item.classList.add('ar-dnd-ghost');
                    canvas._dragging = item;
                    // custom drag image
                    var txt = (JSON.parse(item.dataset.props||'{}').label) || 'Field';
                    var ghost = document.createElement('div');
                    ghost.className = 'ar-dnd-ghost-proxy';
                    ghost.textContent = txt;
                    document.body.appendChild(ghost);
                    e.dataTransfer.setDragImage(ghost, 10, 10);
                    canvas._dragGhost = ghost;
                });
                item.addEventListener('dragend', function(){
                    item.classList.remove('ar-dnd-ghost');
                    Array.from(canvas.children).forEach(function(c){ c.classList.remove('ar-dnd-over'); });
                    var ph = canvas._placeholder; if (ph && ph.parentNode) ph.parentNode.removeChild(ph);
                    var gh = canvas._dragGhost; if (gh && gh.parentNode) gh.parentNode.removeChild(gh);
                    canvas._dragging = null; canvas._placeholder = null; canvas._dragGhost = null;
                });
                item.addEventListener('dragover', function(e){
                    e.preventDefault();
                    var dragging = canvas._dragging;
                    if (!dragging || dragging===item) return;
                    // manage placeholder relative to this item
                    var ph = canvas._placeholder || document.createElement('div');
                    ph.className = 'ar-dnd-placeholder';
                    ph.style.height = (dragging.offsetHeight || item.offsetHeight) + 'px';
                    ph.innerHTML = dragging.innerHTML;
                    var rect = item.getBoundingClientRect();
                    var before = (e.clientY - rect.top) < (rect.height/2);
                    if (before) canvas.insertBefore(ph, item); else canvas.insertBefore(ph, item.nextSibling);
                    canvas._placeholder = ph;
                });
                item.addEventListener('dragleave', function(){ /* no-op */ });
                item.addEventListener('drop', function(e){
                    e.preventDefault();
                    var dragging = canvas._dragging;
                    if (!dragging || dragging===item) return;
                    var ph = canvas._placeholder;
                    if (ph && ph.parentNode) {
                        canvas.insertBefore(dragging, ph);
                        ph.parentNode.removeChild(ph);
                        canvas._placeholder = null;
                    }
                });
                                function syncProps(){
                                    var p = JSON.parse(item.dataset.props || '{}');
                                    var label = item.querySelector('input[data-prop="label"]');
                                    var ph = item.querySelector('input[data-prop="placeholder"]');
                                    var req = item.querySelector('input[data-prop="required"]');
                                    var fmtSel = item.querySelector('select[data-prop="format"]');
                                    var rx = item.querySelector('input[data-prop="regex"]');
                                    p.label = label ? label.value : p.label;
                                    p.placeholder = ph ? ph.value : p.placeholder;
                                    p.required = req ? !!req.checked : !!p.required;
                                    p.type = 'short_text';
                                    if (fmtSel) p.format = fmtSel.value || 'free_text';
                                    if (rx) p.regex = rx.value || '';
                                    item.dataset.props = JSON.stringify(p);
                                    renderSample();
                                }
                                item.querySelectorAll('input[data-prop]').forEach(function(el){
                                    el.addEventListener('input', syncProps);
                                    el.addEventListener('change', syncProps);
                                });
                                var fmtSelEl = item.querySelector('select[data-prop="format"]');
                                if (fmtSelEl) {
                                    fmtSelEl.addEventListener('change', function(){
                                        var rx = item.querySelector('input[data-prop="regex"]');
                                        if (rx) rx.style.display = (fmtSelEl.value==='regex') ? 'inline-block' : 'none';
                                        // put suggested placeholder as placeholder attribute (not value)
                                        var ph = item.querySelector('input[data-prop="placeholder"]');
                                        if (ph && ph.value.trim()==='') { ph.setAttribute('placeholder', 'در صورت تمایل متن نمایش در باکس (مثال: '+suggestPlaceholder(fmtSelEl.value)+')'); }
                                        syncProps();
                                    });
                                }
                                // sample renderer based on format and placeholder
                                function renderSample(){
                                    var p = JSON.parse(item.dataset.props || '{}');
                                    var fmt = p.format || 'free_text';
                                    var a = inputAttrsByFormat(fmt);
                                    var sample = item.querySelector('.ar-live-sample');
                                    if (!sample) return;
                                    sample.setAttribute('type', a.type || 'text');
                                    if (a.inputmode) sample.setAttribute('inputmode', a.inputmode); else sample.removeAttribute('inputmode');
                                    if (a.pattern) sample.setAttribute('pattern', a.pattern); else sample.removeAttribute('pattern');
                                    var phText = (p.placeholder && p.placeholder.trim()) ? p.placeholder : suggestPlaceholder(fmt);
                                    sample.setAttribute('placeholder', phText || '');
                                    // jalali datepicker if available
                                    if (fmt==='date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){
                                        try { jQuery(sample).pDatepicker({ format: 'YYYY/MM/DD', initialValue: false }); } catch(e){}
                                    }
                                    // also update config placeholder input's placeholder to show suggestion
                                    var cfgPh = item.querySelector('input[data-prop="placeholder"]');
                                    if (cfgPh && (cfgPh.value||'').trim()==='') {
                                        cfgPh.setAttribute('placeholder', 'در صورت تمایل متن نمایش در باکس (مثال: '+(phText||'')+')');
                                    }
                                }
                                // initial
                                renderSample();
                                item.querySelector('[data-act="remove"]').onclick = function(){
                                    var canvasRef = document.getElementById('arCanvas');
                                    var idx = Array.from(canvasRef.children).indexOf(item);
                                    var backup = JSON.parse(item.dataset.props || '{}');
                                    item.remove();
                                    notify('فیلد حذف شد', { type: 'error', actionLabel: 'بازگردانی', onAction: function(){
                                        var newEl = addFieldToCanvas(backup);
                                        var ref = canvasRef.children[idx] || null;
                                        if (ref) canvasRef.insertBefore(newEl, ref); else canvasRef.appendChild(newEl);
                                    }});
                                };
                                canvas.appendChild(item);
                                return item;
                        }

            // Fullscreen form preview + submit
            function inputAttrsByFormat(fmt){
                var a = { type:'text', inputmode:'', pattern:'' };
                if (fmt==='email') a.type='email';
                else if (fmt==='numeric') { a.inputmode='numeric'; a.pattern='[0-9]*'; }
                else if (fmt==='mobile_ir' || fmt==='mobile_intl' || fmt==='tel') { a.inputmode='tel'; }
                else if (fmt==='time') a.type='time';
                else if (fmt==='date_greg') a.type='date';
                return a;
            }
            function renderFormPreview(id){
                document.body.classList.add('preview-only');
                var content = document.getElementById('arshlineDashboardContent');
                content.innerHTML = '<div class="card glass" style="padding:1.2rem;max-width:720px;margin:0 auto;">\
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">\
                        <div class="title">پیش‌نمایش فرم #' + id + '</div>\
                        <button id="arPreviewBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                    </div>\
                    <div id="arFormPreviewFields" style="display:flex;flex-direction:column;gap:.8rem;"></div>\
                    <div style="margin-top:1rem;text-align:left;"><button id="arPreviewSubmit" class="ar-btn">ارسال</button></div>\
                </div>';
                fetch(ARSHLINE_REST + 'forms/' + id)
                    .then(r=>r.json())
                    .then(function(data){
                        var fwrap = document.getElementById('arFormPreviewFields');
                        (data.fields||[]).forEach(function(f){
                            var p = f.props || f;
                            var fmt = p.format || 'free_text';
                            var attrs = inputAttrsByFormat(fmt);
                            var phS = p.placeholder && p.placeholder.trim() ? p.placeholder : suggestPlaceholder(fmt);
                            var row = document.createElement('div');
                            row.innerHTML = '<label class="hint" style="display:block;margin-bottom:.3rem;">'+(p.label||'فیلد')+(p.required?' *':'')+'</label>' +
                                '<input class="ar-input" '+(attrs.type?('type="'+attrs.type+'"'):'')+' '+(attrs.inputmode?('inputmode="'+attrs.inputmode+'"'):'')+' '+(attrs.pattern?('pattern="'+attrs.pattern+'"'):'')+' placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' />';
                            fwrap.appendChild(row);
                        });
                        // apply masks
                        fwrap.querySelectorAll('input[data-field-id]').forEach(function(inp, idx){
                            var field = (data.fields||[])[idx] || {};
                            var props = field.props || field || {};
                            applyInputMask(inp, props);
                            if (props.format === 'date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){
                                try { jQuery(inp).pDatepicker({ format: 'YYYY/MM/DD', initialValue: false }); } catch(e){}
                            }
                        });
                        document.getElementById('arPreviewSubmit').onclick = function(){
                            var vals = [];
                            fwrap.querySelectorAll('input[data-field-id]').forEach(function(inp, idx){
                                var fid = parseInt(inp.getAttribute('data-field-id')||'0');
                                vals.push({ field_id: fid, value: inp.value||'' });
                            });
                            fetch(ARSHLINE_REST + 'forms/'+id+'/submissions', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ values: vals }) })
                                .then(async r=>{ if (!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                                .then(function(){ notify('ارسال شد', 'success'); })
                                .catch(function(){ notify('اعتبارسنجی/ارسال ناموفق بود', 'error'); });
                        };
                        document.getElementById('arPreviewBack').onclick = function(){ document.body.classList.remove('preview-only'); renderTab('forms'); };
                    });
            }
                        function saveFields(){
                                var id = parseInt(document.getElementById('arBuilder').dataset.formId||'0');
                                var canvas = document.getElementById('arCanvas');
                                var fields = Array.from(canvas.children).map(function(el){ return JSON.parse(el.dataset.props||'{}'); });
                                fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: fields }) })
                                        .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                                        .then(function(){ notify('ذخیره شد', 'success'); })
                                        .catch(function(){ notify('ذخیره تغییرات ناموفق بود', 'error'); });
                        }
        } else if (tab === 'submissions') {
            content.innerHTML = '<div class="card glass" style="padding:1rem;">\
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;">\
                  <span class="title">پاسخ‌ها</span>\
                  <select id="arFormSelect" style="margin-inline-start:auto;padding:.35rem .5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></select>\
                </div>\
                <div id="arSubsList" class="hint">فرمی انتخاب کنید...</div>\
            </div>';
            fetch(ARSHLINE_REST + 'forms').then(r=>r.json()).then(function(forms){
                var sel = document.getElementById('arFormSelect');
                if (!sel) return;
                sel.innerHTML = '<option value="">انتخاب فرم...</option>' + (forms||[]).map(function(f){ return '<option value="'+f.id+'">#'+f.id+' — '+(f.title||'بدون عنوان')+'</option>'; }).join('');
                sel.addEventListener('change', function(){
                    var id = parseInt(sel.value||'0');
                    var list = document.getElementById('arSubsList');
                    if (!id){ list.textContent='فرمی انتخاب کنید...'; return; }
                    list.textContent = 'در حال بارگذاری...';
                    fetch(ARSHLINE_REST + 'forms/'+id+'/submissions').then(r=>r.json()).then(function(rows){
                        if (!rows || rows.length===0){ list.textContent='پاسخی ثبت نشده است.'; return; }
                        var html = rows.map(function(it){
                            return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px dashed var(--border);">\
                                <div>#'+it.id+' · '+(it.status)+'<span class="hint" style="margin-inline-start:.6rem">'+(it.created_at||'')+'</span></div>\
                                <a href="#" class="hint">جزئیات</a>\
                            </div>';
                        }).join('');
                        list.innerHTML = html;
                    }).catch(()=>{ list.textContent='خطا در بارگذاری پاسخ‌ها'; });
                });
            });
        } else if (tab === 'reports') {
            content.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:1.2rem;">' +
                card('نمودار نرخ تبدیل', 'به‌زودی') +
                card('منابع ترافیک', 'به‌زودی') +
                card('زمان‌های اوج', 'به‌زودی') +
            '</div>';
        } else if (tab === 'users') {
            content.innerHTML = '<div style="display:flex;flex-direction:column;gap:1.2rem;">\
                <div class="card glass"><span class="title">کاربران</span><div class="hint">مدیریت نقش‌ها و دسترسی‌ها (Placeholder)</div></div>\
                <div class="card glass"><span class="title">همکاری تیمی</span><div class="hint">دعوت هم‌تیمی‌ها (Placeholder)</div></div>\
            </div>';
        }
        // re-trigger entrance animation
        content.classList.remove('view');
        void content.offsetWidth;
        content.classList.add('view');
    }

    // bind clicks + keyboard
    links.forEach(function(a){
        a.addEventListener('click', function(e){ e.preventDefault(); renderTab(a.getAttribute('data-tab')); });
        a.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); renderTab(a.getAttribute('data-tab')); }});
    });

    // default tab
    var initial = (location.hash || '').replace('#','') || 'dashboard';
    if (![ 'dashboard','forms','submissions','reports','users' ].includes(initial)) initial = 'dashboard';
    renderTab(initial);
});
</script>
<!-- Ionicons for modern solid cards -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<!-- Persian datepicker (optional) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.css" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.js"></script>
<script>
// lightweight toast notifications
function ensureToastWrap(){
    var w = document.getElementById('arToastWrap');
    if (!w){
        w = document.createElement('div');
        w.id = 'arToastWrap';
        w.className = 'ar-toast-wrap';
        document.body.appendChild(w);
    }
    return w;
}
function notify(message, opts){
    var wrap = ensureToastWrap();
    var el = document.createElement('div');
    var type = typeof opts === 'string' ? opts : (opts && opts.type) || '';
    el.className = 'ar-toast ' + (type ? ('ar-toast--'+type) : '');
    var hasAction = opts && opts.actionLabel && typeof opts.onAction === 'function';
    if (hasAction){
        var span = document.createElement('span');
        span.textContent = message;
        var btn = document.createElement('button');
        btn.textContent = opts.actionLabel;
        btn.style.cssText = 'margin-inline-start:.6rem;padding:.25rem .6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;';
        btn.addEventListener('click', function(){ opts.onAction(); el.remove(); });
        el.appendChild(span);
        el.appendChild(btn);
    } else {
        el.textContent = message;
    }
    wrap.appendChild(el);
    var duration = (opts && opts.duration) || 2800;
    setTimeout(function(){ el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, Math.max(200, duration - 500));
    setTimeout(function(){ el.remove(); }, duration);
}
</script>
<script>
// Input masks for preview inputs
function applyInputMask(inp, props){
    var fmt = props.format || 'free_text';
    function clampLen(){}
    function digitsOnly(){ inp.value = inp.value.replace(/\D+/g,''); }
    function allowChars(regex){ inp.value = (inp.value.match(regex)||[]).join(''); }
    function setInvalid(msg){ inp.style.borderColor = '#b91c1c'; if (msg) inp.title = msg; }
    function clearInvalid(){ inp.style.borderColor = ''; inp.title = ''; }
    inp.addEventListener('input', function(){
        switch(fmt){
            case 'numeric': digitsOnly(); break;
            case 'mobile_ir': inp.value = inp.value.replace(/[^\d]/g,''); if (/^9\d/.test(inp.value)) inp.value = '0'+inp.value; if (inp.value.startsWith('98')) inp.value = '0'+inp.value.slice(2); if (inp.value.length>11) inp.value = inp.value.slice(0,11); break;
            case 'mobile_intl': inp.value = inp.value.replace(/(?!^)[^\d]/g,'').replace(/^([^+\d])+/,''); if (!inp.value.startsWith('+')) inp.value = '+'+inp.value.replace(/\+/g,''); inp.value = inp.value.replace(/(.*\d{15}).*$/, '$1'); break;
            case 'tel': inp.value = inp.value.replace(/[^0-9\-\+\s\(\)]/g,''); break;
            case 'ip': inp.value = inp.value.replace(/[^0-9\.]/g,'').replace(/\.\.+/g,'.'); break;
            case 'time': inp.value = inp.value.replace(/[^0-9]/g,''); if (inp.value.length>2) inp.value = inp.value.slice(0,2)+":"+inp.value.slice(2,4); if (inp.value.length>5) inp.value = inp.value.slice(0,5); break;
            case 'fa_letters': allowChars(/[\u0600-\u06FF\s]/g); break;
            case 'en_letters': allowChars(/[A-Za-z\s]/g); break;
            case 'date_jalali': inp.value = inp.value.replace(/[^0-9/]/g,'').slice(0,10); break;
            default: break;
        }
        clampLen();
    });
    inp.addEventListener('blur', function(){
        clearInvalid();
        var v = inp.value.trim(); if (!v) return;
        var msg = null;
        switch(fmt){
            case 'email': if (!/^\S+@\S+\.\S+$/.test(v)) setInvalid('ایمیل نامعتبر است'); break;
            case 'mobile_ir': if (!/^(\+98|0)?9\d{9}$/.test(v)) setInvalid('شماره موبایل ایران نامعتبر است'); break;
            case 'mobile_intl': if (!/^\+?[1-9]\d{7,14}$/.test(v)) setInvalid('شماره موبایل بین‌المللی نامعتبر است'); break;
            case 'tel': if (!/^[0-9\-\+\s\(\)]{5,20}$/.test(v)) setInvalid('شماره تلفن نامعتبر است'); break;
            case 'numeric': if (!/^\d+$/.test(v)) setInvalid('فقط عددی'); break;
            case 'fa_letters': if (!/^[\u0600-\u06FF\s]+$/.test(v)) setInvalid('فقط حروف فارسی'); break;
            case 'en_letters': if (!/^[A-Za-z\s]+$/.test(v)) setInvalid('فقط حروف انگلیسی'); break;
            case 'ip': if (!/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)$/.test(v)) setInvalid('IP نامعتبر است'); break;
            case 'time': if (!/^(?:[01]?\d|2[0-3]):[0-5]\d$/.test(v)) setInvalid('زمان نامعتبر است'); break;
            case 'date_jalali': if (!/^\d{4}\/(0[1-6]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/.test(v)) setInvalid('تاریخ شمسی نامعتبر است'); break;
            case 'date_greg': if (!/^\d{4}\-(0[1-9]|1[0-2])\-(0[1-9]|[12]\d|3[01])$/.test(v)) setInvalid('تاریخ میلادی نامعتبر است'); break;
            default: break;
        }
    });
}
</script>
</body>
</html>

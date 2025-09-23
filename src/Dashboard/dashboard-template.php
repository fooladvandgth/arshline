<?php
/**
 * Template Name: Arshline Dashboard Fullscreen
 * Description: قالب اختصاصی و تمام‌صفحه برای داشبورد عرشلاین (بدون هدر و فوتر پوسته)
 */

// جلوگیری از بارگذاری مستقیم
if (!defined('ABSPATH')) exit;

// Block access for non-logged-in users or users without required capability
if (!is_user_logged_in() || !( current_user_can('edit_posts') || current_user_can('manage_options') )) {
    wp_redirect( wp_login_url( get_permalink() ) );
    exit;
}

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
            /* Toast palette */
            --toast-success: #16a34a;
            --toast-error: #dc2626;
            --toast-info: #2563eb;
            --toast-warn: #d97706;
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
            backdrop-filter: blur(8px); display: flex; flex-direction: column; transition: width .3s ease; overflow: hidden;
        }
        .arshline-sidebar.closed { width: 72px; }
        .arshline-sidebar .logo {
            font-size: 1.4rem; font-weight: 700; color: var(--primary); padding: 1.75rem 1.25rem 1rem 1.25rem; text-align: right; display:flex; align-items:center; gap:.6rem;
        }
        .arshline-sidebar .logo .label { transition: opacity .2s ease, transform .2s ease; }
        .arshline-sidebar.closed .logo .label { opacity: 0; transform: translateX(6px); pointer-events: none; }
        .arshline-sidebar nav {
            flex: 1; display: flex; flex-direction: column; gap: 1rem; padding: 1rem .5rem; overflow-y: auto;
        }
        .arshline-sidebar nav a {
            display: flex; align-items: center; gap: .75rem; color: var(--muted); text-decoration: none; padding: .7rem 1.1rem; border-radius: 12px; transition: background .2s, color .2s, transform .2s, box-shadow .2s;
        }
    .arshline-sidebar nav a .ic { width: 22px; height: 22px; display:inline-flex; align-items:center; justify-content:center; }
    /* Increase icon spacing to 50px (RTL uses margin-right) */
    [dir='rtl'] .arshline-sidebar nav a .ic { margin-right: 50px; }
    [dir='ltr'] .arshline-sidebar nav a .ic { margin-left: 50px; }
        /* add extra breathing room from the right edge when collapsed (RTL) */
        [dir='rtl'] .arshline-sidebar.closed nav { padding-inline-end: 12px; }
        [dir='ltr'] .arshline-sidebar.closed nav { padding-inline-start: 12px; }
    [dir='rtl'] .arshline-sidebar.closed nav a .ic { margin-right: 50px; }
    [dir='ltr'] .arshline-sidebar.closed nav a .ic { margin-left: 50px; }
        .arshline-sidebar nav a .label { white-space: nowrap; transition: opacity .18s ease, transform .18s ease; }
        .arshline-sidebar.closed nav a { justify-content: center; }
        .arshline-sidebar.closed nav a .label { opacity: 0; transform: translateX(6px); pointer-events: none; }
    .arshline-sidebar nav a svg { transition: transform .2s ease, filter .2s ease; }
    .arshline-sidebar nav a:hover svg { transform: translateX(-4px) scale(1.02); filter: drop-shadow(0 6px 10px rgba(37,99,255,.3)); }
	.arshline-sidebar nav a.active { background: var(--primary); color: #fff; border: 0; box-shadow: 0 6px 16px rgba(0,0,0,.12); }
	.arshline-sidebar nav a:hover { background: rgba(37,99,255,.18); color: #0b1220; box-shadow: 0 8px 20px rgba(37,99,255,.18); }
        .arshline-sidebar .toggle { 
            margin: 0 1.25rem 1rem 1.25rem; cursor: pointer; color: var(--muted); font-size: 1rem; text-align: center; background: transparent; border: 1px solid var(--border); padding: .35rem .55rem; border-radius: 10px; display:flex; align-items:center; justify-content:center; gap:.4rem; font-family: 'Vazirmatn', system-ui, -apple-system, Segoe UI, Roboto, 'Inter', sans-serif;
        }
        .arshline-sidebar .toggle:hover { background: rgba(37,99,255,.08); }
        .arshline-sidebar .toggle .chev { display:inline-block; transition: transform .2s ease; line-height: 1; font-size: 18px; }
        .arshline-main {
            flex: 1; padding: 2.2rem 2rem; min-height: 100vh; transition: background .3s;
        }
        .arshline-header {
            display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;
        }
    /* Sun/Moon Toggle */
    .theme-toggle { --w: 56px; --h: 30px; position: relative; width: var(--w); height: var(--h); border-radius: 999px; background: linear-gradient(135deg, #60a5fa, #0ea5e9); display:inline-flex; align-items:center; padding: 3px; cursor: pointer; box-shadow: var(--shadow-card); border:1px solid var(--border); }
    .theme-toggle .knob { position: absolute; width: 24px; height: 24px; border-radius: 50%; background: #fff; transition: transform .25s ease, background .25s ease; box-shadow: 0 4px 10px rgba(0,0,0,.15); }
    .theme-toggle .sun, .theme-toggle .moon { font-size: 14px; color: #fff; opacity: .9; }
    .theme-toggle .sun { margin-inline-start: 9px; }
    .theme-toggle .moon { margin-inline-end: 9px; margin-inline-start: auto; }
    body.dark .theme-toggle { background: linear-gradient(135deg, #312e81, #0f172a); }
    /* knob translate differs in RTL */
    [dir='rtl'] body:not(.dark) .theme-toggle .knob { transform: translateX(-0px); }
    [dir='rtl'] body.dark .theme-toggle .knob { transform: translateX(26px); }
    [dir='ltr'] body.dark .theme-toggle .knob { transform: translateX(-26px); }
    .ar-btn { cursor:pointer; font-weight:700; border:0; border-radius:12px; background: var(--primary); color:#fff; padding:.55rem .95rem; box-shadow: 0 8px 18px rgba(0,0,0,.12); transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease; font-family: inherit; letter-spacing:.2px; text-decoration:none; display:inline-flex; align-items:center; gap:.35rem; line-height:1.1; }
    .ar-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,0,0,.18); }
    .ar-btn:disabled { opacity:.6; cursor:not-allowed; box-shadow:none; }
    .ar-btn--muted { background:#64748b; }
    .ar-btn--outline { background:transparent; color: var(--text); border:1px solid var(--border); }
    .ar-btn--danger { background:#b91c1c; }
    .ar-btn--soft { background: rgba(30,64,175,.1); color: var(--primary); }
    .ar-input { padding:.5rem .6rem; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-family: inherit; font-size: 1rem; }
    .ar-input::placeholder { color: var(--muted); opacity: .85; }
    .ar-select { padding:.45rem .5rem; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-family: inherit; font-size: 1rem; }
    .ar-dnd-handle { cursor: grab; user-select: none; display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; color:#fff; background: var(--primary); margin-inline-end:.5rem; }
    .ar-dnd-ghost { opacity:.6; transform: scale(.98); box-shadow: 0 6px 16px rgba(0,0,0,.12); }
    .ar-dnd-over { outline: none; background: transparent; }
    .ar-tool { font-family: inherit; font-size:.95rem; background: var(--accent); }
    .ar-dnd-placeholder { border:2px dashed transparent; border-radius:10px; margin:.35rem 0; background: transparent; opacity:.0; padding:0; pointer-events:none; transition: height .14s ease, margin .14s ease, opacity .14s ease, border-color .14s ease; }
    .ar-dnd-placeholder--dashed { opacity:1; border-color: var(--border); background: transparent; }
    .ar-draggable { transition: transform .16s ease, box-shadow .16s ease, background .16s ease; }
    .ar-draggable:active { cursor: grabbing; }
    .ar-dnd-ghost-proxy { position: fixed; top:-9999px; left:-9999px; pointer-events:none; padding:.3rem .6rem; border-radius:8px; background:var(--primary); color:#fff; font-family: inherit; font-size:.9rem; box-shadow: var(--shadow-card); }
    /* Preview-only mode */
    body.preview-only .arshline-sidebar, body.preview-only .arshline-header { display:none !important; }
    body.preview-only .arshline-main { padding: 1.2rem; }
        /* دارک مود */
        body.dark { background: var(--bg-surface); color: var(--text); }
    body.dark .arshline-main { color: var(--text); }
    /* View transition */
    #arshlineDashboardContent.view { animation: arViewIn .28s ease both; }
    @keyframes arViewIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    /* Modern solid cards (original design) */
    .tagline { text-align:center; font-size:1.05rem; font-weight:700; color: var(--text); margin-bottom: 1rem; }
    body.dark .tagline { color: #e5e7eb; }
    .ar-modern-cards { display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:24px; padding: 10px 0 24px; }
    .ar-card { position:relative; width:224px; height:315px; background:#1e40af; border-radius:20px; border-bottom-left-radius:112px; border-bottom-right-radius:112px; display:flex; justify-content:center; align-items:flex-start; overflow:hidden; box-shadow: 0 12px 0 #fff, inset 0 -10px 0 rgba(255,255,255,.18), 0 36px 0 rgba(0,0,0,.12); }
    .ar-card::before { content:""; position:absolute; top:-140px; left:-40%; width:100%; height:120%; background: rgba(255,255,255,.06); transform: rotate(35deg); pointer-events:none; filter: blur(5px); }
    .ar-card .icon { position:relative; width:98px; height:84px; background:#0d1321; border-bottom-left-radius:70px; border-bottom-right-radius:70px; box-shadow: 0 12px 0 rgba(0,0,0,.1), inset 0 -8px 0 #fff; z-index:2; display:flex; justify-content:center; align-items:flex-start; }
    .ar-card .icon::before { content:""; position:absolute; top:0; left:-50px; width:50px; height:50px; background:transparent; border-top-right-radius:50px; box-shadow: 15px -15px 0 15px #0d1321; }
    .ar-card .icon::after { content:""; position:absolute; top:0; right:-50px; width:50px; height:50px; background:transparent; border-top-left-radius:50px; box-shadow: -15px -15px 0 15px #0d1321; }
    .ar-card .icon ion-icon { color:#fff; position:relative; font-size:4.2em; --ionicon-stroke-width:24px; }
    .ar-card .content { position:absolute; width:100%; padding:21px; padding-top:105px; text-align:center; z-index:1; }
    .ar-card .content h2 { font-size:1.4rem; color:#fff; margin-bottom:12px; }
    .ar-card .content p { color:#f1f5f9; line-height:1.6; font-size:.95rem; }
    .ar-card--blue { background:#1e40af; }
    .ar-card--amber { background:#b45309; }
    .ar-card--violet { background:#6d28d9; }
    .ar-card--teal { background:#0e7490; }
    body.dark .ar-card { box-shadow: 0 12px 0 #0d1321, inset 0 -10px 0 rgba(255,255,255,.12), 0 36px 0 rgba(0,0,0,.35); }
    /* Toasts */
    .ar-toast-wrap { position: fixed; right: 1rem; bottom: 1rem; display: flex; flex-direction: column; gap: .6rem; z-index: 9999; }
    .ar-toast { display:flex; align-items:center; gap:.6rem; padding:.6rem .8rem; border-radius:12px; background: var(--surface); color: var(--text); border:1px solid var(--border); border-inline-start: 4px solid var(--primary); box-shadow: var(--shadow-card); transition: opacity .25s ease, transform .25s ease; }
    .ar-toast-ic { font-size: 18px; }
    .ar-toast--success { border-inline-start-color: var(--toast-success); }
    .ar-toast--error { border-inline-start-color: var(--toast-error); }
    .ar-toast--info { border-inline-start-color: var(--toast-info); }
    .ar-toast--warn { border-inline-start-color: var(--toast-warn); }
    /* Editor columns separation */
    .ar-settings { border-inline-end: 1px solid var(--border); padding-inline-end: 1rem; }
    .ar-preview { padding-inline-start: 1rem; }
    /* VC toggle switch styles (scoped) */
    .vc-toggle-container { display:inline-block; }
    .vc-small-switch { position: relative; display: inline-block; width: var(--vc-width,50px); height: var(--vc-height,25px); }
    .vc-small-switch input { display:none; }
    .vc-switch-label { position: absolute; inset: 0; cursor: pointer; background: var(--vc-off-color,#d1d3d4); border-radius: var(--vc-box-border-radius,18px); transition: background var(--vc-animation-speed,.15s ease-out); font-family: var(--vc-font-family,Arial); font-weight: var(--vc-font-weight,300); font-size: var(--vc-font-size,11px); color: var(--vc-off-font-color,#fff); }
    .vc-switch-label:before { content: attr(data-off); position: absolute; right: var(--vc-label-position-off,12px); line-height: var(--vc-height,25px); }
    .vc-switch-label:after  { content: attr(data-on); position: absolute; left: var(--vc-label-position-on,11px); line-height: var(--vc-height,25px); opacity: 0; }
    .vc-switch-handle { position: absolute; top: var(--vc-handle-top,5px); right: 5px; width: var(--vc-handle-width,15px); height: var(--vc-handle-height,15px); background: var(--vc-handle-color,#fff); border-radius: var(--vc-handle-border-radius,20px); transition: all var(--vc-animation-speed,.15s ease-out); box-shadow: var(--vc-handle-shadow,1px 1px 5px rgba(0,0,0,.2)); }
    .vc-small-switch input:checked ~ .vc-switch-label { background: var(--vc-on-color,#38cf5b); color: var(--vc-on-font-color,#fff); }
    .vc-small-switch input:checked ~ .vc-switch-label:before { opacity: 0; }
    .vc-small-switch input:checked ~ .vc-switch-label:after  { opacity: 1; }
    .vc-small-switch input:checked ~ .vc-switch-handle { right: calc(100% - var(--vc-handle-width,15px) - 5px); }
    /* RTL flip for Persian labels */
    /* In RTL: OFF ("خیر") stays right, ON ("بله") stays left */
    .vc-small-switch.vc-rtl .vc-switch-label:before { left: auto; right: var(--vc-label-position-off,12px); }
    .vc-small-switch.vc-rtl .vc-switch-label:after  { right: auto; left: var(--vc-label-position-on,11px); }
    .vc-small-switch.vc-rtl .vc-switch-handle { right: auto; left: 5px; }
    .vc-small-switch.vc-rtl input:checked ~ .vc-switch-handle { left: calc(100% - var(--vc-handle-width,15px) - 5px); right: auto; }
    .vc-small-switch.vc-rtl input:checked ~ .vc-switch-label:before { opacity: 0; }
    .vc-small-switch.vc-rtl input:checked ~ .vc-switch-label:after  { opacity: 1; }
    /* Validation helpers (minimal, scoped) */
    .ar-input--invalid { border-color: #b91c1c !important; box-shadow: 0 0 0 3px rgba(185,28,28,.12); }
    .ar-required { color:#b91c1c; margin: 0 .2rem; font-weight: 700; }
    .ar-field-error { color:#b91c1c; margin-top:.3rem; display:none; }
     </style>
    <script>
    const ARSHLINE_REST = '<?php echo esc_js( rest_url('arshline/v1/') ); ?>';
    const ARSHLINE_NONCE = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    const ARSHLINE_CAN_MANAGE = <?php echo ( current_user_can('edit_posts') || current_user_can('manage_options') ) ? 'true' : 'false'; ?>;
    const ARSHLINE_LOGIN_URL = '<?php echo esc_js( wp_login_url( get_permalink() ) ); ?>';
    </script>
    <script>
    // Tabs: render content per menu item
    document.addEventListener('DOMContentLoaded', function() {
    var content = document.getElementById('arshlineDashboardContent');
        var links = document.querySelectorAll('.arshline-sidebar nav a[data-tab]');
        var sidebar = document.querySelector('.arshline-sidebar');
        var sidebarToggle = document.getElementById('arSidebarToggle');
        // Debug helpers
        var AR_DEBUG = false;
        try { AR_DEBUG = (localStorage.getItem('arshDebug') === '1'); } catch(_){ }
        function clog(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.log.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
        function cwarn(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.warn.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
        function cerror(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.error.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
        try { window.arshSetDebug = function(v){ try { localStorage.setItem('arshDebug', v ? '1' : '0'); } catch(_){ } }; } catch(_){ }

        function setSidebarClosed(closed, persist){
            if (!sidebar) return;
            sidebar.classList.toggle('closed', !!closed);
            try {
                if (sidebarToggle) {
                    sidebarToggle.setAttribute('aria-expanded', closed ? 'false' : 'true');
                    var ch = sidebarToggle.querySelector('.chev');
                    if (ch) ch.textContent = closed ? '❯' : '❮';
                }
            } catch(_){ }
            if (persist){ try { localStorage.setItem('arSidebarClosed', closed ? '1' : '0'); } catch(_){ } }
        }
        try { var initClosed = localStorage.getItem('arSidebarClosed'); if (initClosed === '1') setSidebarClosed(true, false); } catch(_){ }

        // Helpers for safe rich question HTML
        function htmlToText(html){ try { var d=document.createElement('div'); d.innerHTML=String(html||''); return d.textContent||d.innerText||''; } catch(_){ return String(html||''); } }
        function escapeAttr(s){
            try {
                return String(s||'')
                    .replace(/&/g,'&amp;')
                    .replace(/"/g,'&quot;')
                    .replace(/'/g,'&#39;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;');
            } catch(_){ return String(s||''); }
        }
        function sanitizeQuestionHtml(html){
            try {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = String(html||'');
                var allowed = { B:true, I:true, U:true, SPAN:true };
                (function walk(node){
                    var child = node.firstChild;
                    while(child){
                        var next = child.nextSibling;
                        if (child.nodeType === 1){
                            var tag = child.tagName;
                            // Convert <font color> to <span style="color:...">
                            if (tag === 'FONT'){
                                try {
                                    var span = document.createElement('span');
                                    var col = child.getAttribute('color') || (child.style && child.style.color) || '';
                                    if (col) span.setAttribute('style', 'color:'+col);
                                    while(child.firstChild){ span.appendChild(child.firstChild); }
                                    node.replaceChild(span, child);
                                    child = span;
                                    tag = 'SPAN';
                                } catch(_){}
                            }
                            if (!allowed[tag]){
                                while(child.firstChild){ node.insertBefore(child.firstChild, child); }
                                node.removeChild(child);
                            } else {
                                for (var i = child.attributes.length - 1; i >= 0; i--) { child.removeAttribute(child.attributes[i].name); }
                                if (tag === 'SPAN'){
                                    var color = child.style && child.style.color ? child.style.color : '';
                                    if (color){ child.setAttribute('style','color:'+color); } else { child.removeAttribute('style'); }
                                }
                                walk(child);
                            }
                        }
                        child = next;
                    }
                })(wrapper);
                return wrapper.innerHTML;
            } catch(_) { return html ? String(html) : ''; }
        }

        // Simple hash-based router so browser Back works correctly
        var _arNavSilence = 0;
        function setHash(h){
            var target = '#' + h;
            if (location.hash !== target){
                _arNavSilence++;
                location.hash = h;
                setTimeout(function(){ _arNavSilence = Math.max(0, _arNavSilence - 1); }, 0);
            }
        }
        function routeFromHash(){
            var raw = (location.hash||'').replace('#','').trim();
            if (!raw){ renderTab('dashboard'); return; }
            var parts = raw.split('/');
            if (['dashboard','forms','submissions','reports','users'].includes(parts[0])){ renderTab(parts[0]); return; }
            if (parts[0]==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { renderFormBuilder(id); return; } }
            if (parts[0]==='editor' && parts[1]){ var id = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); if (id) { renderFormEditor(id, { index: isNaN(idx)?0:idx }); return; } }
            if (parts[0]==='preview' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { renderFormPreview(id); return; } }
            renderTab('dashboard');
        }
        window.addEventListener('hashchange', function(){ if (_arNavSilence>0) return; routeFromHash(); });

        // theme switch (sun/moon)
        var themeToggle = document.getElementById('arThemeToggle');
        try { if (localStorage.getItem('arshDark') === '1') document.body.classList.add('dark'); } catch(_){ }
        if (themeToggle){
            function applyAria(){ themeToggle.setAttribute('aria-checked', document.body.classList.contains('dark') ? 'true' : 'false'); }
            applyAria();
            var toggle = function(){ document.body.classList.toggle('dark'); applyAria(); try { localStorage.setItem('arshDark', document.body.classList.contains('dark') ? '1' : '0'); } catch(_){ } };
            themeToggle.addEventListener('click', toggle);
            themeToggle.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); toggle(); }});
        }
        if (sidebarToggle){
            var tgl = function(){ var isClosed = sidebar && sidebar.classList.contains('closed'); setSidebarClosed(!isClosed, true); };
            sidebarToggle.addEventListener('click', tgl);
            sidebarToggle.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); tgl(); }});
        }

        function setActive(tab){
            links.forEach(function(a){
                if (a.getAttribute('data-tab') === tab) a.classList.add('active'); else a.classList.remove('active');
            });
        }
        function card(title, subtitle, icon){
            var ic = icon ? ('<span style="font-size:22px;margin-inline-start:.4rem;opacity:.85">'+icon+'</span>') : '';
            return '<div class="card glass" style="display:flex;align-items:center;gap:.6rem;">\
                '+ic+'\
                <div>\
                  <div class="title">'+title+'</div>\
                  <div class="hint">'+(subtitle||'')+'</div>\
                </div>\
            </div>';
        }

        function suggestPlaceholder(fmt){
            switch(fmt){
                case 'email': return 'example@mail.com';
                case 'mobile_ir': return '09123456789';
                case 'mobile_intl': return '+14155552671';
                case 'tel': return '021-12345678';
                case 'numeric': return '123456';
                case 'national_id_ir': return '0012345678';
                case 'postal_code_ir': return '1234567890';
                case 'fa_letters': return 'مثال فارسی';
                case 'en_letters': return 'Sample text';
                case 'ip': return '192.168.1.1';
                case 'time': return '14:30';
                case 'date_jalali': return '1403/01/15';
                case 'date_greg': return '2025-09-22';
                case 'regex': return 'مطابق الگو';
                case 'free_text': return 'پاسخ خود را بنویسید';
                default: return '';
            }
        }
        function inputAttrsByFormat(fmt){
            var a = { type:'text', inputmode:'', pattern:'' };
            if (fmt==='email') a.type='email';
            else if (fmt==='numeric') { a.inputmode='numeric'; a.pattern='[0-9]*'; }
            else if (fmt==='mobile_ir' || fmt==='mobile_intl' || fmt==='tel' || fmt==='national_id_ir' || fmt==='postal_code_ir') { a.inputmode='tel'; }
            else if (fmt==='time') a.type='time';
            else if (fmt==='date_greg') a.type='date';
            return a;
        }

        function saveFields(){
            var builder = document.getElementById('arBuilder');
            var id = parseInt(builder.dataset.formId||'0');
            var idx = parseInt(builder.dataset.fieldIndex||'-1');
            var canvas = document.getElementById('arCanvas');
            var edited = Array.from(canvas.children).map(function(el){ return JSON.parse(el.dataset.props||'{}'); })[0] || {};
            var btn = document.getElementById('arSaveFields');
            if (btn){ btn.disabled = true; btn.textContent = 'در حال ذخیره...'; }
            if (!ARSHLINE_CAN_MANAGE){ notify('برای ویرایش فرم باید وارد شوید یا دسترسی داشته باشید', 'error'); if (btn){ btn.disabled=false; btn.textContent='ذخیره'; } return Promise.resolve(false); }
            return fetch(ARSHLINE_REST + 'forms/'+id)
                .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(data){
                    var arr = (data && data.fields) ? data.fields.slice() : [];
                    if (idx >=0 && idx < arr.length) { arr[idx] = edited; }
                    else { arr.push(edited); }
                    return fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) });
                })
                .then(async r=>{ if(!r.ok){ if (r.status===401){ if (typeof handle401 === 'function') handle401(); else notify('اجازهٔ انجام این عملیات را ندارید. لطفاً وارد شوید یا با مدیر تماس بگیرید.', 'error'); } let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(){ notify('ذخیره شد', 'success'); return true; })
                .catch(function(e){ console.error(e); notify('ذخیره تغییرات ناموفق بود', 'error'); return false; })
                .finally(function(){ if (btn){ btn.disabled = false; btn.textContent = 'ذخیره'; }});
        }

        function renderFormPreview(id){
            try { setSidebarClosed(true, false); } catch(_){ }
            try { setHash('preview/'+id); } catch(_){ }
            document.body.classList.add('preview-only');
            var content = document.getElementById('arshlineDashboardContent');
            content.innerHTML = '<div class="card glass" style="padding:1.2rem;max-width:720px;margin:0 auto;">\
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">\
                    <div class="title">پیش‌نمایش فرم #'+ id +'</div>\
                    <button id="arPreviewBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                </div>\
                <div id="arFormPreviewFields" style="display:flex;flex-direction:column;gap:.8rem;"></div>\
                <div style="margin-top:1rem;text-align:left;"><button id="arPreviewSubmit" class="ar-btn">ارسال</button></div>\
            </div>';
            fetch(ARSHLINE_REST + 'forms/' + id)
                .then(r=>r.json())
                .then(function(data){
                    var fwrap = document.getElementById('arFormPreviewFields');
                    var qNum = 0;
                    var questionProps = [];
                    (data.fields||[]).forEach(function(f){
                        var p = f.props || f;
                        var type = p.type || f.type || 'short_text';
                        if (type === 'welcome' || type === 'thank_you'){
                            var block = document.createElement('div');
                            block.className = 'card glass';
                            block.style.cssText = 'padding:.8rem;';
                            var heading = (p.heading && String(p.heading).trim()) || (type==='welcome'?'پیام خوش‌آمد':'پیام تشکر');
                            var message = (p.message && String(p.message).trim()) || '';
                            var img = (p.image_url && String(p.image_url).trim()) ? ('<div style="margin-bottom:.4rem"><img src="'+String(p.image_url).trim()+'" alt="" style="max-width:100%;border-radius:10px"/></div>') : '';
                            block.innerHTML = (heading ? ('<div class="title" style="margin-bottom:.35rem;">'+heading+'</div>') : '') + img + (message ? ('<div class="hint">'+message+'</div>') : '');
                            fwrap.appendChild(block);
                            return; // no input for message blocks
                        }
                        // question-type field
                        var fmt = p.format || 'free_text';
                        var attrs = inputAttrsByFormat(fmt);
                        var phS = p.placeholder && p.placeholder.trim() ? p.placeholder : suggestPlaceholder(fmt);
                        var row = document.createElement('div');
                        var inputId = 'f_'+(f.id||Math.random().toString(36).slice(2));
                        var descId = inputId+'_desc';
                        var showQ = p.question && String(p.question).trim();
                        var numbered = (p.numbered !== false);
                        if (numbered) qNum += 1;
                        var numberStr = numbered ? (qNum + '. ') : '';
                        var sanitizedQ = sanitizeQuestionHtml(showQ || '');
                        var ariaQ = htmlToText(sanitizedQ);
                        row.innerHTML = (showQ ? ('<div class="hint" style="margin-bottom:.25rem">'+numberStr+sanitizedQ+'</div>') : '')+
                            '<input id="'+inputId+'" class="ar-input" style="width:100%" '+(attrs.type?('type="'+attrs.type+'"'):'')+' '+(attrs.inputmode?('inputmode="'+attrs.inputmode+'"'):'')+' '+(attrs.pattern?('pattern="'+attrs.pattern+'"'):'')+' placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" ' + (showQ?('aria-label="'+escapeAttr(numberStr+ariaQ)+'"'):'') + ' />' +
                            (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ (p.description||'') +'</div>') : '');
                        fwrap.appendChild(row);
                        questionProps.push(p);
                    });
                    // apply masks
                    fwrap.querySelectorAll('input[data-field-id]').forEach(function(inp, idx){
                        var props = questionProps[idx] || {};
                        applyInputMask(inp, props);
                        if ((props.format||'') === 'date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){
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

        function renderFormEditor(id, opts){
            if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); renderTab('forms'); return; }
            try { setSidebarClosed(true, false); } catch(_){ }
            try { var idxHash = (opts && typeof opts.index!=='undefined') ? parseInt(opts.index) : 0; setHash('editor/'+id+'/'+(isNaN(idxHash)?0:idxHash)); } catch(_){ }
            document.body.classList.remove('preview-only');
            var content = document.getElementById('arshlineDashboardContent');
            var hiddenCanvas = '<div id="arCanvas" style="display:none"><div class="ar-item" data-props="{}"></div></div>';
            var fieldIndex = (opts && typeof opts.index !== 'undefined') ? parseInt(opts.index) : -1;
            content.innerHTML = '<div id="arBuilder" class="card glass" data-form-id="'+id+'" data-field-index="'+fieldIndex+'" style="padding:1rem;max-width:980px;margin:0 auto;">\
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">\
                    <div class="title" id="arEditorTitle">...</div>\
                    <div style="display:flex;gap:.5rem;align-items:center;">\
                        <button id="arEditorPreview" class="ar-btn ar-btn--outline">پیش‌نمایش</button>\
                        <button id="arEditorBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                    </div>\
                </div>\
                <div style="display:flex;gap:1rem;align-items:flex-start;">\
                    <div class="ar-settings" style="width:380px;flex:0 0 380px;">\
                        <div class="title" style="margin-bottom:.6rem;">تنظیمات فیلد</div>\
                        <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                            <label class="hint">سؤال</label>\
                            <div id="fQuestionToolbar" style="display:flex;gap:.35rem;align-items:center;">\
                                <button id="fQBold" class="ar-btn ar-btn--outline" type="button" title="پررنگ"><b>B</b></button>\
                                <button id="fQItalic" class="ar-btn ar-btn--outline" type="button" title="مورب"><i>I</i></button>\
                                <button id="fQUnder" class="ar-btn ar-btn--outline" type="button" title="زیرخط"><u>U</u></button>\
                                <input id="fQColor" type="color" title="رنگ" style="margin-inline-start:.25rem;width:36px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);" />\
                            </div>\
                            <div id="fQuestionRich" class="ar-input" contenteditable="true" style="min-height:60px;line-height:1.8;" placeholder="متن سؤال"></div>\
                        </div>\
                        <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                            <label class="hint">نوع ورودی</label>\
                            <select id="fType" class="ar-select">\
                                <option value="free_text">متن آزاد</option>\
                                <option value="email">ایمیل</option>\
                                <option value="numeric">عدد</option>\
                                <option value="date_jalali">تاریخ شمسی</option>\
                                <option value="date_greg">تاریخ میلادی</option>\
                                <option value="time">زمان</option>\
                                <option value="mobile_ir">موبایل ایران</option>\
                                <option value="mobile_intl">موبایل بین‌المللی</option>\
                                <option value="national_id_ir">کد ملی ایران</option>\
                                <option value="postal_code_ir">کد پستی ایران</option>\
                                <option value="tel">تلفن</option>\
                                <option value="fa_letters">حروف فارسی</option>\
                                <option value="en_letters">حروف انگلیسی</option>\
                                <option value="ip">IP</option>\
                            </select>\
                        </div>\
                        <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">\
                            <span class="hint">اجباری</span>\
                            <label class="toggle-switch" title="اجباری" style="transform:scale(.9)">\
                                <input type="checkbox" id="fRequired">\
                                <span class="toggle-switch-background"></span>\
                                <span class="toggle-switch-handle"></span>\
                            </label>\
                        </div>\
                        <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">\
                            <span class="hint">شماره‌گذاری سؤال</span>\
                            <label class="toggle-switch" title="نمایش شماره سؤال" style="transform:scale(.9)">\
                                <input type="checkbox" id="fNumbered">\
                                <span class="toggle-switch-background"></span>\
                                <span class="toggle-switch-handle"></span>\
                            </label>\
                        </div>\
                                                <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:6px">\
                                                        <span class="hint">توضیحات</span>\
                                                                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">\
                                                                                        <label class="vc-small-switch vc-rtl">\
                                                                <input type="checkbox" id="fDescToggle" class="vc-switch-input"/>\
                                                                <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>\
                                                                <span class="vc-switch-handle"></span>\
                                                            </label>\
                                                        </div>\
                                                </div>\
                        <div class="field" id="fDescWrap" style="display:none">\
                            <textarea id="fDescText" class="ar-input" rows="2" placeholder="توضیح زیر سؤال"></textarea>\
                        </div>\
                        <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">\
                            <label class="hint">متن راهنما (placeholder)</label>\
                            <input id="fHelp" class="ar-input" placeholder="مثال: پاسخ را وارد کنید"/>\
                        </div>\
                        <div style="margin-top:12px">\
                            <button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button>\
                        </div>\
                    </div>\
                    <div class="ar-preview" style="flex:1;">\
                        <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>\
                        <div id="pvWrap">\
                            <div id="pvQuestion" class="hint" style="display:none;margin-bottom:.25rem"></div>\
                            <div id="pvDesc" class="hint" style="display:none;margin-bottom:.35rem"></div>\
                            <input id="pvInput" class="ar-input" style="width:100%" />\
                            <div id="pvHelp" class="hint" style="display:none"></div>\
                            <div id="pvErr" class="hint" style="color:#b91c1c;margin-top:.3rem"></div>\
                        </div>\
                    </div>\
                </div>\
            </div>' + hiddenCanvas;

            document.getElementById('arEditorBack').onclick = function(){ renderFormBuilder(id); };
            var prevBtnE = document.getElementById('arEditorPreview'); if (prevBtnE) prevBtnE.onclick = function(){ renderFormPreview(id); };
            content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');

            var defaultProps = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
            fetch(ARSHLINE_REST + 'forms/' + id)
                .then(r=>r.json())
                .then(function(data){
                    function setDirty(d){
                        try {
                            window._arDirty = !!d;
                            if (window._arDirty) {
                                window.onbeforeunload = function(){ return 'تغییرات ذخیره‌نشده دارید.'; };
                            } else {
                                window.onbeforeunload = null;
                            }
                        } catch(_){ }
                    }
                    var titleEl = document.getElementById('arEditorTitle');
                    var formTitle = (data && data.meta && data.meta.title) ? String(data.meta.title) : '';
                    var creating = !!(opts && opts.creating);
                    if (titleEl) titleEl.textContent = creating ? ('ایجاد فرم — ' + (formTitle||(' #' + id))) : ('ویرایش فرم #' + id + (formTitle?(' — ' + formTitle):''));
                    var fields = data.fields || [];
                    var idx = fieldIndex;
                    if (idx < 0 || idx >= fields.length) idx = 0;
                    var base = fields[idx] || defaultProps;
                    var field = base.props || base || defaultProps;
                    var fType = field.type || base.type || 'short_text';
                    // ensure defaults by type
                    if (fType === 'short_text'){
                        field.type = 'short_text';
                        field.label = 'پاسخ کوتاه';
                    } else if (fType === 'welcome'){
                        field.type = 'welcome';
                        field.label = 'پیام خوش‌آمد';
                        if (typeof field.heading === 'undefined') field.heading = 'خوش آمدید';
                        if (typeof field.message === 'undefined') field.message = '';
                        if (typeof field.image_url === 'undefined') field.image_url = '';
                    } else if (fType === 'thank_you'){
                        field.type = 'thank_you';
                        field.label = 'پیام تشکر';
                        if (typeof field.heading === 'undefined') field.heading = 'با تشکر از شما';
                        if (typeof field.message === 'undefined') field.message = '';
                        if (typeof field.image_url === 'undefined') field.image_url = '';
                    }
                    // inject hidden props
                    var canvasEl = document.querySelector('#arCanvas .ar-item');
                    if (canvasEl) canvasEl.setAttribute('data-props', JSON.stringify(field));
                    // setup controls based on type
                    if (field.type === 'welcome' || field.type === 'thank_you'){
                        var sWrap = document.querySelector('.ar-settings');
                        var pWrap = document.querySelector('.ar-preview');
                        if (sWrap){
                            sWrap.innerHTML = '\
                                <div class="title" style="margin-bottom:.6rem;">تنظیمات پیام</div>\
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                                    <label class="hint">عنوان</label>\
                                    <input id="wHeading" class="ar-input" placeholder="'+(field.type==='welcome'?'مثال: خوش آمدید':'مثال: با تشکر')+'" />\
                                </div>\
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                                    <label class="hint">متن پیام</label>\
                                    <textarea id="wMessage" class="ar-input" rows="3" placeholder="متن پیام"></textarea>\
                                </div>\
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                                    <label class="hint">آدرس تصویر (اختیاری)</label>\
                                    <input id="wImageUrl" class="ar-input" placeholder="https://..." />\
                                    <div style="display:flex;gap:.5rem;align-items:center;">\
                                        <input id="wImageFile" type="file" accept="image/*" style="display:none" />\
                                        <button id="wUploadBtn" class="ar-btn ar-btn--outline" type="button">انتخاب و آپلود تصویر</button>\
                                        <span id="wUploadStat" class="hint"></span>\
                                    </div>\
                                </div>\
                                <div style="margin-top:12px">\
                                    <button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button>\
                                </div>';
                        }
                        if (pWrap){
                            pWrap.innerHTML = '\
                                <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>\
                                <div id="wPreview" class="card glass" style="padding:.8rem;"></div>';
                        }
                        function applyMsgPreview(p){
                            var pv = document.getElementById('wPreview'); if (!pv) return;
                            var heading = (p.heading && String(p.heading).trim()) || (p.type==='welcome'?'پیام خوش‌آمد':'پیام تشکر');
                            var message = (p.message && String(p.message).trim()) || '';
                            var img = (p.image_url && String(p.image_url).trim()) ? ('<div style="margin-bottom:.4rem"><img src="'+String(p.image_url).trim()+'" alt="" style="max-width:100%;border-radius:10px"/></div>') : '';
                            pv.innerHTML = (heading ? ('<div class="title" style="margin-bottom:.35rem;">'+heading+'</div>') : '') + img + (message ? ('<div class="hint">'+message+'</div>') : '');
                        }
                        // Bind settings inputs for message editor
                        var hIn = document.getElementById('wHeading');
                        var mIn = document.getElementById('wMessage');
                        var uIn = document.getElementById('wImageUrl');
                        var uBtn = document.getElementById('wUploadBtn');
                        var fIn = document.getElementById('wImageFile');
                        var uStat = document.getElementById('wUploadStat');
                        if (hIn) { hIn.value = field.heading || ''; hIn.addEventListener('input', function(){ field.heading = hIn.value; updateHiddenProps(field); applyMsgPreview(field); setDirty(true); }); }
                        if (mIn) { mIn.value = field.message || ''; mIn.addEventListener('input', function(){ field.message = mIn.value; updateHiddenProps(field); applyMsgPreview(field); setDirty(true); }); }
                        if (uIn) { uIn.value = field.image_url || ''; uIn.addEventListener('input', function(){ field.image_url = uIn.value; updateHiddenProps(field); applyMsgPreview(field); setDirty(true); }); }
                        if (uBtn && fIn) {
                            uBtn.addEventListener('click', function(){ fIn.click(); });
                            fIn.addEventListener('change', function(){
                                if (!fIn.files || !fIn.files[0]) return;
                                var fd = new FormData(); fd.append('file', fIn.files[0]);
                                if (uStat) uStat.textContent = 'در حال آپلود...'; if (uBtn) uBtn.disabled = true;
                                fetch(ARSHLINE_REST + 'upload', { method:'POST', credentials:'same-origin', headers:{ 'X-WP-Nonce': ARSHLINE_NONCE }, body: fd })
                                    .then(async function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401==='function') handle401(); } let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                                    .then(function(obj){ if (obj && obj.url){ uIn.value = obj.url; field.image_url = obj.url; updateHiddenProps(field); applyMsgPreview(field); setDirty(true); notify('آپلود شد', 'success'); } })
                                    .catch(function(){ notify('آپلود تصویر ناموفق بود', 'error'); })
                                    .finally(function(){ if (uStat) uStat.textContent=''; if (uBtn) uBtn.disabled=false; fIn.value=''; });
                            });
                        }
                        applyMsgPreview(field);
                        var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
                        return; // stop short_text editor init
                    }
                    // short_text editor
                    var sel = document.getElementById('fType');
                    var req = document.getElementById('fRequired');
                    var dTg = document.getElementById('fDescToggle');
                    var dTx = document.getElementById('fDescText');
                    var dWrap = document.getElementById('fDescWrap');
                    var help = document.getElementById('fHelp');
                    var qEl = document.getElementById('fQuestionRich');
                    var qBold = document.getElementById('fQBold');
                    var qItalic = document.getElementById('fQItalic');
                    var qUnder = document.getElementById('fQUnder');
                    var qColor = document.getElementById('fQColor');
                    var numEl = document.getElementById('fNumbered');

                    function updateHiddenProps(p){
                        var el = document.querySelector('#arCanvas .ar-item');
                        if (el) el.setAttribute('data-props', JSON.stringify(p));
                    }
                    function applyPreviewFrom(p){
                        var fmt = p.format || 'free_text';
                        var attrs = inputAttrsByFormat(fmt);
                        var inp = document.getElementById('pvInput');
                        if (!inp) return;
                        // Fully teardown any previous Jalali datepicker bindings
                        if (typeof jQuery !== 'undefined'){
                            try { if (jQuery.fn && jQuery.fn.pDatepicker) { jQuery(inp).pDatepicker('destroy'); } } catch(e){}
                            try { jQuery(inp).off('.pDatepicker'); } catch(e){}
                            try { jQuery(inp).removeData('datepicker').removeData('pDatepicker'); } catch(e){}
                        }
                        try { inp.classList.remove('pwt-datepicker-input-element'); } catch(e){}
                        // Replace the input node to ensure no lingering handlers
                        try {
                            var parent = inp.parentNode;
                            var clone = inp.cloneNode(false);
                            clone.id = 'pvInput';
                            clone.className = 'ar-input';
                            parent.replaceChild(clone, inp);
                            inp = clone;
                        } catch(e){}
                        // Reset attributes
                        inp.value = '';
                        inp.removeAttribute('placeholder');
                        inp.setAttribute('type', (attrs && attrs.type) ? attrs.type : 'text');
                        if (attrs && attrs.inputmode) inp.setAttribute('inputmode', attrs.inputmode); else inp.removeAttribute('inputmode');
                        if (attrs && attrs.pattern) inp.setAttribute('pattern', attrs.pattern); else inp.removeAttribute('pattern');
                        var ph = (p.placeholder && p.placeholder.trim()) ? p.placeholder : (fmt==='free_text' ? 'پاسخ را وارد کنید' : suggestPlaceholder(fmt));
                        inp.setAttribute('placeholder', ph || '');
                        var qNode = document.getElementById('pvQuestion');
                        if (qNode){
                            var showQ = (p.question && String(p.question).trim());
                            qNode.style.display = showQ ? 'block' : 'none';
                            // Number based on actual position among question fields
                            var qIndex = 1;
                            try {
                                var beforeCount = 0;
                                (fields||[]).forEach(function(ff, i3){ if (i3 <= idx){ var pp = ff.props || ff; var t = pp.type || ff.type || 'short_text'; if (t !== 'welcome' && t !== 'thank_you'){ beforeCount += 1; } } });
                                qIndex = beforeCount;
                            } catch(_){ qIndex = (idx+1); }
                            var numPrefix = (p.numbered ? (qIndex + '. ') : '');
                            var sanitized = sanitizeQuestionHtml(showQ || '');
                            qNode.innerHTML = showQ ? (numPrefix + sanitized) : '';
                        }
                        // label removed; question sits above input
                        var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent = p.description || ''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; }
                        var helpEl = document.getElementById('pvHelp'); if (helpEl) { helpEl.textContent=''; helpEl.style.display='none'; }
                        // Attach Jalali datepicker only for date_jalali
                        if (fmt==='date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.pDatepicker){
                            try { jQuery(inp).pDatepicker({ format:'YYYY/MM/DD', initialValue:false }); } catch(e){}
                        }
                        // Attach validation/mask to editor preview input
                        try { applyInputMask(inp, p); } catch(e){}
                    }
                    function sync(){ field.label = 'پاسخ کوتاه'; field.type = 'short_text'; updateHiddenProps(field); applyPreviewFrom(field); }

                    if (sel){ sel.value = field.format || 'free_text'; sel.addEventListener('change', function(){ field.format = sel.value || 'free_text'; var i=document.getElementById('pvInput'); if(i) i.value=''; sync(); setDirty(true); }); }
                    if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; sync(); setDirty(true); }); }
                    if (dTg){ dTg.checked = !!field.show_description; if (dWrap) dWrap.style.display = field.show_description ? 'block':'none'; dTg.addEventListener('change', function(){ field.show_description = !!dTg.checked; if(dWrap){ dWrap.style.display = field.show_description ? 'block':'none'; } sync(); setDirty(true); }); }
                    if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; sync(); setDirty(true); }); }
                    if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){ field.placeholder = help.value; sync(); setDirty(true); }); }
                    if (qEl){ qEl.innerHTML = sanitizeQuestionHtml(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); }); }
                    if (qBold){ qBold.addEventListener('click', function(e){ e.preventDefault(); document.execCommand('bold'); if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (qItalic){ qItalic.addEventListener('click', function(e){ e.preventDefault(); document.execCommand('italic'); if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (qUnder){ qUnder.addEventListener('click', function(e){ e.preventDefault(); document.execCommand('underline'); if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (qColor){ qColor.addEventListener('input', function(){ try { document.execCommand('foreColor', false, qColor.value); } catch(_){} if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (numEl){ numEl.checked = field.numbered !== false; field.numbered = numEl.checked; numEl.addEventListener('change', function(){ field.numbered = !!numEl.checked; sync(); setDirty(true); }); }

                    applyPreviewFrom(field);
                    var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
                });
        }

        function addNewField(formId){
            var defaultProps = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
            fetch(ARSHLINE_REST + 'forms/'+formId)
                .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(data){
                    var arr = (data && data.fields) ? data.fields.slice() : [];
                    var hasThank = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; }) !== -1;
                    var insertAt = hasThank ? (arr.length - 1) : arr.length;
                    if (insertAt < 0 || insertAt > arr.length) insertAt = arr.length;
                    arr.splice(insertAt, 0, defaultProps);
                    return fetch(ARSHLINE_REST + 'forms/'+formId+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) }).then(async r=>{ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return insertAt; });
                })
                .then(function(newIndex){ renderFormEditor(formId, { index: newIndex }); })
                .catch(function(){ notify('افزودن فیلد ناموفق بود', 'error'); });
        }

        function renderFormBuilder(id){
            if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); renderTab('forms'); return; }
            try { setSidebarClosed(true, false); } catch(_){ }
            document.body.classList.remove('preview-only');
            try { setHash('builder/'+id); } catch(_){ }
            var content = document.getElementById('arshlineDashboardContent');
            content.innerHTML = '<div class="card glass" style="padding:1rem;max-width:1080px;margin:0 auto;">\
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">\
                    <div class="title">ویرایش فرم #'+id+'</div>\
                    <div style="display:flex;gap:.5rem;align-items:center;">\
                        <button id="arBuilderPreview" class="ar-btn ar-btn--outline">پیش‌نمایش</button>\
                        <button id="arBuilderBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                    </div>\
                </div>\
                <div style="display:flex;gap:1rem;align-items:flex-start;">\
                    <div id="arFormSide" style="flex:1;">\
                        <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش فرم</div>\
                        <div id="arBulkToolbar" style="display:flex;align-items:center;gap:.8rem;margin-bottom:.6rem;">\
                            <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">\
                                <input id="arSelectAll" type="checkbox" />\
                                <span class="hint">انتخاب همه</span>\
                            </label>\
                            <button id="arBulkDelete" class="ar-btn" disabled>حذف انتخاب‌شده‌ها</button>\
                        </div>\
                        <div id="arFormFieldsList" style="display:flex;flex-direction:column;gap:.8rem;"></div>\
                    </div>\
                    <div id="arToolsSide" style="width:300px;flex:0 0 300px;border-inline-start:1px solid var(--border);padding-inline-start:1rem;">\
                        <div class="title" style="margin-bottom:.6rem;">ابزارها</div>\
                        <button id="arAddShortText" class="ar-btn" style="width:100%" draggable="true">افزودن سؤال با پاسخ کوتاه</button>\
                        <div style="height:.5rem"></div>\
                        <button id="arAddWelcome" class="ar-btn ar-btn--soft" style="width:100%">افزودن پیام خوش‌آمد</button>\
                        <div style="height:.4rem"></div>\
                        <button id="arAddThank" class="ar-btn ar-btn--soft" style="width:100%">افزودن پیام تشکر</button>\
                    </div>\
                </div>\
            </div>';
            document.getElementById('arBuilderBack').onclick = function(){ renderTab('forms'); };
            var prevBtn = document.getElementById('arBuilderPreview'); if (prevBtn) prevBtn.onclick = function(){ renderFormPreview(id); };
            content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');
            var list = document.getElementById('arFormFieldsList');
            list.textContent = 'در حال بارگذاری...';
            fetch(ARSHLINE_REST + 'forms/'+id)
                .then(r=>r.json())
                .then(function(data){
                    try { if (AR_DEBUG) { var dbgList = (data.fields||[]).map(function(f){ return { id:f.id, sort:f.sort, type:(f.props&&f.props.type)||f.type }; }); clog('Builder:load fields', dbgList); } } catch(_){ }
                    var fields = data.fields || [];
                    // Build visible order: welcome (if any), then regulars, then thank_you (if any)
                    var wIdx = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='welcome'; });
                    var tIdx = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; });
                    var welcome = (wIdx !== -1) ? fields[wIdx] : null;
                    var thankyou = (tIdx !== -1) ? fields[tIdx] : null;
                    var regulars = fields.map(function(x,i){ return {item:x, original:i}; }).filter(function(z){ var p=z.item.props||z.item; var ty=p.type||z.item.type; return ty!=='welcome' && ty!=='thank_you'; });
                    var visible = [];
                    var visibleMap = [];
                    if (welcome){ visible.push(welcome); visibleMap.push(wIdx); }
                    regulars.forEach(function(z){ visible.push(z.item); visibleMap.push(z.original); });
                    if (thankyou){ visible.push(thankyou); visibleMap.push(tIdx); }
                    if (!visible.length){ list.innerHTML = '<div class="hint">هنوز فیلدی اضافه نشده است.</div>'; }
                    else {
                        var qCounter = 0;
                        list.innerHTML = visible.map(function(f, vIdx){
                            var p = f.props || f; var type = p.type || f.type || 'short_text';
                            if (type==='welcome' || type==='thank_you'){
                                var ttl = (type==='welcome'?'پیام خوش‌آمد':'پیام تشکر');
                                var head = (p.heading&&String(p.heading).trim()) || ttl;
                                return '<div class="card" data-vid="'+vIdx+'" data-oid="'+visibleMap[vIdx]+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">\
                                        <div class="hint">'+ttl+' — '+head+'</div>\
                                        <div style="display:flex;gap:.6rem;align-items:center;">\
                                            <a href="#" class="arEditField" data-id="'+id+'" data-index="'+visibleMap[vIdx]+'">ویرایش</a>\
                                            <a href="#" class="arDeleteMsg" title="حذف '+ttl+'" style="color:#d32f2f;">حذف</a>\
                                        </div>\
                                    </div>\
                                </div>';
                            }
                            var q = (p.question&&p.question.trim()) || '';
                            var qHtml = q ? sanitizeQuestionHtml(q) : 'پرسش بدون عنوان';
                            var n = '';
                            if (p.numbered !== false) { qCounter += 1; n = qCounter + '. '; }
                            return '<div class="card ar-draggable" draggable="true" data-vid="'+vIdx+'" data-oid="'+visibleMap[vIdx]+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">\
                                    <div style="display:flex;align-items:center;gap:.5rem;">\
                                        <span class="ar-dnd-handle" title="جابجایی">≡</span>\
                                        <input type="checkbox" class="arSelectItem" title="انتخاب" />\
                                        <div class="qtext">'+n+qHtml+'</div>\
                                    </div>\
                                    <div style="display:flex;gap:.6rem;align-items:center;">\
                                        <a href="#" class="arEditField" data-id="'+id+'" data-index="'+visibleMap[vIdx]+'">ویرایش</a>\
                                        <a href="#" class="arDeleteField" style="color:#d32f2f;">حذف</a>\
                                    </div>\
                                </div>\
                            </div>';
                        }).join('');
                        // Helper: refresh data-oid and editor indices without full rerender
                        function refreshDomOidMapping(){
                            try {
                                var wIdxN = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='welcome'; });
                                var tIdxN = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; });
                                var regularIdxsN = [];
                                fields.forEach(function(x,i){ var p=x.props||x; var ty=p.type||x.type; if (ty!=='welcome' && ty!=='thank_you') regularIdxsN.push(i); });
                                var rPtr = 0;
                                Array.from(list.children).forEach(function(card){
                                    if (card.classList && card.classList.contains('ar-draggable')){
                                        var noid = regularIdxsN[rPtr++];
                                        if (typeof noid === 'number'){
                                            card.setAttribute('data-oid', String(noid));
                                            var edit = card.querySelector('.arEditField');
                                            if (edit) edit.setAttribute('data-index', String(noid));
                                        }
                                    } else {
                                        var hint = card.querySelector && card.querySelector('.hint');
                                        var txt = (hint && hint.textContent) || '';
                                        var edit = card.querySelector('.arEditField');
                                        if (txt.indexOf('پیام خوش‌آمد') !== -1 && wIdxN !== -1){ card.setAttribute('data-oid', String(wIdxN)); if (edit) edit.setAttribute('data-index', String(wIdxN)); }
                                        else if (txt.indexOf('پیام تشکر') !== -1 && tIdxN !== -1){ card.setAttribute('data-oid', String(tIdxN)); if (edit) edit.setAttribute('data-index', String(tIdxN)); }
                                    }
                                });
                                try { updateBulkUI(); } catch(_){ }
                            } catch(_){ }
                        }
                        // DnD sorting via SortableJS
                        var isReordering = false;
                        var dragStartOrder = [];
                        function commitReorder(){
                            try {
                                var newOrderOids = [];
                                Array.from(list.querySelectorAll('.ar-draggable')).forEach(function(el){ var oid = parseInt(el.getAttribute('data-oid')||''); if (!isNaN(oid)) newOrderOids.push(oid); });
                                var wIdxNow = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='welcome'; });
                                var tIdxNow = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; });
                                var welcomeItem = (wIdxNow !== -1) ? fields[wIdxNow] : null;
                                var thankItem = (tIdxNow !== -1) ? fields[tIdxNow] : null;
                                var reorderedRegulars = newOrderOids.map(function(oid){ return fields[oid]; });
                                var finalArr = [];
                                if (welcomeItem) finalArr.push(welcomeItem);
                                finalArr = finalArr.concat(reorderedRegulars);
                                if (thankItem) finalArr.push(thankItem);
                                if (AR_DEBUG) { try { var finalIds = finalArr.map(function(f){ return f && f.id; }); clog('Reorder: final ids order ->', finalIds, 'from oids:', newOrderOids); } catch(_){ } }
                                clog('Reorder: PUT start', { url: ARSHLINE_REST + 'forms/'+id+'/fields', count: finalArr.length });
                                fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: finalArr }) })
                                    .then(function(r){ clog('Reorder: PUT response', r && r.status); if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                                    .then(function(){
                                        notify('چیدمان به‌روزرسانی شد', 'success');
                                        if (AR_DEBUG){
                                            fetch(ARSHLINE_REST + 'forms/'+id).then(function(rr){ return rr.json(); }).then(function(data2){
                                                var srv = (data2.fields||[]).map(function(f){ return { id:f.id, sort:f.sort, type:(f.props&&f.props.type)||f.type }; });
                                                clog('Reorder: server order after PUT', srv);
                                                renderFormBuilder(id);
                                            }).catch(function(e){ cerror('Reorder: verify GET failed', e); renderFormBuilder(id); });
                                        } else {
                                            renderFormBuilder(id);
                                        }
                                    })
                                    .catch(function(e){ cerror('Reorder: PUT failed', e); notify('به‌روزرسانی چیدمان ناموفق بود', 'error'); });
                            } catch(e){ cerror('Reorder: exception', e); }
                        }
                        function ensureSortable(cb){ if (window.Sortable) { cb(); return; } var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js'; s.onload = function(){ cb(); }; document.head.appendChild(s); }
                        ensureSortable(function(){
                            try { if (window._arSortableInst) { window._arSortableInst.destroy(); } } catch(_){}
                            var reorderPh = document.createElement('div'); reorderPh.className = 'ar-dnd-placeholder'; reorderPh.style.height = '48px';
                            window._arSortableInst = Sortable.create(list, {
                                animation: 160,
                                handle: '.ar-dnd-handle',
                                draggable: '.ar-draggable',
                                ghostClass: 'ar-dnd-ghost',
                                direction: 'vertical',
                                onStart: function(evt){
                                    isReordering = true;
                                    try {
                                        // capture order at drag start
                                        dragStartOrder = Array.from(list.querySelectorAll('.ar-draggable')).map(function(el){ return parseInt(el.getAttribute('data-oid')||''); }).filter(function(n){ return !isNaN(n); });
                                    } catch(_){ dragStartOrder = []; }
                                    try { var it = evt.item; if (it){ var h = it.offsetHeight || 48; reorderPh.style.height = Math.max(44,h)+'px'; } } catch(_){ }
                                    try { reorderPh.classList.add('ar-dnd-placeholder--dashed'); } catch(_){}
                                    try { if (reorderPh && !reorderPh.parentNode){ var sib = evt.item.nextSibling; list.insertBefore(reorderPh, sib); } } catch(_){ }
                                    try { if (typeof toolPh !== 'undefined' && toolPh && toolPh.parentNode){ toolPh.parentNode.removeChild(toolPh); } } catch(_){ }
                                },
                                onEnd: function(evt){
                                    isReordering = false;
                                    try { if (reorderPh){ reorderPh.classList.remove('ar-dnd-placeholder--dashed'); if (reorderPh.parentNode) reorderPh.parentNode.removeChild(reorderPh); } } catch(_){ }
                                    try { if (typeof toolPh !== 'undefined' && toolPh && toolPh.parentNode){ toolPh.parentNode.removeChild(toolPh); } } catch(_){ }
                                    // Compare order before/after; persist if changed
                                    try {
                                        var endOrder = Array.from(list.querySelectorAll('.ar-draggable')).map(function(el){ return parseInt(el.getAttribute('data-oid')||''); }).filter(function(n){ return !isNaN(n); });
                                        var changed = (dragStartOrder.length !== endOrder.length) || endOrder.some(function(v,i){ return v !== dragStartOrder[i]; });
                                        if (changed) commitReorder();
                                    } catch(_){ if (evt.oldIndex !== evt.newIndex) commitReorder(); }
                                },
                                onMove: function(evt){
                                    try {
                                        var related = evt.related;
                                        if (!related || !reorderPh) return true;
                                        // Set placeholder height to the hovered card's height
                                        var h = related.offsetHeight || 48; reorderPh.style.height = Math.max(44, h) + 'px';
                                        // Insert before or after based on willInsertAfter
                                        var willAfter = evt.willInsertAfter === true;
                                        if (willAfter) {
                                            if (related.nextSibling !== reorderPh) { list.insertBefore(reorderPh, related.nextSibling); }
                                        } else {
                                            if (related !== reorderPh) { list.insertBefore(reorderPh, related); }
                                        }
                                        reorderPh.classList.add('ar-dnd-placeholder--dashed');
                                        if (AR_DEBUG) { try { var idx = Array.from(list.querySelectorAll('.ar-draggable')).indexOf(related); clog('DnD:onMove', { relatedIndex: idx, willAfter: willAfter }); } catch(_){ } }
                                    } catch(_){}
                                    return true;
                                }
                            });
                        });
                        list.querySelectorAll('.arEditField').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var idx = parseInt(a.getAttribute('data-index')||'0'); renderFormEditor(id, { index: idx }); }); });
                        // Delete handlers for welcome/thank_you
                        list.querySelectorAll('.arDeleteMsg').forEach(function(a){ a.addEventListener('click', function(e){
                            e.preventDefault();
                            var card = a.closest('.card'); if (!card) return;
                            var oid = parseInt(card.getAttribute('data-oid')||''); if (isNaN(oid)) return;
                            var pp = fields[oid] && (fields[oid].props || fields[oid]); var ty = pp && (pp.type || fields[oid].type) || '';
                            var ttl = (ty==='welcome') ? 'پیام خوش‌آمد' : (ty==='thank_you' ? 'پیام تشکر' : 'آیتم');
                            var ok = window.confirm('از حذف '+ttl+' مطمئن هستید؟'); if (!ok) return;
                            var newFields = fields.slice(); newFields.splice(oid, 1);
                            fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: newFields }) })
                                .then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                                .then(function(){ fields = newFields; try { animateRemove(card); } catch(_){ if (card && card.parentNode) card.parentNode.removeChild(card); } refreshDomOidMapping(); notify(ttl+' حذف شد', 'success'); })
                                .catch(function(){ notify('حذف '+ttl+' ناموفق بود', 'error'); });
                        }); });
                        
                        // Bulk selection helpers
                        function selectedCards(){ return Array.from(list.querySelectorAll('.ar-draggable')).filter(function(card){ var cb = card.querySelector('.arSelectItem'); return cb && cb.checked; }); }
                        var selAll = document.getElementById('arSelectAll');
                        var bulkBtn = document.getElementById('arBulkDelete');
                        function updateBulkUI(){ var count = selectedCards().length; if (bulkBtn){ bulkBtn.disabled = (count===0); bulkBtn.textContent = count? ('حذف انتخاب‌شده‌ها ('+count+')') : 'حذف انتخاب‌شده‌ها'; } if (selAll){ var all = list.querySelectorAll('.arSelectItem'); selAll.checked = (all.length>0 && count===all.length); } }
                        list.querySelectorAll('.arSelectItem').forEach(function(cb){ cb.addEventListener('change', updateBulkUI); });
                        if (selAll){ selAll.onchange = function(){ var all = list.querySelectorAll('.arSelectItem'); all.forEach(function(cb){ cb.checked = selAll.checked; }); updateBulkUI(); }; }
                        // Animate removal helper
                        function animateRemove(el){ try { var h = el.offsetHeight; el.style.height = h+'px'; el.style.transition = 'height .22s ease, opacity .22s ease, margin .22s ease'; el.style.overflow = 'hidden'; requestAnimationFrame(function(){ el.style.opacity = '0'; el.style.margin = '0'; el.style.height = '0px'; }); el.addEventListener('transitionend', function onEnd(ev){ if (ev.propertyName==='height'){ el.removeEventListener('transitionend', onEnd); if (el.parentNode) el.parentNode.removeChild(el); } }); } catch(_){} }
                        if (bulkBtn){ bulkBtn.addEventListener('click', function(){ var cards = selectedCards(); if (!cards.length) return; var oids = new Set(cards.map(function(c){ return parseInt(c.getAttribute('data-oid')||''); }).filter(function(x){ return !isNaN(x); })); if (!oids.size) return; // start animation
                                cards.forEach(function(c){ animateRemove(c); });
                                bulkBtn.disabled = true;
                                // Build new fields by filtering out selected original indices
                                var newFields = fields.filter(function(_f, idx){ return !oids.has(idx); });
                                fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: newFields }) })
                                    .then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                                    .then(function(){ fields = newFields; refreshDomOidMapping(); updateBulkUI(); notify('سؤالات انتخاب‌شده حذف شد', 'success'); })
                                    .catch(function(){ notify('حذف گروهی ناموفق بود', 'error'); });
                        }); }
                        updateBulkUI();
            list.querySelectorAll('.arDeleteField').forEach(function(a){ a.addEventListener('click', function(e){
                            e.preventDefault();
                            var card = a.closest('.card');
                            if (!card) return;
                            var oid = parseInt(card.getAttribute('data-oid')||'');
                            if (isNaN(oid)) return;
                            var p = fields[oid] && (fields[oid].props || fields[oid]);
                            var ty = p && (p.type || fields[oid].type);
                            if (ty === 'welcome' || ty === 'thank_you') return; // safety guard
                            var ok = window.confirm('از حذف این سؤال مطمئن هستید؟');
                            if (!ok) return;
                            var newFields = fields.slice();
                            newFields.splice(oid, 1);
                            fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: newFields }) })
                                .then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                .then(function(){ fields = newFields; try { animateRemove(card); } catch(_){ if (card && card.parentNode) card.parentNode.removeChild(card); } refreshDomOidMapping(); notify('سؤال حذف شد', 'success'); })
                                .catch(function(){ notify('حذف سؤال ناموفق بود', 'error'); });
                        }); });
                        // External tool drag-in insertion
                        var toolPh = null; // dedicated placeholder for tool insertion
                        var draggingTool = false;
                        function ensureToolPlaceholder(heightRef){
                            if (!toolPh) { toolPh = document.createElement('div'); toolPh.className = 'ar-dnd-placeholder'; }
                            if (!toolPh.parentNode) list.appendChild(toolPh);
                            var h = (heightRef && heightRef.offsetHeight) || 48;
                            toolPh.style.height = h + 'px';
                            return toolPh;
                        }
                        function positionToolPlaceholder(e){
                            var y = e.clientY;
                            var beforeNode = null;
                            var children = Array.from(list.querySelectorAll('.ar-draggable'));
                            var heightRef = null;
                            for (var i=0;i<children.length;i++){
                                var ch = children[i];
                                var r = ch.getBoundingClientRect();
                                heightRef = heightRef || ch;
                                if (y < r.top + r.height/2){ beforeNode = ch; break; }
                            }
                            var ph = ensureToolPlaceholder(heightRef);
                            if (beforeNode) list.insertBefore(ph, beforeNode); else list.appendChild(ph);
                            return ph;
                        }
                        function placeholderIndex(){
                            var idx = 0;
                            var found = -1;
                            Array.from(list.children).forEach(function(el){
                                if (el === toolPh) { found = idx; }
                                else if (el.classList && el.classList.contains('ar-draggable')) { idx++; }
                            });
                            return (found === -1) ? idx : found;
                        }
                        list.addEventListener('dragover', function(e){
                            var dt = e.dataTransfer; if (!dt) return;
                            // Do not show tool placeholder while Sortable is active drag
                            if (isReordering || (window._arSortableInst && window._arSortableInst.dragged)) return;
                            var ok = false;
                            if (draggingTool) ok = true; else {
                                var types = Array.from(dt.types||[]);
                                ok = types.includes('application/arshline-tool') || types.includes('text/plain');
                            }
                            if (ok){
                                e.preventDefault();
                                try { dt.dropEffect = 'copy'; } catch(_){ }
                                positionToolPlaceholder(e);
                            }
                        });
                        list.addEventListener('drop', function(e){
                            var dt = e.dataTransfer; if (!dt) return; var t='';
                            try{ t = dt.getData('application/arshline-tool') || dt.getData('text/plain') || ''; } catch(_){ t=''; }
                            if (t === 'short_text' || draggingTool){
                                e.preventDefault();
                                var insertAt = placeholderIndex();
                                fetch(ARSHLINE_REST + 'forms/'+id)
                                    .then(r=>r.json())
                                    .then(function(data){
                                        var arr = (data && data.fields) ? data.fields.slice() : [];
                                        var newField = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
                                        var hasWelcome = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='welcome'; }) !== -1;
                                        var hasThank = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; }) !== -1;
                                        var baseOffset = hasWelcome ? 1 : 0;
                                        var maxPos = arr.length - (hasThank ? 1 : 0);
                                        var realAt = baseOffset + insertAt;
                                        if (realAt < baseOffset) realAt = baseOffset;
                                        if (realAt > maxPos) realAt = maxPos;
                                        arr.splice(realAt, 0, newField);
                                        return fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return {res:r, index: realAt}; });
                                    })
                                    .then(function(obj){ return obj.res.json().then(function(){ return obj.index; }); })
                                    .then(function(newIndex){ notify('فیلد جدید درج شد', 'success'); renderFormEditor(id, { index: newIndex }); })
                                    .catch(function(){ notify('درج فیلد ناموفق بود', 'error'); })
                                    .finally(function(){ if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
                            }
                        });
                        list.addEventListener('dragleave', function(e){
                            // Remove placeholder when leaving list entirely
                            var rect = list.getBoundingClientRect();
                            if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
                                if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh);
                            }
                        });
                    }
                }).catch(function(){ list.textContent='خطا در بارگذاری فیلدها'; });
            var addBtn = document.getElementById('arAddShortText');
            if (addBtn){
                addBtn.setAttribute('draggable','true');
                addBtn.addEventListener('click', function(){ addNewField(id); });
                addBtn.addEventListener('dragstart', function(e){
                    draggingTool = true;
                    try { e.dataTransfer.effectAllowed = 'copy'; } catch(_){ }
                    try { e.dataTransfer.setData('application/arshline-tool','short_text'); } catch(_){ }
                    try { e.dataTransfer.setData('text/plain','short_text'); } catch(_){ }
                    try { var img = document.createElement('div'); img.className = 'ar-dnd-ghost-proxy'; img.textContent = 'سؤال با پاسخ کوتاه'; document.body.appendChild(img); e.dataTransfer.setDragImage(img, 0, 0); setTimeout(function(){ if (img && img.parentNode) img.parentNode.removeChild(img); }, 0); } catch(_){ }
                });
                addBtn.addEventListener('dragend', function(){ draggingTool = false; if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
            }
            var addWelcomeBtn = document.getElementById('arAddWelcome');
            if (addWelcomeBtn){
                addWelcomeBtn.addEventListener('click', function(){
                    fetch(ARSHLINE_REST + 'forms/'+id)
                        .then(r=>r.json())
                        .then(function(data){
                            var arr = (data && data.fields) ? data.fields.slice() : [];
                            var wIndex = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='welcome'; });
                            if (wIndex !== -1){ renderFormEditor(id, { index: wIndex }); return Promise.reject('__AR_EXISTS__'); }
                            var newField = { type:'welcome', label:'پیام خوش‌آمد', heading:'خوش آمدید', message:'', image_url:'' };
                            arr.unshift(newField);
                            return fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return 0; });
                        })
                        .then(function(idxOpen){ if (typeof idxOpen === 'number'){ notify('پیام خوش‌آمد افزوده شد', 'success'); renderFormEditor(id, { index: idxOpen }); } })
                        .catch(function(err){ if (err === '__AR_EXISTS__') return; if (err) notify('افزودن پیام خوش‌آمد ناموفق بود', 'error'); });
                });
            }
            var addThankBtn = document.getElementById('arAddThank');
            if (addThankBtn){
                addThankBtn.addEventListener('click', function(){
                    fetch(ARSHLINE_REST + 'forms/'+id)
                        .then(r=>r.json())
                        .then(function(data){
                            var arr = (data && data.fields) ? data.fields.slice() : [];
                            var tIndex = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; });
                            if (tIndex !== -1){ renderFormEditor(id, { index: tIndex }); return Promise.reject('__AR_EXISTS__'); }
                            var newField = { type:'thank_you', label:'پیام تشکر', heading:'با تشکر از شما', message:'', image_url:'' };
                            arr.push(newField);
                            return fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return arr.length-1; });
                        })
                        .then(function(idxOpen){ if (typeof idxOpen === 'number'){ notify('پیام تشکر افزوده شد', 'success'); renderFormEditor(id, { index: idxOpen }); } })
                        .catch(function(err){ if (err === '__AR_EXISTS__') return; if (err) notify('افزودن پیام تشکر ناموفق بود', 'error'); });
                });
            }
            // allow drop to add
            var formSide = document.getElementById('arFormSide');
            if (formSide){
                formSide.addEventListener('dragover', function(e){ e.preventDefault(); });
                formSide.addEventListener('drop', function(e){ e.preventDefault(); var t = e.dataTransfer.getData('text/plain'); if (t==='short_text') addNewField(id); });
            }
        }

        function renderTab(tab){
            try { localStorage.setItem('arshLastTab', tab); } catch(_){ }
            try { if (['dashboard','forms','submissions','reports','users'].includes(tab)) setHash(tab); } catch(_){ }
            try { setSidebarClosed(false, false); } catch(_){ }
            setActive(tab);
            var content = document.getElementById('arshlineDashboardContent');
            var headerActions = document.getElementById('arHeaderActions');
            if (headerActions) {
                headerActions.innerHTML = '<button id="arHeaderCreateForm" class="ar-btn">+ فرم جدید</button>';
            }
            // Header create: always available, routes to forms and opens inline create
            var globalHeaderCreateBtn = document.getElementById('arHeaderCreateForm');
            if (globalHeaderCreateBtn) {
                globalHeaderCreateBtn.addEventListener('click', function(){
                    window._arOpenCreateInlineOnce = true;
                    renderTab('forms');
                });
            }
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
                        <div class="ar-card ar-card--teal">\
                            <div class="icon"><ion-icon name="settings-outline"></ion-icon></div>\
                            <div class="content"><h2>اتوماسیون</h2><p>(در حال توسعه)</p></div>\
                        </div>\
                    </div>';
            } else if (tab === 'forms') {
                // header button already rendered globally
                content.innerHTML = '<div class="card glass card--static" style="padding:1rem;">\
                    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;">\
                      <span class="title">فرم‌ها</span>\
                      <button id="arCreateFormBtn" class="ar-btn ar-btn--soft" style="margin-inline-start:auto;">+ فرم جدید</button>\
                    </div>\
                    <div id="arCreateInline" style="display:none;align-items:center;gap:.5rem;margin-bottom:.8rem;">\
                      <input id="arNewFormTitle" class="ar-input" placeholder="عنوان فرم" style="min-width:220px"/>\
                      <button id="arCreateFormSubmit" class="ar-btn">ایجاد</button>\
                      <button id="arCreateFormCancel" class="ar-btn ar-btn--outline">انصراف</button>\
                    </div>\
                    <div id="arFormsList" class="hint">در حال بارگذاری...</div>\
                </div>';
                var createBtn = document.getElementById('arCreateFormBtn');
                var headerCreateBtn = document.getElementById('arHeaderCreateForm');
                var inlineWrap = document.getElementById('arCreateInline');
                var submitBtn = document.getElementById('arCreateFormSubmit');
                var cancelBtn = document.getElementById('arCreateFormCancel');
                if (!ARSHLINE_CAN_MANAGE && createBtn){ createBtn.style.display = 'none'; }
                if (createBtn) createBtn.addEventListener('click', function(){
                    if (!inlineWrap) return; var showing = inlineWrap.style.display !== 'none'; inlineWrap.style.display = showing ? 'none' : 'flex';
                    if (!showing){ var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } }
                });
                if (headerCreateBtn) headerCreateBtn.addEventListener('click', function(){
                    // ensure we are on forms tab, show inline create
                    if (!inlineWrap) return; inlineWrap.style.display = 'flex'; var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); }
                });
                if (cancelBtn) cancelBtn.addEventListener('click', function(){ if (inlineWrap) inlineWrap.style.display = 'none'; });
                if (submitBtn) submitBtn.addEventListener('click', function(){
                    var titleEl = document.getElementById('arNewFormTitle');
                    var title = (titleEl && titleEl.value.trim()) || 'فرم جدید';
                    fetch(ARSHLINE_REST + 'forms', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ title: title }) })
                        .then(async r=>{ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
            .then(function(obj){ notify('فرم ایجاد شد', 'success'); if (obj && obj.id){ renderFormBuilder(parseInt(obj.id)); } else { renderTab('forms'); } })
                        .catch(function(){ notify('ایجاد فرم ناموفق بود. لطفاً دسترسی را بررسی کنید.', 'error'); });
                });
                // load forms list
                fetch(ARSHLINE_REST + 'forms').then(r=>r.json()).then(function(forms){
                    var box = document.getElementById('arFormsList'); if (!box) return;
                    if (!forms || forms.length===0){ box.textContent = 'هنوز فرمی ندارید.'; return; }
                    var html = (forms||[]).map(function(f){
                        return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px dashed var(--border);">\
                            <div>#'+f.id+' — '+(f.title||'بدون عنوان')+'</div>\
                            <div style="display:flex;gap:.6rem;">\
                                <a href="#" class="arEditForm ar-btn ar-btn--soft" data-id="'+f.id+'">ویرایش</a>\
                                <a href="#" class="arPreviewForm ar-btn ar-btn--outline" data-id="'+f.id+'">پیش‌نمایش</a>\
                                <a href="#" class="arViewResults ar-btn ar-btn--outline" data-id="'+f.id+'">مشاهده نتایج</a>\
                                '+(ARSHLINE_CAN_MANAGE ? '<a href="#" class="arDeleteForm ar-btn ar-btn--danger" data-id="'+f.id+'">حذف</a>' : '')+'\
                            </div>\
                        </div>';
                    }).join('');
                    box.innerHTML = html;
                    box.querySelectorAll('.arEditForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!ARSHLINE_CAN_MANAGE){ if (typeof handle401 === 'function') handle401(); return; } renderFormBuilder(id); }); });
                    box.querySelectorAll('.arPreviewForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); renderFormPreview(id); }); });
                    box.querySelectorAll('.arViewResults').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; window._pendingFormSelectId = id; renderTab('submissions'); }); });
                    if (ARSHLINE_CAN_MANAGE) {
                        box.querySelectorAll('.arDeleteForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; if (!confirm('حذف فرم #'+id+'؟ این عمل بازگشت‌ناپذیر است.')) return; fetch(ARSHLINE_REST + 'forms/' + id, { method:'DELETE', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(){ notify('فرم حذف شد', 'success'); renderTab('forms'); }).catch(function(){ notify('حذف فرم ناموفق بود', 'error'); }); }); });
                    }
                    // If header create was requested before arriving here, open inline create now
                    if (window._arOpenCreateInlineOnce && inlineWrap){ inlineWrap.style.display = 'flex'; var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } window._arOpenCreateInlineOnce = false; }
                }).catch(function(){ var box = document.getElementById('arFormsList'); if (box) box.textContent = 'خطا در بارگذاری فرم‌ها.'; notify('خطا در بارگذاری فرم‌ها', 'error'); });
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
                // If a form id was requested before arriving here, select it and trigger load
                if (window._pendingFormSelectId){ sel.value = String(window._pendingFormSelectId); var evt = new Event('change'); sel.dispatchEvent(evt); window._pendingFormSelectId = null; }
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
            content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');
        }

        // bind clicks + keyboard
        links.forEach(function(a){
            a.addEventListener('click', function(e){ e.preventDefault(); renderTab(a.getAttribute('data-tab')); });
            a.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); renderTab(a.getAttribute('data-tab')); }});
        });

        // default tab
        if (location.hash){ routeFromHash(); }
        else {
            var initial = (function(){ try { return localStorage.getItem('arshLastTab') || ''; } catch(_){ return ''; } })() || 'dashboard';
            if (![ 'dashboard','forms','submissions','reports','users' ].includes(initial)) initial = 'dashboard';
            setHash(initial);
            renderTab(initial);
        }
    });
    </script>
    </head>
    <body>
    <div class="arshline-dashboard-root">
        <aside class="arshline-sidebar">
            <div class="logo"><span class="label">عرشلاین</span></div>
            <button id="arSidebarToggle" class="toggle" aria-expanded="true" title="باز/بسته کردن منو"><span class="chev">❮</span></button>
            <nav>
                <a href="#" data-tab="dashboard"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-10.5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="label">داشبورد</span></a>
                <a href="#" data-tab="forms"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="1.6"/><path d="M7 8h10M7 12h10M7 16h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">فرم‌ها</span></a>
                <a href="#" data-tab="submissions"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="1.6"/><path d="M8 9h8M8 13h8M8 17h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">پاسخ‌ها</span></a>
                <a href="#" data-tab="reports"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 20h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><rect x="6" y="10" width="3" height="6" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="7" width="3" height="9" stroke="currentColor" stroke-width="1.6"/><rect x="16" y="12" width="3" height="4" stroke="currentColor" stroke-width="1.6"/></svg></span><span class="label">گزارشات</span></a>
                <a href="#" data-tab="users"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">کاربران</span></a>
            </nav>
        </aside>
        <main class="arshline-main">
            <div class="arshline-header">
                <div id="arHeaderActions"></div>
                <div id="arThemeToggle" class="theme-toggle" role="switch" aria-checked="false" tabindex="0">
                    <span class="sun">☀</span>
                    <span class="moon">🌙</span>
                    <span class="knob"></span>
                </div>
            </div>
            <div id="arshlineDashboardContent" class="view"></div>
        </main>
    </div>
<!-- Ionicons for modern solid cards -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<!-- Persian datepicker (optional) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.css" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.js"></script>
<script>
// Toast notifications (polished, reusable)
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
    var type = (typeof opts === 'string') ? opts : (opts && opts.type) || 'info';
    var variant = ['success','error','info','warn'].includes(type) ? type : 'info';
    el.className = 'ar-toast ar-toast--'+variant;
    var icon = document.createElement('span');
    icon.className = 'ar-toast-ic';
    icon.textContent = (variant==='success') ? '✔' : (variant==='error') ? '✖' : (variant==='warn') ? '⚠' : 'ℹ';
    var text = document.createElement('span');
    text.textContent = message;
    el.appendChild(icon);
    el.appendChild(text);
    var hasAction = opts && opts.actionLabel && typeof opts.onAction === 'function';
    if (hasAction){
        var btn = document.createElement('button');
        btn.textContent = opts.actionLabel;
        btn.style.cssText = 'margin-inline-start:.6rem;padding:.25rem .6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;';
        btn.addEventListener('click', function(){ opts.onAction(); el.remove(); });
        el.appendChild(btn);
    }
    wrap.appendChild(el);
    var duration = (opts && opts.duration) || 2800;
    setTimeout(function(){ el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, Math.max(200, duration - 500));
    setTimeout(function(){ el.remove(); }, duration);
}
// Centralized 401 handler
function handle401(){
    try {
        notify('نشست شما منقضی شده یا دسترسی کافی ندارید.', { type:'error', duration: 5000, actionLabel: 'ورود', onAction: function(){ if (ARSHLINE_LOGIN_URL) location.href = ARSHLINE_LOGIN_URL; }});
    } catch(_){ alert('401 Unauthorized: لطفاً وارد شوید.'); }
}
</script>
<script>
// Input masks for preview inputs
function applyInputMask(inp, props){
    var fmt = props.format || 'free_text';
    function normalizeDigits(str){
        var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        var ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        return (str||'').replace(/[۰-۹٠-٩]/g, function(d){
            var i = fa.indexOf(d); if (i>-1) return String(i);
            var j = ar.indexOf(d); if (j>-1) return String(j);
            return d;
        });
    }
    function clampLen(){}
    function digitsOnly(){ inp.value = normalizeDigits(inp.value).replace(/\D+/g,''); }
    function allowChars(regex){ var s = normalizeDigits(inp.value); inp.value = (s.match(regex)||[]).join(''); }
    function setInvalid(msg){ inp.style.borderColor = '#b91c1c'; if (msg) inp.title = msg; }
    function clearInvalid(){ inp.style.borderColor = ''; inp.title = ''; }
    inp.addEventListener('input', function(){
        inp.value = normalizeDigits(inp.value);
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
            case 'national_id_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
            case 'postal_code_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
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
            case 'national_id_ir':
                var nid = v.padStart(10,'0');
                if (!/^\d{10}$/.test(nid)) { setInvalid('کد ملی نامعتبر است'); break; }
                if (/^(\d)\1{9}$/.test(nid)) { setInvalid('کد ملی نامعتبر است'); break; }
                var sum = 0; for (var i=0;i<9;i++){ sum += parseInt(nid[i]) * (10 - i); }
                var r = sum % 11; var c = parseInt(nid[9]);
                if (!((r<2 && c===r) || (r>=2 && c===(11-r)))) setInvalid('کد ملی نامعتبر است');
                break;
            case 'postal_code_ir':
                var pc = v;
                if (!/^\d{10}$/.test(pc)) { setInvalid('کد پستی نامعتبر است'); break; }
                if (/^(\d)\1{9}$/.test(pc)) { setInvalid('کد پستی نامعتبر است'); break; }
                break;
            default: break;
        }
        inp.setAttribute('aria-invalid', inp.style.borderColor ? 'true' : 'false');
    });
}
</script>
</body>
</html>

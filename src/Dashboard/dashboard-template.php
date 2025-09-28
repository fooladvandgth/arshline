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
    <!-- Vazir font -->
    <link href="https://cdn.jsdelivr.net/npm/vazir-font/dist/font-face.css" rel="stylesheet">
    
    <?php
    // Manually enqueue and output modular CSS files since this template bypasses wp_head()
    $plugin_url = plugins_url('', dirname(__DIR__, 2) . '/arshline.php');
    $version = defined('\\Arshline\\Dashboard\\Dashboard::VERSION') ? \Arshline\Dashboard\Dashboard::VERSION : '1.0.0';
    ?>
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . '/assets/css/dashboard.css?ver=' . $version); ?>" />
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . '/assets/css/modules/variables.css?ver=' . $version); ?>" />
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . '/assets/css/modules/layout.css?ver=' . $version); ?>" />
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . '/assets/css/modules/components.css?ver=' . $version); ?>" />
    <link rel="stylesheet" href="<?php echo esc_url($plugin_url . '/assets/css/modules/utilities.css?ver=' . $version); ?>" />

            <!-- Runtime config JSON (consumed by assets/js/core/runtime-config.js) -->
            <script id="arshline-config" type="application/json">
            {
                "rest": "<?php echo esc_js( rest_url('arshline/v1/') ); ?>",
                "nonce": "<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>",
                "sub_view_base": "<?php echo esc_js( add_query_arg('arshline_submission', '%ID%', home_url('/')) ); ?>",
                "can_manage": <?php echo ( current_user_can('edit_posts') || current_user_can('manage_options') ) ? 'true' : 'false'; ?>,
                "login_url": "<?php echo esc_js( wp_login_url( get_permalink() ) ); ?>"
            }
            </script>
            <!-- Extracted: runtime-config moved to external initializer -->
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/core/runtime-config.js?ver=' . $version ); ?>"></script>
            <!-- Extracted: debug-logger -->
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/utils/debug.js?ver=' . $version ); ?>"></script>
            <!-- Extracted: tools-registry and core defaults -->
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/core/tools-registry.js?ver=' . $version ); ?>"></script>
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/core/tool-defaults.js?ver=' . $version ); ?>"></script>
            <!-- Core router -->
            <!-- FULL mode is enabled below; external controller owns renderTab and routing. -->
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/core/router.js?ver=' . $version ); ?>"></script>
            <!-- UI modules: notify, auth, input masks -->
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/ui/notify.js?ver=' . $version ); ?>"></script>
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/ui/auth.js?ver=' . $version ); ?>"></script>
            <script src="<?php echo esc_url( $plugin_url . '/assets/js/ui/input-masks.js?ver=' . $version ); ?>"></script>
    <!-- Load external tool modules (must come after Tools registry) -->
    <!-- Load main dashboard JavaScript -->
    <script src="<?php echo esc_url( plugins_url('assets/js/dashboard.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <!-- Load external tool modules (must come after Tools registry) -->
    <script src="<?php echo esc_url( plugins_url('assets/js/tools/long_text.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <script src="<?php echo esc_url( plugins_url('assets/js/tools/multiple_choice.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <script src="<?php echo esc_url( plugins_url('assets/js/tools/short_text.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <script src="<?php echo esc_url( plugins_url('assets/js/tools/dropdown.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <script src="<?php echo esc_url( plugins_url('assets/js/tools/rating.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <!-- Externalized controller (extracted from inline block) -->
    <script src="<?php echo esc_url( plugins_url('assets/js/dashboard-controller.js', dirname(__DIR__, 2).'/arshline.php') ); ?>"></script>
    <script>
    // Enable FULL mode so inline controller is skipped when external is present
    try { window.ARSH_CTRL_FULL = true; } catch(_){ }
    /* =========================================================================
       BLOCK: dashboard-controller
       Purpose: Orchestrates dashboard behavior: sidebar routing/tabs, theme &
                sidebar toggles, results list, form builder, and preview flows.
       Dependencies: runtime-config, tools-registry, external tool modules,
                     assets/js/dashboard.js
       Exports: none (DOM side-effects only)
       Future extraction: assets/js/dashboard-controller.js
       ========================================================================= */
    // Guard: Skip inline when external controller is in FULL mode; otherwise allow inline to render tabs.
    if (window.ARSH_CTRL_EXTERNAL && window.ARSH_CTRL_FULL) { try { console.debug('ARSH: external controller FULL; skipping inline dashboard-controller'); } catch(_){} } else {
    try { console.debug('[ARSH] inline dashboard-controller active (FULL mode off)'); } catch(_){}
    // Tabs: render content per menu item
    document.addEventListener('DOMContentLoaded', function() {
    var content = document.getElementById('arshlineDashboardContent');
        var links = document.querySelectorAll('.arshline-sidebar nav a[data-tab]');
        var sidebar = document.querySelector('.arshline-sidebar');
        var sidebarToggle = document.getElementById('arSidebarToggle');
        // Debug helpers
        // Allow enabling debug via URL: ?arshdbg=1 (or disable with ?arshdbg=0)
        try {
            var _dbgQS = new URLSearchParams(window.location.search).get('arshdbg');
            if (_dbgQS === '1' || _dbgQS === 'true') { localStorage.setItem('arshDebug', '1'); }
            else if (_dbgQS === '0' || _dbgQS === 'false') { localStorage.removeItem('arshDebug'); }
        } catch(_){ }
        var AR_DEBUG = false;
        try { AR_DEBUG = (localStorage.getItem('arshDebug') === '1'); } catch(_){ }
    // Optional capture mode: set localStorage.arshDebugCapture = '1' to enable
    try {
        if (localStorage.getItem('arshDebugCapture') === '1') {
            window._arConsoleLog = [];
            (function(){
                var methods = ['log','warn','error','info'];
                methods.forEach(function(m){
                    var orig = console[m] ? console[m].bind(console) : function(){};
                    console[m] = function(){
                        try {
                            window._arConsoleLog.push({ level: m, args: Array.from(arguments), ts: Date.now() });
                        } catch(_){ }
                        try {
                            orig.apply(console, arguments);
                        } catch(_){ }
                    };
                });
                // small overlay
                var ov = document.createElement('div');
                ov.id = 'arsh-console-capture';
                ov.style.cssText = 'position:fixed;left:8px;bottom:8px;max-width:420px;max-height:220px;overflow:auto;background:rgba(0,0,0,.8);color:#fff;padding:8px;border-radius:8px;font-size:12px;z-index:99999;';
                ov.innerHTML = '<div style="font-weight:700;margin-bottom:6px">ARSH Console Capture (click to hide)</div>';
                ov.addEventListener('click', function(){
                    try {
                        ov.style.display = 'none';
                    } catch(_){ }
                });
                document.body.appendChild(ov);
                window._arLogDump = function(){
                    try {
                        if (!window._arConsoleLog) return;
                        ov.innerHTML = '<div style="font-weight:700;margin-bottom:6px">ARSH Console Capture (click to hide)</div>' + window._arConsoleLog.slice(-200).map(function(r){
                            return '<div style="margin-bottom:4px;color:' + (r.level === 'error' ? '#ff8080' : (r.level === 'warn' ? '#ffd080' : '#d0d0ff')) + '">[' + new Date(r.ts).toLocaleTimeString() + '] <b>' + r.level + '</b> ' + r.args.map(function(a){
                                try {
                                    return (typeof a === 'string') ? a : JSON.stringify(a);
                                } catch(_){
                                    return String(a);
                                }
                            }).join(' ') + '</div>';
                        }).join('');
                    } catch(_){ }
                };
            })();
        }
    } catch(_){ }
    function clog(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.log.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
    function cwarn(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.warn.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
    // Always print errors to console, regardless of AR_DEBUG
    function cerror(){ if (typeof console !== 'undefined') { try { console.error.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
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

        function escapeHtml(s){
            try {
                return String(s||'')
                    .replace(/&/g,'&amp;')
                    .replace(/</g,'&lt;')
                    .replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;')
                    .replace(/'/g,'&#39;');
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
                                } catch(_){ }
                            }
                            if (!allowed[tag]){
                                while(child.firstChild){ node.insertBefore(child.firstChild, child); }
                                node.removeChild(child);
                            } else {
                                                // Preserve inline color on <span> before stripping attributes.
                                                // We allow a minimal style attribute that only contains a color declaration (e.g. "color:#ff0000" or "color: rgb(...)").
                                                var savedColor = '';
                                                try {
                                                    if (tag === 'SPAN') {
                                                        // prefer inline style color first, fall back to color attribute (from converted <font>)
                                                        savedColor = '';
                                                        try { if (child.style && child.style.color) savedColor = child.style.color; } catch(_){}
                                                        try { if (!savedColor && child.getAttribute && child.getAttribute('color')) savedColor = child.getAttribute('color'); } catch(_){}
                                                        if (savedColor) {
                                                            // normalize common hex shorthand and spaces
                                                            savedColor = String(savedColor).trim();
                                                        }
                                                    }
                                                } catch(_){ savedColor = ''; }
                                                // Remove all attributes then reapply only a safe style if color exists
                                                for (var i = child.attributes.length - 1; i >= 0; i--) { try { child.removeAttribute(child.attributes[i].name); } catch(_){} }
                                                if (tag === 'SPAN'){
                                                    if (savedColor){
                                                        // very small whitelist: only set color in the style attribute
                                                        try { child.setAttribute('style', 'color:' + savedColor); } catch(_) { }
                                                    } else { try { child.removeAttribute('style'); } catch(_){} }
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
            // Backward-compat: if someone navigates to old #submissions, redirect to forms
            if (parts[0]==='submissions'){ renderTab('forms'); return; }
            if (['dashboard','forms','reports','users'].includes(parts[0])){ renderTab(parts[0]); return; }
            if (parts[0]==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { dlog('route:builder', id); renderFormBuilder(id); return; } }
            if (parts[0]==='editor' && parts[1]){ var id = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); dlog('route:editor', { id:id, idx:idx, parts:parts }); if (id) { renderFormEditor(id, { index: isNaN(idx)?0:idx }); return; } }
            if (parts[0]==='preview' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { renderFormPreview(id); return; } }
            if (parts[0]==='results' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { renderFormResults(id); return; } }
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
        // Map type -> Ionicon name (we already load Ionicons)
        function getTypeIcon(type){
            switch(type){
                case 'short_text': return 'create-outline'; // pencil/text
                case 'long_text': return 'newspaper-outline'; // document-like
                case 'multiple_choice':
                case 'multiple-choice': return 'list-outline';
                case 'dropdown': return 'chevron-down-outline';
                case 'welcome': return 'happy-outline';
                case 'thank_you': return 'checkmark-done-outline';
                default: return 'help-circle-outline';
            }
        }
        function getTypeLabel(type){
            switch(type){
                case 'short_text': return 'پاسخ کوتاه';
                case 'long_text': return 'پاسخ طولانی';
                case 'multiple_choice':
                case 'multiple-choice': return 'چندگزینه‌ای';
                case 'dropdown': return 'لیست کشویی';
                case 'welcome': return 'پیام خوش‌آمد';
                case 'thank_you': return 'پیام تشکر';
                default: return 'نامشخص';
            }
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

        // Per-form results page (moved from standalone submissions)
        function renderFormResults(formId){
            var content = document.getElementById('arshlineDashboardContent');
            if (!content) return;
            // Results diagnostics: pick up optional debug toggles from localStorage
            var REST_DEBUG = false;
            try { REST_DEBUG = (localStorage.getItem('arshRestDebug') === '1') || (localStorage.getItem('arshDebug') === '1'); } catch(_){ }
            // Ensure log functions are active while REST debug is on
            AR_DEBUG = !!(REST_DEBUG || AR_DEBUG);
            try { clog('results:init', { formId: formId, restBase: ARSHLINE_REST, debug: REST_DEBUG }); } catch(_){ }
            // Header actions: back to forms
            var headerActions = document.getElementById('arHeaderActions');
            if (headerActions) {
                headerActions.innerHTML = '<button id="arBackToForms" class="ar-btn ar-btn--outline">بازگشت به فرم‌ها</button>';
                var backBtn = document.getElementById('arBackToForms');
                if (backBtn) backBtn.addEventListener('click', function(){ renderTab('forms'); });
            }
                        // Persist route
            try { setHash('results/'+formId); } catch(_){ }
                                    content.innerHTML = '<div class="card glass" style="padding:1rem;">\
                                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;flex-wrap:wrap;">\
                                    <span class="title">نتایج فرم #'+formId+'</span>\
                                </div>\
                                            <div id="arFieldFilters" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;align-items:center">\
                                                <select id="arFieldSelect" class="ar-select" style="min-width:220px"><option value="">انتخاب سوال...</option></select>\
                                                <select id="arFieldOp" class="ar-select"><option value="eq">دقیقا برابر</option><option value="neq">اصلا این نباشد</option><option value="like">شامل باشد</option></select>\
                                                <span id="arFieldValWrap" style="display:inline-flex;min-width:240px">\
                                                    <input id="arFieldVal" class="ar-input" placeholder="مقدار فیلتر" style="min-width:240px"/>\
                                                </span>\
                                                <button id="arFieldApply" class="ar-btn ar-btn--soft">اعمال فیلتر</button>\
                                                <button id="arFieldClear" class="ar-btn ar-btn--outline">پاک‌سازی</button>\
                                                <label class="hint" style="margin-inline-start:1rem">شکستن خطوط:</label>\
                                                <input id="arWrapToggle" type="checkbox" class="ar-input" />\
                                                                                                <span style="flex:1 1 auto"></span>\
                                                                                                <button id="arSubExportCsv" class="ar-btn ar-btn--outline" title="خروجی CSV">خروجی CSV</button>\
                                                                                                <button id="arSubExportXls" class="ar-btn ar-btn--outline" title="خروجی Excel">خروجی Excel</button>\
                                            </div>\
                                <div id="arSubsList"></div>\
            </div>';
                        // simplified header: no global search/status/date filters
                        var q = null, st = null, ans = null, df = null, dt = null;
                        var expCsv = document.getElementById('arSubExportCsv');
                        var expXls = document.getElementById('arSubExportXls');
            var selField = document.getElementById('arFieldSelect');
            var selOp = document.getElementById('arFieldOp');
            var inpVal = document.getElementById('arFieldVal');
            var valWrap = document.getElementById('arFieldValWrap');
            var btnApply = document.getElementById('arFieldApply');
            var btnClear = document.getElementById('arFieldClear');
            var state = { page: 1, per_page: 10 };
            var wrapToggle = document.getElementById('arWrapToggle');
            // Apply persisted wrap preference
            try {
                var pref = localStorage.getItem('arWrap:'+formId);
                if (wrapToggle) { wrapToggle.checked = (pref === '1'); }
                var container = document.querySelector('.arshline-main');
                if (container){ container.classList.remove('ar-wrap','ar-nowrap'); container.classList.add((wrapToggle && wrapToggle.checked) ? 'ar-wrap' : 'ar-nowrap'); }
            } catch(_){ }
            // metadata populated after first load
            var fieldMeta = { choices: {}, labels: {}, types: {}, options: {} };
            function buildQuery(){
                var p = new URLSearchParams();
                p.set('page', String(state.page||1)); p.set('per_page', String(state.per_page||10));
                var sv = '';
                var qv = '';
                var av = '';
                var fv = '';
                var tv = '';
                // single per-field filter with operator
                var fid = (selField && parseInt(selField.value||'0'))||0;
                var vv = (inpVal && inpVal.value.trim())||'';
                var op = (selOp && selOp.value)||'like';
                if (fid>0 && vv){ p.set('f['+fid+']', vv); if (op && op!=='like') p.set('op['+fid+']', op); }
                try { clog('results:filters', { search:qv, answers:av, status:sv, from:fv, to:tv, fieldFilter: { fid: fid, op: op, value: vv } }); } catch(_){ }
                return p.toString();
            }
            function buildRestUrl(path, qs){
                try {
                    var base = ARSHLINE_REST || '';
                    if (path.charAt(0) === '/') path = path.slice(1);
                    var u = new URL(base, window.location.origin);
                    if (u.searchParams.has('rest_route')){
                        var rr = u.searchParams.get('rest_route') || '';
                        if (rr && rr.charAt(rr.length-1) !== '/') rr += '/';
                        rr += path;
                        u.searchParams.set('rest_route', rr);
                    } else {
                        if (u.pathname && u.pathname.charAt(u.pathname.length-1) !== '/') u.pathname += '/';
                        u.pathname += path;
                    }
                    if (qs){
                        var extra = new URLSearchParams(qs);
                        extra.forEach(function(v,k){ u.searchParams.set(k, v); });
                    }
                    return u.toString();
                } catch(_) {
                    return (ARSHLINE_REST||'') + path + (qs? ('?'+qs) : '');
                }
            }
            function renderTable(resp){
                var list = document.getElementById('arSubsList'); if (!list) return; if (!resp) { list.innerHTML = '<div class="hint">پاسخی ثبت نشده است.</div>'; return; }
                var rows = Array.isArray(resp) ? resp : (resp.rows||[]);
                var total = Array.isArray(resp) ? rows.length : (resp.total||0);
                var fields = resp.fields || [];
                var fieldOrder = []; var fieldLabels = {}; var choices = {}; var typesMap = {}; var optionsMap = {};
                if (Array.isArray(fields) && fields.length){
                    fields.forEach(function(f){
                        var fid = parseInt(f.id||0); if (!fid) return;
                        fieldOrder.push(fid);
                        var p = f.props||{};
                        fieldLabels[fid] = p.question || ('فیلد #'+fid);
                        typesMap[fid] = (p.type||'');
                        if (Array.isArray(p.options)){
                            optionsMap[fid] = (p.options||[]).map(function(opt){ return { value: String(opt.value||opt.label||''), label: String(opt.label||String(opt.value||'')) }; });
                            p.options.forEach(function(opt){ var v = String(opt.value||opt.label||''); var l = String(opt.label||v); if (!choices[fid]) choices[fid] = {}; if (v) choices[fid][v] = l; });
                        }
                    });
                }
                // Apply saved column order before rendering to avoid re-render loops
                try {
                    var savedOrder = [];
                    try { savedOrder = JSON.parse(localStorage.getItem('arColsOrder:'+formId) || '[]'); } catch(_){ savedOrder = []; }
                    if (Array.isArray(savedOrder) && savedOrder.length){
                        var filtered = savedOrder.map(function(x){ return parseInt(x); }).filter(function(fid){ return fieldOrder.indexOf(fid) >= 0; });
                        if (filtered.length){ fieldOrder = filtered.concat(fieldOrder.filter(function(fid){ return filtered.indexOf(fid) < 0; })); }
                    }
                } catch(_){ }
                // cache meta for filter UI
                fieldMeta.choices = choices; fieldMeta.labels = fieldLabels; fieldMeta.types = typesMap; fieldMeta.options = optionsMap;
                // populate select with fields once
                if (selField && selField.children.length<=1 && fieldOrder.length){ selField.innerHTML = '<option value="">انتخاب سوال...</option>' + fieldOrder.map(function(fid){ return '<option value="'+fid+'">'+(fieldLabels[fid]||('فیلد #'+fid))+'</option>'; }).join(''); }
                if (!rows || rows.length===0){ list.innerHTML = '<div class="hint">پاسخی ثبت نشده است.</div>'; return; }
                var html = '<div style="overflow:auto">\
                    <table class="ar-table">\
                        <thead><tr>\
                            <th>شناسه</th>\
                            <th>تاریخ</th>';
                fieldOrder.forEach(function(fid){ html += '<th class="ar-th-draggable" draggable="true" data-fid="'+fid+'">'+(fieldLabels[fid]||('فیلد #'+fid))+'</th>'; });
                html += '<th style="border-bottom:1px solid var(--border);padding:.5rem">اقدام</th>\
                        </tr></thead><tbody>';
                html += rows.map(function(it){
                    var viewUrl = (ARSHLINE_SUB_VIEW_BASE||'').replace('%ID%', String(it.id));
                    var byField = {};
                    if (Array.isArray(it.values)){
                        it.values.forEach(function(v){ var fid = parseInt(v.field_id||0); if (!fid) return; if (byField[fid] == null) byField[fid] = String(v.value||''); });
                    }
                    var tr = '\
                    <tr>\
                        <td>#'+it.id+'</td>\
                        <td>'+(it.created_at||'')+'</td>';
                    fieldOrder.forEach(function(fid){ var val = byField[fid] || ''; if (choices[fid] && choices[fid][val]) val = choices[fid][val]; tr += '<td style="padding:.5rem;border-bottom:1px dashed var(--border)">'+escapeHtml(String(val))+'</td>'; });
                    tr += '\
                        <td class="actions"><a href="'+viewUrl+'" target="_blank" rel="noopener" class="ar-btn ar-btn--soft">مشاهده پاسخ</a></td>\
                    </tr>';
                    return tr;
                }).join('');
                html += '</tbody></table></div>';
                if (!Array.isArray(resp)){
                    var page = resp.page||1, per = resp.per_page||10; var pages = Math.max(1, Math.ceil(total/per));
                    html += '<div style="display:flex;gap:.5rem;align-items:center;justify-content:center;margin-top:.6rem">';
                    html += '<button class="ar-btn" data-page="prev" '+(page<=1?'disabled':'')+'>قبلی</button>';
                    html += '<span class="hint">صفحه '+page+' از '+pages+' — '+total+' رکورد</span>';
                    html += '<button class="ar-btn" data-page="next" '+(page>=pages?'disabled':'')+'>بعدی</button>';
                    html += '</div>';
                }
                list.innerHTML = html;
                // Wrap toggle apply
                try {
                    var container = document.querySelector('.arshline-main');
                    container.classList.remove('ar-wrap','ar-nowrap');
                    container.classList.add((wrapToggle && wrapToggle.checked) ? 'ar-wrap' : 'ar-nowrap');
                } catch(_){ }
                // Update field value control if needed (dropdown for choice fields)
                // Enable dragging of question columns
                (function(){
                    var thead = list.querySelector('thead'); if (!thead) return;
                    var draggingFid = null;
                    function saveOrder(order){ try { localStorage.setItem('arColsOrder:'+formId, JSON.stringify(order)); } catch(_){ } }
                    thead.addEventListener('dragstart', function(ev){ var th = ev.target.closest('th[data-fid]'); if (!th) return; draggingFid = parseInt(th.getAttribute('data-fid')||'0'); if (ev.dataTransfer) ev.dataTransfer.effectAllowed = 'move'; });
                    thead.addEventListener('dragover', function(ev){ var th = ev.target.closest('th[data-fid]'); if (!th) return; ev.preventDefault(); th.classList.add('ar-th-drag-over'); if (ev.dataTransfer) ev.dataTransfer.dropEffect='move'; });
                    thead.addEventListener('dragleave', function(ev){ var th = ev.target.closest('th[data-fid]'); if (th) th.classList.remove('ar-th-drag-over'); });
                    thead.addEventListener('drop', function(ev){ var th = ev.target.closest('th[data-fid]'); if (!th) return; ev.preventDefault(); th.classList.remove('ar-th-drag-over'); var targetFid = parseInt(th.getAttribute('data-fid')||'0'); if (!draggingFid || !targetFid || draggingFid===targetFid) return; var from = fieldOrder.indexOf(draggingFid), to = fieldOrder.indexOf(targetFid); if (from<0||to<0) return; var tmp = fieldOrder.splice(from,1)[0]; fieldOrder.splice(to,0,tmp); saveOrder(fieldOrder); renderTable(resp); });
                })();
                function updateFieldValueControl(){
                    if (!valWrap) return;
                    var fid = (selField && parseInt(selField.value||'0'))||0;
                    var prevValEl = document.getElementById('arFieldVal');
                    var current = prevValEl ? prevValEl.value : '';
                    var hasChoices = !!(fid && fieldMeta && fieldMeta.options && Array.isArray(fieldMeta.options[fid]) && fieldMeta.options[fid].length);
                    var newEl;
                    if (hasChoices){
                        var sel = document.createElement('select');
                        sel.id = 'arFieldVal';
                        sel.className = 'ar-select';
                        sel.style.minWidth = '240px';
                        sel.innerHTML = '<option value="">انتخاب مقدار...</option>' + fieldMeta.options[fid].map(function(o){ return '<option value="'+escapeAttr(String(o.value||''))+'">'+escapeHtml(String(o.label||o.value||''))+'</option>'; }).join('');
                        newEl = sel;
                    } else {
                        var inp = document.createElement('input');
                        inp.id = 'arFieldVal'; inp.className = 'ar-input'; inp.placeholder = 'مقدار فیلتر'; inp.style.minWidth = '240px';
                        newEl = inp;
                    }
                    // replace
                    if (prevValEl){ valWrap.replaceChild(newEl, prevValEl); }
                    // rebind reference
                    inpVal = newEl;
                    // restore value if exists
                    try { if (current) newEl.value = current; } catch(_){ }
                }
                if (selField){ selField.removeEventListener && selField.removeEventListener('change', updateFieldValueControl); selField.addEventListener('change', updateFieldValueControl); }
                if (!Array.isArray(resp)){
                    var prev = list.querySelector('button[data-page="prev"]'); var next = list.querySelector('button[data-page="next"]');
                    if (prev) prev.onclick = function(){ state.page = Math.max(1, (resp.page||1)-1); load(); };
                    if (next) next.onclick = function(){ var pages = Math.max(1, Math.ceil((resp.total||0)/(resp.per_page||10))); state.page = Math.min(pages, (resp.page||1)+1); load(); };
                }
            }
            function load(){
                var list = document.getElementById('arSubsList'); if (!list) return;
                list.innerHTML = '<div class="hint">در حال بارگذاری...</div>';
                var qs = buildQuery();
                var url = buildRestUrl('forms/'+formId+'/submissions', (qs? (qs+'&') : '') + 'include=values,fields' + (REST_DEBUG ? '&debug=1' : ''));
                try { clog('results:fetch:url', url); } catch(_){ }
                fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': ARSHLINE_NONCE } })
                .then(async function(r){
                    try { clog('results:fetch:status', r.status); } catch(_){ }
                    var txt = '';
                    try { txt = await r.clone().text(); } catch(_){ }
                    if(!r.ok){
                        try { cerror('results:fetch:error', { status: r.status, body: (txt||'').slice(0, 2000) }); } catch(_){ }
                        if(r.status===401){ if (typeof handle401 === 'function') handle401(); }
                        throw new Error('HTTP '+r.status);
                    }
                    var data;
                    try { data = txt ? JSON.parse(txt) : await r.json(); } catch(e){ try { cerror('results:parse:error', e, 'body:', (txt||'').slice(0, 2000)); } catch(_){ } throw e; }
                    try {
                        if (data && data.debug){ cwarn('results:debug', data.debug); }
                        clog('results:fetch:ok', { total: data.total, rows: (data.rows||[]).length });
                    } catch(_){ }
                    return data;
                })
                .then(function(resp){ renderTable(resp); })
                .catch(function(err){
                    try { cerror('results:render:error', err && (err.message||err)); } catch(_){ }
                    try { console.error('[ARSH] results:render:error', err); } catch(_){ }
                    var msg = (err && (err.message||'')) || '';
                    list.innerHTML = '<div class="hint">خطا در بارگذاری پاسخ‌ها'+(msg?(' — '+escapeHtml(String(msg))):'')+'</div>';
                });
            }
            function addNonce(url){ try { var u = new URL(url); u.searchParams.set('_wpnonce', ARSHLINE_NONCE); return u.toString(); } catch(_){ return url + (url.indexOf('?')>0?'&':'?') + '_wpnonce=' + encodeURIComponent(ARSHLINE_NONCE); } }
            if (expCsv) expCsv.addEventListener('click', function(){ var qs = buildQuery(); var url = buildRestUrl('forms/'+formId+'/submissions', (qs? (qs+'&') : '') + 'format=csv'); window.open(addNonce(url), '_blank'); });
            if (expXls) expXls.addEventListener('click', function(){ var qs = buildQuery(); var url = buildRestUrl('forms/'+formId+'/submissions', (qs? (qs+'&') : '') + 'format=excel'); window.open(addNonce(url), '_blank'); });
            if (wrapToggle) wrapToggle.addEventListener('change', function(){ try { localStorage.setItem('arWrap:'+formId, wrapToggle.checked ? '1' : '0'); } catch(_){ } var root = document.querySelector('.arshline-main'); if(!root) return; root.classList.remove('ar-wrap','ar-nowrap'); root.classList.add(wrapToggle.checked ? 'ar-wrap' : 'ar-nowrap'); });
            if (btnApply) btnApply.addEventListener('click', function(){ state.page = 1; load(); });
            if (btnClear) btnClear.addEventListener('click', function(){ if (selField) selField.value=''; if (inpVal) inpVal.value=''; if (selOp) selOp.value='like'; state.page = 1; load(); });
            load();
        }

        function suggestPlaceholder(fmt){
            switch(fmt){
                case 'email': return 'example@mail.com';
                case 'mobile_ir': return '09123456789';
                case 'mobile_intl': return '+14155552671';
                case 'tel': return '021-12345678';
                case 'numeric': return '123456';
                case 'rating': return 'star-outline'; // Added case for rating
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
            var creating = (builder && builder.getAttribute('data-creating') === '1');
            var intendedInsert = builder ? parseInt(builder.getAttribute('data-intended-insert')||'-1') : -1;
            dlog('saveFields:start', { id: id, idx: idx });
            var canvas = document.getElementById('arCanvas');
            var edited = Array.from(canvas.children).map(function(el){ return JSON.parse(el.dataset.props||'{}'); })[0] || {};
            dlog('saveFields:edited', edited);
            var btn = document.getElementById('arSaveFields');
            if (btn){ btn.disabled = true; btn.textContent = 'در حال ذخیره...'; }
            // Safety guard: if index is invalid, abort to prevent inadvertent array growth
            if (isNaN(idx) || idx < 0){
                dlog('saveFields:invalid-idx-abort', idx);
                notify('مکان فیلد نامعتبر است. لطفاً صفحه را نوسازی کنید و دوباره تلاش کنید.', 'error');
                if (btn){ btn.disabled = false; btn.textContent = 'ذخیره'; }
                return Promise.resolve(false);
            }
            if (!ARSHLINE_CAN_MANAGE){ notify('برای ویرایش فرم باید وارد شوید یا دسترسی داشته باشید', 'error'); if (btn){ btn.disabled=false; btn.textContent='ذخیره'; } return Promise.resolve(false); }
            return fetch(ARSHLINE_REST + 'forms/'+id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(data){
                    dlog('saveFields:loaded-current-fields', (data&&data.fields)?data.fields.length:0);
                    var arr = (data && data.fields) ? data.fields.slice() : [];
                    if (creating){
                        var at = (!isNaN(intendedInsert) && intendedInsert >= 0 && intendedInsert <= arr.length) ? intendedInsert : arr.length;
                        // If there is a thank_you at the end and intendedInsert equals arr.length, insert before it
                        try { var last = arr[arr.length-1]; var lp = last && (last.props||last); if (lp && (lp.type||last.type)==='thank_you' && at >= arr.length) at = arr.length - 1; } catch(_){ }
                        arr.splice(at, 0, edited);
                    } else {
                        if (idx >=0 && idx < arr.length) { arr[idx] = edited; }
                        else { arr.push(edited); }
                    }
                    try { if (typeof console !== 'undefined') console.log('[ARSH] saveFields - sending field.question (sample):', String(edited.question||'').slice(0,200)); } catch(_){ }
                    try { if (typeof console !== 'undefined') console.log('[ARSH] saveFields - full payload fields count:', arr.length); } catch(_){ }
                    dlog('saveFields:payload', arr);
                    return fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) })
                        .then(async function(r){ try { var txt = await r.clone().text(); try { if (typeof console !== 'undefined') console.log('[ARSH] saveFields - server response text:', txt); } catch(_){ } } catch(_){ }
                            return r; });
                })
                .then(async r=>{ if(!r.ok){ if (r.status===401){ if (typeof handle401 === 'function') handle401(); else notify('اجازهٔ انجام این عملیات را ندارید. لطفاً وارد شوید یا با مدیر تماس بگیرید.', 'error'); } let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(){
                    notify('ذخیره شد', 'success');
                    // Ensure we navigate back to the form builder after a successful save
                    try {
                        var b = document.getElementById('arBuilder');
                        var idStr = b ? (b.getAttribute('data-form-id') || '0') : '0';
                        var idNum = parseInt(idStr);
                        if (!isNaN(idNum) && idNum > 0){
                            try { renderFormBuilder(idNum); }
                            catch(_){ if (typeof window.renderFormBuilder === 'function') window.renderFormBuilder(idNum); }
                        }
                    } catch(_) { /* no-op */ }
                    return true;
                })
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
            // First, ensure token exists for this form
            fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                .then(function(){ return fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }); })
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
                            block.innerHTML = (heading ? ('<div class="title" style="margin-bottom:.35rem;">'+heading+'</div>') : '') + img + (message ? ('<div class="hint">'+escapeHtml(message)+'</div>') : '');
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
                        var ariaQ = htmlToText(sanitizedQ || 'پرسش بدون عنوان');
                        var qDisplayHtml = sanitizedQ || 'پرسش بدون عنوان';
                        var questionBlock = '<div class="hint" style="margin-bottom:.25rem">'+ (numbered ? (numberStr + qDisplayHtml) : qDisplayHtml) +'</div>';
                        if (type === 'long_text'){
                            row.innerHTML = questionBlock +
                                '<textarea id="'+inputId+'" class="ar-input" style="width:100%" rows="4" placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'"></textarea>' +
                                (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                        } else if (type === 'multiple_choice' || type === 'multiple-choice') {
                            var opts = p.options || [];
                            var vertical = (p.vertical !== false);
                            var multiple = !!p.multiple;
                            var html = '<div style="display:flex;flex-direction:'+(vertical?'column':'row')+';gap:.5rem;flex-wrap:wrap">';
                            opts.forEach(function(o, i){
                                var lbl = sanitizeQuestionHtml(o.label||'');
                                var sec = o.second_label?('<div class="hint" style="font-size:.8rem">'+escapeHtml(o.second_label)+'</div>') : '';
                                html += '<label style="display:flex;align-items:center;gap:.5rem;"><input type="'+(multiple?'checkbox':'radio')+'" name="mc_'+(f.id||i)+'" value="'+escapeAttr(o.value||'')+'" /> <span>'+lbl+'</span> '+sec+'</label>';
                            });
                            html += '</div>';
                            row.innerHTML = questionBlock + html +
                                (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                        } else if (type === 'dropdown') {
                            var dOpts = (p.options || []).slice();
                            if (p.alpha_sort){ dOpts.sort(function(a,b){ return String(a.label||'').localeCompare(String(b.label||''), 'fa'); }); }
                            if (p.randomize){ for (var z=dOpts.length-1; z>0; z--){ var j=Math.floor(Math.random()*(z+1)); var tmp=dOpts[z]; dOpts[z]=dOpts[j]; dOpts[j]=tmp; } }
                            var selHtml = '<select id="'+inputId+'" class="ar-input" style="width:100%" data-field-id="'+f.id+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'">';
                            selHtml += '<option value="">'+escapeHtml(p.placeholder || 'انتخاب کنید')+'</option>';
                            dOpts.forEach(function(o){ selHtml += '<option value="'+escapeAttr(o.value||'')+'">'+escapeHtml(o.label||'')+'</option>'; });
                            selHtml += '</select>';
                            row.innerHTML = questionBlock + selHtml + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                        } else if (type === 'rating') {
                            var count = parseInt(p.max||5); if (isNaN(count) || count<1) count=1; if (count>20) count=20;
                            var key = String(p.icon||'star');
                            function mapIcon(k){
                                switch(k){
                                    case 'heart': return { solid:'heart', outline:'heart-outline' };
                                    case 'thumb': return { solid:'thumbs-up', outline:'thumbs-up-outline' };
                                    case 'medal': return { solid:'ribbon', outline:'ribbon-outline' };
                                    case 'smile': return { solid:'happy', outline:'happy-outline' };
                                    case 'sad': return { solid:'sad', outline:'sad-outline' };
                                    default: return { solid:'star', outline:'star-outline' };
                                }
                            }
                            var names = mapIcon(key);
                            var icons = '';
                            for (var ri=1; ri<=count; ri++){
                                icons += '<span class="ar-rating-icon" data-value="'+ri+'" style="cursor:pointer;font-size:1.5rem;color:var(--muted);display:inline-flex;align-items:center;justify-content:center;margin-inline-start:.15rem;">'
                                    + '<ion-icon name="'+names.outline+'"></ion-icon>'
                                    + '</span>';
                            }
                            var ratingHtml = '<div class="ar-rating-wrap" data-icon-solid="'+names.solid+'" data-icon-outline="'+names.outline+'" data-field-id="'+f.id+'" role="radiogroup" aria-label="امتیاز" style="display:flex;align-items:center;gap:.1rem;">'+icons+'</div>'
                                + '<input type="hidden" id="'+inputId+'" data-field-id="'+f.id+'" value="" />';
                            row.innerHTML = questionBlock + ratingHtml + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                        } else {
                            row.innerHTML = questionBlock +
                                '<input id="'+inputId+'" class="ar-input" style="width:100%" '+(attrs.type?('type="'+attrs.type+'"'):'')+' '+(attrs.inputmode?('inputmode="'+attrs.inputmode+'"'):'')+' '+(attrs.pattern?('pattern="'+attrs.pattern+'"'):'')+' placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'" />' +
                                (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                        }
                        fwrap.appendChild(row);
                        questionProps.push(p);
                    });
                    // apply masks (include textarea for long_text)
                    fwrap.querySelectorAll('input[data-field-id], textarea[data-field-id]').forEach(function(inp, idx){
                        var props = questionProps[idx] || {};
                        applyInputMask(inp, props);
                        if ((props.format||'') === 'date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){
                            try { jQuery(inp).pDatepicker({ format: 'YYYY/MM/DD', initialValue: false }); } catch(e){}
                        }
                    });
                    // Wire up rating interactions: click to set value and update icons
                    try {
                        Array.from(fwrap.querySelectorAll('.ar-rating-wrap')).forEach(function(wrap){
                            var solid = wrap.getAttribute('data-icon-solid') || 'star';
                            var outline = wrap.getAttribute('data-icon-outline') || 'star-outline';
                            var hidden = wrap.nextElementSibling;
                            var items = Array.from(wrap.querySelectorAll('.ar-rating-icon'));
                            function update(v){ items.forEach(function(el, idx){ var ion = el.querySelector('ion-icon'); if (ion){ ion.setAttribute('name', idx < v ? solid : outline); } el.style.color = idx < v ? 'var(--primary)' : 'var(--muted)'; }); if (hidden) hidden.value = String(v||''); }
                            items.forEach(function(el){
                                el.addEventListener('click', function(){ var v = parseInt(el.getAttribute('data-value')||'0')||0; update(v); });
                                el.setAttribute('tabindex','0');
                                el.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' '){ e.preventDefault(); var v = parseInt(el.getAttribute('data-value')||'0')||0; update(v); } });
                            });
                            update(0);
                        });
                    } catch(_){ }
                    document.getElementById('arPreviewSubmit').onclick = function(){
                        var vals = [];
                        // include both inputs and textareas
                        Array.from(fwrap.querySelectorAll('input[data-field-id], textarea[data-field-id]')).forEach(function(inp, idx){
                            var fid = parseInt(inp.getAttribute('data-field-id')||'0');
                            vals.push({ field_id: fid, value: inp.value||'' });
                        });
                        fetch(ARSHLINE_REST + 'forms/'+id+'/submissions', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ values: vals }) })
                            .then(async r=>{ if (!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                            .then(function(){ notify('ارسال شد', 'success'); })
                            .catch(function(){ notify('اعتبارسنجی/ارسال ناموفق بود', 'error'); });
                    };
                    document.getElementById('arPreviewBack').onclick = function(){
                        document.body.classList.remove('preview-only');
                        try {
                            var back = window._arBackTo; window._arBackTo = null;
                            if (back && back.view === 'builder' && back.id){ renderFormBuilder(back.id); return; }
                            if (back && back.view === 'editor' && back.id){ renderFormEditor(back.id, { index: back.index || 0 }); return; }
                        } catch(_){ }
                        renderTab('forms');
                    };
                });
        }

        function renderFormEditor(id, opts){
            dlog('renderFormEditor:start', { id: id, opts: opts });
            // Merge any pending creating context (set by addNewField) to survive routing re-entry
            try {
                if (!opts || !opts.creating) {
                    var pend = (typeof window !== 'undefined') ? window._arPendingEditor : null;
                    if (pend && pend.id === id) {
                        opts = Object.assign({}, opts||{}, pend);
                        try { window._arPendingEditor = null; } catch(_){ }
                        dlog('renderFormEditor:merged-pending-opts', opts);
                    }
                }
            } catch(_){ }
            if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); renderTab('forms'); return; }
            try { setSidebarClosed(true, false); } catch(_){ }
            try {
                var idxHashRaw = (opts && typeof opts.index!=='undefined') ? opts.index : 0;
                var idxHash = parseInt(idxHashRaw);
                if (isNaN(idxHash)) idxHash = 0;
                // Important: avoid changing hash in creating mode to prevent routeFromHash re-entry
                if (!(opts && opts.creating)) {
                    setHash('editor/'+id+'/'+idxHash);
                }
            } catch(_){ }
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

            document.getElementById('arEditorBack').onclick = function(){ dlog('arEditorBack:click'); renderFormBuilder(id); };
            var prevBtnE = document.getElementById('arEditorPreview'); if (prevBtnE) prevBtnE.onclick = function(){ try { window._arBackTo = { view: 'editor', id: id, index: fieldIndex }; } catch(_){ } renderFormPreview(id); };
            content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');

                    var defaultProps = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
                    // Prevent UI flash: hide settings/preview until data fetch finishes
                    try { var builderEl = document.getElementById('arBuilder'); if (builderEl){ builderEl.classList.add('editor-loading'); } } catch(_){ }
                    var longTextDefaults = {
                        type: 'long_text',
                        label: 'پاسخ طولانی',
                        format: 'free_text',
                        required: false,
                        show_description: false,
                        description: '',
                        placeholder: '',
                        question: '',
                        numbered: true,
                        min_length: 0,
                        max_length: 1000,
                        media_upload: false
                    };
            fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                .then(r=>r.json())
                .then(function(data){
                    dlog('renderFormEditor:data-loaded', data && data.fields ? data.fields.length : 0);
                    // Data loaded, reveal editor UI
                    try { if (builderEl) builderEl.classList.remove('editor-loading'); } catch(_){ }
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
                    var creating = !!(opts && opts.creating);
                    var pendCtx = (typeof window !== 'undefined') ? window._arPendingEditor : null;
                    var pendType = (pendCtx && pendCtx.id === id) ? (pendCtx.newType || null) : null;
                    var newTypeFromOpts = (opts && opts.newType) ? String(opts.newType) : null;
                    var newTypeEffective = newTypeFromOpts || pendType || null;
                    // Resolve target index: in creating mode honor the intended insert; if idx is out-of-range treat as creating
                    if (creating){
                        var fi = parseInt(fieldIndex);
                        if (isNaN(fi) || fi < 0){
                            // fallback: insert at end (before thank_you if present)
                            var hasThankIdx = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type) === 'thank_you'; });
                            idx = (hasThankIdx !== -1) ? hasThankIdx : fields.length;
                        } else {
                            idx = fi;
                        }
                    } else {
                        // Editing existing field: if index invalid -> 0; if out-of-range -> switch to creating-at-end
                        if (isNaN(idx) || idx < 0) idx = 0;
                        else if (idx >= fields.length){
                            creating = true;
                            var hasThankIdx2 = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type) === 'thank_you'; });
                            idx = (hasThankIdx2 !== -1) ? hasThankIdx2 : fields.length;
                        }
                    }
                    dlog('renderFormEditor:flags', { fieldIndex: fieldIndex, creating: creating, newType: newTypeEffective, fieldsLen: fields.length });
                    // Keep builder dataset in sync so saveFields updates proper index and mode
                    try {
                        var b = document.getElementById('arBuilder');
                        if (b) {
                            b.setAttribute('data-field-index', String(idx));
                            b.setAttribute('data-creating', creating ? '1' : '0');
                            if (creating && typeof opts.intendedInsert !== 'undefined') b.setAttribute('data-intended-insert', String(opts.intendedInsert));
                            if (creating && typeof opts.newType !== 'undefined') b.setAttribute('data-new-type', String(opts.newType));
                        }
                    } catch(_){ }
                    dlog('renderFormEditor:resolved-index', idx);
                    // Choose defaults by requested newType when creating
                    var newType = newTypeEffective;
                    var typeDefaults = (newType && ARSH && ARSH.Tools && ARSH.Tools.getDefaults(newType))
                        || (newType === 'long_text' ? longTextDefaults : null)
                        || (ARSH && ARSH.Tools && ARSH.Tools.getDefaults('short_text'))
                        || defaultProps;
                    // In creating mode we always render the editor with defaults for newType
                    var base = creating ? typeDefaults : (fields[idx] || typeDefaults);
                    var field = base.props || base || defaultProps;
                    // Force requested type when creating if defaults didn't carry it
                    if (creating && newType && (field.type||'') !== newType){ field.type = newType; }
                    var fType = field.type || base.type || 'short_text';
                    dlog('renderFormEditor:field-type', fType);
                    // Try tool module first (if provided)
                    var sWrap = document.querySelector('.ar-settings');
                    var pWrap = document.querySelector('.ar-preview');
                    var mod = (window.ARSH && ARSH.Tools && ARSH.Tools.get(fType));
                    if (mod && typeof mod.renderEditor === 'function'){
                        try {
                            var ctx = {
                                id: id,
                                idx: idx,
                                fields: fields,
                                wrappers: { settings: sWrap, preview: pWrap },
                                sanitizeQuestionHtml: sanitizeQuestionHtml,
                                escapeHtml: escapeHtml,
                                escapeAttr: escapeAttr,
                                inputAttrsByFormat: inputAttrsByFormat,
                                suggestPlaceholder: suggestPlaceholder,
                                notify: notify,
                                dlog: dlog,
                                setDirty: setDirty,
                                saveFields: saveFields,
                                ARSHLINE_REST: ARSHLINE_REST,
                                ARSHLINE_NONCE: ARSHLINE_NONCE,
                                ARSHLINE_CAN_MANAGE: ARSHLINE_CAN_MANAGE
                            };
                            // Support both module styles
                            var handled = (mod.renderEditor.length >= 2) ? !!mod.renderEditor(field, ctx) : !!mod.renderEditor({ field: field, id: id, idx: idx, fields: fields, wrappers: { settings: sWrap, preview: pWrap }, sanitizeQuestionHtml: sanitizeQuestionHtml, escapeHtml: escapeHtml, escapeAttr: escapeAttr, inputAttrsByFormat: inputAttrsByFormat, suggestPlaceholder: suggestPlaceholder, notify: notify, dlog: dlog, setDirty: setDirty, saveFields: saveFields, restUrl: ARSHLINE_REST, restNonce: ARSHLINE_NONCE });
                            if (handled) return; // module took over rendering/editor wiring
                        } catch(_){ /* fall back to inline renderer */ }
                    }
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
                    // Only render the correct field type UI (fix double-render)  
                    if (field.type === 'multiple_choice' || field.type === 'multiple-choice') {
                        console.log('[DEBUG] Rendering multiple choice UI');
                        
                        // Multiple choice editor panel
                        var sWrap = document.querySelector('.ar-settings');
                        var pWrap = document.querySelector('.ar-preview');
                        if (sWrap) {
                            sWrap.innerHTML = `
                                <div class="title" style="margin-bottom:.6rem;">سوال چندگزینه‌ای</div>
                                <div class="field" style="margin-bottom:.6rem;">
                                    <label class="hint">متن سؤال</label>
                                    <div id="fQuestionRich" contenteditable="true" style="min-height:44px;padding:.5rem;border:1px solid var(--border);border-radius:8px;background:var(--surface)"></div>
                                </div>
                                <div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">
                                    <label class="hint">اجباری</label>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcRequired" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>
                                    </div>
                                </div>
                                <div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">
                                    <label class="hint">چندانتخابی</label>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcMultiple" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>
                                    </div>
                                </div>
                                <div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">
                                    <label class="hint">نمایش به‌صورت عمودی</label>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcVertical" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>
                                    </div>
                                </div>
                                <div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">
                                    <label class="hint">همگن‌سازی گزینه‌ها (تصادفی‌سازی)</label>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcRandomize" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>
                                    </div>
                                </div>
                                <div style="margin-top:.6rem;margin-bottom:.6rem;">
                                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
                                        <div class="hint">گزینه‌ها</div>
                                        <button id="mcAddOption" class="ar-btn" aria-label="افزودن گزینه" title="افزودن گزینه" style="width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:1.2rem;line-height:1;">+</button>
                                    </div>
                                    <div id="mcOptionsList" style="display:flex;flex-direction:column;gap:.5rem;"></div>
                                </div>
                                <div style="margin-top:12px">
                                    <button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button>
                                </div>`;
                        }
                        if (pWrap){
                            pWrap.innerHTML = `
                                <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>
                                <div id="mcPreview" style="margin-bottom:.6rem;max-width:100%;overflow:hidden"></div>`;
                        }

                        // Helper functions for multiple choice
                        function updateHiddenProps(p){ var el = document.querySelector('#arCanvas .ar-item'); if (el) el.setAttribute('data-props', JSON.stringify(p)); }

                        // Initialize options
                        var opts = field.options || field.choices || [{ label: 'گزینه 1', value: 'opt_1', media_url: '', second_label: '' }];
                        field.options = opts;

                        // Set up event handlers
                        var qEl = document.getElementById('fQuestionRich'); if (qEl){ qEl.innerHTML = sanitizeQuestionHtml(field.question || ''); qEl.addEventListener('input', function(){
                            field.question = sanitizeQuestionHtml(qEl.innerHTML);
                            updateHiddenProps(field);
                            renderMCPreview(field);
                            setDirty(true);
                        }); }

                        var mcRequired = document.getElementById('mcRequired'); if (mcRequired){ mcRequired.checked = !!field.required; mcRequired.addEventListener('change', function(){ field.required = !!mcRequired.checked; updateHiddenProps(field); renderMCPreview(field); setDirty(true); }); }
                        var mcMultiple = document.getElementById('mcMultiple'); if (mcMultiple){ mcMultiple.checked = !!field.multiple; mcMultiple.addEventListener('change', function(){ field.multiple = !!mcMultiple.checked; updateHiddenProps(field); renderMCPreview(field); setDirty(true); }); }
                        var mcVertical = document.getElementById('mcVertical'); if (mcVertical){ mcVertical.checked = (field.vertical !== false); mcVertical.addEventListener('change', function(){ field.vertical = !!mcVertical.checked; updateHiddenProps(field); renderMCPreview(field); setDirty(true); }); }
                        var mcRandomize = document.getElementById('mcRandomize'); if (mcRandomize){ mcRandomize.checked = !!field.randomize; mcRandomize.addEventListener('change', function(){ field.randomize = !!mcRandomize.checked; updateHiddenProps(field); renderMCPreview(field); setDirty(true); }); }

                        var mcList = document.getElementById('mcOptionsList');
                        function renderOptionsList(){
                            if (!mcList) return;
                            mcList.innerHTML = '';
                            opts.forEach(function(o, i){
                                var html = '<div class="card" data-idx="'+i+'" style="padding:.35rem;display:grid;grid-template-columns:auto 1fr auto;grid-auto-rows:auto;gap:.4rem;align-items:center;">'
                                    // first line: + / −  |  label input  |  drag handle
                                    + '<div class="mc-row-tools" style="display:flex;gap:.35rem;align-items:center;">'
                                        + '<button class="ar-btn mcAddHere" data-idx="'+i+'" aria-label="افزودن گزینه بعد از این" title="افزودن" style="width:28px;height:28px;border-radius:8px;padding:0;line-height:1;font-size:1.1rem;display:inline-flex;align-items:center;justify-content:center;">+</button>'
                                        + '<button class="ar-btn ar-btn--soft mcRemove" data-idx="'+i+'" aria-label="حذف این گزینه" title="حذف" style="width:28px;height:28px;border-radius:8px;padding:0;line-height:1;font-size:1.1rem;display:inline-flex;align-items:center;justify-content:center;">−</button>'
                                    + '</div>'
                                    + '<input type="text" class="ar-input" data-role="mc-label" placeholder="متن گزینه" value="'+escapeHtml(o.label||'')+'" style="min-width:120px;max-width:100%;" />'
                                    + '<span class="ar-dnd-handle" title="جابجایی" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;">≡</span>'
                                    // second line spans all columns: second label input (optional)
                                    + '<input type="text" class="ar-input" data-role="mc-second" placeholder="برچسب دوم (اختیاری)" value="'+escapeHtml(o.second_label||'')+'" style="grid-column:1 / -1;min-width:120px;max-width:100%;" />'
                                    + '</div>';
                                var div = document.createElement('div'); div.innerHTML = html;
                                mcList.appendChild(div.firstChild);
                            });
                            // Bind input events
                            Array.from(mcList.querySelectorAll('[data-role="mc-label"]')).forEach(function(inp, idx){ inp.addEventListener('input', function(){ opts[idx].label = inp.value; updateHiddenProps(field); renderMCPreview(field); setDirty(true); }); });
                            Array.from(mcList.querySelectorAll('[data-role="mc-second"]')).forEach(function(inp, idx){ inp.addEventListener('input', function(){ opts[idx].second_label = inp.value; updateHiddenProps(field); renderMCPreview(field); setDirty(true); }); });
                            Array.from(mcList.querySelectorAll('.mcAddHere')).forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); var ii = parseInt(btn.getAttribute('data-idx')||'0'); var n = opts.length + 1; var newOpt = { label:'گزینه '+n, value:'opt_'+(Date.now()%100000), media_url:'', second_label:'' }; opts.splice(isNaN(ii)?opts.length:(ii+1), 0, newOpt); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); setDirty(true); }); });
                            Array.from(mcList.querySelectorAll('.mcRemove')).forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); var ii = parseInt(btn.getAttribute('data-idx')||'0'); if (!isNaN(ii)) opts.splice(ii,1);
                                if (!opts.length) opts.push({ label:'گزینه 1', value:'opt_1', media_url:'', second_label:'' });
                                field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); setDirty(true); }); });
                            // Enable drag sort for options
                            function ensureSortableMC(cb){ if (window.Sortable) { cb(); return; } var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js'; s.onload = function(){ cb(); }; document.head.appendChild(s); }
                            ensureSortableMC(function(){
                                try { if (window._mcSortable) window._mcSortable.destroy(); } catch(_){ }
                                window._mcSortable = Sortable.create(mcList, {
                                    animation: 150,
                                    handle: '.ar-dnd-handle',
                                    draggable: '[data-idx]',
                                    onEnd: function(){
                                        try {
                                            var order = Array.from(mcList.children).map(function(el){ return parseInt(el.getAttribute('data-idx')||''); }).filter(function(n){ return !isNaN(n); });
                                            if (order.length){
                                                var newOpts = order.map(function(i){ return opts[i]; });
                                                opts.splice(0, opts.length); Array.prototype.push.apply(opts, newOpts);
                                                field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); setDirty(true);
                                            }
                                        } catch(_){ }
                                    }
                                });
                            });
                        }

                        function renderMCPreview(p){
                            var out = document.getElementById('mcPreview'); if (!out) return;
                            var parts = [];
                            // Question (with numbering like short_text)
                            try {
                                var showQ = p.question && String(p.question).trim();
                                if (showQ){
                                    var qIndex = 1;
                                    try {
                                        var beforeCount = 0;
                                        (fields||[]).forEach(function(ff, i3){ if (i3 < idx){ var pp = ff.props || ff; var t = pp.type || ff.type || 'short_text'; if (t !== 'welcome' && t !== 'thank_you'){ beforeCount += 1; } } });
                                        qIndex = beforeCount + 1;
                                    } catch(_){ qIndex = (idx+1); }
                                    var numPrefix = (p.numbered !== false ? (qIndex + '. ') : '');
                                    var sanitized = sanitizeQuestionHtml(showQ || '');
                                    parts.push('<div class="hint" style="margin-bottom:.25rem">'+numPrefix+sanitized+'</div>');
                                }
                            } catch(_){ }
                            // Options
                            if (!p.options || !Array.isArray(p.options) || !p.options.length) {
                                parts.push('<div class="hint">هنوز گزینه‌ای اضافه نشده است.</div>');
                                out.innerHTML = parts.join('');
                                return;
                            }
                            var localOpts = (p.options || []).slice();
                            if (p.randomize){
                                for (var z=localOpts.length-1; z>0; z--){ var j = Math.floor(Math.random()*(z+1)); var tmp=localOpts[z]; localOpts[z]=localOpts[j]; localOpts[j]=tmp; }
                            }
                            var type = p.multiple ? 'checkbox' : 'radio';
                            var vertical = (p.vertical !== false);
                            var html = '<div style="display:flex;flex-direction:'+(vertical?'column':'row')+';gap:.6rem;flex-wrap:wrap;align-items:flex-start;">';
                            localOpts.forEach(function(o){
                                var lbl = sanitizeQuestionHtml(o.label||'');
                                var sec = o.second_label ? ('<div class="hint" style="font-size:.8rem;margin-'+(document.dir==='rtl'?'right':'left')+':1.9rem;">'+escapeHtml(o.second_label)+'</div>') : '';
                                html += '<div class="mc-opt" style="display:flex;flex-direction:column;gap:.25rem;max-width:100%;">'
                                        + '<label style="display:flex;align-items:center;gap:.5rem;max-width:100%;"><input type="'+type+'" disabled /> <span>'+lbl+'</span></label>'
                                        + sec
                                    + '</div>';
                            });
                            html += '</div>';
                            parts.push(html);
                            out.innerHTML = parts.join('');
                        }

                        // Initial render
                        renderOptionsList(); renderMCPreview(field);

                        var addBtn = document.getElementById('mcAddOption'); if (addBtn){ addBtn.addEventListener('click', function(e){ e.preventDefault(); var n = opts.length+1; opts.push({ label:'گزینه '+n, value:'opt_'+(Date.now()%100000), media_url:'', second_label:'' }); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); setDirty(true); }); }

                        var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
                        return;
                        
                    } else if (field.type === 'long_text' || field.type === 'longtext' || field.type === 'long-text') {
                        var sWrap = document.querySelector('.ar-settings');
                        var pWrap = document.querySelector('.ar-preview');
                        if (sWrap) {
                            sWrap.innerHTML = `
                                <div class="title" style="margin-bottom:.6rem;">تنظیمات متن بلند</div>
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                                    <label class="hint">سؤال</label>
                                    <div style="display:flex;gap:.35rem;align-items:center;margin-bottom:6px;">
                                        <button id="fQBold" class="ar-btn ar-btn--outline" type="button" title="پررنگ"><b>B</b></button>
                                        <button id="fQItalic" class="ar-btn ar-btn--outline" type="button" title="مورب"><i>I</i></button>
                                        <button id="fQUnder" class="ar-btn ar-btn--outline" type="button" title="زیرخط"><u>U</u></button>
                                        <input id="fQColor" type="color" title="رنگ" style="margin-inline-start:.25rem;width:36px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);" />\
                                    </div>
                                    <div id="fQuestionRich" class="ar-input" contenteditable="true" style="min-height:60px;line-height:1.8;" placeholder="متن سؤال"></div>
                                    <div class="hint" style="font-size:.92em;color:var(--muted);margin-top:2px;">با تایپ <b>@</b> از پاسخ‌ها و متغیرها استفاده کنید.</div>
                                </div>
                                <!-- media controls are rendered at the bottom and shown only when media_upload is enabled -->
                                <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                                    <span class="hint">اجباری</span>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl">
                                            <input type="checkbox" id="fRequired" class="vc-switch-input" />
                                            <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>
                                            <span class="vc-switch-handle"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                                    <span class="hint">عدم نمایش شماره‌ سؤال</span>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl">
                                            <input type="checkbox" id="fHideNumber" class="vc-switch-input" />
                                            <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>
                                            <span class="vc-switch-handle"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                                    <span class="hint">توضیحات</span>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl">
                                            <input type="checkbox" id="fDescToggle" class="vc-switch-input" />
                                            <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>
                                            <span class="vc-switch-handle"></span>
                                        </label>
                                    </div>
                                </div>
                                <div class="field" id="fDescWrap" style="display:none">
                                    <textarea id="fDescText" class="ar-input" rows="2" placeholder="توضیح زیر سؤال"></textarea>
                                </div>
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
                                    <label class="hint">متن راهنما (placeholder)</label>
                                    <input id="fHelp" class="ar-input" placeholder="مثال: پاسخ را وارد کنید"/>\
                                </div>
                                <div class="field" style="display:flex;gap:10px;margin-top:8px;align-items:center;">
                                    <span class="hint">حداقل تعداد حروف</span>
                                    <input id="fMinLen" class="ar-input" type="number" min="0" max="1000" style="width:80px" value="0" />
                                    <span class="hint">حداکثر</span>
                                    <input id="fMaxLen" class="ar-input" type="number" min="1" max="5000" style="width:80px" value="1000" />
                                </div>
                                <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                                    <span class="hint">امکان آپلود تصویر</span>
                                    <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">
                                        <label class="vc-small-switch vc-rtl">
                                            <input type="checkbox" id="fMediaUpload" class="vc-switch-input" />
                                            <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>
                                            <span class="vc-switch-handle"></span>
                                        </label>
                                    </div>
                                </div>
                                <!-- media upload controls (image only) -->
                                <div id="fMediaWrap" style="display:none;margin-top:8px;">
                                    <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                                        <label class="hint">آپلود تصویر سؤال (JPG/PNG تا 300KB)</label>
                                        <input id="fImageFile" type="file" accept="image/jpeg,image/png" style="margin-bottom:4px" />
                                            <div id="fImagePreviewWrap" style="width:200px;height:200px;overflow:hidden;display:${field.image_url?'flex':'none'};align-items:center;justify-content:center;margin-bottom:6px;border-radius:10px;background:transparent"> 
                                                <img id="fImagePreview" src="${field.image_url||''}" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:8px;" />
                                            </div>
                                        <span id="fImageError" class="hint" style="color:#b91c1c;display:none"></span>
                                    </div>
                                </div>
                                <div style="margin-top:12px">
                                    <button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button>
                                </div>`;
                        }
                        if (pWrap) {
                            pWrap.innerHTML = `
                                <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>
                                <div id="pvMedia" style="margin-bottom:6px">${field.image_url?('<div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:6px;border-radius:10px"><img id="pvImage" src="'+(field.image_url||'')+'" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:6px" /></div>') : ''}</div>
                                <div id="pvQuestion" class="hint" style="display:none;margin-bottom:.25rem"></div>
                                <div id="pvDesc" class="hint" style="display:none;margin-bottom:.35rem"></div>
                                <textarea id="pvInput" class="ar-input" style="width:100%" rows="4"></textarea>
                                <div id="pvHelp" class="hint" style="display:none"></div>
                                <div id="pvErr" class="hint" style="color:#b91c1c;margin-top:.3rem"></div>`;
                        }
                        // Bind settings inputs for long_text editor
                        var qEl = document.getElementById('fQuestionRich');
                        var req = document.getElementById('fRequired');
                        var hideNum = document.getElementById('fHideNumber');
                        var dTg = document.getElementById('fDescToggle');
                        var dTx = document.getElementById('fDescText');
                        var dWrap = document.getElementById('fDescWrap');
                        var help = document.getElementById('fHelp');
                        var minLen = document.getElementById('fMinLen');
                        var maxLen = document.getElementById('fMaxLen');
                        var mediaUp = document.getElementById('fMediaUpload');
                        var mediaWrap = document.getElementById('fMediaWrap');
                        var imgFile = document.getElementById('fImageFile');
                        var imgPrev = document.getElementById('fImagePreview');
                        var imgErr = document.getElementById('fImageError');
                        var videoFile = document.getElementById('fVideoFile');
                        var videoPrev = document.getElementById('fVideoPreview');
                        var videoErr = document.getElementById('fVideoError');
                        function updateHiddenProps(p){
                            var el = document.querySelector('#arCanvas .ar-item');
                            if (el) el.setAttribute('data-props', JSON.stringify(p));
                        }
                        function applyPreviewFrom(p){
                            var inp = document.getElementById('pvInput');
                            if (!inp) return;
                            inp.value = '';
                            inp.setAttribute('placeholder', (p.placeholder && p.placeholder.trim()) ? p.placeholder : 'پاسخ را وارد کنید');
                            inp.setAttribute('minlength', p.min_length || 0);
                            inp.setAttribute('maxlength', p.max_length || 1000);
                            inp.required = !!p.required;
                            var mediaWrap = document.getElementById('pvMedia');
                            if (mediaWrap){
                                try {
                                    if (p.image_url){ mediaWrap.innerHTML = '<div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:6px;border-radius:10px"><img src="'+p.image_url+'" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:6px" /></div>'; mediaWrap.style.display = 'block'; }
                                    else { mediaWrap.innerHTML = ''; mediaWrap.style.display = 'none'; }
                               
                                } catch(_){ /* ignore */ }
                            }
                            var qNode = document.getElementById('pvQuestion');
                            if (qNode){
                                var showQ = (p.question && String(p.question).trim());
                                qNode.style.display = showQ ? 'block' : 'none';
                                // Compute the real visible question index (skip welcome/thank_you before this idx)
                                var qIndex = 1;
                                try {
                                    var beforeCount = 0;
                                    (fields||[]).forEach(function(ff, i3){
                                        if (i3 < idx){
                                            var pp = ff.props || ff; var t = pp.type || ff.type || 'short_text';
                                            if (t !== 'welcome' && t !== 'thank_you'){ beforeCount += 1; }
                                        }
                                    });
                                    qIndex = beforeCount + 1;
                                } catch(_){ qIndex = (idx+1); }
                                var numPrefix = (p.numbered !== false ? (qIndex + '. ') : '');
                                var sanitized = sanitizeQuestionHtml(showQ || '');
                                qNode.innerHTML = showQ ? (numPrefix + sanitized) : '';
                                try { if (typeof console !== 'undefined') console.log('[ARSH] applyPreviewFrom pvQuestion.innerHTML:', qNode.innerHTML); } catch(_){ }
                            }
                            var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent = p.description || ''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; }
                        }
                        var qBold = document.getElementById('fQBold');
                        var qItalic = document.getElementById('fQItalic');
                        var qUnder = document.getElementById('fQUnder');
                        var qColor = document.getElementById('fQColor');
                        if (qEl){ qEl.innerHTML = sanitizeQuestionHtml(field.question || ''); qEl.addEventListener('input', function(){
                            field.question = sanitizeQuestionHtml(qEl.innerHTML);
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (qBold){ qBold.addEventListener('click', function(e){
                            e.preventDefault();
                            try { document.execCommand('bold'); } catch(_){ }
                            if (qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }
                        }); }
                        if (qItalic){ qItalic.addEventListener('click', function(e){
                            e.preventDefault();
                            try { document.execCommand('italic'); } catch(_){ }
                            if (qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }
                        }); }
                        if (qUnder){ qUnder.addEventListener('click', function(e){
                            e.preventDefault();
                            try { document.execCommand('underline'); } catch(_){ }
                            if (qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }
                        }); }
                        if (qColor){ qColor.addEventListener('input', function(e){
                            try { document.execCommand('foreColor', false, qColor.value); } catch(_){ }
                            if (qEl){ try { console.log('[ARSH] qColor change (long_text) qEl.innerHTML:', qEl.innerHTML); } catch(_){ } field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }
                        }); }
                        if (req){ req.checked = !!field.required; req.addEventListener('change', function(){
                            field.required = !!req.checked;
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (hideNum){ hideNum.checked = field.numbered === false; hideNum.addEventListener('change', function(){
                            field.numbered = !hideNum.checked;
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (dTg){
                            dTg.checked = !!field.show_description;
                            if (dWrap) {
                                dWrap.style.display = field.show_description ? 'block' : 'none';
                            }
                            dTg.addEventListener('change', function () {
                                field.show_description = !!dTg.checked;
                                if (dWrap) {
                                    dWrap.style.display = field.show_description ? 'block' : 'none';
                                }
                                updateHiddenProps(field);
                                applyPreviewFrom(field);
                                setDirty(true);
                            });
                        }
                        if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){
                            field.description = dTx.value;
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){
                            field.placeholder = help.value;
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (minLen){ minLen.value = field.min_length || 0; minLen.addEventListener('input', function(){
                            field.min_length = parseInt(minLen.value)||0;
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (maxLen){ maxLen.value = field.max_length || 1000; maxLen.addEventListener('input', function(){
                            field.max_length = parseInt(maxLen.value)||1000;
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        }); }
                        if (mediaUp){ 
                            mediaUp.checked = !!field.media_upload; 
                            // show/hide the media controls block
                            if (mediaWrap) mediaWrap.style.display = mediaUp.checked ? 'block' : 'none';
                            mediaUp.addEventListener('change', function(){ 
                                field.media_upload = !!mediaUp.checked; 
                                if (mediaWrap) mediaWrap.style.display = mediaUp.checked ? 'block' : 'none';
                                // when disabling, clear previews
                                if (!mediaUp.checked){ try { if (imgPrev){ imgPrev.src=''; imgPrev.style.display='none'; } } catch(_){ }
                                }
                                updateHiddenProps(field); setDirty(true); 
                            }); 
                        }
                        // Image upload logic
                        if (imgFile && imgPrev && imgErr) {
                            imgFile.addEventListener('change', function(){
                                var file = imgFile.files[0];
                                if (!file) return;
                                if (!['image/jpeg','image/png'].includes(file.type)) { imgErr.textContent = 'فقط JPG یا PNG مجاز است.'; imgErr.style.display = 'block'; return; }
                                if (file.size > 307200) { imgErr.textContent = 'حداکثر حجم 300KB.'; imgErr.style.display = 'block'; return; }
                                imgErr.style.display = 'none';
                                var fd = new FormData(); fd.append('file', file);
                                imgFile.disabled = true;
                                fetch(ARSHLINE_REST + 'upload', { method:'POST', credentials:'same-origin', headers:{ 'X-WP-Nonce': ARSHLINE_NONCE }, body: fd })
                                    .then(async function(r){ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                                    .then(function(obj){ if (obj && obj.url){ field.image_url = obj.url; imgPrev.src = obj.url; imgPrev.style.display = 'block'; updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); } })
                                    .catch(function(){ imgErr.textContent = 'آپلود تصویر ناموفق بود.'; imgErr.style.display = 'block'; })
                                    .finally(function(){ imgFile.disabled = false; imgFile.value = ''; });
                            });
                        }

                        // Video upload removed (image-only policy)
                        applyPreviewFrom(field);
                        var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
                        return;
                    } else if (field.type === 'welcome' || field.type === 'thank_you') {
                        // Message-only editors (heading, message, image). No input in preview.
                        var sWrap = document.querySelector('.ar-settings');
                        var pWrap = document.querySelector('.ar-preview');
                        var isWelcome = (field.type === 'welcome');
                        if (sWrap) {
                            sWrap.innerHTML = `
                                <div class="title" style="margin-bottom:.6rem;">${isWelcome?'تنظیمات پیام خوش‌آمد':'تنظیمات پیام تشکر'}</div>
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                                    <label class="hint">عنوان</label>
                                    <input id="msgHeading" class="ar-input" placeholder="${isWelcome?'خوش آمدید':'با تشکر از شما'}" />
                                </div>
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                                    <label class="hint">متن پیام</label>
                                    <textarea id="msgBody" class="ar-input" rows="3" placeholder="متن پیام"></textarea>
                                </div>
                                <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">
                                    <label class="hint">تصویر (اختیاری) — JPG/PNG تا 300KB</label>
                                    <input id="msgImageFile" type="file" accept="image/jpeg,image/png" style="margin-bottom:4px" />
                                    <div id="msgImageWrap" style="width:200px;height:200px;overflow:hidden;display:${field.image_url?'flex':'none'};align-items:center;justify-content:center;margin-bottom:6px;border-radius:10px;background:transparent">
                                        <img id="msgImagePreview" src="${field.image_url||''}" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:8px;" />
                                    </div>
                                    <span id="msgImageError" class="hint" style="color:#b91c1c;display:none"></span>
                                </div>
                                <div style="margin-top:12px">
                                    <button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button>
                                </div>`;
                        }
                        if (pWrap) {
                            var head = (field.heading && String(field.heading).trim()) || (isWelcome?'پیام خوش‌آمد':'پیام تشکر');
                            var msg = field.message || '';
                            var imgHtml = field.image_url?('<div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:6px;border-radius:10px"><img id="pvMsgImage" src="'+(field.image_url||'')+'" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:6px" /></div>') : '';
                            pWrap.innerHTML = `
                                <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>
                                ${head?('<div class="title" style="margin-bottom:.35rem;">'+head+'</div>'):''}
                                <div id="pvMsgMedia" style="margin-bottom:6px">${imgHtml}</div>
                                ${msg?('<div id="pvMsgBody" class="hint">'+escapeHtml(msg)+'</div>'):('<div id="pvMsgBody" class="hint" style="display:none"></div>')}`;
                        }
                        function updateHiddenProps(p){ var el = document.querySelector('#arCanvas .ar-item'); if (el) el.setAttribute('data-props', JSON.stringify(p)); }
                        function applyMsgPreview(p){
                            try {
                                var t = document.querySelector('.ar-preview .title + .title'); // the heading in preview
                                if (t) t.textContent = (p.heading && String(p.heading).trim()) || (isWelcome?'پیام خوش‌آمد':'پیام تشکر');
                                var body = document.getElementById('pvMsgBody'); if (body){ body.textContent = p.message || ''; body.style.display = (p.message&&String(p.message).trim())?'block':'none'; }
                                var media = document.getElementById('pvMsgMedia'); if (media){ if (p.image_url){ media.innerHTML = '<div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:6px;border-radius:10px"><img src="'+p.image_url+'" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:6px" /></div>'; media.style.display='block'; } else { media.innerHTML=''; media.style.display='none'; } }
                            } catch(_){ }
                        }
                        var hEl = document.getElementById('msgHeading'); if (hEl){ hEl.value = field.heading || (isWelcome?'خوش آمدید':'با تشکر از شما'); hEl.addEventListener('input', function(){ field.heading = hEl.value; updateHiddenProps(field); applyMsgPreview(field); setDirty(true); }); }
                        var mEl = document.getElementById('msgBody'); if (mEl){ mEl.value = field.message || ''; mEl.addEventListener('input', function(){ field.message = mEl.value; updateHiddenProps(field); applyMsgPreview(field); setDirty(true); }); }
                        var fEl = document.getElementById('msgImageFile');
                        var pImg = document.getElementById('msgImagePreview');
                        var pWrapImg = document.getElementById('msgImageWrap');
                        var eImg = document.getElementById('msgImageError');
                        if (fEl && pImg && eImg){
                            fEl.addEventListener('change', function(){
                                var file = fEl.files[0]; if (!file) return;
                                if (!['image/jpeg','image/png'].includes(file.type)) { eImg.textContent = 'فقط JPG یا PNG مجاز است.'; eImg.style.display = 'block'; return; }
                                if (file.size > 307200) { eImg.textContent = 'حداکثر حجم 300KB.'; eImg.style.display = 'block'; return; }
                                eImg.style.display = 'none';
                                var fd = new FormData(); fd.append('file', file);
                                fEl.disabled = true;
                                fetch(ARSHLINE_REST + 'upload', { method:'POST', credentials:'same-origin', headers:{ 'X-WP-Nonce': ARSHLINE_NONCE }, body: fd })
                                    .then(async function(r){ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                                    .then(function(obj){ if (obj && obj.url){ field.image_url = obj.url; pImg.src = obj.url; if (pWrapImg){ pWrapImg.style.display='flex'; } updateHiddenProps(field); applyMsgPreview(field); setDirty(true); } })
                                    .catch(function(){ eImg.textContent = 'آپلود تصویر ناموفق بود.'; eImg.style.display = 'block'; })
                                    .finally(function(){ fEl.disabled = false; fEl.value = ''; });
                            });
                        }
                        applyMsgPreview(field);
                        var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
                        return;
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
                            var useTextarea = (p.type === 'long_text' || p.type === 'longtext' || p.type === 'long-text');
                            var clone;
                            if (useTextarea){
                                clone = document.createElement('textarea');
                                clone.setAttribute('rows','4');
                            } else {
                                clone = document.createElement('input');
                                clone.setAttribute('type', (attrs && attrs.type) ? attrs.type : 'text');
                            }
                            clone.id = 'pvInput';
                            clone.className = 'ar-input';
                            parent.replaceChild(clone, inp);
                            inp = clone;
                        } catch(e){}
                        // Reset attributes
                        inp.value = '';
                        inp.removeAttribute('placeholder');
                        // Set attributes depending on element type
                        if (inp.tagName === 'INPUT'){
                            inp.setAttribute('type', (attrs && attrs.type) ? attrs.type : 'text');
                            if (attrs && attrs.inputmode) inp.setAttribute('inputmode', attrs.inputmode); else inp.removeAttribute('inputmode');
                            if (attrs && attrs.pattern) inp.setAttribute('pattern', attrs.pattern); else inp.removeAttribute('pattern');
                        }
                        var ph = (p.placeholder && p.placeholder.trim()) ? p.placeholder : (fmt==='free_text' ? 'پاسخ را وارد کنید' : suggestPlaceholder(fmt));
                        try { inp.setAttribute('placeholder', ph || ''); } catch(_){ /* textarea may accept placeholder; safe to ignore */ }
                        var qNode = document.getElementById('pvQuestion');
                        if (qNode){
                            var showQ = (p.question && String(p.question).trim());
                            qNode.style.display = showQ ? 'block' : 'none';
                            // Number based on actual position among question fields
                            var qIndex = 1;
                            try {
                                var beforeCount = 0;
                                (fields||[]).forEach(function(ff, i3){ if (i3 < idx){ var pp = ff.props || ff; var t = pp.type || ff.type || 'short_text'; if (t !== 'welcome' && t !== 'thank_you'){ beforeCount += 1; } } });
                                qIndex = beforeCount + 1;
                            } catch(_){ qIndex = (idx+1); }
                            var numPrefix = (p.numbered ? (qIndex + '. ') : '');
                            var sanitized = sanitizeQuestionHtml(showQ || '');
                            qNode.innerHTML = showQ ? (numPrefix + sanitized) : '';
                        }
                        // label removed; question sits above input
                        var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent = p.description || ''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; }
                        var helpEl = document.getElementById('pvHelp'); if (helpEl) { helpEl.textContent=''; helpEl.style.display='none'; }
                        // Attach Jalali datepicker only for date_jalali and only to input elements
                        if (fmt==='date_jalali' && inp.tagName === 'INPUT' && typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.pDatepicker){
                            try { jQuery(inp).pDatepicker({ format:'YYYY/MM/DD', initialValue:false }); } catch(e){}
                        }
                        // Attach validation/mask to editor preview input/textarea
                        try { applyInputMask(inp, p); } catch(e){}
                    }
                    function sync(){
                        if (field.type === 'long_text'){
                            field.label = 'پاسخ طولانی';
                            field.type = 'long_text';
                        } else {
                            field.label = 'پاسخ کوتاه';
                            field.type = 'short_text';
                        }
                        updateHiddenProps(field);
                        applyPreviewFrom(field);
                    }

                    if (sel){ sel.value = field.format || 'free_text'; sel.addEventListener('change', function(){ field.format = sel.value || 'free_text'; var i=document.getElementById('pvInput'); if(i) i.value=''; sync(); setDirty(true); }); }
                    if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; sync(); setDirty(true); }); }
                    if (dTg){ 
                        dTg.checked = !!field.show_description;
                        if (dWrap) {
                            dWrap.style.display = field.show_description ? 'block' : 'none';
                        }
                        dTg.addEventListener('change', function () {
                            field.show_description = !!dTg.checked;
                            if (dWrap) {
                                dWrap.style.display = field.show_description ? 'block' : 'none';
                            }
                            updateHiddenProps(field);
                            applyPreviewFrom(field);
                            setDirty(true);
                        });
                    }
                    if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; sync(); setDirty(true); }); }
                    if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){ field.placeholder = help.value; sync(); setDirty(true); }); }
                    if (qEl){ qEl.innerHTML = sanitizeQuestionHtml(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); }); }
                    if (qBold){ qBold.addEventListener('click', function(e){ e.preventDefault(); document.execCommand('bold'); if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (qItalic){ qItalic.addEventListener('click', function(e){ e.preventDefault(); document.execCommand('italic'); if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (qUnder){ qUnder.addEventListener('click', function(e){ e.preventDefault(); document.execCommand('underline'); if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (qColor){ qColor.addEventListener('input', function(){ try { document.execCommand('foreColor', false, qColor.value); } catch(_){} if(qEl){ try { console.log('[ARSH] qColor change (short_text) qEl.innerHTML:', qEl.innerHTML); } catch(_){ } field.question = sanitizeQuestionHtml(qEl.innerHTML); sync(); setDirty(true); } }); }
                    if (numEl){ numEl.checked = field.numbered !== false; field.numbered = numEl.checked; numEl.addEventListener('change', function(){ field.numbered = !!numEl.checked; sync(); setDirty(true); }); }

                    applyPreviewFrom(field);
                    var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
                });
        }

        function addNewField(formId, fieldType){
            dlog('addNewField:start', { formId: formId, fieldType: fieldType });
            var ft = fieldType || 'short_text';
            fetch(ARSHLINE_REST + 'forms/'+formId, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(data){
                    dlog('addNewField:loaded-existing-fields', (data&&data.fields)?data.fields.length:0);
                    var arr = (data && data.fields) ? data.fields.slice() : [];
                    var hasThank = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; }) !== -1;
                    var insertAt = hasThank ? (arr.length - 1) : arr.length;
                    if (insertAt < 0 || insertAt > arr.length) insertAt = arr.length;
                    // Stash pending creating context to survive any routing/hash re-entry
                    try { window._arPendingEditor = { id: formId, index: insertAt, creating: true, intendedInsert: insertAt, newType: ft, ts: Date.now() }; } catch(_){ }
                    // Open editor in creating mode at intended index; do NOT persist yet
                    renderFormEditor(formId, { index: insertAt, creating: true, intendedInsert: insertAt, newType: ft });
                })
                .catch(function(){ notify('افزودن فیلد ناموفق بود', 'error'); });
        }

        function renderFormBuilder(id){
            console.log('[DEBUG] renderFormBuilder called with id:', id); dlog('renderFormBuilder:start', id);
            if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); renderTab('forms'); return; }
            try { setSidebarClosed(true, false); } catch(_){ }
            document.body.classList.remove('preview-only');
            try { setHash('builder/'+id); } catch(_){ }
            var content = document.getElementById('arshlineDashboardContent');
            console.log('[DEBUG] content element found:', !!content);
            content.innerHTML = '<div class="card glass" style="padding:1rem;max-width:1080px;margin:0 auto;">'
                + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">'
                + '<div class="title">ویرایش فرم #'+id+'</div>'
                + '<div style="display:flex;gap:.5rem;align-items:center;">'
                + '<button id="arBuilderPreview" class="ar-btn ar-btn--outline">پیش‌نمایش</button>'
                + '<button id="arBuilderBack" class="ar-btn ar-btn--muted">بازگشت</button>'
                + '</div>'
                + '</div>'
                + '<style>.ar-tabs .ar-btn.active{background:var(--primary, #eef2ff);border-color:var(--primary, #4338ca);color:#111827}</style>'
                + '<div class="ar-tabs" role="tablist" aria-label="Form Sections" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">'
                + '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arFormFieldsList" data-tab="builder">ساخت</button>'
                + '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arDesignPanel" data-tab="design">طراحی</button>'
                + '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arSettingsPanel" data-tab="settings">تنظیمات</button>'
                + '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arSharePanel" data-tab="share">ارسال</button>'
                + '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arReportsPanel" data-tab="reports">گزارش</button>'
                + '</div>'
                + '<div style="display:flex;gap:1rem;align-items:flex-start;">'
                + '<div id="arFormSide" style="flex:1;">'
                + '<div id="arSectionTitle" class="title" style="margin-bottom:.6rem;">پیش‌نمایش فرم</div>'
                + '<div id="arBulkToolbar" style="display:flex;align-items:center;gap:.8rem;margin-bottom:.6rem;">'
                + '<label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">'
                + '<input id="arSelectAll" type="checkbox" />'
                + '<span class="hint">انتخاب همه</span>'
                + '</label>'
                + '<button id="arBulkDelete" class="ar-btn" disabled>حذف انتخاب‌شده‌ها</button>'
                + '</div>'
                + '<div id="arFormFieldsList" style="display:flex;flex-direction:column;gap:.8rem;"></div>'
                + '<div id="arDesignPanel" style="display:none;">'
                + '  <div class="card" style="padding:.8rem;">'
                + '    <div class="field" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">'
                + '      <span class="hint">رنگ اصلی</span><input id="arDesignPrimary" type="color" />'
                + '      <span class="hint">پس‌زمینه</span><input id="arDesignBg" type="color" />'
                + '      <span class="hint">ظاهر</span><select id="arDesignTheme" class="ar-select"><option value="light">روشن</option><option value="dark">تاریک</option></select>'
                + '      <button id="arSaveDesign" class="ar-btn">ذخیره طراحی</button>'
                + '    </div>'
                + '  </div>'
                + '</div>'
                + '<div id="arSettingsPanel" style="display:none;">'
                + '  <div class="card" style="padding:.8rem;display:flex;flex-direction:column;gap:.8rem;">'
                + '    <div class="title" style="margin-bottom:.2rem;">تنظیمات فرم</div>'
                + '    <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
                + '      <span class="hint">وضعیت فرم</span>'
                + '      <select id="arFormStatus" class="ar-select"><option value="draft">پیش‌نویس</option><option value="published">منتشر شده (فعال)</option><option value="disabled">غیرفعال</option></select>'
                + '      <button id="arSaveStatus" class="ar-btn">ذخیره وضعیت</button>'
                + '      <span class="hint">لینک عمومی فقط در حالت «منتشر شده» فعال است.</span>'
                + '    </div>'
                + '    <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
                + '      <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;"><input type="checkbox" id="arSetHoneypot" /> <span>فعال‌سازی Honeypot (ضدربات ساده)</span></label>'
                + '    </div>'
                + '    <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
                + '      <span class="hint">حداقل زمان تکمیل فرم (ثانیه)</span><input id="arSetMinSec" type="number" min="0" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 5" />'
                + '    </div>'
                + '    <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
                + '      <span class="hint">محدودیت نرخ (ارسال در دقیقه)</span><input id="arSetRatePerMin" type="number" min="0" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 10" />'
                + '      <span class="hint">پنجره زمانی (دقیقه)</span><input id="arSetRateWindow" type="number" min="1" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 5" />'
                + '    </div>'
                + '    <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
                + '      <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;"><input type="checkbox" id="arSetCaptchaEnabled" /> <span>فعالسازی reCAPTCHA</span></label>'
                + '      <span class="hint">Site Key</span><input id="arSetCaptchaSite" type="text" class="ar-input" style="min-width:220px" />'
                + '      <span class="hint">Secret</span><input id="arSetCaptchaSecret" type="password" class="ar-input" style="min-width:220px" />'
                + '      <span class="hint">نسخه</span><select id="arSetCaptchaVersion" class="ar-select"><option value="v2">v2 (checkbox)</option><option value="v3">v3 (score)</option></select>'
                + '    </div>'
                + '    <div style="display:flex;gap:.5rem;">'
                + '      <button id="arSaveSettings" class="ar-btn">ذخیره تنظیمات</button>'
                + '    </div>'
                + '    <div class="hint">توجه: همه این قابلیت‌ها فلگ‌پذیر و ماژولارند و می‌توانید بر اساس هر فرم آن‌ها را فعال/غیرفعال کنید.</div>'
                + '  </div>'
                + '</div>'
                + '<div id="arSharePanel" style="display:none;">'
                + '  <div class="card" style="padding:.8rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">'
                + '    <span class="hint">لینک عمومی فرم:</span><input id="arShareLink" class="ar-input" style="min-width:340px" readonly />'
                + '    <button id="arCopyLink" class="ar-btn">کپی لینک</button>'
                + '    <span id="arShareWarn" class="hint" style="color:#b91c1c;display:none;">برای اشتراک‌گذاری، فرم باید «منتشر شده» باشد.</span>'
                + '  </div>'
                + '</div>'
                + '<div id="arReportsPanel" style="display:none;">'
                + '  <div class="card" style="padding:.8rem;">'
                + '    <div class="title" style="margin-bottom:.6rem;">ارسال‌ها</div>'
                + '    <div id="arSubmissionsList" style="display:flex;flex-direction:column;gap:.5rem"></div>'
                + '  </div>'
                + '</div>'
                + '</div>'
                + '<div id="arToolsSide" style="width:300px;flex:0 0 300px;border-inline-start:1px solid var(--border);padding-inline-start:1rem;">'
                + '<div class="title" style="margin-bottom:.6rem;">ابزارها</div>'
                + '<button id="arAddShortText" class="ar-btn ar-toolbtn" draggable="true">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('short_text')+'"></ion-icon></span>'
                + '  <span>افزودن سؤال با پاسخ کوتاه</span>'
                + '</button>'
                + '<div style="height:.5rem"></div>'
                + '<button id="arAddLongText" class="ar-btn ar-toolbtn" draggable="true">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('long_text')+'"></ion-icon></span>'
                + '  <span>افزودن سؤال با پاسخ طولانی</span>'
                + '</button>'
                + '<div style="height:.5rem"></div>'
                + '<button id="arAddMultipleChoice" class="ar-btn ar-toolbtn" draggable="true">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('multiple_choice')+'"></ion-icon></span>'
                + '  <span>افزودن سؤال چندگزینه‌ای</span>'
                + '</button>'
                + '<div style="height:.5rem"></div>'
                + '<button id="arAddRating" class="ar-btn ar-toolbtn" draggable="true">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('rating')+'"></ion-icon></span>'
                + '  <span>افزودن امتیازدهی</span>'
                + '</button>'
                + '<div style="height:.5rem"></div>'
                + '<button id="arAddDropdown" class="ar-btn ar-toolbtn" draggable="true">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('dropdown')+'"></ion-icon></span>'
                + '  <span>افزودن لیست کشویی</span>'
                + '</button>'
                + '<div style="height:.5rem"></div>'
                + '<button id="arAddWelcome" class="ar-btn ar-toolbtn">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('welcome')+'"></ion-icon></span>'
                + '  <span>افزودن پیام خوش‌آمد</span>'
                + '</button>'
                + '<div style="height:.5rem"></div>'
                + '<button id="arAddThank" class="ar-btn ar-toolbtn">'
                + '  <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('thank_you')+'"></ion-icon></span>'
                + '  <span>افزودن پیام تشکر</span>'
                + '</button>'
                + '</div>'
                + '</div>';
            // Wire builder toolbar actions
            try {
                var bPrev = document.getElementById('arBuilderPreview');
                if (bPrev) bPrev.onclick = function(){ try { window._arBackTo = { view: 'builder', id: id }; } catch(_){ } renderFormPreview(id); };
                var bBack = document.getElementById('arBuilderBack');
                if (bBack) bBack.onclick = function(){ renderTab('forms'); };
            } catch(_){ }
            fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                .then(r=>r.json())
                .then(function(data){
                    dlog('renderFormBuilder:loaded-fields', (data&&data.fields)?data.fields.length:0);
                    var list = document.getElementById('arFormFieldsList');
                    // Setup tabs
                    try {
                        var tabs = Array.from(content.querySelectorAll('.ar-tabs [data-tab]'));
                        function showPanel(which){
                            var title = document.getElementById('arSectionTitle');
                            var panels = {
                                builder: document.getElementById('arFormFieldsList'),
                                design: document.getElementById('arDesignPanel'),
                                settings: document.getElementById('arSettingsPanel'),
                                share: document.getElementById('arSharePanel'),
                                reports: document.getElementById('arReportsPanel'),
                            };
                            Object.keys(panels).forEach(function(k){ panels[k].style.display = (k===which)?'block':'none'; });
                            document.getElementById('arBulkToolbar').style.display = (which==='builder')?'flex':'none';
                            var tools = document.getElementById('arToolsSide'); if (tools) tools.style.display = (which==='builder')?'block':'none';
                            title.textContent = (which==='builder'?'پیش‌نمایش فرم': which==='design'?'طراحی فرم': which==='settings'?'تنظیمات فرم': which==='share'?'ارسال/اشتراک‌گذاری': 'گزارشات فرم');
                            // When entering Share panel, ensure input shows the latest URL
                            if (which === 'share'){
                                try {
                                    var sl = document.getElementById('arShareLink');
                                    if (sl && typeof publicUrl === 'string' && publicUrl){ sl.value = publicUrl; sl.setAttribute('value', publicUrl); }
                                } catch(_){ }
                            }
                        }
                        function setActive(btn){ tabs.forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-selected','false'); }); btn.classList.add('active'); btn.setAttribute('aria-selected','true'); }
                        tabs.forEach(function(btn, idx){
                            btn.setAttribute('tabindex', idx===0? '0' : '-1');
                            btn.addEventListener('click', function(){ setActive(btn); showPanel(btn.getAttribute('data-tab')); });
                            btn.addEventListener('keydown', function(e){
                                var i = tabs.indexOf(btn);
                                if (e.key === 'ArrowRight' || e.key === 'ArrowLeft'){
                                    e.preventDefault();
                                    var ni = (e.key==='ArrowRight') ? (i+1) % tabs.length : (i-1+tabs.length) % tabs.length;
                                    tabs[ni].focus();
                                }
                                if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); setActive(btn); showPanel(btn.getAttribute('data-tab')); }
                            });
                        });
                        // default active
                        var def = content.querySelector('.ar-tabs [data-tab="builder"]'); if (def){ setActive(def); }
                        showPanel('builder');
                        // init design values from meta
                        var meta = data.meta || {};
                        var dPrim = document.getElementById('arDesignPrimary'); if (dPrim) dPrim.value = meta.design_primary || '#1e40af';
                        var dBg = document.getElementById('arDesignBg'); if (dBg) dBg.value = meta.design_bg || '#f5f7fb';
                        var dTheme = document.getElementById('arDesignTheme'); if (dTheme) dTheme.value = meta.design_theme || 'light';
                        // apply to builder preview area
                        try {
                            document.documentElement.style.setProperty('--ar-primary', dPrim.value);
                            var side = document.getElementById('arFormSide');
                            if (side){
                                var isDark = document.body.classList.contains('dark');
                                // If in dark theme, prefer themed surface; otherwise use chosen design bg
                                if (isDark) {
                                    side.style.background = '';
                                } else {
                                    side.style.background = dBg.value || '';
                                }
                            }
                        } catch(_){ }
                        var saveD = document.getElementById('arSaveDesign'); if (saveD){ saveD.onclick = function(){ var payload = { meta: { design_primary: dPrim.value, design_bg: dBg.value, design_theme: dTheme.value } }; fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('طراحی ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره طراحی ناموفق بود', 'error'); }); } }
                        // Re-apply side background on theme toggle (avoid light flash in dark)
                        try {
                            var themeToggle = document.getElementById('arThemeToggle');
                            if (themeToggle){ themeToggle.addEventListener('click', function(){ try {
                                var side = document.getElementById('arFormSide');
                                if (side){
                                    var isDarkNow = document.body.classList.contains('dark');
                                    side.style.background = isDarkNow ? '' : (dBg.value || '');
                                }
                            } catch(_){ } }); }
                        } catch(_){ }
                        // init status select
                        var stSel = document.getElementById('arFormStatus'); if (stSel){ try { stSel.value = String(data.status||'draft'); } catch(_){ } }
                        var saveStatus = document.getElementById('arSaveStatus'); if (saveStatus && stSel){ saveStatus.onclick = function(){
                            var val = String(stSel.value||'draft');
                            fetch(ARSHLINE_REST+'forms/'+id, {
                                method:'PUT', credentials:'same-origin',
                                headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE},
                                body: JSON.stringify({ status: val })
                            })
                            .then(function(r){ if(!r.ok){ if (r.status===401){ if (typeof handle401==='function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                            .then(function(obj){
                                var ns = (obj&&obj.status)||val;
                                notify('وضعیت فرم ذخیره شد: '+ns, 'success');
                                try {
                                    data.status = ns;
                                    // If switched to published, ensure token is generated and refresh Share UI immediately
                                    if (ns === 'published'){
                                        // Step 1: ask server to ensure/generate token (idempotent)
                                        fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                                            .catch(function(){ /* ignore token generation network error here */ })
                                            .finally(function(){
                                                // Step 2: refetch form to get latest token and update UI
                                                fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                                                    .then(function(rr){ return rr.ok ? rr.json() : Promise.reject(new Error('HTTP '+rr.status)); })
                                                    .then(function(d2){ try { data.token = (d2 && d2.token) ? String(d2.token) : (data.token||''); } catch(_){ }
                                                        try { updateShareUI(); } catch(_){ }
                                                    })
                                                    .catch(function(){ try { updateShareUI(); } catch(_){ } });
                                            });
                                    } else {
                                        // Not published: clear link UI immediately
                                        try { updateShareUI(); } catch(_){ }
                                    }
                                } catch(_){ }
                            })
                            .catch(function(){ notify('ذخیره وضعیت ناموفق بود', 'error'); });
                        } }
                        // init settings values from meta
                        try {
                            var hp = document.getElementById('arSetHoneypot'); if (hp) hp.checked = !!meta.anti_spam_honeypot;
                            var ms = document.getElementById('arSetMinSec'); if (ms) ms.value = (typeof meta.min_submit_seconds === 'number') ? String(meta.min_submit_seconds) : '';
                            var rpm = document.getElementById('arSetRatePerMin'); if (rpm) rpm.value = (typeof meta.rate_limit_per_min === 'number') ? String(meta.rate_limit_per_min) : '';
                            var rwin = document.getElementById('arSetRateWindow'); if (rwin) rwin.value = (typeof meta.rate_limit_window_min === 'number') ? String(meta.rate_limit_window_min) : '';
                            var ce = document.getElementById('arSetCaptchaEnabled'); if (ce) ce.checked = !!meta.captcha_enabled;
                            var cs = document.getElementById('arSetCaptchaSite'); if (cs) cs.value = meta.captcha_site_key || '';
                            var ck = document.getElementById('arSetCaptchaSecret'); if (ck) ck.value = meta.captcha_secret_key || '';
                            var cv = document.getElementById('arSetCaptchaVersion'); if (cv) cv.value = meta.captcha_version || 'v2';
                            // UX: disable captcha inputs when not enabled
                            try {
                                function updateCaptchaInputs(){
                                    var enabled = !!(ce && ce.checked);
                                    if (cs) cs.disabled = !enabled;
                                    if (ck) ck.disabled = !enabled;
                                    if (cv) cv.disabled = !enabled;
                                }
                                updateCaptchaInputs();
                                if (ce) ce.addEventListener('change', updateCaptchaInputs);
                            } catch(_){ }
                            var saveS = document.getElementById('arSaveSettings'); if (saveS){ saveS.onclick = function(){
                                var payload = { meta: {
                                    anti_spam_honeypot: !!(hp && hp.checked),
                                    min_submit_seconds: Math.max(0, parseInt((ms && ms.value) ? ms.value : '0') || 0),
                                    rate_limit_per_min: Math.max(0, parseInt((rpm && rpm.value) ? rpm.value : '0') || 0),
                                    rate_limit_window_min: Math.max(1, parseInt((rwin && rwin.value) ? rwin.value : '1') || 1),
                                    captcha_enabled: !!(ce && ce.checked),
                                    captcha_site_key: (cs && cs.value) ? String(cs.value) : '',
                                    captcha_secret_key: (ck && ck.value) ? String(ck.value) : '',
                                    captcha_version: (cv && cv.value) ? String(cv.value) : 'v2'
                                } };
                                fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) })
                                    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                                    .then(function(){ notify('تنظیمات ذخیره شد', 'success'); })
                                    .catch(function(){ notify('ذخیره تنظیمات ناموفق بود', 'error'); });
                            }; }
                        } catch(_){ }
                        // share link (only for published forms)
                        var publicUrl = '';
                        try {
                            var token = (data && data.token) ? String(data.token) : '';
                            if (String(data.status||'') === 'published' && token) {
                                if (window.ARSHLINE_DASHBOARD && ARSHLINE_DASHBOARD.publicTokenBase) {
                                    publicUrl = ARSHLINE_DASHBOARD.publicTokenBase.replace('%TOKEN%', token);
                                } else {
                                    publicUrl = window.location.origin + '/?arshline=' + encodeURIComponent(token);
                                }
                            } else {
                                publicUrl = '';
                            }
                        } catch(_){
                            publicUrl = '';
                        }
                        var shareLink = document.getElementById('arShareLink'); if (shareLink){ shareLink.value = publicUrl; shareLink.setAttribute('value', publicUrl); }
                        function copyText(text){
                            if (navigator.clipboard && navigator.clipboard.writeText){
                                return navigator.clipboard.writeText(text);
                            }
                            return new Promise(function(res, rej){
                                try {
                                    var ta = document.createElement('textarea');
                                    ta.value = text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.opacity='0';
                                    document.body.appendChild(ta); ta.select();
                                    var ok = document.execCommand('copy');
                                    document.body.removeChild(ta);
                                    ok ? res() : rej(new Error('execCommand failed'));
                                } catch(e){ rej(e); }
                            });
                        }
                        var shareWarn = document.getElementById('arShareWarn');
                        function updateShareUI(){ try {
                            var isPub = String(data.status||'') === 'published';
                            var tok = (data && data.token) ? String(data.token) : '';
                            var url = (isPub && tok) ? ((window.ARSHLINE_DASHBOARD && ARSHLINE_DASHBOARD.publicTokenBase) ? ARSHLINE_DASHBOARD.publicTokenBase.replace('%TOKEN%', tok) : (window.location.origin + '/?arshline=' + encodeURIComponent(tok))) : '';
                            publicUrl = url;
                            if (shareLink){ shareLink.value = url; shareLink.setAttribute('value', url); }
                            var copyBtn = document.getElementById('arCopyLink');
                            if (copyBtn){ copyBtn.disabled = !url; }
                            if (shareWarn){ shareWarn.style.display = url ? 'none' : 'inline'; }
                        } catch(_){ } }
                        updateShareUI();
                        var copyBtn = document.getElementById('arCopyLink'); if (copyBtn){ copyBtn.onclick = function(){ if (!publicUrl){ notify('ابتدا فرم را منتشر کنید', 'error'); return; } copyText(publicUrl).then(function(){ notify('کپی شد', 'success'); }).catch(function(){ notify('کپی ناموفق بود', 'error'); }); }; }

                        // Fallback: if token wasn't present initially, refetch once and update link if token appears
                        if (!token && String(data.status||'') === 'published') {
                            setTimeout(function(){
                                fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                                   .then(function(r){ return r.json(); })
                                   .then(function(d2){
                                       try {
                                           var t2 = (d2 && d2.token) ? String(d2.token) : '';
                                           if (t2 && String(d2.status||'') === 'published') {
                                               var url2 = (window.ARSHLINE_DASHBOARD && ARSHLINE_DASHBOARD.publicTokenBase)
                                                   ? ARSHLINE_DASHBOARD.publicTokenBase.replace('%TOKEN%', t2)
                                                   : (window.location.origin + '/?arshline=' + encodeURIComponent(t2));
                                               data.token = t2; data.status = 'published'; publicUrl = url2; updateShareUI();
                                           }
                                       } catch(_){ }
                                   }).catch(function(){ /* ignore */ });
                            }, 250);
                        }
                        // reports fetch
                        var repWrap = document.getElementById('arSubmissionsList'); if (repWrap){ fetch(ARSHLINE_REST+'forms/'+id+'/submissions', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ return r.json(); }).then(function(rows){ repWrap.innerHTML = (rows||[]).map(function(s){ return '<div class="card" style="padding:.5rem;display:flex;justify-content:space-between;">\
                            <span>#'+String(s.id)+' — '+String(s.status||'')+'</span>\
                            <span class="hint">'+String(s.created_at||'')+'</span>\
                        </div>'; }).join('') || '<div class="hint">ارسالی وجود ندارد</div>'; }).catch(function(){ repWrap.innerHTML = '<div class="hint">خطا در بارگذاری گزارشات</div>'; }); }
                    } catch(_){ }
                    // Guard to prevent duplicate add when both click and drag/drop occur nearly simultaneously
                    var lastAddClickTs = 0;
                    var fields = data.fields || [];
                    var qCounter = 0;
                    var visibleMap = [];
                    var vIdx = 0;
                    list.innerHTML = fields.map(function(f, i){
                        var p = f.props || f;
                        var type = p.type || f.type || 'short_text';
                        if (type === 'welcome' || type === 'thank_you'){
                            var ttl = (type==='welcome') ? 'پیام خوش‌آمد' : 'پیام تشکر';
                            var head = (p.heading && String(p.heading).trim()) || '';
                            return '<div class="card" data-oid="'+i+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
                                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">\
                                        <div class="hint" style="display:flex;align-items:center;gap:.4rem;">\
                                          <span class="ar-type-ic"><ion-icon name="'+getTypeIcon(type)+'"></ion-icon></span>\
                                          <span>'+ttl+' — '+head+'</span>\
                                        </div>\
                                        <div style="display:flex;gap:.6rem;align-items:center;">\
                                            <a href="#" class="arEditField" data-id="'+id+'" data-index="'+i+'">ویرایش</a>\
                                            <a href="#" class="arDeleteMsg" title="حذف '+ttl+'" style="color:#d32f2f;">حذف</a>\
                                        </div>\
                                    </div>\
                                </div>';
                        }
                        visibleMap[vIdx] = i;
                        vIdx++;
                        var q = (p.question&&p.question.trim()) || '';
                        var qHtml = q ? sanitizeQuestionHtml(q) : 'پرسش بدون عنوان';
                        var n = '';
                        if (p.numbered !== false) { qCounter += 1; n = qCounter + '. '; }
                        return '<div class="card ar-draggable" draggable="true" data-vid="'+(vIdx-1)+'" data-oid="'+i+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">\
                                <div style="display:flex;align-items:center;gap:.5rem;">\
                                    <span class="ar-dnd-handle" title="جابجایی">≡</span>\
                                    <input type="checkbox" class="arSelectItem" title="انتخاب" />\
                                    <span class="ar-type-ic" title="'+getTypeLabel(type)+'"><ion-icon name="'+getTypeIcon(type)+'"></ion-icon></span>\
                                    <div class="qtext">'+n+qHtml+'</div>\
                                </div>\
                                <div style="display:flex;gap:.6rem;align-items:center;">\
                                    <a href="#" class="arEditField" data-id="'+id+'" data-index="'+i+'">ویرایش</a>\
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
                                            fetch(ARSHLINE_REST + 'forms/'+id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(rr){ return rr.json(); }).then(function(data2){
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
                        list.addEventListener('drop', function(e) {
                            var dt = e.dataTransfer;
                            if (!dt) return;
                            // If a click-based add just happened, ignore this drop to avoid duplicates
                            try {
                                if (Date.now() - lastAddClickTs < 500) {
                                    e.preventDefault();
                                    if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh);
                                    dlog('drop:ignored-due-to-recent-click');
                                    return;
                                }
                            } catch(_){}
                            var t = '';
                            try {
                                t = dt.getData('application/arshline-tool') || dt.getData('text/plain') || '';
                            } catch (_) {
                                t = '';
                            }
                            dlog('drop:tool', t);
                            if (t === 'short_text' || t === 'long_text' || t === 'multiple_choice' || t === 'multiple-choice' || t === 'dropdown' || t === 'rating' || draggingTool) {
                                e.preventDefault();
                                var insertAt = placeholderIndex();
                                dlog('drop:insertAt', insertAt);
                                fetch(ARSHLINE_REST + 'forms/' + id, { credentials: 'same-origin', headers: { 'X-WP-Nonce': ARSHLINE_NONCE } })
                                    .then(function(r){ return r.json(); })
                                    .then(function (data) {
                                        var arr = (data && data.fields) ? data.fields.slice() : [];
                                        dlog('drop:loaded-fields', arr.length);
                                        var newField;
                                        var wantType = (t === 'multiple-choice') ? 'multiple_choice' : t;
                                        newField = (ARSH && ARSH.Tools && ARSH.Tools.getDefaults(wantType))
                                            || (ARSH && ARSH.Tools && ARSH.Tools.getDefaults('short_text'))
                                            || { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
                                        var hasWelcome = arr.findIndex(function (x) { var p = x.props || x; return (p.type || x.type) === 'welcome'; }) !== -1;
                                        var hasThank = arr.findIndex(function (x) { var p = x.props || x; return (p.type || x.type) === 'thank_you'; }) !== -1;
                                        var baseOffset = hasWelcome ? 1 : 0;
                                        var maxPos = arr.length - (hasThank ? 1 : 0);
                                        var realAt = baseOffset + insertAt;
                                        if (realAt < baseOffset) realAt = baseOffset;
                                        if (realAt > maxPos) realAt = maxPos;
                                        dlog('drop:realAt', realAt, 'baseOffset', baseOffset, 'maxPos', maxPos);
                                        arr.splice(realAt, 0, newField);
                                        dlog('drop:payload-size', arr.length);
                                        return fetch(ARSHLINE_REST + 'forms/' + id + '/fields', {
                                            method: 'PUT',
                                            credentials: 'same-origin',
                                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': ARSHLINE_NONCE },
                                            body: JSON.stringify({ fields: arr })
                                        }).then(function (r) {
                                            if (!r.ok) {
                                                if (r.status === 401) {
                                                    if (typeof handle401 === 'function') handle401();
                                                }
                                                throw new Error('HTTP ' + r.status);
                                            }
                                            return { res: r, index: realAt };
                                        });
                                    })
                                    .then(function (obj) {
                                        return obj.res.json().then(function () {
                                            dlog('drop:saved-index', obj.index);
                                            return obj.index;
                                        });
                                    })
                                    .then(function (newIndex) {
                                        notify('فیلد جدید درج شد', 'success');
                                        renderFormEditor(id, { index: newIndex });
                                    })
                                    .catch(function () {
                                        notify('درج فیلد ناموفق بود', 'error');
                                    })
                                    .finally(function () {
                                        if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh);
                                    });
                            }
                        });
                        list.addEventListener('dragleave', function(e){
                            // Remove placeholder when leaving list entirely
                            var rect = list.getBoundingClientRect();
                            if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom){
                                if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh);
                            }
                        });
                }).catch(function(){ list.textContent='خطا در بارگذاری فیلدها'; });
            var addBtn = document.getElementById('arAddShortText');
            if (addBtn){
                addBtn.setAttribute('draggable','true');
                // Remove any existing event listeners by cloning the node
                var newAddBtn = addBtn.cloneNode(true);
                addBtn.parentNode.replaceChild(newAddBtn, addBtn);
                addBtn = newAddBtn;
                addBtn.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id); });
                addBtn.addEventListener('dragstart', function(e){
                    draggingTool = true;
                    try { e.dataTransfer.effectAllowed = 'copy'; } catch(_){ }
                    try { e.dataTransfer.setData('application/arshline-tool','short_text'); } catch(_){ }
                    try { e.dataTransfer.setData('text/plain','short_text'); } catch(_){ }
                    try { var img = document.createElement('div'); img.className = 'ar-dnd-ghost-proxy'; img.textContent = 'سؤال با پاسخ کوتاه'; document.body.appendChild(img); e.dataTransfer.setDragImage(img, 0, 0); setTimeout(function(){ if (img && img.parentNode) img.parentNode.removeChild(img); }, 0); } catch(_){ }
                });
                addBtn.addEventListener('dragend', function(){ draggingTool = false; if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
            }
            var addLongBtn = document.getElementById('arAddLongText');
            if (addLongBtn){
                addLongBtn.setAttribute('draggable','true');
                // Remove any existing event listeners by cloning the node
                var newAddLongBtn = addLongBtn.cloneNode(true);
                addLongBtn.parentNode.replaceChild(newAddLongBtn, addLongBtn);
                addLongBtn = newAddLongBtn;
                addLongBtn.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'long_text'); });
                addLongBtn.addEventListener('dragstart', function(e){
                    draggingTool = true;
                    try { e.dataTransfer.effectAllowed = 'copy'; } catch(_){ }
                    try { e.dataTransfer.setData('application/arshline-tool','long_text'); } catch(_){ }
                    try { e.dataTransfer.setData('text/plain','long_text'); } catch(_){ }
                    try { var img = document.createElement('div'); img.className = 'ar-dnd-ghost-proxy'; img.textContent = 'سؤال با پاسخ طولانی'; document.body.appendChild(img); e.dataTransfer.setDragImage(img, 0, 0); setTimeout(function(){ if (img && img.parentNode) img.parentNode.removeChild(img); }, 0); } catch(_){ }
                });
                addLongBtn.addEventListener('dragend', function(){ draggingTool = false; if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
            }
            var addMcBtn = document.getElementById('arAddMultipleChoice');
            if (addMcBtn){
                addMcBtn.setAttribute('draggable','true');
                // Remove any existing event listeners by cloning the node
                var newAddMcBtn = addMcBtn.cloneNode(true);
                addMcBtn.parentNode.replaceChild(newAddMcBtn, addMcBtn);
                addMcBtn = newAddMcBtn;
                addMcBtn.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'multiple_choice'); });
                addMcBtn.addEventListener('dragstart', function(e){
                    draggingTool = true;
                    try { e.dataTransfer.effectAllowed = 'copy'; } catch(_){ }
                    try { e.dataTransfer.setData('application/arshline-tool','multiple_choice'); } catch(_){ }
                    try { e.dataTransfer.setData('text/plain','multiple_choice'); } catch(_){ }
                    try { var img = document.createElement('div'); img.className = 'ar-dnd-ghost-proxy'; img.textContent = 'سؤال چندگزینه‌ای'; document.body.appendChild(img); e.dataTransfer.setDragImage(img, 0, 0); setTimeout(function(){ if (img && img.parentNode) img.parentNode.removeChild(img); }, 0); } catch(_){ }
                });
                addMcBtn.addEventListener('dragend', function(){ draggingTool = false; if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
            }
            var addDdBtn = document.getElementById('arAddDropdown');
            if (addDdBtn){
                addDdBtn.setAttribute('draggable','true');
                var newAddDdBtn = addDdBtn.cloneNode(true);
                addDdBtn.parentNode.replaceChild(newAddDdBtn, addDdBtn);
                addDdBtn = newAddDdBtn;
                addDdBtn.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'dropdown'); });
                addDdBtn.addEventListener('dragstart', function(e){
                    draggingTool = true;
                    try { e.dataTransfer.effectAllowed = 'copy'; } catch(_){ }
                    try { e.dataTransfer.setData('application/arshline-tool','dropdown'); } catch(_){ }
                    try { e.dataTransfer.setData('text/plain','dropdown'); } catch(_){ }
                    try { var img = document.createElement('div'); img.className = 'ar-dnd-ghost-proxy'; img.textContent = 'لیست کشویی'; document.body.appendChild(img); e.dataTransfer.setDragImage(img, 0, 0); setTimeout(function(){ if (img && img.parentNode) img.parentNode.removeChild(img); }, 0); } catch(_){ }
                });
                addDdBtn.addEventListener('dragend', function(){ draggingTool = false; if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
            }
            var addRatingBtn = document.getElementById('arAddRating');
            if (addRatingBtn){
                addRatingBtn.setAttribute('draggable','true');
                var newAddRatingBtn = addRatingBtn.cloneNode(true);
                addRatingBtn.parentNode.replaceChild(newAddRatingBtn, addRatingBtn);
                addRatingBtn = newAddRatingBtn;
                addRatingBtn.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'rating'); });
                addRatingBtn.addEventListener('dragstart', function(e){
                    draggingTool = true;
                    try { e.dataTransfer.effectAllowed = 'copy'; } catch(_){ }
                    try { e.dataTransfer.setData('application/arshline-tool','rating'); } catch(_){ }
                    try { e.dataTransfer.setData('text/plain','rating'); } catch(_){ }
                    try { var img = document.createElement('div'); img.className = 'ar-dnd-ghost-proxy'; img.textContent = 'امتیازدهی'; document.body.appendChild(img); e.dataTransfer.setDragImage(img, 0, 0); setTimeout(function(){ if (img && img.parentNode) img.parentNode.removeChild(img); }, 0); } catch(_){ }
                });
                addRatingBtn.addEventListener('dragend', function(){ draggingTool = false; if (toolPh && toolPh.parentNode) toolPh.parentNode.removeChild(toolPh); });
            }
            var addWelcomeBtn = document.getElementById('arAddWelcome');
            if (addWelcomeBtn){
                // Remove any existing event listeners by cloning the node
                var newAddWelcomeBtn = addWelcomeBtn.cloneNode(true);
                addWelcomeBtn.parentNode.replaceChild(newAddWelcomeBtn, addWelcomeBtn);
                addWelcomeBtn = newAddWelcomeBtn;
                addWelcomeBtn.addEventListener('click', function(){
                    fetch(ARSHLINE_REST + 'forms/'+id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
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
                // Remove any existing event listeners by cloning the node
                var newAddThankBtn = addThankBtn.cloneNode(true);
                addThankBtn.parentNode.replaceChild(newAddThankBtn, addThankBtn);
                addThankBtn = newAddThankBtn;
                addThankBtn.addEventListener('click', function(){
                    fetch(ARSHLINE_REST + 'forms/'+id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
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
                formSide.addEventListener('drop', function(e){ e.preventDefault(); var t = '';
                    try { t = e.dataTransfer.getData('application/arshline-tool') || e.dataTransfer.getData('text/plain') || ''; } catch(_){ t = ''; }
                    if (t === 'short_text') addNewField(id);
                    else if (t === 'long_text') addNewField(id, 'long_text');
                    else if (t === 'multiple_choice' || t === 'multiple-choice') addNewField(id, 'multiple_choice');
                    else if (t === 'dropdown') addNewField(id, 'dropdown');
                    else if (t === 'rating') addNewField(id, 'rating');
                });
            }
        }

        function renderTab(tab){
            try { localStorage.setItem('arshLastTab', tab); } catch(_){ }
            try { if (['dashboard','forms','reports','users','settings'].includes(tab)) setHash(tab); } catch(_){ }
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
                // Dashboard: Landing modern cards + KPIs + chart (keep original cards)
                content.innerHTML = ''+
                    '<div class="tagline">عرش لاین ، سیستم هوشمند فرم، آزمون، گزارش گیری</div>'+
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
                    </div>'+
                    '<div class="card glass" style="padding:1rem; margin-bottom:1rem;">'+
                      '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:.6rem; align-items:stretch;">'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">همه فرم‌ها</div><div id="arKpiForms" class="title">0</div></div>'+
                          '<ion-icon name="albums-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                        '</div>'+
                                                                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                                                                        '<div><div class="hint">فرم‌های فعال</div><div id="arKpiFormsActive" class="title">0</div></div>'+
                                                    '<ion-icon name="flash-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                                                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                                                                        '<div><div class="hint">فرم‌های غیرفعال</div><div id="arKpiFormsDisabled" class="title">0</div></div>'+
                                                    '<ion-icon name="ban-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">پاسخ‌ها</div><div id="arKpiSubs" class="title">0</div></div>'+
                          '<ion-icon name="clipboard-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                        '</div>'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">کاربران</div><div id="arKpiUsers" class="title">0</div></div>'+
                          '<ion-icon name="people-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                        '</div>'+
                      '</div>'+
                    '</div>'+
                                        '<div class="card glass" style="padding:1rem;">'+
                                            '<div style="display:flex; align-items:center; gap:.6rem; margin-bottom:.6rem;">'+
                        '<span class="title">روند ارسال‌ها</span>'+
                        '<span class="hint">۳۰ روز اخیر</span>'+
                        '<span style="flex:1 1 auto"></span>'+
                        '<select id="arStatsDays" class="ar-select"><option value="30" selected>۳۰ روز</option><option value="60">۶۰ روز</option><option value="90">۹۰ روز</option></select>'+
                      '</div>'+
                                            '<div style="display:flex; flex-wrap:wrap; gap:.8rem; align-items:stretch;">'+
                                                '<div style="width:100%; max-width:360px; height:140px;"><canvas id="arSubsChart"></canvas></div>'+
                                                '<div style="width:160px; flex:0 0 160px; height:140px;"><canvas id="arFormsDonut"></canvas></div>'+
                                            '</div>'+
                    '</div>';

                // Fetch stats and render chart
                (function(){
                    var daysSel = document.getElementById('arStatsDays');
                    var ctx = document.getElementById('arSubsChart');
                    var donutCtx = document.getElementById('arFormsDonut');
                    var chart = null;
                    var donut = null;
                    function palette(){
                        var dark = document.body.classList.contains('dark');
                        return {
                            grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)',
                            text: dark ? '#e5e7eb' : '#374151',
                            line: dark ? '#60a5fa' : '#2563eb',
                            fill: dark ? 'rgba(96,165,250,.15)' : 'rgba(37,99,235,.12)',
                            active: dark ? '#34d399' : '#10b981',
                            disabled: dark ? '#f87171' : '#ef4444'
                        };
                    }
                    function renderChart(labels, data){
                        var pal = palette();
                        if (!ctx) return;
                        try {
                            if (chart){ chart.destroy(); chart = null; }
                        } catch(_){ }
                        if (!window.Chart) { return; }
                        chart = new window.Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'ارسال‌ها',
                                    data: data,
                                    borderColor: pal.line,
                                    backgroundColor: pal.fill,
                                    fill: true,
                                    tension: .3,
                                    pointRadius: 2,
                                    borderWidth: 2,
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                layout: { padding: { top: 6, right: 8, bottom: 6, left: 8 } },
                                scales: {
                                    x: { grid: { color: pal.grid }, ticks: { color: pal.text, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                                    y: { grid: { color: pal.grid }, ticks: { color: pal.text, precision: 0 } }
                                },
                                plugins: {
                                    legend: { labels: { color: pal.text } },
                                    tooltip: { intersect: false, mode: 'index' }
                                }
                            }
                        });
                    }
                    function renderDonut(activeCnt, disabledCnt){
                        if (!donutCtx || !window.Chart) return;
                        var pal = palette();
                        try { if (donut) { donut.destroy(); donut = null; } } catch(_){ }
                        donut = new window.Chart(donutCtx, {
                            type: 'doughnut',
                            data: {
                                labels: ['فعال','غیرفعال'],
                                datasets: [{
                                    data: [activeCnt, disabledCnt],
                                    backgroundColor: [pal.active, pal.disabled],
                                    borderColor: [pal.active, pal.disabled],
                                    borderWidth: 1
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'bottom', labels: { color: pal.text } },
                                    tooltip: { callbacks: { label: function(ctx){ var v = ctx.parsed; var sum = (activeCnt+disabledCnt)||1; var pct = Math.round((v/sum)*100); return ctx.label+': '+v+' ('+pct+'%)'; } } }
                                },
                                cutout: '55%'
                            }
                        });
                    }
                    function applyCounts(c){
                        function set(id, v){ var el = document.getElementById(id); if (el) el.textContent = String(v); }
                        var total = c.forms || 0;
                        var active = c.forms_active || 0;
                        var disabled = Math.max(total - active, 0);
                        set('arKpiForms', total);
                        set('arKpiFormsActive', active);
                        set('arKpiFormsDisabled', disabled);
                        set('arKpiSubs', c.submissions || 0);
                        set('arKpiUsers', c.users || 0);
                        try { renderDonut(active, disabled); } catch(_){ }
                    }
                                        function load(days){
                                                try {
                                                        var url = new URL(ARSHLINE_REST + 'stats');
                                                        url.searchParams.set('days', String(days||30));
                                                        fetch(url.toString(), { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                                                            .then(function(r){ if (!r.ok){ if (r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                                                            .then(function(data){ try { applyCounts(data.counts||{}); var ser = data.series||{}; renderChart(ser.labels||[], ser.submissions_per_day||[]); } catch(e){ console.error(e); } })
                                                            .catch(function(err){ console.error('[ARSH] stats failed', err); /* show zeros already present */ notify('دریافت آمار ناموفق بود', 'error'); });
                                                } catch(e){ console.error(e); }
                                        }
                    if (daysSel){ daysSel.addEventListener('change', function(){ load(parseInt(daysSel.value||'30')); }); }
                    // Initial
                    load(30);
                    // Re-render chart on theme toggle
                    try {
                        var themeToggle = document.getElementById('arThemeToggle');
                        if (themeToggle){ themeToggle.addEventListener('click', function(){ try { 
                            var l = chart && chart.config && chart.config.data && chart.config.data.labels;
                            var v = chart && chart.config && chart.config.data && chart.config.data.datasets && chart.config.data.datasets[0] && chart.config.data.datasets[0].data;
                            if (Array.isArray(l) && Array.isArray(v)) renderChart(l, v);
                            var a = parseInt((document.getElementById('arKpiFormsActive')||{}).textContent||'0')||0;
                            var d = parseInt((document.getElementById('arKpiFormsDisabled')||{}).textContent||'0')||0;
                            renderDonut(a, d);
                        } catch(_){ } }); }
                    } catch(_){ }
                })();
            } else if (tab === 'forms') {
                // header button already rendered globally
                                content.innerHTML = '<div class="card glass card--static" style="padding:1rem;">\
                                        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;">\
                                            <span class="title">فرم‌ها</span>\
                                            <div style="display:flex;gap:.5rem;align-items:center;margin-inline-start:auto;flex-wrap:wrap">\
                                                <input id="arFormSearch" class="ar-input" placeholder="جستجو عنوان/شناسه" style="min-width:220px"/>\
                                                <input id="arFormDateFrom" type="date" class="ar-input" title="از تاریخ"/>\
                                                <input id="arFormDateTo" type="date" class="ar-input" title="تا تاریخ"/>\
                                                <select id="arFormStatusFilter" class="ar-select" title="وضعیت">\
                                                    <option value="">همه وضعیت‌ها</option>\
                                                    <option value="published">فعال</option>\
                                                    <option value="draft">پیش‌نویس</option>\
                                                    <option value="disabled">غیرفعال</option>\
                                                </select>\
                                                <button id="arCreateFormBtn" class="ar-btn ar-btn--soft">+ فرم جدید</button>\
                                            </div>\
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
                                var formSearch = document.getElementById('arFormSearch');
                                var formDF = document.getElementById('arFormDateFrom');
                                var formDT = document.getElementById('arFormDateTo');
                                var formSF = document.getElementById('arFormStatusFilter');
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
                        .then(async function(r){
                            if (!r.ok){
                                if (r.status===401){ if (typeof handle401 === 'function') handle401(); }
                                var t=await r.text(); throw new Error(t||('HTTP '+r.status));
                            }
                            return r.json();
                        })
                        .then(function(obj){
                            if (obj && obj.id){
                                notify('فرم ایجاد شد', 'success');
                                renderFormBuilder(parseInt(obj.id));
                            } else {
                                notify('ایجاد فرم ناموفق بود. لطفاً دسترسی و دیتابیس را بررسی کنید.', 'error');
                                // stay on forms tab and keep inline create visible
                                if (inlineWrap){ inlineWrap.style.display='flex'; var input=document.getElementById('arNewFormTitle'); if (input) input.focus(); }
                            }
                        })
                        .catch(function(e){
                            try { console.error('[ARSH] create_form failed:', e); } catch(_){ }
                            notify('ایجاد فرم ناموفق بود. لطفاً دسترسی را بررسی کنید.', 'error');
                            if (inlineWrap){ inlineWrap.style.display='flex'; var input=document.getElementById('arNewFormTitle'); if (input) input.focus(); }
                        });
                });
                // Allow pressing Enter in title input to submit
                (function(){ try { var inp=document.getElementById('arNewFormTitle'); if (inp){ inp.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); if (submitBtn) submitBtn.click(); } }); } } catch(_){ } })();
                // load forms list with client-side filtering
                fetch(ARSHLINE_REST + 'forms', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(r=>r.json()).then(function(forms){
                    var all = Array.isArray(forms) ? forms : [];
                    var box = document.getElementById('arFormsList'); if (!box) return;
                    function badge(status){
                        var lab = status==='published'?'فعال':(status==='disabled'?'غیرفعال':'پیش‌نویس');
                        var col = status==='published'?'#06b6d4':(status==='disabled'?'#ef4444':'#a3a3a3');
                        return '<span class="hint" style="background:'+col+'20;color:'+col+';padding:.15rem .4rem;border-radius:999px;font-size:12px;">'+lab+'</span>';
                    }
                    function applyFilters(){
                        var term = (formSearch && formSearch.value.trim()) || '';
                        var df = (formDF && formDF.value) || '';
                        var dt = (formDT && formDT.value) || '';
                        var sf = (formSF && formSF.value) || '';
                        var list = all.filter(function(f){
                            var ok = true;
                            if (term){
                                var t = (f.title||'') + ' ' + String(f.id||'');
                                ok = t.indexOf(term) !== -1;
                            }
                            if (ok && df){ ok = String(f.created_at||'').slice(0,10) >= df; }
                            if (ok && dt){ ok = String(f.created_at||'').slice(0,10) <= dt; }
                            if (ok && sf){ ok = String(f.status||'') === sf; }
                            return ok;
                        });
                        var isEmpty = list.length===0;
                        if (isEmpty){ box.innerHTML = '<div class="hint">فرمی مطابق جستجو یافت نشد.</div>'; return; }
                        var html = list.map(function(f){
                            return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px dashed var(--border);">\
                                <div>#'+f.id+' — '+(f.title||'بدون عنوان')+'<div class="hint">'+(f.created_at||'')+'</div></div>\
                                <div style="display:flex;gap:.6rem;">\
                                    '+badge(String(f.status||''))+'\
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
                        box.querySelectorAll('.arViewResults').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; renderFormResults(id); }); });
                        if (ARSHLINE_CAN_MANAGE) {
                            box.querySelectorAll('.arDeleteForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; if (!confirm('حذف فرم #'+id+'؟ این عمل بازگشت‌ناپذیر است.')) return; fetch(ARSHLINE_REST + 'forms/' + id, { method:'DELETE', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(){ notify('فرم حذف شد', 'success'); renderTab('forms'); }).catch(function(){ notify('حذف فرم ناموفق بود', 'error'); }); }); });
                        }
                    }
                    applyFilters();
                    if (formSearch) formSearch.addEventListener('input', function(){ clearTimeout(formSearch._t); formSearch._t = setTimeout(applyFilters, 200); });
                    if (formDF) formDF.addEventListener('change', applyFilters);
                    if (formDT) formDT.addEventListener('change', applyFilters);
                    if (formSF) formSF.addEventListener('change', applyFilters);
                    // If header create was requested before arriving here, open inline create now
                    if (window._arOpenCreateInlineOnce && inlineWrap){ inlineWrap.style.display = 'flex'; var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } window._arOpenCreateInlineOnce = false; }
                }).catch(function(){ var box = document.getElementById('arFormsList'); if (box) box.textContent = 'خطا در بارگذاری فرم‌ها.'; notify('خطا در بارگذاری فرم‌ها', 'error'); });
                        } else if (tab === 'reports') {
                                // Reports: reuse the same stats view for now (KPIs + chart)
                                content.innerHTML = ''+
                                        '<div class="card glass" style="padding:1rem; margin-bottom:1rem;">'+
                                            '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:.6rem; align-items:stretch;">'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">همه فرم‌ها</div><div id="arRptKpiForms" class="title">0</div></div>'+
                                                    '<ion-icon name="albums-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">فرم‌های فعال</div><div id="arRptKpiFormsActive" class="title">0</div></div>'+
                                                    '<ion-icon name="flash-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">فرم‌های غیرفعال</div><div id="arRptKpiFormsDisabled" class="title">0</div></div>'+
                                                    '<ion-icon name="ban-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">پاسخ‌ها</div><div id="arRptKpiSubs" class="title">0</div></div>'+
                                                    '<ion-icon name="clipboard-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                                '<div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
                                                    '<div><div class="hint">کاربران</div><div id="arRptKpiUsers" class="title">0</div></div>'+
                                                    '<ion-icon name="people-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
                                                '</div>'+
                                            '</div>'+
                                        '</div>'+
                                        '<div class="card glass" style="padding:1rem;">'+
                                            '<div style="display:flex; align-items:center; gap:.6rem; margin-bottom:.6rem;">'+
                                                '<span class="title">روند ارسال‌ها</span>'+
                                                '<span class="hint">۳۰ روز اخیر</span>'+
                                                '<span style="flex:1 1 auto"></span>'+
                                                '<select id="arRptStatsDays" class="ar-select"><option value="30" selected>۳۰ روز</option><option value="60">۶۰ روز</option><option value="90">۹۰ روز</option></select>'+
                                            '</div>'+
                                            '<div style="width:100%; max-width:360px; height:140px;"><canvas id="arRptSubsChart"></canvas></div>'+
                                        '</div>';

                                (function(){
                                        var daysSel = document.getElementById('arRptStatsDays');
                                        var ctx = document.getElementById('arRptSubsChart');
                                        var chart = null;
                                        function palette(){
                                                var dark = document.body.classList.contains('dark');
                                                return {
                                                        grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)',
                                                        text: dark ? '#e5e7eb' : '#374151',
                                                        line: dark ? '#34d399' : '#059669',
                                                        fill: dark ? 'rgba(52,211,153,.15)' : 'rgba(5,150,105,.12)'
                                                };
                                        }
                                        function renderChart(labels, data){
                                                var pal = palette();
                                                if (!ctx) return;
                                                try { if (chart){ chart.destroy(); chart = null; } } catch(_){ }
                                                if (!window.Chart) { return; }
                        chart = new window.Chart(ctx, {
                            type: 'line',
                            data: { labels: labels, datasets: [{ label: 'ارسال‌ها', data: data, borderColor: pal.line, backgroundColor: pal.fill, fill: true, tension: .3, pointRadius: 1.5, borderWidth: 1.5 }] },
                            options: { responsive: true, maintainAspectRatio: false, layout: { padding: { top: 6, right: 8, bottom: 6, left: 8 } }, scales: { x: { grid: { color: pal.grid }, ticks: { color: pal.text, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }, y: { grid: { color: pal.grid }, ticks: { color: pal.text, precision: 0 } } }, plugins: { legend: { labels: { color: pal.text } }, tooltip: { intersect: false, mode: 'index' } } }
                        });
                                        }
                                        function applyCounts(c){
                        function set(id, v){ var el = document.getElementById(id); if (el) el.textContent = String(v||0); }
                                                set('arRptKpiForms', c.forms);
                                                set('arRptKpiFormsActive', c.forms_active);
                        set('arRptKpiFormsDisabled', c.forms_disabled);
                                                set('arRptKpiSubs', c.submissions);
                                                set('arRptKpiUsers', c.users);
                                        }
                    function load(days){
                        try {
                            var url = new URL(ARSHLINE_REST + 'stats');
                            url.searchParams.set('days', String(days||30));
                            fetch(url.toString(), { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                            .then(function(r){ if (!r.ok){ if (r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                            .then(function(data){ applyCounts(data.counts||{}); var ser = data.series||{}; renderChart(ser.labels||[], ser.submissions_per_day||[]); })
                            .catch(function(err){ console.error('[ARSH] stats failed', err); /* keep zeros */ notify('دریافت آمار ناموفق بود', 'error'); });
                        } catch(e){ console.error(e); }
                    }
                                        if (daysSel){ daysSel.addEventListener('change', function(){ load(parseInt(daysSel.value||'30')); }); }
                                        load(30);
                                        try { var themeToggle = document.getElementById('arThemeToggle'); if (themeToggle){ themeToggle.addEventListener('click', function(){ try { var l = chart?.config?.data?.labels||[]; var v = chart?.config?.data?.datasets?.[0]?.data||[]; if (l.length) renderChart(l, v); } catch(_){ } }); } } catch(_){ }
                                })();
                        } else if (tab === 'users') {
                content.innerHTML = '<div style="display:flex;flex-direction:column;gap:1.2rem;">\
                    <div class="card glass"><span class="title">کاربران</span><div class="hint">مدیریت نقش‌ها و دسترسی‌ها (Placeholder)</div></div>\
                    <div class="card glass"><span class="title">همکاری تیمی</span><div class="hint">دعوت هم‌تیمی‌ها (Placeholder)</div></div>\
                </div>';
                        } else if (tab === 'settings') {
                                content.innerHTML = '\
                                <div class="card glass" style="padding:1rem;display:flex;flex-direction:column;gap:.8rem;">\
                                    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">\
                                        <button class="ar-btn ar-btn--soft" data-s-tab="security">امنیت</button>\
                                        <button class="ar-btn ar-btn--soft" data-s-tab="ai">هوش مصنوعی</button>\
                                        <button class="ar-btn ar-btn--soft" data-s-tab="users">کاربران</button>\
                                    </div>\
                                    <div id="arGlobalSettingsPanels">\
                                        <div id="arS_Security" class="s-panel">\
                                            <div class="title">تنظیمات امنیتی (سراسری)</div>\
                                            <div class="field" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">\
                                                <label><input type="checkbox" id="gsHoneypot"/> Honeypot</label>\
                                                <span class="hint">حداقل ثانیه</span><input id="gsMinSec" type="number" min="0" step="1" class="ar-input" style="width:100px"/>\
                                                <span class="hint">ارسال/دقیقه</span><input id="gsRatePerMin" type="number" min="0" step="1" class="ar-input" style="width:100px"/>\
                                                <span class="hint">پنجره (دقیقه)</span><input id="gsRateWindow" type="number" min="1" step="1" class="ar-input" style="width:100px"/>\
                                            </div>\
                                            <div class="field" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">\
                                                <label><input type="checkbox" id="gsCaptchaEnabled"/> reCAPTCHA</label>\
                                                <span class="hint">Site Key</span><input id="gsCaptchaSite" class="ar-input" style="min-width:220px"/>\
                                                <span class="hint">Secret</span><input id="gsCaptchaSecret" type="password" class="ar-input" style="min-width:220px"/>\
                                                <span class="hint">نسخه</span><select id="gsCaptchaVersion" class="ar-select"><option value="v2">v2</option><option value="v3">v3</option></select>\
                                            </div>\
                                            <div class="field" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">\
                                                <span class="hint">حداکثر اندازه آپلود (KB)</span><input id="gsUploadKB" type="number" min="50" max="4096" step="10" class="ar-input" style="width:120px"/>\
                                                <label><input type="checkbox" id="gsBlockSvg"/> مسدود کردن SVG</label>\
                                            </div>\
                                            <div><button id="gsSaveSecurity" class="ar-btn">ذخیره امنیت</button></div>\
                                        </div>\
                                        <div id="arS_AI" class="s-panel" style="display:none;">\
                                            <div class="title">تنظیمات هوش مصنوعی (سراسری)</div>\
                                            <label><input type="checkbox" id="gsAiEnabled"/> فعال‌سازی هوش مصنوعی ضداسپم</label>\
                                            <div class="field" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">\
                                                <span class="hint">آستانه امتیاز (0 تا 1)</span><input id="gsAiThreshold" type="number" min="0" max="1" step="0.05" class="ar-input" style="width:120px"/>\
                                            </div>\
                                            <div class="field" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">\
                                                <span class="hint">Base URL</span><input id="gsAiBaseUrl" class="ar-input" placeholder="https://api.example.com" style="min-width:260px"/>\
                                                <span class="hint">API Key</span><input id="gsAiApiKey" type="password" class="ar-input" placeholder="کلید محرمانه" style="min-width:260px"/>\
                                                <span class="hint">Model</span><select id="gsAiModel" class="ar-select"><option value="gpt-4o-mini">gpt-4o-mini</option><option value="gpt-5-mini">gpt-5-mini</option></select>\
                                                <button id="gsAiTest" class="ar-btn ar-btn--soft">تست اتصال</button>\
                                            </div>\
                                            <div class="field" style="display:flex;flex-direction:column;gap:.4rem;">\
                                                <div class="hint">دستور عامل (Agent): مثلا «ایجاد فرم با عنوان فرم تست» یا «حذف فرم 12»</div>\
                                                <textarea id="aiAgentCmd" class="ar-input" style="min-height:72px"></textarea>\
                                                <div><button id="aiAgentRun" class="ar-btn">اجرای دستور</button></div>\
                                                <pre id="aiAgentOut" style="background:rgba(2,6,23,.06); padding:.6rem;border-radius:8px;max-height:180px;overflow:auto;"></pre>\
                                            </div>\
                                            <div><button id="gsSaveAI" class="ar-btn">ذخیره هوش مصنوعی</button></div>\
                                        </div>\
                                        <div id="arS_Users" class="s-panel" style="display:none;">\
                                            <div class="title">کاربران و دسترسی‌ها (Placeholder)</div>\
                                            <div class="hint">به‌زودی: نقش‌ها، دسترسی‌ها، تیم‌ها</div>\
                                        </div>\
                                    </div>\
                                </div>';
                                // tab switch inside settings
                                (function(){ try {
                                        var btns = content.querySelectorAll('[data-s-tab]');
                                        function show(which){
                                                ['Security','AI','Users'].forEach(function(k){ var el = document.getElementById('arS_'+k); if (el) el.style.display = (k.toLowerCase()===which)?'block':'none'; });
                                                btns.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-s-tab')===which); });
                                        }
                                        btns.forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-s-tab')); }); });
                                        show('security');
                                } catch(_){ } })();
                // load settings
                                fetch(ARSHLINE_REST + 'settings', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                                        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                                        .then(function(resp){ var s = resp && resp.settings ? resp.settings : {}; try {
                                                var hp = document.getElementById('gsHoneypot'); if (hp) hp.checked = !!s.anti_spam_honeypot;
                                                var ms = document.getElementById('gsMinSec'); if (ms) ms.value = String(s.min_submit_seconds||0);
                                                var rpm = document.getElementById('gsRatePerMin'); if (rpm) rpm.value = String(s.rate_limit_per_min||0);
                                                var rwin = document.getElementById('gsRateWindow'); if (rwin) rwin.value = String(s.rate_limit_window_min||1);
                                                var ce = document.getElementById('gsCaptchaEnabled'); if (ce) ce.checked = !!s.captcha_enabled;
                                                var cs = document.getElementById('gsCaptchaSite'); if (cs) cs.value = s.captcha_site_key||'';
                                                var ck = document.getElementById('gsCaptchaSecret'); if (ck) ck.value = s.captcha_secret_key||'';
                                                var cv = document.getElementById('gsCaptchaVersion'); if (cv) cv.value = s.captcha_version||'v2';
                                                var uk = document.getElementById('gsUploadKB'); if (uk) uk.value = String(s.upload_max_kb||300);
                                                var bsvg = document.getElementById('gsBlockSvg'); if (bsvg) bsvg.checked = (s.block_svg !== false);
                                                var aiE = document.getElementById('gsAiEnabled'); if (aiE) aiE.checked = !!s.ai_enabled;
                                                var aiT = document.getElementById('gsAiThreshold'); if (aiT) aiT.value = String((typeof s.ai_spam_threshold==='number'?s.ai_spam_threshold:0.5));
                                                // toggle captcha inputs
                                                function updC(){ var en = !!(ce && ce.checked); if (cs) cs.disabled=!en; if (ck) ck.disabled=!en; if (cv) cv.disabled=!en; }
                                                updC(); if (ce) ce.addEventListener('change', updC);
                    } catch(_){ } })
                    .then(function(){
                        // Load AI connection config (base_url, api_key is not auto-filled for safety)
                        return fetch(ARSHLINE_REST + 'ai/config', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                        .then(function(resp){ try {
                            var c = resp && resp.config ? resp.config : {};
                            var bu = document.getElementById('gsAiBaseUrl'); if (bu) bu.value = c.base_url || '';
                            var mo = document.getElementById('gsAiModel'); if (mo) mo.value = c.model || 'gpt-4o-mini';
                            // Do not prefill API key for safety; user can paste to update
                        } catch(_){ } });
                    })
                                        .catch(function(){ notify('خطا در بارگذاری تنظیمات سراسری', 'error'); });
                                function putSettings(part){
                                        return fetch(ARSHLINE_REST + 'settings', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ settings: part }) })
                                                .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
                                }
                function putAiConfig(cfg){
                    return fetch(ARSHLINE_REST + 'ai/config', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ config: cfg }) })
                        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
                }
                                var saveSec = document.getElementById('gsSaveSecurity'); if (saveSec){ saveSec.addEventListener('click', function(){
                                        var payload = {
                                                anti_spam_honeypot: !!(document.getElementById('gsHoneypot')?.checked),
                                                min_submit_seconds: Math.max(0, parseInt(document.getElementById('gsMinSec')?.value||'0')||0),
                                                rate_limit_per_min: Math.max(0, parseInt(document.getElementById('gsRatePerMin')?.value||'0')||0),
                                                rate_limit_window_min: Math.max(1, parseInt(document.getElementById('gsRateWindow')?.value||'1')||1),
                                                captcha_enabled: !!(document.getElementById('gsCaptchaEnabled')?.checked),
                                                captcha_site_key: String(document.getElementById('gsCaptchaSite')?.value||''),
                                                captcha_secret_key: String(document.getElementById('gsCaptchaSecret')?.value||''),
                                                captcha_version: String(document.getElementById('gsCaptchaVersion')?.value||'v2'),
                                                upload_max_kb: Math.max(50, Math.min(4096, parseInt(document.getElementById('gsUploadKB')?.value||'300')||300)),
                                                block_svg: !!(document.getElementById('gsBlockSvg')?.checked)
                                        };
                                        putSettings(payload).then(function(){ notify('تنظیمات امنیت ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره تنظیمات امنیت ناموفق بود', 'error'); });
                                }); }
                var saveAI = document.getElementById('gsSaveAI'); if (saveAI){ saveAI.addEventListener('click', function(){
                    var ai_enabled = !!(document.getElementById('gsAiEnabled')?.checked);
                    var payload = {
                        ai_enabled: ai_enabled,
                        ai_spam_threshold: Math.max(0, Math.min(1, parseFloat(document.getElementById('gsAiThreshold')?.value||'0.5')||0.5))
                    };
                    var cfg = {
                        enabled: ai_enabled,
                        base_url: String(document.getElementById('gsAiBaseUrl')?.value||''),
                        api_key: String(document.getElementById('gsAiApiKey')?.value||''),
                        model: String(document.getElementById('gsAiModel')?.value||'')
                    };
                    putSettings(payload)
                        .then(function(){ return putAiConfig(cfg); })
                        .then(function(){ notify('تنظیمات هوش مصنوعی ذخیره شد', 'success'); })
                        .catch(function(){ notify('ذخیره تنظیمات هوش مصنوعی ناموفق بود', 'error'); });
                }); }
                var testBtn = document.getElementById('gsAiTest'); if (testBtn){ testBtn.addEventListener('click', function(){
                    fetch(ARSHLINE_REST + 'ai/test', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                        .then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return { ok:r.ok, status:r.status, body:j }; }); })
                        .then(function(res){ if (res.body && res.body.ok){ notify('اتصال موفق بود (HTTP '+(res.body.status||res.status)+')', 'success'); } else { notify('اتصال ناموفق بود', 'error'); } })
                        .catch(function(){ notify('خطا در تست اتصال', 'error'); });
                }); }
                var runBtn = document.getElementById('aiAgentRun'); if (runBtn){ runBtn.addEventListener('click', function(){
                    var cmdEl = document.getElementById('aiAgentCmd'); var outEl = document.getElementById('aiAgentOut');
                    var cmd = (cmdEl && cmdEl.value) ? String(cmdEl.value) : '';
                    if (!cmd){ notify('دستور خالی است', 'warn'); return; }
                    fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ command: cmd }) })
                        .then(async function(r){ var txt = ''; try { txt = await r.clone().text(); } catch(_){ } try { var j = txt ? JSON.parse(txt) : await r.json(); outEl.textContent = JSON.stringify(j, null, 2); } catch(_){ outEl.textContent = txt||('HTTP '+r.status); } if (!r.ok) notify('اجرا ناموفق بود', 'error'); else notify('انجام شد', 'success'); })
                        .catch(function(){ notify('خطا در اجرای دستور', 'error'); });
                }); }
            }
            // re-trigger entrance animation
            content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');
        }

        // bind clicks + keyboard
        links.forEach(function(a){
            a.addEventListener('click', function(e){ e.preventDefault(); renderTab(a.getAttribute('data-tab')); });
            a.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); renderTab(a.getAttribute('data-tab')); }});
        });

        // Expose navigators globally for external modules (editors) to call after save
        try {
            window.renderFormBuilder = renderFormBuilder;
            window.renderFormEditor = renderFormEditor;
            window.renderFormPreview = renderFormPreview;
        } catch(_){}

        // default tab
        if (location.hash){ routeFromHash(); }
        else {
            var initial = (function(){ try { return localStorage.getItem('arshLastTab') || ''; } catch(_){ return ''; } })() || 'dashboard';
            if (![ 'dashboard','forms','reports','users','settings' ].includes(initial)) initial = 'dashboard';
            setHash(initial);
            renderTab(initial);
        }
        // Floating AI Terminal wiring
        try {
            var fab = document.getElementById('arAiFab');
            var panel = document.getElementById('arAiPanel');
            var closeBtn = document.getElementById('arAiClose');
            var runBtn = document.getElementById('arAiRun');
            var clearBtn = document.getElementById('arAiClear');
            var cmdEl = document.getElementById('arAiCmd');
            var outEl = document.getElementById('arAiOut');
            function setOpen(b){ if (!panel) return; panel.classList.toggle('open', !!b); panel.setAttribute('aria-hidden', b? 'false':'true'); if (b && cmdEl) cmdEl.focus(); try { sessionStorage.setItem('arAiOpen', b?'1':'0'); } catch(_){ } }
            if (fab) fab.addEventListener('click', function(){ var isOpen = panel && panel.classList.contains('open'); setOpen(!isOpen); });
            if (closeBtn) closeBtn.addEventListener('click', function(){ setOpen(false); });
            if (clearBtn) clearBtn.addEventListener('click', function(){ if(outEl) outEl.textContent=''; if(cmdEl) cmdEl.value=''; try { sessionStorage.removeItem('arAiHist'); } catch(_){ } });
            function appendOut(o){ if (!outEl) return; try { var old = outEl.textContent || ''; var s = (typeof o==='string')? o : JSON.stringify(o, null, 2); outEl.textContent = (old? (old+"\n\n") : '') + s; outEl.scrollTop = outEl.scrollHeight; } catch(_){ }
            }
            function saveHist(cmd, res){ try { var h = []; try { h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); } catch(_){ h = []; } h.push({ t: Date.now(), cmd: String(cmd||''), res: res }); h = h.slice(-20); sessionStorage.setItem('arAiHist', JSON.stringify(h)); } catch(_){ } }
            function loadHist(){ try { var h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); if (Array.isArray(h) && h.length && outEl){ outEl.textContent = h.map(function(x){ return '> '+(x.cmd||'')+'\n'+JSON.stringify(x.res||{}, null, 2); }).join('\n\n'); } } catch(_){ } }
            loadHist();
            function handleAgentAction(j){
                try {
                    if (!j) return;
                    // Confirmation flow: render a quick inline yes/no prompt in output
                    if (j.action === 'confirm' && j.confirm_action){
                        var msg = String(j.message||'تایید می‌کنید؟');
                        appendOut({ confirm: msg, params: j.confirm_action });
                        // Render ephemeral buttons
                        try {
                            var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
                            var yes = document.createElement('button'); yes.className='ar-btn'; yes.textContent='تایید';
                            var no = document.createElement('button'); no.className='ar-btn ar-btn--outline'; no.textContent='انصراف'; no.style.marginInlineStart='.5rem';
                            yes.addEventListener('click', async function(){
                                try {
                                    var r2 = await fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ confirm_action: j.confirm_action }) });
                                    var txt2 = ''; try { txt2 = await r2.clone().text(); } catch(_){ }
                                    var j2 = null; try { j2 = txt2 ? JSON.parse(txt2) : await r2.json(); } catch(_){ }
                                    appendOut(j2 || (txt2 || ('HTTP '+r2.status)));
                                    if (r2.ok && j2 && j2.ok !== false){ handleAgentAction(j2); notify('تایید شد', 'success'); }
                                    else { notify('انجام نشد', 'error'); }
                                } catch(e){ appendOut(String(e)); notify('خطا', 'error'); }
                            });
                            no.addEventListener('click', function(){ notify('لغو شد', 'warn'); });
                            wrap.appendChild(yes); wrap.appendChild(no);
                            if (outEl) outEl.appendChild(wrap);
                        } catch(_){ }
                        return;
                    }
                    // Clarify with options
                    if (j.action === 'clarify' && j.kind === 'options' && Array.isArray(j.options)){
                        appendOut({ clarify: String(j.message||'مبهم است'), options: j.options });
                        try {
                            var wrap2 = document.createElement('div'); wrap2.style.marginTop='.5rem';
                            (j.options||[]).forEach(function(opt){
                                var b = document.createElement('button'); b.className='ar-btn'; b.textContent=String(opt.label||opt.value);
                                b.style.marginInlineEnd='.5rem';
                                b.addEventListener('click', async function(){
                                    // If clarify_action provided, send a confirm prompt next
                                    if (j.clarify_action){
                                        const ca = j.clarify_action; const pa = {}; pa[j.param_key] = opt.value;
                                        // Ask backend to execute direct by sending confirm_action to maintain consistency
                                        var r3 = await fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ confirm_action: { action: ca.action, params: pa } }) });
                                        var t3 = ''; try { t3 = await r3.clone().text(); } catch(_){ }
                                        var j3 = null; try { j3 = t3 ? JSON.parse(t3) : await r3.json(); } catch(_){ }
                                        appendOut(j3 || (t3 || ('HTTP '+r3.status)));
                                        if (r3.ok && j3 && j3.ok !== false){ handleAgentAction(j3); notify('انجام شد', 'success'); } else { notify('انجام نشد', 'error'); }
                                    }
                                });
                                wrap2.appendChild(b);
                            });
                            if (outEl) outEl.appendChild(wrap2);
                        } catch(_){ }
                        return;
                    }
                    // Help capabilities
                    if (j.action === 'help' && j.capabilities){ appendOut({ capabilities: j.capabilities }); return; }
                    // UI actions
                    if (j.action === 'ui' && j.target === 'toggle_theme'){
                        try { var t = document.getElementById('arThemeToggle'); if (t) t.click(); } catch(_){ }
                        return;
                    }
                    if (j.action === 'open_tab' && j.tab){ renderTab(String(j.tab)); }
                    else if (j.action === 'open_builder' && j.id){ try { setHash('builder/'+parseInt(j.id)); } catch(_){ } renderTab('forms'); }
                    else if ((j.action === 'download' || j.action === 'export') && j.url){ try { window.open(String(j.url), '_blank'); } catch(_){ } }
                    else if (j.url && !j.action){ try { window.open(String(j.url), '_blank'); } catch(_){ } }
                } catch(_){ }
            }
            async function runAgent(cmdOverride){
                var cmd = (typeof cmdOverride === 'string' && cmdOverride.trim()) ? cmdOverride.trim() : ((cmdEl && cmdEl.value) ? String(cmdEl.value) : '');
                if (!cmd){ notify('دستور خالی است', 'warn'); return; }
                appendOut('> '+cmd);
                try {
                    var r = await fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ command: cmd }) });
                    var txt = ''; try { txt = await r.clone().text(); } catch(_){ }
                    var j = null; try { j = txt ? JSON.parse(txt) : await r.json(); } catch(_){ }
                    appendOut(j || (txt || ('HTTP '+r.status)));
                    saveHist(cmd, j || txt || {});
                    if (r.ok && j && j.ok !== false){ handleAgentAction(j); notify('انجام شد', 'success'); }
                    else { notify('اجرا ناموفق بود', 'error'); }
                } catch(e){ appendOut(String(e)); notify('خطا در اجرای دستور', 'error'); }
            }
            if (runBtn) runBtn.addEventListener('click', runAgent);
            // Undo icon button: list recent actions and provide one-click undo
            try {
                var undoBtn = document.getElementById('arAiUndoBtn');
                if (undoBtn) undoBtn.addEventListener('click', async function(){
                    try {
                        appendOut('> فهرست بازگردانی‌های اخیر');
                        var r = await fetch(ARSHLINE_REST + 'ai/audit?limit=10', { method:'GET', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} });
                        var t = ''; try { t = await r.clone().text(); } catch(_){ }
                        var j = null; try { j = t ? JSON.parse(t) : await r.json(); } catch(_){ }
                        appendOut(j || (t || ('HTTP '+r.status)));
                        if (j && j.items && Array.isArray(j.items) && j.items.length){
                            var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
                            j.items.forEach(function(it){
                                if (it.undone) return;
                                var lab = document.createElement('div'); lab.textContent = (it.action+': '+it.scope+' #'+(it.target_id||'')); lab.style.display='inline-block'; lab.style.marginInlineEnd='.5rem';
                                var btn = document.createElement('button'); btn.className='ar-btn ar-btn--soft'; btn.textContent='بازگردانی'; btn.style.marginBottom='.25rem';
                                btn.addEventListener('click', async function(){
                                    try {
                                        btn.disabled=true; btn.textContent='...';
                                        var r2 = await fetch(ARSHLINE_REST + 'ai/undo', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: it.undo_token }) });
                                        var t2 = ''; try { t2 = await r2.clone().text(); } catch(_){ }
                                        var j2 = null; try { j2 = t2 ? JSON.parse(t2) : await r2.json(); } catch(_){ }
                                        appendOut(j2 || (t2 || ('HTTP '+r2.status)));
                                        if (r2.ok && j2 && j2.ok){ notify('بازگردانی انجام شد', 'success'); try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } }
                                        else { notify('ناموفق بود', 'error'); }
                                    } catch(e){ appendOut(String(e)); } finally { btn.disabled=false; btn.textContent='بازگردانی'; }
                                });
                                var row = document.createElement('div'); row.appendChild(lab); row.appendChild(btn); wrap.appendChild(row);
                            });
                            outEl.appendChild(wrap);
                        }
                    } catch(e){ appendOut(String(e)); }
                });
            } catch(_){ }
            if (cmdEl) cmdEl.addEventListener('keydown', function(e){ if (e.key==='Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); runAgent(); }});
            // restore open state
            try { if ((sessionStorage.getItem('arAiOpen')||'')==='1') setOpen(true); } catch(_){ }
        } catch(_){ }
        // Allow other modules to trigger AI terminal
        try { window.ARSH_AI = { open: function(){ var p=document.getElementById('arAiPanel'); if(!p) return; p.classList.add('open'); p.setAttribute('aria-hidden','false'); }, run: function(cmd){ var t=document.getElementById('arAiCmd'); if(t){ t.value=String(cmd||''); } var b=document.getElementById('arAiRun'); if(b){ b.click(); } } }; } catch(_){ }
    });
    }
    </script>
    </head>
    <body>
    <div class="arshline-dashboard-root">
        <aside class="arshline-sidebar">
            <div class="logo"><span class="label">عرشلاین</span></div>
            <button id="arSidebarToggle" class="toggle" aria-expanded="true" title="باز/بسته کردن منو"><span class="chev">❮</span></button>
            <nav>
                <a href="#dashboard" data-tab="dashboard"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-10.5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="label">داشبورد</span></a>
                <a href="#forms" data-tab="forms"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="1.6"/><path d="M7 8h10M7 12h10M7 16h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">فرم‌ها</span></a>
                <a href="#reports" data-tab="reports"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 20h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><rect x="6" y="10" width="3" height="6" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="7" width="3" height="9" stroke="currentColor" stroke-width="1.6"/><rect x="16" y="12" width="3" height="4" stroke="currentColor" stroke-width="1.6"/></svg></span><span class="label">گزارشات</span></a>
                <a href="#users" data-tab="users"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">کاربران</span></a>
                <a href="#settings" data-tab="settings"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z" stroke="currentColor" stroke-width="1.6"/><path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1a2 2 0 0 1-2.8 2.8l-.1-.1a1 1 0 0 0-1.1-.2 7.8 7.8 0 0 1-2.6.9 1 1 0 0 0-.8.9V21a2 2 0 0 1-4 0v-.1a1 1 0 0 0-.8-.9 7.8 7.8 0 0 1-2.6-.9 1 1 0 0 0-1.1.2l-.1.1a2 2 0 0 1-2.8-2.8l.1-.1a1 1 0 0 0 .2-1.1A7.8 7.8 0 0 1 3 12a7.8 7.8 0 0 1 .9-2.6 1 1 0 0 0-.2-1.1l-.1-.1a2 2 0 0 1 2.8-2.8l.1.1a1 1 0 0 0 1.1.2 7.8 7.8 0 0 1 2.6-.9 1 1 0 0 0 .8-.9V3a2 2 0 0 1 4 0v.1a1 1 0 0 0 .8.9 7.8 7.8 0 0 1 2.6.9 1 1 0 0 0 1.1-.2l.1-.1a2 2 0 0 1 2.8 2.8l-.1.1a1 1 0 0 0-.2 1.1 7.8 7.8 0 0 1 .9 2.6Z" stroke="currentColor" stroke-width="1.6"/></svg></span><span class="label">تنظیمات</span></a>
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
    <!-- Floating AI Terminal -->
    <button id="arAiFab" class="ar-ai-fab" title="ترمینال هوشیار">هوشیار ▷</button>
    <div id="arAiPanel" class="ar-ai-panel" aria-hidden="true">
        <div class="ar-ai-header">
            <div class="title">ترمینال هوشیار</div>
            <button id="arAiUndoBtn" class="ar-ai-undo" aria-label="بازگردانی اخیر" title="بازگردانی اخیر" style="margin-inline-end:.4rem; font-size:14px; line-height:1; padding:.3rem .5rem;">↶</button>
            <button id="arAiClose" class="ar-ai-close" aria-label="بستن">✕</button>
        </div>
        <div class="ar-ai-body">
            <textarea id="arAiCmd" placeholder="مثلاً: ایجاد فرم با عنوان فرم تست"></textarea>
            <div style="display:flex; gap:.5rem; align-items:center; justify-content:flex-start;">
                <button id="arAiRun" class="ar-btn">اجرا</button>
                <button id="arAiClear" class="ar-btn ar-btn--outline">پاک‌سازی</button>
            </div>
            <pre id="arAiOut"></pre>
        </div>
    </div>
<script src="<?php echo esc_url( plugins_url('assets/js/ui/ai-terminal.js', dirname(__DIR__, 2).'/arshline.php') ); ?>?ver=<?php echo esc_attr($version); ?>"></script>
<!-- Ionicons for modern solid cards -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<!-- Chart.js for charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- Persian datepicker (optional) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.css" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.js"></script>
<!-- UI modules wired: notify/auth/input-masks -->

<!-- Ionicons for modern cards -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</body>
</html>

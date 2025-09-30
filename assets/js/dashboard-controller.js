/* =========================================================================
   FILE: assets/js/dashboard-controller.js
   Purpose: Orchestrates dashboard behavior: sidebar routing/tabs, theme &
            sidebar toggles, results list, form builder, and preview flows.
   Dependencies: runtime-config, tools-registry, external tool modules,
                 assets/js/dashboard.js
   Exports: attaches renderFormBuilder/renderFormEditor/renderFormPreview to window
   ========================================================================= */
(function(){
  // Signal as early as possible that external controller is present,
  // so inline block (in template) can skip binding duplicate handlers.
  try { window.ARSH_CTRL_EXTERNAL = true; } catch(_){ }
  // Tabs: render content per menu item
  document.addEventListener('DOMContentLoaded', function() {
    var content = document.getElementById('arshlineDashboardContent');
  var links = document.querySelectorAll('.arshline-sidebar nav a[data-tab]');
  var allNavLinks = document.querySelectorAll('.arshline-sidebar nav a');
    var sidebar = document.querySelector('.arshline-sidebar');
    var sidebarToggle = document.getElementById('arSidebarToggle');

    // Debug helpers
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
              try { window._arConsoleLog.push({ level: m, args: Array.from(arguments), ts: Date.now() }); } catch(_){ }
              try { orig.apply(console, arguments); } catch(_){ }
            };
          });
          var ov = document.createElement('div');
          ov.id = 'arsh-console-capture';
          ov.style.cssText = 'position:fixed;left:8px;bottom:8px;max-width:420px;max-height:220px;overflow:auto;background:rgba(0,0,0,.8);color:#fff;padding:8px;border-radius:8px;font-size:12px;z-index:99999;';
          ov.innerHTML = '<div style="font-weight:700;margin-bottom:6px">ARSH Console Capture (click to hide)</div>';
          ov.addEventListener('click', function(){ try { ov.style.display = 'none'; } catch(_){ } });
          document.body.appendChild(ov);
          window._arLogDump = function(){
            try {
              if (!window._arConsoleLog) return;
              ov.innerHTML = '<div style="font-weight:700;margin-bottom:6px">ARSH Console Capture (click to hide)</div>' + window._arConsoleLog.slice(-200).map(function(r){
                return '<div style="margin-bottom:4px;color:' + (r.level === 'error' ? '#ff8080' : (r.level === 'warn' ? '#ffd080' : '#d0d0ff')) + '">[' + new Date(r.ts).toLocaleTimeString() + '] <b>' + r.level + '</b> ' + r.args.map(function(a){
                  try { return (typeof a === 'string') ? a : JSON.stringify(a); } catch(_){ return String(a); }
                }).join(' ') + '</div>';
              }).join('');
            } catch(_){ }
          };
        })();
      }
    } catch(_){ }

  function clog(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.log.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
  function dlog(){ try { clog.apply(console, arguments); } catch(_){ } }
    function cwarn(){ if (AR_DEBUG && typeof console !== 'undefined') { try { console.warn.apply(console, ['[ARSH]'].concat([].slice.call(arguments))); } catch(_){ } } }
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
    function escapeAttr(s){ try { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); } catch(_){ return String(s||''); } }
    function escapeHtml(s){ try { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); } catch(_){ return String(s||''); } }
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
              if (tag === 'FONT'){
                try {
                  var span = document.createElement('span');
                  var col = child.getAttribute('color') || (child.style && child.style.color) || '';
                  if (col) span.setAttribute('style', 'color:'+col);
                  while(child.firstChild){ span.appendChild(child.firstChild); }
                  node.replaceChild(span, child);
                  child = span; tag = 'SPAN';
                } catch(_){ }
              }
              if (!allowed[tag]){
                while(child.firstChild){ node.insertBefore(child.firstChild, child); }
                node.removeChild(child);
              } else {
                var savedColor = '';
                try {
                  if (tag === 'SPAN') {
                    savedColor = '';
                    try { if (child.style && child.style.color) savedColor = child.style.color; } catch(_){ }
                    try { if (!savedColor && child.getAttribute && child.getAttribute('color')) savedColor = child.getAttribute('color'); } catch(_){ }
                    if (savedColor) { savedColor = String(savedColor).trim(); }
                  }
                } catch(_){ savedColor = ''; }
                for (var i = child.attributes.length - 1; i >= 0; i--) { try { child.removeAttribute(child.attributes[i].name); } catch(_){} }
                if (tag === 'SPAN'){
                  if (savedColor){ try { child.setAttribute('style', 'color:' + savedColor); } catch(_) { } }
                  else { try { child.removeAttribute('style'); } catch(_){ } }
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
    function setHash(h){ var target = '#' + h; if (location.hash !== target){ _arNavSilence++; location.hash = h; setTimeout(function(){ _arNavSilence = Math.max(0, _arNavSilence - 1); }, 0); } }
    function arRenderTab(tab){
      try {
        if (typeof window.renderTab === 'function') return window.renderTab(tab);
        if (typeof renderTab === 'function') return renderTab(tab);
      } catch(_){ }
      cwarn('renderTab not available; route="'+tab+'"');
    }
    function routeFromHash(){
      var raw = (location.hash||'').replace('#','').trim();
      try { if (raw) localStorage.setItem('arshLastRoute', raw); } catch(_){ }
      if (!raw){
        try {
          var last = localStorage.getItem('arshLastRoute') || localStorage.getItem('arshLastTab') || 'dashboard';
          if (last && last !== 'dashboard'){ setHash(last); return; }
          arRenderTab(last); return;
        } catch(_){ arRenderTab('dashboard'); return; }
      }
    var parts = raw.split('/');
    // Normalize first segment (drop query) for matches like "messaging?tab=sms"
    var seg0 = (parts[0]||'').split('?')[0];
      if (parts[0]==='submissions'){ arRenderTab('forms'); return; }
      // Nested route: users/ug => گروه‌های کاربری
  var seg1 = (parts[1]||'').split('?')[0];
  if (parts[0]==='users' && seg1==='ug'){ renderUsersUG(); return; }
  if (['dashboard','forms','reports','users','settings','messaging','analytics'].includes(seg0)){ arRenderTab(seg0); return; }
  if (seg0==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { dlog('route:builder', id); renderFormBuilder(id); return; } }
  if (seg0==='editor' && parts[1]){ var id2 = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); dlog('route:editor', { id:id2, idx:idx, parts:parts }); if (id2) { renderFormEditor(id2, { index: isNaN(idx)?0:idx }); return; } }
  if (seg0==='preview' && parts[1]){ var id3 = parseInt(parts[1]||'0'); if (id3) { renderFormPreview(id3); return; } }
  if (seg0==='results' && parts[1]){ var id4 = parseInt(parts[1]||'0'); if (id4) { renderFormResults(id4); return; } }
      arRenderTab('dashboard');
    }
    var AR_FULL = !!(window && window.ARSH_CTRL_FULL);
    // In FULL mode, if a centralized router exists, defer hash routing to it to avoid double-handling
    if (AR_FULL && !window.ARSH_ROUTER){
      window.addEventListener('hashchange', function(){ if (_arNavSilence>0) return; routeFromHash(); });
    }
    // Sidebar navigation: drive routing on click
    try {
      links.forEach(function(a){
        a.addEventListener('click', function(e){
          try { e.preventDefault(); } catch(_){ }
          var tab = a.getAttribute('data-tab');
          if (!tab) return;
          if (window.ARSH_ROUTER){
            try { window.ARSH_ROUTER.setHash(tab); } catch(_){ }
            try { window.ARSH_ROUTER.arRenderTab(tab); } catch(_){ }
          } else {
            try { setHash(tab); } catch(_){ }
            try { renderTab(tab); } catch(_){ }
          }
        });
      });
    } catch(_){ }

    // theme switch (sun/moon)
    var themeToggle = document.getElementById('arThemeToggle');
    try { if (localStorage.getItem('arshDark') === '1') document.body.classList.add('dark'); } catch(_){ }
  if (themeToggle && AR_FULL){
      function applyAria(){ themeToggle.setAttribute('aria-checked', document.body.classList.contains('dark') ? 'true' : 'false'); }
      applyAria();
      var toggle = function(){ document.body.classList.toggle('dark'); applyAria(); try { localStorage.setItem('arshDark', document.body.classList.contains('dark') ? '1' : '0'); } catch(_){ } };
      themeToggle.addEventListener('click', toggle);
      themeToggle.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); toggle(); }});
    }
  if (sidebarToggle && AR_FULL){
      var tgl = function(){ var isClosed = sidebar && sidebar.classList.contains('closed'); setSidebarClosed(!isClosed, true); };
      sidebarToggle.addEventListener('click', tgl);
      sidebarToggle.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); tgl(); }});
    }

    function setActive(tab){
      var hash = (location.hash||'').replace('#','');
      var parts = hash.split('/');
      var seg0 = (parts[0]||'').split('?')[0];
      var seg1 = (parts[1]||'').split('?')[0];
      // Evaluate over ALL sidebar links so pure-hash items (e.g., #users/ug) can be highlighted
      allNavLinks.forEach(function(a){
        var dt = a.getAttribute('data-tab');
        var href = a.getAttribute('href') || '';
        var isUG = (seg0==='users' && seg1==='ug');
        var isActive = (dt ? (dt === tab) : false);
        // Do NOT activate parent Users when on users/ug; only activate Users on plain users routes
        if (!isActive && dt === 'users' && !isUG && tab && tab.indexOf('users') === 0) isActive = true;
        // Explicitly highlight the UG anchor when on users/ug
        if (!isActive && isUG && href.indexOf('#users/ug') === 0) isActive = true;
        if (isActive){
          a.classList.add('active');
          try { a.setAttribute('aria-current', 'page'); } catch(_){ }
        } else {
          a.classList.remove('active');
          try { a.removeAttribute('aria-current'); } catch(_){ }
        }
      });
    }
    function getTypeIcon(type){ switch(type){ case 'short_text': return 'create-outline'; case 'long_text': return 'newspaper-outline'; case 'multiple_choice': case 'multiple-choice': return 'list-outline'; case 'dropdown': return 'chevron-down-outline'; case 'welcome': return 'happy-outline'; case 'thank_you': return 'checkmark-done-outline'; default: return 'help-circle-outline'; } }
    function getTypeLabel(type){ switch(type){ case 'short_text': return 'پاسخ کوتاه'; case 'long_text': return 'پاسخ طولانی'; case 'multiple_choice': case 'multiple-choice': return 'چندگزینه‌ای'; case 'dropdown': return 'لیست کشویی'; case 'welcome': return 'پیام خوش‌آمد'; case 'thank_you': return 'پیام تشکر'; default: return 'نامشخص'; } }
    function card(title, subtitle, icon){ var ic = icon ? ('<span style="font-size:22px;margin-inline-start:.4rem;opacity:.85">'+icon+'</span>') : ''; return '<div class="card glass" style="display:flex;align-items:center;gap:.6rem;">'+ic+'<div><div class="title">'+title+'</div><div class="hint">'+(subtitle||'')+'</div></div></div>'; }

      // Lazy loader for گروه‌های کاربری inside custom panel
      function renderUsersUG(){
        // Treat UG as an independent menu: highlight UG link, not the parent Users
        setActive('users/ug');
        var content = document.getElementById('arshlineDashboardContent');
        if (!content) return;
        var qs = new URLSearchParams((location.hash.split('?')[1]||''));
        var tab = qs.get('tab') || 'groups';
        // Header actions: back to users
        var headerActions = document.getElementById('arHeaderActions');
        if (headerActions) headerActions.innerHTML = '<a id="arBackUsers" class="ar-btn ar-btn--outline" href="#users">بازگشت به کاربران</a>';
        content.innerHTML = ''+
          '<div class="card glass" style="padding:1rem;">'+
            '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.6rem">'+
              '<span class="title">گروه‌های کاربری</span>'+
              '<span style="flex:1 1 auto"></span>'+
              '<a class="ar-btn'+(tab==='groups'?'':' ar-btn--outline')+'" href="#users/ug?tab=groups">گروه‌ها</a>'+
              '<a class="ar-btn'+(tab==='members'?'':' ar-btn--outline')+'" href="#users/ug?tab=members">اعضا</a>'+
              '<a class="ar-btn'+(tab==='mapping'?'':' ar-btn--outline')+'" href="#users/ug?tab=mapping">اتصال فرم‌ها</a>'+
              '<a class="ar-btn'+(tab==='custom_fields'?'':' ar-btn--outline')+'" href="#users/ug?tab=custom_fields">فیلدهای سفارشی</a>'+
            '</div>'+
            '<div id="arUGMount" style="min-height:120px;display:flex;align-items:center;justify-content:center">'+
              '<div style="display:flex;align-items:center;gap:.6rem;opacity:.8">'+
                '<span class="ar-spinner" aria-hidden="true" style="width:18px;height:18px;border:2px solid var(--border, #e5e7eb);border-top-color:var(--accent, #06b6d4);border-radius:50%;display:inline-block;animation:arSpin .8s linear infinite"></span>'+
                '<span>در حال بارگذاری...</span>'+
              '</div>'+
            '</div>'+
          '</div>';
        // If bundle already loaded, render current tab immediately
        try { if (typeof window.ARSH_UG_render === 'function') { window.ARSH_UG_render(tab); } } catch(_){ }
        try {
          // Inject keyframes once
          if (!document.getElementById('arSpinnerKeyframes')){
            var st = document.createElement('style');
            st.id = 'arSpinnerKeyframes';
            st.textContent = '@keyframes arSpin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
            document.head.appendChild(st);
          }
        } catch(_){ }
        // Load admin bundle on-demand
        (function ensureBundle(){
          if (window.__ARSH_UG_READY__) { return; }
          var id = 'arsh-ug-bundle'; if (document.getElementById(id)) return;
          var s = document.createElement('script'); s.id = id; s.async = true;
          var fromCss = (document.querySelector('link[href*="/assets/css/dashboard.css"]')||{}).href||'';
          var baseUrl = '';
          if (window.ARSHLINE_PLUGIN_URL) baseUrl = window.ARSHLINE_PLUGIN_URL;
          else if (fromCss) {
            try {
              var cssNoQ = fromCss.split('?')[0];
              baseUrl = cssNoQ.replace('/assets/css/dashboard.css','');
            } catch(_){ baseUrl = ''; }
          }
          if (baseUrl) { try { baseUrl = baseUrl.split('?')[0].replace(/\/$/, ''); } catch(_){ } }
          s.src = baseUrl ? (baseUrl + '/assets/js/admin/user-groups.js') : '/wp-content/plugins/arshline/assets/js/admin/user-groups.js';
          s.onload = function(){
            try {
              var qs = new URLSearchParams((location.hash.split('?')[1]||''));
              var tab = qs.get('tab') || 'groups';
              if (typeof window.ARSH_UG_render === 'function') { window.ARSH_UG_render(tab); }
            } catch(_){ }
          };
          document.body.appendChild(s);
        })();
      }

    // Centralized tab renderer (ported from template)
    function renderTab(tab){
      try {
  if (['dashboard','forms','reports','users','settings','messaging','analytics'].includes(tab)){
          var _h = (location.hash||'').replace('#','');
          var _seg0 = (_h.split('/')[0]||'').split('?')[0];
          if (_seg0 !== tab) setHash(tab); // don't clobber query (e.g., messaging?tab=...)
        }
      } catch(_){ }
      // Persist after possibly updating hash
      try {
        var fullHash = (location.hash||'').replace('#','').trim();
        if (!fullHash) fullHash = tab;
        localStorage.setItem('arshLastTab', tab);
        localStorage.setItem('arshLastRoute', fullHash);
      } catch(_){ }
      try { setSidebarClosed(false, false); } catch(_){ }
      setActive(tab);
      var content = document.getElementById('arshlineDashboardContent');
      var headerActions = document.getElementById('arHeaderActions');
      if (headerActions) { headerActions.innerHTML = '<button id="arHeaderCreateForm" class="ar-btn">+ فرم جدید</button>'; }
      var globalHeaderCreateBtn = document.getElementById('arHeaderCreateForm');
      if (globalHeaderCreateBtn) {
        globalHeaderCreateBtn.addEventListener('click', function(){ window._arOpenCreateInlineOnce = true; renderTab('forms'); });
      }
      if (tab === 'dashboard'){
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
        (function(){
          var daysSel = document.getElementById('arStatsDays');
          var ctx = document.getElementById('arSubsChart');
          var donutCtx = document.getElementById('arFormsDonut');
          var chart = null, donut = null;
          function palette(){ var dark = document.body.classList.contains('dark'); return { grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)', text: dark ? '#e5e7eb' : '#374151', line: dark ? '#60a5fa' : '#2563eb', fill: dark ? 'rgba(96,165,250,.15)' : 'rgba(37,99,235,.12)', active: dark ? '#34d399' : '#10b981', disabled: dark ? '#f87171' : '#ef4444' }; }
          function renderChart(labels, data){ var pal = palette(); if (!ctx) return; try { if (chart){ chart.destroy(); chart=null; } } catch(_){ } if (!window.Chart) return; chart = new window.Chart(ctx, { type:'line', data:{ labels:labels, datasets:[{ label:'ارسال‌ها', data:data, borderColor:pal.line, backgroundColor:pal.fill, fill:true, tension:.3, pointRadius:2, borderWidth:2 }] }, options:{ responsive:true, maintainAspectRatio:false, layout:{ padding:{ top:6, right:8, bottom:6, left:8 } }, scales:{ x:{ grid:{ color:pal.grid }, ticks:{ color:pal.text, maxRotation:0, autoSkip:true, maxTicksLimit:10 } }, y:{ grid:{ color:pal.grid }, ticks:{ color:pal.text, precision:0 } } }, plugins:{ legend:{ labels:{ color:pal.text } }, tooltip:{ intersect:false, mode:'index' } } } }); }
          function renderDonut(activeCnt, disabledCnt){ if (!donutCtx || !window.Chart) return; var pal = palette(); try{ if(donut){ donut.destroy(); donut=null; } } catch(_){ } donut = new window.Chart(donutCtx, { type:'doughnut', data:{ labels:['فعال','غیرفعال'], datasets:[{ data:[activeCnt, disabledCnt], backgroundColor:[pal.active,pal.disabled], borderColor:[pal.active,pal.disabled], borderWidth:1 }] }, options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ color:pal.text } }, tooltip:{ callbacks:{ label:function(ctx){ var v=ctx.parsed; var sum=(activeCnt+disabledCnt)||1; var pct=Math.round((v/sum)*100); return ctx.label+': '+v+' ('+pct+'%)'; } } } }, cutout:'55%' } }); }
          function applyCounts(c){ function set(id,v){ var el=document.getElementById(id); if(el) el.textContent=String(v); } var total=c.forms||0; var active=c.forms_active||0; var disabled=Math.max(total-active,0); set('arKpiForms', total); set('arKpiFormsActive', active); set('arKpiFormsDisabled', disabled); set('arKpiSubs', c.submissions||0); set('arKpiUsers', c.users||0); try{ renderDonut(active,disabled); } catch(_){ } }
          function load(days){ try { var url = new URL(ARSHLINE_REST + 'stats'); url.searchParams.set('days', String(days||30)); fetch(url.toString(), { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(data){ try { applyCounts(data.counts||{}); var ser=data.series||{}; renderChart(ser.labels||[], ser.submissions_per_day||[]); } catch(e){ console.error(e); } }).catch(function(err){ console.error('[ARSH] stats failed', err); notify('دریافت آمار ناموفق بود', 'error'); }); } catch(e){ console.error(e); } }
          if (daysSel){ daysSel.addEventListener('change', function(){ load(parseInt(daysSel.value||'30')); }); }
          load(30);
          try { var themeToggle = document.getElementById('arThemeToggle'); if (themeToggle){ themeToggle.addEventListener('click', function(){ try { var l = chart && chart.config && chart.config.data && chart.config.data.labels; var v = chart && chart.config && chart.config.data && chart.config.data.datasets && chart.config.data.datasets[0] && chart.config.data.datasets[0].data; if (Array.isArray(l) && Array.isArray(v)) renderChart(l, v); var a = parseInt((document.getElementById('arKpiFormsActive')||{}).textContent||'0')||0; var d = parseInt((document.getElementById('arKpiFormsDisabled')||{}).textContent||'0')||0; renderDonut(a, d); } catch(_){ } }); } } catch(_){ }
        })();
      } else if (tab === 'analytics'){
        setActive('analytics');
        content.innerHTML = ''+
        '<div class="card glass" style="padding:1rem;">'+
          '<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem">'+
            '<span class="title">تحلیل‌ها (هوشنگ)</span>'+
            '<span class="hint">انتخاب چند فرم و پرسش؛ خلاصهٔ فارسی</span>'+
          '</div>'+
          '<div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-bottom:.8rem">'+
            '<select id="arAnaForms" class="ar-select" multiple size="6" style="min-width:260px"></select>'+
            '<input id="arAnaQ" class="ar-input" placeholder="سوال شما…" style="min-width:280px;flex:1 1 320px" />'+
            '<input id="arAnaChunk" class="ar-input" type="number" value="800" min="50" max="2000" style="width:120px" title="سایز قطعه" />'+
            /* ساختاری (سریع) — حذف شد: حالت فقط LLM */
            '<span class="hint" style="opacity:.8">حالت: مدل زبانی (LLM)</span>'+
            '<label style="display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap"><input id="arAnaFormatTable" type="checkbox"/> ارسال جدول</label>'+ 
            '<label style="display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap"><input id="arAnaDebug" type="checkbox"/> دیباگ</label>'+ 
            '<input id="arAnaMaxTok" class="ar-input" type="number" value="800" min="16" max="2048" style="width:120px" title="حداکثر توکن خروجی" />'+ 
            '<button id="arAnaRun" class="ar-btn">تحلیل</button>'+ 
            '<button id="arAnaExport" class="ar-btn ar-btn--outline" title="خروجی گفتگو">خروجی</button>'+
            '<button id="arAnaSpeak" class="ar-btn ar-btn--soft" title="خواندن خلاصه">گویا</button>'+
            '<button id="arAnaClear" class="ar-btn ar-btn--outline" title="پاک‌کردن گفتگو">پاک‌کردن گفتگو</button>'+
          '</div>'+
          '<div id="arAnaOut" class="card glass" style="padding:1rem;white-space:pre-wrap;line-height:1.7"></div>'+
        '</div>';
        (function initAna(){
          var sel = document.getElementById('arAnaForms');
          var out = document.getElementById('arAnaOut');
          var run = document.getElementById('arAnaRun');
          var q = document.getElementById('arAnaQ');
          var speak = document.getElementById('arAnaSpeak');
          var chunk = document.getElementById('arAnaChunk');
          var structuredChk = null; // حذف حالت ساختاری؛ فقط LLM
          var formatTableChk = document.getElementById('arAnaFormatTable');
          var debugChk = document.getElementById('arAnaDebug');
          var maxTok = document.getElementById('arAnaMaxTok');
          var clearBtn = document.getElementById('arAnaClear');
          var exportBtn = document.getElementById('arAnaExport');
          // simple in-memory chat history for this session
          var chatHistory = [];
          // persistent session id to store chat server-side
          var chatSessionId = 0; try { chatSessionId = parseInt(localStorage.getItem('arshAnaSessionId')||'0')||0; } catch(_){ }
          var ANA_DEBUG = false; try { ANA_DEBUG = (localStorage.getItem('arshAnaDebug') === '1') || (localStorage.getItem('arshDebug') === '1'); } catch(_){ }
          try { if (debugChk){ debugChk.checked = !!ANA_DEBUG; debugChk.addEventListener('change', function(){ ANA_DEBUG = !!debugChk.checked; try { localStorage.setItem('arshAnaDebug', ANA_DEBUG ? '1' : '0'); } catch(_){ } }); } } catch(_){ }
          try { if (maxTok){ var saved = parseInt(localStorage.getItem('arshAnaMaxTok')||'800')||800; maxTok.value = String(saved); maxTok.addEventListener('change', function(){ var v = parseInt(maxTok.value||'0')||800; if (v<16) v=16; if (v>2048) v=2048; maxTok.value = String(v); try { localStorage.setItem('arshAnaMaxTok', String(v)); } catch(_){ } }); } } catch(_){ }
          // Load minimal config and then list forms
          fetch(ARSHLINE_REST + 'analytics/config', { headers:{ 'X-WP-Nonce': ARSHLINE_NONCE } }).then(function(r){ return r.json(); }).then(function(cfg){ if(!cfg.enabled){ notify('هوشنگ غیرفعال است (AI)؛ ابتدا کلید/مبنا را تنظیم کنید.', 'warn'); } }).catch(function(){ });
          fetch(ARSHLINE_REST + 'forms', { headers:{ 'X-WP-Nonce': ARSHLINE_NONCE } }).then(function(r){ if(!r.ok){ if(r.status===401 && typeof handle401==='function') handle401(); throw new Error('HTTP '+r.status); } return r.json(); }).then(function(data){ try { var arr = Array.isArray(data) ? data : (Array.isArray(data.items)?data.items:[]); sel.innerHTML = arr.map(function(f){ return '<option value="'+String(f.id)+'">#'+String(f.id)+' — '+(f.title||'بی‌عنوان')+'</option>'; }).join(''); } catch(_){ } }).catch(function(err){ console.error(err); notify('دریافت لیست فرم‌ها ناموفق بود','error'); });
          function getSelected(){ return Array.from(sel.options).filter(function(o){ return o.selected; }).map(function(o){ return parseInt(o.value||'0'); }).filter(function(v){ return v>0; }); }
          // helpers for chat-style rendering
          function _appendChatMessage(role, text){
            if (!out) return null;
            var wrap = document.createElement('div');
            wrap.className = 'ar-chat-msg '+role;
            wrap.style.display = 'flex';
            wrap.style.gap = '.6rem';
            wrap.style.marginBottom = '.7rem';
            wrap.style.alignItems = 'flex-start';
            var bubble = document.createElement('div');
            bubble.className = 'ar-chat-bubble';
            bubble.style.whiteSpace = 'pre-wrap';
            bubble.style.lineHeight = '1.7';
            bubble.style.padding = '.6rem .8rem';
            bubble.style.borderRadius = '12px';
            bubble.style.maxWidth = '100%';
            if (role === 'user'){
              bubble.style.background = 'var(--ar-c-bg-soft, rgba(0,0,0,.04))';
              wrap.style.justifyContent = 'flex-end';
            } else {
              bubble.style.background = 'var(--ar-c-surface, rgba(0,0,0,.03))';
              wrap.style.justifyContent = 'flex-start';
            }
            bubble.textContent = text || '';
            wrap.appendChild(bubble);
            out.appendChild(wrap);
            try { out.scrollTop = out.scrollHeight; } catch(_){ }
            return { wrap: wrap, bubble: bubble };
          }
          // Debug helpers
          function _truncate(s, n){ try { s = String(s||''); return s.length>n ? (s.slice(0,n) + '\n…[truncated]') : s; } catch(_){ return String(s||''); } }
          function _pretty(o){ try { return JSON.stringify(o, null, 2); } catch(_){ try { return String(o); } catch(__){ return ''; } } }
          function doRun(){ try {
            var ids = getSelected(); if(ids.length===0){ notify('حداقل یک فرم انتخاب کنید','warn'); return; }
            var body = { form_ids: ids, question: (q.value||'').trim(), chunk_size: parseInt(chunk.value||'800')||800 };
            if (chatSessionId>0) body.session_id = chatSessionId;
            // pass conversation history (limit last 8 turns for brevity)
            if (chatHistory.length){ body.history = chatHistory.slice(-16); }
            // حالت فقط LLM
            body.mode = 'llm';
            // preferred format
            if (formatTableChk && formatTableChk.checked){ body.format = 'table'; }
            if (ANA_DEBUG) body.debug = true;
            try { if (maxTok){ var mt = parseInt(maxTok.value||'0')||0; if (mt>0) body.max_tokens = Math.max(16, Math.min(2048, mt)); } } catch(_){ }
            if (ANA_DEBUG) {
              try {
                console.groupCollapsed('[ARSH][ANA] request');
                console.log('Selected forms:', ids);
                console.log('Question:', body.question);
                console.log('Options:', { chunk_size: body.chunk_size, format: body.format||'json', mode: body.mode, session_id: body.session_id||0, max_tokens: body.max_tokens||undefined, history_len: (body.history||[]).length, debug: !!body.debug });
                console.groupEnd();
              } catch(_){ }
            }
            var old = run.textContent; run.disabled=true; run.textContent='در حال پردازش…';
            // render user message and a pending assistant bubble
            var userMsg = (q.value||'').trim();
            if (userMsg){ _appendChatMessage('user', userMsg); }
            var pending = _appendChatMessage('assistant', 'در حال پردازش…');
            var t0 = (typeof performance!=='undefined'&&performance.now)?performance.now():Date.now();
            fetch(ARSHLINE_REST + 'analytics/analyze', { method:'POST', headers:{ 'X-WP-Nonce': ARSHLINE_NONCE, 'Content-Type':'application/json' }, body: JSON.stringify(body) })
              .then(async function(r){
                var txt='';
                try{ txt=await r.clone().text(); }catch(_){ }
                if (ANA_DEBUG){ try { var t1=(typeof performance!=='undefined'&&performance.now)?performance.now():Date.now(); console.groupCollapsed('[ARSH][ANA] http'); console.log('status:', r.status); console.log('roundtrip_ms:', Math.round(t1 - t0)); console.log('raw_len:', (txt||'').length); console.groupEnd(); } catch(_){ } }
                if(!r.ok){ var msg='HTTP '+r.status; try{ var j=txt?JSON.parse(txt):await r.json(); msg = (j && (j.error||j.message)) || msg; }catch(_){ } throw new Error(msg); }
                try{ return txt?JSON.parse(txt):await r.json(); }catch(e){ throw e; }
              })
              .then(function(j){
                try {
                  if (j.error){ if (pending && pending.bubble) pending.bubble.textContent = 'خطا: '+j.error; return; }
                  if (pending && pending.bubble) pending.bubble.textContent = j.summary || '';
                  if (j.session_id){ chatSessionId = parseInt(j.session_id)||0; try{ localStorage.setItem('arshAnaSessionId', String(chatSessionId||0)); }catch(_){ } }
                  if (ANA_DEBUG) { try { console.info('[ARSH][ANA] response', j); } catch(_){ } }
                  // append to history for better multi-turn chat
                  var assistantMsg = String(j.summary||'');
                  if (userMsg){ chatHistory.push({ role:'user', content:userMsg }); }
                  if (assistantMsg){ chatHistory.push({ role:'assistant', content:assistantMsg }); }
                  // Optionally show a brief usage footer
                  if (Array.isArray(j.usage) && j.usage.length){
                    var tot = j.usage.reduce(function(a,b){ var u=b.usage||{}; return { input:(a.input||0)+(u.input||0), output:(a.output||0)+(u.output||0), total:(a.total||0)+(u.total||0) }; }, {});
                    var m = document.createElement('div'); m.className='hint'; m.style.marginTop = '.6rem'; m.textContent = 'مصرف توکن — ورودی: '+(tot.input||0)+' ؛ خروجی: '+(tot.output||0)+' ؛ کل: '+(tot.total||0);
                    if (pending && pending.wrap) pending.wrap.appendChild(m); else out.appendChild(m);
                  }
                  // Render and log debug details if available
                  if (j.debug && pending && pending.wrap && ANA_DEBUG){
                    try {
                      // Console rich logs per chunk
                      try {
                        console.groupCollapsed('[ARSH][ANA] debug');
                        (Array.isArray(j.debug)? j.debug : [j.debug]).forEach(function(dbg, idx){
                          console.groupCollapsed('chunk #'+(idx+1)+' — form_id:'+(dbg && dbg.form_id) + ' rows:'+(dbg && dbg.rows));
                          if (dbg && dbg.request_preview) console.log('request_preview:', dbg.request_preview);
                          if (dbg && dbg.model) console.log('model:', dbg.model);
                          if (dbg && dbg.usage) console.log('usage:', dbg.usage);
                          if (dbg && dbg.http_status!=null) console.log('http_status:', dbg.http_status);
                          if (dbg && dbg.raw) console.log('raw:\n'+_truncate(dbg.raw, 2000));
                          console.groupEnd();
                        });
                        console.groupEnd();
                      } catch(_){ }
                      // UI collapsible block with first debug entry
                      var first = Array.isArray(j.debug) ? j.debug[0] : j.debug;
                      var det = document.createElement('details'); det.style.marginTop = '.4rem';
                      var sum = document.createElement('summary'); sum.textContent = 'جزئیات دیباگ'; det.appendChild(sum);
                      var pre = document.createElement('pre'); pre.style.whiteSpace = 'pre-wrap'; pre.style.direction = 'ltr'; pre.style.maxHeight='300px'; pre.style.overflow='auto';
                      pre.textContent = _truncate(_pretty(first), 3000);
                      det.appendChild(pre);
                      pending.wrap.appendChild(det);
                    } catch(_){ }
                  }
                } catch(e){ out.textContent = 'خطا در نمایش خروجی'; }
              })
              .catch(function(err){ console.error(err); if (pending && pending.bubble) pending.bubble.textContent = 'درخواست ناموفق بود: '+String(err && err.message || err); else if (out) out.textContent = 'درخواست ناموفق بود: '+String(err && err.message || err); })
              .finally(function(){ run.disabled=false; run.textContent=old; });
          } catch(e){ console.error(e); notify('خطا در اجرای تحلیل','error'); }
          }
          if (run) run.addEventListener('click', doRun);
          if (clearBtn) clearBtn.addEventListener('click', function(){ chatHistory = []; chatSessionId = 0; try{ localStorage.removeItem('arshAnaSessionId'); }catch(_){ } if (out){ out.innerHTML=''; } notify('گفتگو پاک شد','info'); });
          if (exportBtn) exportBtn.addEventListener('click', function(){ try { if (!chatSessionId){ notify('ابتدا یک پیام بفرستید تا گفتگویی ایجاد شود','warn'); return; } var url = new URL(ARSHLINE_REST + 'analytics/sessions/'+chatSessionId+'/export'); url.searchParams.set('format', (formatTableChk && formatTableChk.checked)?'csv':'json'); window.open(url.toString(), '_blank'); } catch(e){ console.error(e); notify('امکان خروجی وجود ندارد','error'); } });
          function speakFa(text){
            try {
              if (!('speechSynthesis' in window)) { notify('مرورگر از خواندن متن پشتیبانی نمی‌کند','warn'); return; }
              var u = new (window.SpeechSynthesisUtterance||function(s){ this.text=s; })();
              u.text = text; u.lang = 'fa-IR';
              function pickVoice(){
                try {
                  var vs = window.speechSynthesis.getVoices() || [];
                  var fa = vs.find(function(v){ return v && /fa(-IR)?/i.test(String(v.lang||'')); });
                  if (fa) u.voice = fa;
                } catch(_){ }
              }
              pickVoice();
              if (!u.voice){ try { window.speechSynthesis.onvoiceschanged = function(){ pickVoice(); }; } catch(_){ } }
              try { window.speechSynthesis.cancel(); } catch(_){ }
              try { window.speechSynthesis.speak(u); } catch(_){ }
            } catch(_){ }
          }
          if (speak) speak.addEventListener('click', function(){ try { var t = ''; if (out){ var bubbles = out.querySelectorAll('.ar-chat-msg.assistant .ar-chat-bubble'); if (bubbles && bubbles.length){ t = bubbles[bubbles.length-1].textContent || ''; } else { t = out.textContent || ''; } } if(!t){ notify('متنی برای خواندن وجود ندارد','warn'); return; } speakFa(t); } catch(_){ } });
        })();
      } else if (tab === 'forms'){
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
        if (createBtn) createBtn.addEventListener('click', function(){ if (!inlineWrap) return; var showing = inlineWrap.style.display !== 'none'; inlineWrap.style.display = showing ? 'none' : 'flex'; if (!showing){ var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } } });
        if (headerCreateBtn) headerCreateBtn.addEventListener('click', function(){ if (!inlineWrap) return; inlineWrap.style.display = 'flex'; var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } });
        if (cancelBtn) cancelBtn.addEventListener('click', function(){ if (inlineWrap) inlineWrap.style.display = 'none'; });
        if (submitBtn) submitBtn.addEventListener('click', function(){ var titleEl = document.getElementById('arNewFormTitle'); var title = (titleEl && titleEl.value.trim()) || 'فرم جدید'; fetch(ARSHLINE_REST + 'forms', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ title: title }) }).then(async function(r){ if (!r.ok){ if (r.status===401){ if (typeof handle401 === 'function') handle401(); } var t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); }).then(function(obj){ if (obj && obj.id){ notify('فرم ایجاد شد', 'success'); renderFormBuilder(parseInt(obj.id)); } else { notify('ایجاد فرم ناموفق بود. لطفاً دسترسی و دیتابیس را بررسی کنید.', 'error'); if (inlineWrap){ inlineWrap.style.display='flex'; var input=document.getElementById('arNewFormTitle'); if (input) input.focus(); } } }).catch(function(e){ try { console.error('[ARSH] create_form failed:', e); } catch(_){ } notify('ایجاد فرم ناموفق بود. لطفاً دسترسی را بررسی کنید.', 'error'); if (inlineWrap){ inlineWrap.style.display='flex'; var input=document.getElementById('arNewFormTitle'); if (input) input.focus(); } }); });
        (function(){ try { var inp=document.getElementById('arNewFormTitle'); if (inp){ inp.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); if (submitBtn) submitBtn.click(); } }); } } catch(_){ } })();
        fetch(ARSHLINE_REST + 'forms', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(r=>r.json()).then(function(forms){
          var all = Array.isArray(forms) ? forms : [];
          var box = document.getElementById('arFormsList'); if (!box) return;
          function badge(status){ var lab = status==='published'?'فعال':(status==='disabled'?'غیرفعال':'پیش‌نویس'); var col = status==='published'?'#06b6d4':(status==='disabled'?'#ef4444':'#a3a3a3'); return '<span class="hint" style="background:'+col+'20;color:'+col+';padding:.15rem .4rem;border-radius:999px;font-size:12px;">'+lab+'</span>'; }
          function applyFilters(){ var term=(formSearch&&formSearch.value.trim())||''; var df=(formDF&&formDF.value)||''; var dt=(formDT&&formDT.value)||''; var sf=(formSF&&formSF.value)||''; var list = all.filter(function(f){ var ok=true; if (term){ var t=(f.title||'')+' '+String(f.id||''); ok = t.indexOf(term)!==-1; } if (ok && df){ ok = String(f.created_at||'').slice(0,10) >= df; } if (ok && dt){ ok = String(f.created_at||'').slice(0,10) <= dt; } if (ok && sf){ ok = String(f.status||'') === sf; } return ok; }); if (list.length===0){ box.innerHTML = '<div class="hint">فرمی مطابق جستجو یافت نشد.</div>'; return; } var html = list.map(function(f){ return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px dashed var(--border);">\
            <div>#'+f.id+' — '+(f.title||'بدون عنوان')+'<div class="hint">'+(f.created_at||'')+'</div></div>\
            <div style="display:flex;gap:.6rem;">\
              '+badge(String(f.status||''))+'\
              <a href="#" class="arEditForm ar-btn ar-btn--soft" data-id="'+f.id+'">ویرایش</a>\
              <a href="#" class="arPreviewForm ar-btn ar-btn--outline" data-id="'+f.id+'">پیش‌نمایش</a>\
              <a href="#" class="arViewResults ar-btn ar-btn--outline" data-id="'+f.id+'">مشاهده نتایج</a>\
              '+(ARSHLINE_CAN_MANAGE ? '<a href="#" class="arDeleteForm ar-btn ar-btn--danger" data-id="'+f.id+'">حذف</a>' : '')+'\
            </div>\
          </div>'; }).join(''); box.innerHTML = html; box.querySelectorAll('.arEditForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!ARSHLINE_CAN_MANAGE){ if (typeof handle401 === 'function') handle401(); return; } renderFormBuilder(id); }); }); box.querySelectorAll('.arPreviewForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); renderFormPreview(id); }); }); box.querySelectorAll('.arViewResults').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; renderFormResults(id); }); }); if (ARSHLINE_CAN_MANAGE) { box.querySelectorAll('.arDeleteForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; if (!confirm('حذف فرم #'+id+'؟ این عمل بازگشت‌ناپذیر است.')) return; fetch(ARSHLINE_REST + 'forms/' + id, { method:'DELETE', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(){ notify('فرم حذف شد', 'success'); renderTab('forms'); }).catch(function(){ notify('حذف فرم ناموفق بود', 'error'); }); }); }); }
          }
          applyFilters();
          if (formSearch) formSearch.addEventListener('input', function(){ clearTimeout(formSearch._t); formSearch._t = setTimeout(applyFilters, 200); });
          if (formDF) formDF.addEventListener('change', applyFilters);
          if (formDT) formDT.addEventListener('change', applyFilters);
          if (formSF) formSF.addEventListener('change', applyFilters);
          if (window._arOpenCreateInlineOnce && inlineWrap){ inlineWrap.style.display = 'flex'; var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } window._arOpenCreateInlineOnce = false; }
        }).catch(function(){ var box = document.getElementById('arFormsList'); if (box) box.textContent = 'خطا در بارگذاری فرم‌ها.'; notify('خطا در بارگذاری فرم‌ها', 'error'); });
      } else if (tab === 'reports'){
        content.innerHTML = ''+
          '<div class="card glass" style="padding:1rem; margin-bottom:1rem;">'+
          '  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:.6rem; align-items:stretch;">'+
          '    <div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
          '      <div><div class="hint">همه فرم‌ها</div><div id="arRptKpiForms" class="title">0</div></div>'+
          '      <ion-icon name="albums-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
          '    </div>'+
          '    <div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
          '      <div><div class="hint">فرم‌های فعال</div><div id="arRptKpiFormsActive" class="title">0</div></div>'+
          '      <ion-icon name="flash-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
          '    </div>'+
          '    <div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
          '      <div><div class="hint">فرم‌های غیرفعال</div><div id="arRptKpiFormsDisabled" class="title">0</div></div>'+
          '      <ion-icon name="ban-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
          '    </div>'+
          '    <div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
          '      <div><div class="hint">پاسخ‌ها</div><div id="arRptKpiSubs" class="title">0</div></div>'+
          '      <ion-icon name="clipboard-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
          '    </div>'+
          '    <div class="card glass" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">'+
          '      <div><div class="hint">کاربران</div><div id="arRptKpiUsers" class="title">0</div></div>'+
          '      <ion-icon name="people-outline" style="font-size:28px; opacity:.8"></ion-icon>'+
          '    </div>'+
          '  </div>'+
          '</div>'+
          '<div class="card glass" style="padding:1rem;">'+
          '  <div style="display:flex; align-items:center; gap:.6rem; margin-bottom:.6rem;">'+
          '    <span class="title">روند ارسال‌ها</span>'+
          '    <span class="hint">۳۰ روز اخیر</span>'+
          '    <span style="flex:1 1 auto"></span>'+
          '    <select id="arRptStatsDays" class="ar-select"><option value="30" selected>۳۰ روز</option><option value="60">۶۰ روز</option><option value="90">۹۰ روز</option></select>'+
          '  </div>'+
          '  <div style="width:100%; max-width:360px; height:140px;"><canvas id="arRptSubsChart"></canvas></div>'+
          '</div>';
        (function(){
          var daysSel = document.getElementById('arRptStatsDays');
          var ctx = document.getElementById('arRptSubsChart');
          var chart = null;
          function palette(){ var dark = document.body.classList.contains('dark'); return { grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)', text: dark ? '#e5e7eb' : '#374151', line: dark ? '#34d399' : '#059669', fill: dark ? 'rgba(52,211,153,.15)' : 'rgba(5,150,105,.12)' }; }
          function renderChart(labels, data){ var pal=palette(); if (!ctx) return; try{ if(chart){ chart.destroy(); chart=null; } } catch(_){ } if (!window.Chart) return; chart = new window.Chart(ctx, { type:'line', data:{ labels:labels, datasets:[{ label:'ارسال‌ها', data:data, borderColor:pal.line, backgroundColor:pal.fill, fill:true, tension:.3, pointRadius:1.5, borderWidth:1.5 }] }, options:{ responsive:true, maintainAspectRatio:false, layout:{ padding:{ top:6, right:8, bottom:6, left:8 } }, scales:{ x:{ grid:{ color:pal.grid }, ticks:{ color:pal.text, maxRotation:0, autoSkip:true, maxTicksLimit:10 } }, y:{ grid:{ color:pal.grid }, ticks:{ color:pal.text, precision:0 } } }, plugins:{ legend:{ labels:{ color:pal.text } }, tooltip:{ intersect:false, mode:'index' } } } }); }
          function applyCounts(c){ function set(id,v){ var el=document.getElementById(id); if (el) el.textContent=String(v||0); } set('arRptKpiForms', c.forms); set('arRptKpiFormsActive', c.forms_active); set('arRptKpiFormsDisabled', c.forms_disabled); set('arRptKpiSubs', c.submissions); set('arRptKpiUsers', c.users); }
          function load(days){ try { var url=new URL(ARSHLINE_REST + 'stats'); url.searchParams.set('days', String(days||30)); fetch(url.toString(), { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(data){ applyCounts(data.counts||{}); var ser=data.series||{}; renderChart(ser.labels||[], ser.submissions_per_day||[]); }).catch(function(err){ console.error('[ARSH] stats failed', err); notify('دریافت آمار ناموفق بود', 'error'); }); } catch(e){ console.error(e); } }
          if (daysSel){ daysSel.addEventListener('change', function(){ load(parseInt(daysSel.value||'30')); }); }
          load(30);
          try { var themeToggle = document.getElementById('arThemeToggle'); if (themeToggle){ themeToggle.addEventListener('click', function(){ try { var l = (chart && chart.config && chart.config.data && chart.config.data.labels) || []; var v = (chart && chart.config && chart.config.data && chart.config.data.datasets && chart.config.data.datasets[0] && chart.config.data.datasets[0].data) || []; if (l.length) renderChart(l, v); } catch(_){ } }); } } catch(_){ }
        })();
      } else if (tab === 'messaging'){
        // Messaging: split into sub-sections (tabs):
        // - sms: sending UI
        // - settings: nested tabs, starting with "تنظیمات پیامک"
        var qs = new URLSearchParams((location.hash.split('?')[1]||''));
        var mtab = qs.get('tab') || 'sms'; // sms | settings
        var msub = qs.get('sub') || 'sms-settings';

        function setMsgHash(tab, sub){
          try { setHash('messaging' + (tab? ('?tab='+encodeURIComponent(tab) + (sub? ('&sub='+encodeURIComponent(sub)) : '')) : '')); } catch(_){ }
        }

        content.innerHTML = ''+
          '<div class="card glass" style="padding:1rem;display:flex;flex-direction:column;gap:.8rem">'
          + '  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">'
          + '    <button class="ar-btn ar-btn--soft" data-m-tab="sms">پیامک</button>'
          + '    <button class="ar-btn ar-btn--soft" data-m-tab="settings">تنظیمات</button>'
          + '  </div>'
          + '  <div id="arMsg_SMS" class="m-panel" style="display:none">'
          + '    <div class="title" style="margin-bottom:.4rem">ارسال پیامک به گروه‌های کاربری</div>'
          + '    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem">'
          + '      <label class="ar-field"><span class="ar-label">گروه‌ها</span><select id="smsGroups" class="ar-select" multiple size="6"></select></label>'
          + '      <label class="ar-field"><span class="ar-label">فرم (اختیاری برای لینک شخصی)</span><select id="smsForm" class="ar-select"><option value="">— بدون لینک —</option></select></label>'
          + '    </div>'
          + '    <label class="ar-field"><span class="ar-label">متن پیام</span><textarea id="smsMessage" class="ar-input" rows="4" placeholder="مثال: سلام #name، لطفاً فرم زیر را تکمیل کنید: #link"></textarea></label>'
          + '    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">'
          + '      <label class="ar-field"><span class="ar-label">زمان‌بندی (اختیاری)</span><input id="smsSchedule" class="ar-input" placeholder="YYYY-MM-DD HH:MM" /></label>'
          + '      <button id="smsSend" class="ar-btn">ارسال</button>'
          + '      <span id="smsVarsHint" class="hint">متغیرها: #name، #phone، #link و فیلدهای سفارشی گروه</span>'
          + '    </div>'
          + '  </div>'
          + '  <div id="arMsg_Settings" class="m-panel" style="display:none">'
          + '    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.4rem">'
          + '      <button class="ar-btn ar-btn--soft" data-ms-tab="sms-settings">تنظیمات پیامک</button>'
          + '    </div>'
          + '    <div id="arMsgS_SmsSettings" class="ms-panel" style="display:none">'
          + '      <div class="hint" style="margin-bottom:.6rem">پیکربندی اتصال به سرویس SMS.IR</div>'
          + '      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem">'
          + '        <label class="ar-field"><span class="ar-label">فعال</span><input id="smsEnabled" type="checkbox" class="ar-input" /></label>'
          + '        <label class="ar-field"><span class="ar-label">کلید API</span><input id="smsApiKey" class="ar-input" placeholder="API Key" /></label>'
          + '        <label class="ar-field"><span class="ar-label">شماره خط</span><input id="smsLine" class="ar-input" placeholder="3000..." /></label>'
          + '      </div>'
          + '      <div style="display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap">'
          + '        <button id="smsSave" class="ar-btn">ذخیره</button>'
          + '        <button id="smsTest" class="ar-btn ar-btn--soft">تست</button>'
          + '        <input id="smsTestPhone" class="ar-input" placeholder="شماره تست (اختیاری)" style="max-width:220px" />'
          + '      </div>'
          + '    </div>'
          + '  </div>'
          + '</div>';

        // Tab handlers (explicit ID mapping to avoid case mismatches)
        (function(){
          var MAIN = { sms: 'arMsg_SMS', settings: 'arMsg_Settings' };
          var SUBS = { 'sms-settings': 'arMsgS_SmsSettings' };
          function showMain(which){ ['sms','settings'].forEach(function(k){ var id = MAIN[k]; var el = id && document.getElementById(id); if (el) el.style.display = (which===k)?'block':'none'; });
            var btns = content.querySelectorAll('[data-m-tab]'); btns.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-m-tab')===which); }); }
          function showSettings(sub){ Object.keys(SUBS).forEach(function(k){ var id = SUBS[k]; var el = id && document.getElementById(id); if (el) el.style.display = (k===sub)?'block':'none'; });
            var sbtns = content.querySelectorAll('[data-ms-tab]'); sbtns.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-ms-tab')===sub); }); }
          var mBtns = content.querySelectorAll('[data-m-tab]'); mBtns.forEach(function(b){ b.addEventListener('click', function(){ mtab = b.getAttribute('data-m-tab')||'sms'; setMsgHash(mtab, mtab==='settings'? msub : ''); showMain(mtab); if (mtab==='settings'){ showSettings(msub||'sms-settings'); } }); });
          var sBtns = content.querySelectorAll('[data-ms-tab]'); sBtns.forEach(function(b){ b.addEventListener('click', function(){ msub = b.getAttribute('data-ms-tab')||'sms-settings'; setMsgHash('settings', msub); showSettings(msub); }); });
          // init
          showMain(mtab);
          if (mtab==='settings'){ showSettings(msub||'sms-settings'); }
        })();

        // Load settings (for settings tab)
        fetch(ARSHLINE_REST + 'sms/settings', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
          .then(function(r){ return r.json(); })
          .then(function(s){ try { var en=document.getElementById('smsEnabled'); if(en) en.checked = !!s.enabled; var ak=document.getElementById('smsApiKey'); if(ak) ak.value = s.api_key||''; var ln=document.getElementById('smsLine'); if(ln) ln.value = s.line_number||''; } catch(_){ } })
          .catch(function(){ /* ignore */ });

        // Local state for SMS UI filtering
  var _smsAllForms = [];
        var _smsFormAccessCache = Object.create(null); // formId -> [groupIds]
        var _smsFormAccessInFlight = Object.create(null); // formId -> Promise
  var _smsGroupFieldsCache = Object.create(null); // groupId -> [{name,label}]
  var _smsGroupFieldsInFlight = Object.create(null); // groupId -> Promise

        function smsGetFormAllowedGroups(fid){
          fid = parseInt(fid, 10) || 0; if (!fid) return Promise.resolve([]);
          if (_smsFormAccessCache[fid]) return Promise.resolve(_smsFormAccessCache[fid]);
          if (_smsFormAccessInFlight[fid]) return _smsFormAccessInFlight[fid];
          var p = fetch(ARSHLINE_REST + 'forms/' + fid + '/access/groups', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(function(r){ return r.json(); })
            .then(function(obj){
              // Accept both legacy [id,...] and current { group_ids: [id,...] }
              var raw = obj;
              if (obj && obj.group_ids && Array.isArray(obj.group_ids)) { raw = obj.group_ids; }
              var ids = Array.isArray(raw) ? raw.map(function(x){ return parseInt(x,10)||0; }).filter(Boolean) : [];
              _smsFormAccessCache[fid] = ids;
              return ids;
            })
            .finally(function(){ delete _smsFormAccessInFlight[fid]; });
          _smsFormAccessInFlight[fid] = p; return p;
        }

        function updateSmsFormsOptions(){
          var el = document.getElementById('smsForm'); if (!el) return;
          var grpEl = document.getElementById('smsGroups'); var selGroups = [];
          try { selGroups = Array.from((grpEl && grpEl.selectedOptions) ? grpEl.selectedOptions : []).map(function(o){ return parseInt(o.value,10)||0; }).filter(Boolean); } catch(_){ selGroups = []; }
          var prevVal = el.value || '';
          el.innerHTML = '<option value="">— بدون لینک —</option>';
          var published = (_smsAllForms||[]).filter(function(f){ return String(f.status) === 'published'; });
          if (!selGroups.length){
            // No groups selected: show all published forms
            published.forEach(function(f){ el.insertAdjacentHTML('beforeend', '<option value="'+f.id+'">#'+f.id+' - '+escapeHtml(String(f.title||''))+'</option>'); });
            try { if (prevVal) el.value = prevVal; } catch(_){ }
            return;
          }
          // With groups selected: only forms mapped to ALL selected groups
          Promise.all(published.map(function(f){ return smsGetFormAllowedGroups(f.id).then(function(ids){ return { f: f, ids: ids }; }); }))
            .then(function(list){
              var allowed = list.filter(function(it){ return selGroups.every(function(g){ return it.ids.indexOf(g) !== -1; }); }).map(function(it){ return it.f; });
              allowed.forEach(function(f){ el.insertAdjacentHTML('beforeend', '<option value="'+f.id+'">#'+f.id+' - '+escapeHtml(String(f.title||''))+'</option>'); });
              // Preserve selection if still valid
              if (allowed.some(function(f){ return String(f.id) === String(prevVal); })){
                try { el.value = prevVal; } catch(_){ }
              } else {
                try { el.value = ''; } catch(_){ }
              }
            })
            .catch(function(){ /* ignore filter errors, keep base option */ });
        }

        function smsGetGroupFields(gid){
          gid = parseInt(gid,10)||0; if (!gid) return Promise.resolve([]);
          if (_smsGroupFieldsCache[gid]) return Promise.resolve(_smsGroupFieldsCache[gid]);
          if (_smsGroupFieldsInFlight[gid]) return _smsGroupFieldsInFlight[gid];
          var p = fetch(ARSHLINE_REST + 'user-groups/' + gid + '/fields', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(function(r){ return r.json(); })
            .then(function(arr){ var out = Array.isArray(arr)? arr.map(function(f){ return { name: String(f.name||'').trim(), label: String(f.label||'').trim()||String(f.name||'') }; }).filter(function(f){ return !!f.name; }) : []; _smsGroupFieldsCache[gid] = out; return out; })
            .finally(function(){ delete _smsGroupFieldsInFlight[gid]; });
          _smsGroupFieldsInFlight[gid] = p; return p;
        }

        function updateSmsVariablesHint(){
          var hintEl = document.getElementById('smsVarsHint'); if (!hintEl) return;
          var base = ['#name', '#phone', '#link'];
          var grpEl = document.getElementById('smsGroups'); var sel = [];
          try { sel = Array.from((grpEl && grpEl.selectedOptions) ? grpEl.selectedOptions : []).map(function(o){ return parseInt(o.value,10)||0; }).filter(Boolean); } catch(_){ sel = []; }
          if (!sel.length){ hintEl.textContent = 'متغیرها: ' + base.join('، ') + ' و فیلدهای سفارشی گروه'; return; }
          // Load fields for all selected groups and compute intersection by name
          Promise.all(sel.map(function(gid){ return smsGetGroupFields(gid); }))
              .then(function(j){
              // Build a set intersection of field names across groups
                  if (ANA_DEBUG) { try { console.info('[ARSH][ANA] response', j); } catch(_){ } }
              var nameSets = all.map(function(list){ return new Set(list.map(function(f){ return f.name; })); });
              var commonNames = [];
                  var dbg = j.debug;
                  if (typeof txt !== 'string' || txt === '') txt = 'پاسخی دریافت نشد.';
                nameSets[0].forEach(function(n){ var inAll = nameSets.every(function(s){ return s.has(n); }); if (inAll) commonNames.push(n); });
                  var footer = '';
                  if (usage && usage.length){ footer = usage.map(function(u){ var uu=u.usage||{}; return '[مصرف توکن] ورودی: '+uu.input+' ؛ خروجی: '+uu.output+' ؛ کل: '+uu.total+(uu.duration_ms?(' ؛ زمان: '+uu.duration_ms+'ms'):''); }).join('\n'); }
                  if (dbg && ANA_DEBUG){ try { footer += (footer?'\n':'') + '--- DEBUG ---\n' + JSON.stringify(dbg, null, 2); } catch(_){ } }
                  out.textContent = txt + (footer? ('\n\n'+footer) : '');
              // Render as #fieldName placeholders
              var custom = commonNames.map(function(n){ return '#' + n; });
              var out = base.concat(custom);
              hintEl.textContent = 'متغیرها: ' + out.join('، ');
            })
            .catch(function(){ hintEl.textContent = 'متغیرها: ' + base.join('، ') + ' و فیلدهای سفارشی گروه'; });
        }

        // Load groups (for sms tab)
        fetch(ARSHLINE_REST + 'user-groups', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
          .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
          .then(function(gs){ var el = document.getElementById('smsGroups'); if (!el) return; el.innerHTML = (gs||[]).map(function(g){ return '<option value="'+g.id+'">'+escapeHtml(String(g.name||('گروه #'+g.id)))+' ('+(g.member_count||0)+')</option>'; }).join(''); try { el.addEventListener('change', function(){ updateSmsFormsOptions(); updateSmsVariablesHint(); }); } catch(_){ } })
          .catch(function(){ notify('بارگذاری گروه‌ها ناموفق بود', 'error'); });
        // Load forms (for link)
        fetch(ARSHLINE_REST + 'forms', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
          .then(function(r){ return r.json(); })
          .then(function(fs){ _smsAllForms = Array.isArray(fs) ? fs : []; updateSmsFormsOptions(); updateSmsVariablesHint(); })
          .catch(function(){ /* ignore */ });

        // Save settings
        var btnSave = document.getElementById('smsSave'); if (btnSave) btnSave.addEventListener('click', function(){
          var payload = { enabled: !!(document.getElementById('smsEnabled')&&document.getElementById('smsEnabled').checked), api_key: String((document.getElementById('smsApiKey')&&document.getElementById('smsApiKey').value)||''), line_number: String((document.getElementById('smsLine')&&document.getElementById('smsLine').value)||'') };
          fetch(ARSHLINE_REST + 'sms/settings', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) })
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(function(){ notify('تنظیمات پیامک ذخیره شد', 'success'); })
            .catch(function(){ notify('ذخیره تنظیمات پیامک ناموفق بود', 'error'); });
        });
        // Test send
        var btnTest = document.getElementById('smsTest'); if (btnTest) btnTest.addEventListener('click', function(){
          var phEl = document.getElementById('smsTestPhone'); var ph = String(phEl && phEl.value || '').trim();
          fetch(ARSHLINE_REST + 'sms/test', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ phone: ph }) })
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(function(){ notify('تست موفق بود', 'success'); })
            .catch(function(){ notify('تست ناموفق بود', 'error'); });
        });
        // Send to groups
        var btnSend = document.getElementById('smsSend'); if (btnSend) btnSend.addEventListener('click', async function(){
          var groupsSel = document.getElementById('smsGroups'); var formSel = document.getElementById('smsForm'); var msgEl = document.getElementById('smsMessage'); var schEl = document.getElementById('smsSchedule');
          var gids = []; try { gids = Array.from(groupsSel && groupsSel.selectedOptions || []).map(function(o){ return parseInt(o.value||'0'); }).filter(function(x){ return x>0; }); } catch(_){ }
          var fid = parseInt((formSel && formSel.value)||'0')||0; var includeLink = fid>0;
          var messageRaw = (msgEl && msgEl.value)||''; var schedule_at = (schEl && schEl.value)||'';
          // Preflight: if message uses #link/#لینک but no form selected, block
          var usesLink = /(#link|#لینک)/i.test(messageRaw);
          if (usesLink && !includeLink){ notify('در متن از #لینک استفاده شده ولی فرمی انتخاب نشده است.', 'warn'); return; }
          // Additional preflight: if a form is selected for personal link, ensure it is published and has a public token
          if (includeLink){
            try {
              var fRes = await fetch(ARSHLINE_REST + 'forms/' + fid, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} });
              var fJson = await fRes.json().catch(function(){ return {}; });
              if (!fRes.ok){ notify('عدم دسترسی به فرم انتخابی یا خطا در بارگذاری فرم.', 'error'); return; }
              var fStatus = (fJson && fJson.status) || '';
              if (String(fStatus) !== 'published'){
                notify('فرم انتخابی باید «فعال/منتشر» باشد تا لینک اختصاصی ساخته شود.', 'warn');
                return;
              }
              // Preflight mapping: ensure selected groups are allowed for this form
              try {
                var mapRes = await fetch(ARSHLINE_REST + 'forms/' + fid + '/access/groups', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} });
                var mapJson = await mapRes.json().catch(function(){ return {}; });
                var allowed = (mapJson && Array.isArray(mapJson.group_ids)) ? mapJson.group_ids.map(function(x){ return parseInt(x); }) : [];
                if (!allowed.length){ notify('این فرم هنوز به هیچ گروهی متصل نشده است. ابتدا در «کاربران → گروه‌ها → اتصال فرم‌ها» گروه‌ها را تنظیم کنید.', 'warn'); return; }
                var allAllowed = gids.every(function(g){ return allowed.indexOf(g) >= 0; });
                if (!allAllowed){ notify('برخی از گروه‌های انتخاب‌شده به این فرم متصل نیستند. اتصال را بررسی کنید.', 'warn'); return; }
              } catch(_){ }
              // Ensure public token exists (server auto-generates for published forms, but we ensure explicitly if needed)
              if (!fJson.token){
                try { await fetch(ARSHLINE_REST + 'forms/' + fid + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }); } catch(_){ }
              }
            } catch(_){ /* network/preflight failure */ }
          }
          var message = messageRaw + ' لغو11';
          if (!gids.length){ notify('حداقل یک گروه را انتخاب کنید', 'warn'); return; }
          if (!message.trim()){ notify('متن پیام خالی است', 'warn'); return; }
          var payload = { group_ids: gids, message: message, include_link: includeLink, form_id: includeLink? fid: undefined, schedule_at: schedule_at||undefined };
          try {
            var r = await fetch(ARSHLINE_REST + 'sms/send', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) });
            var body = await r.json().catch(function(){ return {}; });
            if (r.ok){
              if (body && body.job_id){ notify('در صف ارسال قرار گرفت (#'+body.job_id+')', 'success'); }
              else { notify('ارسال انجام شد: '+(body && body.sent || 0), 'success'); }
            } else {
              var code = body && body.error;
              var errMsg = (body && (body.message || body.error)) || 'ارسال ناموفق بود';
              if (code === 'sms_disabled') errMsg = 'ارسال پیامک غیرفعال است. لطفاً تنظیمات پیامک را فعال کنید.';
              else if (code === 'missing_config') errMsg = 'تنظیمات پیامک ناقص است (API Key یا شماره خط). لطفاً در «تنظیمات پیامک» تکمیل کنید.';
              else if (code === 'no_groups') errMsg = 'حداقل یک گروه را انتخاب کنید.';
              else if (code === 'empty_message') errMsg = 'متن پیام خالی است.';
              else if (code === 'no_recipients') errMsg = 'هیچ مخاطبی با شماره معتبر در گروه‌های انتخابی یافت نشد.';
              else if (code === 'link_placeholder_without_form') errMsg = 'در متن از #لینک استفاده شده ولی فرمی انتخاب نشده است.';
              else if (code === 'link_build_failed') errMsg = 'ساخت لینک اختصاصی برای یکی از اعضا ناموفق بود' + (body && body.member_id ? ' (عضو #'+body.member_id+')' : '') + '. مطمئن شوید فرم فعال است و توکن عمومی دارد.';
              else if (code === 'form_not_mapped') errMsg = 'فرم انتخابی به هیچ گروهی متصل نشده است. ابتدا در «اتصال فرم‌ها» گروه(ها) را برای این فرم تنظیم کنید.';
              else if (code === 'form_not_allowed_for_groups') errMsg = 'فرم انتخابی به برخی از گروه‌های انتخابی متصل نیست. لطفاً اتصال فرم‌ها را بررسی کنید.';
              notify(errMsg, 'error');
            }
          } catch(e){
            notify('خطا در ارسال پیامک', 'error');
          }
        });
      } else if (tab === 'users'){
        // Users: list/search/create + Roles/Policies editor (super admin)
        var uqs = new URLSearchParams((location.hash.split('?')[1]||''));
        var utab = uqs.get('tab') || 'list'; // list | policies
        function setUsersHash(which){ try { setHash('users' + (which && which!=='list' ? ('?tab='+encodeURIComponent(which)) : '')); } catch(_){ } }
        content.innerHTML = ''+
          '<div class="card glass" style="padding:1rem;display:flex;flex-direction:column;gap:.8rem">'
          + '  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">'
          + '    <button class="ar-btn ar-btn--soft" data-u-tab="list">کاربران</button>'
          + '    <button class="ar-btn ar-btn--soft" data-u-tab="policies">نقش‌ها و سیاست‌ها</button>'
          + '    <span style="flex:1 1 auto"></span>'
          + '    <a class="ar-btn ar-btn--outline" href="#users/ug">گروه‌های کاربری</a>'
          + '  </div>'
          + '  <div id="arU_List" class="u-panel" style="display:none">'
          + '    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">'
          + '      <span class="title">کاربران</span>'
          + '      <span style="flex:1 1 auto"></span>'
          + '      <input id="uSearch" class="ar-input" placeholder="جستجو نام کاربری/ایمیل" style="min-width:220px" />'
          + '      <select id="uRoleFilter" class="ar-select" style="min-width:180px"><option value="">همه نقش‌ها</option></select>'
          + '      <button id="uReload" class="ar-btn ar-btn--soft">نوسازی</button>'
          + '    </div>'
          + '    <details id="uCreateWrap" style="margin-top:.6rem"><summary class="ar-btn ar-btn--outline">+ افزودن کاربر</summary>'
          + '      <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.6rem">'
          + '        <input id="uNewLogin" class="ar-input" placeholder="نام کاربری" style="min-width:160px" />'
          + '        <input id="uNewEmail" class="ar-input" placeholder="ایمیل" style="min-width:220px" />'
          + '        <select id="uNewRole" class="ar-select" style="min-width:180px"><option value="">نقش (اختیاری)</option></select>'
          + '        <button id="uCreate" class="ar-btn">ایجاد کاربر</button>'
          + '      </div>'
          + '      <div class="hint">رمز عبور به‌صورت خودکار تولید می‌شود؛ می‌توانید بعدا تغییر دهید.</div>'
          + '    </details>'
          + '    <div id="uList" class="card" style="margin-top:.6rem;padding:.6rem">'
          + '      <div class="hint">در حال بارگذاری کاربران...</div>'
          + '    </div>'
          + '  </div>'
          + '  <div id="arU_Policies" class="u-panel" style="display:none">'
          + '    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">'
          + '      <span class="title">نقش‌ها و سیاست‌های دسترسی</span>'
          + '      <span style="flex:1 1 auto"></span>'
          + '      <button id="uPolSave" class="ar-btn">ذخیره سیاست‌ها</button>'
          + '    </div>'
          + '    <div id="uPolWrap" class="card" style="margin-top:.6rem;padding:.6rem">'
          + '      <div class="hint">در حال بارگذاری نقش‌ها و سیاست‌ها...</div>'
          + '    </div>'
          + '  </div>'
          + '</div>';

        (function bindUsersTabs(){ var btns = content.querySelectorAll('[data-u-tab]'); function show(which){ btns.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-u-tab')===which); }); var L=document.getElementById('arU_List'); var P=document.getElementById('arU_Policies'); if (L) L.style.display = (which==='list')?'block':'none'; if (P) P.style.display = (which==='policies')?'block':'none'; setUsersHash(which); if (which==='list'){ __u_initList(); } else { __u_initPolicies(); } } btns.forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-u-tab')||'list'); }); }); show(utab); })();

        // Users List implementation
        function __u_initList(){
          var roleSelect = document.getElementById('uRoleFilter');
          var newRoleSel = document.getElementById('uNewRole');
          var listBox = document.getElementById('uList');
          function loadRoles(){ return fetch(ARSHLINE_REST + 'roles', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
            .then(function(j){ var roles = Array.isArray(j.roles)? j.roles: []; var opts = '<option value="">همه نقش‌ها</option>' + roles.map(function(r){ return '<option value="'+escapeAttr(r.key)+'">'+escapeHtml(r.name||r.key)+'</option>'; }).join(''); if (roleSelect) roleSelect.innerHTML = opts; if (newRoleSel){ newRoleSel.innerHTML = '<option value="">نقش (اختیاری)</option>' + roles.map(function(r){ return '<option value="'+escapeAttr(r.key)+'">'+escapeHtml(r.name||r.key)+'</option>'; }).join(''); } return roles; })
            .catch(function(e){ (roleSelect)&&(roleSelect.innerHTML='<option value="">همه نقش‌ها</option>'); return []; }); }
          function renderUsers(items){
            if (!listBox) return;
            if (!Array.isArray(items) || items.length === 0){
              listBox.innerHTML = '<div class="hint">کاربری یافت نشد.</div>';
              return;
            }
            // Table header
            var html = ''
              + '<div class="ar-table-wrap">'
              + '  <table class="ar-table" style="width:100%;border-collapse:separate;border-spacing:0 6px">'
              + '    <thead><tr>'
              + '      <th style="text-align:right;padding:.4rem .6rem">کاربر</th>'
              + '      <th style="text-align:right;padding:.4rem .6rem;width:30%">نقش</th>'
              + '      <th style="text-align:right;padding:.4rem .6rem;width:20%">وضعیت</th>'
              + '      <th style="text-align:right;padding:.4rem .6rem;width:20%">عملیات</th>'
              + '    </tr></thead>'
              + '    <tbody>'
              + items.map(function(u){
                  var role = Array.isArray(u.roles) && u.roles.length ? u.roles[0] : '';
                  var disabled = !!u.disabled;
                  return ''
                    + '<tr data-uid="'+u.id+'" class="glass" style="background:var(--surface,#fff);box-shadow:0 2px 8px rgba(0,0,0,.06)">'
                    + '  <td style="padding:.5rem .6rem">'
                    + '    <div class="title" style="font-size:1rem">'+escapeHtml(u.user_login||('user#'+u.id))+'</div>'
                    + '    <div class="hint">'+escapeHtml(u.email||'')+'</div>'
                    + '  </td>'
                    + '  <td style="padding:.5rem .6rem">'
                    + '    <div class="uRoleView">'+escapeHtml(role||'—')+'</div>'
                    + '    <div class="uRoleEdit" style="display:none">'
                    + '      <select class="uRoleSel ar-select" style="min-width:180px"></select>'
                    + '    </div>'
                    + '  </td>'
                    + '  <td style="padding:.5rem .6rem">'
                    + '    <span class="uStatus">'+(disabled?'<span class="badge badge--danger">غیرفعال</span>':'<span class="badge badge--success">فعال</span>')+'</span>'
                    + '  </td>'
                    + '  <td style="padding:.5rem .6rem;display:flex;gap:.4rem;flex-wrap:wrap">'
                    + '    <button class="uEditRole ar-btn ar-btn--soft">ویرایش نقش</button>'
                    + '    <button class="uSaveRole ar-btn" style="display:none">ذخیره</button>'
                    + '    <button class="uCancelRole ar-btn ar-btn--outline" style="display:none">انصراف</button>'
                    + '    <button class="uToggleEnable ar-btn '+(disabled?'':'ar-btn--soft')+'">'+(disabled?'فعال‌سازی':'غیرفعالسازی')+'</button>'
                    + '    <button class="uDelete ar-btn ar-btn--danger">حذف</button>'
                    + '  </td>'
                    + '</tr>';
                }).join('')
              + '    </tbody>'
              + '  </table>'
              + '</div>';
            listBox.innerHTML = html;
            // Load roles and populate single-selects
            fetch(ARSHLINE_REST + 'roles', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
              .then(function(r){ return r.json(); })
              .then(function(j){
                var all = Array.isArray(j.roles)? j.roles: [];
                listBox.querySelectorAll('tr[data-uid]').forEach(function(tr){
                  var uid = parseInt(tr.getAttribute('data-uid')||'0');
                  var user = items.find(function(x){ return parseInt(x.id)===uid; }) || { roles: [] };
                  var role = Array.isArray(user.roles) && user.roles.length ? user.roles[0] : '';
                  var sel = tr.querySelector('.uRoleSel');
                  if (sel){ sel.innerHTML = '<option value="">—</option>' + all.map(function(r){ return '<option value="'+escapeAttr(r.key)+'"'+(r.key===role?' selected':'')+'>'+escapeHtml(r.name||r.key)+'</option>'; }).join(''); }
                });
                // Wire actions per row
                listBox.querySelectorAll('tr[data-uid]').forEach(function(tr){
                  var uid = parseInt(tr.getAttribute('data-uid')||'0');
                  var editBtn = tr.querySelector('.uEditRole');
                  var saveBtn = tr.querySelector('.uSaveRole');
                  var cancelBtn = tr.querySelector('.uCancelRole');
                  var roleView = tr.querySelector('.uRoleView');
                  var roleEdit = tr.querySelector('.uRoleEdit');
                  var sel = tr.querySelector('.uRoleSel');
                  var toggleBtn = tr.querySelector('.uToggleEnable');
                  var delBtn = tr.querySelector('.uDelete');
                  function toggleEdit(on){ if (on){ roleView.style.display='none'; roleEdit.style.display='block'; editBtn.style.display='none'; saveBtn.style.display='inline-flex'; cancelBtn.style.display='inline-flex'; } else { roleView.style.display='block'; roleEdit.style.display='none'; editBtn.style.display='inline-flex'; saveBtn.style.display='none'; cancelBtn.style.display='none'; } }
                  if (editBtn) editBtn.addEventListener('click', function(){ toggleEdit(true); });
                  if (cancelBtn) cancelBtn.addEventListener('click', function(){ toggleEdit(false); });
                  if (saveBtn) saveBtn.addEventListener('click', function(){ var role = String(sel && sel.value || '').trim(); fetch(ARSHLINE_REST + 'users/'+uid, { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ role: role }) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('نقش کاربر ذخیره شد', 'success'); __u_load(); }).catch(function(){ notify('ذخیره نقش ناموفق بود', 'error'); }); });
                  if (toggleBtn) toggleBtn.addEventListener('click', function(){ var isDis = toggleBtn.textContent.indexOf('فعال‌سازی')===-1 ? false : true; var want = isDis; fetch(ARSHLINE_REST + 'users/'+uid, { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ disabled: want }) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify(want?'کاربر غیرفعال شد':'کاربر فعال شد', 'success'); __u_load(); }).catch(function(){ notify('عملیات ناموفق بود', 'error'); }); });
                  if (delBtn) delBtn.addEventListener('click', function(){ if (!confirm('حذف کاربر #'+uid+'؟')) return; fetch(ARSHLINE_REST + 'users/'+uid, { method:'DELETE', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ return r.json().then(function(j){ return { ok:r.ok, status:r.status, body:j }; }); }).then(function(res){ if (res.ok && res.body && res.body.ok){ notify('کاربر حذف شد', 'success'); __u_load(); } else { notify('حذف کاربر ناموفق بود', 'error'); } }).catch(function(){ notify('حذف کاربر ناموفق بود', 'error'); }); });
                });
              });
          }
          function __u_load(){ if (listBox) listBox.innerHTML = '<div class="hint">در حال بارگذاری...</div>'; var qs=new URLSearchParams(); var s=String(document.getElementById('uSearch')?.value||'').trim(); var rf=String(document.getElementById('uRoleFilter')?.value||'').trim(); if (s) qs.set('search', s); if (rf) qs.set('role', rf); fetch(ARSHLINE_REST + 'users' + (qs.toString()?('?'+qs.toString()):''), { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if (r.status===403){ return r.json().then(function(){ throw new Error('forbidden'); }); } if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(obj){ renderUsers(obj.items||[]); }).catch(function(e){ if (listBox) listBox.innerHTML = '<div class="hint">دسترسی به مدیریت کاربران ندارید.</div>'; }); }
          // Wire controls
          var rBtn = document.getElementById('uReload'); if (rBtn) rBtn.addEventListener('click', __u_load);
          var sInp = document.getElementById('uSearch'); if (sInp) sInp.addEventListener('input', function(){ clearTimeout(sInp._t); sInp._t = setTimeout(__u_load, 250); });
          if (roleSelect) roleSelect.addEventListener('change', __u_load);
          // Create user
          var cBtn = document.getElementById('uCreate'); if (cBtn) cBtn.addEventListener('click', function(){ var login = String(document.getElementById('uNewLogin')?.value||'').trim(); var email = String(document.getElementById('uNewEmail')?.value||'').trim(); var role = String(document.getElementById('uNewRole')?.value||'').trim(); if (!login || !email){ notify('نام کاربری و ایمیل الزامی هستند', 'warn'); return; } fetch(ARSHLINE_REST + 'users', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ user_login: login, user_email: email, role: role||undefined }) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('کاربر ایجاد شد', 'success'); try { document.getElementById('uCreateWrap').open = false; } catch(_){ } __u_load(); }).catch(function(){ notify('ایجاد کاربر ناموفق بود', 'error'); }); });
          loadRoles().then(__u_load);
        }

        // Policies editor implementation (super admin only)
        function __u_initPolicies(){
          var wrap = document.getElementById('uPolWrap'); var saveBtn = document.getElementById('uPolSave'); if (wrap) wrap.innerHTML = '<div class="hint">در حال بارگذاری...</div>';
          var rolesInv = []; var features = []; var groups = [];
          function loadAll(){
            return Promise.all([
              fetch(ARSHLINE_REST + 'roles', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok) throw new Error('roles:'+r.status); return r.json(); }).then(function(j){ rolesInv = Array.isArray(j.roles)? j.roles: []; features = Array.isArray(j.features)? j.features: []; }),
              fetch(ARSHLINE_REST + 'roles/policies', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok) throw new Error('pol:'+r.status); return r.json(); }),
              fetch(ARSHLINE_REST + 'user-groups', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok) throw new Error('groups:'+r.status); return r.json(); }).then(function(gs){ groups = Array.isArray(gs) ? gs : []; })
            ]).then(function(results){ return results[1]; });
          }
          function featLabel(k){ switch(k){ case 'forms': return 'فرم‌ها'; case 'groups': return 'گروه‌ها'; case 'sms': return 'پیامک'; case 'reports': return 'گزارش‌ها'; case 'settings': return 'تنظیمات'; case 'users': return 'کاربران'; case 'ai': return 'هوش مصنوعی'; default: return k; } }
          function renderPol(policies){ if (!wrap) return; var pol = (policies && policies.policies) ? policies.policies : policies; var byRole = (pol && pol.roles) ? pol.roles : {}; var roleKeys = Object.keys(byRole);
            if (!roleKeys.length){ wrap.innerHTML = '<div class="hint">نقشی برای ویرایش یافت نشد.</div>'; return; }
            wrap.innerHTML = roleKeys.map(function(rk){ var rpol = byRole[rk] || {}; var label = String(rpol.label||rk); var feats = rpol.features || {}; var gs = rpol.group_scope || { all:false, ids:[] }; var featHtml = features.map(function(fk){ var on = !!feats[fk]; return '<label style="display:inline-flex;align-items:center;gap:.35rem;margin:.2rem .4rem">\
                <input type="checkbox" class="uPolFeat" data-role="'+escapeAttr(rk)+'" value="'+escapeAttr(fk)+'"'+(on?' checked':'')+' /> '+featLabel(fk)+'\
              </label>'; }).join('');
              var grpOpts = groups.map(function(g){ var sel = Array.isArray(gs.ids) && gs.ids.indexOf(g.id)>=0 ? ' selected' : ''; return '<option value="'+String(g.id)+'"'+sel+'>#'+g.id+' — '+escapeHtml(g.name||('گروه #'+g.id))+'</option>'; }).join('');
              return '\
              <div class="card glass" data-role="'+escapeAttr(rk)+'" style="padding:.7rem;margin:.5rem 0">\
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;justify-content:space-between">\
                  <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">\
                    <span class="title" style="font-size:1rem">'+escapeHtml(label)+'</span>\
                    <span class="hint">('+escapeHtml(rk)+')</span>\
                  </div>\
                </div>\
                <div style="margin:.4rem 0;display:flex;gap:.4rem;flex-wrap:wrap">'+featHtml+'</div>\
                <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-top:.4rem">\
                  <label style="display:inline-flex;align-items:center;gap:.35rem"><input type="checkbox" class="uPolAllGroups" '+(gs.all?'checked':'')+'> دسترسی به همه گروه‌ها</label>\
                  <span class="hint">یا انتخاب گروه‌های مجاز:</span>\
                  <select class="uPolGroups ar-select" multiple size="4" style="min-width:240px;'+(gs.all?'opacity:.5;pointer-events:none;':'')+'">'+grpOpts+'</select>\
                </div>\
              </div>';
            }).join('');
            // wire all-groups toggles
            wrap.querySelectorAll('.card[data-role]').forEach(function(card){ var ag = card.querySelector('.uPolAllGroups'); var sel = card.querySelector('.uPolGroups'); if (ag){ ag.addEventListener('change', function(){ if (!sel) return; if (ag.checked){ sel.style.opacity = '.5'; sel.style.pointerEvents = 'none'; } else { sel.style.opacity = ''; sel.style.pointerEvents = ''; } }); } });
          }
          function collectPolicies(){ var out = { roles: {} }; if (!wrap) return out; wrap.querySelectorAll('.card[data-role]').forEach(function(card){ var rk = card.getAttribute('data-role'); var feats = {}; card.querySelectorAll('.uPolFeat').forEach(function(ch){ var key = ch.value; feats[key] = !!ch.checked; }); var allG = !!(card.querySelector('.uPolAllGroups')?.checked); var ids = []; if (!allG){ try { ids = Array.from(card.querySelector('.uPolGroups')?.selectedOptions||[]).map(function(o){ return parseInt(o.value)||0; }).filter(Boolean); } catch(_){ ids=[]; } } out.roles[rk] = { features: feats, group_scope: { all: allG, ids: ids } }; }); return out; }
          function savePolicies(){ var payload = { policies: collectPolicies() }; fetch(ARSHLINE_REST + 'roles/policies', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('سیاست‌ها ذخیره شد', 'success'); }).catch(function(e){ if (String(e&&e.message||'').indexOf('403')>=0){ notify('فقط مدیر سایت می‌تواند سیاست‌ها را ویرایش کند.', 'warn'); } else { notify('ذخیره سیاست‌ها ناموفق بود', 'error'); } }); }
          if (saveBtn) { saveBtn.onclick = savePolicies; }
          loadAll().then(function(pol){ renderPol(pol); }).catch(function(e){ if (wrap) wrap.innerHTML = '<div class="hint">دسترسی به ویرایش سیاست‌ها ندارید.</div>'; });
        }
      } else if (tab === 'settings'){
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
                  <span class="hint">تحلیلگر</span><select id="gsAiParser" class="ar-select"><option value="internal">هوشیار داخلی</option><option value="hybrid">هیبرید (پیش‌فرض)</option><option value="llm">OpenAI LLM</option></select>\
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
        (function(){ try { var btns = content.querySelectorAll('[data-s-tab]'); function show(which){ ['Security','AI','Users'].forEach(function(k){ var el = document.getElementById('arS_'+k); if (el) el.style.display = (k.toLowerCase()===which)?'block':'none'; }); btns.forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-s-tab')===which); }); } btns.forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-s-tab')); }); }); show('security'); } catch(_){ } })();
        fetch(ARSHLINE_REST + 'settings', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
          .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
          .then(function(resp){ var s = resp && resp.settings ? resp.settings : {}; try { var hp=document.getElementById('gsHoneypot'); if(hp) hp.checked=!!s.anti_spam_honeypot; var ms=document.getElementById('gsMinSec'); if(ms) ms.value=String(s.min_submit_seconds||0); var rpm=document.getElementById('gsRatePerMin'); if(rpm) rpm.value=String(s.rate_limit_per_min||0); var rwin=document.getElementById('gsRateWindow'); if(rwin) rwin.value=String(s.rate_limit_window_min||1); var ce=document.getElementById('gsCaptchaEnabled'); if(ce) ce.checked=!!s.captcha_enabled; var cs=document.getElementById('gsCaptchaSite'); if(cs) cs.value=s.captcha_site_key||''; var ck=document.getElementById('gsCaptchaSecret'); if(ck) ck.value=s.captcha_secret_key||''; var cv=document.getElementById('gsCaptchaVersion'); if(cv) cv.value=s.captcha_version||'v2'; var uk=document.getElementById('gsUploadKB'); if(uk) uk.value=String(s.upload_max_kb||300); var bsvg=document.getElementById('gsBlockSvg'); if(bsvg) bsvg.checked=(s.block_svg !== false); var aiE=document.getElementById('gsAiEnabled'); if(aiE) aiE.checked=!!s.ai_enabled; var aiT=document.getElementById('gsAiThreshold'); if(aiT) aiT.value=String((typeof s.ai_spam_threshold==='number'?s.ai_spam_threshold:0.5)); function updC(){ var en = !!(ce && ce.checked); if (cs) cs.disabled=!en; if (ck) ck.disabled=!en; if (cv) cv.disabled=!en; } updC(); if (ce) ce.addEventListener('change', updC); } catch(_){ } })
          .then(function(){ return fetch(ARSHLINE_REST + 'ai/config', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(resp){ try { var c = resp && resp.config ? resp.config : {}; var bu=document.getElementById('gsAiBaseUrl'); if (bu) bu.value = c.base_url || ''; var mo=document.getElementById('gsAiModel'); if (mo) mo.value = c.model || 'gpt-4o-mini'; var pa=document.getElementById('gsAiParser'); if (pa) pa.value = c.parser || 'hybrid'; var ak=document.getElementById('gsAiApiKey'); if (ak) ak.value = c.api_key || ''; } catch(_){ } }); })
          .catch(function(){ notify('خطا در بارگذاری تنظیمات سراسری', 'error'); });
        function putSettings(part){ return fetch(ARSHLINE_REST + 'settings', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ settings: part }) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }); }
        function putAiConfig(cfg){ return fetch(ARSHLINE_REST + 'ai/config', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ config: cfg }) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }); }
        var saveSec=document.getElementById('gsSaveSecurity'); if (saveSec){ saveSec.addEventListener('click', function(){ var payload = { anti_spam_honeypot: !!(document.getElementById('gsHoneypot')?.checked), min_submit_seconds: Math.max(0, parseInt(document.getElementById('gsMinSec')?.value||'0')||0), rate_limit_per_min: Math.max(0, parseInt(document.getElementById('gsRatePerMin')?.value||'0')||0), rate_limit_window_min: Math.max(1, parseInt(document.getElementById('gsRateWindow')?.value||'1')||1), captcha_enabled: !!(document.getElementById('gsCaptchaEnabled')?.checked), captcha_site_key: String(document.getElementById('gsCaptchaSite')?.value||''), captcha_secret_key: String(document.getElementById('gsCaptchaSecret')?.value||''), captcha_version: String(document.getElementById('gsCaptchaVersion')?.value||'v2'), upload_max_kb: Math.max(50, Math.min(4096, parseInt(document.getElementById('gsUploadKB')?.value||'300')||300)), block_svg: !!(document.getElementById('gsBlockSvg')?.checked) }; putSettings(payload).then(function(){ notify('تنظیمات امنیت ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره تنظیمات امنیت ناموفق بود', 'error'); }); }); }
  var saveAI=document.getElementById('gsSaveAI'); if (saveAI){ saveAI.addEventListener('click', function(){ var ai_enabled = !!(document.getElementById('gsAiEnabled')?.checked); var payload = { ai_enabled: ai_enabled, ai_spam_threshold: Math.max(0, Math.min(1, parseFloat(document.getElementById('gsAiThreshold')?.value||'0.5')||0.5)) }; var cfg = { enabled: ai_enabled, base_url: String(document.getElementById('gsAiBaseUrl')?.value||''), api_key: String(document.getElementById('gsAiApiKey')?.value||''), model: String(document.getElementById('gsAiModel')?.value||''), parser: String(document.getElementById('gsAiParser')?.value||'hybrid') }; putSettings(payload).then(function(){ return putAiConfig(cfg); }).then(function(){ notify('تنظیمات هوش مصنوعی ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره تنظیمات هوش مصنوعی ناموفق بود', 'error'); }); }); }
        var testBtn=document.getElementById('gsAiTest'); if (testBtn){ testBtn.addEventListener('click', function(){ fetch(ARSHLINE_REST + 'ai/test', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return { ok:r.ok, status:r.status, body:j }; }); }).then(function(res){ if (res.body && res.body.ok){ notify('اتصال موفق بود (HTTP '+(res.body.status||res.status)+')', 'success'); } else { notify('اتصال ناموفق بود', 'error'); } }).catch(function(){ notify('خطا در تست اتصال', 'error'); }); }); }
  var runBtn = document.getElementById('aiAgentRun'); if (runBtn){ var runAgentSettings=function(){ var cmdEl = document.getElementById('aiAgentCmd'); var outEl = document.getElementById('aiAgentOut'); var cmd = (cmdEl && cmdEl.value) ? String(cmdEl.value) : ''; if (!cmd){ notify('دستور خالی است', 'warn'); return; } if (cmdEl){ try { cmdEl.value=''; } catch(_){ } } fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ command: cmd }) }).then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return { ok:r.ok, status:r.status, body:j }; }); }).then(function(res){ outEl && (outEl.textContent = JSON.stringify(res.body||{}, null, 2)); if (res.ok && res.body && res.body.ok){ notify('انجام شد', 'success'); } else { notify('اجرا ناموفق بود', 'error'); } }).catch(function(){ notify('خطا در اجرای دستور', 'error'); }); }; runBtn.addEventListener('click', runAgentSettings); var cmdElS = document.getElementById('aiAgentCmd'); if (cmdElS){ cmdElS.addEventListener('keydown', function(e){ if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); runAgentSettings(); } }); } }
      } else {
        // default
        renderTab('dashboard');
      }
    }

    // begin extracted functions copied (with minor adjustments) from template
    
    function renderFormResults(formId){
      var content = document.getElementById('arshlineDashboardContent');
      if (!content) return;
      var REST_DEBUG = false;
      try { REST_DEBUG = (localStorage.getItem('arshRestDebug') === '1') || (localStorage.getItem('arshDebug') === '1'); } catch(_){ }
      // Persist route
      try { setHash('results/'+formId); } catch(_){ }
      content.innerHTML = '<div class="card glass" style="padding:1rem;">\
        <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;flex-wrap:wrap;">\
          <button id="arResultsBack" class="ar-btn ar-btn--muted">بازگشت</button>\
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
      // Back button wiring: go back to Forms and keep sidebar state consistent
      try {
        var backBtn = document.getElementById('arResultsBack');
        if (backBtn){ backBtn.addEventListener('click', function(){ try { if (typeof setHash==='function') setHash('forms'); else { location.hash = '#forms'; } } catch(_){ } arRenderTab('forms'); }); }
      } catch(_){ }
      try { if (typeof setActive === 'function') setActive('forms'); } catch(_){ }
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
      try {
        var pref = localStorage.getItem('arWrap:'+formId);
        if (wrapToggle) { wrapToggle.checked = (pref === '1'); }
        var container = document.querySelector('.arshline-main');
        if (container){ container.classList.remove('ar-wrap','ar-nowrap'); container.classList.add((wrapToggle && wrapToggle.checked) ? 'ar-wrap' : 'ar-nowrap'); }
      } catch(_){ }
      var fieldMeta = { choices: {}, labels: {}, types: {}, options: {} };
      function buildQuery(){
        var p = new URLSearchParams();
        p.set('page', String(state.page||1)); p.set('per_page', String(state.per_page||10));
        var fid = (selField && parseInt(selField.value||'0'))||0;
        var vv = (inpVal && inpVal.value.trim())||'';
        var op = (selOp && selOp.value)||'like';
        if (fid>0 && vv){ p.set('f['+fid+']', vv); if (op && op!=='like') p.set('op['+fid+']', op); }
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
            rr += path; u.searchParams.set('rest_route', rr);
          } else {
            if (u.pathname && u.pathname.charAt(u.pathname.length-1) !== '/') u.pathname += '/';
            u.pathname += path;
          }
          if (qs){ var extra = new URLSearchParams(qs); extra.forEach(function(v,k){ u.searchParams.set(k, v); }); }
          return u.toString();
        } catch(_) { return (ARSHLINE_REST||'') + path + (qs? ('?'+qs) : ''); }
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
        try {
          var savedOrder = [];
          try { savedOrder = JSON.parse(localStorage.getItem('arColsOrder:'+formId) || '[]'); } catch(_){ savedOrder = []; }
          if (Array.isArray(savedOrder) && savedOrder.length){
            var filtered = savedOrder.map(function(x){ return parseInt(x); }).filter(function(fid){ return fieldOrder.indexOf(fid) >= 0; });
            if (filtered.length){ fieldOrder = filtered.concat(fieldOrder.filter(function(fid){ return filtered.indexOf(fid) < 0; })); }
          }
        } catch(_){ }
        fieldMeta.choices = choices; fieldMeta.labels = fieldLabels; fieldMeta.types = typesMap; fieldMeta.options = optionsMap;
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
        try {
          var container = document.querySelector('.arshline-main');
          container.classList.remove('ar-wrap','ar-nowrap');
          container.classList.add((wrapToggle && wrapToggle.checked) ? 'ar-wrap' : 'ar-nowrap');
        } catch(_){ }
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
            var sel = document.createElement('select'); sel.id = 'arFieldVal'; sel.className = 'ar-select'; sel.style.minWidth = '240px';
            sel.innerHTML = '<option value="">انتخاب مقدار...</option>' + fieldMeta.options[fid].map(function(o){ return '<option value="'+escapeAttr(String(o.value||''))+'">'+escapeHtml(String(o.label||o.value||''))+'</option>'; }).join(''); newEl = sel;
          } else {
            var inp = document.createElement('input'); inp.id = 'arFieldVal'; inp.className = 'ar-input'; inp.placeholder = 'مقدار فیلتر'; inp.style.minWidth = '240px'; newEl = inp;
          }
          if (prevValEl){ valWrap.replaceChild(newEl, prevValEl); }
          inpVal = newEl; try { if (current) newEl.value = current; } catch(_){ }
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
        fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': ARSHLINE_NONCE } })
          .then(async function(r){ var txt = ''; try { txt = await r.clone().text(); } catch(_){ } if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } var data; try { data = txt ? JSON.parse(txt) : await r.json(); } catch(e){ throw e; } return data; })
          .then(function(resp){ renderTable(resp); })
          .catch(function(err){ var msg = (err && (err.message||'')) || ''; list.innerHTML = '<div class="hint">خطا در بارگذاری پاسخ‌ها'+(msg?(' — '+escapeHtml(String(msg))):'')+'</div>'; });
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
        case 'rating': return 'star-outline';
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
            try { var last = arr[arr.length-1]; var lp = last && (last.props||last); if (lp && (lp.type||last.type)==='thank_you' && at >= arr.length) at = arr.length - 1; } catch(_){ }
            arr.splice(at, 0, edited);
          } else {
            // Preserve ID and update instead of creating duplicates
            if (idx >= 0 && idx < arr.length){
              try { var orig = arr[idx]; var origId = (orig && typeof orig.id !== 'undefined') ? orig.id : (orig && orig.props && typeof orig.props.id !== 'undefined' ? orig.props.id : undefined); if (typeof origId !== 'undefined') edited.id = origId; } catch(_){ }
              arr[idx] = edited;
            } else {
              // If index out of range, update last non-thank-you field
              var lastIdx = -1; for (var i = arr.length - 1; i >= 0; i--){ var pp = arr[i] && (arr[i].props || arr[i]); if ((pp && (pp.type||'') !== 'thank_you') || !pp){ lastIdx = i; break; } }
              if (lastIdx >= 0){ try { var orig2 = arr[lastIdx]; var orig2Id = (orig2 && typeof orig2.id !== 'undefined') ? orig2.id : (orig2 && orig2.props && typeof orig2.props.id !== 'undefined' ? orig2.props.id : undefined); if (typeof orig2Id !== 'undefined') edited.id = orig2Id; } catch(_){ }
                arr[lastIdx] = edited; }
              else { arr.push(edited); }
            }
          }
          // Deduplicate by ID (keep last occurrence), to prevent accidental duplicates
          try {
            var seenIds = new Set();
            var deduped = [];
            for (var di = arr.length - 1; di >= 0; di--) {
              var it = arr[di] || {};
              var pid = (typeof it.id !== 'undefined') ? it.id : (it.props && typeof it.props.id !== 'undefined' ? it.props.id : 0);
              var pidNum = parseInt(pid||'0');
              if (pidNum > 0) {
                if (seenIds.has(pidNum)) { continue; }
                seenIds.add(pidNum);
              }
              deduped.push(it);
            }
            deduped.reverse();
            if (deduped.length !== arr.length) { dlog('saveFields:dedup-applied', { before: arr.length, after: deduped.length }); }
            arr = deduped;
          } catch(_){ }
          dlog('saveFields:payload', arr);
          return fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) })
            .then(async function(r){ try { await r.clone().text(); } catch(_){ } return r; });
        })
        .then(async r=>{ if(!r.ok){ if (r.status===401){ if (typeof handle401 === 'function') handle401(); else notify('اجازهٔ انجام این عملیات را ندارید. لطفاً وارد شوید یا با مدیر تماس بگیرید.', 'error'); } let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
        .then(function(){
          notify('ذخیره شد', 'success');
          try {
            var b = document.getElementById('arBuilder');
            var idStr = b ? (b.getAttribute('data-form-id') || '0') : '0';
            var idNum = parseInt(idStr);
            if (!isNaN(idNum) && idNum > 0){ renderFormBuilder(idNum); }
          } catch(_) { }
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
      fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
        .then(function(){ return fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }); })
        .then(r=>r.json())
        .then(function(data){
          var fwrap = document.getElementById('arFormPreviewFields');
          var qNum = 0; var questionProps = [];
          (data.fields||[]).forEach(function(f){
            var p = f.props || f; var type = p.type || f.type || 'short_text';
            if (type === 'welcome' || type === 'thank_you'){
              var block = document.createElement('div'); block.className = 'card glass'; block.style.cssText = 'padding:.8rem;';
              var heading = (p.heading && String(p.heading).trim()) || (type==='welcome'?'پیام خوش‌آمد':'پیام تشکر');
              var message = (p.message && String(p.message).trim()) || '';
              var img = (p.image_url && String(p.image_url).trim()) ? ('<div style="margin-bottom:.4rem"><img src="'+String(p.image_url).trim()+'" alt="" style="max-width:100%;border-radius:10px"/></div>') : '';
              block.innerHTML = (heading ? ('<div class="title" style="margin-bottom:.35rem;">'+heading+'</div>') : '') + img + (message ? ('<div class="hint">'+escapeHtml(message)+'</div>') : ''); fwrap.appendChild(block); return; }
            var fmt = p.format || 'free_text'; var attrs = inputAttrsByFormat(fmt); var phS = p.placeholder && p.placeholder.trim() ? p.placeholder : suggestPlaceholder(fmt);
            var row = document.createElement('div'); var inputId = 'f_'+(f.id||Math.random().toString(36).slice(2)); var descId = inputId+'_desc'; var showQ = p.question && String(p.question).trim();
            var numbered = (p.numbered !== false); if (numbered) qNum += 1; var numberStr = numbered ? (qNum + '. ') : '';
            var sanitizedQ = sanitizeQuestionHtml(showQ || ''); var ariaQ = htmlToText(sanitizedQ || 'پرسش بدون عنوان'); var qDisplayHtml = sanitizedQ || 'پرسش بدون عنوان';
            var questionBlock = '<div class="hint" style="margin-bottom:.25rem">'+ (numbered ? (numberStr + qDisplayHtml) : qDisplayHtml) +'</div>';
            if (type === 'long_text'){
              row.innerHTML = questionBlock + '<textarea id="'+inputId+'" class="ar-input" style="width:100%" rows="4" placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'"></textarea>' + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
            } else if (type === 'multiple_choice' || type === 'multiple-choice') {
              var opts = p.options || []; var vertical = (p.vertical !== false); var multiple = !!p.multiple;
              var html = '<div style="display:flex;flex-direction:'+(vertical?'column':'row')+';gap:.5rem;flex-wrap:wrap">';
              opts.forEach(function(o, i){ var lbl = sanitizeQuestionHtml(o.label||''); var sec = o.second_label?('<div class="hint" style="font-size:.8rem">'+escapeHtml(o.second_label)+'</div>') : ''; html += '<label style="display:flex;align-items:center;gap:.5rem;"><input type="'+(multiple?'checkbox':'radio')+'" name="mc_'+(f.id||i)+'" value="'+escapeAttr(o.value||'')+'" /> <span>'+lbl+'</span> '+sec+'</label>'; });
              html += '</div>';
              row.innerHTML = questionBlock + html + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
            } else if (type === 'dropdown') {
              var dOpts = (p.options || []).slice(); if (p.alpha_sort){ dOpts.sort(function(a,b){ return String(a.label||'').localeCompare(String(b.label||''), 'fa'); }); }
              if (p.randomize){ for (var z=dOpts.length-1; z>0; z--){ var j=Math.floor(Math.random()*(z+1)); var tmp=dOpts[z]; dOpts[z]=dOpts[j]; dOpts[j]=tmp; } }
              var selHtml = '<select id="'+inputId+'" class="ar-input" style="width:100%" data-field-id="'+f.id+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'">';
              selHtml += '<option value="">'+escapeHtml(p.placeholder || 'انتخاب کنید')+'</option>';
              dOpts.forEach(function(o){ selHtml += '<option value="'+escapeAttr(o.value||'')+'">'+escapeHtml(o.label||'')+'</option>'; }); selHtml += '</select>';
              row.innerHTML = questionBlock + selHtml + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
            } else if (type === 'rating') {
              var count = parseInt(p.max||5); if (isNaN(count) || count<1) count=1; if (count>20) count=20; var key = String(p.icon||'star');
              function mapIcon(k){ switch(k){ case 'heart': return { solid:'heart', outline:'heart-outline' }; case 'thumb': return { solid:'thumbs-up', outline:'thumbs-up-outline' }; case 'medal': return { solid:'ribbon', outline:'ribbon-outline' }; case 'smile': return { solid:'happy', outline:'happy-outline' }; case 'sad': return { solid:'sad', outline:'sad-outline' }; default: return { solid:'star', outline:'star-outline' }; } }
              var names = mapIcon(key); var icons = ''; for (var ri=1; ri<=count; ri++){ icons += '<span class="ar-rating-icon" data-value="'+ri+'" style="cursor:pointer;font-size:1.5rem;color:var(--muted);display:inline-flex;align-items:center;justify-content:center;margin-inline-start:.15rem;"><ion-icon name="'+names.outline+'"></ion-icon></span>'; }
              var ratingHtml = '<div class="ar-rating-wrap" data-icon-solid="'+names.solid+'" data-icon-outline="'+names.outline+'" data-field-id="'+f.id+'" role="radiogroup" aria-label="امتیاز" style="display:flex;align-items:center;gap:.1rem;">'+icons+'</div>' + '<input type="hidden" id="'+inputId+'" data-field-id="'+f.id+'" value="" />';
              row.innerHTML = questionBlock + ratingHtml + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
            } else {
              row.innerHTML = questionBlock + '<input id="'+inputId+'" class="ar-input" style="width:100%" '+(attrs.type?('type="'+attrs.type+'"'):'')+' '+(attrs.inputmode?('inputmode="'+attrs.inputmode+'"'):'')+' '+(attrs.pattern?('pattern="'+attrs.pattern+'"'):'')+' placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'" />' + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
            }
            fwrap.appendChild(row); questionProps.push(p);
          });
          fwrap.querySelectorAll('input[data-field-id], textarea[data-field-id]').forEach(function(inp, idx){ var props = questionProps[idx] || {}; try { applyInputMask(inp, props); } catch(_){ } if ((props.format||'') === 'date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){ try { jQuery(inp).pDatepicker({ format: 'YYYY/MM/DD', initialValue: false }); } catch(e){} } });
          try { Array.from(fwrap.querySelectorAll('.ar-rating-wrap')).forEach(function(wrap){ var solid = wrap.getAttribute('data-icon-solid') || 'star'; var outline = wrap.getAttribute('data-icon-outline') || 'star-outline'; var hidden = wrap.nextElementSibling; var items = Array.from(wrap.querySelectorAll('.ar-rating-icon')); function update(v){ items.forEach(function(el, idx){ var ion = el.querySelector('ion-icon'); if (ion){ ion.setAttribute('name', idx < v ? solid : outline); } el.style.color = idx < v ? 'var(--primary)' : 'var(--muted)'; }); if (hidden) hidden.value = String(v||''); } items.forEach(function(el){ el.addEventListener('click', function(){ var v = parseInt(el.getAttribute('data-value')||'0')||0; update(v); }); el.setAttribute('tabindex','0'); el.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' '){ e.preventDefault(); var v = parseInt(el.getAttribute('data-value')||'0')||0; update(v); } }); }); update(0); }); } catch(_){ }
          document.getElementById('arPreviewSubmit').onclick = function(){ var vals = []; Array.from(fwrap.querySelectorAll('input[data-field-id], textarea[data-field-id]')).forEach(function(inp, idx){ var fid = parseInt(inp.getAttribute('data-field-id')||'0'); vals.push({ field_id: fid, value: inp.value||'' }); }); fetch(ARSHLINE_REST + 'forms/'+id+'/submissions', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ values: vals }) }).then(async r=>{ if (!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); }).then(function(){ notify('ارسال شد', 'success'); }).catch(function(){ notify('اعتبارسنجی/ارسال ناموفق بود', 'error'); }); };
          document.getElementById('arPreviewBack').onclick = function(){ document.body.classList.remove('preview-only'); try { var back = window._arBackTo; window._arBackTo = null; if (back && back.view === 'builder' && back.id){ renderFormBuilder(back.id); return; } if (back && back.view === 'editor' && back.id){ renderFormEditor(back.id, { index: back.index || 0 }); return; } } catch(_){ } arRenderTab('forms'); };
        });
    }

    function renderFormEditor(id, opts){
      dlog('renderFormEditor:start', { id: id, opts: opts });
      // Skip if this navigation is stale (user navigated elsewhere quickly)
      try {
        if (opts && typeof opts.navToken !== 'undefined'){
          if (typeof window._arNavToken === 'undefined' || window._arNavToken !== opts.navToken){
            dlog('renderFormEditor:stale-navToken-skip', { expected: window._arNavToken, got: opts.navToken });
            return;
          }
        }
      } catch(_){ }
      try { if (!opts || !opts.creating) { var pend = (typeof window !== 'undefined') ? window._arPendingEditor : null; if (pend && pend.id === id) { opts = Object.assign({}, opts||{}, pend); try { window._arPendingEditor = null; } catch(_){ } dlog('renderFormEditor:merged-pending-opts', opts); } } } catch(_){ }
      if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); arRenderTab('forms'); return; }
      try { setSidebarClosed(true, false); } catch(_){ }
      try { var idxHashRaw = (opts && typeof opts.index!=='undefined') ? opts.index : 0; var idxHash = parseInt(idxHashRaw); if (isNaN(idxHash)) idxHash = 0; if (!(opts && opts.creating)) { setHash('editor/'+id+'/'+idxHash); } } catch(_){ }
      document.body.classList.remove('preview-only');
      var content = document.getElementById('arshlineDashboardContent');
      var hiddenCanvas = '<div id="arCanvas" style="display:none"><div class="ar-item" data-props="{}"></div></div>';
      var fieldIndex = (opts && typeof opts.index !== 'undefined') ? parseInt(opts.index) : -1;
      // Neutral scaffold (avoid flashing short_text UI before actual tool editor renders)
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
            <div id="arSettingsInner"></div>\
          </div>\
          <div class="ar-preview" style="flex:1;">\
            <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>\
            <div id="arPreviewInner"></div>\
          </div>\
        </div>\
      </div>' + hiddenCanvas;
  document.getElementById('arEditorBack').onclick = function(){ dlog('arEditorBack:click'); try { window._arNavToken = undefined; } catch(_){ } renderFormBuilder(id); };
      var prevBtnE = document.getElementById('arEditorPreview'); if (prevBtnE) prevBtnE.onclick = function(){ try { window._arBackTo = { view: 'editor', id: id, index: fieldIndex }; } catch(_){ } renderFormPreview(id); };
      content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');
      var defaultProps = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
      var longTextDefaults = { type: 'long_text', label: 'پاسخ طولانی', format: 'free_text', required: false, show_description: false, description: '', placeholder: '', question: '', numbered: true, min_length: 0, max_length: 1000, media_upload: false };
      function processData(data){
          dlog('renderFormEditor:data-loaded', data && data.fields ? data.fields.length : 0);
          function setDirty(d){ try { window._arDirty = !!d; window.onbeforeunload = window._arDirty ? function(){ return 'تغییرات ذخیره‌نشده دارید.'; } : null; } catch(_){ } }
          var titleEl = document.getElementById('arEditorTitle'); var formTitle = (data && data.meta && data.meta.title) ? String(data.meta.title) : '';
          var creating = !!(opts && opts.creating);
          if (titleEl) titleEl.textContent = creating ? ('ایجاد فرم — ' + (formTitle||(' #'+id))) : ('ویرایش فرم #'+id + (formTitle?(' — ' + formTitle):''));
          var fields = data.fields || [];
          var idx = (opts && typeof opts.index !== 'undefined') ? parseInt(opts.index) : -1;
          var pendCtx = (typeof window !== 'undefined') ? window._arPendingEditor : null; var pendType = (pendCtx && pendCtx.id === id) ? (pendCtx.newType || null) : null; var newTypeFromOpts = (opts && opts.newType) ? String(opts.newType) : null; var newTypeEffective = newTypeFromOpts || pendType || null;
          if (creating){ var fi = parseInt(idx); if (isNaN(fi) || fi < 0){ var hasThankIdx = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type) === 'thank_you'; }); idx = (hasThankIdx !== -1) ? hasThankIdx : fields.length; } else { idx = fi; } }
          else { if (isNaN(idx) || idx < 0) idx = 0; else if (idx >= fields.length){ creating = true; var hasThankIdx2 = fields.findIndex(function(x){ var p=x.props||x; return (p.type||x.type) === 'thank_you'; }); idx = (hasThankIdx2 !== -1) ? hasThankIdx2 : fields.length; } }
          try { var b = document.getElementById('arBuilder'); if (b) { b.setAttribute('data-field-index', String(idx)); b.setAttribute('data-creating', creating ? '1' : '0'); if (creating && typeof opts.intendedInsert !== 'undefined') b.setAttribute('data-intended-insert', String(opts.intendedInsert)); if (creating && typeof opts.newType !== 'undefined') b.setAttribute('data-new-type', String(opts.newType)); } } catch(_){ }
          var newType = newTypeEffective;
          var typeDefaults = (newType && ARSH && ARSH.Tools && ARSH.Tools.getDefaults(newType)) || (newType === 'long_text' ? longTextDefaults : null) || (ARSH && ARSH.Tools && ARSH.Tools.getDefaults('short_text')) || defaultProps;
          var base = creating ? typeDefaults : (fields[idx] || typeDefaults);
          var field = base.props || base || defaultProps;
          if (creating && newType && (field.type||'') !== newType){ field.type = newType; }
          var fType = field.type || base.type || 'short_text';
          var sWrap = document.querySelector('.ar-settings'); var pWrap = document.querySelector('.ar-preview'); var mod = (window.ARSH && ARSH.Tools && ARSH.Tools.get(fType));
          if (mod && typeof mod.renderEditor === 'function'){
            try {
              var ctx = { id: id, idx: idx, fields: fields, wrappers: { settings: sWrap, preview: pWrap }, sanitizeQuestionHtml: sanitizeQuestionHtml, escapeHtml: escapeHtml, escapeAttr: escapeAttr, inputAttrsByFormat: inputAttrsByFormat, suggestPlaceholder: suggestPlaceholder, notify: notify, dlog: dlog, setDirty: setDirty, saveFields: saveFields, ARSHLINE_REST: ARSHLINE_REST, ARSHLINE_NONCE: ARSHLINE_NONCE, ARSHLINE_CAN_MANAGE: ARSHLINE_CAN_MANAGE };
              var handled = (mod.renderEditor.length >= 2) ? !!mod.renderEditor(field, ctx) : !!mod.renderEditor({ field: field, id: id, idx: idx, fields: fields, wrappers: { settings: sWrap, preview: pWrap }, sanitizeQuestionHtml: sanitizeQuestionHtml, escapeHtml: escapeHtml, escapeAttr: escapeAttr, inputAttrsByFormat: inputAttrsByFormat, suggestPlaceholder: suggestPlaceholder, notify: notify, dlog: dlog, setDirty: setDirty, saveFields: saveFields, restUrl: ARSHLINE_REST, restNonce: ARSHLINE_NONCE });
              if (handled) return;
            } catch(_){ }
          }
          // Fallback editor: only inject short_text UI when no module handles the type
          // Minimal short_text editor wiring
          try {
            var sWrap = document.querySelector('.ar-settings');
            var pWrap = document.querySelector('.ar-preview');
            if (sWrap) sWrap.querySelector('#arSettingsInner').innerHTML = '\
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
              </div>';
            if (pWrap) pWrap.querySelector('#arPreviewInner').innerHTML = '\
              <div id="pvWrap">\
                <div id="pvQuestion" class="hint" style="display:none;margin-bottom:.25rem"></div>\
                <div id="pvDesc" class="hint" style="display:none;margin-bottom:.35rem"></div>\
                <input id="pvInput" class="ar-input" style="width:100%" />\
                <div id="pvHelp" class="hint" style="display:none"></div>\
                <div id="pvErr" class="hint" style="color:#b91c1c;margin-top:.3rem"></div>\
              </div>';
          } catch(_){ }
          var sel = document.getElementById('fType'); var req = document.getElementById('fRequired'); var dTg = document.getElementById('fDescToggle'); var dTx = document.getElementById('fDescText'); var dWrap = document.getElementById('fDescWrap'); var help = document.getElementById('fHelp'); var qEl = document.getElementById('fQuestionRich'); var qBold = document.getElementById('fQBold'); var qItalic = document.getElementById('fQItalic'); var qUnder = document.getElementById('fQUnder'); var qColor = document.getElementById('fQColor'); var numEl = document.getElementById('fNumbered');
          function updateHiddenProps(p){ var el = document.querySelector('#arCanvas .ar-item'); if (el) el.setAttribute('data-props', JSON.stringify(p)); }
          function applyPreviewFrom(p){ var fmt = p.format || 'free_text'; var attrs = inputAttrsByFormat(fmt); var inp = document.getElementById('pvInput'); if (!inp) return; if (inp.tagName === 'INPUT'){ inp.setAttribute('type', (attrs && attrs.type) ? attrs.type : 'text'); if (attrs && attrs.inputmode) inp.setAttribute('inputmode', attrs.inputmode); else inp.removeAttribute('inputmode'); if (attrs && attrs.pattern) inp.setAttribute('pattern', attrs.pattern); else inp.removeAttribute('pattern'); } var ph = (p.placeholder && p.placeholder.trim()) ? p.placeholder : (fmt==='free_text' ? 'پاسخ را وارد کنید' : suggestPlaceholder(fmt)); try { inp.setAttribute('placeholder', ph || ''); } catch(_){ } var qNode = document.getElementById('pvQuestion'); if (qNode){ var showQ = (p.question && String(p.question).trim()); qNode.style.display = showQ ? 'block' : 'none'; var numPrefix = (p.numbered ? ('1. ') : ''); var sanitized = sanitizeQuestionHtml(showQ || ''); qNode.innerHTML = showQ ? (numPrefix + sanitized) : ''; } var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent = p.description || ''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; } try { applyInputMask(inp, p); } catch(_){ } }
          if (sel){ sel.value = field.format || 'free_text'; sel.addEventListener('change', function(){ field.format = sel.value || 'free_text'; var i=document.getElementById('pvInput'); if(i) i.value=''; updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          if (dTg){ dTg.checked = !!field.show_description; if (dWrap) { dWrap.style.display = field.show_description ? 'block' : 'none'; } dTg.addEventListener('change', function () { field.show_description = !!dTg.checked; if (dWrap) { dWrap.style.display = field.show_description ? 'block' : 'none'; } updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){ field.placeholder = help.value; updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          if (qEl){ qEl.innerHTML = sanitizeQuestionHtml(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          if (qBold){ qBold.addEventListener('click', function(e){ e.preventDefault(); try { document.execCommand('bold'); } catch(_){ } if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); } }); }
          if (qItalic){ qItalic.addEventListener('click', function(e){ e.preventDefault(); try { document.execCommand('italic'); } catch(_){ } if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); } }); }
          if (qUnder){ qUnder.addEventListener('click', function(e){ e.preventDefault(); try { document.execCommand('underline'); } catch(_){ } if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); } }); }
          if (qColor){ qColor.addEventListener('input', function(){ try { document.execCommand('foreColor', false, qColor.value); } catch(_){ } if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); } }); }
          if (numEl){ numEl.checked = field.numbered !== false; field.numbered = numEl.checked; numEl.addEventListener('change', function(){ field.numbered = !!numEl.checked; updateHiddenProps(field); applyPreviewFrom(field); setDirty(true); }); }
          applyPreviewFrom(field);
          var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ var ok = await saveFields(); if (ok){ setDirty(false); renderFormBuilder(id); } };
      }
      if (opts && opts.formData){
        dlog('renderFormEditor:using-provided-formData');
        try { processData(opts.formData); } catch(e){ cerror('renderFormEditor:processData failed with provided formData', e); }
      } else {
        dlog('renderFormEditor:fetching-form');
        fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
          .then(r=>r.json())
          .then(function(data){ processData(data); });
      }
    }

    function addNewField(formId, fieldType){
      dlog('addNewField:start', { formId: formId, fieldType: fieldType });
      // Prevent re-entrancy when clicking quickly
      try {
        if (window._arAddInFlight){ dlog('addNewField:busy-skip'); return; }
        window._arAddInFlight = true;
      } catch(_){ }
      function setToolsDisabled(dis){ try { var btns = document.querySelectorAll('#arToolsSide .ar-toolbtn'); btns.forEach(function(b){ b.disabled = !!dis; b.classList.toggle('is-loading', !!dis); }); } catch(_){ } }
      setToolsDisabled(true);
      // Monotonic navigation token to discard stale renders
      var navToken = Date.now();
      try { window._arNavToken = navToken; } catch(_){ }
      var ft = fieldType || 'short_text';
      fetch(ARSHLINE_REST + 'forms/'+formId, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
        .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
        .then(function(data){
          dlog('addNewField:loaded-existing-fields', (data&&data.fields)?data.fields.length:0);
          var arr = (data && data.fields) ? data.fields.slice() : [];
          var hasThank = arr.findIndex(function(x){ var p=x.props||x; return (p.type||x.type)==='thank_you'; }) !== -1;
          var insertAt = hasThank ? (arr.length - 1) : arr.length;
          if (insertAt < 0 || insertAt > arr.length) insertAt = arr.length;
          try { window._arPendingEditor = { id: formId, index: insertAt, creating: true, intendedInsert: insertAt, newType: ft, ts: Date.now() }; } catch(_){ }
          dlog('addNewField:passing-formData-to-editor');
          renderFormEditor(formId, { index: insertAt, creating: true, intendedInsert: insertAt, newType: ft, formData: data, navToken: navToken });
        })
        .catch(function(e){ cerror('addNewField:failed', e); notify('افزودن فیلد ناموفق بود', 'error'); })
        .finally(function(){ try { window._arAddInFlight = false; } catch(_){ } setToolsDisabled(false); });
    }

    function renderFormBuilder(id){
      dlog('renderFormBuilder:start', id);
      if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); arRenderTab('forms'); return; }
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
        <style>.ar-tabs .ar-btn.active{background:var(--primary, #eef2ff);border-color:var(--primary, #4338ca);color:#111827}</style>\
        <div class="ar-tabs" role="tablist" aria-label="Form Sections" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">\
          <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arFormFieldsList" data-tab="builder">ساخت</button>\
          <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arDesignPanel" data-tab="design">طراحی</button>\
          <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arSettingsPanel" data-tab="settings">تنظیمات</button>\
          <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arSharePanel" data-tab="share">ارسال</button>\
          <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arReportsPanel" data-tab="reports">گزارش</button>\
        </div>\
        <div style="display:flex;gap:1rem;align-items:flex-start;">\
          <div id="arFormSide" style="flex:1;">\
            <div id="arSectionTitle" class="title" style="margin-bottom:.6rem;">پیش‌نمایش فرم</div>\
            <div id="arBulkToolbar" style="display:flex;align-items:center;gap:.8rem;margin-bottom:.6rem;">\
              <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">\
                <input id="arSelectAll" type="checkbox" />\
                <span class="hint">انتخاب همه</span>\
              </label>\
              <button id="arBulkDelete" class="ar-btn" disabled>حذف انتخاب‌شده‌ها</button>\
            </div>\
            <div id="arFormFieldsList" style="display:flex;flex-direction:column;gap:.8rem;"></div>\
            <div id="arDesignPanel" style="display:none;">\
              <div class="card" style="padding:.8rem;">\
                <div class="field" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">\
                  <span class="hint">رنگ اصلی</span><input id="arDesignPrimary" type="color" />\
                  <span class="hint">پس‌زمینه</span><input id="arDesignBg" type="color" />\
                  <span class="hint">ظاهر</span><select id="arDesignTheme" class="ar-select"><option value="light">روشن</option><option value="dark">تاریک</option></select>\
                  <button id="arSaveDesign" class="ar-btn">ذخیره طراحی</button>\
                </div>\
              </div>\
            </div>\
            <div id="arSettingsPanel" style="display:none;">\
              <div class="card" style="padding:.8rem;display:flex;flex-direction:column;gap:.8rem;">\
                <div class="title" style="margin-bottom:.2rem;">تنظیمات فرم</div>\
                <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">\
                  <span class="hint">عنوان فرم</span>\
                  <input id="arFormTitle" class="ar-input" placeholder="عنوان" style="min-width:220px" />\
                  <button id="arSaveTitle" class="ar-btn">ذخیره عنوان</button>\
                </div>\
                <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">\
                  <span class="hint">وضعیت فرم</span>\
                  <select id="arFormStatus" class="ar-select"><option value="draft">پیش‌نویس</option><option value="published">منتشر شده (فعال)</option><option value="disabled">غیرفعال</option></select>\
                  <button id="arSaveStatus" class="ar-btn">ذخیره وضعیت</button>\
                  <span class="hint">لینک عمومی فقط در حالت «منتشر شده» فعال است.</span>\
                </div>\
                <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">\
                  <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;"><input type="checkbox" id="arSetHoneypot" /> <span>فعال‌سازی Honeypot (ضدربات ساده)</span></label>\
                </div>\
                <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">\
                  <span class="hint">حداقل زمان تکمیل فرم (ثانیه)</span><input id="arSetMinSec" type="number" min="0" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 5" />\
                </div>\
                <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">\
                  <span class="hint">محدودیت نرخ (ارسال در دقیقه)</span><input id="arSetRatePerMin" type="number" min="0" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 10" />\
                  <span class="hint">پنجره زمانی (دقیقه)</span><input id="arSetRateWindow" type="number" min="1" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 5" />\
                </div>\
                <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">\
                  <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;"><input type="checkbox" id="arSetCaptchaEnabled" /> <span>فعالسازی reCAPTCHA</span></label>\
                  <span class="hint">Site Key</span><input id="arSetCaptchaSite" type="text" class="ar-input" style="min-width:220px" />\
                  <span class="hint">Secret</span><input id="arSetCaptchaSecret" type="password" class="ar-input" style="min-width:220px" />\
                  <span class="hint">نسخه</span><select id="arSetCaptchaVersion" class="ar-select"><option value="v2">v2 (checkbox)</option><option value="v3">v3 (score)</option></select>\
                </div>\
                <div style="display:flex;gap:.5rem;">\
                  <button id="arSaveSettings" class="ar-btn">ذخیره تنظیمات</button>\
                </div>\
                <div class="hint">توجه: همه این قابلیت‌ها فلگ‌پذیر و ماژولارند و می‌توانید بر اساس هر فرم آن‌ها را فعال/غیرفعال کنید.</div>\
              </div>\
            </div>\
            <div id="arSharePanel" style="display:none;">\
              <div class="card" style="padding:.8rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">\
                <span class="hint">لینک عمومی فرم:</span><input id="arShareLink" class="ar-input" style="min-width:340px" readonly />\
                <button id="arCopyLink" class="ar-btn">کپی لینک</button>\
                <span id="arShareWarn" class="hint" style="color:#b91c1c;display:none;">برای اشتراک‌گذاری، فرم باید «منتشر شده» باشد.</span>\
              </div>\
            </div>\
            <div id="arReportsPanel" style="display:none;">\
              <div class="card" style="padding:.8rem;">\
                <div class="title" style="margin-bottom:.6rem;">ارسال‌ها</div>\
                <div id="arSubmissionsList" style="display:flex;flex-direction:column;gap:.5rem"></div>\
              </div>\
            </div>\
          </div>\
          <div id="arToolsSide" style="width:300px;flex:0 0 300px;border-inline-start:1px solid var(--border);padding-inline-start:1rem;">\
            <div class="title" style="margin-bottom:.6rem;">ابزارها</div>\
            <button id="arAddShortText" class="ar-btn ar-toolbtn" draggable="true">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('short_text')+'"></ion-icon></span>\
              <span>افزودن سؤال با پاسخ کوتاه</span>\
            </button>\
            <div style="height:.5rem"></div>\
            <button id="arAddLongText" class="ar-btn ar-toolbtn" draggable="true">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('long_text')+'"></ion-icon></span>\
              <span>افزودن سؤال با پاسخ طولانی</span>\
            </button>\
            <div style="height:.5rem"></div>\
            <button id="arAddMultipleChoice" class="ar-btn ar-toolbtn" draggable="true">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('multiple_choice')+'"></ion-icon></span>\
              <span>افزودن سؤال چندگزینه‌ای</span>\
            </button>\
            <div style="height:.5rem"></div>\
            <button id="arAddRating" class="ar-btn ar-toolbtn" draggable="true">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('rating')+'"></ion-icon></span>\
              <span>افزودن امتیازدهی</span>\
            </button>\
            <div style="height:.5rem"></div>\
            <button id="arAddDropdown" class="ar-btn ar-toolbtn" draggable="true">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('dropdown')+'"></ion-icon></span>\
              <span>افزودن لیست کشویی</span>\
            </button>\
            <div style="height:.5rem"></div>\
            <button id="arAddWelcome" class="ar-btn ar-toolbtn">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('welcome')+'"></ion-icon></span>\
              <span>افزودن پیام خوش‌آمد</span>\
            </button>\
            <div style="height:.5rem"></div>\
            <button id="arAddThank" class="ar-btn ar-toolbtn">\
              <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('thank_you')+'"></ion-icon></span>\
              <span>افزودن پیام تشکر</span>\
            </button>\
          </div>\
        </div>';
      try { var bPrev = document.getElementById('arBuilderPreview'); if (bPrev) bPrev.onclick = function(){ try { window._arBackTo = { view: 'builder', id: id }; } catch(_){ } renderFormPreview(id); }; var bBack = document.getElementById('arBuilderBack'); if (bBack) bBack.onclick = function(){ arRenderTab('forms'); }; } catch(_){ }
      fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
        .then(r=>r.json())
        .then(function(data){
          var list = document.getElementById('arFormFieldsList');
          try {
            var tabs = Array.from(content.querySelectorAll('.ar-tabs [data-tab]'));
            function showPanel(which){ var title = document.getElementById('arSectionTitle'); var panels = { builder: document.getElementById('arFormFieldsList'), design: document.getElementById('arDesignPanel'), settings: document.getElementById('arSettingsPanel'), share: document.getElementById('arSharePanel'), reports: document.getElementById('arReportsPanel'), }; Object.keys(panels).forEach(function(k){ panels[k].style.display = (k===which)?'block':'none'; }); document.getElementById('arBulkToolbar').style.display = (which==='builder')?'flex':'none'; var tools = document.getElementById('arToolsSide'); if (tools) tools.style.display = (which==='builder')?'block':'none'; title.textContent = (which==='builder'?'پیش‌نمایش فرم': which==='design'?'طراحی فرم': which==='settings'?'تنظیمات فرم': which==='share'?'ارسال/اشتراک‌گذاری': 'گزارشات فرم'); }
            function setActive(btn){ tabs.forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-selected','false'); }); btn.classList.add('active'); btn.setAttribute('aria-selected','true'); }
            tabs.forEach(function(btn, idx){ btn.setAttribute('tabindex', idx===0? '0' : '-1'); btn.addEventListener('click', function(){ setActive(btn); showPanel(btn.getAttribute('data-tab')); }); btn.addEventListener('keydown', function(e){ var i = tabs.indexOf(btn); if (e.key === 'ArrowRight' || e.key === 'ArrowLeft'){ e.preventDefault(); var ni = (e.key==='ArrowRight') ? (i+1) % tabs.length : (i-1+tabs.length) % tabs.length; tabs[ni].focus(); } if (e.key === 'Enter' || e.key === ' '){ e.preventDefault(); setActive(btn); showPanel(btn.getAttribute('data-tab')); } }); }); var def = content.querySelector('.ar-tabs [data-tab="builder"]'); if (def){ setActive(def); } showPanel('builder');
            var meta = data.meta || {}; var dPrim = document.getElementById('arDesignPrimary'); if (dPrim) dPrim.value = meta.design_primary || '#1e40af'; var dBg = document.getElementById('arDesignBg'); if (dBg) dBg.value = meta.design_bg || '#f5f7fb'; var dTheme = document.getElementById('arDesignTheme'); if (dTheme) dTheme.value = meta.design_theme || 'light';
            // Populate and wire Title editing
            try {
              var tInp = document.getElementById('arFormTitle'); if (tInp) tInp.value = (meta.title || '');
              var tBtn = document.getElementById('arSaveTitle');
              function applyHeaderTitle(newTitle){ try { var hdr = content.querySelector('.card .title'); if (hdr && hdr.textContent && hdr.textContent.indexOf('ویرایش فرم #'+id)===0){ hdr.textContent = 'ویرایش فرم #'+id + (newTitle?(' — '+newTitle):''); } } catch(_){} }
              if (tBtn && tInp){
                tBtn.onclick = function(){
                  var val = String(tInp.value||'').trim();
                  fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ meta: { title: val } }) })
                    .then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401==='function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); })
                    .then(function(){ notify('عنوان فرم ذخیره شد', 'success'); applyHeaderTitle(val); })
                    .catch(function(){ notify('ذخیره عنوان ناموفق بود', 'error'); });
                };
                // Enter key saves
                tInp.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); if (tBtn) tBtn.click(); } });
              }
            } catch(_){ }
            try { document.documentElement.style.setProperty('--ar-primary', dPrim.value); var side = document.getElementById('arFormSide'); if (side){ var isDark = document.body.classList.contains('dark'); side.style.background = isDark ? '' : (dBg.value || ''); } } catch(_){ }
            var saveD = document.getElementById('arSaveDesign'); if (saveD){ saveD.onclick = function(){ var payload = { meta: { design_primary: dPrim.value, design_bg: dBg.value, design_theme: dTheme.value } }; fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('طراحی ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره طراحی ناموفق بود', 'error'); }); } }
            try { var themeToggle = document.getElementById('arThemeToggle'); if (themeToggle){ themeToggle.addEventListener('click', function(){ try { var side = document.getElementById('arFormSide'); if (side){ var isDarkNow = document.body.classList.contains('dark'); side.style.background = isDarkNow ? '' : (dBg.value || ''); } } catch(_){ } }); } } catch(_){ }
            var stSel = document.getElementById('arFormStatus'); if (stSel){ try { stSel.value = String(data.status||'draft'); } catch(_){ } }
            var saveStatus = document.getElementById('arSaveStatus'); if (saveStatus && stSel){ saveStatus.onclick = function(){ var val = String(stSel.value||'draft'); fetch(ARSHLINE_REST+'forms/'+id, { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ status: val }) }).then(function(r){ if(!r.ok){ if (r.status===401){ if (typeof handle401==='function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(obj){ var ns = (obj&&obj.status)||val; notify('وضعیت فرم ذخیره شد: '+ns, 'success'); try { data.status = ns; if (ns === 'published'){ // Ensure public token exists then refresh Share UI
                    fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                      .catch(function(){ /* ignore */ })
                      .finally(function(){ fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                        .then(function(rr){ return rr.ok ? rr.json() : Promise.reject(new Error('HTTP '+rr.status)); })
                        .then(function(d2){ try { data.token = (d2 && d2.token) ? String(d2.token) : (data.token||''); } catch(_){ } try { updateShareUI && updateShareUI(); } catch(_){ } })
                        .catch(function(){ try { updateShareUI && updateShareUI(); } catch(_){ } }); });
                  } else { try { updateShareUI && updateShareUI(); } catch(_){ } } } catch(_){ } }).catch(function(){ notify('ذخیره وضعیت ناموفق بود', 'error'); }); } }

            // Initialize and wire per-form settings (honeypot, rate limits, captcha...)
            (function initFormSettings(){
              try {
                var meta = data.meta || {};
                var hp = document.getElementById('arSetHoneypot'); if (hp) hp.checked = !!meta.anti_spam_honeypot;
                var ms = document.getElementById('arSetMinSec'); if (ms) ms.value = (typeof meta.min_submit_seconds === 'number') ? String(meta.min_submit_seconds) : '';
                var rpm = document.getElementById('arSetRatePerMin'); if (rpm) rpm.value = (typeof meta.rate_limit_per_min === 'number') ? String(meta.rate_limit_per_min) : '';
                var rwin = document.getElementById('arSetRateWindow'); if (rwin) rwin.value = (typeof meta.rate_limit_window_min === 'number') ? String(meta.rate_limit_window_min) : '';
                var ce = document.getElementById('arSetCaptchaEnabled'); if (ce) ce.checked = !!meta.captcha_enabled;
                var cs = document.getElementById('arSetCaptchaSite'); if (cs) cs.value = meta.captcha_site_key || '';
                var ck = document.getElementById('arSetCaptchaSecret'); if (ck) ck.value = meta.captcha_secret_key || '';
                var cv = document.getElementById('arSetCaptchaVersion'); if (cv) cv.value = meta.captcha_version || 'v2';
                function updateCaptchaInputs(){ var enabled = !!(ce && ce.checked); if (cs) cs.disabled = !enabled; if (ck) ck.disabled = !enabled; if (cv) cv.disabled = !enabled; }
                updateCaptchaInputs(); if (ce) ce.addEventListener('change', updateCaptchaInputs);
                var saveS = document.getElementById('arSaveSettings');
                if (saveS){ saveS.onclick = function(){
                  // Consolidated save: title + status + meta in one go
                  var tInp = document.getElementById('arFormTitle');
                  var stSel = document.getElementById('arFormStatus');
                  var newTitle = tInp ? String(tInp.value||'').trim() : '';
                  var newStatus = stSel ? String(stSel.value||'draft') : 'draft';
                  var payloadMeta = {
                    anti_spam_honeypot: !!(hp && hp.checked),
                    min_submit_seconds: Math.max(0, parseInt((ms && ms.value)? ms.value : '0') || 0),
                    rate_limit_per_min: Math.max(0, parseInt((rpm && rpm.value)? rpm.value : '0') || 0),
                    rate_limit_window_min: Math.max(1, parseInt((rwin && rwin.value)? rwin.value : '1') || 1),
                    captcha_enabled: !!(ce && ce.checked),
                    captcha_site_key: (cs && cs.value) ? String(cs.value) : '',
                    captcha_secret_key: (ck && ck.value) ? String(ck.value) : '',
                    captcha_version: (cv && cv.value) ? String(cv.value) : 'v2',
                    title: newTitle
                  };
                  // Disable button during save
                  var oldText = saveS.textContent; saveS.disabled=true; saveS.textContent='در حال ذخیره...';
                  // Save status and meta sequentially to reuse existing endpoints
                  fetch(ARSHLINE_REST+'forms/'+id, { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ status: newStatus }) })
                    .then(function(r){ if(!r.ok){ if (r.status===401 && typeof handle401==='function') handle401(); throw new Error('HTTP '+r.status); } return r.json(); })
                    .then(function(obj){ try { data.status = (obj&&obj.status)||newStatus; } catch(_){ } return fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ meta: payloadMeta }) }); })
                    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                    .then(function(){
                      try { data.meta = Object.assign({}, data.meta||{}, payloadMeta); } catch(_){ }
                      // Update builder header title
                      try { var hdr = content.querySelector('.card .title'); if (hdr && hdr.textContent && hdr.textContent.indexOf('ویرایش فرم #'+id)===0){ hdr.textContent = 'ویرایش فرم #'+id + (newTitle?(' — '+newTitle):''); } } catch(_){ }
                      // If status is published, ensure token and refresh Share UI
                      if (String(data.status||'') === 'published'){
                        fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                          .catch(function(){ /* ignore */ })
                          .finally(function(){ fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
                            .then(function(rr){ return rr.ok ? rr.json() : Promise.reject(new Error('HTTP '+rr.status)); })
                            .then(function(d2){ try { data.token = (d2 && d2.token) ? String(d2.token) : (data.token||''); } catch(_){ } try { updateShareUI && updateShareUI(); } catch(_){ } notify('تنظیمات ذخیره شد', 'success'); })
                            .catch(function(){ try { updateShareUI && updateShareUI(); } catch(_){ } notify('تنظیمات ذخیره شد', 'success'); }); });
                      } else {
                        try { updateShareUI && updateShareUI(); } catch(_){ }
                        notify('تنظیمات ذخیره شد', 'success');
                      }
                    })
                    .catch(function(){ notify('ذخیره تنظیمات ناموفق بود', 'error'); })
                    .finally(function(){ saveS.disabled=false; saveS.textContent=oldText; });
                }; }
              } catch(_){ }
            })();

            // Share panel: compute public URL, update UI, copy button, and ensure token when entering
            var publicUrl = '';
            function computePublicUrl(){ try { var token = (data && data.token) ? String(data.token) : ''; var isPub = String(data.status||'') === 'published'; if (isPub && token){ if (window.ARSHLINE_DASHBOARD && ARSHLINE_DASHBOARD.publicTokenBase){ return ARSHLINE_DASHBOARD.publicTokenBase.replace('%TOKEN%', token); } return window.location.origin + '/?arshline=' + encodeURIComponent(token); } return ''; } catch(_){ return ''; } }
            function updateShareUI(){ try { publicUrl = computePublicUrl(); var shareLink = document.getElementById('arShareLink'); if (shareLink){ shareLink.value = publicUrl; shareLink.setAttribute('value', publicUrl); } var copyBtn = document.getElementById('arCopyLink'); if (copyBtn){ copyBtn.disabled = !publicUrl; } var shareWarn = document.getElementById('arShareWarn'); if (shareWarn){ shareWarn.style.display = publicUrl ? 'none' : 'inline'; } } catch(_){ } }
            updateShareUI();
            (function wireCopy(){ try { var copyBtn = document.getElementById('arCopyLink'); if (!copyBtn) return; function copyText(text){ if (navigator.clipboard && navigator.clipboard.writeText){ return navigator.clipboard.writeText(text); } return new Promise(function(res, rej){ try { var ta=document.createElement('textarea'); ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); var ok=document.execCommand('copy'); document.body.removeChild(ta); ok?res():rej(new Error('execCommand failed')); } catch(e){ rej(e); } }); } copyBtn.onclick = function(){ if (!publicUrl){ notify('ابتدا فرم را منتشر کنید', 'error'); return; } copyText(publicUrl).then(function(){ notify('کپی شد', 'success'); }).catch(function(){ notify('کپی ناموفق بود', 'error'); }); }; } catch(_){ } })();

            // Enhance tabs showPanel to refresh Share panel on entry and auto-ensure token
            try {
              var tabs = Array.from(content.querySelectorAll('.ar-tabs [data-tab]'));
              function showPanel(which){ var title = document.getElementById('arSectionTitle'); var panels = { builder: document.getElementById('arFormFieldsList'), design: document.getElementById('arDesignPanel'), settings: document.getElementById('arSettingsPanel'), share: document.getElementById('arSharePanel'), reports: document.getElementById('arReportsPanel') }; Object.keys(panels).forEach(function(k){ panels[k].style.display = (k===which)?'block':'none'; }); document.getElementById('arBulkToolbar').style.display = (which==='builder')?'flex':'none'; var tools = document.getElementById('arToolsSide'); if (tools) tools.style.display = (which==='builder')?'block':'none'; title.textContent = (which==='builder'?'پیش‌نمایش فرم': which==='design'?'طراحی فرم': which==='settings'?'تنظیمات فرم': which==='share'?'ارسال/اشتراک‌گذاری': 'گزارشات فرم'); if (which === 'share'){ try { var isPubNow = String(data.status||'') === 'published'; var hasTokNow = !!(data && data.token); if (isPubNow && !hasTokNow){ fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }) .catch(function(){ /* ignore */ }) .finally(function(){ fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }) .then(function(rr){ return rr.ok ? rr.json() : Promise.reject(new Error('HTTP '+rr.status)); }) .then(function(d2){ try { data.token = (d2 && d2.token) ? String(d2.token) : (data.token||''); } catch(_){ } updateShareUI(); }) .catch(function(){ updateShareUI(); }); }); } else { updateShareUI(); } var sl = document.getElementById('arShareLink'); if (sl && typeof publicUrl === 'string'){ sl.value = publicUrl; sl.setAttribute('value', publicUrl); } } catch(_){ } }
              }
              // Re-wire tab buttons to use the enhanced showPanel (maintain existing active state code)
              tabs.forEach(function(btn){ btn.onclick = (function(b){ return function(){ tabs.forEach(function(bb){ bb.classList.remove('active'); bb.setAttribute('aria-selected','false'); }); b.classList.add('active'); b.setAttribute('aria-selected','true'); showPanel(b.getAttribute('data-tab')); }; })(btn); });
            } catch(_){ }
          } catch(_){ }
          var fields = data.fields || []; var qCounter = 0; var visibleMap = []; var vIdx = 0;
          list.innerHTML = fields.map(function(f, i){ var p = f.props || f; var type = p.type || f.type || 'short_text'; if (type === 'welcome' || type === 'thank_you'){ var ttl = (type==='welcome') ? 'پیام خوش‌آمد' : 'پیام تشکر'; var head = (p.heading && String(p.heading).trim()) || ''; return '<div class="card" data-oid="'+i+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
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
          </div>'; }
            visibleMap[vIdx] = i; vIdx++; var q = (p.question&&p.question.trim()) || ''; var qHtml = q ? sanitizeQuestionHtml(q) : 'پرسش بدون عنوان'; var n = ''; if (p.numbered !== false) { qCounter += 1; n = qCounter + '. '; }
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
            </div>'; }).join('');
          // Minimal delete/edit binding
          list.querySelectorAll('.arEditField').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var idx = parseInt(a.getAttribute('data-index')||'0'); renderFormEditor(id, { index: idx }); }); });
          list.querySelectorAll('.arDeleteField').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var card = a.closest('.card'); if (!card) return; var oid = parseInt(card.getAttribute('data-oid')||''); if (isNaN(oid)) return; var p = fields[oid] && (fields[oid].props || fields[oid]); var ty = p && (p.type || fields[oid].type); if (ty === 'welcome' || ty === 'thank_you') return; var ok = window.confirm('از حذف این سؤال مطمئن هستید؟'); if (!ok) return; var newFields = fields.slice(); newFields.splice(oid, 1); fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: newFields }) }).then(function(r){ if(!r.ok){ if(r.status===401){ if (typeof handle401 === 'function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(){ notify('سؤال حذف شد', 'success'); renderFormBuilder(id); }).catch(function(){ notify('حذف سؤال ناموفق بود', 'error'); }); }); });

          // Delete for message blocks (welcome/thank_you)
          list.querySelectorAll('.arDeleteMsg').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var card = a.closest('.card'); if (!card) return; var oid = parseInt(card.getAttribute('data-oid')||''); if (isNaN(oid)) return; var newFields = fields.slice(); newFields.splice(oid, 1); fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: newFields }) }).then(function(r){ if(!r.ok){ if (r.status===401){ if (typeof handle401==='function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(){ notify('پیام حذف شد', 'success'); renderFormBuilder(id); }).catch(function(){ notify('حذف پیام ناموفق بود', 'error'); }); }); });

          // Wire tool buttons (add new fields)
          var btnShort = document.getElementById('arAddShortText'); if (btnShort) btnShort.addEventListener('click', function(){ addNewField(id, 'short_text'); });
          var btnLong = document.getElementById('arAddLongText'); if (btnLong) btnLong.addEventListener('click', function(){ addNewField(id, 'long_text'); });
          var btnMc = document.getElementById('arAddMultipleChoice'); if (btnMc) btnMc.addEventListener('click', function(){ addNewField(id, 'multiple_choice'); });
          var btnDd = document.getElementById('arAddDropdown'); if (btnDd) btnDd.addEventListener('click', function(){ addNewField(id, 'dropdown'); });
          var btnRating = document.getElementById('arAddRating'); if (btnRating) btnRating.addEventListener('click', function(){ addNewField(id, 'rating'); });
          var btnWelcome = document.getElementById('arAddWelcome'); if (btnWelcome) btnWelcome.addEventListener('click', function(){ addNewField(id, 'welcome'); });
          var btnThank = document.getElementById('arAddThank'); if (btnThank) btnThank.addEventListener('click', function(){ addNewField(id, 'thank_you'); });

          // --- Drag & Drop for reorder and drop-to-create ---
          (function initDnd(){
            if (!list) return;
            // Inject minimal styles once
            try {
              if (!document.getElementById('arDndStyles')){
                var st = document.createElement('style'); st.id = 'arDndStyles';
                st.textContent = '.ar-draggable.dragging{opacity:.5} .ar-drop-marker{min-height:48px;border:2px dashed var(--primary,#2563eb);border-radius:10px;background:color-mix(in oklab, var(--primary,#2563eb) 10%, transparent);margin:.3rem 0;transition:all .08s ease} .ar-toolbtn.is-loading{opacity:.6;pointer-events:none}';
                document.head.appendChild(st);
              }
            } catch(_){ }
            var state = { dragging: false, type: null, // 'existing' | 'tool'
                          srcOid: -1, toolType: '', marker: null, overIndex: -1, navFormId: id,
                          ghostEl: null };
            function ensureMarker(){ if (!state.marker){ var m = document.createElement('div'); m.className='ar-drop-marker'; state.marker = m; } return state.marker; }
            function removeMarker(){ if (state.marker && state.marker.parentNode){ try { state.marker.parentNode.removeChild(state.marker); } catch(_){ } } state.marker = null; state.overIndex = -1; }
            function cards(){ return Array.from(list.children).filter(function(el){ return el.classList && el.classList.contains('card'); }); }
            function computeInsertIndex(clientY){ var els = cards(); if (!els.length) return 0; for (var i=0;i<els.length;i++){ var r = els[i].getBoundingClientRect(); var mid = r.top + r.height/2; if (clientY < mid) return i; } return els.length; }
            function placeMarkerAt(idx){
              var els = cards(); var m = ensureMarker();
              if (idx <= 0){ if (els[0]) list.insertBefore(m, els[0]); else list.appendChild(m); }
              else if (idx >= els.length){ list.appendChild(m); }
              else { list.insertBefore(m, els[idx]); }
              // Match height with neighbor card to create a rectangular placeholder
              try {
                var ref = (idx < els.length ? els[idx] : els[els.length-1]);
                var rh = ref ? Math.max(48, Math.round(ref.getBoundingClientRect().height)) : 48;
                m.style.minHeight = rh + 'px';
              } catch(_){ m.style.minHeight = '48px'; }
              state.overIndex = idx;
            }
            function makeGhost(label){ try { var g=document.createElement('div'); g.style.cssText='position:fixed;top:-9999px;left:-9999px;padding:.3rem .5rem;background:rgba(0,0,0,.7);color:#fff;border-radius:6px;font-size:12px;z-index:99999;'; g.textContent=label; document.body.appendChild(g); state.ghostEl=g; return g; } catch(_){ return null; } }
            function clearGhost(){ if (state.ghostEl){ try { state.ghostEl.remove(); } catch(_){ } state.ghostEl=null; } }

            // Existing card drag
            list.addEventListener('dragstart', function(ev){ var card = ev.target.closest('.ar-draggable'); if (!card) return; var oid = parseInt(card.getAttribute('data-oid')||'-1'); if (isNaN(oid)) return; state.dragging=true; state.type='existing'; state.srcOid=oid; try { card.classList.add('dragging'); } catch(_){ } try { ev.dataTransfer.effectAllowed='move'; ev.dataTransfer.setData('text/plain', 'reorder:'+oid); } catch(_){ }
              var gh = makeGhost('جابجایی سؤال #'+(oid+1)); if (gh && ev.dataTransfer && ev.dataTransfer.setDragImage){ try { ev.dataTransfer.setDragImage(gh, 10, 10); } catch(_){ } }
            });
            list.addEventListener('dragenter', function(ev){
              // Allow drop and show marker even if state was lost; detect via dataTransfer
              var dt = ev.dataTransfer; var hint = '';
              try { hint = (dt && dt.getData && dt.getData('text/plain')) || ''; } catch(_){ hint=''; }
              if (!state.dragging && hint && (hint.indexOf('tool:')===0 || hint.indexOf('reorder:')===0)){
                state.dragging = true; state.type = hint.indexOf('tool:')===0 ? 'tool' : 'existing';
              }
              ev.preventDefault(); var idx = computeInsertIndex(ev.clientY); placeMarkerAt(idx);
            });
            list.addEventListener('dragover', function(ev){
              var dt = ev.dataTransfer; var hint = '';
              try { hint = (dt && dt.getData && dt.getData('text/plain')) || ''; } catch(_){ hint=''; }
              if (!state.dragging && hint && (hint.indexOf('tool:')===0 || hint.indexOf('reorder:')===0)){
                state.dragging = true; state.type = hint.indexOf('tool:')===0 ? 'tool' : 'existing';
              }
              if (!state.dragging) return; ev.preventDefault(); var idx = computeInsertIndex(ev.clientY); placeMarkerAt(idx); try { ev.dataTransfer.dropEffect='move'; } catch(_){ }
            });
            list.addEventListener('dragleave', function(ev){ /* optional: if leaving entire list */ });
            list.addEventListener('drop', function(ev){
              // Support drops even if state was lost by checking dataTransfer
              var dt = ev.dataTransfer; var hint = '';
              try { hint = (dt && dt.getData && dt.getData('text/plain')) || ''; } catch(_){ hint=''; }
              if (!state.dragging && hint){ if (hint.indexOf('tool:')===0){ state.dragging=true; state.type='tool'; state.toolType=hint.slice(5); } else if (hint.indexOf('reorder:')===0){ state.dragging=true; state.type='existing'; state.srcOid=parseInt(hint.slice(8)||'-1'); } }
              if (!state.dragging) return; ev.preventDefault(); ev.stopPropagation(); var idx = state.overIndex; removeMarker(); clearGhost();
              if (state.type === 'existing'){
                if (idx < 0) return; // nothing
                var src = state.srcOid; if (isNaN(src) || src<0) return;
                // Build new order from current fields
                var arr = (data && data.fields) ? data.fields.slice() : [];
                // Resolve current DOM order to field indices
                var domEls = cards(); var domOrder = domEls.map(function(el){ return parseInt(el.getAttribute('data-oid')||'-1'); });
                // Compute target OID position in the sequence
                var targetBeforeOid = (idx < domOrder.length) ? domOrder[idx] : -1; // -1 means append
                // Remove src first from arr and domOrder
                var moving = arr.splice(src,1)[0];
                // Adjust domOrder since we removed src
                var srcPos = domOrder.indexOf(src); if (srcPos>=0) domOrder.splice(srcPos,1);
                // Determine insertion position in arr in terms of OID
                var insertAt;
                if (targetBeforeOid === -1){ insertAt = arr.length; }
                else {
                  // Insert before the field with OID targetBeforeOid
                  insertAt = targetBeforeOid; // OID equals original index; but after removal, indices shifted when src < targetBeforeOid
                  if (src < targetBeforeOid) insertAt = Math.max(0, insertAt - 1);
                }
                // Enforce thank_you last
                try { var last = arr[arr.length-1]; var lp = last && (last.props||last); var isLastThank = lp && ((lp.type||last.type)==='thank_you'); if (isLastThank && insertAt > arr.length-1) insertAt = arr.length-1; var movingP = moving && (moving.props||moving); if ((movingP && (movingP.type||moving.type)==='thank_you')) insertAt = arr.length; } catch(_){ }
                arr.splice(insertAt, 0, moving);
                dlog('dnd:reorder', { from: src, to: insertAt });
                fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) })
                  .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                  .then(function(){ notify('ترتیب ذخیره شد', 'success'); renderFormBuilder(id); })
                  .catch(function(){ notify('ذخیره ترتیب ناموفق بود', 'error'); });
              } else if (state.type === 'tool'){
                var t = state.toolType || ''; if (!t) return; var insertIdx = (typeof idx==='number' && idx>=0) ? idx : cards().length; dlog('dnd:drop-tool', { type: t, insert: insertIdx });
                // Use addNewFieldAt to respect drop index (in terms of visible DOM order)
                addNewFieldAt(id, t, insertIdx);
              }
              state.dragging=false; state.type=null; state.srcOid=-1; state.toolType='';
            });
            list.addEventListener('dragend', function(){ removeMarker(); clearGhost(); state.dragging=false; state.type=null; try { list.querySelectorAll('.ar-draggable.dragging').forEach(function(el){ el.classList.remove('dragging'); }); } catch(_){ } });

            // Tool buttons drag
            function wireToolDrag(btn, toolType){ if (!btn) return; btn.setAttribute('draggable','true'); btn.addEventListener('dragstart', function(ev){ state.dragging=true; state.type='tool'; state.toolType=toolType; try { ev.dataTransfer.effectAllowed='copyMove'; ev.dataTransfer.setData('text/plain', 'tool:'+toolType); } catch(_){ } var gh = makeGhost('افزودن '+(btn.textContent||'ابزار')); if (gh && ev.dataTransfer && ev.dataTransfer.setDragImage){ try { ev.dataTransfer.setDragImage(gh, 10, 10); } catch(_){ } } }); btn.addEventListener('dragend', function(){ removeMarker(); clearGhost(); state.dragging=false; state.type=null; state.toolType=''; }); }
            wireToolDrag(btnShort, 'short_text'); wireToolDrag(btnLong, 'long_text'); wireToolDrag(btnMc, 'multiple_choice'); wireToolDrag(btnDd, 'dropdown'); wireToolDrag(btnRating, 'rating'); wireToolDrag(btnWelcome, 'welcome'); wireToolDrag(btnThank, 'thank_you');

            // Add helper: add new field at specific drop index (DOM index => field index)
            function addNewFieldAt(formId, fieldType, domInsertIndex){
              // Map domInsertIndex to array insert position considering last thank_you
              var arr = (data && data.fields) ? data.fields.slice() : [];
              // If dropping beyond last and last is thank_you, place before last
              var insertAt = domInsertIndex;
              if (insertAt > arr.length) insertAt = arr.length;
              try { var last = arr[arr.length-1]; var lp = last && (last.props||last); if (lp && (lp.type||last.type)==='thank_you' && insertAt >= arr.length) insertAt = arr.length - 1; } catch(_){ }
              // Persist intended insert into pending editor and open editor
              try { window._arPendingEditor = { id: formId, index: insertAt, creating: true, intendedInsert: insertAt, newType: fieldType, ts: Date.now() }; } catch(_){ }
              // Prepare a fresh nav token so editor render is not skipped
              var navToken = Date.now();
              try { window._arNavToken = navToken; } catch(_){ }
              // Pass in current form data to avoid extra fetch
              renderFormEditor(formId, { index: insertAt, creating: true, intendedInsert: insertAt, newType: fieldType, formData: { meta: data.meta, fields: arr, status: data.status }, navToken: navToken });
            }
          })();
        });
    }

    // Expose functions globally for compatibility and plug into router
    try {
  window.renderFormResults = renderFormResults;
      window.renderFormPreview = renderFormPreview;
      window.renderFormEditor = renderFormEditor;
      window.renderFormBuilder = renderFormBuilder;
  // Expose Users/UG renderer so central router can invoke it
  try { window.renderUsersUG = renderUsersUG; } catch(_){ }
      window.addNewField = addNewField;
      window.saveFields = saveFields;
      // Expose main tab renderer and initialize router once
      window.renderTab = renderTab;
      // Now that renderTab/renderForm* are attached, initialize the central router (FULL mode) once
      if (window.ARSH_ROUTER){
        try { window.ARSH_ROUTER.arRenderTab = renderTab; } catch(_){ }
        if (!window._arRouterBooted && typeof window.ARSH_ROUTER.init === 'function'){
          try {
            // If no explicit hash on load, restore last route before initializing router
            if (!location.hash || location.hash === '#dashboard'){
              try {
                var lastR = localStorage.getItem('arshLastRoute') || localStorage.getItem('arshLastTab') || '';
                if (lastR && lastR !== 'dashboard'){ setHash(lastR); }
              } catch(_){ }
            }
            window._arRouterBooted = true; window.ARSH_ROUTER.init(); dlog('router:init');
          } catch(_){ }
        } else { dlog('router:init-skipped'); }
      } else {
        // Fallback: simple initial render if router is not present
        try {
          var initial = (location.hash||'').replace('#','').trim();
          if (!initial){ try { initial = localStorage.getItem('arshLastRoute') || localStorage.getItem('arshLastTab') || 'dashboard'; } catch(_){ initial = 'dashboard'; } }
          // If a nested route (e.g., users/ug?tab=members), drive via hash router; else direct tab render
          if (initial.indexOf('/') >= 0 || initial.indexOf('?') >= 0){ setHash(initial); routeFromHash(); }
          else { renderTab(initial); }
        } catch(_){ }
      }
      // Signal to template to skip inline controller block to avoid duplication
      window.ARSH_CTRL_EXTERNAL = true;
      window.ARSH_CTRL_PARTIAL = true;
    } catch(_){ }

  }); // end DOMContentLoaded
})(); // end IIFE

/* =========================================================================
   FILE: assets/js/dashboard-controller.js
   Purpose: Orchestrates dashboard behavior: sidebar routing/tabs, theme &
            sidebar toggles, results list, form builder, and preview flows.
   Dependencies: runtime-config, tools-registry, external tool modules,
                 assets/js/dashboard.js
   Exports: attaches renderFormBuilder/renderFormEditor/renderFormPreview to window
   ========================================================================= */
(function(){
  // Tabs: render content per menu item
  document.addEventListener('DOMContentLoaded', function() {
    var content = document.getElementById('arshlineDashboardContent');
    var links = document.querySelectorAll('.arshline-sidebar nav a[data-tab]');
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
    function routeFromHash(){
      var raw = (location.hash||'').replace('#','').trim();
      if (!raw){ renderTab('dashboard'); return; }
      var parts = raw.split('/');
      if (parts[0]==='submissions'){ renderTab('forms'); return; }
      if (['dashboard','forms','reports','users'].includes(parts[0])){ renderTab(parts[0]); return; }
      if (parts[0]==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { dlog('route:builder', id); renderFormBuilder(id); return; } }
      if (parts[0]==='editor' && parts[1]){ var id2 = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); dlog('route:editor', { id:id2, idx:idx, parts:parts }); if (id2) { renderFormEditor(id2, { index: isNaN(idx)?0:idx }); return; } }
      if (parts[0]==='preview' && parts[1]){ var id3 = parseInt(parts[1]||'0'); if (id3) { renderFormPreview(id3); return; } }
      if (parts[0]==='results' && parts[1]){ var id4 = parseInt(parts[1]||'0'); if (id4) { renderFormResults(id4); return; } }
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

    function setActive(tab){ links.forEach(function(a){ if (a.getAttribute('data-tab') === tab) a.classList.add('active'); else a.classList.remove('active'); }); }
    function getTypeIcon(type){ switch(type){ case 'short_text': return 'create-outline'; case 'long_text': return 'newspaper-outline'; case 'multiple_choice': case 'multiple-choice': return 'list-outline'; case 'dropdown': return 'chevron-down-outline'; case 'welcome': return 'happy-outline'; case 'thank_you': return 'checkmark-done-outline'; default: return 'help-circle-outline'; } }
    function getTypeLabel(type){ switch(type){ case 'short_text': return 'پاسخ کوتاه'; case 'long_text': return 'پاسخ طولانی'; case 'multiple_choice': case 'multiple-choice': return 'چندگزینه‌ای'; case 'dropdown': return 'لیست کشویی'; case 'welcome': return 'پیام خوش‌آمد'; case 'thank_you': return 'پیام تشکر'; default: return 'نامشخص'; } }
    function card(title, subtitle, icon){ var ic = icon ? ('<span style="font-size:22px;margin-inline-start:.4rem;opacity:.85">'+icon+'</span>') : ''; return '<div class="card glass" style="display:flex;align-items:center;gap:.6rem;">'+ic+'<div><div class="title">'+title+'</div><div class="hint">'+(subtitle||'')+'</div></div></div>'; }

    // begin extracted functions copied verbatim from template
    
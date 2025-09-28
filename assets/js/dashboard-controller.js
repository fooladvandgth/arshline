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
      if (!raw){ arRenderTab('dashboard'); return; }
      var parts = raw.split('/');
      if (parts[0]==='submissions'){ arRenderTab('forms'); return; }
      if (['dashboard','forms','reports','users'].includes(parts[0])){ arRenderTab(parts[0]); return; }
      if (parts[0]==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { dlog('route:builder', id); renderFormBuilder(id); return; } }
      if (parts[0]==='editor' && parts[1]){ var id2 = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); dlog('route:editor', { id:id2, idx:idx, parts:parts }); if (id2) { renderFormEditor(id2, { index: isNaN(idx)?0:idx }); return; } }
      if (parts[0]==='preview' && parts[1]){ var id3 = parseInt(parts[1]||'0'); if (id3) { renderFormPreview(id3); return; } }
      if (parts[0]==='results' && parts[1]){ var id4 = parseInt(parts[1]||'0'); if (id4) { renderFormResults(id4); return; } }
      arRenderTab('dashboard');
    }
    var AR_FULL = !!(window && window.ARSH_CTRL_FULL);
    if (AR_FULL){
      window.addEventListener('hashchange', function(){ if (_arNavSilence>0) return; routeFromHash(); });
    }

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

    function setActive(tab){ links.forEach(function(a){ if (a.getAttribute('data-tab') === tab) a.classList.add('active'); else a.classList.remove('active'); }); }
    function getTypeIcon(type){ switch(type){ case 'short_text': return 'create-outline'; case 'long_text': return 'newspaper-outline'; case 'multiple_choice': case 'multiple-choice': return 'list-outline'; case 'dropdown': return 'chevron-down-outline'; case 'welcome': return 'happy-outline'; case 'thank_you': return 'checkmark-done-outline'; default: return 'help-circle-outline'; } }
    function getTypeLabel(type){ switch(type){ case 'short_text': return 'پاسخ کوتاه'; case 'long_text': return 'پاسخ طولانی'; case 'multiple_choice': case 'multiple-choice': return 'چندگزینه‌ای'; case 'dropdown': return 'لیست کشویی'; case 'welcome': return 'پیام خوش‌آمد'; case 'thank_you': return 'پیام تشکر'; default: return 'نامشخص'; } }
    function card(title, subtitle, icon){ var ic = icon ? ('<span style="font-size:22px;margin-inline-start:.4rem;opacity:.85">'+icon+'</span>') : ''; return '<div class="card glass" style="display:flex;align-items:center;gap:.6rem;">'+ic+'<div><div class="title">'+title+'</div><div class="hint">'+(subtitle||'')+'</div></div></div>'; }

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
            if (idx >=0 && idx < arr.length) { arr[idx] = edited; }
            else { arr.push(edited); }
          }
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
      try { if (!opts || !opts.creating) { var pend = (typeof window !== 'undefined') ? window._arPendingEditor : null; if (pend && pend.id === id) { opts = Object.assign({}, opts||{}, pend); try { window._arPendingEditor = null; } catch(_){ } dlog('renderFormEditor:merged-pending-opts', opts); } } } catch(_){ }
      if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); arRenderTab('forms'); return; }
      try { setSidebarClosed(true, false); } catch(_){ }
      try { var idxHashRaw = (opts && typeof opts.index!=='undefined') ? opts.index : 0; var idxHash = parseInt(idxHashRaw); if (isNaN(idxHash)) idxHash = 0; if (!(opts && opts.creating)) { setHash('editor/'+id+'/'+idxHash); } } catch(_){ }
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
      var longTextDefaults = { type: 'long_text', label: 'پاسخ طولانی', format: 'free_text', required: false, show_description: false, description: '', placeholder: '', question: '', numbered: true, min_length: 0, max_length: 1000, media_upload: false };
      fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
        .then(r=>r.json())
        .then(function(data){
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
          // Fallback: short_text/long_text/welcome/thank_you editors are handled as in template
          // For brevity, reuse defaults path by invoking the appropriate tool module if available; otherwise, proceed with short_text editor wiring as minimal viable behavior.
          // Minimal short_text editor wiring
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
          try { window._arPendingEditor = { id: formId, index: insertAt, creating: true, intendedInsert: insertAt, newType: ft, ts: Date.now() }; } catch(_){ }
          renderFormEditor(formId, { index: insertAt, creating: true, intendedInsert: insertAt, newType: ft });
        })
        .catch(function(){ notify('افزودن فیلد ناموفق بود', 'error'); });
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
            try { document.documentElement.style.setProperty('--ar-primary', dPrim.value); var side = document.getElementById('arFormSide'); if (side){ var isDark = document.body.classList.contains('dark'); side.style.background = isDark ? '' : (dBg.value || ''); } } catch(_){ }
            var saveD = document.getElementById('arSaveDesign'); if (saveD){ saveD.onclick = function(){ var payload = { meta: { design_primary: dPrim.value, design_bg: dBg.value, design_theme: dTheme.value } }; fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('طراحی ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره طراحی ناموفق بود', 'error'); }); } }
            try { var themeToggle = document.getElementById('arThemeToggle'); if (themeToggle){ themeToggle.addEventListener('click', function(){ try { var side = document.getElementById('arFormSide'); if (side){ var isDarkNow = document.body.classList.contains('dark'); side.style.background = isDarkNow ? '' : (dBg.value || ''); } } catch(_){ } }); } } catch(_){ }
            var stSel = document.getElementById('arFormStatus'); if (stSel){ try { stSel.value = String(data.status||'draft'); } catch(_){ } }
            var saveStatus = document.getElementById('arSaveStatus'); if (saveStatus && stSel){ saveStatus.onclick = function(){ var val = String(stSel.value||'draft'); fetch(ARSHLINE_REST+'forms/'+id, { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ status: val }) }).then(function(r){ if(!r.ok){ if (r.status===401){ if (typeof handle401==='function') handle401(); } throw new Error('HTTP '+r.status); } return r.json(); }).then(function(obj){ var ns = (obj&&obj.status)||val; notify('وضعیت فرم ذخیره شد: '+ns, 'success'); }).catch(function(){ notify('ذخیره وضعیت ناموفق بود', 'error'); }); } }
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
        });
    }

    // Expose functions globally for compatibility
    try {
      window.renderFormResults = renderFormResults;
      window.renderFormPreview = renderFormPreview;
      window.renderFormEditor = renderFormEditor;
      window.renderFormBuilder = renderFormBuilder;
      window.addNewField = addNewField;
      window.saveFields = saveFields;
      // Signal to template to skip inline controller block to avoid duplication
      window.ARSH_CTRL_EXTERNAL = true;
      window.ARSH_CTRL_PARTIAL = true;
    } catch(_){ }

  }); // end DOMContentLoaded
})(); // end IIFE

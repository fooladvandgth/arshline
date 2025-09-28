/* =========================================================================
   FILE: assets/js/ui/ai-terminal.js
   Purpose: Floating AI terminal UI wiring + backend calls
   Dependencies: runtime-config (ARSHLINE_REST, ARSHLINE_NONCE), notify()
   Exports: window.ARSH_AI { open(), run(cmd) }
   Guards: window.ARSH_AI_INIT to prevent double init
   ========================================================================= */
(function(){
  if (typeof window === 'undefined') return;
  if (window.ARSH_AI_INIT) {
    try { console.debug('[ARSH][AI] already initialized'); } catch(_){}
    return;
  }
  window.ARSH_AI_INIT = true;
  document.addEventListener('DOMContentLoaded', function(){
    try { console.debug('[ARSH][AI] init DOMContentLoaded'); } catch(_){}
    var fab = document.getElementById('arAiFab');
    var panel = document.getElementById('arAiPanel');
    var closeBtn = document.getElementById('arAiClose');
    var runBtn = document.getElementById('arAiRun');
    var clearBtn = document.getElementById('arAiClear');
    var cmdEl = document.getElementById('arAiCmd');
  var outEl = document.getElementById('arAiOut');
  var undoBtn = document.getElementById('arAiUndo');
  // UI action stack for client-side undo (non-destructive UI ops)
  var uiStack = []; // items: {type, payload, undo: fn}
  var lastServerUndoToken = '';

    if (!panel || !outEl) {
      try { console.warn('[ARSH][AI] panel elements not found; skipping bind'); } catch(_){ }
      return;
    }

    var lastActiveEl = null;
    function setOpen(b){
      if (!panel) return;
      var open = !!b;
      if (open){
        // Restore interactivity and show
        try { panel.classList.add('open'); panel.setAttribute('aria-hidden','false'); panel.removeAttribute('inert'); } catch(_){ }
        // Remember current focus and move focus inside the panel
        try { lastActiveEl = document.activeElement; } catch(_){ lastActiveEl = null; }
        if (cmdEl) { try { cmdEl.focus(); } catch(_){ } }
      } else {
        // Hide and mark inert to keep AT consistent
        try { panel.classList.remove('open'); panel.setAttribute('aria-hidden','true'); panel.setAttribute('inert',''); } catch(_){ }
        // If focus remained inside the panel, move it out (prefer FAB, else last active, else blur)
        try {
          var ae = document.activeElement;
          if (ae && panel.contains(ae)){
            if (fab && typeof fab.focus==='function') { fab.focus(); }
            else if (lastActiveEl && document.contains(lastActiveEl) && typeof lastActiveEl.focus==='function') { lastActiveEl.focus(); }
            else if (typeof ae.blur==='function') { ae.blur(); }
          }
        } catch(_){ }
      }
      try { sessionStorage.setItem('arAiOpen', open? '1':'0'); } catch(_){ }
      try { console.debug('[ARSH][AI] setOpen', open); } catch(_){ }
    }
  if (fab) fab.addEventListener('click', function(){ var isOpen = panel.classList.contains('open'); setOpen(!isOpen); });
    if (closeBtn) closeBtn.addEventListener('click', function(){ setOpen(false); });
  // Allow ESC to close and move focus out safely
  try { document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && panel && panel.classList.contains('open')) { e.preventDefault(); setOpen(false); } }); } catch(_){ }
    if (clearBtn) clearBtn.addEventListener('click', function(){ if(outEl) outEl.textContent=''; if(cmdEl) cmdEl.value=''; try { sessionStorage.removeItem('arAiHist'); } catch(_){ } });

    function appendOut(o){
      if (!outEl) return;
      try {
        var old = outEl.textContent || '';
        var s = (typeof o==='string')? o : JSON.stringify(o, null, 2);
        outEl.textContent = (old? (old+"\n\n") : '') + s;
        outEl.scrollTop = outEl.scrollHeight;
      } catch(_){ }
    }
  function logConsole(action, detail){ try { console.log('[HOSHYAR][UNDO]', action, detail||''); } catch(_){ } }
    function attachUndoUI(token){
      try {
        if (!token || !outEl) return;
        var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
        var lab = document.createElement('span'); lab.textContent='قابل بازگشت'; lab.className='ar-badge'; lab.style.marginInlineEnd='.5rem';
        var btn = document.createElement('button'); btn.className='ar-btn ar-btn--soft'; btn.textContent='بازگردانی';
        btn.addEventListener('click', async function(){
          try {
            btn.disabled = true; btn.textContent='در حال بازگردانی…';
            var r = await fetch(buildRest('ai/undo'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: String(token) }) });
            var t = ''; try { t = await r.clone().text(); } catch(_){ }
            var j = null; try { j = t ? JSON.parse(t) : await r.json(); } catch(_){ }
            appendOut(j || (t || ('HTTP '+r.status)));
            if (r.ok && j && j.ok){ notify('بازگردانی انجام شد', 'success'); try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } }
            else { notify('بازگردانی ناموفق بود', 'error'); }
          } catch(e){ appendOut(String(e)); notify('خطا در بازگردانی', 'error'); }
          finally { btn.disabled = false; btn.textContent='بازگردانی'; }
        });
        wrap.appendChild(lab); wrap.appendChild(btn);
        outEl.appendChild(wrap);
      } catch(_){ }
    }
    function saveHist(cmd, res){ try { var h = []; try { h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); } catch(_){ h = []; } h.push({ t: Date.now(), cmd: String(cmd||''), res: res }); h = h.slice(-20); sessionStorage.setItem('arAiHist', JSON.stringify(h)); } catch(_){ } }
    function loadHist(){ try { var h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); if (Array.isArray(h) && h.length && outEl){ outEl.textContent = h.map(function(x){ return '> '+(x.cmd||'')+'\n'+JSON.stringify(x.res||{}, null, 2); }).join('\n\n'); } } catch(_){ } }
    loadHist();

    function buildRest(path, qs){
      try {
        var base = ARSHLINE_REST || '';
        if (!qs) return base + path;
        var hasQ = base.indexOf('?') >= 0;
        var sep = hasQ ? '&' : '?';
        if (typeof qs === 'string') return base + path + sep + qs;
        var parts = [];
        for (var k in qs){ if (Object.prototype.hasOwnProperty.call(qs,k)) { parts.push(encodeURIComponent(k)+'='+encodeURIComponent(String(qs[k]))); } }
        return base + path + (parts.length ? (sep + parts.join('&')) : '');
      } catch(_){ return (ARSHLINE_REST||'') + path; }
    }

    function handleAgentAction(j){
      try {
        if (!j) return;
        if (j.action === 'confirm' && j.confirm_action){
          var msg = String(j.message||'تایید می‌کنید؟');
          appendOut({ confirm: msg, params: j.confirm_action });
          try {
            var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
            var yes = document.createElement('button'); yes.className='ar-btn'; yes.textContent='تایید';
            var no = document.createElement('button'); no.className='ar-btn ar-btn--outline'; no.textContent='انصراف'; no.style.marginInlineStart='.5rem';
            yes.addEventListener('click', async function(){
              try {
                var r2 = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ confirm_action: j.confirm_action }) });
                var txt2 = ''; try { txt2 = await r2.clone().text(); } catch(_){ }
                var j2 = null; try { j2 = txt2 ? JSON.parse(txt2) : await r2.json(); } catch(_){ }
                appendOut(j2 || (txt2 || ('HTTP '+r2.status)));
                if (j2 && j2.undo_token) { attachUndoUI(j2.undo_token); }
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
        if (j.action === 'clarify' && j.kind === 'options' && Array.isArray(j.options)){
          appendOut({ clarify: String(j.message||'مبهم است'), options: j.options });
          try {
            var wrap2 = document.createElement('div'); wrap2.style.marginTop='.5rem';
            (j.options||[]).forEach(function(opt){
              var b = document.createElement('button'); b.className='ar-btn'; b.textContent=String(opt.label||opt.value); b.style.marginInlineEnd='.5rem';
              b.addEventListener('click', async function(){
                if (j.clarify_action){
                  const ca = j.clarify_action; const pa = {}; pa[j.param_key] = opt.value;
                  var r3 = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ confirm_action: { action: ca.action, params: pa } }) });
                  var t3 = ''; try { t3 = await r3.clone().text(); } catch(_){ }
                  var j3 = null; try { j3 = t3 ? JSON.parse(t3) : await r3.json(); } catch(_){ }
                  appendOut(j3 || (t3 || ('HTTP '+r3.status)));
                  if (j3 && j3.undo_token) { attachUndoUI(j3.undo_token); }
                  if (r3.ok && j3 && j3.ok !== false){ handleAgentAction(j3); notify('انجام شد', 'success'); } else { notify('انجام نشد', 'error'); }
                }
              });
              wrap2.appendChild(b);
            });
            if (outEl) outEl.appendChild(wrap2);
          } catch(_){ }
          return;
        }
        if (j.action === 'help' && j.capabilities){ appendOut({ capabilities: j.capabilities }); return; }
        if (j.action === 'ui' && j.target === 'toggle_theme'){
          try {
            var t = document.getElementById('arThemeToggle');
            var prev = t ? (t.getAttribute('aria-checked')==='true') : null;
            if (t) t.click();
            // push undo for theme toggle
            uiStack.push({ type:'toggle_theme', payload:{ prev: prev }, undo:function(){ if (t) t.click(); } });
            logConsole('UI action', 'toggle_theme');
          } catch(_){ }
          return;
        }
        if (j.action === 'open_tab' && j.tab){
          try {
            var prevHash = location.hash;
            var nextTab = String(j.tab);
            if (typeof window.setHash === 'function') { try { setHash(nextTab); } catch(_){ } } else { try { location.hash = '#' + nextTab; } catch(_){ } }
            if (typeof window.renderTab === 'function') window.renderTab(nextTab); else if (typeof window.arRenderTab === 'function') window.arRenderTab(nextTab);
            // push undo: go back to previous tab (ensure render happens)
            uiStack.push({ type:'open_tab', payload:{ prevHash: prevHash }, undo:function(){ try { var prevTab = (prevHash||'').replace(/^#/, '').split('/')[0] || 'dashboard'; if (typeof window.setHash==='function') setHash(prevTab); else { try { location.hash = '#' + prevTab; } catch(_){ } } if (typeof window.renderTab==='function') window.renderTab(prevTab); } catch(_){ } } });
            logConsole('UI action', { open_tab: j.tab });
          } catch(_){ }
          return;
        }
        if (j.action === 'open_builder' && j.id){
          try {
            var prevHash2 = location.hash;
            var builderHash = 'builder/'+parseInt(j.id);
            if (typeof window.setHash==='function') setHash(builderHash); else { try { location.hash = '#' + builderHash; } catch(_){ } }
            if (typeof window.renderTab==='function') window.renderTab('forms');
            uiStack.push({ type:'open_builder', payload:{ prevHash: prevHash2 }, undo:function(){ try { var prevTab2 = (prevHash2||'').replace(/^#/, '').split('/')[0] || 'dashboard'; if (typeof window.setHash==='function') setHash(prevTab2); else { try { location.hash = '#' + prevTab2; } catch(_){ } } if (typeof window.renderTab==='function') window.renderTab(prevTab2); } catch(_){ } } });
            logConsole('UI action', { open_builder: j.id });
          } catch(_){ }
          return;
        }
        if ((j.action === 'download' || j.action === 'export') && j.url){ try { window.open(String(j.url), '_blank'); } catch(_){ } return; }
        if (j.url && !j.action){ try { window.open(String(j.url), '_blank'); } catch(_){ } return; }
      } catch(_){ }
    }

    async function runAgent(cmdOverride){
      var cmd = (typeof cmdOverride === 'string' && cmdOverride.trim()) ? cmdOverride.trim() : ((cmdEl && cmdEl.value) ? String(cmdEl.value) : '');
      if (!cmd){ notify('دستور خالی است', 'warn'); return; }
      appendOut('> '+cmd);
      try {
  var r = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ command: cmd }) });
        var txt = ''; try { txt = await r.clone().text(); } catch(_){ }
        var j = null; try { j = txt ? JSON.parse(txt) : await r.json(); } catch(_){ }
        appendOut(j || (txt || ('HTTP '+r.status)));
        saveHist(cmd, j || txt || {});
        if (r.ok && j && j.ok !== false){ handleAgentAction(j); notify('انجام شد', 'success'); }
        else { notify('اجرا ناموفق بود', 'error'); }
        if (j && j.undo_token){ lastServerUndoToken = String(j.undo_token||''); attachUndoUI(j.undo_token); logConsole('Server undo token', lastServerUndoToken); }
      } catch(e){ appendOut(String(e)); notify('خطا در اجرای دستور', 'error'); }
    }

    if (runBtn) runBtn.addEventListener('click', runAgent);
  if (undoBtn) undoBtn.addEventListener('click', async function(){
      // Prefer client-side UI undo; if none, fallback to server undo token; else list recent audit
      try {
        if (uiStack.length){
          var item = uiStack.pop();
          if (item && typeof item.undo === 'function'){ item.undo(); notify('بازگردانی UI انجام شد', 'success'); logConsole('UI undo', item.type); return; }
        }
        if (lastServerUndoToken){
          try {
            var r = await fetch(buildRest('ai/undo'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: lastServerUndoToken }) });
            var t = ''; try { t = await r.clone().text(); } catch(_){ }
            var j = null; try { j = t ? JSON.parse(t) : await r.json(); } catch(_){ }
            appendOut(j || (t || ('HTTP '+r.status)));
            if (r.ok && j && j.ok){ notify('بازگردانی انجام شد', 'success'); logConsole('Server undo', lastServerUndoToken); lastServerUndoToken=''; try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } return; }
          } catch(e){ appendOut(String(e)); }
        }
        // Fallback: show last 10 actions
        appendOut('> فهرست بازگردانی‌های اخیر');
        var rr = await fetch(buildRest('ai/audit', 'limit=10'), { method:'GET', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} });
        var tt = ''; try { tt = await rr.clone().text(); } catch(_){ }
        var jj = null; try { jj = tt ? JSON.parse(tt) : await rr.json(); } catch(_){ }
        appendOut(jj || (tt || ('HTTP '+rr.status)));
      } catch(e){ appendOut(String(e)); }
    });
    if (cmdEl) cmdEl.addEventListener('keydown', function(e){ if (e.key==='Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); runAgent(); }});
    try { if ((sessionStorage.getItem('arAiOpen')||'')==='1') setOpen(true); } catch(_){ }

    // Public API
    try {
      var api = {
        open: function(){ setOpen(true); },
        run: function(cmd){ if (cmdEl) { cmdEl.value = String(cmd||''); } runAgent(); },
        undo: function(token){ if (token) { lastServerUndoToken = String(token); } attachUndoUI(String(token||'')); }
      };
      // Backward-compat and new modular alias
      window.ARSH_AI = api;
      window.HOSHYAR = api;
      console.debug('[ARSH][AI] ready');
    } catch(_){ }
  });
})();

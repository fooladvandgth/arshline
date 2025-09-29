/* =========================================================================
   FILE: assets/js/ui/ai-terminal.js
   Purpose: Floating AI terminal UI wiring + backend calls
   Dependencies: runtime-config (ARSHLINE_REST, ARSHLINE_NONCE), notify()
   Exports: window.ARSH_AI { open(), run(cmd), undo(token) }
   Guards: window.ARSH_AI_INIT to prevent double init
   ========================================================================= */
(function(){
  if (typeof window === 'undefined') return;
  if (window.ARSH_AI_INIT) {
    try { console.debug('[TODO_REMOVE_LOG][ARSH][AI] already initialized'); } catch(_){ }
    return;
  }
  window.ARSH_AI_INIT = true;

  document.addEventListener('DOMContentLoaded', function(){
    var LOG_MARK = '[TODO_REMOVE_LOG]';
    try { console.debug(LOG_MARK+'[ARSH][AI] init DOMContentLoaded'); } catch(_){ }

    var fab = document.getElementById('arAiFab');
    var panel = document.getElementById('arAiPanel');
    var closeBtn = document.getElementById('arAiClose');
    var runBtn = document.getElementById('arAiRun');
    var clearBtn = document.getElementById('arAiClear');
    var cmdEl = document.getElementById('arAiCmd');
    var outEl = document.getElementById('arAiOut');
    var undoBtn = document.getElementById('arAiUndo');
    var headerEl = panel ? panel.querySelector('.ar-ai-header') : null;

    // UI action stack for client-side undo (non-destructive UI ops)
    var uiStack = [];
    var lastServerUndoToken = '';
    var lastActiveEl = null;

    if (!panel || !outEl) {
      try { console.warn(LOG_MARK+'[ARSH][AI] panel elements not found; skipping bind'); } catch(_){ }
      return;
    }

    function setOpen(b){
      if (!panel) return;
      var open = !!b;
      if (open){
        try { panel.classList.add('open'); panel.setAttribute('aria-hidden','false'); panel.removeAttribute('inert'); } catch(_){ }
        try { lastActiveEl = document.activeElement; } catch(_){ lastActiveEl = null; }
        if (cmdEl) { try { cmdEl.focus(); } catch(_){ } }
      } else {
        try { panel.classList.remove('open'); panel.setAttribute('aria-hidden','true'); panel.setAttribute('inert',''); } catch(_){ }
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
      try { console.debug(LOG_MARK+'[ARSH][AI] setOpen', open); } catch(_){ }
    }

    if (fab) fab.addEventListener('click', function(){ var isOpen = panel.classList.contains('open'); setOpen(!isOpen); });
    if (closeBtn) closeBtn.addEventListener('click', function(){ setOpen(false); });
    try { document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && panel && panel.classList.contains('open')) { e.preventDefault(); setOpen(false); } }); } catch(_){ }

    if (clearBtn) clearBtn.addEventListener('click', function(){ if(outEl) outEl.textContent=''; if(cmdEl) cmdEl.value=''; try { sessionStorage.removeItem('arAiHist'); } catch(_){ } });

    // Draggable panel (mouse + touch) constrained to viewport; persist position
    (function enableDrag(){
      if (!panel || !headerEl) return;
      var startX=0, startY=0, origX=0, origY=0, dragging=false;
      function getNum(v){ var n = parseFloat(String(v||'0').replace('px','')); return isNaN(n)?0:n; }
      function setPos(x,y){ try { panel.style.left = Math.max(0, Math.min(window.innerWidth - panel.offsetWidth, x)) + 'px'; panel.style.bottom = ''; panel.style.top = Math.max(0, Math.min(window.innerHeight - panel.offsetHeight, y)) + 'px'; } catch(_){ } }
      function onDown(clientX, clientY){ try { dragging=true; startX=clientX; startY=clientY; origX = panel.offsetLeft; origY = panel.offsetTop; panel.style.position='fixed'; panel.style.willChange='transform,left,top'; document.body.classList.add('ar-no-select'); } catch(_){ } }
      function onMove(clientX, clientY){ if (!dragging) return; var dx = clientX - startX; var dy = clientY - startY; setPos(origX + dx, origY + dy); }
      function onUp(){ if (!dragging) return; dragging=false; try { document.body.classList.remove('ar-no-select'); panel.style.willChange=''; sessionStorage.setItem('arAiPos', JSON.stringify({ left: getNum(panel.style.left), top: getNum(panel.style.top) })); } catch(_){ } }
      // Mouse
      headerEl.addEventListener('mousedown', function(e){ if (e.button!==0) return; onDown(e.clientX, e.clientY); e.preventDefault(); });
      document.addEventListener('mousemove', function(e){ onMove(e.clientX, e.clientY); });
      document.addEventListener('mouseup', function(){ onUp(); });
      // Touch
      headerEl.addEventListener('touchstart', function(e){ try { var t=e.touches[0]; if (t) onDown(t.clientX, t.clientY); } catch(_){ } }, { passive: true });
      document.addEventListener('touchmove', function(e){ try { if (!dragging) return; var t=e.touches[0]; if (t) onMove(t.clientX, t.clientY); } catch(_){ } }, { passive: true });
      document.addEventListener('touchend', function(){ onUp(); }, { passive: true });
      // Restore saved position
      try { var pos = JSON.parse(sessionStorage.getItem('arAiPos')||'null'); if (pos && typeof pos.left==='number' && typeof pos.top==='number'){ panel.style.left = pos.left+'px'; panel.style.top = pos.top+'px'; panel.style.bottom = ''; } } catch(_){ }
      // Keep within viewport when resizing
      window.addEventListener('resize', function(){ try { var l = getNum(panel.style.left); var t = getNum(panel.style.top); setPos(l, t); } catch(_){ } });
    })();

    function appendOut(o){
      if (!outEl) return;
      try {
        var old = outEl.textContent || '';
        var s = (typeof o==='string')? o : String(o);
        outEl.textContent = (old? (old+"\n\n") : '') + s;
        outEl.scrollTop = outEl.scrollHeight;
      } catch(_){ }
    }
    function logConsole(action, detail){ try { console.log(LOG_MARK+'[HOSHYAR]', action, detail||''); } catch(_){ } }

    function humanizeTab(tab){
      var map = { dashboard:'داشبورد', forms:'فرم‌ها', reports:'گزارشات', users:'کاربران', settings:'تنظیمات', 'users/ug':'گروه‌های کاربری' };
      var key = String(tab||'');
      return map[key] || map[key.split('/')[0]] || key;
    }
    function humanizeFieldType(t){
      var m = { short_text:'پاسخ کوتاه', long_text:'پاسخ طولانی', multiple_choice:'چندگزینه‌ای', dropdown:'لیست کشویی', rating:'امتیازدهی' };
      return m[String(t||'')] || String(t||'');
    }
    function humanizePlanStep(s, i){
      try {
        var a = String(s.action||''); var p = s.params||{}; var n = (i>=0? (i+1)+'. ' : '');
        if (a==='create_form'){ var title = p.title? String(p.title):'فرم جدید'; return n+'ساخت فرم با عنوان «'+title+'»'; }
        if (a==='add_field'){
          var tf = humanizeFieldType(p.type||'short_text');
          var q = p.question? (' — «'+String(p.question)+'»') : '';
          if (p.id){ return n+'افزودن سوال '+tf+' به فرم '+parseInt(p.id)+q; }
          return n+'افزودن سوال '+tf+' به فرم جدید'+q;
        }
        if (a==='update_form_title'){ return n+'تغییر عنوان فرم '+parseInt(p.id)+' به «'+String(p.title||'')+'»'; }
        if (a==='open_builder'){ return n+'باز کردن ویرایشگر فرم '+parseInt(p.id); }
        if (a==='open_results'){ return n+'نمایش نتایج فرم '+parseInt(p.id); }
        if (a==='open_editor'){ var idx = (p.index==null?0:parseInt(p.index)); return n+'باز کردن ویرایشگر پرسش '+((isNaN(idx)?0:idx)+1)+' از فرم '+parseInt(p.id); }
        if (a==='publish_form'){ return n+'انتشار فرم '+parseInt(p.id); }
        if (a==='draft_form'){ return n+'بازگرداندن فرم '+parseInt(p.id)+' به پیش‌نویس'; }
        return n+'اجرای '+a;
      } catch(_){ return String(s.action||''); }
    }
    function extractSuggestions(sug){ try { if (!sug) return []; if (Array.isArray(sug)) return sug; if (Array.isArray(sug.samples)) return sug.samples; var persianKey = Object.keys(sug).find(function(k){ return /نمونه/.test(k); }); if (persianKey && Array.isArray(sug[persianKey])) return sug[persianKey]; } catch(_){ } return []; }

    function humanizeResponse(j, rawTxt, httpOk){
      try {
        if (!httpOk || (j && j.ok === false)){
          if (j && j.error === 'unknown_command'){
            var ss = extractSuggestions(j.suggestions);
            var lines = ['دستور واضح نیست.'];
            if (ss.length){ lines.push('مثال‌ها:'); ss.slice(0,4).forEach(function(x){ lines.push('• '+String(x)); }); }
            return lines.join('\n');
          }
          if (j && j.message){ return String(j.message); }
          if (rawTxt){ return String(rawTxt); }
          return 'خطایی رخ داد.';
        }
        if (j && j.action){
          if (j.action === 'preview' && j.plan && Array.isArray(j.plan.steps)){
            var steps = j.plan.steps.map(function(s, i){ return humanizePlanStep(s, i); }).join('\n');
            return 'پیش‌نمایش طرح ('+j.plan.steps.length+' مرحله):\n'+steps;
          }
          if (j.action === 'confirm') return String(j.message || 'تایید می‌کنید؟');
          if (j.action === 'clarify') return String(j.message || 'مبهم است. لطفاً مشخص‌تر بفرمایید.');
          if (j.action === 'open_tab') return 'در حال باز کردن '+humanizeTab(j.tab)+'…';
          if (j.action === 'open_builder' && j.id) return 'در حال باز کردن ویرایش فرم شماره '+j.id+'…';
          if (j.action === 'open_results' && j.id) return 'در حال نمایش نتایج فرم شماره '+j.id+'…';
          if (j.action === 'open_editor' && (j.id!=null)){ var idx = (j.index==null?0:parseInt(j.index)); return 'در حال باز کردن پرسش '+((isNaN(idx)?0:idx)+1)+' از فرم '+j.id+'…'; }
          if (j.action === 'download' && j.format) return 'در حال دانلود '+String(j.format).toUpperCase()+'…';
          if (j.action === 'list_forms' && Array.isArray(j.forms)) return 'تعداد '+j.forms.length+' فرم یافت شد.';
        }
        // If results of plan execution returned without a specific UI action
        if (j && Array.isArray(j.results)){
          var okCount = j.results.length;
          return 'پلن اجرا شد ('+okCount+' مرحله).';
        }
        return 'انجام شد.';
      } catch(_){ return 'انجام شد.'; }
    }

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
            appendOut(humanizeResponse(j, t, r.ok));
            if (r.ok && j && j.ok){ notify('بازگردانی انجام شد', 'success'); try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } }
            else { notify('بازگردانی ناموفق بود', 'error'); }
          } catch(e){ appendOut(String(e)); notify('خطا در بازگردانی', 'error'); }
          finally { btn.disabled = false; btn.textContent='بازگردانی'; }
        });
        wrap.appendChild(lab); wrap.appendChild(btn);
        outEl.appendChild(wrap);
      } catch(_){ }
    }
    function attachUndoGroupUI(tokens){
      try {
        if (!Array.isArray(tokens) || !tokens.length || !outEl) return;
        var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
        var lab = document.createElement('span'); lab.textContent='بازگردانی پلن'; lab.className='ar-badge'; lab.style.marginInlineEnd='.5rem';
        var btnLast = document.createElement('button'); btnLast.className='ar-btn ar-btn--soft'; btnLast.textContent='بازگردانی آخرین';
        var btnAll = document.createElement('button'); btnAll.className='ar-btn ar-btn--outline'; btnAll.textContent='بازگردانی همه'; btnAll.style.marginInlineStart='.5rem';
        btnLast.addEventListener('click', async function(){ try { var tok = String(tokens[tokens.length-1]||''); if (!tok) return; btnLast.disabled=true; btnLast.textContent='در حال بازگردانی…'; var r = await fetch(buildRest('ai/undo'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: tok }) }); var t = ''; try { t = await r.clone().text(); } catch(_){ } var j = null; try { j = t ? JSON.parse(t) : await r.json(); } catch(_){ } appendOut(humanizeResponse(j, t, r.ok)); if (r.ok && j && j.ok){ notify('بازگردانی انجام شد', 'success'); try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } } else { notify('بازگردانی ناموفق بود', 'error'); } } catch(e){ appendOut(String(e)); notify('خطا در بازگردانی', 'error'); } finally { btnLast.disabled=false; btnLast.textContent='بازگردانی آخرین'; } });
        btnAll.addEventListener('click', async function(){ try { btnAll.disabled=true; btnAll.textContent='در حال بازگردانی…'; for (var i=tokens.length-1; i>=0; i--){ var tok = String(tokens[i]||''); if (!tok) continue; var r = await fetch(buildRest('ai/undo'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: tok }) }); var t = ''; try { t = await r.clone().text(); } catch(_){ } var j = null; try { j = t ? JSON.parse(t) : await r.json(); } catch(_){ } appendOut(humanizeResponse(j, t, r.ok)); } notify('بازگردانی پلن انجام شد', 'success'); try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } } catch(e){ appendOut(String(e)); notify('خطا در بازگردانی', 'error'); } finally { btnAll.disabled=false; btnAll.textContent='بازگردانی همه'; } });
        wrap.appendChild(lab); wrap.appendChild(btnLast); wrap.appendChild(btnAll);
        outEl.appendChild(wrap);
      } catch(_){ }
    }

    function saveHist(cmd, friendly){ try { var h = []; try { h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); } catch(_){ h = []; } h.push({ t: Date.now(), cmd: String(cmd||''), res: String(friendly||'') }); h = h.slice(-20); sessionStorage.setItem('arAiHist', JSON.stringify(h)); } catch(_){ } }
    function loadHist(){ try { var h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); if (Array.isArray(h) && h.length && outEl){ outEl.textContent = h.map(function(x){ return '> '+(x.cmd||'')+'\n'+String(x.res||''); }).join('\n\n'); } } catch(_){ } }
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
    function getUiContext(){
      try {
        var h = (location.hash||'').replace(/^#/, '');
        var tab = (h.split('/')[0]||'dashboard') || 'dashboard';
        return { ui_tab: tab, ui_route: h };
      } catch(_){ return { ui_tab: 'dashboard', ui_route: '' }; }
    }

    function handleAgentAction(j){
      try {
        if (!j) return;
        // Plan preview with Execute button
        if (j.action === 'preview' && j.plan && Array.isArray(j.plan.steps)){
          appendOut(humanizeResponse(j, '', true));
          try {
            var wrapP = document.createElement('div'); wrapP.style.marginTop='.5rem';
            var execBtn = document.createElement('button'); execBtn.className='ar-btn'; execBtn.textContent='اجرای طرح';
            var cancelBtn = document.createElement('button'); cancelBtn.className='ar-btn ar-btn--outline'; cancelBtn.textContent='لغو'; cancelBtn.style.marginInlineStart='.5rem';
            execBtn.addEventListener('click', async function(){
              try {
                var rX = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(Object.assign({ plan: j.plan, confirm: true }, getUiContext())) });
                var tX = ''; try { tX = await rX.clone().text(); } catch(_){ }
                var jX = null; try { jX = tX ? JSON.parse(tX) : await rX.json(); } catch(_){ }
                var friendlyX = humanizeResponse(jX, tX, rX.ok);
                appendOut(friendlyX);
                if (jX && jX.undo_token) { lastServerUndoToken = String(jX.undo_token||''); attachUndoUI(jX.undo_token); }
                if (jX && Array.isArray(jX.undo_tokens) && jX.undo_tokens.length){ lastServerUndoToken = String(jX.undo_tokens[jX.undo_tokens.length-1]||''); attachUndoGroupUI(jX.undo_tokens); }
                if (rX.ok && jX && jX.ok !== false){ handleAgentAction(jX); notify('طرح اجرا شد', 'success'); }
                else { notify('اجرای طرح ناموفق بود', 'error'); }
              } catch(e){ appendOut(String(e)); notify('خطا', 'error'); }
            });
            cancelBtn.addEventListener('click', function(){ notify('لغو شد', 'warn'); });
            wrapP.appendChild(execBtn); wrapP.appendChild(cancelBtn);
            if (outEl) outEl.appendChild(wrapP);
          } catch(_){ }
          return;
        }
        if (j.action === 'confirm' && j.confirm_action){
          var msg = String(j.message||'تایید می‌کنید؟');
          appendOut(msg);
          try {
            var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
            var yes = document.createElement('button'); yes.className='ar-btn'; yes.textContent='تایید';
            var no = document.createElement('button'); no.className='ar-btn ar-btn--outline'; no.textContent='انصراف'; no.style.marginInlineStart='.5rem';
            yes.addEventListener('click', async function(){
              try {
                var r2 = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(Object.assign({ confirm_action: j.confirm_action }, getUiContext())) });
                var txt2 = ''; try { txt2 = await r2.clone().text(); } catch(_){ }
                var j2 = null; try { j2 = txt2 ? JSON.parse(txt2) : await r2.json(); } catch(_){ }
                var friendly2 = humanizeResponse(j2, txt2, r2.ok);
                appendOut(friendly2);
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
          appendOut(String(j.message||'مبهم است'));
          try {
            var wrap2 = document.createElement('div'); wrap2.style.marginTop='.5rem';
            (j.options||[]).forEach(function(opt){
              var b = document.createElement('button'); b.className='ar-btn'; b.textContent=String(opt.label||opt.value); b.style.marginInlineEnd='.5rem';
              b.addEventListener('click', async function(){
                if (j.clarify_action){
                  var ca = j.clarify_action; var pa = {}; pa[j.param_key] = opt.value;
                  var r3 = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(Object.assign({ confirm_action: { action: ca.action, params: pa } }, getUiContext())) });
                  var t3 = ''; try { t3 = await r3.clone().text(); } catch(_){ }
                  var j3 = null; try { j3 = t3 ? JSON.parse(t3) : await r3.json(); } catch(_){ }
                  appendOut(humanizeResponse(j3, t3, r3.ok));
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
        if (j.action === 'help' && j.capabilities){ appendOut('راهنما دریافت شد.'); return; }
        if (j.action === 'ui' && j.target === 'toggle_theme'){
          try {
            var t = document.getElementById('arThemeToggle');
            var prev = t ? (t.getAttribute('aria-checked')==='true') : null;
            if (t) t.click();
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
            uiStack.push({ type:'open_tab', payload:{ prevHash: prevHash }, undo:function(){ try { var prevTab = (prevHash||'').replace(/^#/, '').split('/')[0] || 'dashboard'; if (typeof window.setHash==='function') setHash(prevTab); else { try { location.hash = '#' + prevTab; } catch(_){ } } if (typeof window.renderTab==='function') window.renderTab(prevTab); } catch(_){ } } });
            logConsole('UI action', { open_tab: j.tab });
            appendOut('باز شد: '+humanizeTab(j.tab));
          } catch(_){ }
          return;
        }
        if (j.action === 'open_builder' && j.id){
          try {
            var prevHash2 = location.hash;
            var builderHash = 'builder/'+parseInt(j.id);
            if (typeof window.setHash==='function') setHash(builderHash); else { try { location.hash = '#' + builderHash; } catch(_){ } }
            try { if (window.ARSH_ROUTER && typeof window.ARSH_ROUTER.routeFromHash==='function') { window.ARSH_ROUTER.routeFromHash(); }
              else if (typeof window.renderFormBuilder==='function') { window.renderFormBuilder(parseInt(j.id)); }
              else if (typeof window.renderTab==='function') { window.renderTab('forms'); }
            } catch(_){ if (typeof window.renderTab==='function') window.renderTab('forms'); }
            uiStack.push({ type:'open_builder', payload:{ prevHash: prevHash2 }, undo:function(){ try { var prevTab2 = (prevHash2||'').replace(/^#/, '').split('/')[0] || 'dashboard'; if (typeof window.setHash==='function') setHash(prevTab2); else { try { location.hash = '#' + prevTab2; } catch(_){ } } if (typeof window.renderTab==='function') window.renderTab(prevTab2); } catch(_){ } } });
            logConsole('UI action', { open_builder: j.id });
            appendOut('ویرایش فرم '+parseInt(j.id)+' باز شد.');
          } catch(_){ }
          return;
        }
        if (j.action === 'open_results' && j.id){
          try {
            var prevHash3 = location.hash;
            var resultsHash = 'results/'+parseInt(j.id);
            if (typeof window.setHash==='function') setHash(resultsHash); else { try { location.hash = '#' + resultsHash; } catch(_){ } }
            if (typeof window.renderTab==='function') window.renderTab('forms');
            uiStack.push({ type:'open_results', payload:{ prevHash: prevHash3 }, undo:function(){ try { var prevTab3 = (prevHash3||'').replace(/^#/, '').split('/')[0] || 'dashboard'; if (typeof window.setHash==='function') setHash(prevTab3); else { try { location.hash = '#' + prevTab3; } catch(_){ } } if (typeof window.renderTab==='function') window.renderTab(prevTab3); } catch(_){ } } });
            logConsole('UI action', { open_results: j.id });
            appendOut('نتایج فرم '+parseInt(j.id)+' باز شد.');
          } catch(_){ }
          return;
        }
        if (j.action === 'open_editor' && j.id != null){
          try {
            var prevHash4 = location.hash; var idx = (j.index==null?0:parseInt(j.index));
            var editorHash = 'editor/'+parseInt(j.id)+'/'+(isNaN(idx)?0:idx);
            if (typeof window.setHash==='function') setHash(editorHash); else { try { location.hash = '#' + editorHash; } catch(_){ } }
            try { if (window.ARSH_ROUTER && typeof window.ARSH_ROUTER.routeFromHash==='function') { window.ARSH_ROUTER.routeFromHash(); }
              else if (typeof window.renderFormEditor==='function') { window.renderFormEditor(parseInt(j.id), { index: idx }); }
              else if (typeof window.renderTab==='function') { window.renderTab('forms'); }
            } catch(_){ if (typeof window.renderTab==='function') window.renderTab('forms'); }
            uiStack.push({ type:'open_editor', payload:{ prevHash: prevHash4 }, undo:function(){ try { var prevTab4 = (prevHash4||'').replace(/^#/, '').split('/')[0] || 'dashboard'; if (typeof window.setHash==='function') setHash(prevTab4); else { try { location.hash = '#' + prevTab4; } catch(_){ } } if (typeof window.renderTab==='function') window.renderTab(prevTab4); } catch(_){ } } });
            logConsole('UI action', { open_editor: { id: j.id, index: idx } });
            appendOut('پرسش '+((isNaN(idx)?0:idx)+1)+' از فرم '+parseInt(j.id)+' باز شد.');
          } catch(_){ }
          return;
        }
        if (j.action === 'ui' && j.target){
          try {
            if (j.target === 'undo'){
              if (undoBtn && typeof undoBtn.click==='function') { undoBtn.click(); } else { notify('بازگردانی در دسترس نیست', 'warn'); }
              return;
            }
            if (j.target === 'go_back'){
              if (uiStack.length){ var item = uiStack.pop(); if (item && typeof item.undo==='function'){ item.undo(); notify('بازگشت انجام شد', 'success'); return; } }
              try { history.back(); } catch(_){ }
              return;
            }
            if (j.target === 'open_editor_index'){
              var curr = (location.hash||'').replace('#','').split('/');
              if (curr[0]==='builder' && curr[1]){
                var idb = parseInt(curr[1]||'0'); var idx2 = (j.index==null?0:parseInt(j.index));
                if (!isNaN(idb) && idb>0){
                  var eh = 'editor/'+idb+'/'+(isNaN(idx2)?0:idx2);
                  if (typeof window.setHash==='function') setHash(eh); else { try { location.hash = '#' + eh; } catch(_){ } }
                  try { if (window.ARSH_ROUTER && typeof window.ARSH_ROUTER.routeFromHash==='function') window.ARSH_ROUTER.routeFromHash(); else if (typeof window.renderFormEditor==='function') window.renderFormEditor(idb, { index: idx2 }); } catch(_){ }
                  return;
                }
              }
              notify('برای باز کردن ویرایشگر پرسش، ابتدا وارد صفحه ویرایش فرم شوید', 'warn');
              return;
            }
          } catch(_){ }
          return;
        }
        if ((j.action === 'download' || j.action === 'export') && j.url){ try { window.open(String(j.url), '_blank'); appendOut('لینک دانلود باز شد.'); } catch(_){ } return; }
        if (j.url && !j.action){ try { window.open(String(j.url), '_blank'); } catch(_){ } return; }
      } catch(_){ }
    }

    async function runAgent(cmdOverride){
      var cmd = (typeof cmdOverride === 'string' && cmdOverride.trim()) ? cmdOverride.trim() : ((cmdEl && cmdEl.value) ? String(cmdEl.value) : '');
      if (cmdEl) { try { cmdEl.value = ''; } catch(_){ } }
      if (!cmd){ notify('دستور خالی است', 'warn'); return; }
      appendOut('> '+cmd);
      // Client-side smart intents for common Persian routes
      try {
        var c = cmd.replace(/[\s\u200c\u200d]+/g,' ').trim();
        // If the command includes opening Forms, proactively navigate but continue
        try {
          var reForms = /(برو\s*(?:به)?\s*)?(?:منوی\s*)?فرم(?:\s*ها|ها)?/i;
          if (reForms.test(c)){
            if (typeof window.setHash === 'function') { setHash('forms'); } else { try { location.hash = '#forms'; } catch(_){ } }
            if (typeof window.renderTab === 'function') { try { window.renderTab('forms'); } catch(_){ } }
          }
        } catch(_){ }
        // examples: "برو به گروه های کاربری" , "گروه‌های کاربری" , "کاربران/گروه‌ها"
        var reUG = /(برو\s*به\s*)?(گروه[\u200c\u200d\s-]*های\s*کاربری|گروه\s*های\s*کاربری|گروه‌های\s*کاربری|گروه ها?ی کاربری|کاربران\s*\/\s*گروه‌ها?)/i;
        if (reUG.test(c)){
          // Show progressive feedback and navigate
          appendOut('در حال باز کردن کاربران…');
          try {
            var next = 'users/ug';
            if (typeof window.setHash === 'function') { setHash(next); } else { try { location.hash = '#'+next; } catch(_){ } }
            if (typeof window.renderTab === 'function') { try { window.renderTab('users'); } catch(_){ } }
            // If lazy UG loader is used, ask it to render current tab
            if (typeof window.ARSH_UG_render === 'function') { try { window.ARSH_UG_render('groups'); } catch(_){ } }
            appendOut('باز شد: کاربران');
            saveHist(cmd, 'در حال باز کردن کاربران…\nباز شد: کاربران');
            notify('باز شد', 'success');
          } catch(_){ }
          return;
        }
        // Quick add field intents
        // Map Persian commands to field types
        var mapAdd = [
          { re: /(افزودن|اضافه کن|یک)\s*(سوال|پرسش)\s*(کوتاه|پاسخ کوتاه)/i, type:'short_text' },
          { re: /(افزودن|اضافه کن|یک)\s*(سوال|پرسش)\s*(طولانی|پاسخ طولانی)/i, type:'long_text' },
          { re: /(افزودن|اضافه کن|یک)\s*(سوال|پرسش)\s*(چند\s*گزینه|چندگزینه)/i, type:'multiple_choice' },
          { re: /(افزودن|اضافه کن|یک)\s*(سوال|پرسش)\s*(لیست|کشویی|لیست کشویی)/i, type:'dropdown' },
          { re: /(افزودن|اضافه کن|یک)\s*(امتیاز|رتبه|ستاره)/i, type:'rating' },
          { re: /(افزودن|اضافه کن|یک)\s*(پیام)\s*(خوش\s*آمد|خوشامد|خوش آمد)/i, type:'welcome' },
          { re: /(افزودن|اضافه کن|یک)\s*(پیام)\s*(تشکر|سپاس)/i, type:'thank_you' }
        ];
        for (var k=0; k<mapAdd.length; k++){
          if (mapAdd[k].re.test(c)){
            // Must be on builder page with a form id in hash
            var hh = (location.hash||'').replace('#','').split('/');
            if (hh[0] === 'builder' && hh[1]){
              var fid = parseInt(hh[1]||'0');
              if (!isNaN(fid) && fid>0 && typeof window.addNewField === 'function'){
                window.addNewField(fid, mapAdd[k].type);
                appendOut('در حال افزودن '+mapAdd[k].type+'…');
                saveHist(cmd, 'در حال افزودن فیلد');
                notify('افزوده شد', 'success');
                return;
              }
            }
            // If not on builder, try to open last opened form builder
            appendOut('ابتدا وارد صفحه ویرایش فرم شوید، سپس دوباره تلاش کنید.');
            notify('ابتدا صفحه ویرایش فرم را باز کنید', 'warn');
            return;
          }
        }
      } catch(_){ }
      // If user typed a JSON plan, send as preview; support optional confirm flag
      var payload = null;
      try {
        var parsed = JSON.parse(cmd);
        if (parsed && typeof parsed === 'object' && Array.isArray(parsed.steps)){
          payload = { plan: parsed, confirm: !!parsed.confirm };
        }
      } catch(_){ }
      var bodyObj = Object.assign(payload || { command: cmd }, getUiContext());
      try {
        var r = await fetch(buildRest('ai/agent'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(bodyObj) });
        var txt = ''; try { txt = await r.clone().text(); } catch(_){ }
        var j = null; try { j = txt ? JSON.parse(txt) : await r.json(); } catch(_){ }
        try { console.debug(LOG_MARK+'[ARSH][AI] response', { status:r.status, body:j||txt }); } catch(_){ }
  var friendly = humanizeResponse(j, txt, r.ok);
  // Avoid double-printing preview: handleAgentAction will render preview + buttons once
  var isPreview = !!(j && j.action === 'preview' && j.plan && Array.isArray(j.plan.steps));
  if (!isPreview) { appendOut(friendly); }
        saveHist(cmd, friendly);
  if (r.ok && j && j.ok !== false){ handleAgentAction(j); notify('انجام شد', 'success'); } else { notify('اجرا ناموفق بود', 'error'); }
        if (j && j.undo_token){ lastServerUndoToken = String(j.undo_token||''); attachUndoUI(j.undo_token); logConsole('Server undo token', lastServerUndoToken); }
      } catch(e){ appendOut(String(e)); notify('خطا در اجرای دستور', 'error'); }
    }

    if (runBtn) runBtn.addEventListener('click', runAgent);

    if (undoBtn) undoBtn.addEventListener('click', async function(){
      try {
        if (uiStack.length){ var item = uiStack.pop(); if (item && typeof item.undo === 'function'){ item.undo(); notify('بازگردانی UI انجام شد', 'success'); logConsole('UI undo', item.type); return; } }
        if (lastServerUndoToken){
          try {
            var r = await fetch(buildRest('ai/undo'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: lastServerUndoToken }) });
            var t = ''; try { t = await r.clone().text(); } catch(_){ }
            var j = null; try { j = t ? JSON.parse(t) : await r.json(); } catch(_){ }
            appendOut(humanizeResponse(j, t, r.ok));
            if (r.ok && j && j.ok){ notify('بازگردانی انجام شد', 'success'); logConsole('Server undo', lastServerUndoToken); lastServerUndoToken=''; try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } return; }
          } catch(e){ appendOut(String(e)); }
        }
        appendOut('> فهرست بازگردانی‌های اخیر');
        var rr = await fetch(buildRest('ai/audit', 'limit=10'), { method:'GET', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} });
        var tt = ''; try { tt = await rr.clone().text(); } catch(_){ }
        var jj = null; try { jj = tt ? JSON.parse(tt) : await rr.json(); } catch(_){ }
        if (jj && Array.isArray(jj.items) && jj.items.length){
          try {
            var wrap = document.createElement('div'); wrap.style.marginTop='.5rem';
            var title = document.createElement('div'); title.textContent = 'آخرین '+jj.items.length+' مورد:'; title.style.marginBottom='.25rem'; wrap.appendChild(title);
            jj.items.forEach(function(it){
              var row = document.createElement('div'); row.style.display='flex'; row.style.alignItems='center'; row.style.gap='.5rem'; row.style.marginBottom='.25rem';
              var label = document.createElement('span'); label.textContent = (it.action||'-')+' / '+(it.scope||'-')+(it.target_id?' #'+it.target_id:''); label.className='ar-badge';
              var time = document.createElement('span'); time.textContent = it.created_at ? ('— '+String(it.created_at)) : ''; time.style.opacity='.7'; time.style.fontSize='.85em';
              var b = document.createElement('button'); b.className='ar-btn ar-btn--soft'; b.textContent='بازگردانی'; if (it.undone) { b.disabled = true; b.textContent='بازگردانی شده'; }
              b.addEventListener('click', async function(){ try { b.disabled=true; b.textContent='در حال بازگردانی…'; var r0 = await fetch(buildRest('ai/undo'), { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ token: String(it.undo_token||'') }) }); var t0 = ''; try { t0 = await r0.clone().text(); } catch(_){ } var j0 = null; try { j0 = t0 ? JSON.parse(t0) : await r0.json(); } catch(_){ } appendOut(humanizeResponse(j0, t0, r0.ok)); if (r0.ok && j0 && j0.ok){ notify('بازگردانی انجام شد', 'success'); b.textContent='بازگردانی شد'; try { if (typeof window.renderTab==='function') window.renderTab('forms'); } catch(_){ } } else { b.disabled=false; b.textContent='بازگردانی'; notify('بازگردانی ناموفق بود', 'error'); } } catch(e){ appendOut(String(e)); b.disabled=false; b.textContent='بازگردانی'; }});
              row.appendChild(label); row.appendChild(time); row.appendChild(b); wrap.appendChild(row);
            });
            outEl.appendChild(wrap);
          } catch(_){ }
        } else {
          appendOut('تاریخی برای بازگردانی یافت نشد.');
        }
      } catch(e){ appendOut(String(e)); }
    });

    if (cmdEl) cmdEl.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); runAgent(); }});

    try { if ((sessionStorage.getItem('arAiOpen')||'')==='1') setOpen(true); } catch(_){ }

    try {
      var api = { open: function(){ setOpen(true); }, run: function(cmd){ if (cmdEl) { cmdEl.value = String(cmd||''); } runAgent(); }, undo: function(token){ if (token) { lastServerUndoToken = String(token); } attachUndoUI(String(token||'')); } };
      window.ARSH_AI = api; window.HOSHYAR = api;
      console.debug(LOG_MARK+'[ARSH][AI] ready');
    } catch(_){ }
  });
})();

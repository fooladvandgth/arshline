/*
 * Arshline Console Capture Module
 * Client-side events logging (Dashboard only) to browser console.
 * - click, input/change, form submit, error/unhandledrejection, DOM mutations, AJAX (fetch/XHR)
 * - Output: linear with full timestamp YYYY-MM-DD HH:mm:ss
 * - Sensitive data filtering (email/phone)
 * - Async queue to avoid UI jank
 */
(function(window, document){
  'use strict';
  var CFG = window.ARSHLINE_CAPTURE || { enabled:false };
  // تعیین دسترسی تنها برای مدیران/ویرایشگران، با تأخیر در صورتی که شیء داشبورد هنوز مقداردهی نشده باشد
  function canManageNow(){
    try {
      if (window.ARSHLINE_DASHBOARD && typeof window.ARSHLINE_DASHBOARD.canManage !== 'undefined') {
        return !!window.ARSHLINE_DASHBOARD.canManage;
      }
      // Fallback: read inline dashboard config JSON if present
      var cfgEl = document.getElementById('arshline-config');
      if (cfgEl && cfgEl.textContent) {
        try {
          var json = JSON.parse(cfgEl.textContent);
          if (json && typeof json.can_manage !== 'undefined') {
            return !!json.can_manage;
          }
        } catch(_) {}
      }
    } catch(_){ }
    return false;
  }

  var queue = [];
  var flushing = false;
  var maxBatch = 50;
  var flushInterval = 500; // ms
  var started = false;

  // Regex ها برای سانسور داده‌ها
  var reEmail = /([a-zA-Z0-9_.+-]+)@([a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+)/g;
  // ایران: 09xxxxxxxxx و بین‌المللی ساده
  var rePhone = /(\+?98|0)?9\d{9}|\+?\d{7,15}/g;

  function ts(){ var d=new Date(); function p(n){ return (n<10?'0':'')+n; } return d.getFullYear()+ '-' + p(d.getMonth()+1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }
  function cssPath(el){ try { if(!el || !el.nodeType) return ''; var path=[], parent; while (el && el.nodeType===1 && path.length<5){ var name=el.nodeName.toLowerCase(); var id=el.id?('#'+el.id):''; var cls=(el.className&&typeof el.className==='string')?('.'+el.className.trim().split(/\s+/).slice(0,2).join('.')):''; path.unshift(name+id+cls); parent=el.parentElement; el=parent; } return path.join(' > '); } catch(_){ return ''; } }
  function summarizeTarget(el){ try { if(!el) return ''; var sel=cssPath(el); if(sel) return sel; var html=el.outerHTML||''; return html? html.slice(0,120).replace(/\s+/g,' ')+'…' : (el.nodeName||''); } catch(_){ return ''; } }
  function sanitize(val){ try { var s = (typeof val==='string')? val : JSON.stringify(val); if (!s) return s; s = s.replace(reEmail, '***@***'); s = s.replace(rePhone, '********'); return s; } catch(_){ return ''; } }
  function validate(entry){ try { if(!entry || !entry.type) return false; if(typeof entry.message==='string' && entry.message.length>2000) entry.message = entry.message.slice(0,2000)+'…'; return true; } catch(_){ return false; } }
  function push(entry){ if(!validate(entry)) return; queue.push(entry); if(queue.length>=maxBatch){ flush(); } }
  function flush(){ if(flushing || !queue.length) return; flushing=true; var batch=queue.splice(0, maxBatch); setTimeout(function(){ try { batch.forEach(function(e){ var line = '['+ts()+'] '+e.type.toUpperCase()+ ' :: ' + (e.target||'') + (e.message?(' :: '+sanitize(e.message)):'') + (e.data?(' :: '+sanitize(e.data)):''); console.log('%c[ARSH-CAPTURE]','color:#16a34a', line); }); } finally { flushing=false; } }, 0); }
  setInterval(flush, flushInterval);

  // Capture helpers
  function onClick(e){ try { var t=e.target; push({ type:'click', target:summarizeTarget(t) }); } catch(_){ } }
  function onInput(e){ try { var t=e.target; if(!t) return; var v=''; if(t && 'value' in t){ v = String(t.value||''); } push({ type:'input', target:summarizeTarget(t), data:v.slice(0,120) }); } catch(_){ } }
  function onChange(e){ try { var t=e.target; var v=''; if(t && 'value' in t){ v = String(t.value||''); } push({ type:'change', target:summarizeTarget(t), data:v.slice(0,120) }); } catch(_){ } }
  function onSubmit(e){ try { var f=e.target; var id=(f && f.id)?('#'+f.id):''; push({ type:'submit', target:summarizeTarget(f), data:id }); } catch(_){ } }
  function onError(msg, src, lineno, colno, err){ try { var m = (err && err.message) ? err.message : String(msg||''); push({ type:'error', message: m, data: (src? (src+':'+lineno+':'+colno) : '') }); } catch(_){ } return false; }
  function onRejection(ev){ try { var r=ev && (ev.reason||ev); var m = (r && r.message)? r.message : (typeof r==='string'? r : ''); push({ type:'error', message: 'UnhandledRejection: '+m }); } catch(_){ } }

  // MutationObserver (throttled summary)
  var moPending = false; var moAdded=0, moRemoved=0, moAttrs=0;
  function onMutations(muts){ muts.forEach(function(m){ if (m.type==='childList'){ moAdded += (m.addedNodes||[]).length; moRemoved += (m.removedNodes||[]).length; } else if (m.type==='attributes'){ moAttrs += 1; } }); if (!moPending){ moPending=true; setTimeout(function(){ push({ type:'dom', message:'تغییرات DOM', data: 'افزوده:'+moAdded+' حذف:'+moRemoved+' ویژگی:'+moAttrs }); moPending=false; moAdded=0; moRemoved=0; moAttrs=0; }, 800); } }

  // Fetch/XHR capture
  function wrapFetch(){ if (!window.fetch) return; var _fetch = window.fetch; window.fetch = function(input, init){ try { var method = (init && init.method) ? String(init.method).toUpperCase() : 'GET'; var url = (typeof input==='string')? input : (input && input.url) ? input.url : ''; push({ type:'ajax', message:'fetch '+method, data:url }); } catch(_){ } return _fetch.apply(this, arguments); } }
  function wrapXHR(){ if (!window.XMLHttpRequest) return; var _open = XMLHttpRequest.prototype.open; var _send = XMLHttpRequest.prototype.send; XMLHttpRequest.prototype.open = function(method, url){ try { this.__arsh_meta = { method:String(method||'GET').toUpperCase(), url:String(url||'') }; } catch(_){ } return _open.apply(this, arguments); }; XMLHttpRequest.prototype.send = function(body){ try { var info = (this && this.__arsh_meta) ? this.__arsh_meta : { method:'GET', url:'' }; push({ type:'ajax', message:'xhr '+info.method, data: info.url }); } catch(_){ } return _send.apply(this, arguments); }; }

  function init(){ if (started) return; started = true;
    document.addEventListener('click', onClick, true);
    document.addEventListener('input', onInput, true);
    document.addEventListener('change', onChange, true);
    document.addEventListener('submit', onSubmit, true);
    window.addEventListener('error', function(e){ onError(e.message, e.filename, e.lineno, e.colno, e.error); }, true);
    window.addEventListener('unhandledrejection', onRejection, true);
    try { var mo = new MutationObserver(onMutations); mo.observe(document.documentElement, { childList:true, attributes:true, subtree:true }); } catch(_){ }
    wrapFetch(); wrapXHR();
    if (CFG && CFG.strings && CFG.strings.moduleEnabled) { console.info('%c[ARSH-CAPTURE]','color:#16a34a', CFG.strings.moduleEnabled); }
  }
  function waitForAllowedThenInit(){
    var tries = 0, maxTries = 50; // ~5s
    var timer = setInterval(function(){
      tries++;
      if (canManageNow()){
        clearInterval(timer);
        init();
      } else if (tries >= maxTries){
        clearInterval(timer);
        // Silent: not allowed or dashboard not detected
      }
    }, 100);
  }

  // Self-tests (optional)
  function runTests(){
    var s = (CFG && CFG.strings) || {};
    console.info('[ARSH-CAPTURE]', s.testStart || 'Running tests…');
    var ok = 0, fail = 0;
    try {
      // Click test
      var btn = document.createElement('button'); btn.id='__arsh_test_btn'; btn.textContent='btn'; document.body.appendChild(btn);
      btn.click(); ok++;
      // Input test
      var inp = document.createElement('input'); inp.id='__arsh_test_inp'; document.body.appendChild(inp); inp.value='Hello'; var ev = new Event('input', { bubbles:true }); inp.dispatchEvent(ev); ok++;
      // Error test
      try { throw new Error('TEST_ERROR'); } catch(e){ onError(e.message, 'test.js', 1, 1, e); ok++; }
      // Submit test
      var form = document.createElement('form'); form.id='__arsh_test_form'; document.body.appendChild(form); var sev = new Event('submit', { bubbles:true }); form.dispatchEvent(sev); ok++;
    } catch(_){ fail++; }
    console.info('[ARSH-CAPTURE]', (s.testDone||'Done') + ' — ' + (s.testPass||'PASS') + ':' + ok + ' ' + (s.testFail||'FAIL') + ':' + fail);
  }

  // Public API
  window.ARSHCapture = {
    init: init,
    push: push,
    runTests: runTests
  };

  if (CFG && CFG.enabled){
    if (canManageNow()) { init(); } else { waitForAllowedThenInit(); }
  } else {
    if (CFG && CFG.strings && CFG.strings.moduleDisabled) console.info('%c[ARSH-CAPTURE]','color:#999', CFG.strings.moduleDisabled);
  }
  if (CFG && CFG.enabled && CFG.runTests){ setTimeout(runTests, 150); }

})(window, document);

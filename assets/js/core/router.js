/* =========================================================================
   FILE: assets/js/core/router.js
   Purpose: Centralized hash-based routing and tab activation
   Dependencies: inline renderTab (until FULL mode), ARSH_CTRL flags
   Exports: window.ARSH_ROUTER { setHash, routeFromHash, arRenderTab, setActive }
   Guards: ARSH_ROUTER_INIT
   ========================================================================= */
(function(){
  if (typeof window === 'undefined') return;
  if (window.ARSH_ROUTER_INIT) return; window.ARSH_ROUTER_INIT = true;
  var _arNavSilence = 0;

  function setHash(h){
    var target = '#' + h;
    if (location.hash !== target){
      _arNavSilence++;
      location.hash = h;
      setTimeout(function(){ _arNavSilence = Math.max(0, _arNavSilence - 1); }, 0);
    }
  }
  function arRenderTab(tab){
    try {
      if (typeof window.renderTab === 'function') return window.renderTab(tab);
      if (typeof renderTab === 'function') return renderTab(tab);
    } catch(_){ }
    try { console.warn('[ARSH][Router] renderTab not available; route="'+tab+'"'); } catch(_){ }
  }
  function routeFromHash(){
    var raw = (location.hash||'').replace('#','').trim();
    if (!raw){ arRenderTab('dashboard'); return; }
    var parts = raw.split('/');
    if (parts[0]==='submissions'){ arRenderTab('forms'); return; }
    if (['dashboard','forms','reports','users','settings'].includes(parts[0])){ arRenderTab(parts[0]); return; }
    if (parts[0]==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { try { window.renderFormBuilder && window.renderFormBuilder(id); } catch(_){ } return; } }
    if (parts[0]==='editor' && parts[1]){ var id2 = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); if (id2) { try { window.renderFormEditor && window.renderFormEditor(id2, { index: isNaN(idx)?0:idx }); } catch(_){ } return; } }
    if (parts[0]==='preview' && parts[1]){ var id3 = parseInt(parts[1]||'0'); if (id3) { try { window.renderFormPreview && window.renderFormPreview(id3); } catch(_){ } return; } }
    if (parts[0]==='results' && parts[1]){ var id4 = parseInt(parts[1]||'0'); if (id4) { try { window.renderFormResults && window.renderFormResults(id4); } catch(_){ } return; } }
    arRenderTab('dashboard');
  }
  function setActive(tab){
    try { document.querySelectorAll('.arshline-sidebar nav a[data-tab]').forEach(function(a){ a.classList.toggle('active', a.getAttribute('data-tab')===tab); }); } catch(_){ }
  }

  function init(){
    try { console.debug('[ARSH][Router] init'); } catch(_){ }
    window.addEventListener('hashchange', function(){ if (_arNavSilence>0) return; routeFromHash(); });
    // default tab
    if (location.hash){ routeFromHash(); }
    else {
      var initial = (function(){ try { return localStorage.getItem('arshLastTab') || ''; } catch(_){ return ''; } })() || 'dashboard';
      if (![ 'dashboard','forms','reports','users','settings' ].includes(initial)) initial = 'dashboard';
      setHash(initial);
      arRenderTab(initial);
    }
  }

  // Export API
  window.ARSH_ROUTER = { setHash: setHash, routeFromHash: routeFromHash, arRenderTab: arRenderTab, setActive: setActive, init: init };
})();

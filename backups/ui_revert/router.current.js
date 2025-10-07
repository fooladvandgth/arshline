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
    try { console.debug('[ARSH][NAV][routeFromHash] raw="'+raw+'" hash="'+location.hash+'" ts='+Date.now()); } catch(_){ }
    if (!raw){ arRenderTab('dashboard'); return; }
    var parts = raw.split('/');
    // Normalize first segment to ignore query (e.g., "messaging?tab=sms" -> "messaging")
    var seg0 = (parts[0]||'').split('?')[0];
    try { console.debug('[ARSH][NAV] seg0(before-alias)="'+seg0+'" parts='+JSON.stringify(parts)); } catch(_){ }
    // Legacy alias support (older builds / saved links). Map legacy route tokens to current ones.
    if (seg0 === 'messages') seg0 = 'messaging';
    try { console.debug('[ARSH][NAV] seg0(after-alias)="'+seg0+'"'); } catch(_){ }
    // Support nested route: users/ug (query params allowed like ?tab=...)
    var seg1 = (parts[1]||'').split('?')[0];
    try { console.debug('[ARSH][NAV] seg1="'+seg1+'"'); } catch(_){ }
    if (seg0==='users' && seg1==='ug'){
      try { if (typeof window.renderUsersUG === 'function') { window.renderUsersUG(); return; } } catch(_){}
      arRenderTab('users'); return;
    }
    if (seg0==='submissions'){ arRenderTab('forms'); return; }
    if (['dashboard','forms','reports','users','settings','messaging'].includes(seg0)){ arRenderTab(seg0); return; }
    if (seg0==='builder' && parts[1]){ var id = parseInt(parts[1]||'0'); if (id) { try { window.renderFormBuilder && window.renderFormBuilder(id); } catch(_){ } return; } }
    if (seg0==='editor' && parts[1]){ var id2 = parseInt(parts[1]||'0'); var idx = parseInt(parts[2]||'0'); if (id2) { try { window.renderFormEditor && window.renderFormEditor(id2, { index: isNaN(idx)?0:idx }); } catch(_){ } return; } }
    if (seg0==='preview' && parts[1]){ var id3 = parseInt(parts[1]||'0'); if (id3) { try { window.renderFormPreview && window.renderFormPreview(id3); } catch(_){ } return; } }
    if (seg0==='results' && parts[1]){ var id4 = parseInt(parts[1]||'0'); if (id4) { try { window.renderFormResults && window.renderFormResults(id4); } catch(_){ } return; } }
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
      // Apply same alias normalization to persisted initial tab
      if (initial === 'messages') initial = 'messaging';
      if (![ 'dashboard','forms','reports','users','settings','messaging' ].includes(initial)) initial = 'dashboard';
      setHash(initial);
      arRenderTab(initial);
    }
  }

  // Export API
  window.ARSH_ROUTER = { setHash: setHash, routeFromHash: routeFromHash, arRenderTab: arRenderTab, setActive: setActive, init: init };
})();

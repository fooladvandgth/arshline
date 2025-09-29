/*
  ARSHLINE Debug Logger
  - Toggle with window.ARSHDBG = 0/1 (default 1)
  - Function dlog(...args) prefixes logs with [ARSHDBG]
*/
(function DebugLogger(){
  if (typeof window.ARSHDBG === 'undefined') {
    window.ARSHDBG = 1; // default ON, can be toggled at runtime
  }
  window.dlog = function(){
    if (!window.ARSHDBG) return;
    try { console.log.apply(console, ['[ARSHDBG]'].concat(Array.prototype.slice.call(arguments))); }
    catch(_) { /* no-op */ }
  };
})();

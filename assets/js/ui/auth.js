/* =========================================================================
   FILE: assets/js/ui/auth.js
   Purpose: Centralized 401 handler with toast and redirect action
   Exports: window.handle401 (back-compat), window.ARSHLINE.Auth.handle401
   Guards: ARSH_AUTH_INIT
   ========================================================================= */
(function(){
  if (typeof window === 'undefined') return;
  if (window.ARSH_AUTH_INIT) return; window.ARSH_AUTH_INIT = true;

  function handle401(){
    try {
      if (typeof window.notify === 'function'){
        window.notify('نشست شما منقضی شده یا دسترسی کافی ندارید.', {
          type: 'error', duration: 5000, actionLabel: 'ورود',
          onAction: function(){ if (window.ARSHLINE_LOGIN_URL) location.href = window.ARSHLINE_LOGIN_URL; }
        });
      } else if (window.ARSHLINE && typeof window.ARSHLINE.notify === 'function'){
        window.ARSHLINE.notify('نشست شما منقضی شده یا دسترسی کافی ندارید.', {
          type: 'error', duration: 5000, actionLabel: 'ورود',
          onAction: function(){ if (window.ARSHLINE_LOGIN_URL) location.href = window.ARSHLINE_LOGIN_URL; }
        });
      } else {
        alert('401 Unauthorized: لطفاً وارد شوید.');
      }
    } catch(_){ }
  }

  // Public API
  window.ARSHLINE = window.ARSHLINE || {};
  window.ARSHLINE.Auth = { handle401: handle401 };
  // Back-compat global
  window.handle401 = handle401;
})();

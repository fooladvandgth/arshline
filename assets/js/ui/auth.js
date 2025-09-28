(function(){
  'use strict';
  function handle401(){
    try {
      var loginUrl = (typeof ARSHLINE_LOGIN_URL !== 'undefined' && ARSHLINE_LOGIN_URL) ||
                     (window.ARSHLINE_DASHBOARD && window.ARSHLINE_DASHBOARD.loginUrl) || '';
      var notify = (window.ARSH && window.ARSH.UI && window.ARSH.UI.notify) || window.notify;
      if (typeof notify === 'function'){
        notify('نشست شما منقضی شده یا دسترسی کافی ندارید.', {
          type: 'error',
          duration: 5000,
          actionLabel: loginUrl ? 'ورود' : undefined,
          onAction: function(){ if (loginUrl) location.href = loginUrl; }
        });
      } else {
        alert('401 Unauthorized: لطفاً وارد شوید.');
        if (loginUrl) location.href = loginUrl;
      }
    } catch (_) {
      try { console.warn('handle401 fallback'); } catch(__){}
    }
  }
  try {
    window.ARSH = window.ARSH || {};
    window.ARSH.Auth = window.ARSH.Auth || {};
    window.ARSH.Auth.handle401 = handle401;
    // Back-compat global
    if (typeof window.handle401 !== 'function') window.handle401 = handle401;
  } catch(_){ }
})();

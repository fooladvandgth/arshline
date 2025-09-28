(function(){
  'use strict';
  function ensureToastWrap(){
    var w = document.getElementById('arToastWrap');
    if (!w){
      w = document.createElement('div');
      w.id = 'arToastWrap';
      w.className = 'ar-toast-wrap';
      document.body.appendChild(w);
    }
    return w;
  }
  function notify(message, opts){
    try {
      var wrap = ensureToastWrap();
      var el = document.createElement('div');
      var type = (typeof opts === 'string') ? opts : (opts && opts.type) || 'info';
      var variant = ['success','error','info','warn'].includes(type) ? type : 'info';
      el.className = 'ar-toast ar-toast--' + variant;
      var icon = document.createElement('span');
      icon.className = 'ar-toast-ic';
      icon.textContent = (variant==='success') ? '✔' : (variant==='error') ? '✖' : (variant==='warn') ? '⚠' : 'ℹ';
      var text = document.createElement('span');
      text.textContent = String(message || '');
      el.appendChild(icon);
      el.appendChild(text);
      var hasAction = opts && opts.actionLabel && typeof opts.onAction === 'function';
      if (hasAction){
        var btn = document.createElement('button');
        btn.textContent = String(opts.actionLabel || '');
        btn.style.cssText = 'margin-inline-start:.6rem;padding:.25rem .6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;';
        btn.addEventListener('click', function(){ try { opts.onAction(); } catch(_){}; try { el.remove(); } catch(_){} });
        el.appendChild(btn);
      }
      wrap.appendChild(el);
      var duration = (opts && opts.duration) || 2800;
      setTimeout(function(){ try { el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; } catch(_){} }, Math.max(200, duration - 500));
      setTimeout(function(){ try { el.remove(); } catch(_){} }, duration);
    } catch (e) {
      try { console.warn('notify fallback:', message); } catch(_){}
    }
  }
  try {
    window.ARSH = window.ARSH || {};
    window.ARSH.UI = window.ARSH.UI || {};
    window.ARSH.UI.notify = notify;
    // Back-compat global
    if (typeof window.notify !== 'function') window.notify = notify;
  } catch(_){ }
})();

/* =========================================================================
   FILE: assets/js/ui/notify.js
   Purpose: Toast notifications with back-compat global notify()
   Exports: window.ARSHLINE.notify and global window.notify
   Guards: ARSH_NOTIFY_INIT to prevent duplicate wrappers
   ========================================================================= */
(function(){
  if (typeof window === 'undefined') return;
  if (window.ARSH_NOTIFY_INIT) return;
  window.ARSH_NOTIFY_INIT = true;

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
    try { console.debug('[ARSH][UI] notify:', message, opts); } catch(_){}
    var wrap = ensureToastWrap();
    var el = document.createElement('div');
    var type = (typeof opts === 'string') ? opts : (opts && opts.type) || 'info';
    var variant = ['success','error','info','warn'].includes(type) ? type : 'info';
    el.className = 'ar-toast ar-toast--'+variant;
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
      btn.textContent = opts.actionLabel;
      btn.style.cssText = 'margin-inline-start:.6rem;padding:.25rem .6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;';
      btn.addEventListener('click', function(){ try { opts.onAction(); } catch(_){ } el.remove(); });
      el.appendChild(btn);
    }
    wrap.appendChild(el);
    var duration = (opts && opts.duration) || 2800;
    setTimeout(function(){ el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, Math.max(200, duration - 500));
    setTimeout(function(){ el.remove(); }, duration);
  }

  // Public API
  window.ARSHLINE = window.ARSHLINE || {};
  window.ARSHLINE.notify = notify;
  try { if (typeof window.notify !== 'function') window.notify = function(message, variant){ return notify(message, variant); }; } catch(_){ }
  // Back-compat global
  window.notify = notify;
})();

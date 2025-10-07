/* =========================================================================
   UI Module: Modern Cards (legacy ar-modern-cards)
   Purpose: Render the existing dashboard cards with identical HTML/CSS classes
            and wire click handlers. Keeps visuals 1:1 with current design.
   Usage:
     const el = ARSH_UI.renderModernCards({
       container: document.getElementById('mount'),
       items: [
         { id:'arCardFormBuilder', color:'blue', icon:'globe-outline', title:'فرم‌ساز پیشرفته', onClick: () => ... },
         ...
       ],
       scale: 0.6 // optional, falls back to CSS :root --ar-card-scale
     });
   ========================================================================= */
(function(global){
  if (!global.ARSH_UI) global.ARSH_UI = {};

  function toEl(html){ var d=document.createElement('div'); d.innerHTML = html.trim(); return d.firstElementChild; }

  function renderModernCards(opts){
    opts = opts || {};
    var container = opts.container || null;
    var items = Array.isArray(opts.items) ? opts.items : [];
    var scale = (typeof opts.scale === 'number' && opts.scale > 0) ? opts.scale : null;

    // Build HTML with the exact class names expected by CSS
    var html = '<div class="ar-modern-cards">' + items.map(function(it){
      var id = it.id ? (' id="'+String(it.id)+'"') : '';
      var mod = it.color ? (' ar-card--'+String(it.color)) : '';
      var icon = it.icon ? ('<div class="icon"><ion-icon name="'+String(it.icon)+'"></ion-icon></div>') : '';
      var title = it.title ? String(it.title) : '';
      return '<div class="ar-card'+mod+'"'+id+'>' + icon + '<div class="content"><h2>'+title+'</h2></div></div>';
    }).join('') + '</div>';

    var root = toEl(html);
    if (scale != null){ try { root.style.setProperty('--ar-card-scale', String(scale)); } catch(_){ /* noop */ } }

    // Attach clicks
    items.forEach(function(it){
      var el = null;
      try { el = root.querySelector(it.id ? ('#'+CSS.escape(String(it.id))) : ''); } catch(_){ }
      if (!el){
        // fallback: match by title if no id provided
        try { el = Array.from(root.querySelectorAll('.ar-card .content h2')).map(function(h){ return h.closest('.ar-card'); }).find(function(c){ return c && c.textContent && c.textContent.trim() === String(it.title||'').trim(); }); } catch(_){ el = null; }
      }
      if (el && typeof it.onClick === 'function'){
        el.addEventListener('click', function(ev){ try { ev.preventDefault(); } catch(_){ } try{ it.onClick(); } catch(_){ } });
      }
    });

    if (container){
      // Clear and mount
      try { while(container.firstChild) container.removeChild(container.firstChild); } catch(_){ }
      container.appendChild(root);
    }
    return root;
  }

  global.ARSH_UI.renderModernCards = renderModernCards;
})(window);

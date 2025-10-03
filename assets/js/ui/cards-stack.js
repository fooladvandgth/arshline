(function(){
  function renderCardsStack(target, items){
    var root = (typeof target === 'string') ? document.querySelector(target) : target;
    if (!root) return null;
    var container = document.createElement('div');
    container.className = 'ar-cstack-container';
    (items||[]).forEach(function(it){
      var card = document.createElement('div');
      card.className = 'ar-cstack-card';
      card.tabIndex = 0;
      card.setAttribute('role','button');
      if (it.onClick) card.addEventListener('click', it.onClick);
      card.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); if (it.onClick) it.onClick(e); }});

      var h3 = document.createElement('h3'); h3.className = 'ar-cstack-title'; h3.textContent = it.title || '';
      var bar = document.createElement('div'); bar.className = 'ar-cstack-bar';
      var empty = document.createElement('div'); empty.className = 'ar-cstack-emptybar';
      var filled = document.createElement('div'); filled.className = 'ar-cstack-filledbar';
      bar.appendChild(empty); bar.appendChild(filled);
      var circle = document.createElement('div'); circle.className = 'ar-cstack-circle';
      circle.innerHTML = '<svg class="ar-cstack-svg" xmlns="http://www.w3.org/2000/svg" width="120" height="120"><circle class="ar-cstack-stroke" cx="60" cy="60" r="50"/></svg>';

      card.appendChild(h3); card.appendChild(bar); card.appendChild(circle);
      container.appendChild(card);
    });
    // Clear and inject
    root.innerHTML = '';
    root.appendChild(container);
    return container;
  }

  // Expose globally in ARSH namespace
  try {
    window.ARSH_UI = window.ARSH_UI || {};
    window.ARSH_UI.renderCardsStack = renderCardsStack;
  } catch(_){ }
})();
(function(window, document){
  'use strict';
  if (!window.ARSH || !ARSH.Tools || !ARSH.Tools.register) return;

  function defaults(){
    return { type:'multiple_choice', label:'سوال چندگزینه‌ای', options:[{ label:'گزینه 1', value:'opt_1', second_label:'', media_url:'' }], multiple:false, required:false, vertical:true, randomize:false, numbered:true };
  }

  function renderEditor(field, ctx){
    try {
      field = field || defaults();
      var sWrap = (ctx && ctx.wrappers && ctx.wrappers.settings) || document.querySelector('.ar-settings');
      var pWrap = (ctx && ctx.wrappers && ctx.wrappers.preview) || document.querySelector('.ar-preview');
      if (!sWrap || !pWrap) return false;

      sWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">سوال چندگزینه‌ای</div>',
        '<div class="field" style="margin-bottom:.6rem;">',
          '<label class="hint">متن سؤال</label>',
          '<div id="fQuestionRich" contenteditable="true" style="min-height:44px;padding:.5rem;border:1px solid var(--border);border-radius:8px;background:var(--surface)"></div>',
        '</div>',
        '<div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">',
          '<label class="hint">اجباری</label>',
          '<div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
            '<label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcRequired" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>',
          '</div>',
        '</div>',
        '<div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">',
          '<label class="hint">چندان‌انتخابی</label>',
          '<div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
            '<label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcMultiple" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>',
          '</div>',
        '</div>',
        '<div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">',
          '<label class="hint">نمایش به‌صورت عمودی</label>',
          '<div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
            '<label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcVertical" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>',
          '</div>',
        '</div>',
        '<div class="field" style="display:flex;gap:8px;align-items:center;margin-bottom:.6rem;">',
          '<label class="hint">همگن‌سازی گزینه‌ها (تصادفی‌سازی)</label>',
          '<div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
            '<label class="vc-small-switch vc-rtl"><input type="checkbox" id="mcRandomize" class="vc-switch-input" /><span class="vc-switch-label" data-on="بله" data-off="خیر"></span><span class="vc-switch-handle"></span></label>',
          '</div>',
        '</div>',
        '<div style="margin-top:.6rem;margin-bottom:.6rem;">',
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">',
            '<div class="hint">گزینه‌ها</div>',
            '<button id="mcAddOption" class="ar-btn" aria-label="افزودن گزینه" title="افزودن گزینه" style="width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:1.2rem;line-height:1;">+</button>',
          '</div>',
          '<div id="mcOptionsList" style="display:flex;flex-direction:column;gap:.5rem;"></div>',
        '</div>',
        '<div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>'
      ].join('');

      pWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>',
        '<div id="mcPreview" style="margin-bottom:.6rem;max-width:100%;overflow:hidden"></div>'
      ].join('');

      function sanitize(html){ try{ var d=document.createElement('div'); d.innerHTML=String(html||''); Array.from(d.querySelectorAll('script,style,iframe')).forEach(function(n){n.remove();}); return d.innerHTML; }catch(_){ return html||''; } }
      function updateHiddenProps(p){ var el=document.querySelector('#arCanvas .ar-item'); if(el) el.setAttribute('data-props', JSON.stringify(p)); }

      var opts = field.options || field.choices || [{ label:'گزینه 1', value:'opt_1', media_url:'', second_label:'' }];
      field.options = opts;

      var qEl = document.getElementById('fQuestionRich'); if (qEl){ qEl.innerHTML = sanitize(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitize(qEl.innerHTML); updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var mcRequired = document.getElementById('mcRequired'); if (mcRequired){ mcRequired.checked = !!field.required; mcRequired.addEventListener('change', function(){ field.required = !!mcRequired.checked; updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var mcMultiple = document.getElementById('mcMultiple'); if (mcMultiple){ mcMultiple.checked = !!field.multiple; mcMultiple.addEventListener('change', function(){ field.multiple = !!mcMultiple.checked; updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var mcVertical = document.getElementById('mcVertical'); if (mcVertical){ mcVertical.checked = (field.vertical !== false); mcVertical.addEventListener('change', function(){ field.vertical = !!mcVertical.checked; updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var mcRandomize = document.getElementById('mcRandomize'); if (mcRandomize){ mcRandomize.checked = !!field.randomize; mcRandomize.addEventListener('change', function(){ field.randomize = !!mcRandomize.checked; updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }

      var mcList = document.getElementById('mcOptionsList');
      function renderOptionsList(){
        if (!mcList) return;
        mcList.innerHTML = '';
        opts.forEach(function(o, i){
          var html = ''
            + '<div class="card" data-idx="'+i+'" style="padding:.35rem;display:grid;grid-template-columns:auto 1fr auto;grid-auto-rows:auto;gap:.4rem;align-items:center;">'
            +   '<div class="mc-row-tools" style="display:flex;gap:.35rem;align-items:center;">'
            +     '<button class="ar-btn mcAddHere" data-idx="'+i+'" aria-label="افزودن گزینه بعد از این" title="افزودن" style="width:28px;height:28px;border-radius:8px;padding:0;line-height:1;font-size:1.1rem;display:inline-flex;align-items:center;justify-content:center;">+</button>'
            +     '<button class="ar-btn ar-btn--soft mcRemove" data-idx="'+i+'" aria-label="حذف این گزینه" title="حذف" style="width:28px;height:28px;border-radius:8px;padding:0;line-height:1;font-size:1.1rem;display:inline-flex;align-items:center;justify-content:center;">−</button>'
            +   '</div>'
            +   '<input type="text" class="ar-input" data-role="mc-label" placeholder="متن گزینه" value="'+(o.label||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'" style="min-width:120px;max-width:100%;" />'
            +   '<span class="ar-dnd-handle" title="جابجایی" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;">≡</span>'
            +   '<input type="text" class="ar-input" data-role="mc-second" placeholder="برچسب دوم (اختیاری)" value="'+(o.second_label||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'" style="grid-column:1 / -1;min-width:120px;max-width:100%;" />'
            + '</div>';
          var div = document.createElement('div'); div.innerHTML = html; mcList.appendChild(div.firstChild);
        });
        // Bind input events
        Array.from(mcList.querySelectorAll('[data-role="mc-label"]')).forEach(function(inp, idx){ inp.addEventListener('input', function(){ opts[idx].label = inp.value; updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        Array.from(mcList.querySelectorAll('[data-role="mc-second"]')).forEach(function(inp, idx){ inp.addEventListener('input', function(){ opts[idx].second_label = inp.value; updateHiddenProps(field); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        Array.from(mcList.querySelectorAll('.mcAddHere')).forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); var ii = parseInt(btn.getAttribute('data-idx')||'0'); var n = opts.length + 1; var newOpt = { label:'گزینه '+n, value:'opt_'+(Date.now()%100000), media_url:'', second_label:'' }; opts.splice(isNaN(ii)?opts.length:(ii+1), 0, newOpt); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        Array.from(mcList.querySelectorAll('.mcRemove')).forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); var ii = parseInt(btn.getAttribute('data-idx')||'0'); if (!isNaN(ii)) opts.splice(ii,1); if (!opts.length) opts.push({ label:'گزینه 1', value:'opt_1', media_url:'', second_label:'' }); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        // Drag sort
        function ensureSortable(cb){ if (window.Sortable) { cb(); return; } var s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js'; s.onload = function(){ cb(); }; document.head.appendChild(s); }
        ensureSortable(function(){
          try { if (window._mcSortable) window._mcSortable.destroy(); } catch(_){ }
          window._mcSortable = Sortable.create(mcList, {
            animation: 150,
            handle: '.ar-dnd-handle',
            draggable: '[data-idx]',
            onEnd: function(){
              try {
                var order = Array.from(mcList.children).map(function(el){ return parseInt(el.getAttribute('data-idx')||''); }).filter(function(n){ return !isNaN(n); });
                if (order.length){
                  var newOpts = order.map(function(i){ return opts[i]; });
                  opts.splice(0, opts.length); Array.prototype.push.apply(opts, newOpts);
                  field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true);
                }
              } catch(_){ }
            }
          });
        });
      }

      function renderMCPreview(p){
        var out = document.getElementById('mcPreview'); if (!out) return;
        var parts = [];
        // Question with numbering
        try {
          var showQ = p.question && String(p.question).trim();
          if (showQ){
            var qIndex = 1;
            try { var beforeCount = 0; (ctx.fields||[]).forEach(function(ff, i3){ if (i3 < ctx.idx){ var pp = ff.props || ff; var t = pp.type || ff.type || 'short_text'; if (t !== 'welcome' && t !== 'thank_you'){ beforeCount += 1; } } }); qIndex = beforeCount + 1; } catch(_){ qIndex = (ctx.idx+1); }
            var numPrefix = (p.numbered !== false ? (qIndex + '. ') : '');
            var sanitized = sanitize(showQ || '');
            parts.push('<div class="hint" style="margin-bottom:.25rem">'+numPrefix+sanitized+'</div>');
          }
        } catch(_){ }
        if (!p.options || !Array.isArray(p.options) || !p.options.length){ out.innerHTML = '<div class="hint">هنوز گزینه‌ای اضافه نشده است.</div>'; return; }
        var localOpts = (p.options || []).slice();
        if (p.randomize){ for (var z=localOpts.length-1; z>0; z--){ var j = Math.floor(Math.random()*(z+1)); var tmp=localOpts[z]; localOpts[z]=localOpts[j]; localOpts[j]=tmp; } }
        var type = p.multiple ? 'checkbox' : 'radio';
        var vertical = (p.vertical !== false);
        var html = '<div style="display:flex;flex-direction:'+(vertical?'column':'row')+';gap:.6rem;flex-wrap:wrap;align-items:flex-start;">';
        localOpts.forEach(function(o){
          var lbl = sanitize(o.label||'');
          var sec = o.second_label ? ('<div class="hint" style="font-size:.8rem;margin-'+(document.dir==='rtl'?'right':'left')+':1.9rem;">'+(o.second_label||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</div>') : '';
          html += '<div class="mc-opt" style="display:flex;flex-direction:column;gap:.25rem;max-width:100%;">'
               +    '<label style="display:flex;align-items:center;gap:.5rem;max-width:100%;"><input type="'+type+'" disabled /> <span>'+lbl+'</span></label>'
               +    sec
               +  '</div>';
        });
        html += '</div>';
        parts.push(html);
        out.innerHTML = parts.join('');
      }

      renderOptionsList();
      renderMCPreview(field);

      var addBtn = document.getElementById('mcAddOption'); if (addBtn){ addBtn.addEventListener('click', function(e){ e.preventDefault(); var n=opts.length+1; opts.push({ label:'گزینه '+n, value:'opt_'+(Date.now()%100000), media_url:'', second_label:'' }); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderMCPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var saveBtn = document.getElementById('arSaveFields'); if (saveBtn){ saveBtn.onclick = async function(){ var ok = await (ctx && ctx.saveFields ? ctx.saveFields() : Promise.resolve(false)); if (ok){ if (ctx && ctx.setDirty) ctx.setDirty(false); if (typeof window.renderFormBuilder==='function'){ window.renderFormBuilder(ctx.id); } } }; }
      return true;
    } catch(_){ return false; }
  }

  function renderPreview(field, ctx){ return false; }

  ARSH.Tools.register({
    type: 'multiple_choice',
    defaults: defaults(),
    renderEditor: renderEditor,
    renderPreview: renderPreview
  });
})(window, document);

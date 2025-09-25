(function(window, document){
  'use strict';
  if (!window.ARSH || !ARSH.Tools || !ARSH.Tools.register) return;

  function defaults(){
    return { type:'dropdown', label:'لیست کشویی', question:'', required:false, numbered:true, show_description:false, description:'', placeholder:'', options:[{ label:'گزینه 1', value:'opt_1' }], randomize:false, alpha_sort:false };
  }

  function renderEditor(field, ctx){
    try {
      field = field || defaults();
      var sWrap = (ctx && ctx.wrappers && ctx.wrappers.settings) || document.querySelector('.ar-settings');
      var pWrap = (ctx && ctx.wrappers && ctx.wrappers.preview) || document.querySelector('.ar-preview');
      if (!sWrap || !pWrap) return false;

      sWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">تنظیمات لیست کشویی</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
          '<label class="hint">سؤال</label>',
          '<div style="display:flex;gap:.35rem;align-items:center;margin-bottom:6px;">',
            '<button id="fQBold" class="ar-btn ar-btn--outline" type="button" title="پررنگ"><b>B</b></button>',
            '<button id="fQItalic" class="ar-btn ar-btn--outline" type="button" title="مورب"><i>I</i></button>',
            '<button id="fQUnder" class="ar-btn ar-btn--outline" type="button" title="زیرخط"><u>U</u></button>',
            '<input id="fQColor" type="color" title="رنگ" style="margin-inline-start:.25rem;width:36px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);" />',
          '</div>',
          '<div id="fQuestionRich" class="ar-input" contenteditable="true" style="min-height:60px;line-height:1.8;" placeholder="متن سؤال"></div>',
          '<div class="hint" style="font-size:.92em;color:var(--muted);margin-top:2px;">با تایپ <b>@</b> از پاسخ‌ها و متغیرها استفاده کنید.</div>',
        '</div>',
        '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">',
          '<span class="hint">اجباری</span>',
          '<label class="toggle-switch" title="اجباری" style="transform:scale(.9)">',
            '<input type="checkbox" id="fRequired">',
            '<span class="toggle-switch-background"></span>',
            '<span class="toggle-switch-handle"></span>',
          '</label>',
        '</div>',
        '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">',
          '<span class="hint">شماره‌گذاری سؤال</span>',
          '<label class="toggle-switch" title="نمایش شماره سؤال" style="transform:scale(.9)">',
            '<input type="checkbox" id="fNumbered">',
            '<span class="toggle-switch-background"></span>',
            '<span class="toggle-switch-handle"></span>',
          '</label>',
        '</div>',
        '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:6px">',
          '<span class="hint">توضیحات</span>',
          '<label class="toggle-switch" title="نمایش توضیحات" style="transform:scale(.9)">',
            '<input type="checkbox" id="fDescToggle">',
            '<span class="toggle-switch-background"></span>',
            '<span class="toggle-switch-handle"></span>',
          '</label>',
        '</div>',
        '<div class="field" id="fDescWrap" style="display:none">',
          '<textarea id="fDescText" class="ar-input" rows="2" placeholder="توضیح زیر سؤال"></textarea>',
        '</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">',
          '<label class="hint">متن راهنما (placeholder)</label>',
          '<input id="fHelp" class="ar-input" placeholder="انتخاب کنید"/>',
        '</div>',
        '<div class="field" style="display:flex;gap:10px;align-items:center;margin-top:8px">',
          '<span class="hint">مرتب‌سازی الفبایی</span>',
          '<label class="toggle-switch" title="مرتب‌سازی الفبایی" style="transform:scale(.9)">',
            '<input type="checkbox" id="fAlphaSort">',
            '<span class="toggle-switch-background"></span>',
            '<span class="toggle-switch-handle"></span>',
          '</label>',
        '</div>',
        '<div class="field" style="display:flex;gap:10px;align-items:center;margin-top:8px">',
          '<span class="hint">تصادفی‌سازی</span>',
          '<label class="toggle-switch" title="تصادفی‌سازی" style="transform:scale(.9)">',
            '<input type="checkbox" id="fRandomize">',
            '<span class="toggle-switch-background"></span>',
            '<span class="toggle-switch-handle"></span>',
          '</label>',
        '</div>',
        '<div style="margin-top:.6rem;margin-bottom:.6rem;">',
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">',
            '<div class="hint">گزینه‌ها</div>',
            '<button id="ddAddOption" class="ar-btn" aria-label="افزودن گزینه" title="افزودن گزینه" style="width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:1.2rem;line-height:1;">+</button>',
          '</div>',
          '<div id="ddOptionsList" style="display:flex;flex-direction:column;gap:.5rem;"></div>',
        '</div>',
        '<div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>'
      ].join('');

      pWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>',
        '<div id="ddPreview" style="margin-bottom:.6rem;max-width:100%;overflow:hidden"></div>'
      ].join('');

      function sanitize(html){ try{ var d=document.createElement('div'); d.innerHTML=String(html||''); Array.from(d.querySelectorAll('script,style,iframe')).forEach(function(n){n.remove();}); return d.innerHTML; }catch(_){ return html||''; } }
      function updateHiddenProps(p){ var el=document.querySelector('#arCanvas .ar-item'); if(el) el.setAttribute('data-props', JSON.stringify(p)); }

      var opts = Array.isArray(field.options) ? field.options : [{ label:'گزینه 1', value:'opt_1' }];
      field.options = opts;

      var qEl = document.getElementById('fQuestionRich'); if (qEl){ qEl.innerHTML = sanitize(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitize(qEl.innerHTML); updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var req = document.getElementById('fRequired'); if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var num = document.getElementById('fNumbered'); if (num){ num.checked = field.numbered !== false; num.addEventListener('change', function(){ field.numbered = !!num.checked; updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var dTg = document.getElementById('fDescToggle'); var dWrap = document.getElementById('fDescWrap'); if (dTg){ dTg.checked = !!field.show_description; if (dWrap) dWrap.style.display = field.show_description ? 'block' : 'none'; dTg.addEventListener('change', function(){ field.show_description = !!dTg.checked; if (dWrap) dWrap.style.display = field.show_description ? 'block' : 'none'; updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var dTx = document.getElementById('fDescText'); if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var help = document.getElementById('fHelp'); if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){ field.placeholder = help.value; updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
    var alpha = document.getElementById('fAlphaSort');
    var rnd = document.getElementById('fRandomize');
    if (alpha){ alpha.checked = !!field.alpha_sort; }
    if (rnd){ rnd.checked = !!field.randomize; }
    // enforce mutual exclusivity at init
    if (alpha && rnd){ if (alpha.checked && rnd.checked){ rnd.checked = false; field.randomize = false; } }
    if (alpha){ alpha.addEventListener('change', function(){
      field.alpha_sort = !!alpha.checked;
      if (field.alpha_sort && rnd){ rnd.checked = false; field.randomize = false; }
      updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true);
    }); }
    if (rnd){ rnd.addEventListener('change', function(){
      field.randomize = !!rnd.checked;
      if (field.randomize && alpha){ alpha.checked = false; field.alpha_sort = false; }
      updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true);
    }); }

      var list = document.getElementById('ddOptionsList');
      function renderOptionsList(){
        if (!list) return;
        list.innerHTML = '';
        opts.forEach(function(o, i){
          var html = ''
            + '<div class="card" data-idx="'+i+'" style="padding:.35rem;display:grid;grid-template-columns:auto 1fr auto;grid-auto-rows:auto;gap:.4rem;align-items:center;">'
            +   '<div class="dd-row-tools" style="display:flex;gap:.35rem;align-items:center;">'
            +     '<button class="ar-btn ddAddHere" data-idx="'+i+'" aria-label="افزودن گزینه بعد از این" title="افزودن" style="width:28px;height:28px;border-radius:8px;padding:0;line-height:1;font-size:1.1rem;display:inline-flex;align-items:center;justify-content:center;">+</button>'
            +     '<button class="ar-btn ar-btn--soft ddRemove" data-idx="'+i+'" aria-label="حذف این گزینه" title="حذف" style="width:28px;height:28px;border-radius:8px;padding:0;line-height:1;font-size:1.1rem;display:inline-flex;align-items:center;justify-content:center;">−</button>'
            +   '</div>'
            +   '<input type="text" class="ar-input" data-role="dd-label" placeholder="متن گزینه" value="'+String(o.label||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'" style="min-width:120px;max-width:100%;" />'
            +   '<span class="ar-dnd-handle" title="جابجایی" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;">≡</span>'
            + '</div>';
          var div = document.createElement('div'); div.innerHTML = html; list.appendChild(div.firstChild);
        });
        Array.from(list.querySelectorAll('[data-role="dd-label"]')).forEach(function(inp, idx){ inp.addEventListener('input', function(){ opts[idx].label = inp.value; updateHiddenProps(field); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        Array.from(list.querySelectorAll('.ddAddHere')).forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); var ii=parseInt(btn.getAttribute('data-idx')||'0'); var n=opts.length+1; var newOpt={ label:'گزینه '+n, value:'opt_'+(Date.now()%100000) }; opts.splice(isNaN(ii)?opts.length:(ii+1),0,newOpt); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        Array.from(list.querySelectorAll('.ddRemove')).forEach(function(btn){ btn.addEventListener('click', function(e){ e.preventDefault(); var ii=parseInt(btn.getAttribute('data-idx')||'0'); if(!isNaN(ii)) opts.splice(ii,1); if(!opts.length) opts.push({ label:'گزینه 1', value:'opt_1' }); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); });
        function ensureSortable(cb){ if (window.Sortable) { cb(); return; } var s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js'; s.onload=function(){ cb(); }; document.head.appendChild(s); }
        ensureSortable(function(){
          try { if (window._ddSortable) window._ddSortable.destroy(); } catch(_){ }
          window._ddSortable = Sortable.create(list, {
            animation:150,
            handle: '.ar-dnd-handle',
            draggable: '[data-idx]',
            onEnd: function(){
              try {
                var order = Array.from(list.children).map(function(el){ return parseInt(el.getAttribute('data-idx')||''); }).filter(function(n){ return !isNaN(n); });
                if (order.length){ var nopts = order.map(function(i){ return opts[i]; }); opts.splice(0,opts.length); Array.prototype.push.apply(opts, nopts); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }
              } catch(_){ }
            }
          });
        });
      }

      function renderDDPreview(p){
        var out = document.getElementById('ddPreview'); if (!out) return;
        var parts = [];
        try {
          var showQ = p.question && String(p.question).trim();
          if (showQ){
            var qIndex = 1;
            try { var before=0; (ctx.fields||[]).forEach(function(ff,i3){ if(i3<ctx.idx){ var pp=ff.props||ff; var t=pp.type||ff.type||'short_text'; if (t!=='welcome'&&t!=='thank_you'){ before+=1; } } }); qIndex=before+1; } catch(_){ qIndex=(ctx.idx+1); }
            var numPrefix = (p.numbered !== false ? (qIndex + '. ') : '');
            parts.push('<div class="hint" style="margin-bottom:.25rem">'+numPrefix+sanitize(showQ||'')+'</div>');
          }
        } catch(_){ }
        var local = (p.options||[]).slice();
        if (p.alpha_sort){ local.sort(function(a,b){ return String(a.label||'').localeCompare(String(b.label||''), 'fa'); }); }
        if (p.randomize){ for (var z=local.length-1; z>0; z--){ var j=Math.floor(Math.random()*(z+1)); var tmp=local[z]; local[z]=local[j]; local[j]=tmp; } }
  var html = '<select class="ar-input" style="width:100%">';
        html += '<option value="">'+String(p.placeholder||'انتخاب کنید').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</option>';
        local.forEach(function(o){ html += '<option>'+String(o.label||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</option>'; });
        html += '</select>';
        parts.push(html);
        out.innerHTML = parts.join('');
      }

      renderOptionsList();
      renderDDPreview(field);

      var addBtn = document.getElementById('ddAddOption'); if (addBtn){ addBtn.addEventListener('click', function(e){ e.preventDefault(); var n=opts.length+1; opts.push({ label:'گزینه '+n, value:'opt_'+(Date.now()%100000) }); field.options = opts; updateHiddenProps(field); renderOptionsList(); renderDDPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var saveBtn = document.getElementById('arSaveFields'); if (saveBtn){ saveBtn.onclick = async function(){ var ok = await (ctx && ctx.saveFields ? ctx.saveFields() : Promise.resolve(false)); if (ok){ if (ctx && ctx.setDirty) ctx.setDirty(false); if (typeof window.renderFormBuilder==='function'){ window.renderFormBuilder(ctx.id); } } }; }
      return true;
    } catch(_){ return false; }
  }

  function renderPreview(field, ctx){ return false; }

  ARSH.Tools.register({
    type: 'dropdown',
    defaults: defaults(),
    renderEditor: renderEditor,
    renderPreview: renderPreview
  });
})(window, document);

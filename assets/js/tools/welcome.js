(function(window, document){
  'use strict';
  if (!window.ARSH || !ARSH.Tools || !ARSH.Tools.register) return;

  function defaults(){
    return { type:'welcome', label:'پیام خوش‌آمد', heading:'خوش آمدید', message:'', image_url:'' };
  }

  function renderEditor(field, ctx){
    try {
      field = field || defaults();
      var sWrap = (ctx && ctx.wrappers && ctx.wrappers.settings) || document.querySelector('.ar-settings');
      var pWrap = (ctx && ctx.wrappers && ctx.wrappers.preview) || document.querySelector('.ar-preview');
      if (!sWrap || !pWrap) return false;
      var esc = (ctx && ctx.escapeHtml) ? ctx.escapeHtml : function(s){ try { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); } catch(_){ return String(s||''); } };

      sWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">تنظیمات پیام خوش‌آمد</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
          '<label class="hint">تیتر</label>',
          '<input id="fHeading" class="ar-input" placeholder="مثال: خوش آمدید"/>',
        '</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
          '<label class="hint">متن</label>',
          '<textarea id="fMessage" class="ar-input" rows="3" placeholder="پیام خوش‌آمد"></textarea>',
        '</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
          '<label class="hint">آدرس تصویر (اختیاری)</label>',
          '<input id="fImage" class="ar-input" placeholder="https://..."/>',
        '</div>',
        '<div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>'
      ].join('');

      pWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>',
        '<div id="pvWrap"></div>'
      ].join('');

      function updateHiddenProps(p){ var el=document.querySelector('#arCanvas .ar-item'); if(el) el.setAttribute('data-props', JSON.stringify(p)); }
      function applyPreviewFrom(p){
        var wrap = document.getElementById('pvWrap'); if (!wrap) return;
        var img = (p.image_url && String(p.image_url).trim()) ? ('<div style="margin-bottom:.4rem"><img src="'+String(p.image_url).trim()+'" alt="" style="max-width:100%;border-radius:10px"/></div>') : '';
        var heading = (p.heading && String(p.heading).trim()) || '';
        var message = (p.message && String(p.message).trim()) || '';
        wrap.innerHTML = (heading?('<div class="title" style="margin-bottom:.35rem;">'+esc(heading)+'</div>'):'') + img + (message?('<div class="hint">'+esc(message)+'</div>'):'');
      }

      var h = document.getElementById('fHeading'); var m = document.getElementById('fMessage'); var i = document.getElementById('fImage');
      if (h){ h.value = field.heading || ''; h.addEventListener('input', function(){ field.heading = h.value; updateHiddenProps(field); applyPreviewFrom(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (m){ m.value = field.message || ''; m.addEventListener('input', function(){ field.message = m.value; updateHiddenProps(field); applyPreviewFrom(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (i){ i.value = field.image_url || ''; i.addEventListener('input', function(){ field.image_url = i.value; updateHiddenProps(field); applyPreviewFrom(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }

      applyPreviewFrom(field);
      var saveBtn = document.getElementById('arSaveFields'); if (saveBtn){ saveBtn.onclick = async function(){ var ok = await (ctx && ctx.saveFields ? ctx.saveFields() : Promise.resolve(false)); if (ok){ if (ctx && ctx.setDirty) ctx.setDirty(false); if (typeof window.renderFormBuilder==='function'){ window.renderFormBuilder(ctx.id); } } }; }
      return true;
    } catch(_){ return false; }
  }

  function renderPreview(field, ctx){ return false; }

  ARSH.Tools.register({
    type: 'welcome',
    defaults: defaults(),
    renderEditor: renderEditor,
    renderPreview: renderPreview
  });
})(window, document);

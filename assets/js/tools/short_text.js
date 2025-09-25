(function(window, document){
  'use strict';
  if (!window.ARSH || !ARSH.Tools || !ARSH.Tools.register) return;

  function defaults(){
    return { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
  }

  function renderEditor(field, ctx){
    try {
      field = field || defaults();
      var sWrap = (ctx && ctx.wrappers && ctx.wrappers.settings) || document.querySelector('.ar-settings');
      var pWrap = (ctx && ctx.wrappers && ctx.wrappers.preview) || document.querySelector('.ar-preview');
      if (!sWrap || !pWrap) return false;

      sWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">تنظیمات پاسخ کوتاه</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
          '<label class="hint">سؤال</label>',
          '<div style="display:flex;gap:.35rem;align-items:center;margin-bottom:6px;">',
            '<button id="fQBold" class="ar-btn ar-btn--outline" type="button" title="پررنگ"><b>B</b></button>',
            '<button id="fQItalic" class="ar-btn ar-btn--outline" type="button" title="مورب"><i>I</i></button>',
            '<button id="fQUnder" class="ar-btn ar-btn--outline" type="button" title="زیرخط"><u>U</u></button>',
            '<input id="fQColor" type="color" title="رنگ" style="margin-inline-start:.25rem;width:36px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);" />',
          '</div>',
          '<div id="fQuestionRich" class="ar-input" contenteditable="true" style="min-height:60px;line-height:1.8;" placeholder="متن سؤال"></div>',
        '</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
          '<label class="hint">نوع ورودی</label>',
          '<select id="fType" class="ar-select">',
            '<option value="free_text">متن آزاد</option>',
            '<option value="email">ایمیل</option>',
            '<option value="numeric">عدد</option>',
            '<option value="date_jalali">تاریخ شمسی</option>',
            '<option value="date_greg">تاریخ میلادی</option>',
            '<option value="time">زمان</option>',
            '<option value="mobile_ir">موبایل ایران</option>',
            '<option value="mobile_intl">موبایل بین‌المللی</option>',
            '<option value="national_id_ir">کد ملی ایران</option>',
            '<option value="postal_code_ir">کد پستی ایران</option>',
            '<option value="tel">تلفن</option>',
            '<option value="fa_letters">حروف فارسی</option>',
            '<option value="en_letters">حروف انگلیسی</option>',
            '<option value="ip">IP</option>',
          '</select>',
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
          '<div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
            '<label class="vc-small-switch vc-rtl">',
              '<input type="checkbox" id="fDescToggle" class="vc-switch-input"/>',
              '<span class="vc-switch-label" data-on="بله" data-off="خیر"></span>',
              '<span class="vc-switch-handle"></span>',
            '</label>',
          '</div>',
        '</div>',
        '<div class="field" id="fDescWrap" style="display:none">',
          '<textarea id="fDescText" class="ar-input" rows="2" placeholder="توضیح زیر سؤال"></textarea>',
        '</div>',
        '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">',
          '<label class="hint">متن راهنما (placeholder)</label>',
          '<input id="fHelp" class="ar-input" placeholder="مثال: پاسخ را وارد کنید"/>',
        '</div>',
        '<div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>'
      ].join('');

      pWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>',
        '<div id="pvQuestion" class="hint" style="display:none;margin-bottom:.25rem"></div>',
        '<div id="pvDesc" class="hint" style="display:none;margin-bottom:.35rem"></div>',
        '<input id="pvInput" class="ar-input" style="width:100%" />',
        '<div id="pvHelp" class="hint" style="display:none"></div>',
        '<div id="pvErr" class="hint" style="color:#b91c1c;margin-top:.3rem"></div>'
      ].join('');

      function updateHiddenProps(p){ var el=document.querySelector('#arCanvas .ar-item'); if(el) el.setAttribute('data-props', JSON.stringify(p)); }
      function applyPreviewFrom(p){
        var fmt = p.format || 'free_text';
        var attrs = (ctx && ctx.inputAttrsByFormat) ? ctx.inputAttrsByFormat(fmt) : { type:'text' };
        var inp = document.getElementById('pvInput'); if (!inp) return;
        // Replace input if needed (ensure input not textarea)
        if (inp.tagName !== 'INPUT'){
          var parent = inp.parentNode; var clone = document.createElement('input'); clone.id='pvInput'; clone.className='ar-input'; parent.replaceChild(clone, inp); inp = clone;
        }
        inp.value='';
        try { inp.setAttribute('type', (attrs && attrs.type) ? attrs.type : 'text'); } catch(_){ }
        if (attrs && attrs.inputmode) inp.setAttribute('inputmode', attrs.inputmode); else inp.removeAttribute('inputmode');
        if (attrs && attrs.pattern) inp.setAttribute('pattern', attrs.pattern); else inp.removeAttribute('pattern');
        var ph = (p.placeholder && p.placeholder.trim()) ? p.placeholder : ((fmt==='free_text' && ctx && ctx.suggestPlaceholder) ? ctx.suggestPlaceholder(fmt) : 'پاسخ خود را بنویسید');
        try { inp.setAttribute('placeholder', ph || ''); } catch(_){ }
        var qNode = document.getElementById('pvQuestion'); if (qNode){ var showQ=(p.question && String(p.question).trim()); qNode.style.display = showQ ? 'block' : 'none'; var qIndex=1; try { var beforeCount=0; (ctx.fields||[]).forEach(function(ff,i3){ if(i3<ctx.idx){ var pp=ff.props||ff; var t=pp.type||ff.type||'short_text'; if (t!=='welcome'&&t!=='thank_you'){ beforeCount+=1; } } }); qIndex=beforeCount+1; } catch(_){ qIndex=(ctx.idx+1); } var numPrefix=(p.numbered ? (qIndex + '. ') : ''); var sanitized = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(showQ||'') : (showQ||''); qNode.innerHTML = showQ ? (numPrefix + sanitized) : ''; }
        var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent=p.description||''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; }
        try { if (typeof window.applyInputMask==='function'){ window.applyInputMask(inp, p); } } catch(_){ }
        if (fmt==='date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.pDatepicker){ try { jQuery(inp).pDatepicker({ format:'YYYY/MM/DD', initialValue:false }); } catch(e){} }
      }

      // Bind settings
      var sel = document.getElementById('fType');
      var req = document.getElementById('fRequired');
      var dTg = document.getElementById('fDescToggle');
      var dTx = document.getElementById('fDescText');
      var dWrap = document.getElementById('fDescWrap');
      var help = document.getElementById('fHelp');
      var qEl = document.getElementById('fQuestionRich');
      var qBold = document.getElementById('fQBold');
      var qItalic = document.getElementById('fQItalic');
      var qUnder = document.getElementById('fQUnder');
      var qColor = document.getElementById('fQColor');
      var numEl = document.getElementById('fNumbered');

      function sync(){ updateHiddenProps(field); applyPreviewFrom(field); }
      if (sel){ sel.value = field.format || 'free_text'; sel.addEventListener('change', function(){ field.format = sel.value || 'free_text'; var i=document.getElementById('pvInput'); if(i) i.value=''; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (dTg){ dTg.checked = !!field.show_description; if (dWrap) dWrap.style.display = field.show_description ? 'block' : 'none'; dTg.addEventListener('change', function(){ field.show_description = !!dTg.checked; if (dWrap) dWrap.style.display = field.show_description ? 'block' : 'none'; updateHiddenProps(field); applyPreviewFrom(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){ field.placeholder = help.value; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (qEl){ qEl.innerHTML = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(field.question || '') : (field.question || ''); qEl.addEventListener('input', function(){ field.question = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(qEl.innerHTML) : qEl.innerHTML; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      if (qBold){ qBold.addEventListener('click', function(e){ e.preventDefault(); try { document.execCommand('bold'); } catch(_){ } if(qEl){ field.question = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(qEl.innerHTML) : qEl.innerHTML; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); } }); }
      if (qItalic){ qItalic.addEventListener('click', function(e){ e.preventDefault(); try { document.execCommand('italic'); } catch(_){ } if(qEl){ field.question = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(qEl.innerHTML) : qEl.innerHTML; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); } }); }
      if (qUnder){ qUnder.addEventListener('click', function(e){ e.preventDefault(); try { document.execCommand('underline'); } catch(_){ } if(qEl){ field.question = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(qEl.innerHTML) : qEl.innerHTML; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); } }); }
      if (qColor){ qColor.addEventListener('input', function(){ try { document.execCommand('foreColor', false, qColor.value); } catch(_){ } if(qEl){ field.question = (ctx && ctx.sanitizeQuestionHtml) ? ctx.sanitizeQuestionHtml(qEl.innerHTML) : qEl.innerHTML; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); } }); }
      if (numEl){ numEl.checked = field.numbered !== false; field.numbered = numEl.checked; numEl.addEventListener('change', function(){ field.numbered = !!numEl.checked; sync(); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }

      applyPreviewFrom(field);
      var saveBtn = document.getElementById('arSaveFields'); if (saveBtn){ saveBtn.onclick = async function(){ var ok = await (ctx && ctx.saveFields ? ctx.saveFields() : Promise.resolve(false)); if (ok){ if (ctx && ctx.setDirty) ctx.setDirty(false); if (typeof window.renderFormBuilder==='function'){ window.renderFormBuilder(ctx.id); } } }; }
      return true;
    } catch(_){ return false; }
  }

  function renderPreview(field, ctx){ return false; }

  ARSH.Tools.register({
    type: 'short_text',
    defaults: defaults(),
    renderEditor: renderEditor,
    renderPreview: renderPreview
  });
})(window, document);

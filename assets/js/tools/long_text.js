(function(window, document){
  'use strict';
  window.ARSH = window.ARSH || {};
  if (!window.ARSH.Tools || !window.ARSH.Tools.register){ return; }

  function defaults(){
    return { type:'long_text', label:'پاسخ طولانی', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true, min_length:0, max_length:5000, media_upload:false };
  }

  function renderEditor(ctx){
    // ctx: { field, fields, idx, applyPreviewFrom, updateHiddenProps, setDirty, saveFields, notify, restUrl, restNonce }
    var field = ctx.field;
    var sWrap = document.querySelector('.ar-settings');
    var pWrap = document.querySelector('.ar-preview');
    if (sWrap){ sWrap.innerHTML = [
      '<div class="title" style="margin-bottom:.6rem;">تنظیمات متن بلند</div>',
      '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
      '  <label class="hint">سؤال</label>',
      '  <div style="display:flex;gap:.35rem;align-items:center;margin-bottom:6px;">',
      '    <button id="fQBold" class="ar-btn ar-btn--outline" type="button" title="پررنگ"><b>B</b></button>',
      '    <button id="fQItalic" class="ar-btn ar-btn--outline" type="button" title="مورب"><i>I</i></button>',
      '    <button id="fQUnder" class="ar-btn ar-btn--outline" type="button" title="زیرخط"><u>U</u></button>',
      '    <input id="fQColor" type="color" title="رنگ" style="margin-inline-start:.25rem;width:36px;height:32px;border-radius:8px;border:1px solid var(--border);background:var(--surface);" />',
      '  </div>',
      '  <div id="fQuestionRich" class="ar-input" contenteditable="true" style="min-height:60px;line-height:1.8;" placeholder="متن سؤال"></div>',
      '  <div class="hint" style="font-size:.92em;color:var(--muted);margin-top:2px;">با تایپ <b>@</b> از پاسخ‌ها و متغیرها استفاده کنید.</div>',
      '</div>',
      '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">',
      '  <span class="hint">اجباری</span>',
      '  <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
      '    <label class="vc-small-switch vc-rtl">',
      '      <input type="checkbox" id="fRequired" class="vc-switch-input" />',
      '      <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>',
      '      <span class="vc-switch-handle"></span>',
      '    </label>',
      '  </div>',
      '</div>',
      '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">',
      '  <span class="hint">عدم نمایش شماره‌ سؤال</span>',
      '  <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
      '    <label class="vc-small-switch vc-rtl">',
      '      <input type="checkbox" id="fHideNumber" class="vc-switch-input" />',
      '      <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>',
      '      <span class="vc-switch-handle"></span>',
      '    </label>',
      '  </div>',
      '</div>',
      '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">',
      '  <span class="hint">توضیحات</span>',
      '  <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
      '    <label class="vc-small-switch vc-rtl">',
      '      <input type="checkbox" id="fDescToggle" class="vc-switch-input" />',
      '      <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>',
      '      <span class="vc-switch-handle"></span>',
      '    </label>',
      '  </div>',
      '</div>',
      '<div class="field" id="fDescWrap" style="display:none">',
      '  <textarea id="fDescText" class="ar-input" rows="2" placeholder="توضیح زیر سؤال"></textarea>',
      '</div>',
      '<div class="field" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">',
      '  <label class="hint">متن راهنما (placeholder)</label>',
      '  <input id="fHelp" class="ar-input" placeholder="مثال: پاسخ را وارد کنید"/>',
      '</div>',
      '<div class="field" style="display:flex;gap:10px;margin-top:8px;align-items:center;">',
      '  <span class="hint">حداقل تعداد حروف</span>',
      '  <input id="fMinLen" class="ar-input" type="number" min="0" max="1000" style="width:80px" value="0" />',
      '  <span class="hint">حداکثر</span>',
      '  <input id="fMaxLen" class="ar-input" type="number" min="1" max="5000" style="width:80px" value="1000" />',
      '</div>',
      '<div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">',
      '  <span class="hint">امکان آپلود عکس</span>',
      '  <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">',
      '    <label class="vc-small-switch vc-rtl">',
      '      <input type="checkbox" id="fMediaUpload" class="vc-switch-input" />',
      '      <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>',
      '      <span class="vc-switch-handle"></span>',
      '    </label>',
      '  </div>',
      '</div>',
      '<div id="fMediaWrap" style="display:none;margin-top:8px;">',
      '  <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">',
      '    <label class="hint">آپلود تصویر سؤال (JPG/PNG تا 300KB)</label>',
      '    <input id="fImageFile" type="file" accept="image/jpeg,image/png" style="margin-bottom:4px" />',
      '    <div id="fImagePreviewWrap" style="width:200px;height:200px;overflow:hidden;display:' + (field.image_url? 'flex':'none') + ';align-items:center;justify-content:center;margin-bottom:6px;border-radius:10px;background:transparent">',
      '      <img id="fImagePreview" src="' + (field.image_url||'') + '" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:8px;" />',
      '    </div>',
      '    <span id="fImageError" class="hint" style="color:#b91c1c;display:none"></span>',
      '  </div>',
      '</div>',
      '<div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>'
    ].join(''); }

    if (pWrap){ pWrap.innerHTML = [
      '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>',
      '<div id="pvMedia" style="margin-bottom:6px">',
      (field.image_url ? '<div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:6px;border-radius:10px"><img id="pvImage" src="'+(field.image_url||'')+'" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:6px" /></div>' : ''),
      '</div>',
      '<div id="pvQuestion" class="hint" style="display:none;margin-bottom:.25rem"></div>',
      '<div id="pvDesc" class="hint" style="display:none;margin-bottom:.35rem"></div>',
      '<textarea id="pvInput" class="ar-input" style="width:100%" rows="4"></textarea>',
      '<div id="pvHelp" class="hint" style="display:none"></div>',
      '<div id="pvErr" class="hint" style="color:#b91c1c;margin-top:.3rem"></div>'
    ].join(''); }

    // Bind controls
    var qEl = document.getElementById('fQuestionRich');
    var req = document.getElementById('fRequired');
    var hideNum = document.getElementById('fHideNumber');
    var dTg = document.getElementById('fDescToggle');
    var dTx = document.getElementById('fDescText');
    var dWrap = document.getElementById('fDescWrap');
    var help = document.getElementById('fHelp');
    var minLen = document.getElementById('fMinLen');
    var maxLen = document.getElementById('fMaxLen');
    var mediaUp = document.getElementById('fMediaUpload');
    var mediaWrap = document.getElementById('fMediaWrap');
    var imgFile = document.getElementById('fImageFile');
    var imgPrev = document.getElementById('fImagePreview');
    var imgErr = document.getElementById('fImageError');

    function sanitizeQuestionHtml(html){ try{ var div=document.createElement('div'); div.innerHTML=html||''; Array.from(div.querySelectorAll('script,style,iframe')).forEach(function(n){ n.remove(); }); return div.innerHTML; }catch(_){ return ''; } }
    function updateHiddenProps(p){ var el=document.querySelector('#arCanvas .ar-item'); if(el) el.setAttribute('data-props', JSON.stringify(p)); }
    function applyPreviewFrom(p){
      var inp = document.getElementById('pvInput'); if (!inp) return;
      inp.value='';
      inp.setAttribute('placeholder', (p.placeholder && p.placeholder.trim()) ? p.placeholder : 'پاسخ را وارد کنید');
      inp.setAttribute('minlength', p.min_length || 0);
      inp.setAttribute('maxlength', p.max_length || 1000);
      inp.required = !!p.required;
      var mediaWrapPv = document.getElementById('pvMedia');
      if (mediaWrapPv){ if (p.image_url){ mediaWrapPv.innerHTML='<div style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:6px;border-radius:10px"><img src="'+p.image_url+'" style="max-width:200px;max-height:200px;width:auto;height:auto;display:block;border-radius:6px" /></div>'; mediaWrapPv.style.display='block'; } else { mediaWrapPv.innerHTML=''; mediaWrapPv.style.display='none'; } }
      var qNode=document.getElementById('pvQuestion'); if (qNode){ var showQ=(p.question && String(p.question).trim()); qNode.style.display=showQ?'block':'none'; var numPrefix=(p.numbered !== false ? '1. ' : ''); var sanitized=sanitizeQuestionHtml(showQ||''); qNode.innerHTML = showQ ? (numPrefix + sanitized) : ''; }
      var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent=p.description||''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; }
    }

    if (qEl){ qEl.innerHTML = sanitizeQuestionHtml(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    var qBold = document.getElementById('fQBold'); if (qBold){ qBold.addEventListener('click', function(e){ e.preventDefault(); try{ document.execCommand('bold'); }catch(_){} if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); } }); }
    var qItalic = document.getElementById('fQItalic'); if (qItalic){ qItalic.addEventListener('click', function(e){ e.preventDefault(); try{ document.execCommand('italic'); }catch(_){} if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); } }); }
    var qUnder = document.getElementById('fQUnder'); if (qUnder){ qUnder.addEventListener('click', function(e){ e.preventDefault(); try{ document.execCommand('underline'); }catch(_){} if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); } }); }
    var qColor = document.getElementById('fQColor'); if (qColor){ qColor.addEventListener('input', function(){ try{ document.execCommand('foreColor', false, qColor.value); }catch(_){} if(qEl){ field.question = sanitizeQuestionHtml(qEl.innerHTML); updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); } }); }

    if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required=!!req.checked; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    if (hideNum){ hideNum.checked = field.numbered === false; hideNum.addEventListener('change', function(){ field.numbered=!hideNum.checked; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    if (dTg){ dTg.checked=!!field.show_description; if (dWrap) dWrap.style.display = field.show_description ? 'block':'none'; dTg.addEventListener('change', function(){ field.show_description=!!dTg.checked; if(dWrap) dWrap.style.display = field.show_description ? 'block':'none'; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    if (dTx){ dTx.value = field.description||''; dTx.addEventListener('input', function(){ field.description=dTx.value; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    if (help){ help.value = field.placeholder||''; help.addEventListener('input', function(){ field.placeholder=help.value; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    if (minLen){ minLen.value = field.min_length || 0; minLen.addEventListener('input', function(){ field.min_length=parseInt(minLen.value)||0; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }
    if (maxLen){ maxLen.value = field.max_length || 1000; maxLen.addEventListener('input', function(){ field.max_length=parseInt(maxLen.value)||1000; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); }); }

    if (mediaUp){ mediaUp.checked = !!field.media_upload; if (mediaWrap) mediaWrap.style.display = mediaUp.checked ? 'block' : 'none'; mediaUp.addEventListener('change', function(){ field.media_upload = !!mediaUp.checked; if (mediaWrap) mediaWrap.style.display = mediaUp.checked ? 'block' : 'none'; if (!mediaUp.checked){ try { if (imgPrev){ imgPrev.src=''; imgPrev.style.display='none'; } } catch(_){} } updateHiddenProps(field); if(ctx.setDirty) ctx.setDirty(true); }); }

    // Image upload
    if (imgFile && imgPrev && imgErr){
      imgFile.addEventListener('change', function(){
        var file = imgFile.files[0]; if(!file) return;
        if (!['image/jpeg','image/png'].includes(file.type)){ imgErr.textContent='فقط JPG یا PNG مجاز است.'; imgErr.style.display='block'; return; }
        if (file.size > 307200){ imgErr.textContent='حداکثر حجم 300KB.'; imgErr.style.display='block'; return; }
        imgErr.style.display='none';
        var fd = new FormData(); fd.append('file', file);
        imgFile.disabled = true;
        fetch((ctx.restUrl||window.ARSHLINE_REST||'') + 'upload', { method:'POST', credentials:'same-origin', headers:{ 'X-WP-Nonce': (ctx.restNonce||window.ARSHLINE_NONCE||'') }, body: fd })
          .then(function(r){ if(!r.ok) return r.text().then(function(t){ throw new Error(t||('HTTP '+r.status)); }); return r.json(); })
          .then(function(obj){ if(obj && obj.url){ field.image_url = obj.url; imgPrev.src=obj.url; imgPrev.style.display='block'; updateHiddenProps(field); applyPreviewFrom(field); if(ctx.setDirty) ctx.setDirty(true); } })
          .catch(function(){ imgErr.textContent='آپلود تصویر ناموفق بود.'; imgErr.style.display='block'; })
          .finally(function(){ imgFile.disabled=false; imgFile.value=''; });
      });
    }

    if (typeof ctx.applyPreviewFrom === 'function'){ ctx.applyPreviewFrom(field); }
    var saveBtn = document.getElementById('arSaveFields'); if (saveBtn){ saveBtn.onclick = async function(){ try{ var ok = await ctx.saveFields(); if(ok){ if(ctx.setDirty) ctx.setDirty(false); if (typeof window.renderFormBuilder==='function' && typeof ctx.id !== 'undefined'){ window.renderFormBuilder(ctx.id); } } }catch(_){} }; }
    return true;
  }

  function renderPreview(field, ctx){
    // No-op: dashboard renders preview via core code; kept for future separation.
  }

  window.ARSH.Tools.register({
    type: 'long_text',
    defaults: defaults(),
    renderEditor: renderEditor,
    renderPreview: renderPreview
  });

})(window, document);

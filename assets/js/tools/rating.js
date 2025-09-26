(function(window, document){
  'use strict';
  if (!window.ARSH || !ARSH.Tools || !ARSH.Tools.register) return;

  function defaults(){
    return { type:'rating', label:'امتیازدهی', question:'', required:false, numbered:true, show_description:false, description:'', max:5, icon:'star', media_upload:false };
  }

  function renderEditor(field, ctx){
    try {
      field = field || defaults();
      // clamp values
      var maxVal = parseInt(field.max||5); if (isNaN(maxVal) || maxVal < 1) maxVal = 1; if (maxVal > 20) maxVal = 20; field.max = maxVal;
      var ICONS = [
        { key:'star',  solid:'star',       outline:'star-outline',  label:'ستاره' },
        { key:'heart', solid:'heart',      outline:'heart-outline', label:'قلب' },
        { key:'thumb', solid:'thumbs-up',  outline:'thumbs-up-outline', label:'تایید' },
        { key:'medal', solid:'ribbon',     outline:'ribbon-outline', label:'مدال' },
        { key:'smile', solid:'happy',      outline:'happy-outline', label:'لبخند' },
        { key:'sad',   solid:'sad',        outline:'sad-outline',   label:'ناراحت' }
      ];
      if (!ICONS.find(function(i){ return i.key === field.icon; })) field.icon = 'star';

      var sWrap = (ctx && ctx.wrappers && ctx.wrappers.settings) || document.querySelector('.ar-settings');
      var pWrap = (ctx && ctx.wrappers && ctx.wrappers.preview) || document.querySelector('.ar-preview');
      if (!sWrap || !pWrap) return false;

      sWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">تنظیمات امتیازدهی</div>',
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
        '<div class="field" style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap;">',
          '<span class="hint">تعداد آیکون‌ها (۱ تا ۲۰)</span>',
          '<input id="fMax" class="ar-input" type="number" min="1" max="20" style="width:90px" />',
        '</div>',
        '<div class="field" style="display:flex;gap:10px;align-items:flex-start;margin-top:8px;flex-wrap:wrap;">',
          '<span class="hint" style="margin-top:.35rem;">شکل آیکون</span>',
          '<div id="fIconPalette" role="radiogroup" aria-label="انتخاب آیکون" style="display:flex;gap:.4rem;flex-wrap:wrap;"></div>',
        '</div>',
        '<div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>'
      ].join('');

      pWrap.innerHTML = [
        '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>',
        '<div id="rtPreview" style="margin-bottom:.6rem;max-width:100%;overflow:hidden"></div>'
      ].join('');

      function sanitize(html){ try{ var d=document.createElement('div'); d.innerHTML=String(html||''); Array.from(d.querySelectorAll('script,style,iframe')).forEach(function(n){n.remove();}); return d.innerHTML; }catch(_){ return html||''; } }
      function updateHiddenProps(p){ var el=document.querySelector('#arCanvas .ar-item'); if(el) el.setAttribute('data-props', JSON.stringify(p)); }

      // bind controls
      var qEl = document.getElementById('fQuestionRich'); if (qEl){ qEl.innerHTML = sanitize(field.question || ''); qEl.addEventListener('input', function(){ field.question = sanitize(qEl.innerHTML); updateHiddenProps(field); renderRTPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var req = document.getElementById('fRequired'); if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; updateHiddenProps(field); renderRTPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var num = document.getElementById('fNumbered'); if (num){ num.checked = field.numbered !== false; num.addEventListener('change', function(){ field.numbered = !!num.checked; updateHiddenProps(field); renderRTPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var dTg = document.getElementById('fDescToggle'); var dWrap = document.getElementById('fDescWrap'); if (dTg){ dTg.checked = !!field.show_description; if (dWrap) dWrap.style.display = field.show_description ? 'block' : 'none'; dTg.addEventListener('change', function(){ field.show_description = !!dTg.checked; if (dWrap) dWrap.style.display = field.show_description ? 'block' : 'none'; updateHiddenProps(field); renderRTPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var dTx = document.getElementById('fDescText'); if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; updateHiddenProps(field); renderRTPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var maxIn = document.getElementById('fMax'); if (maxIn){ maxIn.value = field.max || 5; maxIn.addEventListener('input', function(){ var v=parseInt(maxIn.value||''); if (isNaN(v)) v=5; if (v<1) v=1; if (v>20) v=20; field.max=v; maxIn.value=v; updateHiddenProps(field); renderRTPreview(field); if (ctx && ctx.setDirty) ctx.setDirty(true); }); }
      var iconPal = document.getElementById('fIconPalette');
      if (iconPal){
        function drawPalette(){
          iconPal.innerHTML = ICONS.map(function(i){
            var active = (i.key === field.icon);
            var name = active ? i.solid : i.outline;
            var border = active ? 'var(--primary)' : 'var(--border)';
            var color = active ? 'var(--primary)' : 'var(--muted)';
            var bg = active ? 'rgba(0,0,0,0.02)' : 'var(--surface)';
            return '<button type="button" class="ar-icon-choice'+(active?' is-active':'')+'" data-key="'+i.key+'" aria-pressed="'+(active?'true':'false')+'" title="'+i.label+'" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:.35rem .5rem;border:1px solid '+border+';border-radius:10px;background:'+bg+';cursor:pointer;min-width:70px;">'
              + '<ion-icon name="'+name+'" style="font-size:1.4rem;color:'+color+'"></ion-icon>'
              + '<span class="hint" style="font-size:.8rem;margin-top:.2rem;color:'+color+'">'+i.label+'</span>'
            + '</button>';
          }).join('');
          Array.from(iconPal.querySelectorAll('.ar-icon-choice')).forEach(function(btn){
            btn.addEventListener('click', function(){
              var key = btn.getAttribute('data-key') || 'star';
              field.icon = key;
              updateHiddenProps(field);
              drawPalette();
              renderRTPreview(field);
              if (ctx && ctx.setDirty) ctx.setDirty(true);
            });
          });
        }
        drawPalette();
      }

      function mapIcon(key){ var found = ICONS.find(function(i){ return i.key===key; }) || ICONS[0]; return { solid: found.solid, outline: found.outline }; }

      function renderRTPreview(p){
        var out = document.getElementById('rtPreview'); if (!out) return;
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
        var count = parseInt(p.max||5); if (isNaN(count) || count<1) count=1; if (count>20) count=20;
        var names = mapIcon(p.icon||'star');
        var icons = [];
        for (var i=1;i<=count;i++){
          icons.push('<span class="ar-rating-icon" data-value="'+i+'" style="cursor:pointer;font-size:1.4rem;color:var(--muted);display:inline-flex;align-items:center;justify-content:center;">\
            <ion-icon name="'+(names.outline)+'"></ion-icon>\
          </span>');
        }
        parts.push('<div class="ar-rating-wrap" aria-label="امتیاز" role="radiogroup" data-icon-solid="'+names.solid+'" data-icon-outline="'+names.outline+'">'+icons.join(' ')+'</div>');
        out.innerHTML = parts.join('');

        // simple local interaction in preview panel
        try {
          var wrap = out.querySelector('.ar-rating-wrap');
          if (wrap){
            var items = Array.from(wrap.querySelectorAll('.ar-rating-icon'));
            function update(v){ items.forEach(function(el,idx){ var ion=el.querySelector('ion-icon'); if (ion){ ion.setAttribute('name', idx < v ? names.solid : names.outline); } el.style.color = idx < v ? 'var(--primary)' : 'var(--muted)'; }); }
            items.forEach(function(el){ el.addEventListener('click', function(){ var v=parseInt(el.getAttribute('data-value')||'0')||0; update(v); }); el.setAttribute('tabindex','0'); el.addEventListener('keydown', function(e){ if(e.key==='Enter' || e.key===' '){ e.preventDefault(); var v=parseInt(el.getAttribute('data-value')||'0')||0; update(v); } }); });
            update(0);
          }
        } catch(_){ }
      }

      renderRTPreview(field);

      var saveBtn = document.getElementById('arSaveFields'); if (saveBtn){ saveBtn.onclick = async function(){ var ok = await (ctx && ctx.saveFields ? ctx.saveFields() : Promise.resolve(false)); if (ok){ if (ctx && ctx.setDirty) ctx.setDirty(false); if (typeof window.renderFormBuilder==='function'){ window.renderFormBuilder(ctx.id); } } }; }
      return true;
    } catch(_){ return false; }
  }

  function renderPreview(field, ctx){ return false; }

  ARSH.Tools.register({
    type: 'rating',
    defaults: defaults(),
    renderEditor: renderEditor,
    renderPreview: renderPreview
  });
})(window, document);

<?php
/**
 * Public Form Template
 * Renders a form when visiting /?arshline_form=ID or /?arshline=TOKEN
 */

// Security: run within WP
if (!defined('ABSPATH')) { exit; }

$form_id = isset($_GET['arshline_form']) ? intval($_GET['arshline_form']) : 0;
$token = isset($_GET['arshline']) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $_GET['arshline']) : '';
if (!$form_id && !$token) { wp_die(__('Invalid form.', 'arshline')); }

get_header();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet">
<style>
.arsh-public-wrap{max-width:800px;margin:40px auto;padding:16px}
.arsh-public-card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.arsh-title{font-weight:700;font-size:22px;margin-bottom:10px}
.arsh-hint{opacity:.7;font-size:13px}
.arsh-btn{background:var(--ar-primary,#1e40af);color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}
.arsh-btn:disabled{opacity:.6;cursor:not-allowed}
.ar-alert{margin-top:12px;padding:10px;border-radius:8px}
.ar-alert--ok{background:#ecfdf5;color:#065f46}
.ar-alert--err{background:#fef2f2;color:#991b1b}
/* font */
html, body, .arsh-public-wrap{font-family:'Vazirmatn', system-ui, -apple-system, Segoe UI, Roboto, "Noto Sans", "Helvetica Neue", Arial, "Apple Color Emoji", "Segoe UI Emoji"}
/* validation styles */
.ar-field.ar-invalid{border-color:#ef4444 !important;background:#fff7f7}
.ar-field .ar-err{color:#b91c1c;font-size:12px;margin-top:4px;display:none}
.ar-field.ar-invalid .ar-err{display:block}
</style>

<div class="arsh-public-wrap">
  <div id="arshPublic" class="arsh-public-card">
    <div id="arFormTitle" class="arsh-title"><?php echo esc_html(get_bloginfo('name')); ?></div>
    <div id="arFormHint" class="arsh-hint"><?php echo esc_html(get_bloginfo('description')); ?></div>
    <div id="arFormMount" style="margin-top:12px"></div>
  </div>
</div>

<script>
(function(){
  var AR_REST = <?php echo json_encode( rest_url('arshline/v1/') ); ?>;
  var FORM_ID = <?php echo json_encode($form_id); ?>;
  var TOKEN = <?php echo json_encode($token); ?>;
  var mount = document.getElementById('arFormMount');
  var titleEl = document.getElementById('arFormTitle');
  var hintEl = document.getElementById('arFormHint');
  var state = { def:null, fields:[], questions:[] };

  // Optional: load HTMX if not present
  if (!window.htmx) {
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/htmx.org@1.9.12';
    s.defer = true;
    s.onload = function(){
      try { if (typeof window._ar_process_htmx === 'function') window._ar_process_htmx(); } catch(_){ }
    };
    document.head.appendChild(s);
  }

    function h(html){ var d=document.createElement('div'); d.innerHTML=html; return d.firstChild; }
    function esc(s){ s = String(s||''); return s.replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'})[c]; }); }

  // normalize Persian/Arabic digits to ASCII
  function normalizeDigits(s){
    if (!s) return '';
    var fa = '۰۱۲۳۴۵۶۷۸۹', ar = '٠١٢٣٤٥٦٧٨٩';
    var out = '';
    for (var i=0;i<s.length;i++){
      var ch = s[i]; var p = fa.indexOf(ch); if (p>=0){ out += String(p); continue; }
      p = ar.indexOf(ch); if (p>=0){ out += String(p); continue; }
      out += ch;
    }
    return out;
  }

  function validateValue(f, raw){
    var v = normalizeDigits(String(raw||'').trim());
    var label = f.question || 'فیلد';
    if (f.type === 'multiple_choice'){
      if (f.required && (v === '' || v == null)) return label + ' الزامی است.';
      return '';
    }
    if (f.type === 'dropdown'){
      if (f.required && (v === '' || v == null)) return label + ' الزامی است.';
      return '';
    }
    if (f.type === 'rating'){
      if (f.required && (v === '' || v === '0')) return label + ' الزامی است.';
      return '';
    }
    // text-based
    if (f.required && v === '') return label + ' الزامی است.';
    if (v === '') return '';
    var fmt = f.format || 'free_text';
    switch (fmt){
      case 'email': if (!/^\S+@\S+\.\S+$/.test(v)) return 'ایمیل نامعتبر است.'; break;
      case 'mobile_ir': if (!/^(\+98|0)?9\d{9}$/.test(v)) return 'شماره موبایل ایران نامعتبر است.'; break;
      case 'mobile_intl': if (!/^\+?[1-9]\d{7,14}$/.test(v)) return 'شماره موبایل بین‌المللی نامعتبر است.'; break;
      case 'tel': if (!/^[0-9\-\+\s\(\)]{5,20}$/.test(v)) return 'شماره تلفن نامعتبر است.'; break;
      case 'numeric': if (!/^\d+$/.test(v)) return 'فقط اعداد مجاز است.'; break;
      case 'national_id_ir':
        if (!/^\d{10}$/.test(v)) return 'کد ملی نامعتبر است.';
        if (/^(\d)\1{9}$/.test(v)) return 'کد ملی نامعتبر است.';
        var sum=0; for (var i=0;i<9;i++){ sum += parseInt(v[i],10)*(10-i);} var r=sum%11; var c=parseInt(v[9],10);
        if (!((r<2 && c===r) || (r>=2 && c===(11-r)))) return 'کد ملی نامعتبر است.';
        break;
      case 'postal_code_ir':
        if (!/^\d{10}$/.test(v)) return 'کد پستی نامعتبر است.';
        if (/^(\d)\1{9}$/.test(v)) return 'کد پستی نامعتبر است.';
        break;
      case 'fa_letters': if (!/^[\u0600-\u06FF\s]+$/.test(v)) return 'فقط حروف فارسی مجاز است.'; break;
      case 'en_letters': if (!/^[A-Za-z\s]+$/.test(v)) return 'فقط حروف انگلیسی مجاز است.'; break;
      case 'ip': if (!/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)$/.test(v) && !/^[0-9A-Fa-f:]+$/.test(v)) return 'آی‌پی نامعتبر است.'; break;
      case 'time': if (!/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(v)) return 'زمان نامعتبر است.'; break;
      case 'date_jalali': if (!/^\d{4}\/(0[1-6]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/.test(v)) return 'تاریخ شمسی نامعتبر است.'; break;
      case 'date_greg': if (!/^\d{4}\-(0[1-9]|1[0-2])\-(0[1-9]|[12]\d|3[01])$/.test(v)) return 'تاریخ میلادی نامعتبر است.'; break;
      case 'regex':
        if (f.regex) {
          try { var re = new RegExp(f.regex); if (!re.test(v)) return 'مقدار با الگوی دلخواه تطابق ندارد.'; } catch(e) { /* ignore invalid pattern */ }
        }
        break;
      case 'free_text':
      default: break;
    }
    return '';
  }

  function showFieldError(wrap, msg){
    if (!wrap) return;
    wrap.classList.toggle('ar-invalid', !!msg);
    var err = wrap.querySelector('.ar-err'); if (err){ err.textContent = String(msg||''); }
  }

  function attachValidationHandlers(wrap, f){
    var controlSelector = '[name="field_'+f.id+'"]';
    var controls = wrap.querySelectorAll(controlSelector);
    function currentValue(){
      if (f.type === 'multiple_choice'){
        var checked = wrap.querySelector(controlSelector+':checked'); return checked ? checked.value : '';
      }
      var el = controls[0]; return el ? el.value : '';
    }
    function run(){ var msg = validateValue(f, currentValue()); showFieldError(wrap, msg); return msg; }
    controls.forEach(function(el){
      if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT'){
        el.addEventListener('blur', run);
        el.addEventListener('input', function(){ if (wrap.classList.contains('ar-invalid')) run(); });
        if (el.type === 'radio') { el.addEventListener('change', run); }
        // Enter to move next (not for textarea)
        if (el.tagName !== 'TEXTAREA'){
          el.addEventListener('keydown', function(ev){
            if (ev.key === 'Enter'){
              ev.preventDefault(); focusNextField(f);
            }
          });
        }
      }
    });
    return { run: run };
  }

  function focusNextField(f){
    var idx = (state.questions||[]).findIndex(function(x){ return x && x.id === f.id; });
    var next = (state.questions||[])[idx+1];
    if (!next) { return; }
    var nextWrap = document.querySelector('.ar-field[data-field-id="'+next.id+'"]');
    if (!nextWrap) return;
    var ctl = nextWrap.querySelector('[name="field_'+next.id+'"]');
    if (ctl) { try { ctl.focus(); } catch(_){} }
  }

  function renderField(f, idx){
    // Simple public-side renderer, mirrors admin preview where possible
    var wrap = document.createElement('div');
    wrap.className = 'card';
    wrap.style.cssText = 'padding:.6rem;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:.6rem;';
  wrap.classList.add('ar-field');
  wrap.setAttribute('data-field-id', String(f.id));
  wrap.setAttribute('data-index', String(idx));
  var req = f.required ? ' <span style="color:#b91c1c">*</span>' : '';
  var labelNumber = (f.numbered!==false? ('<span class="hint" style="margin-inline-end:.4rem;opacity:.6">'+(idx+1)+'</span>') : '');
  var labelHtml = labelNumber + esc(f.question||'') + req;
  wrap.innerHTML = '<div style="font-weight:600;margin-bottom:.4rem">'+labelHtml+'</div>';
    var body = document.createElement('div');
    // known types
    if (f.type === 'short_text' || f.type === 'long_text'){
      var inp = document.createElement(f.type==='long_text'?'textarea':'input');
      if (f.type==='short_text') inp.type='text';
      inp.name = 'field_'+f.id;
      inp.required = !!f.required;
      inp.className = 'ar-input'; inp.style.cssText = 'width:100%';
      body.appendChild(inp);
    } else if (f.type === 'multiple_choice'){
  (f.options||[]).forEach(function(opt, i){ var id='f'+f.id+'_'+i; var row = document.createElement('label'); row.setAttribute('for', id); row.style.cssText='display:flex;gap:.35rem;align-items:center;margin:.15rem 0'; var input=document.createElement('input'); input.type='radio'; input.name='field_'+f.id; input.id=id; input.value=String(opt.value||opt.label||''); if (i===0 && f.required) input.required=true; var span=document.createElement('span'); span.textContent = String(opt.label||''); row.appendChild(input); row.appendChild(span); body.appendChild(row); });
    } else if (f.type === 'dropdown'){
      var sel = document.createElement('select'); sel.name='field_'+f.id; sel.required=!!f.required; sel.className='ar-select'; sel.style.cssText='width:100%';
  var ph = (f.placeholder||'یک گزینه را انتخاب کنید'); var phOpt=document.createElement('option'); phOpt.value=''; phOpt.textContent=String(ph); phOpt.disabled=true; phOpt.selected=true; sel.appendChild(phOpt);
      var opts = (f.options||[]).slice(); if (f.alpha_sort) opts.sort(function(a,b){ return String(a.label||'').localeCompare(String(b.label||''),'fa'); }); if (f.randomize) { for (let i=opts.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)); var t=opts[i]; opts[i]=opts[j]; opts[j]=t; } }
  opts.forEach(function(o){ var op=document.createElement('option'); op.value = String(o.value||o.label||''); op.textContent = String(o.label||''); sel.appendChild(op); });
      body.appendChild(sel);
    } else if (f.type === 'rating'){
      var max = Math.max(1, Math.min(20, parseInt(f.max||5)));
      var ic = String(f.icon||'star');
      var hidden = document.createElement('input'); hidden.type='hidden'; hidden.name='field_'+f.id; hidden.value=''; body.appendChild(hidden);
      var row = document.createElement('div'); row.style.cssText='display:flex;gap:.3rem;align-items:center;';
      function iconSvg(kind, filled){
        var c = filled? 'currentColor' : 'none'; var stroke = 'currentColor';
        if (kind==='heart') return '<svg width="26" height="26" viewBox="0 0 24 24" fill="'+c+'" stroke="'+stroke+'" stroke-width="1.8"><path d="M12 21s-7-4.35-9.33-8A5.33 5.33 0 1 1 12 6a5.33 5.33 0 1 1 9.33 7c-2.33 3.65-9.33 8-9.33 8Z"/></svg>';
        if (kind==='medal') return '<svg width="26" height="26" viewBox="0 0 24 24" fill="'+c+'" stroke="'+stroke+'" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M8 12l-2 8 6-3 6 3-2-8"/></svg>';
        if (kind==='thumb' || kind==='approve') return '<svg width="26" height="26" viewBox="0 0 24 24" fill="'+c+'" stroke="'+stroke+'" stroke-width="1.8"><path d="M14 10V4l-4 6H5v10h9l4-8v-2z"/></svg>';
        if (kind==='smile' || kind==='happy') return '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="'+stroke+'" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 10h.01M15 10h.01"/></svg>';
        if (kind==='sad') return '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="'+stroke+'" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M8 16s1.5-2 4-2 4 2 4 2"/><path d="M9 10h.01M15 10h.01"/></svg>';
        return '<svg width="26" height="26" viewBox="0 0 24 24" fill="'+c+'" stroke="'+stroke+'" stroke-width="1.8"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.77 5.82 22 7 14.14l-5-4.87 6.91-1.01z"/></svg>';
      }
      function paint(v){ Array.from(row.children).forEach(function(btn, i){ btn.style.color = i < v ? 'var(--ar-primary,#1e40af)' : '#94a3b8'; }); }
      for (var i=0;i<max;i++){ (function(ix){ var b=document.createElement('button'); b.type='button'; b.style.cssText='border:none;background:transparent;cursor:pointer;color:#94a3b8;padding:0;'; b.innerHTML = iconSvg(ic, false); b.addEventListener('mouseenter', function(){ paint(ix+1); }); b.addEventListener('mouseleave', function(){ paint(parseInt(hidden.value||0)); }); b.addEventListener('click', function(){ hidden.value = String(ix+1); paint(ix+1); }); row.appendChild(b); })(i); }
      body.appendChild(row);
    } else {
      body.innerHTML = '<div class="arsh-hint">نوع پشتیبانی‌نشده</div>';
    }
    // per-field error holder
    var err = document.createElement('div'); err.className='ar-err'; err.setAttribute('aria-live','polite');
    wrap.appendChild(body);
    wrap.appendChild(err);
    // attach validators
    attachValidationHandlers(wrap, f);
    return wrap;
  }

  function render(){
    mount.innerHTML = '';
    var def = state.def;
    if (!def){ mount.innerHTML = '<div class="arsh-hint">در حال بارگذاری…</div>'; return; }
    // style from meta
    var meta = def.meta || {};
    document.documentElement.style.setProperty('--ar-primary', meta.design_primary || '#1e40af');
    document.body.style.background = meta.design_bg || '#f6f8fb';
    // title from meta
    if (titleEl) { titleEl.textContent = meta.title || titleEl.textContent || 'فرم'; }
    if (hintEl) { /* keep site description as hint by default */ }
  var form = document.createElement('form'); form.id='arPublicForm'; form.style.marginTop = '8px';
  // Optionally enable HTMX if meta.use_htmx === true; otherwise rely on JSON fetch
  var useHtmx = !!meta.use_htmx;
  if (useHtmx) {
    var hxUrl = TOKEN
      ? (AR_REST + 'public/forms/by-token/' + encodeURIComponent(TOKEN) + '/submit')
      : <?php echo json_encode( rest_url('arshline/v1/public/forms/'.$form_id.'/submit') ); ?>;
    form.setAttribute('hx-post', hxUrl);
    form.setAttribute('hx-target', '#arAlert');
    form.setAttribute('hx-swap', 'innerHTML');
  }
  // Anti-spam hidden fields based on settings (honeypot + submit timestamp)
  var hpEnabled = !!meta.anti_spam_honeypot;
  var minSec = (typeof meta.min_submit_seconds === 'number') ? meta.min_submit_seconds : 0;
  var tsInit = Math.floor(Date.now()/1000);
  var tsInput = document.createElement('input'); tsInput.type='hidden'; tsInput.name='ts'; tsInput.value=String(tsInit); form.appendChild(tsInput);
  if (hpEnabled){ var hp=document.createElement('input'); hp.type='text'; hp.name='hp'; hp.value=''; hp.autocomplete='off'; hp.style.cssText='display:none !important; position:absolute; left:-9999px;'; form.appendChild(hp); }
  // Optional reCAPTCHA integration
  var captchaEnabled = !!meta.captcha_enabled;
  var captchaVersion = meta.captcha_version || 'v2';
  var captchaSite = meta.captcha_site_key || '';
  var captchaToken = '';
    // Render only supported question fields with proper numbering
    var qIdx = 0;
    (state.questions||[]).forEach(function(f){ form.appendChild(renderField(f, qIdx)); qIdx++; });
  var foot = document.createElement('div'); foot.style.marginTop='12px';
  var submit = document.createElement('button'); submit.type='submit'; submit.className='arsh-btn'; submit.textContent='ارسال'; foot.appendChild(submit);
  var alert = document.createElement('div'); alert.id='arAlert'; foot.appendChild(alert);
  // Render captcha widget if enabled
  if (captchaEnabled && captchaSite){
    var capWrap = document.createElement('div'); capWrap.style.cssText='margin:.5rem 0';
    if (captchaVersion === 'v2'){
      capWrap.innerHTML = '<div id="arCaptchaV2" class="g-recaptcha" data-sitekey="'+captchaSite+'"></div>';
      foot.insertBefore(capWrap, submit);
      // Load v2 script
      var s2 = document.createElement('script'); s2.src='https://www.google.com/recaptcha/api.js'; s2.defer=true; document.head.appendChild(s2);
    } else {
      // v3: execute on submit; load script with site key
      var s3 = document.createElement('script'); s3.src='https://www.google.com/recaptcha/api.js?render='+encodeURIComponent(captchaSite); s3.defer=true; document.head.appendChild(s3);
    }
  }
    form.appendChild(foot);
    function validateAll(){
      var errors = [];
      (state.questions||[]).forEach(function(f){
        var wrap = document.querySelector('.ar-field[data-field-id="'+f.id+'"]');
        if (!wrap) return;
        var val = (function(){
          if (f.type === 'multiple_choice'){
            var c = wrap.querySelector('[name="field_'+f.id+'"]:checked'); return c? c.value : '';
          }
          var el = wrap.querySelector('[name="field_'+f.id+'"]'); return el? el.value : '';
        })();
        var msg = validateValue(f, val);
        showFieldError(wrap, msg);
        if (msg) { errors.push({ f:f, msg:msg, wrap:wrap }); }
      });
      return errors;
    }
    form.addEventListener('submit', function(e){
      var errs = validateAll();
      if (errs.length){
        e.preventDefault();
        alert.className='ar-alert ar-alert--err';
        alert.innerHTML = '<div>لطفا خطاهای زیر را برطرف کنید:</div><ul style="margin:6px 0">'+errs.map(function(x){ return '<li>'+x.msg+'</li>'; }).join('')+'</ul>';
        alert.style.display='block';
        var first = errs[0];
        try { first.wrap.scrollIntoView({behavior:'smooth', block:'center'}); } catch(_){ }
        var ctl = first.wrap.querySelector('[name^="field_"]'); if (ctl) { try { ctl.focus(); } catch(_){ } }
        return;
      }
  // If HTMX is enabled and present, let HTMX handle submission
  if (useHtmx && window.htmx) { return; }
      e.preventDefault(); submit.disabled = true; alert.style.display='none';
      var fd = new FormData(form); var vals = []; (state.questions||[]).forEach(function(f){ var k='field_'+f.id; if (fd.has(k)){ vals.push({ field_id: f.id, value: fd.get(k) }); } });
  // If captcha v3 enabled, execute to get token before submit
  function doSubmit(){
    var payload = { values: vals };
    // include anti-spam fields in JSON submission as well
    payload.ts = tsInit; if (hpEnabled) payload.hp = '';
    if (captchaEnabled){
      if (captchaVersion === 'v2'){
        var resp = (window.grecaptcha && window.grecaptcha.getResponse) ? window.grecaptcha.getResponse() : '';
        payload['g-recaptcha-response'] = resp || '';
      } else {
        payload['ar_recaptcha_token'] = captchaToken || '';
      }
    }
    var postUrl = TOKEN ? (AR_REST + 'public/forms/by-token/' + encodeURIComponent(TOKEN) + '/submissions') : (AR_REST + 'forms/'+FORM_ID+'/submissions');
    fetch(postUrl, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
        .then(function(r){
          if (r.status === 422) { return r.json().then(function(j){ throw { code:422, data:j }; }); }
          if (!r.ok) { throw { code:r.status }; }
          return r.json();
        })
        .then(function(){ alert.className='ar-alert ar-alert--ok'; alert.textContent='با موفقیت ثبت شد.'; alert.style.display='block'; form.reset(); submit.disabled=false; })
        .catch(function(err){
          alert.className='ar-alert ar-alert--err';
          if (err && err.code === 422 && err.data && Array.isArray(err.data.messages)) {
            alert.innerHTML = '<div>خطا در اعتبارسنجی:</div><ul style="margin:6px 0">' + err.data.messages.map(function(m){ return '<li>'+String(m)+'</li>'; }).join('') + '</ul>';
          } else {
            alert.textContent='خطا در ارسال فرم.';
          }
          alert.style.display='block'; submit.disabled=false;
        });
  }
  if (captchaEnabled && captchaVersion === 'v3' && window.grecaptcha && window.grecaptcha.execute){
    try { window.grecaptcha.ready(function(){ window.grecaptcha.execute(captchaSite, { action: 'submit' }).then(function(token){ captchaToken = token; doSubmit(); }).catch(function(){ doSubmit(); }); }); } catch(_){ doSubmit(); }
  } else { doSubmit(); }
    });
    mount.appendChild(form);

    // If HTMX just loaded later, process the form to bind behaviors
    window._ar_process_htmx = function(){ try { if (window.htmx) window.htmx.process(form); } catch(_){ } };
    if (window.htmx) { try { window.htmx.process(form); } catch(_){ } }
  }

  // fetch form definition
  var getUrl = TOKEN ? (AR_REST + 'public/forms/by-token/' + encodeURIComponent(TOKEN)) : (AR_REST + 'public/forms/' + FORM_ID);
  fetch(getUrl)
    .then(function(r){ return r.json(); })
    .then(function(data){
      state.def = data || {};
      var rows = Array.isArray(state.def.fields) ? state.def.fields : [];
      // Flatten DB rows (id, sort, props) into public field objects
      var flat = rows.map(function(row){ var p = (row && row.props) || {}; return {
        id: row.id,
        sort: row.sort,
        required: !!p.required,
        type: p.type || '',
        question: p.question || '',
        placeholder: p.placeholder || '',
        format: p.format || 'free_text',
        regex: p.regex || '',
        options: Array.isArray(p.options)? p.options : [],
        numbered: (p.numbered !== false),
        alpha_sort: !!p.alpha_sort,
        randomize: !!p.randomize,
        max: p.max,
        icon: p.icon,
      }; });
      // Filter to supported question types and skip non-questions like welcome/thank_you
      var supported = { short_text:1, long_text:1, multiple_choice:1, dropdown:1, rating:1 };
      state.fields = flat;
      state.questions = flat.filter(function(f){ return supported[f.type]; });
      render();
    })
    .catch(function(){ mount.innerHTML = '<div class="arsh-hint">فرم یافت نشد.</div>'; });
})();
</script>

<?php get_footer();

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

  function renderField(f, idx){
    // Simple public-side renderer, mirrors admin preview where possible
    var wrap = document.createElement('div');
    wrap.className = 'card';
    wrap.style.cssText = 'padding:.6rem;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:.6rem;';
    var label = (f.numbered!==false? ('<span class="hint" style="margin-inline-end:.4rem;opacity:.6">'+(idx+1)+'</span>') : '') + (f.question||'');
    wrap.innerHTML = '<div style="font-weight:600;margin-bottom:.4rem">'+label+'</div>';
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
      (f.options||[]).forEach(function(opt, i){ var id='f'+f.id+'_'+i; var row = h('<label for="'+id+'" style="display:flex;gap:.35rem;align-items:center;margin:.15rem 0"><input type="radio" name="field_'+f.id+'" id="'+id+'" value="'+String(opt.value||opt.label||'')+'" '+(i===0 && f.required?'required':'')+' /> <span>'+(opt.label||'')+'</span></label>'); body.appendChild(row); });
    } else if (f.type === 'dropdown'){
      var sel = document.createElement('select'); sel.name='field_'+f.id; sel.required=!!f.required; sel.className='ar-select'; sel.style.cssText='width:100%';
      var ph = (f.placeholder||'یک گزینه را انتخاب کنید'); var phOpt=document.createElement('option'); phOpt.value=''; phOpt.textContent=ph; phOpt.disabled=true; phOpt.selected=true; sel.appendChild(phOpt);
      var opts = (f.options||[]).slice(); if (f.alpha_sort) opts.sort(function(a,b){ return String(a.label||'').localeCompare(String(b.label||''),'fa'); }); if (f.randomize) { for (let i=opts.length-1;i>0;i--){ var j=Math.floor(Math.random()*(i+1)); var t=opts[i]; opts[i]=opts[j]; opts[j]=t; } }
      opts.forEach(function(o){ var op=document.createElement('option'); op.value = String(o.value||o.label||''); op.textContent = o.label||''; sel.appendChild(op); });
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
    wrap.appendChild(body);
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
  // Attach hx-* attributes unconditionally; activate once HTMX is available
  var hxUrl = TOKEN
    ? (AR_REST + 'public/forms/by-token/' + encodeURIComponent(TOKEN) + '/submit')
    : <?php echo json_encode( rest_url('arshline/v1/public/forms/'.$form_id.'/submit') ); ?>;
  form.setAttribute('hx-post', hxUrl);
    form.setAttribute('hx-target', '#arAlert');
    form.setAttribute('hx-swap', 'innerHTML');
    // Render only supported question fields with proper numbering
    var qIdx = 0;
    (state.questions||[]).forEach(function(f){ form.appendChild(renderField(f, qIdx)); qIdx++; });
    var foot = document.createElement('div'); foot.style.marginTop='12px';
    var submit = document.createElement('button'); submit.type='submit'; submit.className='arsh-btn'; submit.textContent='ارسال'; foot.appendChild(submit);
    var alert = document.createElement('div'); alert.id='arAlert'; alert.style.display='none'; foot.appendChild(alert);
    form.appendChild(foot);
    form.addEventListener('submit', function(e){
      // If HTMX is present, let HTMX handle submission
      if (window.htmx) { return; }
      e.preventDefault(); submit.disabled = true; alert.style.display='none';
      var fd = new FormData(form); var vals = []; (state.questions||[]).forEach(function(f){ var k='field_'+f.id; if (fd.has(k)){ vals.push({ field_id: f.id, value: fd.get(k) }); } });
  var postUrl = TOKEN ? (AR_REST + 'public/forms/by-token/' + encodeURIComponent(TOKEN) + '/submissions') : (AR_REST + 'forms/'+FORM_ID+'/submissions');
  fetch(postUrl, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ values: vals }) })
        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
        .then(function(){ alert.className='ar-alert ar-alert--ok'; alert.textContent='با موفقیت ثبت شد.'; alert.style.display='block'; form.reset(); submit.disabled=false; })
        .catch(function(){ alert.className='ar-alert ar-alert--err'; alert.textContent='خطا در ارسال فرم.'; alert.style.display='block'; submit.disabled=false; });
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

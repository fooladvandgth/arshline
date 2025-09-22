<?php
/**
 * Template Name: Arshline Dashboard Fullscreen
 * Description: قالب اختصاصی و تمام‌صفحه برای داشبورد عرشلاین (بدون هدر و فوتر پوسته)
 */

// جلوگیری از بارگذاری مستقیم
if (!defined('ABSPATH')) exit;

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>داشبورد عرشلاین</title>
    <link rel="icon" href="<?php echo esc_url( plugins_url('favicon.ico', dirname(__DIR__, 2).'/arshline.php') ); ?>" type="image/x-icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Cyberpunk/Futuristic (light) */
            --primary: #1e40af;     /* Indigo 800 (deeper) */
            --primary-600: #1d4ed8;
            --secondary: #0e7490;   /* Cyan 700 (deeper) */
            --hot: #a21caf;         /* Fuchsia 700 (deeper) */
            --accent: #047857;      /* Emerald 800 (deeper) */
            --text: #0b1220;
            --muted: #64748b;
            --bg-surface: #f5f7fb;
            --surface: #ffffff;
            --card: #ffffff;
            --border: #e2e8f0;
            --sidebar: #ffffff;
            --shadow-primary: 0 16px 36px rgba(30,64,175,.28);
            --shadow-card: 0 8px 20px rgba(0,0,0,.10);
            --grad-primary: #1e40af; /* solid */
            --grad-accent: #047857;  /* solid */
            /* Sporty solids for feature cards */
            --grad-blue: #1e40af;
            --grad-green: #047857;
            --grad-pink: #a21caf;
            --grad-cyan-magenta: #0e7490;
            /* Glassmorphism (light) */
            --glass-a: rgba(255,255,255,.6);
            --glass-b: rgba(255,255,255,.22);
            --glass-border: rgba(255,255,255,.55);
            /* Glow */
            --glow-primary: 0 0 0 3px rgba(37,99,255,.22), 0 10px 28px rgba(37,99,255,.28);
            --glow-accent: 0 0 0 3px rgba(0,255,149,.18), 0 10px 28px rgba(0,229,255,.26);
        }
        body.dark {
            /* Cyberpunk (dark) */
            --text: #e5e7eb;
            --muted: #94a3b8;
            --bg-surface: #0c111f;
            --surface: #0d1321;
            --card: #121a2a;
            --border: #1f2a44;
            --sidebar: #0d1321; /* solid */
            --shadow-primary: 0 18px 42px rgba(30,64,175,.35);
            --shadow-card: 0 12px 28px rgba(0,0,0,.55);
            --grad-primary: #1e40af; /* solid */
            --grad-accent: #047857;  /* solid */
            /* Glassmorphism (dark) */
            --glass-a: rgba(255,255,255,.1);
            --glass-b: rgba(255,255,255,.05);
            --glass-border: rgba(255,255,255,.2);
            /* Glow */
            --glow-primary: 0 0 0 3px rgba(37,99,255,.28), 0 14px 34px rgba(37,99,255,.38);
            --glow-accent: 0 0 0 3px rgba(0,255,149,.22), 0 14px 34px rgba(0,229,255,.34);
        }
        body {
            margin: 0; padding: 0; font-family: 'Vazirmatn', system-ui, -apple-system, Segoe UI, Roboto, 'Inter', sans-serif; 
            background: var(--bg-surface);
            color: var(--text); transition: background .3s, color .3s;
        }
        .arshline-dashboard-root {
            display: flex; min-height: 100vh; width: 100vw;
        }
        .arshline-sidebar {
            width: 280px; background: var(--sidebar); border-inline-start: 1px solid var(--border);
            backdrop-filter: blur(8px); display: flex; flex-direction: column; transition: width .3s;
        }
        .arshline-sidebar.closed { width: 64px; }
        .arshline-sidebar .logo {
            font-size: 1.4rem; font-weight: 700; color: var(--primary); padding: 1.75rem 1.25rem 1rem 1.25rem; text-align: right; display:flex; align-items:center; gap:.6rem;
        }
        .arshline-sidebar nav {
            flex: 1; display: flex; flex-direction: column; gap: 1rem; padding: 1rem 0;
        }
        .arshline-sidebar nav a {
            display: flex; align-items: center; gap: .75rem; color: var(--muted); text-decoration: none; padding: .7rem 1.1rem; border-radius: 12px; transition: background .2s, color .2s, transform .2s, box-shadow .2s;
        }
    .arshline-sidebar nav a svg { transition: transform .2s ease, filter .2s ease; }
    .arshline-sidebar nav a:hover svg { transform: translateX(-4px) scale(1.02); filter: drop-shadow(0 6px 10px rgba(37,99,255,.3)); }
	.arshline-sidebar nav a.active { background: var(--primary); color: #fff; border: 0; box-shadow: 0 6px 16px rgba(0,0,0,.12); }
	.arshline-sidebar nav a:hover { background: rgba(37,99,255,.18); color: #0b1220; box-shadow: 0 8px 20px rgba(37,99,255,.18); }
        .arshline-sidebar .toggle {
            margin: 1rem 1.25rem; cursor: pointer; color: var(--muted); font-size: 1.2rem; text-align: right;
        }
        .arshline-main {
            flex: 1; padding: 2.2rem 2rem; min-height: 100vh; transition: background .3s;
        }
        .arshline-header {
            display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;
        }
        .arshline-header .mode-switch { cursor: pointer; font-size: 1.2rem; color: #fff; background: var(--primary); padding:.55rem .8rem; border-radius:12px; border:0; box-shadow: 0 6px 16px rgba(0,0,0,.12); transition: transform .2s ease, box-shadow .2s ease; }
        .arshline-header .mode-switch:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(0,0,0,.18); }
    .ar-btn { cursor:pointer; font-weight:600; border:0; border-radius:12px; background: var(--primary); color:#fff; padding:.5rem .9rem; box-shadow: 0 6px 16px rgba(0,0,0,.12); transition: transform .2s ease, box-shadow .2s ease; font-family: inherit; }
    .ar-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 22px rgba(0,0,0,.18); }
    .ar-btn--muted { background:#64748b; }
    .ar-input { padding:.5rem .6rem; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-family: inherit; font-size: 1rem; }
    .ar-select { padding:.45rem .5rem; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); font-family: inherit; font-size: 1rem; }
    .ar-dnd-handle { cursor: grab; user-select: none; display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:8px; color:#fff; background: var(--primary); margin-inline-end:.5rem; }
    .ar-dnd-ghost { opacity:.6; }
    .ar-dnd-over { outline: 2px dashed var(--primary); outline-offset: 4px; }
    .ar-tool { font-family: inherit; font-size:.95rem; background: var(--accent); }
    .ar-dnd-placeholder { border:1px solid var(--border); border-radius:10px; margin:.4rem 0; background: var(--surface); opacity:.35; padding:.5rem .8rem; pointer-events:none; }
    .ar-dnd-ghost-proxy { position: fixed; top:-9999px; left:-9999px; pointer-events:none; padding:.3rem .6rem; border-radius:8px; background:var(--primary); color:#fff; font-family: inherit; font-size:.9rem; box-shadow: var(--shadow-card); }
    /* Preview-only mode */
    body.preview-only .arshline-sidebar, body.preview-only .arshline-header { display:none !important; }
    body.preview-only .arshline-main { padding: 1.2rem; }
        /* دارک مود */
        body.dark { background: var(--bg-surface); color: var(--text); }
    body.dark .arshline-main { color: var(--text); }
    /* VC toggle switch styles (scoped) */
    .vc-toggle-container { display:inline-block; }
    .vc-small-switch { position: relative; display: inline-block; width: var(--vc-width,50px); height: var(--vc-height,25px); }
    .vc-small-switch input { display:none; }
    .vc-switch-label { position: absolute; inset: 0; cursor: pointer; background: var(--vc-off-color,#d1d3d4); border-radius: var(--vc-box-border-radius,18px); transition: background var(--vc-animation-speed,.15s ease-out); font-family: var(--vc-font-family,Arial); font-weight: var(--vc-font-weight,300); font-size: var(--vc-font-size,11px); color: var(--vc-off-font-color,#fff); }
    .vc-switch-label:before { content: attr(data-off); position: absolute; right: var(--vc-label-position-off,12px); line-height: var(--vc-height,25px); }
    .vc-switch-label:after  { content: attr(data-on); position: absolute; left: var(--vc-label-position-on,11px); line-height: var(--vc-height,25px); opacity: 0; }
    .vc-switch-handle { position: absolute; top: var(--vc-handle-top,5px); right: 5px; width: var(--vc-handle-width,15px); height: var(--vc-handle-height,15px); background: var(--vc-handle-color,#fff); border-radius: var(--vc-handle-border-radius,20px); transition: all var(--vc-animation-speed,.15s ease-out); box-shadow: var(--vc-handle-shadow,1px 1px 5px rgba(0,0,0,.2)); }
    .vc-small-switch input:checked ~ .vc-switch-label { background: var(--vc-on-color,#38cf5b); color: var(--vc-on-font-color,#fff); }
    .vc-small-switch input:checked ~ .vc-switch-label:before { opacity: 0; }
    .vc-small-switch input:checked ~ .vc-switch-label:after  { opacity: 1; }
    .vc-small-switch input:checked ~ .vc-switch-handle { right: calc(100% - var(--vc-handle-width,15px) - 5px); }
    </style>
    <script>
    const ARSHLINE_REST = '<?php echo esc_js( rest_url('arshline/v1/') ); ?>';
    const ARSHLINE_NONCE = '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>';
    const ARSHLINE_CAN_MANAGE = <?php echo current_user_can('manage_options') ? 'true' : 'false'; ?>;
    </script>
    <script>
    // Tabs: render content per menu item
    document.addEventListener('DOMContentLoaded', function() {
        var content = document.getElementById('arshlineDashboardContent');
        var links = document.querySelectorAll('.arshline-sidebar nav a[data-tab]');

        // theme switch
        var modeBtn = document.getElementById('arModeSwitch');
        try { if (localStorage.getItem('arshDark') === '1') document.body.classList.add('dark'); } catch(_){ }
        if (modeBtn){
            modeBtn.addEventListener('click', function(){
                document.body.classList.toggle('dark');
                try { localStorage.setItem('arshDark', document.body.classList.contains('dark') ? '1' : '0'); } catch(_){ }
            });
        }

        function setActive(tab){
            links.forEach(function(a){
                if (a.getAttribute('data-tab') === tab) a.classList.add('active'); else a.classList.remove('active');
            });
        }
        function card(title, subtitle){
            return '<div class="card glass">\
                <div class="title">'+title+'</div>\
                <div class="hint">'+(subtitle||'')+'</div>\
            </div>';
        }

        function suggestPlaceholder(fmt){
            switch(fmt){
                case 'email': return 'example@mail.com';
                case 'mobile_ir': return '09123456789';
                case 'mobile_intl': return '+14155552671';
                case 'tel': return '021-12345678';
                case 'numeric': return '123456';
                case 'national_id_ir': return '0012345678';
                case 'postal_code_ir': return '1234567890';
                case 'fa_letters': return 'مثال فارسی';
                case 'en_letters': return 'Sample text';
                case 'ip': return '192.168.1.1';
                case 'time': return '14:30';
                case 'date_jalali': return '1403/01/15';
                case 'date_greg': return '2025-09-22';
                case 'regex': return 'مطابق الگو';
                case 'free_text': return 'پاسخ خود را بنویسید';
                default: return '';
            }
        }
        function inputAttrsByFormat(fmt){
            var a = { type:'text', inputmode:'', pattern:'' };
            if (fmt==='email') a.type='email';
            else if (fmt==='numeric') { a.inputmode='numeric'; a.pattern='[0-9]*'; }
            else if (fmt==='mobile_ir' || fmt==='mobile_intl' || fmt==='tel' || fmt==='national_id_ir' || fmt==='postal_code_ir') { a.inputmode='tel'; }
            else if (fmt==='time') a.type='time';
            else if (fmt==='date_greg') a.type='date';
            return a;
        }

        function saveFields(){
            var id = parseInt(document.getElementById('arBuilder').dataset.formId||'0');
            var canvas = document.getElementById('arCanvas');
            var fields = Array.from(canvas.children).map(function(el){ return JSON.parse(el.dataset.props||'{}'); });
            fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: fields }) })
                .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                .then(function(){ notify('ذخیره شد', 'success'); })
                .catch(function(){ notify('ذخیره تغییرات ناموفق بود', 'error'); });
        }

        function renderFormPreview(id){
            document.body.classList.add('preview-only');
            var content = document.getElementById('arshlineDashboardContent');
            content.innerHTML = '<div class="card glass" style="padding:1.2rem;max-width:720px;margin:0 auto;">\
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">\
                    <div class="title">پیش‌نمایش فرم #'+ id +'</div>\
                    <button id="arPreviewBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                </div>\
                <div id="arFormPreviewFields" style="display:flex;flex-direction:column;gap:.8rem;"></div>\
                <div style="margin-top:1rem;text-align:left;"><button id="arPreviewSubmit" class="ar-btn">ارسال</button></div>\
            </div>';
            fetch(ARSHLINE_REST + 'forms/' + id)
                .then(r=>r.json())
                .then(function(data){
                    var fwrap = document.getElementById('arFormPreviewFields');
                    var qCount = 0;
                    (data.fields||[]).forEach(function(f){
                        var p = f.props || f;
                        var fmt = p.format || 'free_text';
                        var attrs = inputAttrsByFormat(fmt);
                        var phS = p.placeholder && p.placeholder.trim() ? p.placeholder : suggestPlaceholder(fmt);
                        var row = document.createElement('div');
                        var inputId = 'f_'+(f.id||Math.random().toString(36).slice(2));
                        var descId = inputId+'_desc';
                        var showQ = p.question && String(p.question).trim();
                        var numbered = (p.numbered !== false);
                        var numberStr = '';
                        if (showQ && numbered) { qCount++; numberStr = qCount + '. '; }
                        row.innerHTML = (showQ ? ('<div class="hint" style="margin-bottom:.25rem">'+numberStr+String(p.question).trim()+'</div>') : '')+
                            '<label for="'+inputId+'" class="hint" style="display:block;margin-bottom:.3rem;">'+(p.label||'فیلد')+(p.required?' *':'')+'</label>' +
                            '<input id="'+inputId+'" class="ar-input" style="width:100%" '+(attrs.type?('type="'+attrs.type+'"'):'')+' '+(attrs.inputmode?('inputmode="'+attrs.inputmode+'"'):'')+' '+(attrs.pattern?('pattern="'+attrs.pattern+'"'):'')+' placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" />' +
                            (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ (p.description||'') +'</div>') : '');
                        fwrap.appendChild(row);
                    });
                    // apply masks
                    fwrap.querySelectorAll('input[data-field-id]').forEach(function(inp, idx){
                        var field = (data.fields||[])[idx] || {};
                        var props = field.props || field || {};
                        applyInputMask(inp, props);
                        if (props.format === 'date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){
                            try { jQuery(inp).pDatepicker({ format: 'YYYY/MM/DD', initialValue: false }); } catch(e){}
                        }
                    });
                    document.getElementById('arPreviewSubmit').onclick = function(){
                        var vals = [];
                        fwrap.querySelectorAll('input[data-field-id]').forEach(function(inp, idx){
                            var fid = parseInt(inp.getAttribute('data-field-id')||'0');
                            vals.push({ field_id: fid, value: inp.value||'' });
                        });
                        fetch(ARSHLINE_REST + 'forms/'+id+'/submissions', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ values: vals }) })
                            .then(async r=>{ if (!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                            .then(function(){ notify('ارسال شد', 'success'); })
                            .catch(function(){ notify('اعتبارسنجی/ارسال ناموفق بود', 'error'); });
                    };
                    document.getElementById('arPreviewBack').onclick = function(){ document.body.classList.remove('preview-only'); renderTab('forms'); };
                });
        }

        function renderFormEditor(id){
            document.body.classList.remove('preview-only');
            var content = document.getElementById('arshlineDashboardContent');
            var hiddenCanvas = '<div id="arCanvas" style="display:none"><div class="ar-item" data-props="{}"></div></div>';
            content.innerHTML = '<div id="arBuilder" class="card glass" data-form-id="'+id+'" style="padding:1rem;max-width:980px;margin:0 auto;">\
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">\
                    <div class="title">ویرایش فرم #'+id+'</div>\
                    <button id="arEditorBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                </div>\
                <div style="display:flex;gap:1rem;align-items:flex-start;">\
                    <div class="ar-settings" style="width:380px;flex:0 0 380px;">\
                        <div class="title" style="margin-bottom:.6rem;">تنظیمات فیلد</div>\
                        <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                            <label class="hint">سؤال</label>\
                            <textarea id="fQuestion" class="ar-input" rows="2" placeholder="متن سؤال"></textarea>\
                        </div>\
                        <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-bottom:8px;">\
                            <label class="hint">نوع ورودی</label>\
                            <select id="fType" class="ar-select">\
                                <option value="free_text">متن آزاد</option>\
                                <option value="email">ایمیل</option>\
                                <option value="numeric">عدد</option>\
                                <option value="date_jalali">تاریخ شمسی</option>\
                                <option value="date_greg">تاریخ میلادی</option>\
                                <option value="time">زمان</option>\
                                <option value="mobile_ir">موبایل ایران</option>\
                                <option value="mobile_intl">موبایل بین‌المللی</option>\
                                <option value="national_id_ir">کد ملی ایران</option>\
                                <option value="postal_code_ir">کد پستی ایران</option>\
                                <option value="tel">تلفن</option>\
                                <option value="fa_letters">حروف فارسی</option>\
                                <option value="en_letters">حروف انگلیسی</option>\
                                <option value="ip">IP</option>\
                            </select>\
                        </div>\
                        <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">\
                            <span class="hint">اجباری</span>\
                            <label class="toggle-switch" title="اجباری" style="transform:scale(.9)">\
                                <input type="checkbox" id="fRequired">\
                                <span class="toggle-switch-background"></span>\
                                <span class="toggle-switch-handle"></span>\
                            </label>\
                        </div>\
                        <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:10px">\
                            <span class="hint">شماره‌گذاری سؤال</span>\
                            <label class="toggle-switch" title="نمایش شماره سؤال" style="transform:scale(.9)">\
                                <input type="checkbox" id="fNumbered">\
                                <span class="toggle-switch-background"></span>\
                                <span class="toggle-switch-handle"></span>\
                            </label>\
                        </div>\
                                                <div class="field" style="display:flex;align-items:center;gap:10px;margin-bottom:6px">\
                                                        <span class="hint">توضیحات</span>\
                                                        <div class="vc-toggle-container" style="--vc-width:50px;--vc-height:25px;--vc-on-color:#38cf5b;--vc-off-color:#d1d3d4;">\
                                                            <label class="vc-small-switch">\
                                                                <input type="checkbox" id="fDescToggle" class="vc-switch-input"/>\
                                                                <span class="vc-switch-label" data-on="بله" data-off="خیر"></span>\
                                                                <span class="vc-switch-handle"></span>\
                                                            </label>\
                                                        </div>\
                                                </div>\
                        <div class="field" id="fDescWrap" style="display:none">\
                            <textarea id="fDescText" class="ar-input" rows="2" placeholder="توضیح زیر سؤال"></textarea>\
                        </div>\
                        <div class="field" style="display:flex;flex-direction:column;gap:6px;margin-top:8px">\
                            <label class="hint">متن راهنما (placeholder)</label>\
                            <input id="fHelp" class="ar-input" placeholder="مثال: پاسخ را وارد کنید"/>\
                        </div>\
                        <div style="margin-top:12px">\
                            <button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button>\
                        </div>\
                    </div>\
                    <div class="ar-preview" style="flex:1;">\
                        <div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div>\
                        <div id="pvWrap">\
                            <div id="pvQuestion" class="hint" style="display:none;margin-bottom:.25rem"></div>\
                            <label class="hint" id="pvLabel" style="display:block;margin-bottom:.3rem">پاسخ کوتاه</label>\
                            <div id="pvDesc" class="hint" style="display:none;margin-bottom:.35rem"></div>\
                            <input id="pvInput" class="ar-input" style="width:100%" />\
                            <div id="pvHelp" class="hint" style="display:none"></div>\
                            <div id="pvErr" class="hint" style="color:#b91c1c;margin-top:.3rem"></div>\
                        </div>\
                    </div>\
                </div>\
            </div>' + hiddenCanvas;

            document.getElementById('arEditorBack').onclick = function(){ renderTab('forms'); };

            var defaultProps = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
            fetch(ARSHLINE_REST + 'forms/' + id)
                .then(r=>r.json())
                .then(function(data){
                    var field = (data.fields && data.fields[0] && (data.fields[0].props || data.fields[0])) || defaultProps;
                    // ensure base
                    field.type = 'short_text';
                    field.label = 'پاسخ کوتاه';
                    // inject hidden props
                    var canvasEl = document.querySelector('#arCanvas .ar-item');
                    if (canvasEl) canvasEl.setAttribute('data-props', JSON.stringify(field));
                    // setup controls
                    var sel = document.getElementById('fType');
                    var req = document.getElementById('fRequired');
                    var dTg = document.getElementById('fDescToggle');
                    var dTx = document.getElementById('fDescText');
                    var dWrap = document.getElementById('fDescWrap');
                    var help = document.getElementById('fHelp');
                    var qEl = document.getElementById('fQuestion');
                    var numEl = document.getElementById('fNumbered');

                    function updateHiddenProps(p){
                        var el = document.querySelector('#arCanvas .ar-item');
                        if (el) el.setAttribute('data-props', JSON.stringify(p));
                    }
                    function applyPreviewFrom(p){
                        var fmt = p.format || 'free_text';
                        var attrs = inputAttrsByFormat(fmt);
                        var inp = document.getElementById('pvInput');
                        if (!inp) return;
                        // Fully teardown any previous Jalali datepicker bindings
                        if (typeof jQuery !== 'undefined'){
                            try { if (jQuery.fn && jQuery.fn.pDatepicker) { jQuery(inp).pDatepicker('destroy'); } } catch(e){}
                            try { jQuery(inp).off('.pDatepicker'); } catch(e){}
                            try { jQuery(inp).removeData('datepicker').removeData('pDatepicker'); } catch(e){}
                        }
                        try { inp.classList.remove('pwt-datepicker-input-element'); } catch(e){}
                        // Replace the input node to ensure no lingering handlers
                        try {
                            var parent = inp.parentNode;
                            var clone = inp.cloneNode(false);
                            clone.id = 'pvInput';
                            clone.className = 'ar-input';
                            parent.replaceChild(clone, inp);
                            inp = clone;
                        } catch(e){}
                        // Reset attributes
                        inp.value = '';
                        inp.removeAttribute('placeholder');
                        inp.setAttribute('type', (attrs && attrs.type) ? attrs.type : 'text');
                        if (attrs && attrs.inputmode) inp.setAttribute('inputmode', attrs.inputmode); else inp.removeAttribute('inputmode');
                        if (attrs && attrs.pattern) inp.setAttribute('pattern', attrs.pattern); else inp.removeAttribute('pattern');
                        var ph = (p.placeholder && p.placeholder.trim()) ? p.placeholder : (fmt==='free_text' ? 'پاسخ را وارد کنید' : suggestPlaceholder(fmt));
                        inp.setAttribute('placeholder', ph || '');
                        var qNode = document.getElementById('pvQuestion');
                        if (qNode){
                            var showQ = (p.question && p.question.trim());
                            qNode.style.display = showQ ? 'block' : 'none';
                            qNode.textContent = showQ ? ((p.numbered ? '1. ' : '') + p.question.trim()) : '';
                        }
                        var lbl = document.getElementById('pvLabel'); if (lbl) lbl.innerHTML = (p.label||'پاسخ کوتاه') + (p.required?' *':'');
                        var desc = document.getElementById('pvDesc'); if (desc){ desc.textContent = p.description || ''; desc.style.display = p.show_description && p.description ? 'block' : 'none'; }
                        var helpEl = document.getElementById('pvHelp'); if (helpEl) { helpEl.textContent=''; helpEl.style.display='none'; }
                        // Attach Jalali datepicker only for date_jalali
                        if (fmt==='date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.pDatepicker){
                            try { jQuery(inp).pDatepicker({ format:'YYYY/MM/DD', initialValue:false }); } catch(e){}
                        }
                    }
                    function sync(){ field.label = 'پاسخ کوتاه'; field.type = 'short_text'; updateHiddenProps(field); applyPreviewFrom(field); }

                    if (sel){ sel.value = field.format || 'free_text'; sel.addEventListener('change', function(){ field.format = sel.value || 'free_text'; var i=document.getElementById('pvInput'); if(i) i.value=''; sync(); }); }
                    if (req){ req.checked = !!field.required; req.addEventListener('change', function(){ field.required = !!req.checked; sync(); }); }
                    if (dTg){ dTg.checked = !!field.show_description; if (dWrap) dWrap.style.display = field.show_description ? 'block':'none'; dTg.addEventListener('change', function(){ field.show_description = !!dTg.checked; if(dWrap){ dWrap.style.display = field.show_description ? 'block':'none'; } sync(); }); }
                    if (dTx){ dTx.value = field.description || ''; dTx.addEventListener('input', function(){ field.description = dTx.value; sync(); }); }
                    if (help){ help.value = field.placeholder || ''; help.addEventListener('input', function(){ field.placeholder = help.value; sync(); }); }
                    if (qEl){ qEl.value = field.question || ''; qEl.addEventListener('input', function(){ field.question = qEl.value; sync(); }); }
                    if (numEl){ numEl.checked = field.numbered !== false; field.numbered = numEl.checked; numEl.addEventListener('change', function(){ field.numbered = !!numEl.checked; sync(); }); }

                    applyPreviewFrom(field);
                    var saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = function(){ saveFields(); };
                });
        }

        function renderTab(tab){
            try { localStorage.setItem('arshLastTab', tab); } catch(_){ }
            setActive(tab);
            var content = document.getElementById('arshlineDashboardContent');
            if (tab === 'dashboard') {
                content.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:1.2rem;">' +
                    card('فرم‌ساز سریع', 'فرم بسازید و منتشر کنید') +
                    card('پاسخ‌ها', 'مرور و جستجو') +
                    card('گزارشات', 'به‌زودی') +
                '</div>';
            } else if (tab === 'forms') {
                content.innerHTML = '<div class="card glass card--static" style="padding:1rem;">\
                    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;">\
                      <span class="title">فرم‌ها</span>\
                      <button id="arCreateFormBtn" class="ar-btn" style="margin-inline-start:auto;">+ فرم جدید</button>\
                    </div>\
                    <div id="arCreateInline" style="display:none;align-items:center;gap:.5rem;margin-bottom:.8rem;">\
                      <input id="arNewFormTitle" class="ar-input" placeholder="عنوان فرم" style="min-width:220px"/>\
                      <button id="arCreateFormSubmit" class="ar-btn">ایجاد</button>\
                      <button id="arCreateFormCancel" class="ar-btn ar-btn--muted">انصراف</button>\
                    </div>\
                    <div id="arFormsList" class="hint">در حال بارگذاری...</div>\
                </div>';
                var createBtn = document.getElementById('arCreateFormBtn');
                var inlineWrap = document.getElementById('arCreateInline');
                var submitBtn = document.getElementById('arCreateFormSubmit');
                var cancelBtn = document.getElementById('arCreateFormCancel');
                if (!ARSHLINE_CAN_MANAGE && createBtn){ createBtn.style.display = 'none'; }
                if (createBtn) createBtn.addEventListener('click', function(){
                    if (!inlineWrap) return; var showing = inlineWrap.style.display !== 'none'; inlineWrap.style.display = showing ? 'none' : 'flex';
                    if (!showing){ var input = document.getElementById('arNewFormTitle'); if (input){ input.value=''; input.focus(); } }
                });
                if (cancelBtn) cancelBtn.addEventListener('click', function(){ if (inlineWrap) inlineWrap.style.display = 'none'; });
                if (submitBtn) submitBtn.addEventListener('click', function(){
                    var titleEl = document.getElementById('arNewFormTitle');
                    var title = (titleEl && titleEl.value.trim()) || 'فرم جدید';
                    fetch(ARSHLINE_REST + 'forms', { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ title: title }) })
                        .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                        .then(function(obj){ notify('فرم ایجاد شد', 'success'); if (obj && obj.id){ renderFormEditor(parseInt(obj.id)); } else { renderTab('forms'); } })
                        .catch(function(){ notify('ایجاد فرم ناموفق بود. لطفاً دسترسی را بررسی کنید.', 'error'); });
                });
                // load forms list
                fetch(ARSHLINE_REST + 'forms').then(r=>r.json()).then(function(forms){
                    var box = document.getElementById('arFormsList'); if (!box) return;
                    if (!forms || forms.length===0){ box.textContent = 'هنوز فرمی ندارید.'; return; }
                    var html = (forms||[]).map(function(f){
                        return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px dashed var(--border);">\
                            <div>#'+f.id+' — '+(f.title||'بدون عنوان')+'</div>\
                            <div style="display:flex;gap:.6rem;">\
                                <a href="#" class="arEditForm" data-id="'+f.id+'">ویرایش</a>\
                                <a href="#" class="arPreviewForm" data-id="'+f.id+'">پیش‌نمایش</a>\
                                '+(ARSHLINE_CAN_MANAGE ? '<a href="#" class="arDeleteForm" data-id="'+f.id+'" style="color:#b91c1c">حذف</a>' : '')+'\
                            </div>\
                        </div>';
                    }).join('');
                    box.innerHTML = html;
                    box.querySelectorAll('.arEditForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); renderFormEditor(id); }); });
                    box.querySelectorAll('.arPreviewForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); renderFormPreview(id); }); });
                    if (ARSHLINE_CAN_MANAGE) {
                        box.querySelectorAll('.arDeleteForm').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); var id = parseInt(a.getAttribute('data-id')); if (!id) return; if (!confirm('حذف فرم #'+id+'؟ این عمل بازگشت‌ناپذیر است.')) return; fetch(ARSHLINE_REST + 'forms/' + id, { method:'DELETE', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('فرم حذف شد', 'success'); renderTab('forms'); }).catch(function(){ notify('حذف فرم ناموفق بود', 'error'); }); }); });
                    }
                }).catch(function(){ var box = document.getElementById('arFormsList'); if (box) box.textContent = 'خطا در بارگذاری فرم‌ها.'; notify('خطا در بارگذاری فرم‌ها', 'error'); });
            } else if (tab === 'submissions') {
                content.innerHTML = '<div class="card glass" style="padding:1rem;">\
                    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;">\
                      <span class="title">پاسخ‌ها</span>\
                      <select id="arFormSelect" style="margin-inline-start:auto;padding:.35rem .5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"></select>\
                    </div>\
                    <div id="arSubsList" class="hint">فرمی انتخاب کنید...</div>\
                </div>';
                fetch(ARSHLINE_REST + 'forms').then(r=>r.json()).then(function(forms){
                    var sel = document.getElementById('arFormSelect');
                    if (!sel) return;
                    sel.innerHTML = '<option value="">انتخاب فرم...</option>' + (forms||[]).map(function(f){ return '<option value="'+f.id+'">#'+f.id+' — '+(f.title||'بدون عنوان')+'</option>'; }).join('');
                    sel.addEventListener('change', function(){
                        var id = parseInt(sel.value||'0');
                        var list = document.getElementById('arSubsList');
                        if (!id){ list.textContent='فرمی انتخاب کنید...'; return; }
                        list.textContent = 'در حال بارگذاری...';
                        fetch(ARSHLINE_REST + 'forms/'+id+'/submissions').then(r=>r.json()).then(function(rows){
                            if (!rows || rows.length===0){ list.textContent='پاسخی ثبت نشده است.'; return; }
                            var html = rows.map(function(it){
                                return '<div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px dashed var(--border);">\
                                    <div>#'+it.id+' · '+(it.status)+'<span class="hint" style="margin-inline-start:.6rem">'+(it.created_at||'')+'</span></div>\
                                    <a href="#" class="hint">جزئیات</a>\
                                </div>';
                            }).join('');
                            list.innerHTML = html;
                        }).catch(()=>{ list.textContent='خطا در بارگذاری پاسخ‌ها'; });
                    });
                });
            } else if (tab === 'reports') {
                content.innerHTML = '<div style="display:flex;flex-wrap:wrap;gap:1.2rem;">' +
                    card('نمودار نرخ تبدیل', 'به‌زودی') +
                    card('منابع ترافیک', 'به‌زودی') +
                    card('زمان‌های اوج', 'به‌زودی') +
                '</div>';
            } else if (tab === 'users') {
                content.innerHTML = '<div style="display:flex;flex-direction:column;gap:1.2rem;">\
                    <div class="card glass"><span class="title">کاربران</span><div class="hint">مدیریت نقش‌ها و دسترسی‌ها (Placeholder)</div></div>\
                    <div class="card glass"><span class="title">همکاری تیمی</span><div class="hint">دعوت هم‌تیمی‌ها (Placeholder)</div></div>\
                </div>';
            }
            // re-trigger entrance animation
            content.classList.remove('view'); void content.offsetWidth; content.classList.add('view');
        }

        // bind clicks + keyboard
        links.forEach(function(a){
            a.addEventListener('click', function(e){ e.preventDefault(); renderTab(a.getAttribute('data-tab')); });
            a.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' ') { e.preventDefault(); renderTab(a.getAttribute('data-tab')); }});
        });

        // default tab
        var initial = (location.hash || '').replace('#','') || (function(){ try { return localStorage.getItem('arshLastTab') || ''; } catch(_){ return ''; } })() || 'dashboard';
        if (![ 'dashboard','forms','submissions','reports','users' ].includes(initial)) initial = 'dashboard';
        renderTab(initial);
    });
    </script>
    </head>
    <body>
    <div class="arshline-dashboard-root">
        <aside class="arshline-sidebar">
            <div class="logo"><span>عرشلاین</span></div>
            <nav>
                <a href="#" data-tab="dashboard">داشبورد</a>
                <a href="#" data-tab="forms">فرم‌ها</a>
                <a href="#" data-tab="submissions">پاسخ‌ها</a>
                <a href="#" data-tab="reports">گزارشات</a>
                <a href="#" data-tab="users">کاربران</a>
            </nav>
        </aside>
        <main class="arshline-main">
            <div class="arshline-header">
                <div></div>
                <button id="arModeSwitch" class="mode-switch" type="button">تغییر حالت</button>
            </div>
            <div id="arshlineDashboardContent" class="view"></div>
        </main>
    </div>
<!-- Ionicons for modern solid cards -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
<!-- Persian datepicker (optional) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.css" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.js"></script>
<script src="https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.js"></script>
<script>
// lightweight toast notifications
function ensureToastWrap(){
    var w = document.getElementById('arToastWrap');
    if (!w){
        w = document.createElement('div');
        w.id = 'arToastWrap';
        w.className = 'ar-toast-wrap';
        document.body.appendChild(w);
    }
    return w;
}
function notify(message, opts){
    var wrap = ensureToastWrap();
    var el = document.createElement('div');
    var type = typeof opts === 'string' ? opts : (opts && opts.type) || '';
    el.className = 'ar-toast ' + (type ? ('ar-toast--'+type) : '');
    var hasAction = opts && opts.actionLabel && typeof opts.onAction === 'function';
    if (hasAction){
        var span = document.createElement('span');
        span.textContent = message;
        var btn = document.createElement('button');
        btn.textContent = opts.actionLabel;
        btn.style.cssText = 'margin-inline-start:.6rem;padding:.25rem .6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;';
        btn.addEventListener('click', function(){ opts.onAction(); el.remove(); });
        el.appendChild(span);
        el.appendChild(btn);
    } else {
        el.textContent = message;
    }
    wrap.appendChild(el);
    var duration = (opts && opts.duration) || 2800;
    setTimeout(function(){ el.style.opacity = '0'; el.style.transform = 'translateY(6px)'; }, Math.max(200, duration - 500));
    setTimeout(function(){ el.remove(); }, duration);
}
</script>
<script>
// Input masks for preview inputs
function applyInputMask(inp, props){
    var fmt = props.format || 'free_text';
    function normalizeDigits(str){
        var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        var ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        return (str||'').replace(/[۰-۹٠-٩]/g, function(d){
            var i = fa.indexOf(d); if (i>-1) return String(i);
            var j = ar.indexOf(d); if (j>-1) return String(j);
            return d;
        });
    }
    function clampLen(){}
    function digitsOnly(){ inp.value = normalizeDigits(inp.value).replace(/\D+/g,''); }
    function allowChars(regex){ var s = normalizeDigits(inp.value); inp.value = (s.match(regex)||[]).join(''); }
    function setInvalid(msg){ inp.style.borderColor = '#b91c1c'; if (msg) inp.title = msg; }
    function clearInvalid(){ inp.style.borderColor = ''; inp.title = ''; }
    inp.addEventListener('input', function(){
        inp.value = normalizeDigits(inp.value);
        switch(fmt){
            case 'numeric': digitsOnly(); break;
            case 'mobile_ir': inp.value = inp.value.replace(/[^\d]/g,''); if (/^9\d/.test(inp.value)) inp.value = '0'+inp.value; if (inp.value.startsWith('98')) inp.value = '0'+inp.value.slice(2); if (inp.value.length>11) inp.value = inp.value.slice(0,11); break;
            case 'mobile_intl': inp.value = inp.value.replace(/(?!^)[^\d]/g,'').replace(/^([^+\d])+/,''); if (!inp.value.startsWith('+')) inp.value = '+'+inp.value.replace(/\+/g,''); inp.value = inp.value.replace(/(.*\d{15}).*$/, '$1'); break;
            case 'tel': inp.value = inp.value.replace(/[^0-9\-\+\s\(\)]/g,''); break;
            case 'ip': inp.value = inp.value.replace(/[^0-9\.]/g,'').replace(/\.\.+/g,'.'); break;
            case 'time': inp.value = inp.value.replace(/[^0-9]/g,''); if (inp.value.length>2) inp.value = inp.value.slice(0,2)+":"+inp.value.slice(2,4); if (inp.value.length>5) inp.value = inp.value.slice(0,5); break;
            case 'fa_letters': allowChars(/[\u0600-\u06FF\s]/g); break;
            case 'en_letters': allowChars(/[A-Za-z\s]/g); break;
            case 'date_jalali': inp.value = inp.value.replace(/[^0-9/]/g,'').slice(0,10); break;
            case 'national_id_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
            case 'postal_code_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
            default: break;
        }
        clampLen();
    });
    inp.addEventListener('blur', function(){
        clearInvalid();
        var v = inp.value.trim(); if (!v) return;
        var msg = null;
        switch(fmt){
            case 'email': if (!/^\S+@\S+\.\S+$/.test(v)) setInvalid('ایمیل نامعتبر است'); break;
            case 'mobile_ir': if (!/^(\+98|0)?9\d{9}$/.test(v)) setInvalid('شماره موبایل ایران نامعتبر است'); break;
            case 'mobile_intl': if (!/^\+?[1-9]\d{7,14}$/.test(v)) setInvalid('شماره موبایل بین‌المللی نامعتبر است'); break;
            case 'tel': if (!/^[0-9\-\+\s\(\)]{5,20}$/.test(v)) setInvalid('شماره تلفن نامعتبر است'); break;
            case 'numeric': if (!/^\d+$/.test(v)) setInvalid('فقط عددی'); break;
            case 'fa_letters': if (!/^[\u0600-\u06FF\s]+$/.test(v)) setInvalid('فقط حروف فارسی'); break;
            case 'en_letters': if (!/^[A-Za-z\s]+$/.test(v)) setInvalid('فقط حروف انگلیسی'); break;
            case 'ip': if (!/^((25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(25[0-5]|2[0-4]\d|[01]?\d\d?)$/.test(v)) setInvalid('IP نامعتبر است'); break;
            case 'time': if (!/^(?:[01]?\d|2[0-3]):[0-5]\d$/.test(v)) setInvalid('زمان نامعتبر است'); break;
            case 'date_jalali': if (!/^\d{4}\/(0[1-6]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/.test(v)) setInvalid('تاریخ شمسی نامعتبر است'); break;
            case 'date_greg': if (!/^\d{4}\-(0[1-9]|1[0-2])\-(0[1-9]|[12]\d|3[01])$/.test(v)) setInvalid('تاریخ میلادی نامعتبر است'); break;
            case 'national_id_ir':
                var nid = v.padStart(10,'0');
                if (!/^\d{10}$/.test(nid)) { setInvalid('کد ملی نامعتبر است'); break; }
                if (/^(\d)\1{9}$/.test(nid)) { setInvalid('کد ملی نامعتبر است'); break; }
                var sum = 0; for (var i=0;i<9;i++){ sum += parseInt(nid[i]) * (10 - i); }
                var r = sum % 11; var c = parseInt(nid[9]);
                if (!((r<2 && c===r) || (r>=2 && c===(11-r)))) setInvalid('کد ملی نامعتبر است');
                break;
            case 'postal_code_ir':
                var pc = v;
                if (!/^\d{10}$/.test(pc)) { setInvalid('کد پستی نامعتبر است'); break; }
                if (/^(\d)\1{9}$/.test(pc)) { setInvalid('کد پستی نامعتبر است'); break; }
                break;
            default: break;
        }
        inp.setAttribute('aria-invalid', inp.style.borderColor ? 'true' : 'false');
    });
}
</script>
</body>
</html>

/* global jQuery */
(function(window, document){
    'use strict';

    // Config from PHP
    const CFG = window.ARSHLINE_DASHBOARD || {};
    const ARSHLINE_REST = CFG.restUrl || '';
    const ARSHLINE_NONCE = CFG.restNonce || '';
    const ARSHLINE_CAN_MANAGE = !!CFG.canManage;
    const ARSHLINE_LOGIN_URL = CFG.loginUrl || '';
    const PUBLIC_BASE = CFG.publicBase || '';
    const PUBLIC_TOKEN_BASE = CFG.publicTokenBase || '';
    const STRINGS = CFG.strings || {};
    const t = (key, fallback) => (typeof STRINGS[key] !== 'undefined' ? STRINGS[key] : fallback);

    // Debug flag
    let AR_DEBUG = false;
    try { AR_DEBUG = (localStorage.getItem('arshDebug') === '1'); } catch(_){ /* no-op */}
    const clog = (...args) => { if (AR_DEBUG && window.console) { try { console.log('[ARSH]', ...args); } catch(_){} } };
    const cwarn = (...args) => { if (AR_DEBUG && window.console) { try { console.warn('[ARSH]', ...args); } catch(_){} } };
    const cerror = (...args) => { if (window.console) { try { console.error('[ARSH]', ...args); } catch(_){} } };

    // Toasts
    function ensureToastWrap(){
        let w = document.getElementById('arToastWrap');
        if (!w){ w = document.createElement('div'); w.id = 'arToastWrap'; w.className = 'ar-toast-wrap'; document.body.appendChild(w); }
        return w;
    }
    function notify(message, opts){
        const wrap = ensureToastWrap();
        const el = document.createElement('div');
        const type = (typeof opts === 'string') ? opts : (opts && opts.type) || 'info';
        const variant = ['success','error','info','warn'].includes(type) ? type : 'info';
        el.className = 'ar-toast ar-toast--'+variant;
        const icon = document.createElement('span'); icon.className='ar-toast-ic';
        icon.textContent = (variant==='success') ? '✔' : (variant==='error') ? '✖' : (variant==='warn') ? '⚠' : 'ℹ';
        const text = document.createElement('span'); text.textContent = String(message||'');
        el.appendChild(icon); el.appendChild(text);
        if (opts && opts.actionLabel && typeof opts.onAction === 'function'){
            const btn = document.createElement('button'); btn.textContent = String(opts.actionLabel||'');
            btn.style.cssText='margin-inline-start:.6rem;padding:.25rem .6rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;';
            btn.addEventListener('click', function(){ try { opts.onAction(); } catch(_){} el.remove(); });
            el.appendChild(btn);
        }
        wrap.appendChild(el);
        const duration = (opts && opts.duration) || 2800;
        setTimeout(function(){ el.style.opacity='0'; el.style.transform='translateY(6px)'; }, Math.max(200, duration-500));
        setTimeout(function(){ try { el.remove(); } catch(_){} }, duration);
    }

    function handle401(){
        try {
            notify(t('session_expired','نشست شما منقضی شده یا دسترسی کافی ندارید.'), { type:'error', duration: 5000, actionLabel: 'ورود', onAction: function(){ if (ARSHLINE_LOGIN_URL) location.href = ARSHLINE_LOGIN_URL; }});
        } catch(_){ alert('401 Unauthorized'); }
    }

    // Helpers
    function escapeHtml(s){ try { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); } catch(_){ return String(s||''); } }
    function escapeAttr(s){
        try {
            return String(s||'')
                .replace(/&/g,'&amp;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#39;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;');
        } catch(_){ return String(s||''); }
    }
    function htmlToText(html){ try { const d=document.createElement('div'); d.innerHTML=String(html||''); return d.textContent||d.innerText||''; } catch(_){ return String(html||''); } }
    function sanitizeQuestionHtml(html){
        try {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = String(html||'');
            const allowed = { B:true, I:true, U:true, SPAN:true };
            (function walk(node){
                let child = node.firstChild;
                while(child){
                    const next = child.nextSibling;
                    if (child.nodeType === 1){
                        let tag = child.tagName;
                        if (tag === 'FONT'){
                            try {
                                const span = document.createElement('span');
                                let col = child.getAttribute('color') || (child.style && child.style.color) || '';
                                if (col) span.setAttribute('style', 'color:'+col);
                                while(child.firstChild){ span.appendChild(child.firstChild); }
                                node.replaceChild(span, child);
                                child = span;
                                tag = 'SPAN';
                            } catch(_){ }
                        }
                        if (!allowed[tag]){
                            while(child.firstChild){ node.insertBefore(child.firstChild, child); }
                            node.removeChild(child);
                        } else {
                            // Preserve color on span only
                            let savedColor = '';
                            try {
                                if (tag === 'SPAN'){
                                    if (child.style && child.style.color) savedColor = child.style.color;
                                    if (!savedColor && child.getAttribute) savedColor = child.getAttribute('color') || '';
                                    savedColor = String(savedColor||'').trim();
                                }
                            } catch(_){ savedColor = ''; }
                            for (let i = child.attributes.length - 1; i >= 0; i--) { try { child.removeAttribute(child.attributes[i].name); } catch(_){} }
                            if (tag === 'SPAN'){
                                if (savedColor){ try { child.setAttribute('style', 'color:'+savedColor); } catch(_){ } }
                                else { try { child.removeAttribute('style'); } catch(_){ }
                                }
                            }
                            walk(child);
                        }
                    }
                    child = next;
                }
            })(wrapper);
            return wrapper.innerHTML;
        } catch(_){ return html ? String(html) : ''; }
    }

    // Icons and labels for types
    function getTypeIcon(type){
        switch(type){
            case 'short_text': return 'create-outline';
            case 'long_text': return 'newspaper-outline';
            case 'multiple_choice':
            case 'multiple-choice': return 'list-outline';
            case 'dropdown': return 'chevron-down-outline';
            case 'welcome': return 'happy-outline';
            case 'thank_you': return 'checkmark-done-outline';
            case 'rating': return 'star-outline';
            default: return 'help-circle-outline';
        }
    }
    function getTypeLabel(type){
        switch(type){
            case 'short_text': return 'پاسخ کوتاه';
            case 'long_text': return 'پاسخ طولانی';
            case 'multiple_choice':
            case 'multiple-choice': return 'چندگزینه‌ای';
            case 'dropdown': return 'لیست کشویی';
            case 'welcome': return 'پیام خوش‌آمد';
            case 'thank_you': return 'پیام تشکر';
            case 'rating': return 'امتیاز';
            default: return 'نامشخص';
        }
    }

    // Input mask for preview
    function applyInputMask(inp, props){
        const fmt = (props && props.format) || 'free_text';
        function normalizeDigits(str){
            const fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            const ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
            return (str||'').replace(/[۰-۹٠-٩]/g, function(d){ const i=fa.indexOf(d); if(i>-1) return String(i); const j=ar.indexOf(d); if(j>-1) return String(j); return d; });
        }
        function digitsOnly(){ inp.value = normalizeDigits(inp.value).replace(/\D+/g,''); }
        function allowChars(regex){ const s=normalizeDigits(inp.value); inp.value=(s.match(regex)||[]).join(''); }
        function setInvalid(msg){ inp.style.borderColor = '#b91c1c'; if (msg) inp.title = msg; }
        function clearInvalid(){ inp.style.borderColor = ''; inp.title = ''; }
        inp.addEventListener('input', function(){
            inp.value = normalizeDigits(inp.value);
            switch(fmt){
                case 'numeric': digitsOnly(); break;
                case 'mobile_ir': inp.value = inp.value.replace(/[^\d]/g,''); if (/^9\d/.test(inp.value)) inp.value = '0'+inp.value; if (inp.value.startsWith('98')) inp.value = '0'+inp.value.slice(2); if (inp.value.length>11) inp.value = inp.value.slice(0,11); break;
                case 'mobile_intl': inp.value = inp.value.replace(/(?!^)\D+/g,'').replace(/^([^+\d])+/, ''); if (!inp.value.startsWith('+')) inp.value = '+'+inp.value.replace(/\+/g,''); inp.value = inp.value.replace(/(.*\d{15}).*$/, '$1'); break;
                case 'tel': inp.value = inp.value.replace(/[^0-9\-\+\s\(\)]/g,''); break;
                case 'ip': inp.value = inp.value.replace(/[^0-9\.]/g,'').replace(/\.\.+/g,'.'); break;
                case 'fa_letters': allowChars(/[\u0600-\u06FF\s]/g); break;
                case 'en_letters': allowChars(/[A-Za-z\s]/g); break;
                case 'date_jalali': inp.value = inp.value.replace(/[^0-9/]/g,'').slice(0,10); break;
                case 'national_id_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
                case 'postal_code_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
                default: break;
            }
        });
        inp.addEventListener('blur', function(){
            clearInvalid(); const v=(inp.value||'').trim(); if (!v) return;
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
                case 'national_id_ir': {
                    const nid = v.padStart(10,'0');
                    if (!/^\d{10}$/.test(nid)) { setInvalid('کد ملی نامعتبر است'); break; }
                    if (/^(\d)\1{9}$/.test(nid)) { setInvalid('کد ملی نامعتبر است'); break; }
                    let sum=0; for (let i=0;i<9;i++){ sum += parseInt(nid[i]) * (10-i); }
                    const r = sum % 11; const c = parseInt(nid[9]);
                    if (!((r<2 && c===r) || (r>=2 && c===(11-r)))) setInvalid('کد ملی نامعتبر است');
                    break;
                }
                case 'postal_code_ir': {
                    const pc = v; if (!/^\d{10}$/.test(pc) || /^(\d)\1{9}$/.test(pc)) setInvalid('کد پستی نامعتبر است');
                    break;
                }
                default: break;
            }
            inp.setAttribute('aria-invalid', inp.style.borderColor ? 'true' : 'false');
        });
    }

    // Sidebar and theme toggle
    function setSidebarClosed(closed){
        const sidebar = document.querySelector('.arshline-sidebar');
        const toggle = document.getElementById('arSidebarToggle');
        if (!sidebar) return;
        sidebar.classList.toggle('closed', !!closed);
        if (toggle){
            toggle.setAttribute('aria-expanded', closed ? 'false' : 'true');
            const ch = toggle.querySelector('.chev'); if (ch) ch.textContent = closed ? '❯' : '❮';
        }
        try { localStorage.setItem('arSidebarClosed', closed ? '1':'0'); } catch(_){ }
    }

    function initToggles(){
        // theme
        const themeToggle = document.getElementById('arThemeToggle');
        try { if (localStorage.getItem('arshDark') === '1') document.body.classList.add('dark'); } catch(_){}
        function applyAria(){ if (themeToggle) themeToggle.setAttribute('aria-checked', document.body.classList.contains('dark') ? 'true':'false'); }
        const toggleTheme = function(){ document.body.classList.toggle('dark'); applyAria(); try { localStorage.setItem('arshDark', document.body.classList.contains('dark')?'1':'0'); } catch(_){ } };
        if (themeToggle){ applyAria(); themeToggle.addEventListener('click', toggleTheme); themeToggle.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' '){ e.preventDefault(); toggleTheme(); }}); }
        // sidebar
        const sidebarToggle = document.getElementById('arSidebarToggle');
        if (sidebarToggle){
            const tgl = function(){ const isClosed = document.querySelector('.arshline-sidebar')?.classList.contains('closed'); setSidebarClosed(!isClosed); };
            sidebarToggle.addEventListener('click', tgl);
            sidebarToggle.addEventListener('keydown', function(e){ if (e.key==='Enter'||e.key===' '){ e.preventDefault(); tgl(); }});
            try { const initClosed = localStorage.getItem('arSidebarClosed'); if (initClosed==='1') setSidebarClosed(true); } catch(_){ }
        }
    }

    // Input format helpers used by editor/preview
    function suggestPlaceholder(fmt){
        switch(fmt){
            case 'email': return 'example@mail.com';
            case 'mobile_ir': return '09123456789';
            case 'mobile_intl': return '+14155552671';
            case 'tel': return '021-12345678';
            case 'numeric': return '123456';
            case 'rating': return 'star-outline';
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
        const a = { type:'text', inputmode:'', pattern:'' };
        if (fmt==='email') a.type='email';
        else if (fmt==='numeric') { a.inputmode='numeric'; a.pattern='[0-9]*'; }
        else if (fmt==='mobile_ir' || fmt==='mobile_intl' || fmt==='tel' || fmt==='national_id_ir' || fmt==='postal_code_ir') { a.inputmode='tel'; }
        else if (fmt==='time') a.type='time';
        else if (fmt==='date_greg') a.type='date';
        return a;
    }

    // Results view
    function renderFormResults(formId){
        const content = document.getElementById('arshlineDashboardContent');
        if (!content) return;
        let REST_DEBUG = false;
        try { REST_DEBUG = (localStorage.getItem('arshRestDebug') === '1') || (localStorage.getItem('arshDebug') === '1'); } catch(_){ }
        AR_DEBUG = !!(REST_DEBUG || AR_DEBUG);
        try { clog('results:init', { formId }); } catch(_){ }
        const headerActions = document.getElementById('arHeaderActions');
        if (headerActions){ headerActions.innerHTML = '<button id="arBackToForms" class="ar-btn ar-btn--outline">بازگشت به فرم‌ها</button>'; const backBtn = document.getElementById('arBackToForms'); if (backBtn) backBtn.addEventListener('click', function(){ renderTab('forms'); }); }
        try { setHash('results/'+formId); } catch(_){ }
        content.innerHTML = '<div class="card glass" style="padding:1rem;">\
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.8rem;flex-wrap:wrap;">\
                <span class="title">نتایج فرم #'+formId+'</span>\
            </div>\
            <div id="arFieldFilters" style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem;align-items:center">\
                <select id="arFieldSelect" class="ar-select" style="min-width:220px"><option value="">انتخاب سوال...</option></select>\
                <select id="arFieldOp" class="ar-select"><option value="eq">دقیقا برابر</option><option value="neq">اصلا این نباشد</option><option value="like">شامل باشد</option></select>\
                <span id="arFieldValWrap" style="display:inline-flex;min-width:240px">\
                    <input id="arFieldVal" class="ar-input" placeholder="مقدار فیلتر" style="min-width:240px"/>\
                </span>\
                <button id="arFieldApply" class="ar-btn ar-btn--soft">اعمال فیلتر</button>\
                <button id="arFieldClear" class="ar-btn ar-btn--outline">پاک‌سازی</button>\
                <label class="hint" style="margin-inline-start:1rem">شکستن خطوط:</label>\
                <input id="arWrapToggle" type="checkbox" class="ar-input" />\
                <span style="flex:1 1 auto"></span>\
                <button id="arSubExportCsv" class="ar-btn ar-btn--outline" title="خروجی CSV">خروجی CSV</button>\
                <button id="arSubExportXls" class="ar-btn ar-btn--outline" title="خروجی Excel">خروجی Excel</button>\
            </div>\
            <div id="arSubsList"></div>\
        </div>';
        const expCsv = document.getElementById('arSubExportCsv');
        const expXls = document.getElementById('arSubExportXls');
        const selField = document.getElementById('arFieldSelect');
        const selOp = document.getElementById('arFieldOp');
        let inpVal = document.getElementById('arFieldVal');
        const valWrap = document.getElementById('arFieldValWrap');
        const btnApply = document.getElementById('arFieldApply');
        const btnClear = document.getElementById('arFieldClear');
        const state = { page: 1, per_page: 10 };
        const wrapToggle = document.getElementById('arWrapToggle');
        try {
            const pref = localStorage.getItem('arWrap:'+formId);
            if (wrapToggle) { wrapToggle.checked = (pref === '1'); }
            const container = document.querySelector('.arshline-main');
            if (container){ container.classList.remove('ar-wrap','ar-nowrap'); container.classList.add((wrapToggle && wrapToggle.checked) ? 'ar-wrap' : 'ar-nowrap'); }
        } catch(_){ }
        const fieldMeta = { choices: {}, labels: {}, types: {}, options: {} };
        function buildQuery(){
            const p = new URLSearchParams(); p.set('page', String(state.page||1)); p.set('per_page', String(state.per_page||10));
            const fid = (selField && parseInt(selField.value||'0'))||0;
            const vv = (inpVal && inpVal.value.trim())||'';
            const op = (selOp && selOp.value)||'like';
            if (fid>0 && vv){ p.set('f['+fid+']', vv); if (op && op!=='like') p.set('op['+fid+']', op); }
            return p.toString();
        }
        function buildRestUrl(path, qs){
            try {
                let p = path; if (p.charAt(0)==='/') p = p.slice(1);
                const u = new URL(ARSHLINE_REST||'', window.location.origin);
                if (u.searchParams.has('rest_route')){
                    let rr = u.searchParams.get('rest_route') || '';
                    if (rr && rr.charAt(rr.length-1) !== '/') rr += '/'; rr += p; u.searchParams.set('rest_route', rr);
                } else {
                    if (u.pathname && u.pathname.charAt(u.pathname.length-1) !== '/') u.pathname += '/';
                    u.pathname += p;
                }
                if (qs){ const extra = new URLSearchParams(qs); extra.forEach((v,k)=>u.searchParams.set(k,v)); }
                return u.toString();
            } catch(_){ return (ARSHLINE_REST||'') + path + (qs? ('?'+qs) : ''); }
        }
        function renderTable(resp){
            const list = document.getElementById('arSubsList'); if (!list) return;
            if (!resp){ list.innerHTML = '<div class="hint">پاسخی ثبت نشده است.</div>'; return; }
            const rows = Array.isArray(resp) ? resp : (resp.rows||[]);
            const total = Array.isArray(resp) ? rows.length : (resp.total||0);
            const fields = resp.fields || [];
            let fieldOrder = []; const fieldLabels = {}; const choices = {}; const typesMap = {}; const optionsMap = {};
            if (Array.isArray(fields) && fields.length){
                fields.forEach(function(f){
                    const fid = parseInt(f.id||0); if (!fid) return;
                    fieldOrder.push(fid);
                    const p = f.props||{};
                    fieldLabels[fid] = p.question || ('فیلد #'+fid);
                    typesMap[fid] = (p.type||'');
                    if (Array.isArray(p.options)){
                        optionsMap[fid] = (p.options||[]).map(function(opt){ return { value: String(opt.value||opt.label||''), label: String(opt.label||String(opt.value||'')) }; });
                        p.options.forEach(function(opt){ const v = String(opt.value||opt.label||''); const l = String(opt.label||v); if (!choices[fid]) choices[fid] = {}; if (v) choices[fid][v] = l; });
                    }
                });
            }
            try {
                let savedOrder = [];
                try { savedOrder = JSON.parse(localStorage.getItem('arColsOrder:'+formId) || '[]'); } catch(_){ savedOrder = []; }
                if (Array.isArray(savedOrder) && savedOrder.length){
                    const filtered = savedOrder.map(x=>parseInt(x)).filter(fid=>fieldOrder.indexOf(fid)>=0);
                    if (filtered.length){ fieldOrder = filtered.concat(fieldOrder.filter(fid=>filtered.indexOf(fid)<0)); }
                }
            } catch(_){ }
            fieldMeta.choices = choices; fieldMeta.labels = fieldLabels; fieldMeta.types = typesMap; fieldMeta.options = optionsMap;
            if (selField && selField.children.length<=1 && fieldOrder.length){ selField.innerHTML = '<option value="">انتخاب سوال...</option>' + fieldOrder.map(fid=>'<option value="'+fid+'">'+(fieldLabels[fid]||('فیلد #'+fid))+'</option>').join(''); }
            if (!rows || rows.length===0){ list.innerHTML = '<div class="hint">پاسخی ثبت نشده است.</div>'; return; }
            let html = '<div style="overflow:auto">\
                <table class="ar-table">\
                    <thead><tr>\
                        <th>شناسه</th>\
                        <th>تاریخ</th>';
            fieldOrder.forEach(fid=>{ html += '<th class="ar-th-draggable" draggable="true" data-fid="'+fid+'">'+(fieldLabels[fid]||('فیلد #'+fid))+'</th>'; });
            html += '<th style="border-bottom:1px solid var(--border);padding:.5rem">اقدام</th>\
                    </tr></thead><tbody>';
            html += rows.map(function(it){
                const byField = {}; (Array.isArray(it.values)?it.values:[]).forEach(function(v){ const fid = parseInt(v.field_id||0); if (!fid) return; if (byField[fid] == null) byField[fid] = String(v.value||''); });
                let tr = '\
                <tr>\
                    <td>#'+it.id+'</td>\
                    <td>'+(it.created_at||'')+'</td>';
                fieldOrder.forEach(function(fid){ let val = byField[fid] || ''; if (choices[fid] && choices[fid][val]) val = choices[fid][val]; tr += '<td style="padding:.5rem;border-bottom:1px dashed var(--border)">'+escapeHtml(String(val))+'</td>'; });
                const viewUrl = (window.ARSHLINE_SUB_VIEW_BASE||'').replace('%ID%', String(it.id||'')) || '#';
                tr += '\
                    <td class="actions"><a href="'+viewUrl+'" target="_blank" rel="noopener" class="ar-btn ar-btn--soft">مشاهده پاسخ</a></td>\
                </tr>';
                return tr;
            }).join('');
            html += '</tbody></table></div>';
            if (!Array.isArray(resp)){
                const page = resp.page||1, per = resp.per_page||10; const pages = Math.max(1, Math.ceil(total/per));
                html += '<div style="display:flex;gap:.5rem;align-items:center;justify-content:center;margin-top:.6rem">';
                html += '<button class="ar-btn" data-page="prev" '+(page<=1?'disabled':'')+'>قبلی</button>';
                html += '<span class="hint">صفحه '+page+' از '+pages+' — '+total+' رکورد</span>';
                html += '<button class="ar-btn" data-page="next" '+(page>=pages?'disabled':'')+'>بعدی</button>';
                html += '</div>';
            }
            list.innerHTML = html;
            try {
                const container = document.querySelector('.arshline-main');
                container.classList.remove('ar-wrap','ar-nowrap');
                container.classList.add((wrapToggle && wrapToggle.checked) ? 'ar-wrap' : 'ar-nowrap');
            } catch(_){ }
            (function(){
                const thead = list.querySelector('thead'); if (!thead) return;
                let draggingFid = null;
                function saveOrder(order){ try { localStorage.setItem('arColsOrder:'+formId, JSON.stringify(order)); } catch(_){ } }
                thead.addEventListener('dragstart', function(ev){ const th = ev.target.closest('th[data-fid]'); if (!th) return; draggingFid = parseInt(th.getAttribute('data-fid')||'0'); if (ev.dataTransfer) ev.dataTransfer.effectAllowed = 'move'; });
                thead.addEventListener('dragover', function(ev){ const th = ev.target.closest('th[data-fid]'); if (!th) return; ev.preventDefault(); th.classList.add('ar-th-drag-over'); if (ev.dataTransfer) ev.dataTransfer.dropEffect='move'; });
                thead.addEventListener('dragleave', function(ev){ const th = ev.target.closest('th[data-fid]'); if (th) th.classList.remove('ar-th-drag-over'); });
                thead.addEventListener('drop', function(ev){ const th = ev.target.closest('th[data-fid]'); if (!th) return; ev.preventDefault(); th.classList.remove('ar-th-drag-over'); const targetFid = parseInt(th.getAttribute('data-fid')||'0'); if (!draggingFid || !targetFid || draggingFid===targetFid) return; const from = fieldOrder.indexOf(draggingFid), to = fieldOrder.indexOf(targetFid); if (from<0||to<0) return; const tmp = fieldOrder.splice(from,1)[0]; fieldOrder.splice(to,0,tmp); saveOrder(fieldOrder); renderTable(resp); });
            })();
            function updateFieldValueControl(){
                if (!valWrap) return;
                const fid = (selField && parseInt(selField.value||'0'))||0;
                const prevValEl = document.getElementById('arFieldVal');
                const current = prevValEl ? prevValEl.value : '';
                const hasChoices = !!(fid && fieldMeta && fieldMeta.options && Array.isArray(fieldMeta.options[fid]) && fieldMeta.options[fid].length);
                let newEl;
                if (hasChoices){
                    const sel = document.createElement('select'); sel.id='arFieldVal'; sel.className='ar-select'; sel.style.minWidth='240px';
                    sel.innerHTML = '<option value="">انتخاب مقدار...</option>' + fieldMeta.options[fid].map(o=>'<option value="'+escapeAttr(String(o.value||''))+'">'+escapeHtml(String(o.label||o.value||''))+'</option>').join('');
                    newEl = sel;
                } else {
                    const inp = document.createElement('input'); inp.id='arFieldVal'; inp.className='ar-input'; inp.placeholder='مقدار فیلتر'; inp.style.minWidth='240px'; newEl = inp;
                }
                if (prevValEl){ valWrap.replaceChild(newEl, prevValEl); }
                inpVal = newEl;
                try { if (current) newEl.value = current; } catch(_){ }
            }
            if (selField){ selField.removeEventListener && selField.removeEventListener('change', updateFieldValueControl); selField.addEventListener('change', updateFieldValueControl); }
            if (!Array.isArray(resp)){
                const prev = list.querySelector('button[data-page="prev"]'); const next = list.querySelector('button[data-page="next"]');
                if (prev) prev.onclick = function(){ state.page = Math.max(1, (resp.page||1)-1); load(); };
                if (next) next.onclick = function(){ const pages = Math.max(1, Math.ceil((resp.total||0)/(resp.per_page||10))); state.page = Math.min(pages, (resp.page||1)+1); load(); };
            }
        }
        function load(){
            const list = document.getElementById('arSubsList'); if (!list) return;
            list.innerHTML = '<div class="hint">در حال بارگذاری...</div>';
            const qs = buildQuery();
            const url = buildRestUrl('forms/'+formId+'/submissions', (qs? (qs+'&') : '') + 'include=values,fields' + (REST_DEBUG ? '&debug=1' : ''));
            try { clog('results:fetch:url', url); } catch(_){ }
            fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': ARSHLINE_NONCE } })
                .then(async function(r){
                    try { clog('results:fetch:status', r.status); } catch(_){ }
                    let txt = ''; try { txt = await r.clone().text(); } catch(_){ }
                    if (!r.ok){ try { cerror('results:fetch:error', { status: r.status, body: (txt||'').slice(0,2000) }); } catch(_){ } if (r.status===401){ handle401(); } throw new Error('HTTP '+r.status); }
                    let data; try { data = txt ? JSON.parse(txt) : await r.json(); } catch(e){ try { cerror('results:parse:error', e, 'body:', (txt||'').slice(0, 2000)); } catch(_){ } throw e; }
                    try { if (data && data.debug){ cwarn('results:debug', data.debug); } clog('results:fetch:ok', { total: data.total, rows: (data.rows||[]).length }); } catch(_){ }
                    return data;
                })
                .then(function(resp){ renderTable(resp); })
                .catch(function(err){ try { cerror('results:render:error', err && (err.message||err)); } catch(_){ } list.innerHTML = '<div class="hint">خطا در بارگذاری پاسخ‌ها'+(err && err.message ? (' — '+escapeHtml(String(err.message))) : '')+'</div>'; });
        }
        function addNonce(url){ try { const u=new URL(url); u.searchParams.set('_wpnonce', ARSHLINE_NONCE); return u.toString(); } catch(_){ return url + (url.indexOf('?')>0?'&':'?') + '_wpnonce=' + encodeURIComponent(ARSHLINE_NONCE); } }
        if (expCsv) expCsv.addEventListener('click', function(){ const qs = buildQuery(); const url = buildRestUrl('forms/'+formId+'/submissions', (qs? (qs+'&') : '') + 'format=csv'); window.open(addNonce(url), '_blank'); });
        if (expXls) expXls.addEventListener('click', function(){ const qs = buildQuery(); const url = buildRestUrl('forms/'+formId+'/submissions', (qs? (qs+'&') : '') + 'format=excel'); window.open(addNonce(url), '_blank'); });
        if (wrapToggle) wrapToggle.addEventListener('change', function(){ try { localStorage.setItem('arWrap:'+formId, wrapToggle.checked ? '1' : '0'); } catch(_){ } const root = document.querySelector('.arshline-main'); if(!root) return; root.classList.remove('ar-wrap','ar-nowrap'); root.classList.add(wrapToggle.checked ? 'ar-wrap' : 'ar-nowrap'); });
        if (btnApply) btnApply.addEventListener('click', function(){ state.page = 1; load(); });
        if (btnClear) btnClear.addEventListener('click', function(){ if (selField) selField.value=''; if (inpVal) inpVal.value=''; if (selOp) selOp.value='like'; state.page = 1; load(); });
        load();
    }

    // Save fields helper (used in editors)
    async function saveFields(){
        const builder = document.getElementById('arBuilder');
        const id = parseInt(builder?.dataset.formId||'0');
        const idx = parseInt(builder?.dataset.fieldIndex||'-1');
        const creating = (builder && builder.getAttribute('data-creating') === '1');
        const intendedInsert = builder ? parseInt(builder.getAttribute('data-intended-insert')||'-1') : -1;
        clog('saveFields:start', { id, idx });
        const canvas = document.getElementById('arCanvas');
        const edited = Array.from(canvas.children).map(el=>JSON.parse(el.dataset.props||'{}'))[0] || {};
        const btn = document.getElementById('arSaveFields');
        if (btn){ btn.disabled = true; btn.textContent = 'در حال ذخیره...'; }
        if (isNaN(idx) || idx < 0){
            clog('saveFields:invalid-idx-abort', idx);
            notify('مکان فیلد نامعتبر است. لطفاً صفحه را نوسازی کنید و دوباره تلاش کنید.', 'error');
            if (btn){ btn.disabled = false; btn.textContent = 'ذخیره'; }
            return false;
        }
        if (!ARSHLINE_CAN_MANAGE){ notify('برای ویرایش فرم باید وارد شوید یا دسترسی داشته باشید', 'error'); if (btn){ btn.disabled=false; btn.textContent='ذخیره'; } return false; }
        try {
            const r = await fetch(ARSHLINE_REST + 'forms/'+id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} });
            if (!r.ok){ throw new Error(await r.text() || ('HTTP '+r.status)); }
            const data = await r.json();
            let arr = (data && data.fields) ? data.fields.slice() : [];
            if (creating){
                let at = (!isNaN(intendedInsert) && intendedInsert >= 0 && intendedInsert <= arr.length) ? intendedInsert : arr.length;
                try { const last = arr[arr.length-1]; const lp = last && (last.props||last); if (lp && (lp.type||last.type)==='thank_you' && at >= arr.length) at = arr.length - 1; } catch(_){ }
                arr.splice(at, 0, edited);
            } else {
                if (idx >=0 && idx < arr.length) { arr[idx] = edited; }
                else { arr.push(edited); }
            }
            const r2 = await fetch(ARSHLINE_REST + 'forms/'+id+'/fields', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ fields: arr }) });
            if (!r2.ok){ if (r2.status===401){ handle401(); } throw new Error(await r2.text() || ('HTTP '+r2.status)); }
            await r2.json();
            notify('ذخیره شد', 'success');
            try {
                const b = document.getElementById('arBuilder');
                const idStr = b ? (b.getAttribute('data-form-id') || '0') : '0';
                const idNum = parseInt(idStr);
                if (!isNaN(idNum) && idNum > 0){ renderFormBuilder(idNum); }
            } catch(_){ }
            return true;
        } catch(e){ cerror(e); notify('ذخیره تغییرات ناموفق بود', 'error'); return false; }
        finally { if (btn){ btn.disabled = false; btn.textContent = 'ذخیره'; } }
    }

    // Preview (simplified parity with inline)
    function renderFormPreview(id){
        try { setSidebarClosed(true); } catch(_){ }
        try { setHash('preview/'+id); } catch(_){ }
        document.body.classList.add('preview-only');
        const content = document.getElementById('arshlineDashboardContent');
        content.innerHTML = '<div class="card glass" style="padding:1.2rem;max-width:720px;margin:0 auto;">\
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">\
                <div class="title">پیش‌نمایش فرم #'+ id +'</div>\
                <button id="arPreviewBack" class="ar-btn ar-btn--muted">بازگشت</button>\
            </div>\
            <div id="arFormPreviewFields" style="display:flex;flex-direction:column;gap:.8rem;"></div>\
            <div style="margin-top:1rem;text-align:left;"><button id="arPreviewSubmit" class="ar-btn">ارسال</button></div>\
        </div>';
        fetch(ARSHLINE_REST + 'forms/' + id + '/token', { method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(function(){ return fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }); })
            .then(r=>r.json())
            .then(function(data){
                const fwrap = document.getElementById('arFormPreviewFields');
                let qNum = 0; const questionProps = [];
                (data.fields||[]).forEach(function(f){
                    const p = f.props || f; const type = p.type || f.type || 'short_text';
                    if (type === 'welcome' || type === 'thank_you'){
                        const block = document.createElement('div'); block.className='card glass'; block.style.cssText='padding:.8rem;';
                        const heading = (p.heading && String(p.heading).trim()) || (type==='welcome'?'پیام خوش‌آمد':'پیام تشکر');
                        const message = (p.message && String(p.message).trim()) || '';
                        const img = (p.image_url && String(p.image_url).trim()) ? ('<div style="margin-bottom:.4rem"><img src="'+String(p.image_url).trim()+'" alt="" style="max-width:100%;border-radius:10px"/></div>') : '';
                        block.innerHTML = (heading ? ('<div class="title" style="margin-bottom:.35rem;">'+heading+'</div>') : '') + img + (message ? ('<div class="hint">'+escapeHtml(message)+'</div>') : '');
                        fwrap.appendChild(block); return;
                    }
                    const fmt = p.format || 'free_text';
                    const attrs = inputAttrsByFormat(fmt);
                    const phS = p.placeholder && p.placeholder.trim() ? p.placeholder : suggestPlaceholder(fmt);
                    const row = document.createElement('div');
                    const inputId = 'f_'+(f.id||Math.random().toString(36).slice(2));
                    const descId = inputId+'_desc';
                    const showQ = p.question && String(p.question).trim();
                    const numbered = (p.numbered !== false);
                    if (numbered) qNum += 1;
                    const numberStr = numbered ? (qNum + '. ') : '';
                    const sanitizedQ = sanitizeQuestionHtml(showQ || '');
                    const ariaQ = htmlToText(sanitizedQ || 'پرسش بدون عنوان');
                    const qDisplayHtml = sanitizedQ || 'پرسش بدون عنوان';
                    const questionBlock = '<div class="hint" style="margin-bottom:.25rem">'+ (numbered ? (numberStr + qDisplayHtml) : qDisplayHtml) +'</div>';
                    if (type === 'long_text'){
                        row.innerHTML = questionBlock +
                            '<textarea id="'+inputId+'" class="ar-input" style="width:100%" rows="4" placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'"></textarea>' +
                            (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                    } else if (type === 'multiple_choice' || type === 'multiple-choice') {
                        const opts = p.options || [];
                        const vertical = (p.vertical !== false);
                        const multiple = !!p.multiple;
                        let html = '<div style="display:flex;flex-direction:'+(vertical?'column':'row')+';gap:.5rem;flex-wrap:wrap">';
                        opts.forEach(function(o, i){
                            const lbl = sanitizeQuestionHtml(o.label||'');
                            const sec = o.second_label?('<div class="hint" style="font-size:.8rem">'+escapeHtml(o.second_label)+'</div>') : '';
                            html += '<label style="display:flex;align-items:center;gap:.5rem;"><input type="'+(multiple?'checkbox':'radio')+'" name="mc_'+(f.id||i)+'" value="'+escapeAttr(o.value||'')+'" /> <span>'+lbl+'</span> '+sec+'</label>';
                        });
                        html += '</div>';
                        row.innerHTML = questionBlock + html + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                    } else if (type === 'dropdown') {
                        let dOpts = (p.options || []).slice();
                        if (p.alpha_sort){ dOpts.sort((a,b)=> String(a.label||'').localeCompare(String(b.label||''), 'fa')); }
                        if (p.randomize){ for (let z=dOpts.length-1; z>0; z--){ const j=Math.floor(Math.random()*(z+1)); const tmp=dOpts[z]; dOpts[z]=dOpts[j]; dOpts[j]=tmp; } }
                        let selHtml = '<select id="'+inputId+'" class="ar-input" style="width:100%" data-field-id="'+f.id+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'">';
                        selHtml += '<option value="">'+escapeHtml(p.placeholder || 'انتخاب کنید')+'</option>';
                        dOpts.forEach(o=>{ selHtml += '<option value="'+escapeAttr(o.value||'')+'">'+escapeHtml(o.label||'')+'</option>'; });
                        selHtml += '</select>';
                        row.innerHTML = questionBlock + selHtml + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                    } else if (type === 'rating') {
                        let count = parseInt(p.max||5); if (isNaN(count) || count<1) count=1; if (count>20) count=20;
                        const key = String(p.icon||'star');
                        function mapIcon(k){
                            switch(k){
                                case 'heart': return { solid:'heart', outline:'heart-outline' };
                                case 'thumb': return { solid:'thumbs-up', outline:'thumbs-up-outline' };
                                case 'medal': return { solid:'ribbon', outline:'ribbon-outline' };
                                case 'smile': return { solid:'happy', outline:'happy-outline' };
                                case 'sad': return { solid:'sad', outline:'sad-outline' };
                                default: return { solid:'star', outline:'star-outline' };
                            }
                        }
                        const names = mapIcon(key);
                        let icons = '';
                        for (let ri=1; ri<=count; ri++){
                            icons += '<span class="ar-rating-icon" data-value="'+ri+'" style="cursor:pointer;font-size:1.5rem;color:var(--muted);display:inline-flex;align-items:center;justify-content:center;margin-inline-start:.15rem;">'
                                + '<ion-icon name="'+names.outline+'"></ion-icon>'
                                + '</span>';
                        }
                        const ratingHtml = '<div class="ar-rating-wrap" data-icon-solid="'+names.solid+'" data-icon-outline="'+names.outline+'" data-field-id="'+f.id+'" role="radiogroup" aria-label="امتیاز" style="display:flex;align-items:center;gap:.1rem;">'+icons+'</div>'
                            + '<input type="hidden" id="'+inputId+'" data-field-id="'+f.id+'" value="" />';
                        row.innerHTML = questionBlock + ratingHtml + (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                    } else {
                        row.innerHTML = questionBlock +
                            '<input id="'+inputId+'" class="ar-input" style="width:100%" '+(attrs.type?('type="'+attrs.type+'"'):'')+' '+(attrs.inputmode?('inputmode="'+attrs.inputmode+'"'):'')+' '+(attrs.pattern?('pattern="'+attrs.pattern+'"'):'')+' placeholder="'+(phS||'')+'" data-field-id="'+f.id+'" data-format="'+fmt+'" ' + (p.required?'required':'') + ' aria-describedby="'+(p.show_description?descId:'')+'" aria-invalid="false" aria-label="'+escapeAttr((numbered?numberStr:'')+ariaQ)+'" />' +
                            (p.show_description && p.description ? ('<div id="'+descId+'" class="hint" style="margin-top:.25rem;">'+ escapeHtml(p.description||'') +'</div>') : '');
                    }
                    fwrap.appendChild(row);
                    questionProps.push(p);
                });
                // apply masks
                fwrap.querySelectorAll('input[data-field-id], textarea[data-field-id]').forEach(function(inp, idx){
                    const props = questionProps[idx] || {};
                    applyInputMask(inp, props);
                    if ((props.format||'') === 'date_jalali' && typeof jQuery !== 'undefined' && jQuery.fn.pDatepicker){ try { jQuery(inp).pDatepicker({ format:'YYYY/MM/DD', initialValue:false }); } catch(_){ } }
                });
                // rating interactivity
                try {
                    Array.from(fwrap.querySelectorAll('.ar-rating-wrap')).forEach(function(wrap){
                        const solid = wrap.getAttribute('data-icon-solid') || 'star';
                        const outline = wrap.getAttribute('data-icon-outline') || 'star-outline';
                        const hidden = wrap.nextElementSibling;
                        const items = Array.from(wrap.querySelectorAll('.ar-rating-icon'));
                        function update(v){ items.forEach(function(el, idx){ const ion = el.querySelector('ion-icon'); if (ion){ ion.setAttribute('name', idx < v ? solid : outline); } el.style.color = idx < v ? 'var(--primary)' : 'var(--muted)'; }); if (hidden) hidden.value = String(v||''); }
                        items.forEach(function(el){ el.addEventListener('click', function(){ const v = parseInt(el.getAttribute('data-value')||'0')||0; update(v); }); el.setAttribute('tabindex','0'); el.addEventListener('keydown', function(e){ if (e.key==='Enter' || e.key===' '){ e.preventDefault(); const v = parseInt(el.getAttribute('data-value')||'0')||0; update(v); } }); });
                        update(0);
                    });
                } catch(_){ }
                document.getElementById('arPreviewSubmit').onclick = function(){
                    const vals = [];
                    Array.from(fwrap.querySelectorAll('input[data-field-id], textarea[data-field-id]')).forEach(function(inp){ const fid = parseInt(inp.getAttribute('data-field-id')||'0'); vals.push({ field_id: fid, value: inp.value||'' }); });
                    fetch(ARSHLINE_REST + 'forms/'+id+'/submissions', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ values: vals }) })
                        .then(async r=>{ if (!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
                        .then(function(){ notify('ارسال شد', 'success'); })
                        .catch(function(){ notify('اعتبارسنجی/ارسال ناموفق بود', 'error'); });
                };
                document.getElementById('arPreviewBack').onclick = function(){
                    document.body.classList.remove('preview-only');
                    try {
                        const back = window._arBackTo; window._arBackTo = null;
                        if (back && back.view === 'builder' && back.id){ renderFormBuilder(back.id); return; }
                        if (back && back.view === 'editor' && back.id){ renderFormEditor(back.id, { index: back.index || 0 }); return; }
                    } catch(_){ }
                    renderTab('forms');
                };
            });
    }

    // Minimal field editor and builder – migrated core with tool hooks
    function addNewField(formId, fieldType){
        const ft = fieldType || 'short_text';
        fetch(ARSHLINE_REST + 'forms/'+formId, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(async r=>{ if(!r.ok){ let t=await r.text(); throw new Error(t||('HTTP '+r.status)); } return r.json(); })
            .then(function(data){
                const arr = (data && data.fields) ? data.fields.slice() : [];
                const hasThank = arr.findIndex(function(x){ const p=x.props||x; return (p.type||x.type)==='thank_you'; }) !== -1;
                let insertAt = hasThank ? (arr.length - 1) : arr.length; if (insertAt < 0 || insertAt > arr.length) insertAt = arr.length;
                try { window._arPendingEditor = { id: formId, index: insertAt, creating: true, intendedInsert: insertAt, newType: ft, ts: Date.now() }; } catch(_){ }
                renderFormEditor(formId, { index: insertAt, creating: true, intendedInsert: insertAt, newType: ft });
            })
            .catch(function(){ notify('افزودن فیلد ناموفق بود', 'error'); });
    }

    function renderFormEditor(id, opts){
        // For brevity, reuse the inline implementation by delegating to tool modules when available
        // and falling back to a short_text editor shell to keep behavior.
        // Note: The full detailed inline editor was very large; we'll use the tool module API primarily.

        // Load form and open editor context, preferring tool module renderers
        try { setSidebarClosed(true); } catch(_){ }
        try { const idxHashRaw = (opts && typeof opts.index!=='undefined') ? opts.index : 0; const idxHash = parseInt(idxHashRaw)||0; if (!(opts && opts.creating)) { setHash('editor/'+id+'/'+idxHash); } } catch(_){ }
        document.body.classList.remove('preview-only');
        const content = document.getElementById('arshlineDashboardContent');
        const hiddenCanvas = '<div id="arCanvas" style="display:none"><div class="ar-item" data-props="{}"></div></div>';
        const fieldIndex = (opts && typeof opts.index !== 'undefined') ? parseInt(opts.index) : -1;
        content.innerHTML = '<div id="arBuilder" class="card glass" data-form-id="'+id+'" data-field-index="'+fieldIndex+'" style="padding:1rem;max-width:980px;margin:0 auto;">\
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">\
                <div class="title" id="arEditorTitle">...</div>\
                <div style="display:flex;gap:.5rem;align-items:center;">\
                    <button id="arEditorPreview" class="ar-btn ar-btn--outline">پیش‌نمایش</button>\
                    <button id="arEditorBack" class="ar-btn ar-btn--muted">بازگشت</button>\
                </div>\
            </div>\
            <div style="display:flex;gap:1rem;align-items:flex-start;">\
                <div class="ar-settings" style="width:380px;flex:0 0 380px;"></div>\
                <div class="ar-preview" style="flex:1;"></div>\
            </div>\
        </div>' + hiddenCanvas;
        const backBtn = document.getElementById('arEditorBack'); if (backBtn) backBtn.onclick = function(){ renderFormBuilder(id); };
        const prevBtn = document.getElementById('arEditorPreview'); if (prevBtn) prevBtn.onclick = function(){ try { window._arBackTo = { view: 'editor', id: id, index: fieldIndex }; } catch(_){ } renderFormPreview(id); };

        fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(r=>r.json())
            .then(function(data){
                const fields = data.fields || [];
                let idx = fieldIndex;
                let creating = !!(opts && opts.creating);
                if (creating){
                    if (isNaN(idx) || idx < 0){
                        const hasThankIdx = fields.findIndex(function(x){ const p=x.props||x; return (p.type||x.type) === 'thank_you'; });
                        idx = (hasThankIdx !== -1) ? hasThankIdx : fields.length;
                    }
                } else {
                    if (isNaN(idx) || idx < 0) idx = 0;
                    else if (idx >= fields.length){ creating = true; const hasThankIdx2 = fields.findIndex(function(x){ const p=x.props||x; return (p.type||x.type) === 'thank_you'; }); idx = (hasThankIdx2 !== -1) ? hasThankIdx2 : fields.length; }
                }
                const b = document.getElementById('arBuilder'); if (b){ b.setAttribute('data-field-index', String(idx)); b.setAttribute('data-creating', creating ? '1' : '0'); if (creating && typeof opts?.intendedInsert !== 'undefined') b.setAttribute('data-intended-insert', String(opts.intendedInsert)); if (creating && typeof opts?.newType !== 'undefined') b.setAttribute('data-new-type', String(opts.newType)); }
                const formTitle = (data && data.meta && data.meta.title) ? String(data.meta.title) : '';
                const titleEl = document.getElementById('arEditorTitle'); if (titleEl) titleEl.textContent = creating ? ('ایجاد فرم — ' + (formTitle||(' #'+id))) : ('ویرایش فرم #' + id + (formTitle?(' — ' + formTitle):''));
                // choose defaults based on newType
                const newTypeEffective = (opts && opts.newType) ? String(opts.newType) : null;
                const defaultShort = { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true };
                const base = creating ? ((newTypeEffective && window.ARSH && ARSH.Tools && (ARSH.Tools.getDefaults(newTypeEffective) || ARSH.Tools.getDefaults('short_text'))) || defaultShort) : (fields[idx] || defaultShort);
                const field = base.props || base || defaultShort;
                if (creating && newTypeEffective && (field.type||'') !== newTypeEffective){ field.type = newTypeEffective; }
                const fType = field.type || base.type || 'short_text';

                const sWrap = document.querySelector('.ar-settings');
                const pWrap = document.querySelector('.ar-preview');
                const mod = (window.ARSH && ARSH.Tools && ARSH.Tools.get(fType));
                if (mod && typeof mod.renderEditor === 'function'){
                    try {
                        const ctx = {
                            id, idx, fields,
                            wrappers: { settings: sWrap, preview: pWrap },
                            sanitizeQuestionHtml, escapeHtml, escapeAttr, inputAttrsByFormat, suggestPlaceholder,
                            notify, dlog: clog, setDirty: function(d){ window._arDirty = !!d; window.onbeforeunload = window._arDirty ? function(){ return 'تغییرات ذخیره‌نشده دارید.'; } : null; },
                            saveFields,
                            ARSHLINE_REST, ARSHLINE_NONCE, ARSHLINE_CAN_MANAGE
                        };
                        // support both styles
                        const handled = (mod.renderEditor.length >= 2) ? !!mod.renderEditor(field, ctx) : !!mod.renderEditor({ field, id, idx, fields, wrappers: { settings: sWrap, preview: pWrap }, sanitizeQuestionHtml, escapeHtml, escapeAttr, inputAttrsByFormat, suggestPlaceholder, notify, dlog: clog, setDirty: ctx.setDirty, saveFields, restUrl: ARSHLINE_REST, restNonce: ARSHLINE_NONCE });
                        if (handled) return;
                    } catch(_){ }
                }
                // Fallback basic editor shell
                if (sWrap) sWrap.innerHTML = '<div class="title" style="margin-bottom:.6rem;">'+getTypeLabel(fType)+'</div><div class="hint">ماژول ابزار برای این نوع یافت نشد.</div><div style="margin-top:12px"><button id="arSaveFields" class="ar-btn" style="font-size:.9rem">ذخیره</button></div>';
                if (pWrap) pWrap.innerHTML = '<div class="title" style="margin-bottom:.6rem;">پیش‌نمایش</div><div class="hint">پیش‌نمایش برای '+getTypeLabel(fType)+'</div>';
                const canvasEl = document.querySelector('#arCanvas .ar-item'); if (canvasEl) canvasEl.setAttribute('data-props', JSON.stringify(field));
                const saveBtn = document.getElementById('arSaveFields'); if (saveBtn) saveBtn.onclick = async function(){ const ok = await saveFields(); if (ok){ window._arDirty = false; window.onbeforeunload = null; renderFormBuilder(id); } };
            });
    }

    function renderFormBuilder(id){
        if (!ARSHLINE_CAN_MANAGE){ notify('دسترسی به ویرایش فرم ندارید', 'error'); renderTab('forms'); return; }
        try { setSidebarClosed(true); } catch(_){ }
        document.body.classList.remove('preview-only');
        try { setHash('builder/'+id); } catch(_){ }
        const content = document.getElementById('arshlineDashboardContent');
        content.innerHTML = ''
            + '<div class="card glass" style="padding:1rem;max-width:1080px;margin:0 auto;">'
            +   '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;">'
            +     '<div class="title">ویرایش فرم #'+id+'</div>'
            +     '<div style="display:flex;gap:.5rem;align-items:center;">'
            +       '<button id="arBuilderPreview" class="ar-btn ar-btn--outline">پیش‌نمایش</button>'
            +       '<button id="arBuilderBack" class="ar-btn ar-btn--muted">بازگشت</button>'
            +     '</div>'
            +   '</div>'
            +   '<style>.ar-tabs .ar-btn.active{background:var(--primary, #eef2ff);border-color:var(--primary, #4338ca);color:#111827}</style>'
            +   '<div class="ar-tabs" role="tablist" aria-label="Form Sections" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">'
            +   '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arFormFieldsList" data-tab="builder">ساخت</button>'
            +   '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arDesignPanel" data-tab="design">طراحی</button>'
            +   '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arSettingsPanel" data-tab="settings">تنظیمات</button>'
            +   '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arSharePanel" data-tab="share">ارسال</button>'
            +   '  <button class="ar-btn ar-btn--soft" role="tab" aria-selected="false" aria-controls="arReportsPanel" data-tab="reports">گزارش</button>'
            +   '</div>'
            +   '<div style="display:flex;gap:1rem;align-items:flex-start;">'
            +   '  <div id="arFormSide" style="flex:1;">'
            +   '    <div id="arSectionTitle" class="title" style="margin-bottom:.6rem;">پیش‌نمایش فرم</div>'
            +   '    <div id="arBulkToolbar" style="display:flex;align-items:center;gap:.8rem;margin-bottom:.6rem;">'
            +   '      <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;">'
            +   '        <input id="arSelectAll" type="checkbox" />'
            +   '        <span class="hint">انتخاب همه</span>'
            +   '      </label>'
            +   '      <button id="arBulkDelete" class="ar-btn" disabled>حذف انتخاب‌شده‌ها</button>'
            +   '    </div>'
            +   '    <div id="arFormFieldsList" style="display:flex;flex-direction:column;gap:.8rem;"></div>'
            +   '    <div id="arDesignPanel" style="display:none;">'
            +   '      <div class="card" style="padding:.8rem;">'
            +   '        <div class="field" style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">'
            +   '          <span class="hint">رنگ اصلی</span><input id="arDesignPrimary" type="color" />'
            +   '          <span class="hint">پس‌زمینه</span><input id="arDesignBg" type="color" />'
            +   '          <span class="hint">ظاهر</span><select id="arDesignTheme" class="ar-select"><option value="light">روشن</option><option value="dark">تاریک</option></select>'
            +   '          <button id="arSaveDesign" class="ar-btn">ذخیره طراحی</button>'
            +   '        </div>'
            +   '      </div>'
            +   '    </div>'
            +   '    <div id="arSettingsPanel" style="display:none;">'
            +   '      <div class="card" style="padding:.8rem;display:flex;flex-direction:column;gap:.8rem;">'
            +   '        <div class="title" style="margin-bottom:.2rem;">تنظیمات فرم</div>'
            +   '        <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
            +   '          <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;"><input type="checkbox" id="arSetHoneypot" /> <span>فعال‌سازی Honeypot (ضدربات ساده)</span></label>'
            +   '        </div>'
            +   '        <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
            +   '          <span class="hint">حداقل زمان تکمیل فرم (ثانیه)</span><input id="arSetMinSec" type="number" min="0" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 5" />'
            +   '        </div>'
            +   '        <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
            +   '          <span class="hint">محدودیت نرخ (ارسال در دقیقه)</span><input id="arSetRatePerMin" type="number" min="0" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 10" />'
            +   '          <span class="hint">پنجره زمانی (دقیقه)</span><input id="arSetRateWindow" type="number" min="1" step="1" class="ar-input" style="width:120px" placeholder="مثلاً 5" />'
            +   '        </div>'
            +   '        <div class="field" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">'
            +   '          <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer;"><input type="checkbox" id="arSetCaptchaEnabled" /> <span>فعالسازی reCAPTCHA</span></label>'
            +   '          <span class="hint">Site Key</span><input id="arSetCaptchaSite" type="text" class="ar-input" style="min-width:220px" />'
            +   '          <span class="hint">Secret</span><input id="arSetCaptchaSecret" type="password" class="ar-input" style="min-width:220px" />'
            +   '          <span class="hint">نسخه</span><select id="arSetCaptchaVersion" class="ar-select"><option value="v2">v2 (checkbox)</option><option value="v3">v3 (score)</option></select>'
            +   '        </div>'
            +   '        <div style="display:flex;gap:.5rem;">'
            +   '          <button id="arSaveSettings" class="ar-btn">ذخیره تنظیمات</button>'
            +   '        </div>'
            +   '        <div class="hint">توجه: همه این قابلیت‌ها فلگ‌پذیر و ماژولارند و می‌توانید بر اساس هر فرم آن‌ها را فعال/غیرفعال کنید.</div>'
            +   '      </div>'
            +   '    </div>'
            +   '    <div id="arSharePanel" style="display:none;">'
            +   '      <div class="card" style="padding:.8rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;">'
            +   '        <span class="hint">لینک عمومی فرم:</span><input id="arShareLink" class="ar-input" style="min-width:340px" readonly />'
            +   '        <button id="arCopyLink" class="ar-btn">کپی لینک</button>'
            +   '      </div>'
            +   '    </div>'
            +   '    <div id="arReportsPanel" style="display:none;">'
            +   '      <div class="card" style="padding:.8rem;">'
            +   '        <div class="title" style="margin-bottom:.6rem;">ارسال‌ها</div>'
            +   '        <div id="arSubmissionsList" style="display:flex;flex-direction:column;gap:.5rem"></div>'
            +   '      </div>'
            +   '    </div>'
            +   '  </div>'
            +   '  <div id="arToolsSide" style="width:300px;flex:0 0 300px;border-inline-start:1px solid var(--border);padding-inline-start:1rem;">'
            +   '    <div class="title" style="margin-bottom:.6rem;">ابزارها</div>'
            +   '    <button id="arAddShortText" class="ar-btn ar-toolbtn" draggable="true">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('short_text')+'"></ion-icon></span>'
            +   '      <span>افزودن سؤال با پاسخ کوتاه</span>'
            +   '    </button>'
            +   '    <div style="height:.5rem"></div>'
            +   '    <button id="arAddLongText" class="ar-btn ar-toolbtn" draggable="true">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('long_text')+'"></ion-icon></span>'
            +   '      <span>افزودن سؤال با پاسخ طولانی</span>'
            +   '    </button>'
            +   '    <div style="height:.5rem"></div>'
            +   '    <button id="arAddMultipleChoice" class="ar-btn ar-toolbtn" draggable="true">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('multiple_choice')+'"></ion-icon></span>'
            +   '      <span>افزودن سؤال چندگزینه‌ای</span>'
            +   '    </button>'
            +   '    <div style="height:.5rem"></div>'
            +   '    <button id="arAddRating" class="ar-btn ar-toolbtn" draggable="true">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('rating')+'"></ion-icon></span>'
            +   '      <span>افزودن امتیازدهی</span>'
            +   '    </button>'
            +   '    <div style="height:.5rem"></div>'
            +   '    <button id="arAddDropdown" class="ar-btn ar-toolbtn" draggable="true">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('dropdown')+'"></ion-icon></span>'
            +   '      <span>افزودن لیست کشویی</span>'
            +   '    </button>'
            +   '    <div style="height:.5rem"></div>'
            +   '    <button id="arAddWelcome" class="ar-btn ar-toolbtn">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('welcome')+'"></ion-icon></span>'
            +   '      <span>افزودن پیام خوش‌آمد</span>'
            +   '    </button>'
            +   '    <div style="height:.5rem"></div>'
            +   '    <button id="arAddThank" class="ar-btn ar-toolbtn">'
            +   '      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon('thank_you')+'"></ion-icon></span>'
            +   '      <span>افزودن پیام تشکر</span>'
            +   '    </button>'
            +   '  </div>'
            +   '</div>'
            + '</div>';
        // actions
        const bPrev = document.getElementById('arBuilderPreview'); if (bPrev) bPrev.onclick = function(){ try { window._arBackTo = { view: 'builder', id: id }; } catch(_){ } renderFormPreview(id); };
        const bBack = document.getElementById('arBuilderBack'); if (bBack) bBack.onclick = function(){ renderTab('forms'); };

        // load form data
        fetch(ARSHLINE_REST + 'forms/' + id, { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} })
            .then(r=>r.json())
            .then(function(data){
                const list = document.getElementById('arFormFieldsList');
                // tabs
                try {
                    const tabs = Array.from(content.querySelectorAll('.ar-tabs [data-tab]'));
                    function showPanel(which){
                        const title = document.getElementById('arSectionTitle');
                        const panels = { builder: document.getElementById('arFormFieldsList'), design: document.getElementById('arDesignPanel'), settings: document.getElementById('arSettingsPanel'), share: document.getElementById('arSharePanel'), reports: document.getElementById('arReportsPanel') };
                        Object.keys(panels).forEach(function(k){ panels[k].style.display = (k===which)?'block':'none'; });
                        document.getElementById('arBulkToolbar').style.display = (which==='builder')?'flex':'none';
                        const tools = document.getElementById('arToolsSide'); if (tools) tools.style.display = (which==='builder')?'block':'none';
                        title.textContent = (which==='builder'?'پیش‌نمایش فرم': which==='design'?'طراحی فرم': which==='settings'?'تنظیمات فرم': which==='share'?'ارسال/اشتراک‌گذاری': 'گزارشات فرم');
                        if (which === 'share'){
                            const sl = document.getElementById('arShareLink');
                            // Prefer token-based URL if present
                            let publicUrl = '';
                            try {
                                const token = (data && data.token) ? String(data.token) : '';
                                if (token && PUBLIC_TOKEN_BASE){ publicUrl = PUBLIC_TOKEN_BASE.replace('%TOKEN%', token); }
                                else if (PUBLIC_BASE){ publicUrl = PUBLIC_BASE.replace('%ID%', String(id)); }
                                else { publicUrl = window.location.origin + '/?arshline_form='+id; }
                            } catch(_){ publicUrl = window.location.origin + '/?arshline_form='+id; }
                            if (sl){ sl.value = publicUrl; sl.setAttribute('value', publicUrl); }
                        }
                    }
                    function setActive(btn){ tabs.forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-selected','false'); }); btn.classList.add('active'); btn.setAttribute('aria-selected','true'); }
                    tabs.forEach(function(btn, idx){ btn.setAttribute('tabindex', idx===0? '0' : '-1'); btn.addEventListener('click', function(){ setActive(btn); showPanel(btn.getAttribute('data-tab')); }); btn.addEventListener('keydown', function(e){ const i = tabs.indexOf(btn); if (e.key==='ArrowRight'||e.key==='ArrowLeft'){ e.preventDefault(); const ni=(e.key==='ArrowRight')? (i+1)%tabs.length : (i-1+tabs.length)%tabs.length; tabs[ni].focus(); } if (e.key==='Enter'||e.key===' '){ e.preventDefault(); setActive(btn); showPanel(btn.getAttribute('data-tab')); } }); });
                    const def = content.querySelector('.ar-tabs [data-tab="builder"]'); if (def){ setActive(def); }
                    showPanel('builder');
                } catch(_){ }

                // Design/meta init and save
                const meta = data.meta || {};
                const dPrim = document.getElementById('arDesignPrimary'); if (dPrim) dPrim.value = meta.design_primary || '#1e40af';
                const dBg = document.getElementById('arDesignBg'); if (dBg) dBg.value = meta.design_bg || '#f5f7fb';
                const dTheme = document.getElementById('arDesignTheme'); if (dTheme) dTheme.value = meta.design_theme || 'light';
                try { document.documentElement.style.setProperty('--ar-primary', dPrim.value); const side = document.getElementById('arFormSide'); if (side) side.style.background = dBg.value; } catch(_){ }
                const saveD = document.getElementById('arSaveDesign'); if (saveD){ saveD.onclick = function(){ const payload = { meta: { design_primary: dPrim.value, design_bg: dBg.value, design_theme: dTheme.value } }; fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) }).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(function(){ notify('طراحی ذخیره شد', 'success'); }).catch(function(){ notify('ذخیره طراحی ناموفق بود', 'error'); }); } }

                // Settings init and save
                try {
                    const hp = document.getElementById('arSetHoneypot'); if (hp) hp.checked = !!meta.anti_spam_honeypot;
                    const ms = document.getElementById('arSetMinSec'); if (ms) ms.value = (typeof meta.min_submit_seconds === 'number') ? String(meta.min_submit_seconds) : '';
                    const rpm = document.getElementById('arSetRatePerMin'); if (rpm) rpm.value = (typeof meta.rate_limit_per_min === 'number') ? String(meta.rate_limit_per_min) : '';
                    const rwin = document.getElementById('arSetRateWindow'); if (rwin) rwin.value = (typeof meta.rate_limit_window_min === 'number') ? String(meta.rate_limit_window_min) : '';
                    const ce = document.getElementById('arSetCaptchaEnabled'); if (ce) ce.checked = !!meta.captcha_enabled;
                    const cs = document.getElementById('arSetCaptchaSite'); if (cs) cs.value = meta.captcha_site_key || '';
                    const ck = document.getElementById('arSetCaptchaSecret'); if (ck) ck.value = meta.captcha_secret_key || '';
                    const cv = document.getElementById('arSetCaptchaVersion'); if (cv) cv.value = meta.captcha_version || 'v2';
                    function updateCaptchaInputs(){ const enabled = !!(ce && ce.checked); if (cs) cs.disabled = !enabled; if (ck) ck.disabled = !enabled; if (cv) cv.disabled = !enabled; }
                    updateCaptchaInputs(); if (ce) ce.addEventListener('change', updateCaptchaInputs);
                    const saveS = document.getElementById('arSaveSettings'); if (saveS){ saveS.onclick = function(){ const payload = { meta: {
                        anti_spam_honeypot: !!(hp && hp.checked),
                        min_submit_seconds: Math.max(0, parseInt((ms && ms.value) ? ms.value : '0') || 0),
                        rate_limit_per_min: Math.max(0, parseInt((rpm && rpm.value) ? rpm.value : '0') || 0),
                        rate_limit_window_min: Math.max(1, parseInt((rwin && rwin.value) ? rwin.value : '1') || 1),
                        captcha_enabled: !!(ce && ce.checked),
                        captcha_site_key: (cs && cs.value) ? String(cs.value) : '',
                        captcha_secret_key: (ck && ck.value) ? String(ck.value) : '',
                        captcha_version: (cv && cv.value) ? String(cv.value) : 'v2'
                    } };
                    fetch(ARSHLINE_REST+'forms/'+id+'/meta', { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify(payload) })
                        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
                        .then(function(){ notify('تنظیمات ذخیره شد', 'success'); })
                        .catch(function(){ notify('ذخیره تنظیمات ناموفق بود', 'error'); }); } }
                } catch(_){ }

                // Share link compute now
                let publicUrl = '';
                try {
                    const token = (data && data.token) ? String(data.token) : '';
                    if (token && PUBLIC_TOKEN_BASE) publicUrl = PUBLIC_TOKEN_BASE.replace('%TOKEN%', token);
                    else if (PUBLIC_BASE) publicUrl = PUBLIC_BASE.replace('%ID%', String(id));
                    else publicUrl = window.location.origin + '/?arshline_form='+id;
                } catch(_){ publicUrl = window.location.origin + '/?arshline_form='+id; }
                const shareLink = document.getElementById('arShareLink'); if (shareLink){ shareLink.value = publicUrl; shareLink.setAttribute('value', publicUrl); }
                function copyText(text){
                    if (navigator.clipboard && navigator.clipboard.writeText){ return navigator.clipboard.writeText(text); }
                    return new Promise(function(res, rej){ try { const ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta); ta.select(); const ok = document.execCommand('copy'); document.body.removeChild(ta); ok ? res() : rej(new Error('execCommand failed')); } catch(e){ rej(e); } });
                }
                const copyBtn = document.getElementById('arCopyLink'); if (copyBtn){ copyBtn.onclick = function(){ copyText(publicUrl).then(function(){ notify('کپی شد', 'success'); }).catch(function(){ notify('کپی ناموفق بود', 'error'); }); }; }

                // Reports list
                const repWrap = document.getElementById('arSubmissionsList'); if (repWrap){ fetch(ARSHLINE_REST+'forms/'+id+'/submissions', { credentials:'same-origin', headers:{'X-WP-Nonce': ARSHLINE_NONCE} }).then(function(r){ return r.json(); }).then(function(rows){ repWrap.innerHTML = (rows||[]).map(function(s){ return '<div class="card" style="padding:.5rem;display:flex;justify-content:space-between;">\
                        <span>#'+String(s.id)+' — '+String(s.status||'')+'</span>\
                        <span class="hint">'+String(s.created_at||'')+'</span>\
                    </div>'; }).join('') || '<div class="hint">ارسالی وجود ندارد</div>'; }).catch(function(){ repWrap.innerHTML = '<div class="hint">خطا در بارگذاری گزارشات</div>'; }); }

                // Render fields list (simple, no DnD for now – tool-driven editing still available)
                const fieldsArr = data.fields || [];
                let qCounter = 0;
                list.innerHTML = fieldsArr.map(function(f, i){
                    const p = f.props || f; const type = p.type || f.type || 'short_text';
                    if (type === 'welcome' || type === 'thank_you'){
                        const ttl = (type==='welcome') ? 'پیام خوش‌آمد' : 'پیام تشکر';
                        const head = (p.heading && String(p.heading).trim()) || '';
                        return '<div class="card" data-oid="'+i+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">\
                                    <div class="hint" style="display:flex;align-items:center;gap:.4rem;">\
                                      <span class="ar-type-ic"><ion-icon name="'+getTypeIcon(type)+'"></ion-icon></span>\
                                      <span>'+ttl+' — '+escapeHtml(head)+'</span>\
                                    </div>\
                                    <div style="display:flex;gap:.6rem;align-items:center;">\
                                        <a href="#" class="arEditField" data-id="'+id+'" data-index="'+i+'">ویرایش</a>\
                                    </div>\
                                </div>\
                            </div>';
                    }
                    const q = (p.question&&String(p.question).trim()) || '';
                    let n = ''; if (p.numbered !== false) { qCounter += 1; n = qCounter + '. '; }
                    const qHtml = q ? sanitizeQuestionHtml(q) : 'پرسش بدون عنوان';
                    return '<div class="card" data-oid="'+i+'" style="padding:.6rem;border:1px solid var(--border);border-radius:10px;background:var(--surface);">\
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;">\
                            <div style="display:flex;align-items:center;gap:.5rem;">\
                                <span class="ar-type-ic" title="'+getTypeLabel(type)+'"><ion-icon name="'+getTypeIcon(type)+'"></ion-icon></span>\
                                <div class="qtext">'+n+qHtml+'</div>\
                            </div>\
                            <div style="display:flex;gap:.6rem;align-items:center;">\
                                <a href="#" class="arEditField" data-id="'+id+'" data-index="'+i+'">ویرایش</a>\
                            </div>\
                        </div>\
                    </div>';
                }).join('');
                list.querySelectorAll('.arEditField').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); const idx = parseInt(a.getAttribute('data-index')||'0'); renderFormEditor(id, { index: idx }); }); });

                // Tool buttons
                let lastAddClickTs = 0;
                const addShort = document.getElementById('arAddShortText'); if (addShort){ addShort.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'short_text'); }); }
                const addLong = document.getElementById('arAddLongText'); if (addLong){ addLong.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'long_text'); }); }
                const addMC = document.getElementById('arAddMultipleChoice'); if (addMC){ addMC.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'multiple_choice'); }); }
                const addDD = document.getElementById('arAddDropdown'); if (addDD){ addDD.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'dropdown'); }); }
                const addRating = document.getElementById('arAddRating'); if (addRating){ addRating.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'rating'); }); }
                const addWelcome = document.getElementById('arAddWelcome'); if (addWelcome){ addWelcome.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'welcome'); }); }
                const addThank = document.getElementById('arAddThank'); if (addThank){ addThank.addEventListener('click', function(){ lastAddClickTs = Date.now(); addNewField(id, 'thank_you'); }); }
            });
    }

    // Routing
    let _arNavSilence = 0;
    function setHash(h){ const target = '#'+h; if (location.hash !== target){ _arNavSilence++; location.hash = h; setTimeout(function(){ _arNavSilence = Math.max(0,_arNavSilence-1); }, 0); } }
    function renderTab(tab){
        // Placeholder – the full renderers will be progressively migrated here
        const content = document.getElementById('arshlineDashboardContent');
        if (!content) return;
        if (tab === 'dashboard'){
            content.innerHTML = '<div class="tagline">به عرشلاین خوش آمدید</div>';
        } else if (tab === 'forms'){
            content.innerHTML = '<div class="card glass" style="padding:1rem">'+escapeHtml(t('forms_load_error','در حال آماده‌سازی...'))+'</div>';
        } else {
            content.innerHTML = '<div class="card glass" style="padding:1rem">'+escapeHtml(tab)+'</div>';
        }
    }
    function routeFromHash(){
        const raw = (location.hash||'').replace('#','').trim();
        if (!raw){ renderTab('dashboard'); return; }
        const parts = raw.split('/');
        if (['dashboard','forms','reports','users','settings'].includes(parts[0])){ renderTab(parts[0]); return; }
        if (parts[0]==='builder' && parts[1]){ const id = parseInt(parts[1]||'0'); if (id) { clog('route:builder', id); renderFormBuilder(id); return; } }
        if (parts[0]==='editor' && parts[1]){ const id = parseInt(parts[1]||'0'); const idx = parseInt(parts[2]||'0')||0; clog('route:editor', { id, idx }); if (id) { renderFormEditor(id, { index: idx }); return; } }
        if (parts[0]==='preview' && parts[1]){ const id = parseInt(parts[1]||'0'); if (id) { renderFormPreview(id); return; } }
        if (parts[0]==='results' && parts[1]){ const id = parseInt(parts[1]||'0'); if (id) { renderFormResults(id); return; } }
        renderTab('dashboard');
    }

    // Public API for other modules
    window.ARSHLINE = window.ARSHLINE || {};
    Object.assign(window.ARSHLINE, {
        notify, handle401, applyInputMask, renderTab,
        renderFormBuilder, renderFormEditor, renderFormPreview, renderFormResults, addNewField, saveFields,
        restBase: ARSHLINE_REST, restNonce: ARSHLINE_NONCE, canManage: ARSHLINE_CAN_MANAGE, loginUrl: ARSHLINE_LOGIN_URL,
        t
    });

    // AI Terminal (floating) wiring
    function initAiTerminal(){
        try {
            const panel = document.getElementById('arAiPanel');
            const fab = document.getElementById('arAiFab');
            const closeBtn = document.getElementById('arAiClose');
            const runBtn = document.getElementById('arAiRun');
            const clearBtn = document.getElementById('arAiClear');
            const cmdEl = document.getElementById('arAiCmd');
            const outEl = document.getElementById('arAiOut');
            if (!panel || !fab || !cmdEl || !outEl){ return; }

            function setOpen(b){
                panel.classList.toggle('open', !!b);
                panel.setAttribute('aria-hidden', b ? 'false' : 'true');
                if (b) { try { cmdEl.focus(); } catch(_){} }
                try { sessionStorage.setItem('arAiOpen', b ? '1':'0'); } catch(_){ }
            }
            function appendOut(o){
                try {
                    const old = outEl.textContent || '';
                    const s = (typeof o === 'string') ? o : JSON.stringify(o, null, 2);
                    outEl.textContent = (old ? (old+"\n\n") : '') + s;
                    outEl.scrollTop = outEl.scrollHeight;
                } catch(_){ }
            }
            function saveHist(cmd, res){
                try {
                    let h = [];
                    try { h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]'); } catch(_){ h = []; }
                    h.push({ t: Date.now(), cmd: String(cmd||''), res });
                    h = h.slice(-20);
                    sessionStorage.setItem('arAiHist', JSON.stringify(h));
                } catch(_){ }
            }
            function loadHist(){
                try {
                    const h = JSON.parse(sessionStorage.getItem('arAiHist')||'[]');
                    if (Array.isArray(h) && h.length){
                        outEl.textContent = h.map(function(x){ return '> '+(x.cmd||'')+'\n'+JSON.stringify(x.res||{}, null, 2); }).join('\n\n');
                    }
                } catch(_){ }
            }
            function handleAgentAction(j){
                try {
                    if (!j) return;
                    if (j.action === 'confirm' && j.confirm_action){
                        const msg = String(j.message||'تایید می‌کنید؟');
                        appendOut({ confirm: msg, params: j.confirm_action });
                        try {
                            const wrap = document.createElement('div'); wrap.style.marginTop = '.5rem';
                            const yes = document.createElement('button'); yes.className='ar-btn'; yes.textContent='تایید';
                            const no = document.createElement('button'); no.className='ar-btn ar-btn--outline'; no.textContent='انصراف'; no.style.marginInlineStart='.5rem';
                            yes.addEventListener('click', async function(){
                                try {
                                    const r2 = await fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ confirm_action: j.confirm_action }) });
                                    let txt2 = ''; try { txt2 = await r2.clone().text(); } catch(_){ }
                                    let j2 = null; try { j2 = txt2 ? JSON.parse(txt2) : await r2.json(); } catch(_){ }
                                    appendOut(j2 || (txt2 || ('HTTP '+r2.status)));
                                    if (r2.ok && j2 && j2.ok !== false){ handleAgentAction(j2); notify('تایید شد', 'success'); }
                                    else { notify('انجام نشد', 'error'); }
                                } catch(e){ appendOut(String(e)); notify('خطا', 'error'); }
                            });
                            no.addEventListener('click', function(){ notify('لغو شد', 'warn'); });
                            wrap.appendChild(yes); wrap.appendChild(no);
                            outEl.appendChild(wrap);
                        } catch(_){ }
                        return;
                    }
                    if (j.action === 'clarify' && j.kind === 'options' && Array.isArray(j.options)){
                        appendOut({ clarify: String(j.message||'مبهم است'), options: j.options });
                        try {
                            const wrap2 = document.createElement('div'); wrap2.style.marginTop = '.5rem';
                            (j.options||[]).forEach(function(opt){
                                const b = document.createElement('button'); b.className='ar-btn'; b.textContent=String(opt.label||opt.value);
                                b.style.marginInlineEnd='.5rem';
                                b.addEventListener('click', async function(){
                                    if (j.clarify_action){
                                        const ca = j.clarify_action; const pa = {}; pa[j.param_key] = opt.value;
                                        const r3 = await fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ confirm_action: { action: ca.action, params: pa } }) });
                                        let t3 = ''; try { t3 = await r3.clone().text(); } catch(_){ }
                                        let j3 = null; try { j3 = t3 ? JSON.parse(t3) : await r3.json(); } catch(_){ }
                                        appendOut(j3 || (t3 || ('HTTP '+r3.status)));
                                        if (r3.ok && j3 && j3.ok !== false){ handleAgentAction(j3); notify('انجام شد', 'success'); } else { notify('انجام نشد', 'error'); }
                                    }
                                });
                                wrap2.appendChild(b);
                            });
                            outEl.appendChild(wrap2);
                        } catch(_){ }
                        return;
                    }
                    if (j.action === 'help' && j.capabilities){ appendOut({ capabilities: j.capabilities }); return; }
                    if (j.action === 'ui' && j.target === 'toggle_theme'){
                        try { const tgl = document.getElementById('arThemeToggle'); if (tgl) tgl.click(); } catch(_){ }
                        return;
                    }
                    if (j.action === 'open_tab' && j.tab){ renderTab(String(j.tab)); }
                    else if (j.action === 'open_builder' && j.id){ try { setHash('builder/'+parseInt(j.id)); } catch(_){ } renderTab('forms'); }
                    else if ((j.action === 'download' || j.action === 'export') && j.url){ try { window.open(String(j.url), '_blank'); } catch(_){ } }
                    else if (j.url && !j.action){ try { window.open(String(j.url), '_blank'); } catch(_){ } }
                } catch(_){ }
            }
            async function runAgent(cmdOverride){
                const cmd = (typeof cmdOverride === 'string' && cmdOverride.trim()) ? cmdOverride.trim() : ((cmdEl && cmdEl.value) ? String(cmdEl.value) : '');
                if (!cmd){ notify('دستور خالی است', 'warn'); return; }
                appendOut('> '+cmd);
                try {
                    const r = await fetch(ARSHLINE_REST + 'ai/agent', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce': ARSHLINE_NONCE}, body: JSON.stringify({ command: cmd }) });
                    let txt = ''; try { txt = await r.clone().text(); } catch(_){ }
                    let j = null; try { j = txt ? JSON.parse(txt) : await r.json(); } catch(_){ }
                    appendOut(j || (txt || ('HTTP '+r.status)));
                    saveHist(cmd, j || txt || {});
                    if (r.ok && j && j.ok !== false){ handleAgentAction(j); notify('انجام شد', 'success'); }
                    else { notify('اجرا ناموفق بود', 'error'); }
                } catch(e){ appendOut(String(e)); notify('خطا در اجرای دستور', 'error'); }
            }

            fab.addEventListener('click', function(){ const isOpen = panel.classList.contains('open'); setOpen(!isOpen); });
            if (closeBtn) closeBtn.addEventListener('click', function(){ setOpen(false); });
            if (clearBtn) clearBtn.addEventListener('click', function(){ outEl.textContent=''; cmdEl.value=''; try { sessionStorage.removeItem('arAiHist'); } catch(_){ } });
            if (runBtn) runBtn.addEventListener('click', function(){ runAgent(); });
            cmdEl.addEventListener('keydown', function(e){ if (e.key==='Enter' && (e.ctrlKey||e.metaKey)){ e.preventDefault(); runAgent(); }});
            try { if ((sessionStorage.getItem('arAiOpen')||'')==='1') setOpen(true); } catch(_){ }
            loadHist();

            // Global helper for other modules
            try {
                window.ARSH_AI = {
                    open: function(){ try { setOpen(true); } catch(_){} },
                    run: function(cmd){ try { if (typeof cmd === 'string'){ cmdEl.value = cmd; } runAgent(); } catch(_){} }
                };
            } catch(_){ }
        } catch(_){ }
    }

    // Init
    document.addEventListener('DOMContentLoaded', function(){
        initToggles();
        initAiTerminal();
        if (location.hash){ routeFromHash(); } else { renderTab('dashboard'); }
    });
    window.addEventListener('hashchange', function(){ if (_arNavSilence>0) return; routeFromHash(); });
})(window, document);

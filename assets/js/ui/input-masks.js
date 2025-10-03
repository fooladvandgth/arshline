/* =========================================================================
   FILE: assets/js/ui/input-masks.js
   Purpose: applyInputMask for preview/editor inputs
   Exports: window.applyInputMask (back-compat), window.ARSHLINE.UI.applyInputMask
   Guards: ARSH_MASKS_INIT
   ========================================================================= */
(function(){
  if (typeof window === 'undefined') return;
  if (window.ARSH_MASKS_INIT) return; window.ARSH_MASKS_INIT = true;

  // استفاده از تابع مشترک normalizeDigits از persian-utils.js
  var normalizeDigits = window.ARSHLINE?.Persian?.normalizeDigits || function(str){
    var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    var ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    return (String(str||'')).replace(/[۰-۹٠-٩]/g, function(d){
      var i = fa.indexOf(d); if (i>-1) return String(i);
      var j = ar.indexOf(d); if (j>-1) return String(j);
      return d;
    });
  };

  function applyInputMask(inp, props){
    if (!inp) return;
    var fmt = (props && props.format) || 'free_text';
    function digitsOnly(){ inp.value = normalizeDigits(inp.value).replace(/\D+/g,''); }
    function allowChars(regex){ var s = normalizeDigits(inp.value); inp.value = (s.match(regex)||[]).join(''); }
    function setInvalid(msg){ inp.style.borderColor = '#b91c1c'; if (msg) inp.title = msg; }
    function clearInvalid(){ inp.style.borderColor = ''; inp.title = ''; }
    function clampLen(){ try { var max = parseInt(props && props.max_length); if (max>0 && inp.value.length>max) { inp.value = inp.value.slice(0, max); } } catch(_){} }

    inp.addEventListener('input', function(){
      inp.value = normalizeDigits(inp.value);
      switch(fmt){
        case 'numeric': digitsOnly(); break;
        case 'mobile_ir': inp.value = inp.value.replace(/[^\d]/g,''); if (/^9\d/.test(inp.value)) inp.value = '0'+inp.value; if (inp.value.startsWith('98')) inp.value = '0'+inp.value.slice(2); if (inp.value.length>11) inp.value = inp.value.slice(0,11); break;
        case 'mobile_intl': inp.value = inp.value.replace(/(?!^)\D+/g,'').replace(/^(?=[^+\d]).*/,''); if (!inp.value.startsWith('+')) inp.value = '+'+inp.value.replace(/\+/g,''); inp.value = inp.value.replace(/(.*\d{15}).*$/, '$1'); break;
        case 'tel': inp.value = inp.value.replace(/[^0-9\-\+\s\(\)]/g,''); break;
        case 'ip': inp.value = inp.value.replace(/[^0-9\.]/g,'').replace(/\.\.+/g,'.'); break;
        case 'fa_letters': allowChars(/[\u0600-\u06FF\s]/g); break;
        case 'en_letters': allowChars(/[A-Za-z\s]/g); break;
        case 'date_jalali': inp.value = inp.value.replace(/[^0-9\/]/g,'').slice(0,10); break;
        case 'national_id_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
        case 'postal_code_ir': inp.value = inp.value.replace(/\D+/g,'').slice(0,10); break;
        default: break;
      }
      clampLen();
    });

    inp.addEventListener('blur', function(){
      clearInvalid();
      var v = (inp.value||'').trim(); if (!v) return;
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

  // Public API
  window.ARSHLINE = window.ARSHLINE || {};
  window.ARSHLINE.UI = window.ARSHLINE.UI || {};
  window.ARSHLINE.UI.applyInputMask = applyInputMask;
  // Back-compat global
  window.applyInputMask = applyInputMask;
})();

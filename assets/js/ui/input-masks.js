(function(){
  'use strict';
  function normalizeDigits(str){
    try {
      var map = { '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
                  '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9' };
      return String(str||'').replace(/[۰-۹٠-٩]/g, function(d){ return map[d] || d; });
    } catch(_) { return String(str||''); }
  }
  function applyInputMask(inp, props){
    try {
      if (!inp) return;
      var p = props || {};
      var fmt = p.format || 'free_text';
      var maxLen = (typeof p.max_length === 'number') ? p.max_length : null;
      var typeDigitsOnly = ['numeric','mobile_ir','mobile_intl','tel','postal_code_ir','national_id_ir'].indexOf(fmt) >= 0;
      var typeFaLetters = fmt === 'fa_letters';
      var typeEnLetters = fmt === 'en_letters';

      function clampLen(){
        if (maxLen && inp.value && inp.value.length > maxLen){ inp.value = inp.value.slice(0, maxLen); }
      }
      function digitsOnly(){ inp.value = normalizeDigits(inp.value).replace(/\D+/g,''); }
      function allow(regex){
        var s = normalizeDigits(inp.value);
        var m = s.match(regex);
        inp.value = m ? m.join('') : '';
      }
      function validateOnBlur(){ inp.setAttribute('aria-invalid', inp.value && inp.style.borderColor ? 'true' : 'false'); }

      inp.addEventListener('input', function(){
        if (typeDigitsOnly) digitsOnly();
        else if (typeFaLetters) allow(/[\u0600-\u06FF\s]+/g);
        else if (typeEnLetters) allow(/[A-Za-z\s]+/g);
        clampLen();
      });
      inp.addEventListener('blur', validateOnBlur);
    } catch(_){ }
  }
  try {
    window.ARSH = window.ARSH || {};
    window.ARSH.UI = window.ARSH.UI || {};
    window.ARSH.UI.applyInputMask = applyInputMask;
    // Back-compat global
    if (typeof window.applyInputMask !== 'function') window.applyInputMask = applyInputMask;
  } catch(_){ }
})();

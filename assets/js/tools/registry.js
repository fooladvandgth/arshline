(function(window){
  'use strict';
  // Namespace
  var ns = window.ARSH = window.ARSH || {};
  // Tools registry
  (function(ns){
    var _defs = Object.create(null);
    function register(def){ if (!def || !def.type) return; _defs[def.type] = def; }
    function get(type){ return _defs[type] || null; }
    function clone(obj){ try { return JSON.parse(JSON.stringify(obj)); } catch(_) { return obj; } }
    function getDefaults(type){ var d = get(type); return d && d.defaults ? clone(d.defaults) : null; }
    function renderEditor(type, field, ctx){
      try {
        var d = get(type); if (!d || typeof d.renderEditor !== 'function') return false; ctx = ctx || {}; ctx.field = ctx.field || field;
        if (d.renderEditor.length >= 2) { return !!d.renderEditor(field, ctx); }
        else { return !!d.renderEditor(ctx); }
      } catch(_){ return false; }
    }
    function renderPreview(type, field, ctx){
      try {
        var d = get(type); if (!d || typeof d.renderPreview !== 'function') return false; ctx = ctx || {}; ctx.field = ctx.field || field;
        if (d.renderPreview.length >= 2) { return !!d.renderPreview(field, ctx); }
        else { return !!d.renderPreview(ctx); }
      } catch(_){ return false; }
    }
    ns.Tools = { register: register, get: get, getDefaults: getDefaults, renderEditor: renderEditor, renderPreview: renderPreview };
  })(ns);

  // Core tool defaults (previously inline in template)
  ns.Tools.register({
    type: 'short_text',
    defaults: { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true }
  });
  ns.Tools.register({
    type: 'long_text',
    defaults: { type:'long_text', label:'پاسخ طولانی', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true, min_length:0, max_length:5000, media_upload:false }
  });
  ns.Tools.register({
    type: 'multiple_choice',
    defaults: { type:'multiple_choice', label:'سوال چندگزینه‌ای', options:[{ label:'گزینه 1', value:'opt_1', second_label:'', media_url:'' }], multiple:false, required:false, vertical:true, randomize:false, numbered:true }
  });
  ns.Tools.register({
    type: 'dropdown',
    defaults: { type:'dropdown', label:'لیست کشویی', question:'', required:false, numbered:true, show_description:false, description:'', placeholder:'', options:[{ label:'گزینه 1', value:'opt_1' }], randomize:false, alpha_sort:false }
  });
  ns.Tools.register({
    type: 'rating',
    defaults: { type:'rating', label:'امتیازدهی', question:'', required:false, numbered:true, show_description:false, description:'', max:5, icon:'star', media_upload:false }
  });
})(window);

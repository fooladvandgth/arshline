/*!
 * ARSH Core Tool Defaults
 * Registers built-in tool types with their default props
 * Extracted from dashboard-template.php
 */
(function (global) {
    'use strict';
    var ARSH = global.ARSH = global.ARSH || {};
    if (!ARSH.Tools || !ARSH.Tools.register) return; // safety

    ARSH.Tools.register({
        type: 'short_text',
        defaults: { type:'short_text', label:'پاسخ کوتاه', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true }
    });

    ARSH.Tools.register({
        type: 'long_text',
        defaults: { type:'long_text', label:'پاسخ طولانی', format:'free_text', required:false, show_description:false, description:'', placeholder:'', question:'', numbered:true, min_length:0, max_length:5000, media_upload:false }
    });

    ARSH.Tools.register({
        type: 'multiple_choice',
        defaults: { type:'multiple_choice', label:'سوال چندگزینه‌ای', options:[{ label:'گزینه 1', value:'opt_1', second_label:'', media_url:'' }], multiple:false, required:false, vertical:true, randomize:false, numbered:true }
    });

    ARSH.Tools.register({
        type: 'dropdown',
        defaults: { type:'dropdown', label:'لیست کشویی', question:'', required:false, numbered:true, show_description:false, description:'', placeholder:'', options:[{ label:'گزینه 1', value:'opt_1' }], randomize:false, alpha_sort:false }
    });

    ARSH.Tools.register({
        type: 'rating',
        defaults: { type:'rating', label:'امتیازدهی', question:'', required:false, numbered:true, show_description:false, description:'', max:5, icon:'star', media_upload:false }
    });

    // Message blocks
    ARSH.Tools.register({
        type: 'welcome',
        defaults: { type:'welcome', label:'پیام خوش‌آمد', heading:'خوش آمدید', message:'', image_url:'' }
    });

    ARSH.Tools.register({
        type: 'thank_you',
        defaults: { type:'thank_you', label:'پیام تشکر', heading:'با تشکر از شما', message:'', image_url:'' }
    });
})(window);

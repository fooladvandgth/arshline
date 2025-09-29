(function($){
  'use strict';
  // اسکریپت مدیریت CRUD گروه‌ها و اعضا + اتصال فرم‌ها به گروه‌ها
  // Fallbacks برای اجرای داخل داشبورد سفارشی
  var REST = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.rest) || (window.ARSHLINE_REST || '');
  var NONCE = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.nonce) || (window.ARSHLINE_NONCE || '');
  var STR = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.strings) || {};
  var NONCES = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.nonces) || {};
  var ADMIN_POST = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.adminPostUrl) || '';
  var FORMS_ENDPOINT = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.formsEndpoint) || ((REST||'') + 'forms');
  var PANEL = (function(){ try { return (location.hash||'').indexOf('#users/ug') !== -1; } catch(_){ return false; } })();

  function getHashParams(){
    try { var hash = String(location.hash||''); var q = hash.split('?')[1]||''; var sp = new URLSearchParams(q); var o={}; sp.forEach(function(v,k){ o[k]=v; }); return o; } catch(_){ return {}; }
  }
  function getParam(name){
    // ابتدا از hash (برای داشبورد سفارشی) سپس از search (صفحه ادمین) می‌خوانیم
    var hp = getHashParams(); if (Object.prototype.hasOwnProperty.call(hp, name)) return hp[name];
    try { return new URLSearchParams(location.search).get(name); } catch(_){ return null; }
  }

  function api(path, opts){
    opts = opts || {}; opts.headers = opts.headers || {};
    opts.headers['X-WP-Nonce'] = NONCE;
    return fetch(REST + path, opts).then(function(r){ if(!r.ok) throw r; return r.json(); });
  }

  function esc(s){ return String(s||''); }

  function renderGroups($m){
    $m.html('<div>'+esc(STR.loading||'در حال بارگذاری...')+'</div>');
    api('user-groups', { credentials:'same-origin' })
      .then(function(groups){
        var html = '';
        // Wrap in a card glass block to match dashboard visuals
        html += '<div class="card glass" style="padding:1rem;">';
        // Toolbar: title on left, Add button aligned to the right (in RTL)
        html += '  <div class="ar-ug-toolbar" style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem">';
        html += '    <span class="title">'+esc(STR.groups||'گروه‌ها')+'</span>';
        html += '    <span style="flex:1 1 auto"></span>';
  html += '    <button id="ugAddToggle" type="button" class="button button-primary">'+esc(STR.add||'افزودن')+'</button>';
        html += '  </div>';
        // Hidden add box (appears within the same card, not above the table visually)
        html += '  <div id="ugAddBox" style="display:none;margin-bottom:.6rem;padding:.5rem;border:1px dashed var(--border, #d1d5db);border-radius:.5rem;background:var(--surface, #fff)">';
        html += '    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">';
        html += '      <input id="ugNewName" class="regular-text" placeholder="'+esc(STR.name||'نام')+'"/>';
  html += '      <button id="ugAddConfirm" type="button" class="button button-primary">'+esc(STR.add||'افزودن')+'</button>';
  html += '      <button id="ugAddCancel" type="button" class="button">'+esc(STR.cancel||'انصراف')+'</button>';
        html += '    </div>';
        html += '  </div>';
        // Table
        html += '  <div class="table-wrap">';
        html += '    <table class="widefat striped" style="width:100%"><thead><tr><th>ID</th><th>'+esc(STR.name||'نام')+'</th><th>تعداد اعضا</th><th></th></tr></thead><tbody>';
        (groups||[]).forEach(function(g){
          html += '<tr data-id="'+g.id+'">';
          html += '<td>'+g.id+'</td>';
          html += '<td><span class="ugNameText">'+esc(g.name)+'</span></td>';
          html += '<td>'+(g.member_count||0)+'</td>';
          html += '<td>';
          html += '<button class="button ugEdit">'+esc(STR.edit||'ویرایش')+'</button> ';
          html += '<button class="button button-link-delete ugDel">'+esc(STR.delete||'حذف')+'</button> ';
          html += '<a class="button" href="#users/ug?tab=custom_fields&group_id='+g.id+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>';
          html += '</td>';
          html += '</tr>';
        });
        html += '    </tbody></table>';
        html += '  </div>'; // table-wrap
        html += '</div>'; // card glass
        $m.html(html);
        // Toggle add box visibility
        $m.off('click', '#ugAddToggle').on('click', '#ugAddToggle', function(e){
          e.preventDefault();
          var $box = $('#ugAddBox'); if(!$box.length) return;
          var willShow = $box.is(':hidden');
          try { $box.stop(true,true).slideToggle(150, function(){ if (willShow) { try { document.getElementById('ugNewName').focus(); } catch(_){ } } }); } catch(_){ $box.toggle(); if (willShow) { try { document.getElementById('ugNewName').focus(); } catch(__){} } }
        });
        // Confirm add (avoid duplicate bind)
        $m.off('click', '#ugAddConfirm').on('click', '#ugAddConfirm', function(){
          var name = $('#ugNewName').val().trim(); if(!name){ try{ document.getElementById('ugNewName').focus(); }catch(_){ } return; }
          api('user-groups', { credentials:'same-origin', method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name: name }) })
            .then(function(){ if (window.notify) notify('گروه ایجاد شد', 'success'); $('#ugNewName').val(''); try { $('#ugAddBox').slideUp(120); } catch(_){ $('#ugAddBox').hide(); } renderGroups($m); })
            .catch(function(){ if (window.notify) notify('ایجاد گروه ناموفق بود', 'error'); });
        });
        // Enter to confirm
  $m.off('keydown', '#ugNewName').on('keydown', '#ugNewName', function(e){ if (e.key === 'Enter'){ e.preventDefault(); $('#ugAddConfirm').trigger('click'); } });
        // Cancel
  $m.off('click', '#ugAddCancel').on('click', '#ugAddCancel', function(){ $('#ugNewName').val(''); try { $('#ugAddBox').slideUp(120); } catch(_){ $('#ugAddBox').hide(); } });
        // Enter edit mode
        $m.on('click', '.ugEdit', function(){
          var $tr=$(this).closest('tr'); var id=+$tr.data('id');
          var $nameCell = $tr.find('td').eq(1);
          var current = $nameCell.find('.ugNameText').text();
          $nameCell.data('orig', current);
          $nameCell.html('<input class="ugName" type="text" value="'+esc(current)+'"/>' );
          var gid = id;
          var $btnCell = $tr.find('td').eq(3);
          $btnCell.html('<button class="button ugSave">'+esc(STR.save||'ذخیره')+'</button> <button class="button ugCancel">'+esc(STR.cancel||'انصراف')+'</button> <a class="button" href="#users/ug?tab=custom_fields&group_id='+gid+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>');
          try { $tr.find('.ugName').focus(); } catch(_){ }
        });
        // Cancel edit
        $m.on('click', '.ugCancel', function(){ var $tr=$(this).closest('tr'); var $nameCell = $tr.find('td').eq(1); var orig = $nameCell.data('orig')||$nameCell.text(); $nameCell.html('<span class="ugNameText">'+esc(orig)+'</span>'); var gid=+$tr.data('id'); var $btnCell=$tr.find('td').eq(3); $btnCell.html('<button class="button ugEdit">'+esc(STR.edit||'ویرایش')+'</button> <button class="button button-link-delete ugDel">'+esc(STR.delete||'حذف')+'</button> <a class="button" href="#users/ug?tab=custom_fields&group_id='+gid+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>'); });
        // Save edit
        function finishRowView($tr, newName){ var $nameCell = $tr.find('td').eq(1); $nameCell.html('<span class="ugNameText">'+esc(newName)+'</span>'); var gid=+$tr.data('id'); var $btnCell=$tr.find('td').eq(3); $btnCell.html('<button class="button ugEdit">'+esc(STR.edit||'ویرایش')+'</button> <button class="button button-link-delete ugDel">'+esc(STR.delete||'حذف')+'</button> <a class="button" href="#users/ug?tab=custom_fields&group_id='+gid+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>'); }
        $m.on('keydown', 'input.ugName', function(e){ if (e.key==='Enter'){ e.preventDefault(); $(this).closest('tr').find('.ugSave').trigger('click'); } });
        $m.on('click', '.ugSave', function(){ var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.ugName').val(); api('user-groups/'+id, { credentials:'same-origin', method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name }) })
          .then(function(){ if (window.notify) notify('ذخیره شد', 'success'); finishRowView($tr, name); })
          .catch(function(){ if (window.notify) notify('ذخیره گروه ناموفق بود', 'error'); }); });
        $m.on('click', '.ugDel', function(){ if(!confirm(STR.confirm_delete||'حذف؟')) return; var $tr=$(this).closest('tr'); var id=+$tr.data('id'); api('user-groups/'+id, { credentials:'same-origin', method:'DELETE' })
          .then(function(){ if (window.notify) notify('گروه حذف شد', 'success'); renderGroups($m); })
          .catch(function(){ if (window.notify) notify('حذف گروه ناموفق بود', 'error'); }); });
    })
    .catch(function(){ $m.html('<div class="notice notice-error">'+esc(STR.groups_load_error||'خطا در بارگذاری لیست گروه‌ها')+'</div>'); if (window.notify) notify('خطا در بارگذاری لیست گروه‌ها', 'error'); });
  }
  function renderCustomFields($m){
    api('user-groups', { credentials:'same-origin' }).then(function(groups){
      var gid = parseInt(getParam('group_id') || (groups[0] && groups[0].id) || 0, 10) || 0;
  var html='';
  html += '<form method="get" class="arsh-ug-filter" style="margin-bottom:8px">';
      html += '<input type="hidden" name="page" value="arshline-user-groups"/>';
      html += '<input type="hidden" name="tab" value="custom_fields"/>';
      html += '<label>'+esc(STR.group||'گروه')+': <select name="group_id" id="ugSelCF">';
      (groups||[]).forEach(function(g){ html += '<option value="'+g.id+'"'+(g.id===gid?' selected':'')+'>'+esc(g.name)+'</option>'; });
      html += '</select></label> ';
      html += '<button class="button">'+esc(STR.search||'برو')+'</button>';
      html += '</form>';

      html += '<div id="ugFieldsBox">'+esc(STR.loading||'در حال بارگذاری...')+'</div>';
  $m.html(html);
  if (PANEL) { $m.on('submit', 'form.arsh-ug-filter', function(e){ e.preventDefault(); }); }

      function list(){ if (!gid){ $('#ugFieldsBox').html('<div class="notice notice-info">'+esc(STR.select_group||'یک گروه را انتخاب کنید')+'</div>'); return; }
        api('user-groups/'+gid+'/fields', { credentials:'same-origin' }).then(function(fields){
        var t = '<div style="margin:8px 0;display:flex;gap:.4rem;align-items:center">'+
          '<input id="ugFName" class="regular-text" placeholder="کلید (مثل code)"/>'+ 
          '<input id="ugFLabel" class="regular-text" placeholder="برچسب (مثل کد)"/>'+ 
          '<select id="ugFType"><option value="text">متن</option><option value="number">عدد</option><option value="date">تاریخ</option><option value="email">ایمیل</option><option value="select">انتخابی</option><option value="checkbox">چک‌باکس</option></select>'+ 
          '<label><input id="ugFReq" type="checkbox"/> اجباری</label>'+ 
          '<button id="ugFAdd" class="button button-primary">افزودن فیلد</button>'+ 
        '</div>';
        t += '<table class="widefat striped"><thead><tr><th>#</th><th>کلید</th><th>برچسب</th><th>نوع</th><th>اجباری</th><th>مرتب‌سازی</th><th></th></tr></thead><tbody>';
        (fields||[]).forEach(function(f){
          t += '<tr data-id="'+f.id+'">'+
            '<td>'+f.id+'</td>'+ 
            '<td><input class="cfName" value="'+esc(f.name)+'"/></td>'+ 
            '<td><input class="cfLabel" value="'+esc(f.label||'')+'"/></td>'+ 
            '<td><select class="cfType">'+
              '<option '+(f.type==='text'?'selected':'')+' value="text">متن</option>'+ 
              '<option '+(f.type==='number'?'selected':'')+' value="number">عدد</option>'+ 
              '<option '+(f.type==='date'?'selected':'')+' value="date">تاریخ</option>'+ 
              '<option '+(f.type==='email'?'selected':'')+' value="email">ایمیل</option>'+ 
              '<option '+(f.type==='select'?'selected':'')+' value="select">انتخابی</option>'+ 
              '<option '+(f.type==='checkbox'?'selected':'')+' value="checkbox">چک‌باکس</option>'+ 
            '</select></td>'+ 
            '<td><input type="checkbox" class="cfReq" '+(f.required?'checked':'')+' /></td>'+ 
            '<td><input class="cfSort" type="number" value="'+(f.sort||0)+'" style="width:80px"/></td>'+ 
            '<td><button class="button cfSave">'+esc(STR.save||'ذخیره')+'</button> <button class="button button-link-delete cfDel">'+esc(STR.delete||'حذف')+'</button></td>'+ 
          '</tr>';
        });
        t += '</tbody></table>';
        $('#ugFieldsBox').html(t);
      }); }
      list();
      // Auto-switch in panel context when group select changes (Custom Fields)
      $m.on('change', '#ugSelCF', function(){
        try {
          var gidNew = parseInt(this.value,10)||0;
          var h = (location.hash||'').split('?')[0] || '#users/ug?tab=custom_fields';
          var qs = new URLSearchParams((location.hash||'').split('?')[1]||'');
          qs.set('group_id', String(gidNew));
          location.hash = h + '?' + qs.toString();
          // refresh list immediately
          gid = gidNew; list();
        } catch(_){ }
      });

      $m.on('click', '#ugFAdd', function(e){ e.preventDefault(); if(!gid){ if (window.notify) notify('ابتدا گروه را انتخاب کنید', 'warn'); return; } var name=$('#ugFName').val().trim(); var label=$('#ugFLabel').val().trim()||name; var type=$('#ugFType').val(); var req=$('#ugFReq').is(':checked'); if(!name) return; api('user-groups/'+gid+'/fields', { credentials:'same-origin', method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, label:label, type:type, required:req, sort:0 }) })
        .then(function(){ if (window.notify) notify('فیلد افزوده شد', 'success'); $('#ugFName').val(''); $('#ugFLabel').val(''); $('#ugFReq').prop('checked', false); list(); })
        .catch(function(){ if (window.notify) notify('افزودن فیلد ناموفق بود', 'error'); }); });
      $m.on('click', '.cfSave', function(e){ e.preventDefault(); if(!gid){ if (window.notify) notify('ابتدا گروه را انتخاب کنید', 'warn'); return; } var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.cfName').val(); var label=$tr.find('.cfLabel').val(); var type=$tr.find('.cfType').val(); var req=$tr.find('.cfReq').is(':checked'); var sort=parseInt($tr.find('.cfSort').val()||'0',10)||0; api('user-groups/'+gid+'/fields/'+id, { credentials:'same-origin', method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, label:label, type:type, required:req, sort:sort }) })
        .then(function(){ if (window.notify) notify('ذخیره شد', 'success'); })
        .catch(function(){ if (window.notify) notify('ذخیره فیلد ناموفق بود', 'error'); }); });
      $m.on('click', '.cfDel', function(e){ e.preventDefault(); if(!gid){ if (window.notify) notify('ابتدا گروه را انتخاب کنید', 'warn'); return; } if(!confirm(STR.confirm_delete||'حذف؟')) return; var $tr=$(this).closest('tr'); var id=+$tr.data('id'); api('user-groups/'+gid+'/fields/'+id, { credentials:'same-origin', method:'DELETE' })
        .then(function(){ if (window.notify) notify('فیلد حذف شد', 'success'); list(); })
        .catch(function(){ if (window.notify) notify('حذف فیلد ناموفق بود', 'error'); }); });
    });
  }

  function renderMembers($m){
    // فیلتر بر اساس گروه + لیست اعضا + ایمپورت CSV
    api('user-groups', { credentials:'same-origin' }).then(function(groups){
      var gid = parseInt(getParam('group_id') || (groups[0] && groups[0].id) || 0, 10) || 0;
  var html='';
  html += '<form method="get" class="arsh-ug-filter" style="margin-bottom:12px;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">';
      html += '<input type="hidden" name="page" value="arshline-user-groups"/>'; 
      html += '<input type="hidden" name="tab" value="members"/>'; 
      html += '<label>'+esc(STR.group||'گروه')+': <select name="group_id" id="ugSel" >';
      (groups||[]).forEach(function(g){ html += '<option value="'+g.id+'"'+(g.id===gid?' selected':'')+'>'+esc(g.name)+'</option>'; });
      html += '</select></label> ';
  html += '<label>'+esc(STR.search||'جستجو')+': <input type="search" id="ugSearch" class="regular-text" placeholder="'+esc(STR.name||'نام')+'/'+esc(STR.phone||'شماره')+'" /></label>';
  html += '<label>'+esc('در هر صفحه')+': <select id="ugPerPage"><option value="10">10</option><option value="20">20</option><option value="50">50</option><option value="100">100</option></select></label>';
      html += '<button class="button">'+esc(STR.search||'برو')+'</button>';
      html += '</form>';

    var adminPostUrl = (ADMIN_POST && ADMIN_POST.replace('admin-ajax.php','admin-post.php')) || (function(){ try { return window.location.origin + '/wp-admin/admin-post.php'; } catch(_){ return '/wp-admin/admin-post.php'; } })();
    html += '<form method="post" action="'+esc(adminPostUrl)+'" enctype="multipart/form-data" style="margin:10px 0">';
      html += '<input type="hidden" name="action" value="arshline_import_members"/>';
      html += '<input type="hidden" name="group_id" value="'+gid+'"/>';
      (function(){ try {
        var h = (location.hash||'');
        var base = (location.origin || '') + (location.pathname || '/');
        var redir = base + '?arshline_dashboard=1' + h; // route back into panel
        html += '<input type="hidden" name="redirect_to" value="'+esc(redir)+'"/>';
      } catch(_){ } })();
    html += '<input type="hidden" name="_wpnonce" value="'+esc((NONCES&&NONCES.import)||'')+'"/>';
      html += '<label>'+esc(STR.import||'ایمپورت')+': <input type="file" name="csv" accept=".csv"/></label> ';
      html += '<button class="button button-primary">'+esc(STR.import||'ایمپورت')+'</button>';
      html += '</form>';

      html += '<div id="ugMembersList">'+esc(STR.loading||'در حال بارگذاری...')+'</div>';
  $m.html(html);
  if (PANEL) { $m.on('submit', 'form.arsh-ug-filter', function(e){ e.preventDefault(); }); }

      // Initialize search/per_page from hash
      try { var q0 = getParam('search'); if (q0) $('#ugSearch').val(q0); var pp0 = parseInt(getParam('per_page')||'0',10)||0; if (pp0) $('#ugPerPage').val(String(pp0)); else $('#ugPerPage').val('20'); } catch(_){ $('#ugPerPage').val('20'); }

      // لیست اعضا
      var __lastMembersMeta = null;
      function list(){ if (!gid){ $('#ugMembersList').html('<div class=\"notice notice-info\">'+esc(STR.select_group||'یک گروه را انتخاب کنید')+'</div>'); return; }
        var per = parseInt($('#ugPerPage').val()||'20',10)||20; var q = String($('#ugSearch').val()||'');
        var page = parseInt(getParam('page')||'1',10)||1; if (page<1) page=1;
        var url = 'user-groups/'+gid+'/members?per_page='+per+'&page='+page + (q?('&search='+encodeURIComponent(q)):'');
  api(url, { credentials:'same-origin' }).then(function(resp){
        var members = Array.isArray(resp) ? resp : (resp.items||[]);
        var t = '<div style="overflow:auto">';
        t += '<table class="widefat striped"><thead><tr><th>ID</th><th>'+esc(STR.name||'نام')+'</th><th>'+esc(STR.phone||'شماره همراه')+'</th><th></th></tr></thead><tbody>';
        (members||[]).forEach(function(mm){
          t += '<tr data-id="'+mm.id+'"><td>'+mm.id+'</td><td><input class="mName" type="text" value="'+esc(mm.name)+'"/></td><td><input class="mPhone" type="text" value="'+esc(mm.phone)+'"/></td><td>';
          t += '<button class="button mSave">'+esc(STR.save||'ذخیره')+'</button> ';
          t += '<button class="button button-link-delete mDel">'+esc(STR.delete||'حذف')+'</button>';
          t += '</td></tr>';
        });
        t += '</tbody></table>';
        // Pager
        if (!Array.isArray(resp)){
          var total = parseInt(resp.total||'0',10)||0; var cur = parseInt(resp.page||'1',10)||1; var perPage = parseInt(resp.per_page||per,10)||per; var totalPages = parseInt(resp.total_pages||'1',10)||1;
          __lastMembersMeta = { total: total, page: cur, per_page: perPage, total_pages: totalPages };
          t += '<div class="ar-pager" style="margin-top:10px;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">';
          t += '<button class="button ar-page-first" '+(cur<=1?'disabled':'')+'>« اول</button>';
          t += '<button class="button ar-page-prev" '+(cur<=1?'disabled':'')+'>‹ قبلی</button>';
          t += '<span style="opacity:.8">صفحه '+cur+' از '+totalPages+' (کل: '+total+')</span>';
          t += '<button class="button ar-page-next" '+(cur>=totalPages?'disabled':'')+'>بعدی ›</button>';
          t += '<button class="button ar-page-last" '+(cur>=totalPages?'disabled':'')+'>آخر »</button>';
          t += '</div>';
        }
        t += '</div>';
        $('#ugMembersList').html(t);
      }); }
      list();
      // Auto-switch in panel context (Members) when group select changes
      $m.on('change', '#ugSel', function(){
        try {
          var gidNew = parseInt(this.value,10)||0;
          var h = (location.hash||'').split('?')[0] || '#users/ug?tab=members';
          var qs = new URLSearchParams((location.hash||'').split('?')[1]||'');
          qs.set('group_id', String(gidNew));
          qs.delete('page'); // reset page on group change
          location.hash = h + '?' + qs.toString();
          // re-render
          gid = gidNew; list();
        } catch(_){ }
      });
      // Search and per-page change handlers
      $m.on('input', '#ugSearch', function(){
        try {
          var h = (location.hash||'').split('?')[0] || '#users/ug?tab=members';
          var qs = new URLSearchParams((location.hash||'').split('?')[1]||'');
          if (this.value) qs.set('search', this.value); else qs.delete('search');
          qs.delete('page');
          location.hash = h + '?' + qs.toString();
        } catch(_){ }
        list();
      });
      $m.on('change', '#ugPerPage', function(){
        try { var h = (location.hash||'').split('?')[0] || '#users/ug?tab=members'; var qs = new URLSearchParams((location.hash||'').split('?')[1]||''); var v = parseInt(this.value,10)||20; if (v) qs.set('per_page', String(v)); else qs.delete('per_page'); qs.delete('page'); location.hash = h + '?' + qs.toString(); } catch(_){ }
        list();
      });
      // Pager buttons
      $m.on('click', '.ar-page-first, .ar-page-prev, .ar-page-next, .ar-page-last', function(){
        var qs = new URLSearchParams((location.hash||'').split('?')[1]||'');
        var cur = parseInt(qs.get('page')||'1',10)||1; var totalPages = (__lastMembersMeta && __lastMembersMeta.total_pages) ? __lastMembersMeta.total_pages : 1;
        if ($(this).hasClass('ar-page-first')) cur = 1;
        else if ($(this).hasClass('ar-page-prev')) cur = Math.max(1, cur-1);
        else if ($(this).hasClass('ar-page-next')) cur = cur+1;
        else if ($(this).hasClass('ar-page-last')) cur = totalPages;
        if (cur<1) cur=1;
        qs.set('page', String(cur));
        var h = (location.hash||'').split('?')[0] || '#users/ug?tab=members';
        location.hash = h + '?' + qs.toString();
        list();
      });

      $m.on('click', '.mSave', function(){ if(!gid){ if (window.notify) notify('ابتدا گروه را انتخاب کنید', 'warn'); return; } var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.mName').val(); var phone=$tr.find('.mPhone').val();
        api('user-groups/'+gid+'/members/'+id, { credentials:'same-origin', method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, phone:phone }) })
          .then(function(){ if (window.notify) notify('ذخیره شد', 'success'); })
          .catch(function(){ if (window.notify) notify('ذخیره عضو ناموفق بود', 'error'); });
      });
      $m.on('click', '.mDel', function(){ if(!gid){ if (window.notify) notify('ابتدا گروه را انتخاب کنید', 'warn'); return; } if(!confirm(STR.confirm_delete||'حذف؟')) return; var $tr=$(this).closest('tr'); var id=+$tr.data('id'); api('user-groups/'+gid+'/members/'+id, { credentials:'same-origin', method:'DELETE' })
        .then(function(){ if (window.notify) notify('عضو حذف شد', 'success'); list(); })
        .catch(function(){ if (window.notify) notify('حذف عضو ناموفق بود', 'error'); }); });

      // خروجی لینک‌ها (CSV)
    var exportUrl = new URL(adminPostUrl, window.location.origin);
      exportUrl.searchParams.set('action', 'arshline_export_group_links');
      exportUrl.searchParams.set('group_id', String(gid));
      exportUrl.searchParams.set('form_id', String( (window.ARSHLINE_FORM_ID_FOR_LINKS||0) ));
  exportUrl.searchParams.set('_wpnonce', (NONCES.export||''));
      var $btn = $('<p><a class="button" href="'+exportUrl.toString()+'">'+esc(STR.export||'خروجی لینک‌ها')+'</a></p>');
      $m.append($btn);
    });
  }

  function renderMapping($m){
    // Load forms and groups, then allow selecting groups per form
    $m.html('<div>'+esc(STR.loading||'در حال بارگذاری...')+'</div>');
    Promise.all([
      api('user-groups', { credentials:'same-origin' }),
      fetch(FORMS_ENDPOINT, { headers:{'X-WP-Nonce': NONCE}, credentials:'same-origin' }).then(function(r){ return r.json(); })
    ]).then(function(results){
      var groups = results[0]||[];
      var forms = results[1]||[];
      var fid = parseInt(getParam('form_id') || (forms[0] && forms[0].id) || 0, 10) || 0;
  var html = '';
  html += '<form method="get" class="arsh-ug-filter" style="margin-bottom:8px">';
      html += '<input type="hidden" name="page" value="arshline-user-groups"/>';
      html += '<input type="hidden" name="tab" value="mapping"/>';
      html += '<label>'+esc(STR.form||'فرم')+': <select name="form_id" id="ugFormSel">';
      (forms||[]).forEach(function(f){ var title = (f && f.title) || ('#'+f.id); html += '<option value="'+f.id+'"'+(f.id===fid?' selected':'')+'>'+esc(title)+'</option>'; });
      html += '</select></label> ';
      html += '<button class="button">'+esc(STR.search||'برو')+'</button>';
      html += '</form>';

      html += '<div id="ugMapBox">'+esc(STR.loading||'در حال بارگذاری...')+'</div>';
  $m.html(html);
  if (PANEL) { $m.on('submit', 'form.arsh-ug-filter', function(e){ e.preventDefault(); }); }

      function loadMap(){
  api('forms/'+fid+'/access/groups', { credentials:'same-origin' }).then(function(resp){
          var selected = (resp && resp.group_ids) || [];
          var t = '<div>';
          t += '<ul style="list-style:none;padding:0">';
          (groups||[]).forEach(function(g){
            var chk = selected.indexOf(g.id) >= 0 ? ' checked' : '';
            t += '<li><label><input type="checkbox" class="mapG" value="'+g.id+'"'+chk+'/> '+esc(g.name)+'</label></li>';
          });
          t += '</ul>';
          t += '<button id="ugSaveMap" class="button button-primary">'+esc(STR.save_mapping||'ذخیره اتصال')+'</button>';
          t += '</div>';
          $('#ugMapBox').html(t);
        });
      }
      loadMap();
      // Auto-switch in panel context for mapping form selector
      $m.on('change', '#ugFormSel', function(){
        try {
          var fidNew = parseInt(this.value,10)||0;
          var h = (location.hash||'').split('?')[0] || '#users/ug?tab=mapping';
          var qs = new URLSearchParams((location.hash||'').split('?')[1]||'');
          qs.set('form_id', String(fidNew));
          location.hash = h + '?' + qs.toString();
          fid = fidNew; loadMap();
        } catch(_){ }
      });

      $m.on('click', '#ugSaveMap', function(){
        var ids = []; $('#ugMapBox .mapG:checked').each(function(){ ids.push(parseInt(this.value,10)); });
        api('forms/'+fid+'/access/groups', { credentials:'same-origin', method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ group_ids: ids }) })
          .then(function(){ if (window.notify) notify('اتصال فرم/گروه ذخیره شد', 'success'); })
          .catch(function(){ if (window.notify) notify('ذخیره اتصال ناموفق بود', 'error'); });
      });
    });
  }

  // اکسپورت ورودی واحد برای رندر در داشبورد سفارشی
  function renderTab(tab){
    var $mount = $('#arUGMount'); if(!$mount.length) return;
    if (tab === 'groups') renderGroups($mount);
    else if (tab === 'members') renderMembers($mount);
    else if (tab === 'mapping') renderMapping($mount);
    else renderCustomFields($mount);
  }
  try { window.ARSH_UG_render = renderTab; } catch(_){ }

  $(function(){
    try { window.__ARSH_UG_READY__ = true; } catch(_){ }
    var $mount = $('#arUGMount'); if(!$mount.length) return;
    var initial = ($('#arshline-ug-app').data('tab')|| getParam('tab') || 'groups');
    renderTab(initial);
  });
})(jQuery);

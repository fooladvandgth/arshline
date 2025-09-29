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
    // Robust URL joiner that supports both /wp-json/... and index.php?rest_route=... bases
    opts = opts || {}; opts.headers = opts.headers || {};
    opts.headers['X-WP-Nonce'] = NONCE;
    var url = '';
    try {
      var base = String(REST || '');
      // Build using URL to correctly merge search params
      var u = new URL(base, window.location.origin);
      var parts = String(path || '').split('?');
      var pth = parts[0] || '';
      var q = parts[1] || '';
      if (u.searchParams && u.searchParams.has('rest_route')){
        // index.php?rest_route=/arshline/v1/...
        var rr = String(u.searchParams.get('rest_route') || '');
        rr = rr.replace(/\/+$/, '');
        pth = String(pth || '').replace(/^\/+/, '');
        var joined = ('/' + rr + '/' + pth).replace(/\/{2,}/g, '/');
        u.searchParams.set('rest_route', joined);
        if (q){
          var qs = new URLSearchParams(q);
          qs.forEach(function(v,k){ u.searchParams.append(k, v); });
        }
      } else {
        // /wp-json/arshline/v1/...
        var cleanBasePath = (u.pathname || '').replace(/\/+$/, '');
        pth = String(pth || '').replace(/^\/+/, '');
        u.pathname = (cleanBasePath + '/' + pth).replace(/\/{2,}/g, '/');
        if (q){
          var qs2 = new URLSearchParams(q);
          qs2.forEach(function(v,k){ u.searchParams.append(k, v); });
        }
      }
      url = u.toString();
    } catch(_){
      // Fallback: naive concatenation
      url = String(REST || '') + String(path || '');
    }
    return fetch(url, opts).then(function(r){ if(!r.ok) throw r; return r.json(); });
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
  // parent select for add
  html += '      <label>'+esc(STR.parent||'مادر')+': <select id="ugNewParent"><option value="">—</option>';
  (groups||[]).forEach(function(gg){ html += '<option value="'+gg.id+'">'+esc(gg.name)+'</option>'; });
  html += '</select></label>';
  html += '      <button id="ugAddConfirm" type="button" class="button button-primary">'+esc(STR.add||'افزودن')+'</button>';
  html += '      <button id="ugAddCancel" type="button" class="button">'+esc(STR.cancel||'انصراف')+'</button>';
        html += '    </div>';
        html += '  </div>';
        // Table
        html += '  <div class="table-wrap">';
        html += '    <table class="widefat striped" style="width:100%"><thead><tr><th>ID</th><th>'+esc(STR.name||'نام')+'</th><th>'+esc(STR.parent||'مادر')+'</th><th>تعداد اعضا</th><th></th></tr></thead><tbody>';
        (groups||[]).forEach(function(g){
          html += '<tr data-id="'+g.id+'">';
          html += '<td>'+g.id+'</td>';
          html += '<td><span class="ugNameText">'+esc(g.name)+'</span></td>';
          var parentName = '';
          if (g.parent_id){ var pg = (groups||[]).find(function(x){ return x.id===g.parent_id; }); parentName = pg ? pg.name : ('#'+g.parent_id); }
          html += '<td><span class="ugParentText">'+esc(parentName||'—')+'</span></td>';
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
          var pidStr = $('#ugNewParent').val(); var pid = pidStr?parseInt(pidStr,10):null;
          api('user-groups', { credentials:'same-origin', method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name: name, parent_id: pid }) })
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
          // parent select
          var $parentCell = $tr.find('td').eq(2);
          var curPid = null; try { var txt = $parentCell.find('.ugParentText').text(); var found = (groups||[]).find(function(x){ return x.name===txt; }); curPid = found ? found.id : null; } catch(_){ curPid = null; }
          var sel = '<select class="ugParent"><option value="">—</option>';
          (groups||[]).forEach(function(gg){ if (gg.id===id) return; sel += '<option value="'+gg.id+'"'+(gg.id===curPid?' selected':'')+'>'+esc(gg.name)+'</option>'; });
          sel += '</select>';
          $parentCell.data('orig', $parentCell.html()); $parentCell.html(sel);
          var gid = id;
          var $btnCell = $tr.find('td').eq(3);
          // shift because of added parent column: actions are now at index 4
          $btnCell = $tr.find('td').eq(4);
          $btnCell.html('<button class="button ugSave">'+esc(STR.save||'ذخیره')+'</button> <button class="button ugCancel">'+esc(STR.cancel||'انصراف')+'</button> <a class="button" href="#users/ug?tab=custom_fields&group_id='+gid+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>');
          try { $tr.find('.ugName').focus(); } catch(_){ }
        });
        // Cancel edit
  $m.on('click', '.ugCancel', function(){ var $tr=$(this).closest('tr'); var $nameCell = $tr.find('td').eq(1); var orig = $nameCell.data('orig')||$nameCell.text(); $nameCell.html('<span class="ugNameText">'+esc(orig)+'</span>'); var $parentCell=$tr.find('td').eq(2); var porig = $parentCell.data('orig'); if (porig){ $parentCell.html(porig); } var gid=+$tr.data('id'); var $btnCell=$tr.find('td').eq(4); $btnCell.html('<button class="button ugEdit">'+esc(STR.edit||'ویرایش')+'</button> <button class="button button-link-delete ugDel">'+esc(STR.delete||'حذف')+'</button> <a class="button" href="#users/ug?tab=custom_fields&group_id='+gid+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>'); });
        // Save edit
        function finishRowView($tr, newName){ var $nameCell = $tr.find('td').eq(1); $nameCell.html('<span class="ugNameText">'+esc(newName)+'</span>'); var gid=+$tr.data('id'); var $btnCell=$tr.find('td').eq(3); $btnCell.html('<button class="button ugEdit">'+esc(STR.edit||'ویرایش')+'</button> <button class="button button-link-delete ugDel">'+esc(STR.delete||'حذف')+'</button> <a class="button" href="#users/ug?tab=custom_fields&group_id='+gid+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>'); }
        $m.on('keydown', 'input.ugName', function(e){ if (e.key==='Enter'){ e.preventDefault(); $(this).closest('tr').find('.ugSave').trigger('click'); } });
        $m.on('click', '.ugSave', function(){ var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.ugName').val(); var pidStr = $tr.find('.ugParent').val(); var pid = pidStr?parseInt(pidStr,10):null; if (pid===id){ if (window.notify) notify('گروه نمی‌تواند مادر خودش باشد', 'warn'); return; } api('user-groups/'+id, { credentials:'same-origin', method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, parent_id: pid }) })
          .then(function(){ if (window.notify) notify('ذخیره شد', 'success'); finishRowView($tr, name); renderGroups($m); })
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
        $('#ugFieldsBox').html('⏳ '+esc(STR.loading||'در حال بارگذاری...'));
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
      }).catch(function(err){ var msg = 'خطا در بارگذاری فیلدها'; if (err && err.status) msg += ' ('+err.status+')'; $('#ugFieldsBox').html('<div class="notice notice-error">'+esc(msg)+'</div>'); try { if (window.notify) notify('خطا در بارگذاری فیلدها', 'error'); } catch(_){ } }); }
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
    }).catch(function(err){ var msg = 'خطا در بارگذاری گروه‌ها'; if (err && err.status) msg += ' ('+err.status+')'; $m.html('<div class="notice notice-error">'+esc(msg)+'</div>'); try { if (window.notify) notify('خطا در بارگذاری گروه‌ها', 'error'); } catch(_){ } });
  }

  function renderMembers($m){
    // فیلتر بر اساس گروه + لیست اعضا + ایمپورت CSV
    api('user-groups', { credentials:'same-origin' })
    .then(function(groups){
      var gid = parseInt(getParam('group_id') || (groups[0] && groups[0].id) || 0, 10) || 0;
      var html='';
      // Card container + toolbar (align with Groups)
      html += '<div class="card glass" style="padding:1rem;">';
      html += '  <div class="ar-ug-toolbar" style="display:flex;align-items:center;gap:.6rem;margin-bottom:.6rem">';
      html += '    <span class="title">'+esc(STR.members||'اعضا')+'</span>';
      html += '    <span style="flex:1 1 auto"></span>';
      html += '    <button id="mAddToggle" type="button" class="button button-primary">'+esc(STR.add||'افزودن')+'</button>';
      html += '  </div>';
      // Filters row
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

      // Add box (hidden by default)
      html += '  <div id="mAddBox" style="display:none;margin-bottom:.6rem;padding:.5rem;border:1px dashed var(--border, #d1d5db);border-radius:.5rem;background:var(--surface, #fff)">';
      html += '    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">';
      html += '      <input id="mNewName" class="regular-text" placeholder="'+esc(STR.name||'نام')+'"/>';
      html += '      <input id="mNewPhone" class="regular-text" placeholder="'+esc(STR.phone||'شماره همراه')+'"/>';
      html += '      <button id="mAddConfirm" type="button" class="button button-primary">'+esc(STR.add||'افزودن')+'</button>';
      html += '      <button id="mAddCancel" type="button" class="button">'+esc(STR.cancel||'انصراف')+'</button>';
      html += '    </div>';
      html += '  </div>';

      var adminPostUrl = (ADMIN_POST && ADMIN_POST.replace('admin-ajax.php','admin-post.php')) || (function(){ try { return window.location.origin + '/wp-admin/admin-post.php'; } catch(_){ return '/wp-admin/admin-post.php'; } })();
      // Prefer https when site is https or localhost to avoid mixed-content warnings
      try {
        var u0 = new URL(adminPostUrl, window.location.origin);
        if ((window.location.protocol === 'https:' && u0.protocol !== 'https:') || /^(localhost|127\.0\.0\.1)$/i.test(u0.hostname)){
          u0.protocol = 'https:';
          adminPostUrl = u0.toString();
        }
      } catch(_){ }
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

      html += '<div id="ugMembersList">'+'⏳ '+esc(STR.loading||'در حال بارگذاری...')+'</div>';
      html += '</div>'; // /card.glass
      $m.html(html);
      if (PANEL) { $m.on('submit', 'form.arsh-ug-filter', function(e){ e.preventDefault(); }); }

      // Initialize search/per_page from hash
      try { var q0 = getParam('search'); if (q0) $('#ugSearch').val(q0); var pp0 = parseInt(getParam('per_page')||'0',10)||0; if (pp0) $('#ugPerPage').val(String(pp0)); else $('#ugPerPage').val('20'); } catch(_){ $('#ugPerPage').val('20'); }

      // لیست اعضا
      var __lastMembersMeta = null;
      var __groupFields = [];
      function loadFieldsAndList(){
        if (!gid){ $('#ugMembersList').html('<div class="notice notice-info">'+esc(STR.select_group||'یک گروه را انتخاب کنید')+'</div>'); return; }
        // Fetch fields first, then list members so we can render dynamic columns
        $('#ugMembersList').html('⏳ '+esc(STR.loading||'در حال بارگذاری...'));
        api('user-groups/'+gid+'/fields', { credentials:'same-origin' })
          .then(function(fields){ __groupFields = Array.isArray(fields) ? fields : []; list(); })
          .catch(function(err){ __groupFields = []; var msg='خطا در بارگذاری فیلدها'; if (err&&err.status) msg+=' ('+err.status+')'; $('#ugMembersList').html('<div class="notice notice-error">'+esc(msg)+'</div>'); try{ if(window.notify) notify('خطا در بارگذاری فیلدها','error'); }catch(_){ } });
      }
      function list(){ if (!gid){ $('#ugMembersList').html('<div class="notice notice-info">'+esc(STR.select_group||'یک گروه را انتخاب کنید')+'</div>'); return; }
        $('#ugMembersList').html('⏳ '+esc(STR.loading||'در حال بارگذاری...'));
        var per = parseInt($('#ugPerPage').val()||'20',10)||20; var q = String($('#ugSearch').val()||'');
        var page = parseInt(getParam('page')||'1',10)||1; if (page<1) page=1;
        var url = 'user-groups/'+gid+'/members?per_page='+per+'&page='+page + (q?('&search='+encodeURIComponent(q)):'');
        api(url, { credentials:'same-origin' }).then(function(resp){
          var members = Array.isArray(resp) ? resp : (resp.items||[]);
          var t = '<div class="table-wrap" style="overflow:auto">';
          t += '<table class="widefat striped" style="width:100%"><thead><tr><th>ID</th><th>'+esc(STR.name||'نام')+'</th><th>'+esc(STR.phone||'شماره همراه')+'</th>';
          // Dynamic custom fields headers
          (__groupFields||[]).forEach(function(f){ var lbl = (f&&f.label)?f.label:f.name; t += '<th>'+esc(lbl)+'</th>'; });
          t += '<th></th></tr></thead><tbody>';
          if (!members || members.length === 0){
            var colSpan = 4 + (__groupFields?__groupFields.length:0);
            t += '<tr><td colspan="'+colSpan+'" style="text-align:center;opacity:.8">'+esc('هیچ عضوی برای این گروه ثبت نشده است')+'</td></tr>';
          } else {
            (members||[]).forEach(function(mm){
              var data = (mm&&mm.data)?mm.data:{};
              t += '<tr data-id="'+mm.id+'">'+
                   '<td>'+mm.id+'</td>'+
                   '<td><span class="mNameText">'+esc(mm.name)+'</span></td>'+
                   '<td><span class="mPhoneText">'+esc(mm.phone)+'</span></td>';
              (__groupFields||[]).forEach(function(f){ var key=f.name; var val = (data && data[key]!=null)? String(data[key]) : ''; t += '<td><span class="mFieldText" data-name="'+esc(key)+'">'+esc(val)+'</span></td>'; });
              t += '<td><button class="button mEdit">'+esc(STR.edit||'ویرایش')+'</button> <button class="button button-link-delete mDel">'+esc(STR.delete||'حذف')+'</button></td>'+
                   '</tr>';
            });
          }
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
        }).catch(function(err){
          try { if (window.notify) notify('خطا در بارگذاری اعضا', 'error'); } catch(_){ }
          var msg = 'خطا در بارگذاری لیست اعضا';
          if (err && err.status) { msg += ' ('+err.status+')'; }
          $('#ugMembersList').html('<div class="notice notice-error">'+esc(msg)+'</div>');
        });
      }
      loadFieldsAndList();
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
          gid = gidNew; // update hidden group_id in import form and sample/export links
          try { $('form[action*="arshline_import_members"] input[name="group_id"]').val(String(gid)); } catch(_){ }
          try {
            var adminPostUrl2 = (ADMIN_POST && ADMIN_POST.replace('admin-ajax.php','admin-post.php')) || (function(){ try { return window.location.origin + '/wp-admin/admin-post.php'; } catch(_){ return '/wp-admin/admin-post.php'; } })();
            var tplUrl = new URL(adminPostUrl2, window.location.origin);
            tplUrl.searchParams.set('action','arshline_download_members_template');
            tplUrl.searchParams.set('group_id', String(gid));
            tplUrl.searchParams.set('_wpnonce', (NONCES&&NONCES.template)||'');
            $('#mSampleTpl').attr('href', tplUrl.toString());
            var expUrl = new URL(adminPostUrl2, window.location.origin);
            expUrl.searchParams.set('action','arshline_export_group_links');
            expUrl.searchParams.set('group_id', String(gid));
            expUrl.searchParams.set('form_id', String( (window.ARSHLINE_FORM_ID_FOR_LINKS||0) ));
            expUrl.searchParams.set('_wpnonce', (NONCES&&NONCES.export)||'');
            $('#ugExportLinks').attr('href', expUrl.toString());
          } catch(_){ }
          loadFieldsAndList();
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

      // Row edit workflow
      $m.on('click', '.mEdit', function(){
        var $tr=$(this).closest('tr');
        var id=+$tr.data('id');
        var $nameCell = $tr.find('td').eq(1);
        var $phoneCell = $tr.find('td').eq(2);
        var name = $nameCell.find('.mNameText').text();
        var phone = $phoneCell.find('.mPhoneText').text();
        $nameCell.data('orig', name).html('<input class="mName" type="text" value="'+esc(name)+'"/>' );
        $phoneCell.data('orig', phone).html('<input class="mPhone" type="text" value="'+esc(phone)+'"/>' );
        // turn custom fields into inputs
        $tr.find('.mFieldText').each(function(){ var key = $(this).data('name'); var val = $(this).text(); var $td=$(this).closest('td'); $td.data('orig', val).html('<input class="mField" data-name="'+esc(key)+'" type="text" value="'+esc(val)+'"/>'); });
        var $btnCell = $tr.find('td').eq(3);
        // Actions cell is the last one now (after custom fields)
        $btnCell = $tr.find('td').last();
        $btnCell.html('<button class="button mSave">'+esc(STR.save||'ذخیره')+'</button> <button class="button mCancel">'+esc(STR.cancel||'انصراف')+'</button>');
        try { $tr.find('.mName').focus(); } catch(_){ }
      });
      $m.on('keydown', 'input.mName, input.mPhone', function(e){ if (e.key==='Enter'){ e.preventDefault(); $(this).closest('tr').find('.mSave').trigger('click'); } });
      $m.on('click', '.mCancel', function(){
        var $tr=$(this).closest('tr');
        var $nameCell = $tr.find('td').eq(1);
        var $phoneCell = $tr.find('td').eq(2);
        var origN = $nameCell.data('orig')||$nameCell.text();
        var origP = $phoneCell.data('orig')||$phoneCell.text();
        $nameCell.html('<span class="mNameText">'+esc(origN)+'</span>');
        $phoneCell.html('<span class="mPhoneText">'+esc(origP)+'</span>');
        // restore all custom fields
        $tr.find('td').each(function(){ var $inp = $(this).find('input.mField'); if ($inp.length){ var key = $inp.data('name'); var orig = $(this).data('orig')||''; $(this).html('<span class="mFieldText" data-name="'+esc(key)+'">'+esc(orig)+'</span>'); } });
        // Restore actions into last cell
        $tr.find('td').last().html('<button class="button mEdit">'+esc(STR.edit||'ویرایش')+'</button> <button class="button button-link-delete mDel">'+esc(STR.delete||'حذف')+'</button>');
      });
      $m.on('click', '.mSave', function(){ if(!gid){ if (window.notify) notify('ابتدا گروه را انتخاب کنید', 'warn'); return; } var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.mName').val(); var phone=$tr.find('.mPhone').val(); var data={}; $tr.find('input.mField').each(function(){ var k=$(this).data('name'); data[k]=String($(this).val()||''); });
        api('user-groups/'+gid+'/members/'+id, { credentials:'same-origin', method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, phone:phone, data:data }) })
          .then(function(){ if (window.notify) notify('ذخیره شد', 'success'); list(); })
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
  try { if (window.location.protocol === 'https:' && exportUrl.protocol !== 'https:') exportUrl.protocol = 'https:'; } catch(_){ }
  var tplUrl = new URL(adminPostUrl, window.location.origin);
  tplUrl.searchParams.set('action', 'arshline_download_members_template');
  tplUrl.searchParams.set('group_id', String(gid));
  tplUrl.searchParams.set('_wpnonce', (NONCES.template||''));
  // Hide the export links entry in Members section as requested
  var $btns = $('<p style="margin-top:.6rem"><a id="mSampleTpl" class="button" href="'+tplUrl.toString()+'">'+esc('دانلود فایل نمونه CSV')+'</a></p>');
  $('#ugMembersList').after($btns);

      // Add toggle and confirm/cancel
      $m.off('click', '#mAddToggle').on('click', '#mAddToggle', function(e){ e.preventDefault(); var $box=$('#mAddBox'); var willShow = $box.is(':hidden'); try { $box.stop(true,true).slideToggle(150, function(){ if (willShow){ try{ $('#mNewName').focus(); }catch(_){ } } }); } catch(_){ $box.toggle(); if (willShow){ try{ $('#mNewName').focus(); }catch(__){} } } });
      $m.off('click', '#mAddConfirm').on('click', '#mAddConfirm', function(){ var name=$('#mNewName').val().trim(); var phone=$('#mNewPhone').val().trim(); if(!name||!phone){ if(window.notify) notify('نام و شماره لازم است', 'warn'); return; } var payload={ members:[{ name:name, phone:phone }]}; api('user-groups/'+gid+'/members', { credentials:'same-origin', method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
        .then(function(){ if(window.notify) notify('عضو افزوده شد', 'success'); $('#mNewName').val(''); $('#mNewPhone').val(''); try{ $('#mAddBox').slideUp(120);}catch(_){ $('#mAddBox').hide(); } list(); })
        .catch(function(){ if(window.notify) notify('افزودن عضو ناموفق بود', 'error'); }); });
      $m.off('click', '#mAddCancel').on('click', '#mAddCancel', function(){ $('#mNewName').val(''); $('#mNewPhone').val(''); try{ $('#mAddBox').slideUp(120);}catch(_){ $('#mAddBox').hide(); } });
    }).catch(function(err){
      var msg = 'خطا در بارگذاری لیست گروه‌ها';
      if (err && err.status) msg += ' ('+err.status+')';
      $m.html('<div class="notice notice-error">'+esc(msg)+'</div>');
      try { if (window.notify) notify('خطا در بارگذاری گروه‌ها', 'error'); } catch(_){ }
    });
  }

  function renderMapping($m){
    // Load forms and groups, then allow selecting groups per form
    $m.html('<div>'+'⏳ '+esc(STR.loading||'در حال بارگذاری...')+'</div>');
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
  $('#ugMapBox').html('⏳ '+esc(STR.loading||'در حال بارگذاری...'));
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
          // Export member links for this form across all connected groups
          try {
            var adminPostUrl = (ADMIN_POST && ADMIN_POST.replace('admin-ajax.php','admin-post.php')) || (function(){ try { return window.location.origin + '/wp-admin/admin-post.php'; } catch(_){ return '/wp-admin/admin-post.php'; } })();
            var exp = new URL(adminPostUrl, window.location.origin);
            exp.searchParams.set('action','arshline_export_group_links');
            exp.searchParams.set('form_id', String(fid));
            exp.searchParams.set('_wpnonce', (NONCES&&NONCES.export)||'');
            try { if (window.location.protocol === 'https:' && exp.protocol !== 'https:') exp.protocol = 'https:'; } catch(_){ }
            var disabled = (!Array.isArray(selected) || selected.length===0) ? ' aria-disabled="true" style="pointer-events:none;opacity:.6"' : '';
            t += '<a id="ugMapExportLinks" class="button" target="_blank" href="'+exp.toString()+'"'+disabled+'>خروجی لینک‌های اعضا برای این فرم</a>';
          } catch(_){ }
          t += '</div>';
          $('#ugMapBox').html(t);
        }).catch(function(err){ var msg='خطا در بارگذاری اتصال فرم/گروه'; if (err&&err.status) msg += ' ('+err.status+')'; $('#ugMapBox').html('<div class="notice notice-error">'+esc(msg)+'</div>'); try { if (window.notify) notify('خطا در بارگذاری اتصال', 'error'); } catch(_){ } });
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
          .then(function(){ if (window.notify) notify('اتصال فرم/گروه ذخیره شد', 'success'); try { var a = document.getElementById('ugMapExportLinks'); if (a){ if (ids.length===0){ a.setAttribute('aria-disabled','true'); a.style.pointerEvents='none'; a.style.opacity='0.6'; } else { a.removeAttribute('aria-disabled'); a.style.pointerEvents=''; a.style.opacity=''; } } } catch(_){ } })
          .catch(function(){ if (window.notify) notify('ذخیره اتصال ناموفق بود', 'error'); });
      });
    }).catch(function(err){ var msg='خطا در بارگذاری داده‌ها'; if (err && err.status) msg += ' ('+err.status+')'; $m.html('<div class="notice notice-error">'+esc(msg)+'</div>'); try { if (window.notify) notify('خطا در بارگذاری داده‌ها', 'error'); } catch(_){ } });
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

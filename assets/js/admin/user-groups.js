(function($){
  'use strict';
  // اسکریپت مدیریت CRUD گروه‌ها و اعضا + اتصال فرم‌ها به گروه‌ها
  var REST = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.rest) || '';
  var NONCE = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.nonce) || '';
  var STR = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.strings) || {};
  var NONCES = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.nonces) || {};
  var ADMIN_POST = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.adminPostUrl) || '';
  var FORMS_ENDPOINT = (window.ARSHLINE_ADMIN && ARSHLINE_ADMIN.formsEndpoint) || '';

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
        html += '<div style="display:flex;gap:.5rem;align-items:center;margin-bottom:8px">';
        html += '<input id="ugNewName" class="regular-text" placeholder="'+esc(STR.name||'نام')+'"/>';
        html += '<button id="ugAdd" class="button button-primary">'+esc(STR.add||'افزودن')+'</button>';
        html += '</div>';
        html += '<table class="widefat striped"><thead><tr><th>ID</th><th>'+esc(STR.name||'نام')+'</th><th>تعداد اعضا</th><th></th></tr></thead><tbody>';
        (groups||[]).forEach(function(g){
          html += '<tr data-id="'+g.id+'">';
          html += '<td>'+g.id+'</td>';
          html += '<td><input class="ugName" type="text" value="'+esc(g.name)+'"/></td>';
          html += '<td>'+(g.member_count||0)+'</td>';
          html += '<td>';
          html += '<button class="button ugSave">'+esc(STR.save||'ذخیره')+'</button> ';
          html += '<button class="button button-link-delete ugDel">'+esc(STR.delete||'حذف')+'</button> ';
          html += '<a class="button" href="#users/ug-fields?group_id='+g.id+'">'+esc(STR.custom_fields||'فیلدهای سفارشی')+'</a>';
          html += '</td>';
          html += '</tr>';
        });
        html += '</tbody></table>';
        $m.html(html);
        $('#ugAdd').on('click', function(){ var name = $('#ugNewName').val().trim(); if(!name) return; api('user-groups', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name: name }) }).then(function(){ renderGroups($m); }); });
        $m.on('click', '.ugSave', function(){ var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.ugName').val(); api('user-groups/'+id, { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name }) }).then(function(){ /* noop */ }); });
        $m.on('click', '.ugDel', function(){ if(!confirm(STR.confirm_delete||'حذف؟')) return; var $tr=$(this).closest('tr'); var id=+$tr.data('id'); api('user-groups/'+id, { method:'DELETE' }).then(function(){ renderGroups($m); }); });
      });
  }
  function renderCustomFields($m){
    api('user-groups', { credentials:'same-origin' }).then(function(groups){
      var gid = parseInt(new URLSearchParams(location.search).get('group_id')|| (groups[0] && groups[0].id) || 0, 10) || 0;
      var html='';
      html += '<form method="get" style="margin-bottom:8px">';
      html += '<input type="hidden" name="page" value="arshline-user-groups"/>';
      html += '<input type="hidden" name="tab" value="custom_fields"/>';
      html += '<label>'+esc(STR.group||'گروه')+': <select name="group_id" id="ugSelCF">';
      (groups||[]).forEach(function(g){ html += '<option value="'+g.id+'"'+(g.id===gid?' selected':'')+'>'+esc(g.name)+'</option>'; });
      html += '</select></label> ';
      html += '<button class="button">'+esc(STR.search||'برو')+'</button>';
      html += '</form>';

      html += '<div id="ugFieldsBox">'+esc(STR.loading||'در حال بارگذاری...')+'</div>';
      $m.html(html);

      function list(){ api('user-groups/'+gid+'/fields', { credentials:'same-origin' }).then(function(fields){
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

      $m.on('click', '#ugFAdd', function(e){ e.preventDefault(); var name=$('#ugFName').val().trim(); var label=$('#ugFLabel').val().trim()||name; var type=$('#ugFType').val(); var req=$('#ugFReq').is(':checked'); if(!name) return; api('user-groups/'+gid+'/fields', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, label:label, type:type, required:req, sort:0 }) }).then(function(){ $('#ugFName').val(''); $('#ugFLabel').val(''); $('#ugFReq').prop('checked', false); list(); }); });
      $m.on('click', '.cfSave', function(e){ e.preventDefault(); var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.cfName').val(); var label=$tr.find('.cfLabel').val(); var type=$tr.find('.cfType').val(); var req=$tr.find('.cfReq').is(':checked'); var sort=parseInt($tr.find('.cfSort').val()||'0',10)||0; api('user-groups/'+gid+'/fields/'+id, { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, label:label, type:type, required:req, sort:sort }) }).then(function(){ /* saved */ }); });
      $m.on('click', '.cfDel', function(e){ e.preventDefault(); if(!confirm(STR.confirm_delete||'حذف؟')) return; var $tr=$(this).closest('tr'); var id=+$tr.data('id'); api('user-groups/'+gid+'/fields/'+id, { method:'DELETE' }).then(function(){ list(); }); });
    });
  }

  function renderMembers($m){
    // فیلتر بر اساس گروه + لیست اعضا + ایمپورت CSV
    api('user-groups', { credentials:'same-origin' }).then(function(groups){
      var gid = parseInt(new URLSearchParams(location.search).get('group_id')|| (groups[0] && groups[0].id) || 0, 10) || 0;
      var html='';
      html += '<form method="get" style="margin-bottom:8px">';
      html += '<input type="hidden" name="page" value="arshline-user-groups"/>'; 
      html += '<input type="hidden" name="tab" value="members"/>'; 
      html += '<label>'+esc(STR.group||'گروه')+': <select name="group_id" id="ugSel" >';
      (groups||[]).forEach(function(g){ html += '<option value="'+g.id+'"'+(g.id===gid?' selected':'')+'>'+esc(g.name)+'</option>'; });
      html += '</select></label> ';
      html += '<button class="button">'+esc(STR.search||'برو')+'</button>';
      html += '</form>';

  html += '<form method="post" action="'+esc(ADMIN_POST||ajaxurl).replace('admin-ajax.php','admin-post.php')+'" enctype="multipart/form-data" style="margin:10px 0">';
      html += '<input type="hidden" name="action" value="arshline_import_members"/>';
      html += '<input type="hidden" name="group_id" value="'+gid+'"/>';
  html += '<input type="hidden" name="_wpnonce" value="'+esc(NONCES.import||'')+'"/>';
      html += '<label>'+esc(STR.import||'ایمپورت')+': <input type="file" name="csv" accept=".csv"/></label> ';
      html += '<button class="button button-primary">'+esc(STR.import||'ایمپورت')+'</button>';
      html += '</form>';

      html += '<div id="ugMembersList">'+esc(STR.loading||'در حال بارگذاری...')+'</div>';
      $m.html(html);

      // لیست اعضا
      function list(){ api('user-groups/'+gid+'/members', { credentials:'same-origin' }).then(function(members){
        var t = '<table class="widefat striped"><thead><tr><th>ID</th><th>'+esc(STR.name||'نام')+'</th><th>'+esc(STR.phone||'شماره همراه')+'</th><th></th></tr></thead><tbody>';
        (members||[]).forEach(function(mm){
          t += '<tr data-id="'+mm.id+'"><td>'+mm.id+'</td><td><input class="mName" type="text" value="'+esc(mm.name)+'"/></td><td><input class="mPhone" type="text" value="'+esc(mm.phone)+'"/></td><td>';
          t += '<button class="button mSave">'+esc(STR.save||'ذخیره')+'</button> ';
          t += '<button class="button button-link-delete mDel">'+esc(STR.delete||'حذف')+'</button>';
          t += '</td></tr>';
        });
        t += '</tbody></table>';
        $('#ugMembersList').html(t);
      }); }
      list();

      $m.on('click', '.mSave', function(){ var $tr=$(this).closest('tr'); var id=+$tr.data('id'); var name=$tr.find('.mName').val(); var phone=$tr.find('.mPhone').val();
        api('user-groups/'+gid+'/members/'+id, { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ name:name, phone:phone }) })
          .then(function(){ /* saved */ })
          .catch(function(){ alert('اشکال در ذخیره عضو'); });
      });
      $m.on('click', '.mDel', function(){ if(!confirm(STR.confirm_delete||'حذف؟')) return; var $tr=$(this).closest('tr'); var id=+$tr.data('id'); api('user-groups/'+gid+'/members/'+id, { method:'DELETE' }).then(function(){ list(); }); });

      // خروجی لینک‌ها (CSV)
  var exportUrl = new URL((ADMIN_POST|| (window.location.origin + '/wp-admin/admin-post.php')));
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
      var fid = parseInt(new URLSearchParams(location.search).get('form_id')|| (forms[0] && forms[0].id) || 0, 10) || 0;
      var html = '';
      html += '<form method="get" style="margin-bottom:8px">';
      html += '<input type="hidden" name="page" value="arshline-user-groups"/>';
      html += '<input type="hidden" name="tab" value="mapping"/>';
      html += '<label>'+esc(STR.form||'فرم')+': <select name="form_id" id="ugFormSel">';
      (forms||[]).forEach(function(f){ var title = (f && f.title) || ('#'+f.id); html += '<option value="'+f.id+'"'+(f.id===fid?' selected':'')+'>'+esc(title)+'</option>'; });
      html += '</select></label> ';
      html += '<button class="button">'+esc(STR.search||'برو')+'</button>';
      html += '</form>';

      html += '<div id="ugMapBox">'+esc(STR.loading||'در حال بارگذاری...')+'</div>';
      $m.html(html);

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

      $m.on('click', '#ugSaveMap', function(){
        var ids = []; $('#ugMapBox .mapG:checked').each(function(){ ids.push(parseInt(this.value,10)); });
        api('forms/'+fid+'/access/groups', { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ group_ids: ids }) })
          .then(function(){ /* saved */ })
          .catch(function(){ alert('اشکال در ذخیره اتصال'); });
      });
    });
  }

  $(function(){
    var $mount = $('#arUGMount'); if(!$mount.length) return;
    var tab = ($('#arshline-ug-app').data('tab')||'groups');
    if (tab === 'groups') renderGroups($mount);
    else if (tab === 'members') renderMembers($mount);
    else if (tab === 'mapping') renderMapping($mount);
    else renderCustomFields($mount);
  });
})(jQuery);

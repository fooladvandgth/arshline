<?php
/**
 * Printable Submission View (Standalone)
 */
if (!defined('ABSPATH')) { exit; }
if (!current_user_can('manage_options') && !current_user_can('edit_posts')){ wp_die(__('دسترسی مجاز نیست.', 'arshline')); }
$submission_id = isset($_GET['arshline_submission']) ? intval($_GET['arshline_submission']) : 0;
if ($submission_id <= 0) { wp_die(__('شناسه نامعتبر است', 'arshline')); }

use Arshline\Modules\Forms\SubmissionRepository;
use Arshline\Modules\Forms\FieldRepository;

$sub = SubmissionRepository::findWithValues($submission_id);
if (!$sub) { wp_die(__('ارسال یافت نشد', 'arshline')); }
$fields = FieldRepository::listByForm((int)$sub['form_id']);
$labels = [];
foreach ($fields as $fr){ $p = $fr['props'] ?? []; $labels[$fr['id']] = $p['question'] ?? ('فیلد #'.$fr['id']); }

// Prepare rows for export (label, value)
$rows = [];
if (!empty($sub['values'])){
  foreach ($sub['values'] as $val){ $fid = (int)$val['field_id']; $rows[] = [ 'label' => $labels[$fid] ?? ('فیلد #'.$fid), 'value' => (string)($val['value'] ?? '') ]; }
}

// Allowed minimal HTML in answers (bold/italic/underline, line breaks, basic lists, links)
$allowed = [
  'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [],
  'br' => [], 'p' => [], 'ul' => [], 'ol' => [], 'li' => [],
  'a' => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [] ],
  // 'span' => [ 'style' => [] ], // intentionally disabled for safety
];
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="<?php bloginfo('charset'); ?>" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ارسال #<?php echo (int)$sub['id']; ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --bg:#f7f8fb; --surface:#fff; --text:#0f172a; --muted:#6b7280; --border:#e5e7eb; --primary:#1e3a8a;
    }
    html, body { height: 100%; }
    body{ margin:0; background:var(--bg); color:var(--text); font-family: 'IRANSans','IRANSansX','Vazirmatn','Tahoma','Segoe UI',sans-serif; }
    .arsh-btn{ background:var(--primary); color:#fff; border:none; border-radius:10px; padding:.55rem .9rem; cursor:pointer; }
    .arsh-btn:focus{ outline:2px solid #93c5fd; outline-offset:2px; }
    .arsh-print-wrap{ max-width:960px; margin:28px auto; padding:16px; }
    .arsh-print-card{ background:var(--surface); border-radius:14px; padding:18px; box-shadow:0 8px 30px rgba(2,8,23,.08); }
    .arsh-head{ display:flex; gap:1rem; justify-content:space-between; align-items:center; margin-bottom:12px; }
    .hint{ color:var(--muted); font-size:.92em; }
    .arsh-actions{ display:flex; gap:.5rem; align-items:center; }
    .arsh-answers{ margin-top:8px }
    .arsh-item{ border:1px solid var(--border); border-radius:12px; padding:.9rem; margin:.55rem 0; background:#fff; }
    .arsh-item .q{ font-weight:700; margin-bottom:.35rem }
    .arsh-item .a{ white-space:pre-wrap; }
    @media print {
      .no-print { display: none !important; }
      body { background: #fff !important; }
      .arsh-print-wrap { margin:0; padding:0; }
      .arsh-print-card { box-shadow:none; border-radius:0; }
    }
  </style>
</head>
<body>
  <div class="arsh-print-wrap">
    <div class="arsh-print-card">
      <div class="arsh-head">
        <div>
          <div style="font-size:18px;font-weight:700">ارسال #<?php echo (int)$sub['id']; ?> — <span class="hint"><?php echo esc_html($sub['status'] ?? ''); ?></span></div>
          <div class="hint"><?php echo esc_html($sub['created_at'] ?? ''); ?></div>
        </div>
        <div class="arsh-actions no-print">
          <button class="arsh-btn" onclick="window.print()">پرینت / PDF</button>
          <button class="arsh-btn" id="btnCsv">دانلود CSV</button>
          <button class="arsh-btn" id="btnXls">دانلود Excel</button>
        </div>
      </div>
      <div class="arsh-answers">
        <?php if (!empty($sub['values'])): foreach ($sub['values'] as $val): $fid=(int)$val['field_id']; ?>
          <div class="arsh-item">
            <div class="q"><?php echo esc_html($labels[$fid] ?? ('فیلد #'.$fid)); ?></div>
            <div class="a"><?php echo wp_kses((string)($val['value'] ?? ''), $allowed); ?></div>
          </div>
        <?php endforeach; else: ?>
          <div class="hint">پاسخی ثبت نشده است.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script>
    (function(){
      var data = <?php echo wp_json_encode($rows, JSON_UNESCAPED_UNICODE); ?> || [];
      function toCSV(rows){
        function esc(v){ v = String(v==null?'':v).replace(/"/g,'""'); return '"'+v+'"'; }
        var header = ['برچسب','پاسخ'];
        var out = [ header.map(esc).join(',') ];
        rows.forEach(function(r){ out.push([esc(r.label||''), esc(r.value||'')].join(',')); });
        return '\uFEFF' + out.join('\r\n'); // UTF-8 BOM for Excel
      }
      function toHTMLTable(rows){
        var html = '<table border="1"><thead><tr><th>برچسب</th><th>پاسخ</th></tr></thead><tbody>';
        rows.forEach(function(r){ html += '<tr><td>'+String(r.label||'')+'</td><td>'+String(r.value||'').replace(/\n/g,'<br>')+'</td></tr>'; });
        html += '</tbody></table>';
        return html;
      }
      function download(filename, blob){ var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 200); }
      var id = <?php echo (int)$sub['id']; ?>;
      var btnCsv = document.getElementById('btnCsv'); if (btnCsv) btnCsv.addEventListener('click', function(){ var csv = toCSV(data); download('submission-'+id+'.csv', new Blob([csv], {type:'text/csv;charset=utf-8;'})); });
      var btnXls = document.getElementById('btnXls'); if (btnXls) btnXls.addEventListener('click', function(){ var html = toHTMLTable(data); download('submission-'+id+'.xls', new Blob([html], {type:'application/vnd.ms-excel;charset=utf-8;'})); });
    })();
  </script>
</body>
</html>
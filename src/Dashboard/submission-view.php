<?php
/**
 * Printable Submission View
 */
if (!defined('ABSPATH')) { exit; }
if (!current_user_can('manage_options') && !current_user_can('edit_posts')){ wp_die(__('دسترسی مجاز نیست.', 'arshline')); }
$submission_id = isset($_GET['arshline_submission']) ? intval($_GET['arshline_submission']) : 0;
if ($submission_id <= 0) { wp_die(__('شناسه نامعتبر است', 'arshline')); }

use Arshline\Support\Helpers;
use Arshline\Modules\Forms\SubmissionRepository;
use Arshline\Modules\Forms\FieldRepository;

$sub = SubmissionRepository::findWithValues($submission_id);
if (!$sub) { wp_die(__('ارسال یافت نشد', 'arshline')); }
$fields = FieldRepository::listByForm((int)$sub['form_id']);
$labels = [];
foreach ($fields as $fr){ $p = $fr['props'] ?? []; $labels[$fr['id']] = $p['question'] ?? ('فیلد #'.$fr['id']); }

get_header();
?>
<style>
@media print {
  .no-print { display: none !important; }
  body { background: #fff !important; }
}
.arsh-print-wrap{max-width:900px;margin:24px auto;padding:16px}
.arsh-print-card{background:#fff;border-radius:12px;padding:16px;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.arsh-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.arsh-answers{margin-top:8px}
.arsh-item{border:1px solid #e5e7eb;border-radius:10px;padding:.8rem;margin:.5rem 0}
.arsh-item .q{font-weight:700;margin-bottom:.35rem}
.arsh-item .a{white-space:pre-wrap}
</style>
<div class="arsh-print-wrap">
  <div class="arsh-print-card">
    <div class="arsh-head">
      <div>
        <div style="font-size:18px;font-weight:700">ارسال #<?php echo (int)$sub['id']; ?> — <span class="hint"><?php echo esc_html($sub['status'] ?? ''); ?></span></div>
        <div class="hint"><?php echo esc_html($sub['created_at'] ?? ''); ?></div>
      </div>
      <div class="no-print" style="display:flex;gap:.5rem;align-items:center">
        <button class="arsh-btn" onclick="window.print()">پرینت / PDF</button>
      </div>
    </div>
    <div class="arsh-answers">
      <?php if (!empty($sub['values'])): foreach ($sub['values'] as $val): $fid=(int)$val['field_id']; ?>
        <div class="arsh-item">
          <div class="q"><?php echo esc_html($labels[$fid] ?? ('فیلد #'.$fid)); ?></div>
          <div class="a"><?php echo esc_html((string)$val['value']); ?></div>
        </div>
      <?php endforeach; else: ?>
        <div class="hint">پاسخی ثبت نشده است.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php get_footer();
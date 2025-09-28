<?php
namespace Arshline\Dashboard;

use WP_List_Table;

if (!defined('ABSPATH')) { exit; }

/**
 * صفحهٔ مدیریت «گروه‌های کاربری» زیر منوی کاربران
 * - CRUD گروه‌ها
 * - CRUD اعضا + ایمپورت CSV/Excel
 * - خروجی لینک‌های شخصی (CSV)
 * - اتصال فرم‌ها به گروه‌ها
 */
class UserGroupsPage
{
    const SLUG = 'arshline-user-groups';

    public static function boot(): void
    {
        add_action('admin_menu', [static::class, 'registerMenu']);
        add_action('admin_enqueue_scripts', [static::class, 'enqueueAssets']);
        // اکشن‌های سروری برای ایمپورت/اکسپورت (admin-post)
        add_action('admin_post_arshline_export_group_links', [static::class, 'handleExportGroupLinks']);
        add_action('admin_post_arshline_import_members', [static::class, 'handleImportMembers']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'users.php',
            __('گروه‌های کاربری عرشلاین', 'arshline'),
            __('گروه‌های کاربری (عرشلاین)', 'arshline'),
            'manage_options',
            static::SLUG,
            [static::class, 'renderPage']
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'users_page_' . static::SLUG) return;
        // RTL و استایل‌های وردپرس کافی است؛ اسکریپت اختصاصی مدیریت رویدادها
        wp_enqueue_script(
            'arshline-user-groups-admin',
            plugins_url('assets/js/admin/user-groups.js', dirname(__DIR__, 2) . '/arshline.php'),
            ['jquery'],
            (defined('ARSHLINE_VERSION') ? ARSHLINE_VERSION : (defined(__NAMESPACE__.'\\Dashboard::VERSION') ? Dashboard::VERSION : '1.0.0')),
            true
        );
        wp_localize_script('arshline-user-groups-admin', 'ARSHLINE_ADMIN', [
            'rest' => esc_url_raw(rest_url('arshline/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'nonces' => [
                'import' => wp_create_nonce('arshline_import_members'),
                'export' => wp_create_nonce('arshline_export_group_links'),
            ],
            'adminPostUrl' => admin_url('admin-post.php'),
            'formsEndpoint' => esc_url_raw(rest_url('arshline/v1/forms')),
            'strings' => [
                'add' => __('افزودن', 'arshline'),
                'edit' => __('ویرایش', 'arshline'),
                'delete' => __('حذف', 'arshline'),
                'save' => __('ذخیره', 'arshline'),
                'cancel' => __('انصراف', 'arshline'),
                'confirm_delete' => __('از حذف مطمئن هستید؟', 'arshline'),
                'import' => __('ایمپورت', 'arshline'),
                'export' => __('خروجی', 'arshline'),
                'search' => __('جستجو', 'arshline'),
                'name' => __('نام', 'arshline'),
                'phone' => __('شماره همراه', 'arshline'),
                'token' => __('توکن', 'arshline'),
                'link' => __('لینک', 'arshline'),
                'group' => __('گروه', 'arshline'),
                'members' => __('اعضا', 'arshline'),
                'groups' => __('گروه‌ها', 'arshline'),
                'mapping' => __('اتصال فرم‌ها', 'arshline'),
                'custom_fields' => __('فیلدهای سفارشی', 'arshline'),
                'loading' => __('در حال بارگذاری...', 'arshline'),
                'save_mapping' => __('ذخیره اتصال', 'arshline'),
                'form' => __('فرم', 'arshline'),
                'select_form' => __('انتخاب فرم', 'arshline'),
            ],
        ]);
        // استایل‌های ساده برای RTL
        wp_add_inline_style('wp-admin', 'html[dir=rtl] .arshline-ug-wrap{direction:rtl}');
    }

    /**
     * رندر صفحه شامل ۳ تب: گروه‌ها، اعضا، اتصال فرم‌ها
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی مجاز نیست.', 'arshline'));
        }
        echo '<div class="wrap arshline-ug-wrap" style="direction:rtl;text-align:right">';
        echo '<h1>'.esc_html__('گروه‌های کاربری (عرشلاین)', 'arshline').'</h1>';
        // ناوبری تب‌ها
        echo '<h2 class="nav-tab-wrapper">';
        $tabs = [
            'groups' => __('گروه‌ها', 'arshline'),
            'members' => __('اعضا', 'arshline'),
            'mapping' => __('اتصال فرم‌ها', 'arshline'),
        ];
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'groups';
        foreach ($tabs as $k => $label) {
            $active = ($tab === $k) ? ' nav-tab-active' : '';
            $url = add_query_arg(['page' => static::SLUG, 'tab' => $k], admin_url('users.php'));
            echo '<a class="nav-tab'.$active.'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</h2>';

        echo '<div id="arshline-ug-app" data-tab="'.esc_attr($tab).'">';
        // ظرف اصلی، رندر توسط JS انجام می‌شود
        echo '<div class="ar-card" style="background:#fff;border-radius:12px;padding:14px;box-shadow:0 2px 14px rgba(0,0,0,.06)">';
        echo '<div id="arUGMount">'.esc_html__('در حال بارگذاری...', 'arshline').'</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * خروجی CSV لینک‌های شخصی برای یک گروه
     * ستون‌ها: نام، شماره همراه، توکن، لینک
     * UTF-8 بدون BOM و با ضدعفونی جهت جلوگیری از CSV Injection
     */
    public static function handleExportGroupLinks(): void
    {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی مجاز نیست.', 'arshline')); }
        check_admin_referer('arshline_export_group_links');
    $gid = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;
    // form_id اختیاری است؛ لینک عمومی فقط بر اساس توکن ساخته می‌شود
    $fid = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
    if ($gid <= 0) { wp_die(esc_html__('شناسه نامعتبر است.', 'arshline')); }

        // بارگذاری اعضا از REST یا مستقیم DB — در این صفحه فقط لینک می‌سازیم، REST قبلاً امن شده
        // برای سادگی از REST استفاده نمی‌کنیم تا خروجی سریع باشد
        $members = \Arshline\Modules\UserGroups\MemberRepository::list($gid, 50000);

        // پایه لینک عمومی با توکن: ?arshline=TOKEN
        $base = add_query_arg('arshline', '%TOKEN%', home_url('/'));

        // هدرهای دانلود
        $filename = 'arshline_group_'.$gid.'_links.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        // بدون BOM جهت خواسته؛ با این حال Excel ویندوز معمولاً با UTF-8 ok است
        $out = fopen('php://output', 'w');
        // عنوان ستون‌ها: افزوده شدن فیلدهای سفارشی گروه به‌صورت پویا
        $customHeaders = [];
        try {
            $fields = \Arshline\Modules\UserGroups\FieldRepository::listByGroup($gid);
            foreach ($fields as $f){ $customHeaders[] = is_string($f->label) && $f->label !== '' ? $f->label : $f->name; }
        } catch (\Throwable $e) { $customHeaders = []; }
        $headers = array_merge(['نام','شماره همراه','توکن','لینک'], $customHeaders);
        // اطمینان از UTF-8
        fputs($out, implode(',', array_map([static::class,'csvSafe'], $headers))."\n");
        foreach ($members as $m) {
            $tok = $m->token ?: \Arshline\Modules\UserGroups\MemberRepository::ensureToken($m->id);
            $link = str_replace('%TOKEN%', rawurlencode((string)$tok), $base);
            $row = [ $m->name, $m->phone, (string)$tok, $link ];
            // Map custom fields per order
            $dataArr = is_array($m->data) ? $m->data : (json_decode($m->data ?? '[]', true) ?: []);
            foreach ($fields ?? [] as $f){ $key = $f->name; $row[] = isset($dataArr[$key]) && is_scalar($dataArr[$key]) ? (string)$dataArr[$key] : ''; }
            fputs($out, implode(',', array_map([static::class,'csvSafe'], $row))."\n");
        }
        fclose($out); exit;
    }

    /**
     * ایمپورت اعضا از CSV (ستون‌های نام و شماره همراه الزامی)
     * سایر ستون‌ها در data ذخیره می‌شود.
     */
    public static function handleImportMembers(): void
    {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('دسترسی مجاز نیست.', 'arshline')); }
        check_admin_referer('arshline_import_members');
        $gid = isset($_POST['group_id']) ? intval($_POST['group_id']) : 0;
        if ($gid <= 0) { wp_die(esc_html__('شناسه گروه نامعتبر است.', 'arshline')); }
        if (!isset($_FILES['csv']) || empty($_FILES['csv']['tmp_name'])) { wp_die(esc_html__('فایل انتخاب نشده است.', 'arshline')); }

        $fh = fopen($_FILES['csv']['tmp_name'], 'r');
        if (!$fh) { wp_die(esc_html__('امکان خواندن فایل نیست.', 'arshline')); }
        // خواندن هدر
        $header = fgetcsv($fh, 0, ',');
        if (!$header) { fclose($fh); wp_die(esc_html__('هدر CSV نامعتبر است.', 'arshline')); }
        $header = array_map('trim', $header);
        // یافتن ستون‌های ضروری
        $idxName = array_search('نام', $header);
        $idxPhone = array_search('شماره همراه', $header);
        if ($idxName === false || $idxPhone === false) { fclose($fh); wp_die(esc_html__('ستون‌های نام و شماره همراه الزامی است.', 'arshline')); }
        $rows = [];
        while (($cols = fgetcsv($fh, 0, ',')) !== false) {
            $name = isset($cols[$idxName]) ? sanitize_text_field($cols[$idxName]) : '';
            $phone = isset($cols[$idxPhone]) ? sanitize_text_field($cols[$idxPhone]) : '';
            if ($name === '' || $phone === '') continue;
            $data = [];
            foreach ($header as $i => $colName) {
                if ($i === $idxName || $i === $idxPhone) continue;
                if (!isset($cols[$i])) continue;
                $safeKey = preg_replace('/[^A-Za-z0-9_\-]/', '_', $colName);
                $data[$safeKey] = sanitize_text_field($cols[$i]);
            }
            $rows[] = [ 'name' => $name, 'phone' => $phone, 'data' => $data ];
        }
        fclose($fh);

        if (!empty($rows)) {
            $n = \Arshline\Modules\UserGroups\MemberRepository::addBulk($gid, $rows);
            wp_safe_redirect(add_query_arg(['page' => static::SLUG, 'tab' => 'members', 'group_id' => $gid, 'imported' => $n], admin_url('users.php')));
            exit;
        }
        wp_safe_redirect(add_query_arg(['page' => static::SLUG, 'tab' => 'members', 'group_id' => $gid, 'imported' => 0], admin_url('users.php')));
        exit;
    }

    /**
     * جلوگیری از تزریق CSV (Excel formulas) و جداسازها
     */
    protected static function csvSafe(string $s): string
    {
        // جلوگیری از =,+,-,@ در ابتدای سلول
        if (preg_match('/^[=\+\-@]/', $s)) { $s = "'".$s; }
        // تبدیل " به "" و قرار دادن داخل دابل‌کوت
        $s = str_replace('"', '""', $s);
        // جداسازی با کاما، پس باید همیشه در کوت بیاید
        return '"'.$s.'"';
    }
}

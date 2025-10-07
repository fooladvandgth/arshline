<?php
/**
 * Plugin Name: فرم‌ساز و داشبورد عرشلاین (Arshline Starter)
 * Plugin URI: https://arshline.ir/
 * Description: افزونه فرم‌ساز، داشبورد و گزارشات فارسی عرشلاین برای وردپرس با پشتیبانی هوش مصنوعی و امنیت پیشرفته.
 * Version: 7.2.2
 * Author: گروه توسعه عرشلاین
 * Author URI: https://arshline.ir/
 * License: GPL2
 * Text Domain: arshline
 */

use Arshline\Dashboard\Dashboard;
use Arshline\Dashboard\DashboardPageInstaller;
use Arshline\Modules\Forms\Form;
use Arshline\Modules\Forms\FormRepository;
use Arshline\Modules\Forms\FormValidator;
use Arshline\Modules\Forms\Submission;
use Arshline\Modules\Forms\SubmissionRepository;
use Arshline\Modules\Forms\SubmissionValueRepository;
use Arshline\Modules\FormsModule;
use Arshline\Core\Api;
use Arshline\Core\AccessControl;
// use Arshline\Dashboard\SettingsPage; // Removed - settings moved to custom dashboard
use Arshline\Dashboard\UserGroupsPage;

if (!defined('ABSPATH')) {
    
    // Optional: Load Composer autoloader if the plugin was installed with vendor deps.
    // Must appear after use statements to avoid "use must be first statement" parsing issues.
    $__arshline_composer_autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($__arshline_composer_autoload)) {
        require_once $__arshline_composer_autoload;
    }
    exit;
}

if (!defined('ARSHLINE_DEBUG_NONCE_KEY')) {
    define('ARSHLINE_DEBUG_NONCE_KEY', '_arshline_nonce');
}

// --- Hoosha Logger bootstrap (enable & maintenance) ---
if (!defined('ARSHLINE_HOOSHA_LOG_ENABLED')) {
    // Forced enable (user request) – can be overridden by redefining before plugin load.
    define('ARSHLINE_HOOSHA_LOG_ENABLED', true);
}

// Register daily event for log retention (if not already)
add_action('init', static function(){
    if (ARSHLINE_HOOSHA_LOG_ENABLED && function_exists('wp_next_scheduled') && !wp_next_scheduled('arshline_hoosha_log_retention')){
        wp_schedule_event(time()+3600, 'daily', 'arshline_hoosha_log_retention');
    }
});

add_action('arshline_hoosha_log_retention', static function(){
    // Remove rotated hoosha.log.* files older than 30 days or keep max 10 recent.
    if (!ARSHLINE_HOOSHA_LOG_ENABLED) return;
    $base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : null;
    if (!$base || !is_dir($base)) return;
    $pattern = $base . '/hoosha.log.*';
    $files = glob($pattern); if (!$files) return;
    // Sort by mtime desc
    usort($files, function($a,$b){ return filemtime($b) <=> filemtime($a); });
    $now = time(); $keep = 0;
    foreach ($files as $f){
        $keep++;
        $ageDays = ($now - filemtime($f)) / 86400;
        if ($ageDays > 30 || $keep > 10){ @unlink($f); }
    }
});

add_filter('template_include', static function ($template) {
    // Printable submission view: ?arshline_submission=ID (admins/editors only)
    if (isset($_GET['arshline_submission']) && (int) $_GET['arshline_submission'] > 0) {
        if (current_user_can('manage_options') || current_user_can('edit_posts')) {
            $sub_template = __DIR__ . '/src/Dashboard/submission-view.php';
            if (file_exists($sub_template)) {
                return $sub_template;
            }
        } else {
            wp_die(__('دسترسی مجاز نیست.', 'arshline'));
        }
    }
    // Public form rendering via query param
    if (isset($_GET['arshline_form']) && (int) $_GET['arshline_form'] > 0) {
        $public_template = __DIR__ . '/src/Frontend/form-template.php';
        if (file_exists($public_template)) {
            return $public_template;
        }
    }
    // Public form rendering via short token (?arshline=TOKEN)
    if (isset($_GET['arshline'])) {
        $token = sanitize_text_field((string) $_GET['arshline']);
        if ($token && preg_match('/^[A-Za-z0-9]{8,24}$/', $token)) {
            $public_template = __DIR__ . '/src/Frontend/form-template.php';
            if (file_exists($public_template)) {
                return $public_template;
            }
        }
    }

    if (arshline_is_dashboard_request()) {
        $plugin_template = __DIR__ . '/src/Dashboard/dashboard-template.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    return $template;
});

register_activation_hook(__FILE__, static function () {
    update_option('arshline_do_page_install', 1);
    update_option('arshline_allow_frontpage_install', 1);
    // Ensure Hoosha logger option is enabled on activation
    update_option('arshline_hoosha_log_enabled', 1);
    FormsModule::migrate();
});

add_action('init', static function () {
    DashboardPageInstaller::register_shortcode();

    if (get_option('arshline_do_page_install')) {
        DashboardPageInstaller::install_dashboard_page();
        if (get_option('arshline_allow_frontpage_install')) {
            DashboardPageInstaller::ensure_front_page();
        }
        delete_option('arshline_do_page_install');
        delete_option('arshline_allow_frontpage_install');
    }
});

add_action('template_redirect', static function () {
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    if (!is_front_page()) {
        return;
    }

    $dashboard_id = (int) get_option('arshline_dashboard_page_id');
    if (!$dashboard_id || is_page($dashboard_id)) {
        return;
    }

    $url = get_permalink($dashboard_id);
    if ($url) {
        wp_safe_redirect($url);
        exit;
    }
});

add_action('plugins_loaded', static function () {
    AccessControl::boot();
    Dashboard::boot();
    FormsModule::boot();
    Api::boot();
    // SettingsPage::boot(); // Removed - settings moved to custom dashboard
    UserGroupsPage::boot();
});
add_action('wp_enqueue_scripts', static function () {
    if (!arshline_is_dashboard_request()) {
        return;
    }

    $version = defined('\\Arshline\\Dashboard\\Dashboard::VERSION') ? Dashboard::VERSION : '1.0.0';

    // Enqueue Vazir font globally for the plugin dashboard
    wp_enqueue_style('arshline-font-vazir', 'https://cdn.jsdelivr.net/npm/vazir-font/dist/font-face.css', [], null);

    // Enqueue modular CSS files in correct order for WordPress standards
    wp_enqueue_style('arshline-variables', plugins_url('assets/css/modules/variables.css', __FILE__), [], $version);
    wp_enqueue_style('arshline-layout', plugins_url('assets/css/modules/layout.css', __FILE__), ['arshline-variables'], $version);
    wp_enqueue_style('arshline-components', plugins_url('assets/css/modules/components.css', __FILE__), ['arshline-variables', 'arshline-layout'], $version);
    wp_enqueue_style('arshline-utilities', plugins_url('assets/css/modules/utilities.css', __FILE__), ['arshline-variables'], $version);
    // Provide minimal wp-admin class compatibility (button, widefat, regular-text, notices)
    wp_enqueue_style('arshline-wp-admin-compat', plugins_url('assets/css/modules/wp-admin-compat.css', __FILE__), ['arshline-components'], $version);

    wp_enqueue_script('arshline-ionicons', 'https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js', [], null, true);

    wp_enqueue_script('jquery');
    wp_enqueue_style('arshline-persian-datepicker', 'https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/css/persian-datepicker.css', [], null);
    wp_enqueue_script('arshline-persian-date', 'https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.js', ['jquery'], null, true);
    wp_enqueue_script('arshline-persian-datepicker', 'https://cdn.jsdelivr.net/npm/persian-datepicker@1.2.0/dist/js/persian-datepicker.js', ['arshline-persian-date'], null, true);

    $strings = [
        'loading' => __('در حال بارگذاری...', 'arshline'),
        'form_delete_confirm' => __('حذف فرم #{id}؟ این عمل بازگشت‌ناپذیر است.', 'arshline'),
        'form_deleted' => __('فرم حذف شد', 'arshline'),
        'form_delete_failed' => __('حذف فرم ناموفق بود', 'arshline'),
        'uploading' => __('در حال آپلود...', 'arshline'),
        'upload_failed' => __('آپلود ناموفق بود', 'arshline'),
        'upload_image_failed' => __('آپلود تصویر ناموفق بود', 'arshline'),
        'upload_success' => __('آپلود شد', 'arshline'),
        'save_success' => __('ذخیره شد', 'arshline'),
        'save_failed' => __('ذخیره تغییرات ناموفق بود', 'arshline'),
        'submission_success' => __('ارسال شد', 'arshline'),
        'submission_failed' => __('اعتبارسنجی یا ارسال ناموفق بود', 'arshline'),
        'remove_question_failed' => __('حذف سؤال ناموفق بود', 'arshline'),
        'question_removed' => __('سؤال حذف شد', 'arshline'),
        'bulk_delete_failed' => __('حذف گروهی ناموفق بود', 'arshline'),
        'bulk_delete_success' => __('سؤالات انتخاب‌شده حذف شد', 'arshline'),
        'insert_field_failed' => __('درج فیلد ناموفق بود', 'arshline'),
        'insert_field_success' => __('فیلد جدید درج شد', 'arshline'),
        'add_field_failed' => __('افزودن فیلد ناموفق بود', 'arshline'),
        'welcome_add_failed' => __('افزودن پیام خوش‌آمد ناموفق بود', 'arshline'),
        'welcome_add_success' => __('پیام خوش‌آمد افزوده شد', 'arshline'),
        'thankyou_add_failed' => __('افزودن پیام تشکر ناموفق بود', 'arshline'),
        'thankyou_add_success' => __('پیام تشکر افزوده شد', 'arshline'),
        'create_form_failed' => __('ایجاد فرم ناموفق بود. لطفاً دسترسی را بررسی کنید.', 'arshline'),
        'form_created' => __('فرم ایجاد شد', 'arshline'),
        'forms_load_error' => __('خطا در بارگذاری فرم‌ها', 'arshline'),
        'session_expired' => __('نشست شما منقضی شده یا دسترسی کافی ندارید.', 'arshline'),
        'permission_required' => __('برای ویرایش فرم باید وارد شوید یا دسترسی داشته باشید', 'arshline'),
        'no_edit_permission' => __('دسترسی به ویرایش فرم ندارید', 'arshline'),
        'forbidden_action' => __('اجازهٔ انجام این عملیات را ندارید. لطفاً وارد شوید یا با مدیر تماس بگیرید.', 'arshline'),
        'layout_update_success' => __('چیدمان به‌روزرسانی شد', 'arshline'),
        'layout_update_failed' => __('به‌روزرسانی چیدمان ناموفق بود', 'arshline'),
        'message_delete_failed' => __('حذف {title} ناموفق بود', 'arshline'),
        'unauthorized' => __('دسترسی مجاز نیست.', 'arshline'),
    ];

    $public_base = add_query_arg('arshline_form', '%ID%', home_url('/'));
    $public_token_base = add_query_arg('arshline', '%TOKEN%', home_url('/'));
    wp_localize_script('arshline-dashboard', 'ARSHLINE_DASHBOARD', [
        'restUrl' => esc_url_raw(rest_url('arshline/v1/')),
        'restNonce' => wp_create_nonce('wp_rest'),
        'canManage' => current_user_can('edit_posts') || current_user_can('manage_options'),
        'loginUrl' => esc_url_raw(wp_login_url(get_permalink())),
        'strings' => $strings,
        'publicBase' => esc_url_raw($public_base),
        'publicTokenBase' => esc_url_raw($public_token_base),
    ]);

    // Enqueue console capture module for dashboard only; gated by the option
    $capture_enabled = (bool) get_option(SettingsPage::OPTION_CAPTURE, false);
    wp_enqueue_script(
        'arshline-console-capture',
        plugins_url('assets/js/modules/console-capture.js', __FILE__),
        [],
        $version,
        true
    );
    wp_localize_script('arshline-console-capture', 'ARSHLINE_CAPTURE', [
        'enabled' => $capture_enabled,
        'runTests' => isset($_GET['arsh_capture_test']) && current_user_can('manage_options'),
        'strings' => [
            'moduleEnabled' => __('ماژول ثبت رویداد فعال شد.', 'arshline'),
            'moduleDisabled' => __('ماژول ثبت رویداد غیرفعال است.', 'arshline'),
            'testStart' => __('آغاز تست واحد ماژول ثبت رویداد…', 'arshline'),
            'testPass' => __('موفق', 'arshline'),
            'testFail' => __('ناموفق', 'arshline'),
            'testDone' => __('پایان تست‌ها', 'arshline'),
        ],
    ]);
});


// Autoload (Composer-like)
spl_autoload_register(function ($class) {
    $prefix = 'Arshline\\';
    $base_dir = __DIR__ . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return; // Not one of ours.
    }
    // Convert namespace separators to directory separators (single backslash is the separator).
    $relative_class = substr($class, strlen($prefix));
    $path = str_replace('\\', '/', $relative_class) . '.php';
    $file = $base_dir . $path;
    if (file_exists($file)) {
        require_once $file;
    }
});

add_action('init', static function () {
    if (isset($_GET['arshline_test_submission'])) {
        arshline_verify_debug_request('submission');
        $submissionData = [
            'form_id' => 1,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status' => 'pending',
            'meta' => ['desc' => 'آزمایش از REST'],
            'values' => [
                ['field_id' => 1, 'value' => 'نام تستی'],
                ['field_id' => 2, 'value' => 'ali@example.com'],
            ],
        ];
        $submission = new Submission($submissionData);
        $submission_id = SubmissionRepository::save($submission);
        foreach ($submission->values as $index => $val) {
            SubmissionValueRepository::save($submission_id, (int) $val['field_id'], $val['value'], $index);
        }
        $message = sprintf(
            '<div style="direction:rtl">%s</div>',
            esc_html(sprintf('ارسال تست با موفقیت ذخیره شد. شناسه: %d', $submission_id))
        );
        wp_die(
            wp_kses_post($message),
            esc_html__('Arshline Debug', 'arshline'),
            ['response' => 200]
        );
    }

    if (isset($_GET['arshline_test_form'])) {
        arshline_verify_debug_request('form');
        $formData = [
            'schema_version' => '1.0.0',
            'owner_id' => get_current_user_id(),
            'status' => 'draft',
            'meta' => ['title' => 'فرم تستی'],
            'fields' => [
                ['type' => 'text', 'label' => 'نام'],
                ['type' => 'email', 'label' => 'ایمیل'],
            ],
        ];
        $form = new Form($formData);
        $errors = FormValidator::validate($form);
        if (!empty($errors)) {
            $message = sprintf(
                '<div style="direction:rtl;color:red">%s</div>',
                esc_html(implode(' | ', $errors))
            );
            wp_die(
                wp_kses_post($message),
                esc_html__('Arshline Debug', 'arshline'),
                ['response' => 422]
            );
        }
        $id = FormRepository::save($form);
        $message = sprintf(
            '<div style="direction:rtl">%s</div>',
            esc_html(sprintf('فرم تست با موفقیت ذخیره شد. شناسه: %d', $id))
        );
        wp_die(
            wp_kses_post($message),
            esc_html__('Arshline Debug', 'arshline'),
            ['response' => 200]
        );
    }
}, 20);

if (!function_exists('arshline_is_dashboard_request')) {
    function arshline_is_dashboard_request(): bool
    {
        if (!is_page()) {
            return false;
        }

        $dashboard_id = (int) get_option('arshline_dashboard_page_id');
        if ($dashboard_id && is_page($dashboard_id)) {
            return true;
        }

        $queried_id = get_queried_object_id();
        if ($queried_id) {
            $slug = get_post_field('post_name', $queried_id);
            if ($slug === 'arshline-dashboard') {
                return true;
            }
            $content = get_post_field('post_content', $queried_id);
            if (function_exists('has_shortcode') && has_shortcode($content, 'arshline_dashboard')) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('arshline_verify_debug_request')) {
    function arshline_verify_debug_request(string $action): void
    {
        if (!current_user_can('manage_options')) {
            arshline_debug_forbidden_response();
        }

        $nonce = isset($_GET[ARSHLINE_DEBUG_NONCE_KEY])
            ? sanitize_text_field(wp_unslash((string) $_GET[ARSHLINE_DEBUG_NONCE_KEY]))
            : '';

        if (!$nonce || !wp_verify_nonce($nonce, 'arshline_debug_' . $action)) {
            arshline_debug_forbidden_response();
        }
    }
}

if (!function_exists('arshline_debug_forbidden_response')) {
    function arshline_debug_forbidden_response(): void
    {
        wp_die(
            esc_html__('درخواست مجاز نیست.', 'arshline'),
            esc_html__('Arshline Debug', 'arshline'),
            ['response' => 403]
        );
    }
}

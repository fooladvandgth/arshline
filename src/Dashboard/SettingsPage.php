<?php
namespace Arshline\Dashboard;

if (!defined('ABSPATH')) { exit; }

class SettingsPage
{
    const OPTION_CAPTURE = 'arshline_capture_console_events';
    const OPTION_AI      = 'arshline_settings'; // array option used across the plugin

    public static function boot(): void
    {
        add_action('admin_menu', [static::class, 'registerMenu']);
        add_action('admin_init', [static::class, 'registerSettings']);
    }

    public static function registerMenu(): void
    {
        // Settings -> Arshline Settings
        add_options_page(
            __('تنظیمات عرشلاین', 'arshline'),
            __('تنظیمات عرشلاین', 'arshline'),
            'manage_options',
            'arshline-settings',
            [static::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting('arshline_settings', self::OPTION_CAPTURE, [
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => static function ($val) {
                return (bool) $val;
            },
        ]);

        // Register the main settings array used by AI features and other keys.
        // Input name format: arshline_settings[key]
        register_setting('arshline_settings', self::OPTION_AI, [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => static function ($val) {
                $in = is_array($val) ? $val : [];
                $out = [];
                // ai_mode: efficient|hybrid|ai-heavy
                $m = isset($in['ai_mode']) ? (string)$in['ai_mode'] : 'hybrid';
                $allowed = ['efficient','hybrid','ai-heavy'];
                if (!in_array($m, $allowed, true)) { $m = 'hybrid'; }
                $out['ai_mode'] = $m;
                // ai_max_rows (50..1000)
                $mr = isset($in['ai_max_rows']) && is_numeric($in['ai_max_rows']) ? (int)$in['ai_max_rows'] : 400;
                $out['ai_max_rows'] = max(50, min(1000, $mr));
                // ai_allow_pii
                $out['ai_allow_pii'] = !empty($in['ai_allow_pii']);
                // token ceilings
                $tt = isset($in['ai_tok_typical']) && is_numeric($in['ai_tok_typical']) ? (int)$in['ai_tok_typical'] : 8000;
                $tm = isset($in['ai_tok_max']) && is_numeric($in['ai_tok_max']) ? (int)$in['ai_tok_max'] : 32000;
                if ($tm < $tt) { $tm = $tt; }
                $out['ai_tok_typical'] = max(1000, min(16000, $tt));
                $out['ai_tok_max'] = max($out['ai_tok_typical'], min(32000, $tm));
                return $out;
            },
        ]);

        add_settings_section(
            'arshline_debug_section',
            __('دیباگ و گزارش رویدادها', 'arshline'),
            function () {
                echo '<p style="direction:rtl">' . esc_html__('در این بخش می‌توانید ثبت رویدادهای سمت کلاینت را برای دیباگ در کنسول مرورگر فعال کنید. توصیه می‌شود فقط برای محیط مدیریت فعال بماند.', 'arshline') . '</p>';
            },
            'arshline_settings_page'
        );

        add_settings_field(
            self::OPTION_CAPTURE,
            __('فعال‌سازی ثبت رویدادهای کنسول', 'arshline'),
            function () {
                $val = (bool) get_option(self::OPTION_CAPTURE, false);
                echo '<label style="direction:rtl"><input type="checkbox" name="' . esc_attr(self::OPTION_CAPTURE) . '" value="1" ' . checked(true, $val, false) . ' /> ' . esc_html__('فقط در داشبورد عرشلاین فعال شود (سمت فرانت عمومی غیرفعال است).', 'arshline') . '</label>';
            },
            'arshline_settings_page',
            'arshline_debug_section'
        );

        // AI Analysis Settings
        add_settings_section(
            'arshline_ai_section',
            __('تحلیل هوشمند (AI) — حالت و سقف‌ها', 'arshline'),
            function () {
                echo '<p style="direction:rtl">' . esc_html__('پیکربندی حالت اجرای تحلیل هوشمند و محدودیت‌های ارسال داده برای حفظ سرعت، هزینه و حریم خصوصی.', 'arshline') . '</p>';
            },
            'arshline_settings_page'
        );

        // Field: ai_mode
        add_settings_field(
            'arshline_ai_mode',
            __('حالت تحلیل', 'arshline'),
            function () {
                $opt = get_option(self::OPTION_AI, []);
                $mode = isset($opt['ai_mode']) ? (string)$opt['ai_mode'] : 'hybrid';
                echo '<select name="' . esc_attr(self::OPTION_AI) . '[ai_mode]">';
                $choices = [
                    'efficient' => __('سریع و بهینه (سمت‌سرور)', 'arshline'),
                    'hybrid' => __('ترکیبی (پیش‌فرض)', 'arshline'),
                    'ai-heavy' => __('متکی به AI (انعطاف بالاتر)', 'arshline'),
                ];
                foreach ($choices as $val => $label) {
                    echo '<option value="' . esc_attr($val) . '" ' . selected($mode, $val, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            },
            'arshline_settings_page',
            'arshline_ai_section'
        );

        // Field: ai_max_rows
        add_settings_field(
            'arshline_ai_max_rows',
            __('حداکثر ردیف ارسالی در حالت AI-subset', 'arshline'),
            function () {
                $opt = get_option(self::OPTION_AI, []);
                $v = isset($opt['ai_max_rows']) ? (int)$opt['ai_max_rows'] : 400;
                echo '<input type="number" min="50" max="1000" step="10" name="' . esc_attr(self::OPTION_AI) . '[ai_max_rows]" value="' . esc_attr((string)$v) . '" />';
                echo '<p class="description" style="direction:rtl">' . esc_html__('بین ۵۰ تا ۱۰۰۰ (پیش‌فرض: ۴۰۰)', 'arshline') . '</p>';
            },
            'arshline_settings_page',
            'arshline_ai_section'
        );

        // Field: ai_allow_pii
        add_settings_field(
            'arshline_ai_allow_pii',
            __('اجازهٔ ارسال PII به AI', 'arshline'),
            function () {
                $opt = get_option(self::OPTION_AI, []);
                $v = !empty($opt['ai_allow_pii']);
                echo '<label style="direction:rtl"><input type="checkbox" name="' . esc_attr(self::OPTION_AI) . '[ai_allow_pii]" value="1" ' . checked(true, $v, false) . ' /> ' . esc_html__('ارسال شماره/ایمیل فقط در صورت نیاز صریح کاربر.', 'arshline') . '</label>';
            },
            'arshline_settings_page',
            'arshline_ai_section'
        );

        // Field: ai_tok_typical
        add_settings_field(
            'arshline_ai_tok_typical',
            __('سقف معمول توکن ورودی', 'arshline'),
            function () {
                $opt = get_option(self::OPTION_AI, []);
                $v = isset($opt['ai_tok_typical']) ? (int)$opt['ai_tok_typical'] : 8000;
                echo '<input type="number" min="1000" max="16000" step="500" name="' . esc_attr(self::OPTION_AI) . '[ai_tok_typical]" value="' . esc_attr((string)$v) . '" />';
            },
            'arshline_settings_page',
            'arshline_ai_section'
        );

        // Field: ai_tok_max
        add_settings_field(
            'arshline_ai_tok_max',
            __('سقف نهایی توکن ورودی', 'arshline'),
            function () {
                $opt = get_option(self::OPTION_AI, []);
                $v = isset($opt['ai_tok_max']) ? (int)$opt['ai_tok_max'] : 32000;
                echo '<input type="number" min="4000" max="32000" step="1000" name="' . esc_attr(self::OPTION_AI) . '[ai_tok_max]" value="' . esc_attr((string)$v) . '" />';
            },
            'arshline_settings_page',
            'arshline_ai_section'
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی مجاز نیست.', 'arshline'));
        }
        echo '<div class="wrap" style="direction:rtl;text-align:right">';
        echo '<h1>' . esc_html__('تنظیمات عرشلاین', 'arshline') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('arshline_settings');
        do_settings_sections('arshline_settings_page');
        submit_button(__('ذخیره تغییرات', 'arshline'));
        echo '</form>';
        echo '</div>';
    }
}

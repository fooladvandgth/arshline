<?php
namespace Arshline\Dashboard;

if (!defined('ABSPATH')) { exit; }

class SettingsPage
{
    const OPTION_CAPTURE = 'arshline_capture_console_events';

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

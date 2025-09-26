<?php
namespace Arshline\Dashboard;

class Dashboard {
    /**
     * نسخه فعلی داشبورد افزونه
     */
    const VERSION = '2.1.0';

    /**
     * راه‌اندازی داشبورد اختصاصی افزونه
     */
    public static function boot() {
        // غیرفعال‌سازی منوهای پیش‌فرض وردپرس برای این افزونه
        add_action('admin_menu', [self::class, 'remove_wp_menus'], 999);
        // افزودن منوی اختصاصی افزونه
        add_action('admin_menu', [self::class, 'add_dashboard_menu']);
    }

    /**
     * حذف منوهای پیش‌فرض وردپرس (در صورت نیاز)
     */
    public static function remove_wp_menus() {
        // نمونه: حذف منوی فرم‌ها و سایر منوهای مرتبط
        // remove_menu_page('edit.php?post_type=page');
        // remove_menu_page('tools.php');
        // ...
    }

    /**
     * افزودن منوی اختصاصی افزونه
     */
    public static function add_dashboard_menu() {
        add_menu_page(
            __('داشبورد عرشلاین', 'arshline'),
            __('عرشلاین', 'arshline'),
            'manage_options',
            'arshline-dashboard',
            [self::class, 'render_dashboard'],
            'dashicons-admin-generic',
            2
        );
    }

    /**
     * رندر داشبورد اختصاصی افزونه
     */
    public static function render_dashboard() {
        echo '<div class="wrap" dir="rtl">';
        echo '<h1>داشبورد اختصاصی عرشلاین</h1>';
        echo '<p>نسخه داشبورد: <b>' . self::VERSION . '</b></p>';
        echo '<p>در این بخش، مدیریت کامل افزونه بدون وابستگی به داشبورد وردپرس انجام می‌شود.</p>';
        // ... سایر بخش‌های داشبورد ...
        echo '</div>';
    }
}

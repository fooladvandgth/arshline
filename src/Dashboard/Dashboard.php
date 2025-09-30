<?php
namespace Arshline\Dashboard;

class Dashboard {
    /**
     * نسخه فعلی داشبورد افزونه
     */
    const VERSION = '4.0.1';

    /**
     * راه‌اندازی داشبورد اختصاصی افزونه
     */
    public static function boot() {
        // غیرفعال‌سازی منوهای پیش‌فرض وردپرس برای این افزونه
        add_action('admin_menu', [self::class, 'remove_wp_menus'], 999);
        // افزودن منوی اختصاصی افزونه
        add_action('admin_menu', [self::class, 'add_dashboard_menu']);
        // افزودن منوی مستقل «گروه‌های کاربری» به عنوان آیتم مجزا (نه زیر منوی کاربران)
        add_action('admin_menu', [self::class, 'add_user_groups_menu']);
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
     * افزودن زیرمنوی «مدیریت گروه‌های کاربری» زیر منوی اصلی «کاربران» وردپرس
     */
    public static function add_user_groups_menu() {
        add_menu_page(
            __('گروه‌های کاربری عرشلاین', 'arshline'),
            __('گروه‌های کاربری', 'arshline'),
            'manage_options',
            'arshline-user-groups',
            [self::class, 'render_user_groups'],
            'dashicons-groups',
            3
        );
    }

    /**
     * صفحه placeholder برای مدیریت گروه‌ها تا UI کامل پیاده‌سازی شود.
     */
    public static function render_user_groups() {
        echo '<div class="wrap" dir="rtl">';
        echo '<h1>گروه‌های کاربری عرشلاین</h1>';
        echo '<p>این بخش به‌زودی با مدیریت کامل گروه‌ها، اعضا و لینک‌های شخصی‌سازی شده تکمیل می‌شود.</p>';
        echo '<p>فعلاً می‌توانید از REST API‌های زیر استفاده کنید:</p>';
        echo '<ul style="line-height:1.9">';
        echo '<li>GET /wp-json/arshline/v1/user-groups</li>';
        echo '<li>POST /wp-json/arshline/v1/user-groups</li>';
        echo '<li>PUT/DELETE /wp-json/arshline/v1/user-groups/{id}</li>';
        echo '<li>GET/POST /wp-json/arshline/v1/user-groups/{id}/members</li>';
        echo '<li>POST /wp-json/arshline/v1/user-groups/{gid}/members/{mid}/token</li>';
        echo '<li>GET/PUT /wp-json/arshline/v1/forms/{fid}/access/groups</li>';
        echo '</ul>';
        echo '</div>';
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

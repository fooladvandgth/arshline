<?php
namespace Arshline\Dashboard;

class DashboardPageInstaller {
    /**
     * نام اسلاگ صفحه داشبورد
     */
    const DASHBOARD_SLUG = 'arshline-dashboard';
    /**
     * عنوان صفحه داشبورد
     */
    const DASHBOARD_TITLE = 'داشبورد عرشلاین';

    /**
     * نصب صفحه داشبورد هنگام فعال‌سازی افزونه
     */
    public static function install_dashboard_page() {
        // اگر صفحه قبلاً ساخته نشده باشد، ایجاد کن
        $page = get_page_by_path(self::DASHBOARD_SLUG);
        $template = 'src/Dashboard/dashboard-template.php';
        if (!$page) {
            $page_id = wp_insert_post([
                'post_title'   => self::DASHBOARD_TITLE,
                'post_name'    => self::DASHBOARD_SLUG,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_content' => '[arshline_dashboard]', // شورت‌کد placeholder
            ]);
            if ($page_id && !is_wp_error($page_id)) {
                update_post_meta($page_id, '_wp_page_template', $template);
                update_option('arshline_dashboard_page_id', $page_id);
            }
        } else {
            update_post_meta($page->ID, '_wp_page_template', $template);
            update_option('arshline_dashboard_page_id', $page->ID);
        }
    }

    /**
     * تنظیم صفحه داشبورد به عنوان صفحه نخست سایت (موقت)
     */
    public static function ensure_front_page() {
        $page_id = (int) get_option('arshline_dashboard_page_id');
        if ($page_id > 0) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $page_id);
            // برگه نوشته‌ها را خالی بگذاریم
            update_option('page_for_posts', 0);
        }
    }

    /**
     * ثبت شورت‌کد برای نمایش داشبورد مدرن
     */
    public static function register_shortcode() {
        add_shortcode('arshline_dashboard', [self::class, 'render_dashboard']);
    }

    /**
     * رندر محتوای داشبورد مدرن (placeholder)
     */
    public static function render_dashboard() {
        ob_start();
        echo '<div class="arshline-dashboard-modern" dir="rtl">';
        echo '<h1 style="font-size:2.2rem;color:#1a73e8;margin-bottom:1.5rem;">داشبورد مدرن عرشلاین</h1>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:2rem;">';
        echo self::placeholder_card('فرم‌ساز پیشرفته');
        echo self::placeholder_card('مدیریت پاسخ‌ها');
        echo self::placeholder_card('تحلیل و گزارش');
        echo self::placeholder_card('هوش مصنوعی');
        echo self::placeholder_card('پیامک و اعلان');
        echo self::placeholder_card('تنظیمات امنیتی');
        echo self::placeholder_card('API و وب‌هوک');
        echo self::placeholder_card('سازنده قالب و تم');
        echo '</div>';
        echo '<p style="margin-top:2rem;color:#888;">این داشبورد به صورت پویا و ماژولار توسعه می‌یابد و هر بخش به تدریج فعال خواهد شد.</p>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * کارت placeholder برای هر بخش
     */
    public static function placeholder_card($title) {
        return '<div style="background:#fff;border-radius:16px;box-shadow:0 2px 12px #0001;padding:2rem 1.5rem;min-width:220px;flex:1 1 220px;text-align:center;">'
            .'<span style="font-size:1.2rem;font-weight:600;color:#333;">'.$title.'</span>'
            .'<div style="margin:1rem 0;color:#bbb;">(در حال توسعه)</div>'
            .'</div>';
    }
}

<?php
/**
 * Template Name: Arshline Dashboard Fullscreen
 * Description: قالب اختصاصی و تمام‌صفحه برای داشبورد عرشلاین (بدون هدر و فوتر پوسته)
 */

// جلوگیری از بارگذاری مستقیم
if (!defined('ABSPATH')) exit;

// Block access for non-logged-in users or users without required capability
if (!is_user_logged_in() || !( current_user_can('edit_posts') || current_user_can('manage_options') )) {
    wp_redirect( wp_login_url( get_permalink() ) );
    exit;
}

?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>داشبورد عرشلاین</title>
    <link rel="icon" href="<?php echo esc_url( plugins_url('favicon.ico', dirname(__DIR__, 2).'/arshline.php') ); ?>" type="image/x-icon" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;600;700&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
</head>
<body class="arshline-dashboard">
<?php wp_body_open(); ?>
    <div class="arshline-dashboard-root">
            <aside class="arshline-sidebar">
                <div class="logo"><span class="label">عرشلاین</span></div>
                <button id="arSidebarToggle" class="toggle" aria-expanded="true" title="باز/بسته کردن منو"><span class="chev">❮</span></button>
                <nav>
                    <a href="#" data-tab="dashboard"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-10.5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="label">داشبورد</span></a>
                    <a href="#" data-tab="forms"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="1.6"/><path d="M7 8h10M7 12h10M7 16h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">فرم‌ها</span></a>
                    <a href="#" data-tab="submissions"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 4h16v16H4z" stroke="currentColor" stroke-width="1.6"/><path d="M8 9h8M8 13h8M8 17h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">پاسخ‌ها</span></a>
                    <a href="#" data-tab="reports"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4 20h16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><rect x="6" y="10" width="3" height="6" stroke="currentColor" stroke-width="1.6"/><rect x="11" y="7" width="3" height="9" stroke="currentColor" stroke-width="1.6"/><rect x="16" y="12" width="3" height="4" stroke="currentColor" stroke-width="1.6"/></svg></span><span class="label">گزارشات</span></a>
                    <a href="#" data-tab="users"><span class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 20a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span><span class="label">کاربران</span></a>
                </nav>
            </aside>
            <main class="arshline-main">
                <div class="arshline-header">
                    <div id="arHeaderActions"></div>
                    <div id="arThemeToggle" class="theme-toggle" role="switch" aria-checked="false" tabindex="0">
                        <span class="sun">☀</span>
                        <span class="moon">🌙</span>
                        <span class="knob"></span>
                    </div>
                </div>
                <div id="arshlineDashboardContent" class="view"></div>
            </main>
        </div>
    <?php wp_footer(); ?>
</body>
</html>

<?php
namespace Arshline\Core\Ai;

class Capabilities
{
    /**
     * Declarative capability map for Hoshyar.
     * kind: ui|navigation|forms|builder|results|users|settings|system
     * mutating: whether it changes data (requires confirmation safeguards)
     */
    public static function all(): array
    {
        return [
            [ 'id' => 'help', 'label' => 'نمایش کمک و قابلیت‌ها', 'intent' => 'help', 'kind' => 'system', 'mutating' => false ],
            [ 'id' => 'open_tab_dashboard', 'label' => 'باز کردن تب داشبورد', 'intent' => 'open_tab', 'params' => ['tab' => 'dashboard'], 'kind' => 'navigation', 'mutating' => false ],
            [ 'id' => 'open_tab_forms', 'label' => 'باز کردن تب فرم‌ها', 'intent' => 'open_tab', 'params' => ['tab' => 'forms'], 'kind' => 'navigation', 'mutating' => false ],
            [ 'id' => 'open_tab_reports', 'label' => 'باز کردن تب گزارشات', 'intent' => 'open_tab', 'params' => ['tab' => 'reports'], 'kind' => 'navigation', 'mutating' => false ],
            [ 'id' => 'open_tab_users', 'label' => 'باز کردن تب کاربران', 'intent' => 'open_tab', 'params' => ['tab' => 'users'], 'kind' => 'navigation', 'mutating' => false ],
            [ 'id' => 'open_tab_settings', 'label' => 'باز کردن تب تنظیمات', 'intent' => 'open_tab', 'params' => ['tab' => 'settings'], 'kind' => 'navigation', 'mutating' => false ],
            [ 'id' => 'toggle_theme', 'label' => 'تغییر تم (روشن/تاریک)', 'intent' => 'ui', 'params' => ['target' => 'toggle_theme'], 'kind' => 'ui', 'mutating' => false ],
            // Placeholders for future mutating ops (require confirmation)
            [ 'id' => 'form_create', 'label' => 'ایجاد فرم جدید', 'intent' => 'form_create', 'kind' => 'forms', 'mutating' => true ],
            [ 'id' => 'form_delete', 'label' => 'حذف فرم', 'intent' => 'form_delete', 'kind' => 'forms', 'mutating' => true ],
        ];
    }
}

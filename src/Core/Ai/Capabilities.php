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
            [ 'id' => 'open_form', 'label' => 'انتشار/فعال‌سازی فرم', 'intent' => 'open_form', 'params' => ['id' => 'number'], 'kind' => 'forms', 'mutating' => true ],
            [ 'id' => 'close_form', 'label' => 'بستن/غیرفعال کردن فرم', 'intent' => 'close_form', 'params' => ['id' => 'number'], 'kind' => 'forms', 'mutating' => true ],
            [ 'id' => 'draft_form', 'label' => 'بازگردانی به پیش‌نویس', 'intent' => 'draft_form', 'params' => ['id' => 'number'], 'kind' => 'forms', 'mutating' => true ],
            [ 'id' => 'update_form_title', 'label' => 'تغییر عنوان فرم', 'intent' => 'update_form_title', 'params' => ['id' => 'number', 'title' => 'string'], 'kind' => 'forms', 'mutating' => true ],
            [ 'id' => 'add_field_short_text', 'label' => 'افزودن سوال پاسخ کوتاه', 'intent' => 'add_field', 'params' => ['id' => 'number', 'type' => 'short_text'], 'kind' => 'builder', 'mutating' => true ],
            // User Groups (UG) capabilities
            [ 'id' => 'open_ug', 'label' => 'باز کردن «گروه‌های کاربری»', 'intent' => 'open_ug', 'params' => ['tab' => 'groups|members|fields'], 'kind' => 'users', 'mutating' => false ],
            [ 'id' => 'ug_create_group', 'label' => 'ایجاد گروه کاربری', 'intent' => 'ug_create_group', 'params' => ['name' => 'string', 'parent_id' => 'number?'], 'kind' => 'users', 'mutating' => true ],
            [ 'id' => 'ug_update_group', 'label' => 'ویرایش گروه کاربری', 'intent' => 'ug_update_group', 'params' => ['id' => 'number', 'name' => 'string?', 'parent_id' => 'number?'], 'kind' => 'users', 'mutating' => true ],
            [ 'id' => 'ug_set_form_access', 'label' => 'تعیین دسترسی گروه‌ها به فرم', 'intent' => 'ug_set_form_access', 'params' => ['form_id' => 'number', 'group_ids' => 'number[]'], 'kind' => 'users', 'mutating' => true ],
            [ 'id' => 'ug_ensure_tokens', 'label' => 'تولید توکن برای اعضای گروه', 'intent' => 'ug_ensure_tokens', 'params' => ['group_id' => 'number'], 'kind' => 'users', 'mutating' => true ],
            [ 'id' => 'ug_export_links', 'label' => 'خروجی لینک‌های اختصاصی', 'intent' => 'ug_export_links', 'params' => ['group_id' => 'number?', 'form_id' => 'number?'], 'kind' => 'users', 'mutating' => false ],
            [ 'id' => 'ug_download_members_template', 'label' => 'دانلود فایل نمونه اعضا (CSV)', 'intent' => 'ug_download_members_template', 'params' => ['group_id' => 'number'], 'kind' => 'users', 'mutating' => false ],
        ];
    }
}

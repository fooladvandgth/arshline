<?php
namespace Arshline\Core;

/**
 * Central registry for admin menu entries to allow Hoshiyar (voice/AI assistant) to open pages by natural commands.
 * Each menu has: slug, page_title, menu_title, capability, icon (if any), position, description,
 * and an array of command synonyms (fa/en) that can be matched loosely.
 */
class MenuRegistry
{
    /**
     * Return full menu definitions (static for now; can be extended later to auto-detect via global $menu / $submenu).
     * NOTE: Keep slugs in sync with add_menu_page registrations.
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'arshline-dashboard',
                'page_title' => 'داشبورد عرشلاین',
                'menu_title' => 'عرشلاین',
                'capability' => 'manage_options',
                'icon' => 'dashicons-admin-generic',
                'position' => 2,
                'description' => 'صفحه اصلی مدیریتی افزونه عرشلاین برای مشاهده نمای کلی و تنظیمات پایه.',
                'commands' => [
                    // Persian
                    'داشبورد عرشلاین','داشبورد','باز کردن داشبورد','صفحه اصلی عرشلاین','پنل عرشلاین',
                    // English
                    'arshline dashboard','open dashboard','show dashboard','main panel','arshline panel'
                ],
            ],
            [
                'slug' => 'arshline-user-groups',
                'page_title' => 'گروه‌های کاربری عرشلاین',
                'menu_title' => 'گروه‌های کاربری',
                'capability' => 'manage_options',
                'icon' => 'dashicons-groups',
                'position' => 3,
                'description' => 'مدیریت گروه‌های کاربری، اعضا و دسترسی فرم‌ها (در حال توسعه UI).',
                'commands' => [
                    'گروه های کاربری','گروه‌های کاربری','مدیریت گروه ها','باز کردن گروه ها','لیست گروه ها',
                    'user groups','groups','open user groups','manage groups','show groups'
                ],
            ],
            [
                'slug' => 'arshline-reports',
                'page_title' => 'گزارشات فرم‌ها',
                'menu_title' => 'گزارشات',
                'capability' => 'manage_options',
                'icon' => 'dashicons-chart-area',
                'position' => 4,
                'description' => 'نمایش نمودارها و گزارش‌های پایه ارسال‌های فرم‌ها.',
                'commands' => [
                    // Persian – reports ONLY (pure reporting)
                    'گزارشات','گزارش','آمار','آمار فرم ها','نمودار فرم','نمودارها','صفحه گزارشات',
                    // English
                    'reports','report','statistics','stats'
                ],
            ],
            [
                'slug' => 'arshline-analytics',
                'page_title' => 'تحلیل هوشمند (هوشنگ)',
                'menu_title' => 'تحلیل‌ها',
                'capability' => 'manage_options',
                'icon' => 'dashicons-analytics',
                'position' => 5,
                'description' => 'تحلیل هوشمند و ترکیبی داده‌های فرم‌ها با استفاده از هوشنگ.',
                'commands' => [
                    // Persian – analytics / AI layer
                    'تحلیل','تحلیل ها','تحلیل‌ها','تحلیلها','آنالیز','آنالیزها','آنالیز ها','تحلیل فرم ها','تحلیل فرم‌ها','تحلیل نتایج','تحلیل هوشمند','تحلیل هوشنگ','هوشنگ','هوشانالیز','آنالیز فرم ها','آنالیز داده ها','تحلیل ارسال ها','تحلیل پاسخ ها',
                    // English
                    'analytics','ai analytics','insights','form analytics','forms analytics','data analytics'
                ],
            ],
        ];
    }

    /**
     * Find a menu by slug.
     */
    public static function findBySlug(string $slug): ?array
    {
        foreach (self::all() as $m) { if ($m['slug'] === $slug) return $m; }
        return null;
    }

    /**
     * Perform a loose command match.
     * Strategy: normalize input (trim, lowercase, remove Arabic/Persian diacritics basic), then check if any command
     * synonym is contained OR equals. Could be upgraded to fuzzy scoring later.
     */
    public static function findByCommand(string $input): ?array
    {
        $norm = self::normalize($input);
        $best = null; $bestLen = 0;
        foreach (self::all() as $menu) {
            foreach ($menu['commands'] as $cmd) {
                $cNorm = self::normalize($cmd);
                if ($cNorm === $norm) { return $menu; }
                if (mb_strpos($norm, $cNorm) !== false || mb_strpos($cNorm, $norm) !== false) {
                    $len = mb_strlen($cNorm, 'UTF-8');
                    if ($len > $bestLen) { $best = $menu; $bestLen = $len; }
                }
            }
        }
        return $best;
    }

    protected static function normalize(string $s): string
    {
        $s = trim(mb_strtolower($s, 'UTF-8'));
        // Basic Persian half/space unify
        $repl = [
            'ي' => 'ی', 'ك' => 'ک', '‌' => ' ', 'ە' => 'ه'
        ];
        $s = strtr($s, $repl);
        // Collapse multiple spaces
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }
}

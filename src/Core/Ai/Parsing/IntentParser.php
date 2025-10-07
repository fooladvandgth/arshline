<?php
namespace Arshline\Core\Ai\Parsing;

/**
 * IntentParser: Lightweight Persian colloquial command parser for Hoshyar.
 * Goals:
 *  - Normalize input (trim, remove ZWNJ, unify spaces, lowercase)
 *  - Expand common colloquial variants via synonym replacement
 *  - Match against ordered pattern rules producing {action, params}
 *  - Provide confidence and remaining text for future disambiguation
 */
class IntentParser
{
    public static function parse(string $raw): array
    {
        $norm = self::normalize($raw);
        if ($norm === ''){
            return ['ok'=>false,'reason'=>'empty'];
        }
        // Direct theme detection first
        $theme = self::matchTheme($norm);
        if ($theme){ return $theme; }
        // Navigation detection
        $nav = self::matchNavigation($norm);
        if ($nav){ return $nav; }
        // Form related intents
        $form = self::matchForm($norm);
        if ($form){ return $form; }
        return ['ok'=>false,'reason'=>'no_match','normalized'=>$norm];
    }

    protected static function normalize(string $s): string
    {
        $s = preg_replace('/[\x{200c}\x{200d}\x{200f}]/u',' ', $s); // remove ZWNJ & directionals
        $s = trim(mb_strtolower($s));
        $s = preg_replace('/\s+/u',' ', $s);
        // colloquial shorteners
        $map = [
            'تمو'=>'تم', 'تم رو'=>'تم', 'حالتو'=>'حالت', 'فرمو'=>'فرم', 'فرم رو'=>'فرم', 'پوستۀ'=>'پوسته'
        ];
        $s = strtr($s, $map);
        return $s;
    }

    protected static function matchTheme(string $s): ?array
    {
        // Dark explicit
        // Dark modes (including تیره, شب, common typo تشب)
        if (preg_match('/\b(حالت|تم|پوسته)?\s*(?:خیلی\s*)?(تاریک|تیره|شب|تشب)\b/u', $s)){
            return ['ok'=>true,'action'=>'ui','target'=>'toggle_theme','mode'=>'dark','source'=>'parser'];
        }
        if (preg_match('/\b(حالت|تم|پوسته)?\s*(روشن|روز|سفید)\b/u', $s)){
            return ['ok'=>true,'action'=>'ui','target'=>'toggle_theme','mode'=>'light','source'=>'parser'];
        }
        if (preg_match('/^(?:تم|حالت)\s*رو?\s*(?:عوض|تغییر) کن$/u', $s)){
            return ['ok'=>true,'action'=>'ui','target'=>'toggle_theme','source'=>'parser'];
        }
        // Colloquial quick forms
        if (in_array($s, ['تم تاریک','تاریک کن','دارکش کن','تیره کن','تم تیره','حالت تیره','حالت تشب','تم شب'], true)){
            return ['ok'=>true,'action'=>'ui','target'=>'toggle_theme','mode'=>'dark','source'=>'parser'];
        }
        if (in_array($s, ['تم روشن','روشن کن','سفیدش کن','تم روز','حالت روز'], true)){
            return ['ok'=>true,'action'=>'ui','target'=>'toggle_theme','mode'=>'light','source'=>'parser'];
        }
        return null;
    }

    protected static function matchForm(string $s): ?array
    {
        // open builder: ویرایش فرم 12 / فرم 12 را باز کن
        if (preg_match('/(?:ویرایش|ادیت|باز کن|بازش کن) فرم (\d{1,6})/u', $s, $m)){
            return ['ok'=>true,'action'=>'open_builder','id'=>(int)$m[1],'source'=>'parser'];
        }
        if (preg_match('/نتایج فرم (\d{1,6})/u', $s, $m)){
            return ['ok'=>true,'action'=>'open_results','id'=>(int)$m[1],'source'=>'parser'];
        }
        if ($s === 'لیست فرم ها' || $s === 'لیست فرم‌ها' || $s === 'فرم ها' || $s==='فرم‌ها'){
            return ['ok'=>true,'action'=>'list_forms','source'=>'parser'];
        }
        return null;
    }

    protected static function matchNavigation(string $s): ?array
    {
        // forms tab
        if (preg_match('/^(?:برو\s*)?(?:منوی\s*)?فرم(?: ها|ها)?$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'forms','source'=>'parser'];
        }
        // users tab
        if (preg_match('/^(?:برو\s*)?کاربران$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'users','source'=>'parser'];
        }
        // groups (users/ug)
        if (preg_match('/^(?:برو\s*)?گروه(?: های|های)? کاربری$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'users/ug','source'=>'parser'];
        }
        // settings
        if (preg_match('/^(?:برو\s*)?(تنظیمات|ستینگ|کانفیگ)$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'settings','source'=>'parser'];
        }
        // analytics
        if (preg_match('/^(?:برو\s*)?(تحلیل ها?|آنالیز|آنالیتیکس|analytics)$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'analytics','source'=>'parser'];
        }
        // reports
        if (preg_match('/^(?:برو\s*)?(گزارش(?:ات| ها)?|ریپورت|reports?)$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'reports','source'=>'parser'];
        }
        // messaging
        if (preg_match('/^(?:برو\s*)?(پیام رسانی|پیام‌رسانی|پیامک|sms|اس ام اس)$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'messages','source'=>'parser'];
        }
        // hoosha / analytics builder phrases SHOULD open analytics tab (user request: "فرم ساز هوشا" => Hoosha analytics)
        if (preg_match('/^(?:برو\s*)?(فرم ساز هوشا|سازنده فرم هوشا|بیلدر هوشا|هوشا فرم ساز|هوشنگ|هوشا|تحلیل هوشمند)$/u', $s)){
            return ['ok'=>true,'action'=>'open_tab','tab'=>'analytics','source'=>'parser'];
        }
        return null;
    }
}

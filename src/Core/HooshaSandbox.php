<?php
namespace Arshline\Core;

// Lightweight sandboxed extractor independent of full WP runtime.
// Focus: split user text lines into candidate fields, infer simple types, merge duplicates,
// apply DOB/day-of-birth consolidation, then (if GuardService exists) run guard to finalize.

class HooshaSandbox
{
    /** Provide minimal WP shims if not running inside WordPress. */
    protected static function ensureWpShims(): void {
        if (!function_exists('wp_upload_dir')){
            function wp_upload_dir(){ return ['basedir'=> sys_get_temp_dir() . '/arshline_uploads']; }
        }
        if (!function_exists('wp_json_encode')){
            function wp_json_encode($data,$options=0,$depth=512){ return json_encode($data,$options|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES,$depth); }
        }
        if (!function_exists('is_wp_error')){ function is_wp_error($v){ return false; } }
    }
    /** Extract raw candidate lines from multi-line Persian text. */
    protected static function lines(string $text): array {
        $text = str_replace(["\r"], "", $text);
        $parts = preg_split('/\n+/', $text); 
        $out = [];
        foreach ($parts as $p){
            $p = trim($p);
            if ($p === '' || mb_strlen($p,'UTF-8') < 2) continue;
            // Remove trailing punctuation markers often in questions
            $p = preg_replace('/[؟?]+$/u','', $p);
            $out[] = $p;
        }
        return $out;
    }

    /** Basic heuristic type inference (subset of full pipeline). */
    protected static function infer_type(string $label): string {
        $l = mb_strtolower($label,'UTF-8');
        if (preg_match('/کد\s*ملی|شماره\s*ملی/u',$l)) return 'national_id';
        if (preg_match('/ایمیل/u',$l)) return 'email';
        if (preg_match('/حقوق|درآمد|دستمزد/u',$l)) return 'currency';
        if (preg_match('/چند\s*روز|در\s*هفته.*روز/u',$l)) return 'number';
        if (preg_match('/رنگ.*(سبز|آبی|قرمز)/u',$l)) return 'select';
        if (preg_match('/بیمه|فعال کرده/u',$l)) return 'yesno';
        if (preg_match('/منطقه/u',$l)) return 'text';
        if (preg_match('/تاریخ\s*تولد/u',$l)) return 'date';
        if (preg_match('/روز\s*تولد/u',$l)) return 'day_of_month';
        return 'text';
    }

    /** Build initial candidate fields. */
    protected static function build_candidates(array $lines): array {
        $fields = [];
        $i=0;
        foreach ($lines as $ln){
            $i++;
            $type = self::infer_type($ln);
            $field = [
                'id' => 'f'.$i,
                'label' => $ln,
                'type' => $type,
            ];
            if ($type === 'select' && preg_match('/(سبز|آبی|قرمز)/u',$ln)){
                // Extract known colors
                $opts = [];
                foreach (['سبز','آبی','قرمز'] as $c){ if (mb_strpos($ln,$c) !== false) $opts[] = $c; }
                if ($opts){ $field['options'] = array_values(array_unique($opts)); }
            }
            if ($type === 'yesno'){ $field['options'] = ['بله','خیر']; }
            $fields[] = $field;
        }
        return $fields;
    }

    /** Merge obvious duplicates by canonical hash of normalized label keywords. */
    protected static function collapse_duplicates(array $fields): array {
        $seen = [];$out=[];
        $normalize = function(string $lbl): string {
            $lbl = mb_strtolower($lbl,'UTF-8');
            $lbl = preg_replace('/[\s[:punct:]،\.\?]+/u','', $lbl);
            // synonym normalization
            $map = [
                'شمارهملی' => 'کدملی',
                'کدملیخودرابنویسید' => 'کدملی',
                'شمارهملیراثبتکنید' => 'کدملی',
                'ایمیلکار' => 'ایمیلکاری',
                'ادرسایمیل' => 'ایمیلکاری',
            ];
            foreach ($map as $k=>$v){ $lbl = str_replace($k,$v,$lbl); }
            return $lbl;
        };
        foreach ($fields as $f){
            $canon = $normalize($f['label']);
            if (isset($seen[$canon])){
                $idx = $seen[$canon];
                if (isset($f['options'])){
                    $prior = $out[$idx]['options'] ?? [];
                    if (is_array($f['options'])){
                        $out[$idx]['options'] = array_values(array_unique(array_merge($prior,$f['options'])));
                    }
                }
                // prefer specific type over generic text
                if (($out[$idx]['type']??'')==='text' && ($f['type']??'')!=='text'){
                    $out[$idx]['type']=$f['type'];
                }
                continue;
            }
            $seen[$canon]=count($out);
            $out[]=$f;
        }
        return $out;
    }

    /** Consolidate day-of-birth into date-of-birth if both appear. */
    protected static function merge_dob_day(array $fields): array {
        $dobIndex = null; $dayIndexes = [];
        foreach ($fields as $i=>$f){
            $lbl = $f['label']; $low = mb_strtolower($lbl,'UTF-8');
            if ($dobIndex === null && preg_match('/تاریخ\s*تولد/u',$low)) $dobIndex = $i;
            if (preg_match('/روز\s*تولد/u',$low)) $dayIndexes[] = $i;
        }
        if ($dobIndex !== null && $dayIndexes){
            // Remove all day-only fields
            $new = [];
            foreach ($fields as $i=>$f){ if (!in_array($i,$dayIndexes,true)) $new[]=$f; }
            // Optionally add note or tag
            $new[$dobIndex]['notes'][] = 'sandbox:merged_day_of_birth('.count($dayIndexes).')';
            return array_values($new);
        }
        return $fields;
    }

    /** Public entry: produce final schema-esque array with optional Guard. */
    public static function process(string $text, bool $runGuard = true): array {
        self::ensureWpShims();
        $lines = self::lines($text);
        $candidates = self::build_candidates($lines);
        $collapsed1 = self::collapse_duplicates($candidates);
        $merged = self::merge_dob_day($collapsed1);

        // Additional semantic merges (sport days, salary, email, insurance, region, color text versus select)
        $semanticGroups = [
            'sport_days' => ['/در\s*هفته.*روز\s*ورزش/','/چند\s*روز.*فعالیت\s*ورزشی/'],
            'salary' => ['/حقوق/','/میانگین\s*حقوق/'],
            'insurance' => ['/بیمه\s*خودرو/','/بیمه\s*ماشین/'],
            'email' => ['/ایمیل\s*کاری/','/ایمیل\s*محل\s*کار/'],
            'region' => ['/منطقه\s*محل\s*سکونت/','/منطقه\s*زندگی/'],
            'color' => ['/رنگ\s*مورد\s*علاقه/','/رنگ\s*محبوب.*سبز/']
        ];
        $final = [];
        $groupChosen = [];
        foreach ($merged as $f){
            $lblLow = mb_strtolower($f['label'],'UTF-8');
            $assigned = false;
            foreach ($semanticGroups as $key=>$patterns){
                foreach ($patterns as $pat){
                    if (preg_match($pat.'u',$lblLow)){
                        if (!isset($groupChosen[$key])){
                            // first wins; if color group and this is text while later there is a select, we adjust later
                            $groupChosen[$key] = $f; 
                        } else {
                            // merge options or prefer select
                            if (($key==='color') && isset($f['options']) && is_array($f['options'])){
                                $prior = $groupChosen[$key]['options'] ?? [];
                                $groupChosen[$key]['options'] = array_values(array_unique(array_merge($prior,$f['options'])));
                                if (($groupChosen[$key]['type']??'')!=='select'){ $groupChosen[$key]['type']='select'; }
                            }
                        }
                        $assigned = true; break 2;
                    }
                }
            }
            if (!$assigned){ $final[] = $f; }
        }
        // Append consolidated groups
        foreach ($groupChosen as $gk=>$gf){ $final[] = $gf; }

        $guardData = null; $finalFields = $final; $notes = [];
        if ($runGuard && class_exists('Arshline\\Guard\\GuardService')){
            try {
                $guard = new \Arshline\Guard\GuardService();
                // Guard expects (baseline, schema, userText, notes)
                $eval = $guard->evaluate(['fields'=>$candidates], ['fields'=>$finalFields], $text, []);
                if (is_array($eval) && isset($eval['fields']['fields'])){
                    $finalFields = $eval['fields']['fields'];
                }
                $guardData = $eval;
                $notes[]='sandbox:guard_applied';
            } catch (\Throwable $e){ $notes[]='sandbox:guard_error'; }
        } else {
            $notes[]='sandbox:guard_skipped';
        }
        return [ 'schema'=>['fields'=>$finalFields], 'guard'=>$guardData, 'notes'=>$notes ];
    }
}

<?php
namespace Arshline\Guard;

/**
 * CapabilityScanner: dynamically extracts supported field types, formats, and special rules
 * from core plugin source files (Api.php, FormValidator.php, form-template.php, etc.).
 * Result is cached statically (per-request) and can optionally be persisted via a transient (if WP available).
 * Emits a guard capability_scan log event (GuardLogger) with summary counts.
 */
class CapabilityScanner
{
    protected static ?array $cache = null; // in-process cache
    const TRANSIENT_KEY = 'arshline_guard_capabilities_v1';
    const TRANSIENT_TTL = 3600; // 1h

    /** Get unified capability map: ['types'=>[], 'formats'=>[], 'rules'=>[]] */
    public static function get(): array
    {
        if (self::$cache !== null) return self::$cache;
        // Try transient
        if (function_exists('get_transient')) {
            $t = get_transient(self::TRANSIENT_KEY);
            if (is_array($t) && isset($t['types'],$t['formats'],$t['rules'])) {
                self::$cache = $t;
                return $t;
            }
        }
        $cap = self::scanSources();
        self::$cache = $cap;
        if (function_exists('set_transient')) {
            set_transient(self::TRANSIENT_KEY, $cap, self::TRANSIENT_TTL);
        }
        if (class_exists('Arshline\\Guard\\GuardLogger')) {
            GuardLogger::phase('capability_scan', [
                'types'=>count($cap['types']),
                'formats'=>count($cap['formats']),
                'rules'=>count($cap['rules']),
            ]);
        }
        return $cap;
    }

    /** Force refresh (bypasses cache) */
    public static function refresh(): array
    {
        self::$cache = null; return self::get();
    }

    /* ---------------- Internal scanning ---------------- */
    protected static function scanSources(): array
    {
        $root = dirname(__DIR__,2); // go up from Guard/ to plugin root
        $sources = [
            $root . '/Core/Api.php',
            $root . '/Modules/Forms/FormValidator.php',
            $root . '/Frontend/form-template.php',
            $root . '/Guard/GuardService.php',
            $root . '/Hoosha/Pipeline/OpenAIModelClient.php',
        ];
        $types = [];
        $formats = [];
        $rules = [];

        foreach ($sources as $file){
            if (!is_file($file)) continue;
            $content = @file_get_contents($file);
            if ($content === false) continue;
            self::extractFromContent($content, $types, $formats, $rules);
        }

        // Normalize & sort
        $types = array_values(array_unique($types)); sort($types);
        $formats = array_values(array_unique($formats)); sort($formats);
        $rules = array_values(array_unique($rules)); sort($rules);

        return [ 'types'=>$types, 'formats'=>$formats, 'rules'=>$rules ];
    }

    /** Extract tokens from content heuristically */
    protected static function extractFromContent(string $code, array &$types, array &$formats, array &$rules): void
    {
        // 1. Regex simple arrays: ['short_text','long_text',...] or [ 'free_text'=>1, 'email'=>1 ]
        if (preg_match_all('/\[(?:[^\]]*?)\]/s', $code, $blocks)){
            foreach ($blocks[0] as $blk){
                // Match quoted tokens
                if (preg_match_all('/["\']([A-Za-z0-9_]+)["\']\s*=>?/', $blk, $tok1)){
                    foreach ($tok1[1] as $tk){ self::classifyToken($tk,$types,$formats,$rules); }
                }
                if (preg_match_all('/["\']([A-Za-z0-9_]+)["\']\s*,/',$blk,$tok2)){
                    foreach ($tok2[1] as $tk){ self::classifyToken($tk,$types,$formats,$rules); }
                }
            }
        }
        // 2. Switch/case patterns (case 'national_id_ir':)
        if (preg_match_all('/case\s+["\']([A-Za-z0-9_]+)["\']\s*:/', $code, $cases)){
            foreach ($cases[1] as $tk){ self::classifyToken($tk,$types,$formats,$rules); }
        }
        // 3. Direct format assignments -> 'props']['format']='token'
        if (preg_match_all('/format\'\]?\s*=\s*["\']([A-Za-z0-9_]+)["\']/', $code, $fmtAssign)){
            foreach ($fmtAssign[1] as $tk){ self::classifyToken($tk,$types,$formats,$rules, true); }
        }
    }

    protected static function classifyToken(string $tk, array &$types, array &$formats, array &$rules, bool $forceFormat=false): void
    {
        $t = strtolower($tk);
        // Heuristic classification
        $typeHints = ['short_text','long_text','multiple_choice','dropdown','rating','number','date'];
        $formatHints = ['email','numeric','mobile_ir','mobile_intl','tel','national_id_ir','postal_code_ir','fa_letters','en_letters','ip','time','date_jalali','date_greg','regex','free_text','sheba_ir','credit_card_ir','national_id_company_ir','alphanumeric','alphanumeric_no_space','alphanumeric_extended','file_upload','captcha_alphanumeric'];
        $ruleHints = ['confirm','required','multiple','options','min','max','rows'];
        if ($forceFormat || in_array($t,$formatHints,true)) { $formats[]=$t; return; }
        if (in_array($t,$typeHints,true)) { $types[]=$t; return; }
        if (in_array($t,$ruleHints,true)) { $rules[]=$t; return; }
        // Fallback heuristic: tokens ending with _ir or starting with date_ often formats
        if (preg_match('/(_ir$|^date_|^time$|_code_|_id_)/',$t)){ $formats[]=$t; }
    }
}

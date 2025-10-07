<?php
namespace Arshline\Guard;

/**
 * GuardLogger: Field-level & phase-level NDJSON logger for the Guard pipeline (GuardService / GuardUnit / AI-Assist).
 * Activation:
 *  - Constant: define('HOOSHA_GUARD_DEBUG', true)
 *  - Option (array arshline_settings): guard_debug => truthy
 * File Location Logic (highest wins):
 *  - Option: arshline_guard_log_path (exact path)
 *  - WP_CONTENT_DIR/uploads/guard.log
 *  - sys_get_temp_dir()/guard.log (fallback)
 * Rotation: naive size-based (10MB) -> guard.log.<timestamp>
 * Redaction: basic sensitive key masking.
 */
class GuardLogger
{
    const OPT_PATH = 'arshline_guard_log_path';
    const OPT_SETTINGS = 'arshline_settings';
    const DEFAULT_FILENAME = 'guard.log';
    const MAX_BYTES = 10485760; // 10MB

    /** Quick enable check */
    public static function enabled(): bool
    {
        if (defined('HOOSHA_GUARD_DEBUG') && HOOSHA_GUARD_DEBUG) return true;
        if (function_exists('get_option')) {
            $settings = get_option(self::OPT_SETTINGS, []);
            if (is_array($settings) && !empty($settings['guard_debug'])) return true;
        }
        return false;
    }

    /** Generic log writer */
    public static function log(array $entry): bool
    {
        if (!self::enabled()) return false;
        $path = self::resolvePath();
        self::ensureDir(dirname($path));
        self::rotateIfNeeded($path);
        $entry['ts'] = $entry['ts'] ?? gmdate('c');
        $entry = self::redact($entry);
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $fp = @fopen($path, 'a');
        if (!$fp) return false;
        $ok=false;
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $json."\n");
                fflush($fp);
                flock($fp, LOCK_UN);
                $ok=true;
            }
        } finally { fclose($fp); }
        return $ok;
    }

    /** Convenience: phase event */
    public static function phase(string $phase, array $extra = []): void
    {
        self::log(['ev'=>'phase','phase'=>$phase] + $extra);
    }

    /** Convenience: field decision */
    public static function fieldDecision(array $data): void
    {
        // Expected keys: field_idx, action, reason?, score?, question_idx?, label?
        $base = ['ev'=>'field'];
        self::log($base + $data);
    }

    /** Convenience: summary */
    public static function summary(array $metrics, array $issues = [], array $notes = [], ?string $requestId = null): void
    {
        $payload = [
            'ev'=>'summary',
            'metrics'=>$metrics,
            'issues'=>$issues,
            'notes'=>$notes,
        ];
        if ($requestId) $payload['request_id']=$requestId;
        self::log($payload);
    }

    /** Convenience: AI phase event (ai_analysis / ai_reasoning / ai_validation) */
    public static function ai(string $kind, array $data = []): void
    {
        self::log(['ev'=>$kind] + $data);
    }

    /* ---------------- Internals ---------------- */
    protected static function resolvePath(): string
    {
        $path = null;
        if (function_exists('get_option')) {
            $opt = get_option(self::OPT_PATH, '');
            if ($opt) $path = $opt;
        }
        if (!$path) {
            if (defined('WP_CONTENT_DIR')) {
                $path = rtrim(WP_CONTENT_DIR,'/\\') . '/uploads/' . self::DEFAULT_FILENAME;
            } else {
                $path = rtrim(sys_get_temp_dir(),'/\\') . '/' . self::DEFAULT_FILENAME;
            }
        }
        return $path;
    }

    protected static function ensureDir(string $dir): void
    {
        if ($dir && !is_dir($dir)) @mkdir($dir, 0755, true);
    }

    protected static function rotateIfNeeded(string $path): void
    {
        if (!file_exists($path)) return;
        if (filesize($path) > self::MAX_BYTES) {
            @rename($path, $path.'.'.time());
        }
    }

    protected static function redact(array $data): array
    {
        $sensitive = ['api_key','authorization','access_token','token','password','secret','openai_api_key'];
        $mask = '<<<REDACTED>>>';
        $walker = function($val) use (&$walker,$sensitive,$mask){
            if (is_array($val)) {
                $out=[]; foreach ($val as $k=>$v){ $lk=strtolower((string)$k); if (in_array($lk,$sensitive,true)){ $out[$k]=$mask; continue; } $out[$k]=is_array($v)?$walker($v):$v; } return $out;
            }
            return $val;
        };
        return $walker($data);
    }
}

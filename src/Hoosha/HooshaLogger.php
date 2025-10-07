<?php
namespace Arshline\Hoosha;

/**
 * Hoosha Logger: newline-delimited JSON (ndjson) logger for model interactions.
 * Features: enable flag via constant/option, rotation, redaction of sensitive keys, file locking.
 */
class HooshaLogger
{
    const OPTION_ENABLED = 'arshline_hoosha_log_enabled';
    const OPTION_PATH = 'arshline_hoosha_log_path';
    const DEFAULT_MAX_BYTES = 10485760; // 10MB
    const DEFAULT_FILENAME = 'hoosha.log';

    /** Determine if logging is enabled */
    public static function enabled(): bool
    {
        if (defined('ARSHLINE_HOOSHA_LOG_ENABLED')) {
            return (bool) ARSHLINE_HOOSHA_LOG_ENABLED;
        }
        if (function_exists('get_option')) {
            return (bool) get_option(self::OPTION_ENABLED, false);
        }
        return false;
    }

    /** Log entry (array) as one-line JSON. Returns true on success. */
    public static function log(array $entry): bool
    {
        if (!self::enabled()) return false;
        $path = self::resolvePath();
        self::ensureDir(dirname($path));
        self::rotateIfNeeded($path);
        $entry['ts'] = $entry['ts'] ?? gmdate('c');
        $entry = self::redact($entry);
        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = json_encode(['ts'=>gmdate('c'),'note'=>'encode_failed'], JSON_UNESCAPED_UNICODE);
        }
        $fp = @fopen($path, 'a');
        if (!$fp) return false;
        $ok = false;
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $json . "\n");
                fflush($fp);
                flock($fp, LOCK_UN);
                $ok = true;
            }
        } finally {
            fclose($fp);
        }
        return $ok;
    }

    /* ---------------- Internal helpers ---------------- */

    protected static function resolvePath(): string
    {
        $base = null;
        if (function_exists('get_option')) {
            $base = get_option(self::OPTION_PATH, '');
        }
        if (!$base) {
            // Prefer WP_CONTENT_DIR/uploads or fallback to sys_get_temp_dir
            if (defined('WP_CONTENT_DIR')) {
                $uploads = WP_CONTENT_DIR . '/uploads';
                $base = $uploads . '/' . self::DEFAULT_FILENAME;
            } else {
                $base = rtrim(sys_get_temp_dir(), '/\\') . '/' . self::DEFAULT_FILENAME;
            }
        }
        return $base;
    }

    protected static function ensureDir(string $dir): void
    {
        if ($dir === '' || is_dir($dir)) return;
        @mkdir($dir, 0755, true);
    }

    protected static function rotateIfNeeded(string $path): void
    {
        if (!file_exists($path)) return;
        $maxBytes = self::DEFAULT_MAX_BYTES;
        if (filesize($path) > $maxBytes) {
            $rotated = $path . '.' . time();
            @rename($path, $rotated);
        }
    }

    protected static function redact(array $data): array
    {
        $sensitive = ['api_key','authorization','access_token','token','password','secret','openai_api_key'];
        $mask = '<<<REDACTED>>>';
        $walker = function($value) use (&$walker, $sensitive, $mask) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k=>$v) {
                    $lk = strtolower((string)$k);
                    if (in_array($lk, $sensitive, true)) { $out[$k] = $mask; continue; }
                    if (is_array($v)) { $out[$k] = $walker($v); continue; }
                    if (is_string($v)) {
                        // redact bearer tokens
                        // Normalize & redact Bearer tokens (use # delimiter to avoid escaping '/')
                        if (preg_match('#Bearer\s+[A-Za-z0-9._~+/+=-]+#i', $v)) {
                            $v = preg_replace('#Bearer\s+[A-Za-z0-9._~+/+=-]+#i', 'Bearer ' . $mask, $v);
                        }
                        // redact long base64/token-like
                        if (strlen($v) > 120 && preg_match('#[A-Za-z0-9_-]{40,}#', $v)) {
                            $v = substr($v,0,10).'...'.$mask;
                        }
                        // key=value style
                        $v = preg_replace('#(api_key|access_token|openai_api_key)=[A-Za-z0-9_-]{8,}#i', '$1='.$mask, $v);
                    }
                    $out[$k] = $v;
                }
                return $out;
            }
            return $value;
        };
        return $walker($data);
    }
}

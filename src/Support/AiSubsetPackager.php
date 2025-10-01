<?php
namespace Arshline\Support;

/**
 * AiSubsetPackager
 *
 * Provides helper methods to build sanitized, size-limited subsets of tabular data
 * for AI prompts. This is a scaffolding-only class (not yet wired into flows).
 */
class AiSubsetPackager
{
    /**
     * Return a column whitelist for a given high-level intent.
     * Example intents: person_mood, contact_info, compare_trend, generic.
     */
    public static function columnWhitelist(string $intent, bool $allowPII = false): array
    {
        $intent = strtolower(trim($intent));
        $base = ['name', 'created_at'];
        switch ($intent) {
            case 'person_mood':
                $cols = array_merge($base, ['mood_text', 'mood_score']);
                break;
            case 'compare_trend':
                $cols = array_merge($base, ['mood_text', 'mood_score']);
                break;
            case 'contact_info':
                $cols = array_merge($base, ['phone', 'mobile', 'email']);
                break;
            default:
                $cols = $base;
        }
        if (!$allowPII) {
            $cols = array_values(array_filter($cols, function ($c) {
                return !in_array($c, ['phone', 'mobile', 'email'], true);
            }));
        }
        return array_values(array_unique($cols));
    }

    /**
     * Simple PII masking for phone/email-like fields. Extend as needed.
     */
    public static function maskPII($value, string $field)
    {
        $v = is_scalar($value) ? (string)$value : '';
        if ($v === '') return $v;
        $f = strtolower($field);
        if (in_array($f, ['phone', 'mobile'], true)) {
            // keep last 4 digits
            $digits = preg_replace('/\D+/', '', $v);
            $last4 = substr($digits, -4);
            return '***-***-' . $last4;
        }
        if ($f === 'email') {
            $parts = explode('@', $v, 2);
            $user = $parts[0] ?? '';
            $dom = $parts[1] ?? '';
            if ($user !== '') $user = substr($user, 0, 1) . '***';
            return $user . ($dom ? '@' . $dom : '');
        }
        return $v;
    }

    /**
     * Select columns, apply caps, and mask PII if needed. Rows are arrays with string keys.
     * Options: [ 'max_rows' => int, 'allow_pii' => bool ]
     */
    public static function sanitizeRows(array $rows, array $columns, array $options = []): array
    {
        $max = isset($options['max_rows']) && is_numeric($options['max_rows']) ? max(1, (int)$options['max_rows']) : 400;
        $allowPII = !empty($options['allow_pii']);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $item = [];
            foreach ($columns as $c) {
                $val = $r[$c] ?? null;
                if (!$allowPII && in_array($c, ['phone', 'mobile', 'email'], true)) {
                    $val = self::maskPII($val, $c);
                }
                $item[$c] = is_scalar($val) ? (string)$val : (is_null($val) ? null : json_encode($val, JSON_UNESCAPED_UNICODE));
            }
            $out[] = $item;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    /**
     * Package data for model consumption. Caller will embed into the prompt.
     * This only returns a structured array; it does not perform any HTTP.
     */
    public static function packageForModel(string $question, array $rows, array $columns, array $meta = []): array
    {
        return [
            'question' => $question,
            'columns' => array_values($columns),
            'rows' => array_values($rows),
            'meta' => $meta,
        ];
    }
}

<?php
namespace Arshline\Hosha2;

/**
 * Redacts sensitive keys/values in log payloads.
 * Strategy:
 *  - Keys matching sensitive patterns are replaced entirely with '***'.
 *  - Values that look like emails / bearer tokens / api keys are masked.
 */
class Hosha2LogRedactor
{
    /** @var string[] */
    protected array $sensitiveKeys = [
        'api_key','openai_api_key','secret','token','auth','password','pwd','authorization','phone','mobile','email'
    ];

    /**
     * Redact payload recursively.
     */
    public function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->redact($v);
                continue;
            }
            $lk = strtolower((string)$k);
            if (in_array($lk, $this->sensitiveKeys, true)) {
                $data[$k] = '***';
                continue;
            }
            if (is_string($v)) {
                // Email like
                if (preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i', $v)) {
                    $data[$k] = $this->maskCenter($v);
                    continue;
                }
                // Bearer / token like long base64/hex strings
                if (preg_match('/^(sk-[A-Za-z0-9]{20,}|[A-Fa-f0-9]{32,}|[A-Za-z0-9+\/=]{40,})$/', $v)) {
                    $data[$k] = $this->maskCenter($v);
                    continue;
                }
                // Phone (basic) – mask middle digits
                if (preg_match('/^\+?\d{7,15}$/', $v)) {
                    $data[$k] = $this->maskDigits($v);
                    continue;
                }
            }
        }
        return $data;
    }

    protected function maskCenter(string $value): string
    {
        $len = strlen($value);
        if ($len <= 6) return '***';
        $keep = (int)floor($len * 0.25);
        return substr($value, 0, $keep) . '***' . substr($value, -$keep);
    }

    protected function maskDigits(string $value): string
    {
        return preg_replace('/(\d)(?=\d{4})/', '*', $value);
    }
}
?>
<?php
namespace Arshline\Hosha2;

use InvalidArgumentException;

class Hosha2RateLimiter
{
    private ?Hosha2LoggerInterface $logger;
    private int $maxRequests;
    private int $windowSeconds;
    private string $transientKey = 'hosha2_rate_limit_window';

    public function __construct(?Hosha2LoggerInterface $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->maxRequests = isset($config['max_requests']) ? (int)$config['max_requests'] : 10;
        $this->windowSeconds = isset($config['window']) ? (int)$config['window'] : 60;
        if ($this->maxRequests <= 0) throw new InvalidArgumentException('max_requests must be > 0');
        if ($this->windowSeconds <= 0) throw new InvalidArgumentException('window must be > 0');
    }

    /**
     * Check if new request is allowed under current sliding window.
     */
    public function isAllowed(string $requestId): bool
    {
        $entries = $this->loadEntries();
        $entries = $this->cleanupOldEntries($entries);
        $currentCount = count($entries);
        $allowed = $currentCount < $this->maxRequests;
        $payload = [
            'req_id' => $requestId,
            'allowed' => $allowed,
            'current_count' => $currentCount,
            'max_allowed' => $this->maxRequests,
            'window_seconds' => $this->windowSeconds,
        ];
        if ($this->logger) {
            $this->logger->log('rate_limit_check', $payload, $allowed ? 'INFO' : 'WARN');
            if (!$allowed) {
                $this->logger->log('rate_limit_exceeded', $payload, 'WARN');
            }
        }
        // Persist cleaned entries (without adding new) to avoid growth
        $this->storeEntries($entries);
        return $allowed;
    }

    /**
     * Record a newly accepted request.
     */
    public function recordRequest(string $requestId): void
    {
        $entries = $this->loadEntries();
        $entries = $this->cleanupOldEntries($entries);
        $entries[] = [
            'timestamp' => $this->now(),
            'req_id' => $requestId,
        ];
        $this->storeEntries($entries);
    }

    // ---- Internal helpers ----
    protected function now(): int { return time(); }

    private function loadEntries(): array
    {
        if (!function_exists('get_transient')) return [];
        $raw = get_transient($this->transientKey);
        if (!is_array($raw)) return [];
        return $raw;
    }

    private function storeEntries(array $entries): void
    {
        if (!function_exists('set_transient')) return; // test env w/out WP
        // TTL: window + 60s buffer
        set_transient($this->transientKey, $entries, $this->windowSeconds + 60);
    }

    private function cleanupOldEntries(array $entries): array
    {
        $cutoff = $this->now() - $this->windowSeconds;
        $filtered = [];
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $ts = $e['timestamp'] ?? 0;
            if ($ts >= $cutoff) $filtered[] = $e;
        }
        return $filtered;
    }
}
?>
<?php
namespace Arshline\Hosha2;

/**
 * Hosha2 Logger Interface
 * NDJSON oriented logging with lightweight rotation + redaction.
 */
interface Hosha2LoggerInterface
{
    /**
     * Generic event logger.
     * Implementations MUST write one JSON object per line (UTF-8, no pretty print).
     * @param string $event Event kind identifier (e.g. phase, summary, error, debug)
     * @param array $payload Arbitrary structured data (will be redacted before write)
     */
    public function log(string $event, array $payload = [], string $level = 'INFO'): void;

    /**
     * Convenience phase marker.
     */
    public function phase(string $phase, array $extra = [], string $level = 'INFO'): void;

    /**
     * Summary emitter (final envelope for a run context).
     */
    public function summary(array $metrics, array $issues = [], array $notes = []): void;

    /**
     * Set a contextual correlation id (request id / run id) applied automatically to subsequent entries.
     */
    public function setContext(array $context): void;

    /**
     * Force rotation check (size-based) and rotate if needed.
     */
    public function rotateIfNeeded(): void;
}
?>
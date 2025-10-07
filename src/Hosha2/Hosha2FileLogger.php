<?php
namespace Arshline\Hosha2;

use DateTimeImmutable;

class Hosha2FileLogger implements Hosha2LoggerInterface
{
    protected string $logDir;
    protected string $logFile;
    protected int $maxBytes;
    protected array $context = [];
    protected Hosha2LogRedactor $redactor;
    protected bool $enabled = true;

    public function __construct(string $logDir, string $filename = 'hooshyar2-log.txt', int $maxBytes = 5242880, ?Hosha2LogRedactor $redactor = null)
    {
        $this->logDir = rtrim($logDir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
        $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . $filename;
        $this->maxBytes = $maxBytes; // default ~5MB
        $this->redactor = $redactor ?: new Hosha2LogRedactor();
    }

    public function setEnabled(bool $enabled): void { $this->enabled = $enabled; }

    public function setContext(array $context): void
    {
        // Merge so later setContext can add fields
        $this->context = array_merge($this->context, $context);
    }

    public function log(string $event, array $payload = [], string $level = 'INFO'): void
    {
        if (!$this->enabled) return;
        $this->rotateIfNeeded();
        $record = [
            'ts' => (new DateTimeImmutable())->format('c'),
            'ev' => $event,
            'lvl' => strtoupper($level),
        ] + $this->context + $payload;
        $record = $this->redactor->redact($record);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return; // silently skip on encoding error
        }
        $fh = @fopen($this->logFile, 'ab');
        if (!$fh) return;
        // Acquire shared exclusive lock during write
        if (function_exists('flock')) @flock($fh, LOCK_EX);
        @fwrite($fh, $json . "\n");
        if (function_exists('flock')) @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    public function phase(string $phase, array $extra = [], string $level = 'INFO'): void
    {
        $this->log('phase', ['phase' => $phase] + $extra, $level);
    }

    public function summary(array $metrics, array $issues = [], array $notes = []): void
    {
        $this->log('summary', [
            'metrics' => $metrics,
            'issues' => $issues,
            'notes' => $notes,
        ], 'INFO');
    }

    public function rotateIfNeeded(): void
    {
        if (!$this->enabled) return;
        clearstatcache(true, $this->logFile);
        if (file_exists($this->logFile) && filesize($this->logFile) >= $this->maxBytes) {
            $suffix = date('Ymd_His');
            $rotated = $this->logFile . '.' . $suffix;
            @rename($this->logFile, $rotated);
            // Optionally prune old rotated logs (keep last 5)
            $this->pruneOld();
        }
    }

    protected function pruneOld(int $keep = 5): void
    {
        $pattern = basename($this->logFile) . '.'; // prefix
        $files = @scandir($this->logDir);
        if (!$files) return;
        $rotated = [];
        foreach ($files as $f) {
            if (strpos($f, $pattern) === 0) {
                $rotated[] = $this->logDir . DIRECTORY_SEPARATOR . $f;
            }
        }
        if (count($rotated) <= $keep) return;
        usort($rotated, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
        $excess = array_slice($rotated, $keep);
        foreach ($excess as $del) @unlink($del);
    }
}
?>
<?php
namespace Arshline\Hosha2;

class Hosha2ProgressTracker
{
    protected string $requestId;
    protected array $weights = [
        'analyze_input' => 0.10,
        'build_capabilities' => 0.15,
        'openai_send' => 0.20,
        'validate_response' => 0.25,
        'persist_form' => 0.15,
        'render_preview' => 0.15,
    ];
    protected array $completed = [];
    protected ?Hosha2LoggerInterface $logger;

    public function __construct(string $requestId, ?Hosha2LoggerInterface $logger = null)
    {
        $this->requestId = $requestId;
        $this->logger = $logger;
    }

    public function mark(string $phase): void
    {
        if (!isset($this->weights[$phase])) return;
        $this->completed[$phase] = true;
        $progress = $this->progress();
        $this->store($progress);
        if ($this->logger) {
            $this->logger->log('progress_update', ['req_id'=>$this->requestId,'phase'=>$phase,'progress'=>$progress]);
        }
    }

    /**
     * Returns cumulative fractional progress (0..1).
     */
    public function progress(): float
    {
        $sum = 0.0;
        foreach ($this->completed as $ph => $_) {
            $sum += $this->weights[$ph] ?? 0.0;
        }
        return min(1.0, round($sum, 4));
    }

    /**
     * Returns ordered list of completed phases (sequence contract for API response).
     */
    public function phases(): array
    {
        return array_keys($this->completed);
    }

    protected function store(float $progress): void
    {
        $key = $this->key();
        if (function_exists('set_transient')) {
            set_transient($key, ['progress'=>$progress,'ts'=>time()], 120);
        } else {
            // Non-WP environment fallback: in-memory static registry
            static $mem = [];
            $mem[$key] = ['progress'=>$progress,'ts'=>time()];
        }
    }

    protected function key(): string
    {
        return 'hosha2_progress_' . $this->requestId;
    }
}
?>
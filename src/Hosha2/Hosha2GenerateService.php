<?php
namespace Arshline\Hosha2;

use RuntimeException;

class Hosha2GenerateService
{
    protected Hosha2CapabilitiesBuilder $capBuilder;
    protected Hosha2OpenAIEnvelopeFactory $envFactory;
    protected Hosha2OpenAIClientInterface $client;
    protected Hosha2DiffValidator $diffValidator;
    protected ?Hosha2LoggerInterface $logger;
    protected ?Hosha2RateLimiter $rateLimiter;
    protected ?Hosha2VersionRepository $versionRepo;

    public function __construct(
        Hosha2CapabilitiesBuilder $capBuilder,
        Hosha2OpenAIEnvelopeFactory $envFactory,
        Hosha2OpenAIClientInterface $client,
        Hosha2DiffValidator $diffValidator,
        ?Hosha2LoggerInterface $logger = null,
        ?Hosha2RateLimiter $rateLimiter = null,
        ?Hosha2VersionRepository $versionRepo = null
    ) {
        $this->capBuilder = $capBuilder;
        $this->envFactory = $envFactory;
        $this->client = $client;
        $this->diffValidator = $diffValidator;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
        $this->versionRepo = $versionRepo;
    }

    public function generate(array $userInput): array
    {
        $reqId = $userInput['req_id'] ?? substr(md5(uniqid('', true)),0,10);
        if ($this->logger) $this->logger->setContext(['req_id'=>$reqId]);
        $progress = new Hosha2ProgressTracker($reqId, $this->logger);
        $progress->mark('analyze_input');

        // Rate limit check (before any heavy work & before cancellation checkpoints by design spec)
        if ($this->rateLimiter) {
            if (!$this->rateLimiter->isAllowed($reqId)) {
                if ($this->logger) $this->logger->log('rate_limit_exceeded', ['req_id'=>$reqId], 'WARN');
                throw new RuntimeException('Rate limit exceeded');
            }
            $this->rateLimiter->recordRequest($reqId);
        }

        try {
            // Checkpoint 1: before capabilities build
            if ($this->isCancelled($reqId)) {
                if ($this->logger) $this->logger->log('request_cancelled', ['req_id'=>$reqId,'checkpoint'=>'before_capabilities'], 'WARN');
                throw new RuntimeException('Request cancelled by user');
            }

            $cap = $this->capBuilder->build();
            $progress->mark('build_capabilities');

            // Checkpoint 2: before OpenAI call
            if ($this->isCancelled($reqId)) {
                if ($this->logger) $this->logger->log('request_cancelled', ['req_id'=>$reqId,'checkpoint'=>'before_openai'], 'WARN');
                throw new RuntimeException('Request cancelled by user');
            }

            $envelope = $this->envFactory->createGenerate($userInput, $cap, []);
            $progress->mark('openai_send');
            $raw = $this->client->sendGenerate($envelope);

            // Compute diff SHA (pre-apply) strictly on original diff structure
            $diffSha = null;
            if (isset($raw['diff']) && is_array($raw['diff']) && !empty($raw['diff'])) {
                $diffSha = sha1(json_encode($raw['diff']));
            }

            // Checkpoint 3: after OpenAI before diff validation
            if ($this->isCancelled($reqId)) {
                if ($this->logger) $this->logger->log('request_cancelled', ['req_id'=>$reqId,'checkpoint'=>'after_openai'], 'WARN');
                throw new RuntimeException('Request cancelled by user');
            }

            $progress->mark('validate_response');
            if (!is_array($raw['diff'])) {
                // Normalize invalid diff to empty for downstream safety
                $raw['diff'] = [];
                $diffSha = null; // ensure null SHA when diff invalid
            }
            if (!$this->diffValidator->validate($raw['diff'])) {
                throw new RuntimeException('Invalid diff: '. implode(';',$this->diffValidator->errors()));
            }

            // mock persistence step
            $progress->mark('persist_form');
            $progress->mark('render_preview');

            // Version snapshot (if repository available)
            $versionId = null;
            if ($this->versionRepo) {
                $metadata = [
                    'user_prompt' => $userInput['prompt'] ?? '',
                    'tokens_used' => $raw['token_usage']['total'] ?? 0,
                    'created_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
                    'diff_applied' => false,
                ];
                // assume form_id maybe passed else 0 placeholder
                $formId = (int)($userInput['form_id'] ?? 0);
                $versionId = $this->versionRepo->saveSnapshot($formId, $raw['final_form'], $metadata, $diffSha);
            }

            if ($this->logger) {
                $summaryNotes = ['pipeline:generate_complete'];
                if ($versionId) $summaryNotes[] = 'version:saved#'.$versionId;
                if ($diffSha) $summaryNotes[] = 'diff_sha:'.$diffSha;
                $this->logger->summary([
                    'progress' => $progress->progress(),
                    'fields' => count($raw['final_form']['fields'] ?? []),
                    'tokens_total' => $raw['token_usage']['total'] ?? 0,
                    'diff_sha' => $diffSha,
                ], [], $summaryNotes);
            }
            return [
                'request_id' => $reqId,
                'final_form' => $raw['final_form'],
                'diff' => $raw['diff'],
                'token_usage' => $raw['token_usage'],
                'progress' => $progress->phases(),
                'progress_percent' => $progress->progress(),
                'version_id' => $versionId,
                'diff_sha' => $diffSha,
            ];
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'cancelled') !== false) {
                if ($this->logger) $this->logger->log('generate_cancelled', ['req_id'=>$reqId,'message'=>$e->getMessage()], 'WARN');
                return [
                    'request_id' => $reqId,
                    'cancelled' => true,
                    'message' => $e->getMessage(),
                ];
            }
            throw $e; // rethrow unrelated runtime exceptions
        }
    }

    private function isCancelled(string $requestId): bool
    {
        if (!function_exists('get_transient')) {
            return false; // in tests without WP
        }
        return (bool) get_transient('hosha2_cancel_' . $requestId);
    }
}
?>
<?php
namespace Arshline\Hosha2;

use WP_Error;

class Hosha2OpenAIHttpClient implements Hosha2OpenAIClientInterface
{
    /**
     * Human readable taxonomy mapped to internal stable codes with ERR_ prefix.
     */
    public const ERROR_CODES = [
        'NETWORK_ERROR'    => 'ERR_NETWORK_ERROR',
        'TIMEOUT'          => 'ERR_TIMEOUT',
        'RATE_LIMIT'       => 'ERR_RATE_LIMIT',
        'INVALID_API_KEY'  => 'ERR_INVALID_API_KEY',
        'MODEL_OVERLOADED' => 'ERR_MODEL_OVERLOADED',
        'INVALID_REQUEST'  => 'ERR_INVALID_REQUEST',
        'SERVER_ERROR'     => 'ERR_SERVER_ERROR',
        'UNKNOWN'          => 'ERR_UNKNOWN',
    ];
    protected string $apiKey;
    protected string $baseUrl;
    protected int $timeout;
    protected ?Hosha2LoggerInterface $logger;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.openai.com/v1/chat/completions', int $timeout = 30, ?Hosha2LoggerInterface $logger = null)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->logger = $logger;
    }

    public function sendGenerate(array $envelope): array
    {
        return $this->dispatch('generate', $envelope);
    }

    public function sendValidate(array $envelope): array
    {
        return $this->dispatch('validate', $envelope);
    }

    protected function dispatch(string $intent, array $envelope): array
    {
        $start = microtime(true);
        if ($this->logger) $this->logger->log('openai_request_start', ['intent'=>$intent]);
        $body = $this->buildBody($intent, $envelope);
        $args = [
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($body),
        ];
        $response = wp_remote_post($this->baseUrl, $args);
        $latency = round((microtime(true) - $start) * 1000, 2);
        if (is_wp_error($response)) {
            $mapped = $this->mapWpError($response);
            if ($this->logger) $this->logger->log('openai_request_end', [
                'intent'=>$intent,
                'latency_ms'=>$latency,
                'error_code'=>$mapped['code'],
                'error_msg'=>$mapped['message'],
                'http_status'=>null
            ], 'ERROR');
            return ['error'=>$mapped];
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        if ($code >= 400) {
            $mapped = $this->mapHttpError($code, $json);
            // If OpenAI style error body detected, refine mapping via parseOpenAIError
            if (isset($json['error'])) {
                $mapped = $this->parseOpenAIError($json, $mapped, $code);
            }
            if ($this->logger) $this->logger->log('openai_request_end', [
                'intent'=>$intent,
                'latency_ms'=>$latency,
                'error_code'=>$mapped['code'],
                'error_msg'=>$mapped['message'],
                'http_status'=>$code
            ], 'ERROR');
            return ['error'=>$mapped];
        }
        // Expect model JSON already structured; fallback minimal wrapper if unexpected
        $result = $this->normalizeModelResponse($intent, $json ?? []);
        $diff = $result['diff'] ?? [];
        $diffSha = sha1(json_encode($diff));
        if ($this->logger) $this->logger->log('openai_request_end', [
            'intent'=>$intent,
            'latency_ms'=>$latency,
            'tokens_total'=>$result['token_usage']['total'] ?? null,
            'diff_sha'=>$diffSha
        ], 'INFO');
        return $result;
    }

    protected function buildBody(string $intent, array $envelope): array
    {
        // Minimal chat payload; actual prompt engineering deferred.
        return [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role'=>'system','content'=>'You are an AI form generator. Intent='.$intent],
                ['role'=>'user','content'=> json_encode($envelope, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
            ],
            'temperature' => 0.2,
        ];
    }

    protected function mapWpError(WP_Error $err): array
    {
        $msg = $err->get_error_message();
        $code = self::ERROR_CODES['NETWORK_ERROR'];
        $lmsg = strtolower($msg);
        if (str_contains($lmsg, 'timeout')) $code = self::ERROR_CODES['TIMEOUT'];
        return ['code'=>$code,'message'=>$msg];
    }

    protected function mapHttpError(int $code, ?array $json): array
    {
        $msg = $json['error']['message'] ?? ('HTTP ' . $code);
        $bodyText = is_array($json) ? json_encode($json) : '';
        switch (true) {
            case in_array($code, [401,403], true):
                $mapped = self::ERROR_CODES['INVALID_API_KEY'];
                break;
            case $code === 429:
                $mapped = self::ERROR_CODES['RATE_LIMIT'];
                break;
            case ($code >= 400 && $code < 500):
                $mapped = self::ERROR_CODES['INVALID_REQUEST'];
                break;
            case ($code === 503 && stripos($bodyText, 'overloaded') !== false):
                $mapped = self::ERROR_CODES['MODEL_OVERLOADED'];
                break;
            case ($code === 503):
                $mapped = self::ERROR_CODES['SERVER_ERROR'];
                break;
            case ($code >= 500 && $code < 600):
                $mapped = self::ERROR_CODES['SERVER_ERROR'];
                break;
            default:
                $mapped = self::ERROR_CODES['UNKNOWN'];
        }
        return ['code'=>$mapped,'message'=>$msg,'http_status'=>$code];
    }

    protected function parseOpenAIError(array $decoded, array $current, int $statusCode): array
    {
        $err = $decoded['error'] ?? [];
        $type = $err['type'] ?? '';
        $msg  = $err['message'] ?? ($current['message'] ?? '');
        $map = [
            'invalid_request_error' => self::ERROR_CODES['INVALID_REQUEST'],
            'authentication_error'  => self::ERROR_CODES['INVALID_API_KEY'],
            'rate_limit_error'      => self::ERROR_CODES['RATE_LIMIT'],
            'server_error'          => self::ERROR_CODES['SERVER_ERROR'],
        ];
        if (isset($map[$type])) {
            return ['code'=>$map[$type],'message'=>$msg,'http_status'=>$statusCode];
        }
        return $current; // fallback to previous mapping
    }

    protected function normalizeModelResponse(string $intent, array $json): array
    {
        // Placeholder: attempts to extract a JSON block from assistant message; future robust parser.
        if (isset($json['choices'][0]['message']['content'])) {
            $content = $json['choices'][0]['message']['content'];
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                // Assume already in final envelope shape or partial
                $decoded['intent'] = $decoded['intent'] ?? $intent;
                return $this->augmentDefaults($decoded);
            }
        }
        // Fallback minimal structure
        return $this->augmentDefaults([
            'intent'=>$intent,
            'final_form'=>['version'=>'arshline_form@v1','fields'=>[],'layout'=>[],'meta'=>[]],
            'diff'=>[],
            'diagnostics'=>['notes'=>['empty_content'],'riskFlags'=>[]],
            'token_usage'=>['prompt'=>0,'completion'=>0,'total'=>0]
        ]);
    }

    protected function augmentDefaults(array $r): array
    {
        $r['diagnostics'] = $r['diagnostics'] ?? ['notes'=>[],'riskFlags'=>[]];
        $r['token_usage'] = $r['token_usage'] ?? ['prompt'=>0,'completion'=>0,'total'=> ($r['token_usage']['prompt']??0)+($r['token_usage']['completion']??0)];
        $r['diff'] = $r['diff'] ?? [];
        if (!isset($r['final_form']['version'])) {
            $r['final_form']['version'] = 'arshline_form@v1';
        }
        return $r;
    }
}
?>
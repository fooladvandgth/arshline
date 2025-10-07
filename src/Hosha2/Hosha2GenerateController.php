<?php
namespace Arshline\Hosha2;

use WP_REST_Request;
use WP_REST_Response;
use RuntimeException;

/**
 * REST controller wrapper for Hosha2GenerateService.
 * Endpoint: POST /hosha2/v1/forms/{form_id}/generate
 */
class Hosha2GenerateController
{
    protected Hosha2GenerateService $service;

    public function __construct(Hosha2GenerateService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle generation request.
     * Expected JSON body: { "prompt": string, "options"?: {...} }
     */
    public function handle(WP_REST_Request $req): WP_REST_Response
    {
        $formId = (int)$req['form_id'];
        if ($formId <= 0) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_form_id','message'=>'form_id must be positive integer']], 400);
        }
        $rawPrompt = $req->get_param('prompt');
        if ($rawPrompt === null) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'missing_prompt','message'=>'prompt field required']], 400);
        }
        $prompt = (string)$rawPrompt;
        if (trim($prompt) === '') {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'empty_prompt','message'=>'prompt cannot be empty']], 400);
        }
        $options = $req->get_param('options');
        if ($options !== null && !is_array($options)) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_options_type','message'=>'options must be JSON object']], 400);
        }
        // Allow external (test/diagnostic) injection of request id if provided & safe format
        $providedReqId = $req->get_param('req_id');
        if (is_string($providedReqId) && preg_match('/^[a-f0-9]{6,32}$/i', $providedReqId)) {
            $reqId = substr(strtolower($providedReqId),0,32);
        } else {
            $reqId = substr(md5(uniqid('', true)),0,10);
        }
        try {
            $result = $this->service->generate([
                'form_id' => $formId,
                'prompt' => $prompt,
                'options' => $options ?? [],
                'req_id' => $reqId,
            ]);
            if (isset($result['cancelled']) && $result['cancelled']) {
                // 499: Client Closed Request (non-standard but widely adopted) — semantic fit for user-triggered cancellation.
                return new WP_REST_Response([
                    'success'=>false,
                    'cancelled'=>true,
                    'error'=>[
                        'code'=>'request_cancelled',
                        'message'=>$result['message'] ?? 'Request was cancelled by client'
                    ],
                    'request_id'=>$result['request_id']
                ], 499);
            }
            return new WP_REST_Response([
                'success'=>true,
                'request_id'=>$result['request_id'],
                'version_id'=>$result['version_id'] ?? null,
                'diff_sha'=>$result['diff_sha'] ?? null,
                'data'=>[
                    'final_form'=>$result['final_form'],
                    'diff'=>$result['diff'],
                    'token_usage'=>$result['token_usage'],
                    'progress'=>$result['progress'],
                    'progress_percent'=>$result['progress_percent'] ?? null,
                ]
            ], 200);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if ($msg === 'FORM_NOT_FOUND') {
                return new WP_REST_Response([
                    'success'=>false,
                    'error'=>['code'=>'form_not_found','message'=>'Form not found'],
                    'request_id'=>$reqId
                ], 404);
            }
            if (stripos($msg, 'Rate limit exceeded') !== false) {
                return new WP_REST_Response([
                    'success'=>false,
                    'error'=>['code'=>'rate_limited','message'=>'Rate limit exceeded'],
                    'request_id'=>$reqId
                ], 429);
            }
            if (stripos($msg,'OPENAI_FAIL') !== false || stripos($msg,'OPENAI_ERROR') !== false) {
                return new WP_REST_Response([
                    'success'=>false,
                    'error'=>['code'=>'service_unavailable','message'=>'AI service temporarily unavailable'],
                    'request_id'=>$reqId
                ], 503);
            }
            return new WP_REST_Response([
                'success'=>false,
                'error'=>['code'=>'internal_error','message'=>$msg],
                'request_id'=>$reqId
            ], 500);
        } catch (\Throwable $t) {
            return new WP_REST_Response([
                'success'=>false,
                'error'=>['code'=>'internal_error','message'=>$t->getMessage()],
                'request_id'=>$reqId
            ], 500);
        }
    }
}

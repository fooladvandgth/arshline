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
        $reqId = substr(md5(uniqid('', true)),0,10);
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
                ]
            ], 200);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $status = 500;
            if (stripos($msg, 'Rate limit exceeded') !== false) $status = 429;
            if (stripos($msg, 'Request cancelled') !== false) $status = 409;
            return new WP_REST_Response([
                'success'=>false,
                'error'=>[
                    'code'=> $status===429? 'rate_limited' : 'runtime_error',
                    'message'=>$msg
                ],
                'request_id'=>$reqId
            ], $status);
        } catch (\Throwable $t) {
            return new WP_REST_Response([
                'success'=>false,
                'error'=>[
                    'code'=>'fatal','message'=>$t->getMessage()
                ],
                'request_id'=>$reqId
            ], 500);
        }
    }
}

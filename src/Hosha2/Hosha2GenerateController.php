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
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_form_id']], 400);
        }
        $prompt = (string)($req->get_param('prompt') ?? '');
        if (trim($prompt) === '') {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'missing_prompt']], 422);
        }
        $options = $req->get_param('options');
        if ($options !== null && !is_array($options)) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_options_type']], 422);
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
                return new WP_REST_Response([
                    'success'=>false,
                    'cancelled'=>true,
                    'request_id'=>$result['request_id'],
                    'message'=>$result['message'] ?? 'cancelled'
                ], 409);
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

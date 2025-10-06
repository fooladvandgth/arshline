<?php
namespace Arshline\Hosha2;

use WP_REST_Request;
use WP_REST_Response;
use RuntimeException;

class Hosha2VersionController
{
    private Hosha2VersionRepository $repo;
    private ?Hosha2LoggerInterface $logger;

    public function __construct(Hosha2VersionRepository $repo, ?Hosha2LoggerInterface $logger = null)
    {
        $this->repo = $repo;
        $this->logger = $logger;
    }

    public function listFormVersions(WP_REST_Request $req): WP_REST_Response
    {
    $formId = (int)$req['form_id'];
        if ($formId <= 0) {
            return new WP_REST_RESPONSE(['success'=>false,'error'=>['code'=>'invalid_form_id','message'=>'form_id must be positive integer']], 400);
        }
        $limit = $req->get_param('limit');
        $offset = $req->get_param('offset');
        $limitVal = is_numeric($limit) ? (int)$limit : 10;
        $offsetVal = is_numeric($offset) ? (int)$offset : 0;
        if ($limitVal <= 0 || $limitVal > 100) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_limit','message'=>'limit must be between 1 and 100']], 400);
        }
        if ($offsetVal < 0) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_offset','message'=>'offset must be >= 0']], 400);
        }
        try {
            $total = $this->repo->countVersions($formId);
            $rows = $this->repo->listVersions($formId, $limitVal, $offsetVal);
            $versions = [];
            foreach ($rows as $r) {
                $isoCreated = $r['created_at'];
                if ($isoCreated) {
                    $ts = strtotime($isoCreated);
                    if ($ts !== false) {
                        $isoCreated = gmdate('c', $ts); // ISO8601 UTC
                    }
                }
                $versions[] = [
                    'version_id' => $r['version_id'],
                    'form_id' => $formId,
                    'created_at' => $isoCreated,
                    'metadata' => $r['metadata'],
                ];
            }
            if ($this->logger) {
                $this->logger->log('versions_list_endpoint',[ 'form_id'=>$formId, 'returned'=>count($versions), 'total'=>$total, 'limit'=>$limitVal, 'offset'=>$offsetVal ], 'INFO');
            }
            return new WP_REST_Response([
                'success'=>true,
                'data'=>[
                    'versions'=>$versions,
                    'total'=>$total,
                    'limit'=>$limitVal,
                    'offset'=>$offsetVal,
                    'returned'=>count($versions)
                ]
            ], 200);
        } catch (RuntimeException $e) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'internal_error','message'=>$e->getMessage()]], 500);
        } catch (\Throwable $t) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'internal_error','message'=>$t->getMessage()]], 500);
        }
    }
}

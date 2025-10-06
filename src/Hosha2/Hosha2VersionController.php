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

    public function getVersion(WP_REST_Request $req): WP_REST_Response
    {
        $formId = (int)$req['form_id'];
        $versionIdRaw = $req['version_id'] ?? $req['id'] ?? null;
        $versionId = is_numeric($versionIdRaw) ? (int)$versionIdRaw : 0;
        if ($formId <= 0) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_form_id','message'=>'form_id must be positive integer']], 400);
        }
        if ($versionId <= 0) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'invalid_version_id','message'=>'version_id must be positive integer']], 400);
        }
        try {
            $snap = $this->repo->getSnapshot($versionId);
            if ($snap === null) {
                if ($this->logger) $this->logger->log('version_get_endpoint',[ 'form_id'=>$formId,'version_id'=>$versionId,'found'=>false ],'INFO');
                return new WP_REST_Response(['success'=>false,'error'=>['code'=>'version_not_found','message'=>'Version not found']], 404);
            }
            // Ownership validation: metadata legacy includes _hosha2_form_id; fallback to reject if mismatch
            $meta = $snap['metadata'] ?? [];
            $ownerForm = (int)($meta['_hosha2_form_id'] ?? 0);
            if ($ownerForm !== $formId) {
                if ($this->logger) $this->logger->log('version_get_endpoint',[ 'form_id'=>$formId,'version_id'=>$versionId,'found'=>false,'mismatch'=>$ownerForm ],'WARN');
                return new WP_REST_Response(['success'=>false,'error'=>['code'=>'version_not_found','message'=>'Version not found for form']], 404);
            }
            $createdAt = $snap['created_at'] ?? '';
            if ($createdAt) {
                $ts = strtotime($createdAt);
                if ($ts !== false) {
                    $createdAt = gmdate('c', $ts);
                }
            }
            $payload = [
                'success'=>true,
                'data'=>[
                    'version_id'=>$versionId,
                    'form_id'=>$formId,
                    'created_at'=>$createdAt,
                    'metadata'=>$meta,
                    'snapshot'=>$snap['config'] ?? [],
                ]
            ];
            if ($this->logger) $this->logger->log('version_get_endpoint',[ 'form_id'=>$formId,'version_id'=>$versionId,'found'=>true ],'INFO');
            return new WP_REST_Response($payload, 200);
        } catch (RuntimeException $e) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'internal_error','message'=>$e->getMessage()]], 500);
        } catch (\Throwable $t) {
            return new WP_REST_Response(['success'=>false,'error'=>['code'=>'internal_error','message'=>$t->getMessage()]], 500);
        }
    }
}

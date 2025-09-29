<?php
namespace Arshline\Core\Ai;

use WP_REST_Request;
use WP_REST_Response;
use Arshline\Modules\Forms\FormRepository;
use Arshline\Modules\Forms\FieldRepository;
use Arshline\Support\Audit;

/**
 * Executes a validated plan steps with audit and safe defaults.
 */
class PlanExecutor
{
    /**
     * Execute the plan.
     * Returns array { ok, preview, results, undo_tokens }
     */
    public static function execute(array $plan): array
    {
        $results = [];
        $undoTokens = [];
        $lastFormId = 0;
        foreach ($plan['steps'] as $s){
            $a = $s['action'];
            $p = $s['params'] ?? [];
            if ($a === 'create_form'){
                $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                $req->set_body_params(['title'=>(string)$p['title']]);
                $res = \Arshline\Core\Api::create_form($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $fid = (int)($data['id'] ?? 0);
                if ($fid > 0){ $lastFormId = $fid; }
                $results[] = [ 'action'=>'create_form', 'id'=>$fid, 'undo_token'=>($data['undo_token']??null) ];
                if (!empty($data['undo_token'])) $undoTokens[] = $data['undo_token'];
            }
            elseif ($a === 'add_field'){
                $fid = isset($p['id']) ? (int)$p['id'] : ($lastFormId ?: 0);
                if ($fid <= 0){ $results[] = [ 'ok'=>false, 'error'=>'missing_form_id_for_add_field' ]; continue; }
                $before = FieldRepository::listByForm($fid);
                $fields = $before;
                $type = (string)($p['type'] ?? 'short_text');
                $question = (string)($p['question'] ?? '');
                $required = !empty($p['required']);
                $defaults = [
                    'type'=>$type ?: 'short_text',
                    'label'=> $question !== '' ? $question : 'سؤال جدید',
                    'format'=>'free_text',
                    'required'=>$required,
                    'show_description'=>false,
                    'description'=>'',
                    'placeholder'=>'',
                    'question'=> $question,
                    'numbered'=>true,
                ];
                $idx = isset($p['index']) ? (int)$p['index'] : null;
                if (is_int($idx) && $idx >= 0 && $idx <= count($fields)) array_splice($fields, $idx, 0, [[ 'props' => $defaults ]]);
                else $fields[] = [ 'props'=>$defaults ];
                FieldRepository::replaceAll($fid, $fields);
                $after = FieldRepository::listByForm($fid);
                $undo = Audit::log('update_form_fields', 'form', $fid, ['fields'=>$before], ['fields'=>$after]);
                $results[] = [ 'action'=>'add_field', 'id'=>$fid, 'index'=> is_int($idx) ? $idx : count($after)-1, 'undo_token'=>$undo ];
                if ($undo) $undoTokens[] = $undo;
            }
            elseif ($a === 'update_form_title'){
                $fid = (int)$p['id']; $title = (string)$p['title'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['title'=>$title]);
                $res = \Arshline\Core\Api::update_form($req);
                $results[] = [ 'action'=>'update_form_title', 'id'=>$fid, 'ok'=> $res instanceof WP_REST_Response ];
            }
            elseif ($a === 'publish_form' || $a === 'draft_form'){
                $fid = (int)$p['id'];
                $status = $a === 'publish_form' ? 'published' : 'draft';
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>$status]);
                $res = \Arshline\Core\Api::update_form($req);
                $results[] = [ 'action'=>$a, 'id'=>$fid, 'ok'=> $res instanceof WP_REST_Response ];
            }
            elseif ($a === 'open_builder' || $a === 'open_results' || $a === 'open_editor'){
                // UI navigation-only; just echo back
                $results[] = array_merge(['action'=>$a], $p);
            }
        }
        return [ 'ok'=>true, 'results'=>$results, 'undo_tokens'=>$undoTokens ];
    }
}

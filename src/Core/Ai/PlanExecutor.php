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
            elseif ($a === 'open_ug'){
                // UI navigation only: reflect back with optional tab/group_id
                $tab = (string)($p['tab'] ?? 'groups');
                $gid = isset($p['group_id']) ? (int)$p['group_id'] : null;
                $pp = ['tab'=>$tab]; if ($gid) $pp['group_id'] = $gid;
                $results[] = array_merge(['action'=>'open_ug'], $pp);
            }
            elseif ($a === 'ug_create_group'){
                // Use REST handler to create and capture undo snapshot via Audit in Api handler if any
                $name = (string)($p['name'] ?? '');
                $pid = isset($p['parent_id']) ? $p['parent_id'] : null;
                $req = new \WP_REST_Request('POST', '/arshline/v1/user-groups');
                $body = ['name'=>$name]; if ($pid !== null) $body['parent_id'] = $pid;
                $req->set_body_params($body);
                $res = \Arshline\Core\Api::ug_create_group($req);
                $data = $res instanceof \WP_REST_Response ? $res->get_data() : [];
                $gid = (int)($data['id'] ?? 0);
                // Log a minimal audit for undo (delete on undo)
                $undo = \Arshline\Support\Audit::log('create_group', 'ug', $gid, [], ['group'=>['id'=>$gid,'name'=>$name,'parent_id'=>($pid===null?null:(int)$pid)]]);
                if ($undo) $undoTokens[] = $undo;
                $results[] = [ 'action'=>'ug_create_group', 'id'=>$gid, 'undo_token'=>$undo ];
            }
            elseif ($a === 'ug_update_group'){
                $id = (int)($p['id'] ?? 0);
                if ($id <= 0){ $results[] = [ 'ok'=>false, 'error'=>'missing_group_id' ]; continue; }
                // Capture before state (best-effort)
                $before = [];
                try { $g = \Arshline\Modules\UserGroups\GroupRepository::find($id); if ($g){ $before = ['name'=>$g->name, 'parent_id'=>$g->parent_id]; } } catch (\Throwable $e) { }
                $req = new \WP_REST_Request('PUT', '/arshline/v1/user-groups/'.$id);
                $req->set_url_params(['group_id'=>$id]);
                $req->set_body_params(array_diff_key($p, ['id'=>true]));
                $res = \Arshline\Core\Api::ug_update_group($req);
                $ok = $res instanceof \WP_REST_Response;
                $after = [];
                try { $g2 = \Arshline\Modules\UserGroups\GroupRepository::find($id); if ($g2){ $after = ['name'=>$g2->name, 'parent_id'=>$g2->parent_id]; } } catch (\Throwable $e) { }
                $undo = \Arshline\Support\Audit::log('update_group', 'ug', $id, $before, $after);
                if ($undo) $undoTokens[] = $undo;
                $results[] = [ 'action'=>'ug_update_group', 'id'=>$id, 'ok'=>$ok, 'undo_token'=>$undo ];
            }
            elseif ($a === 'ug_export_links'){
                // Return a UI downloadable link using admin-post with nonce-less public flow not possible; rely on admin-post endpoints exposed in template
                $gid = isset($p['group_id']) ? (int)$p['group_id'] : 0;
                $fid = isset($p['form_id']) ? (int)$p['form_id'] : 0;
                // Build admin-post URL (frontend exposes window.ARSHLINE_ADMIN.adminPostUrl)
                $params = [];
                if ($gid > 0) $params['group_id'] = $gid; if ($fid > 0) $params['form_id'] = $fid;
                $url = add_query_arg(array_merge(['action'=>'arshline_export_group_links', '_wpnonce'=>wp_create_nonce('arshline_export_group_links')], $params), admin_url('admin-post.php'));
                $results[] = [ 'action'=>'download', 'format'=>'csv', 'url'=>$url ];
            }
            elseif ($a === 'ug_download_members_template'){
                $gid = (int)($p['group_id'] ?? 0);
                $url = add_query_arg(['action'=>'arshline_download_members_template', '_wpnonce'=>wp_create_nonce('arshline_download_members_template'), 'group_id'=>$gid], admin_url('admin-post.php'));
                $results[] = [ 'action'=>'download', 'format'=>'csv', 'url'=>$url ];
            }
            elseif ($a === 'ug_set_form_access'){
                $fid = (int)($p['form_id'] ?? 0); $gids = is_array($p['group_ids'] ?? null) ? array_values(array_map('intval', $p['group_ids'])) : [];
                if ($fid <= 0){ $results[] = [ 'ok'=>false, 'error'=>'invalid_form_id' ]; continue; }
                // Snapshot before mapping for undo
                $before = ['group_ids' => \Arshline\Modules\UserGroups\FormGroupAccessRepository::getGroupIds($fid)];
                $req = new \WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid.'/access/groups');
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['group_ids'=>$gids]);
                $res = \Arshline\Core\Api::set_form_access_groups($req);
                $after = ['group_ids' => \Arshline\Modules\UserGroups\FormGroupAccessRepository::getGroupIds($fid)];
                $undo = \Arshline\Support\Audit::log('set_form_access', 'form', $fid, $before, $after);
                if ($undo) $undoTokens[] = $undo;
                $results[] = [ 'action'=>'ug_set_form_access', 'form_id'=>$fid, 'ok'=> $res instanceof \WP_REST_Response, 'undo_token'=>$undo ];
            }
            elseif ($a === 'ug_ensure_tokens'){
                $gid = (int)($p['group_id'] ?? 0);
                $req = new \WP_REST_Request('POST', '/arshline/v1/user-groups/'.$gid.'/members/ensure-tokens');
                $req->set_url_params(['group_id'=>$gid]);
                $res = \Arshline\Core\Api::ug_bulk_ensure_tokens($req);
                $data = $res instanceof \WP_REST_Response ? $res->get_data() : [];
                $results[] = [ 'action'=>'ug_ensure_tokens', 'group_id'=>$gid, 'ok'=> $res instanceof \WP_REST_Response, 'generated'=> (int)($data['generated'] ?? 0) ];
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

<?php
namespace Arshline\Core\Ai;

use WP_Error;

/**
 * Validates a plan for Hoshyar multi-step execution.
 * Plan shape:
 * {
 *   version: 1,
 *   steps: [
 *     { action: 'create_form', params: { title: string } },
 *     { action: 'add_field', params: { id: number, type: 'short_text|long_text|multiple_choice|dropdown|rating', question?: string, required?: bool, index?: number } },
 *     { action: 'update_form_title', params: { id: number, title: string } },
 *     { action: 'open_builder', params: { id: number } },
 *     { action: 'open_editor', params: { id: number, index: number } },
 *     { action: 'open_results', params: { id: number } },
 *     { action: 'publish_form', params: { id: number } },
 *     { action: 'draft_form', params: { id: number } },
 *   ]
 * }
 *
 * Non-destructive defaults; destructive ops (delete_form) intentionally excluded.
 */
class PlanValidator
{
    public static function validate($plan)
    {
        if (!is_array($plan)) return new WP_Error('invalid_plan', 'Plan must be an object');
        $ver = (int)($plan['version'] ?? 1);
        if ($ver !== 1) return new WP_Error('invalid_version', 'Unsupported plan version');
        $steps = $plan['steps'] ?? null;
        if (!is_array($steps) || empty($steps)) return new WP_Error('no_steps', 'Plan must include steps');
        $allowed = [
            // Forms/builder
            'create_form','add_field','update_form_title','open_builder','open_editor','open_results','publish_form','draft_form',
            // User Groups (UG)
            'open_ug','ug_create_group','ug_update_group','ug_export_links','ug_download_members_template','ug_set_form_access','ug_ensure_tokens'
        ];
        $maxSteps = apply_filters('arshline_ai_plan_max_steps', 12);
        if (count($steps) > max(1, (int)$maxSteps)) return new WP_Error('too_many_steps', 'Plan has too many steps');
        $out = [ 'version' => 1, 'steps' => [] ];
        $hasCreateBefore = false;
        foreach ($steps as $i => $s){
            if (!is_array($s)) return new WP_Error('invalid_step', 'Step must be an object at index '.$i);
            $action = isset($s['action']) ? (string)$s['action'] : '';
            if (!in_array($action, $allowed, true)) return new WP_Error('invalid_action', 'Action not allowed: '.$action);
            $params = is_array($s['params'] ?? null) ? $s['params'] : [];
            switch ($action){
                case 'create_form':
                    $title = trim((string)($params['title'] ?? ''));
                    if ($title === '') $title = apply_filters('arshline_ai_new_form_default_title', 'فرم جدید');
                    $out['steps'][] = [ 'action'=>'create_form', 'params'=>['title'=>$title] ];
                    $hasCreateBefore = true;
                    break;
                case 'add_field':
                    $id = (int)($params['id'] ?? 0);
                    $type = (string)($params['type'] ?? 'short_text');
                    $idx = isset($params['index']) ? (int)$params['index'] : null;
                    $question = isset($params['question']) ? (string)$params['question'] : '';
                    $required = !empty($params['required']);
                    $allowedTypes = ['short_text','long_text','multiple_choice','dropdown','rating'];
                    // Allow missing id only if preceded by create_form in this plan
                    if ($id <= 0 && !$hasCreateBefore) return new WP_Error('missing_id', 'add_field requires id');
                    if (!in_array($type, $allowedTypes, true)) $type = 'short_text';
                    $step = [ 'action'=>'add_field', 'params'=>['id'=>$id,'type'=>$type,'required'=>$required] ];
                    if ($id <= 0) { unset($step['params']['id']); }
                    if (is_int($idx) && $idx >= 0) $step['params']['index'] = $idx;
                    if ($question !== '') $step['params']['question'] = mb_substr($question, 0, 200);
                    $out['steps'][] = $step;
                    break;
                case 'open_ug':
                    // params: { tab?: 'groups'|'members'|'mapping'|'custom_fields', group_id?: number }
                    $tab = (string)($params['tab'] ?? 'groups');
                    $allowedTabs = ['groups','members','mapping','custom_fields'];
                    if (!in_array($tab, $allowedTabs, true)) $tab = 'groups';
                    $gid = isset($params['group_id']) ? (int)$params['group_id'] : null;
                    $p = ['tab'=>$tab]; if (is_int($gid) && $gid>0) $p['group_id'] = $gid;
                    $out['steps'][] = [ 'action'=>'open_ug', 'params'=>$p ];
                    break;
                case 'ug_create_group':
                    // params: { name: string, parent_id?: number|null }
                    $name = trim((string)($params['name'] ?? ''));
                    if ($name === '') return new WP_Error('missing_params', 'ug_create_group requires name');
                    $pid = isset($params['parent_id']) && $params['parent_id']!=='' ? (int)$params['parent_id'] : null;
                    if ($pid !== null && $pid <= 0) $pid = null;
                    $pp = ['name'=>$name]; if ($pid !== null) $pp['parent_id'] = $pid;
                    $out['steps'][] = [ 'action'=>'ug_create_group', 'params'=>$pp ];
                    break;
                case 'ug_update_group':
                    // params: { id: number, name?: string, parent_id?: number|null }
                    $id = (int)($params['id'] ?? 0);
                    if ($id <= 0) return new WP_Error('missing_id', 'ug_update_group requires id');
                    $pp = ['id'=>$id];
                    if (isset($params['name'])){ $nm = trim((string)$params['name']); if ($nm!=='') $pp['name']=$nm; }
                    if (array_key_exists('parent_id', $params)){
                        $pid = ($params['parent_id'] === null || $params['parent_id'] === '' ? null : (int)$params['parent_id']);
                        if ($pid !== null && $pid <= 0) $pid = null;
                        $pp['parent_id'] = $pid;
                    }
                    if (count($pp)===1) return new WP_Error('missing_params', 'ug_update_group requires at least one field');
                    $out['steps'][] = [ 'action'=>'ug_update_group', 'params'=>$pp ];
                    break;
                case 'ug_export_links':
                    // params: { group_id?: number, form_id?: number } — one of them required
                    $gid = isset($params['group_id']) ? (int)$params['group_id'] : 0;
                    $fid = isset($params['form_id']) ? (int)$params['form_id'] : 0;
                    if ($gid <= 0 && $fid <= 0) return new WP_Error('missing_params', 'ug_export_links requires group_id or form_id');
                    $pp = [];
                    if ($gid > 0) $pp['group_id'] = $gid; if ($fid > 0) $pp['form_id'] = $fid;
                    $out['steps'][] = [ 'action'=>'ug_export_links', 'params'=>$pp ];
                    break;
                case 'ug_download_members_template':
                    // params: { group_id: number }
                    $gid = (int)($params['group_id'] ?? 0);
                    if ($gid <= 0) return new WP_Error('missing_params', 'ug_download_members_template requires group_id');
                    $out['steps'][] = [ 'action'=>'ug_download_members_template', 'params'=>['group_id'=>$gid] ];
                    break;
                case 'ug_set_form_access':
                    // params: { form_id: number, group_ids: number[] }
                    $fid = (int)($params['form_id'] ?? 0);
                    $gids = $params['group_ids'] ?? [];
                    if ($fid <= 0 || !is_array($gids)) return new WP_Error('missing_params', 'ug_set_form_access requires form_id and group_ids');
                    $gids = array_values(array_unique(array_filter(array_map('intval', $gids))));
                    $out['steps'][] = [ 'action'=>'ug_set_form_access', 'params'=>['form_id'=>$fid, 'group_ids'=>$gids] ];
                    break;
                case 'ug_ensure_tokens':
                    // params: { group_id: number }
                    $gid = (int)($params['group_id'] ?? 0);
                    if ($gid <= 0) return new WP_Error('missing_params', 'ug_ensure_tokens requires group_id');
                    $out['steps'][] = [ 'action'=>'ug_ensure_tokens', 'params'=>['group_id'=>$gid] ];
                    break;
                case 'update_form_title':
                    $id = (int)($params['id'] ?? 0);
                    $title = trim((string)($params['title'] ?? ''));
                    if ($id <= 0 || $title === '') return new WP_Error('missing_params', 'update_form_title requires id and title');
                    $out['steps'][] = [ 'action'=>'update_form_title', 'params'=>['id'=>$id,'title'=>mb_substr($title, 0, 160)] ];
                    break;
                case 'publish_form':
                case 'draft_form':
                    $id = (int)($params['id'] ?? 0);
                    if ($id <= 0) return new WP_Error('missing_id', $action.' requires id');
                    $out['steps'][] = [ 'action'=>$action, 'params'=>['id'=>$id] ];
                    break;
                case 'open_builder':
                case 'open_results':
                    $id = (int)($params['id'] ?? 0);
                    if ($id <= 0) return new WP_Error('missing_id', $action.' requires id');
                    $out['steps'][] = [ 'action'=>$action, 'params'=>['id'=>$id] ];
                    break;
                case 'open_editor':
                    $id = (int)($params['id'] ?? 0);
                    $index = (int)($params['index'] ?? -1);
                    if ($id <= 0 || $index < 0) return new WP_Error('missing_params', 'open_editor requires id and index');
                    $out['steps'][] = [ 'action'=>'open_editor', 'params'=>['id'=>$id, 'index'=>$index] ];
                    break;
            }
        }
        return $out;
    }
}

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
            'create_form','add_field','update_form_title','open_builder','open_editor','open_results','publish_form','draft_form'
        ];
        $maxSteps = apply_filters('arshline_ai_plan_max_steps', 12);
        if (count($steps) > max(1, (int)$maxSteps)) return new WP_Error('too_many_steps', 'Plan has too many steps');
        $out = [ 'version' => 1, 'steps' => [] ];
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
                    break;
                case 'add_field':
                    $id = (int)($params['id'] ?? 0);
                    $type = (string)($params['type'] ?? 'short_text');
                    $idx = isset($params['index']) ? (int)$params['index'] : null;
                    $question = isset($params['question']) ? (string)$params['question'] : '';
                    $required = !empty($params['required']);
                    $allowedTypes = ['short_text','long_text','multiple_choice','dropdown','rating'];
                    if ($id <= 0) return new WP_Error('missing_id', 'add_field requires id');
                    if (!in_array($type, $allowedTypes, true)) $type = 'short_text';
                    $step = [ 'action'=>'add_field', 'params'=>['id'=>$id,'type'=>$type,'required'=>$required] ];
                    if (is_int($idx) && $idx >= 0) $step['params']['index'] = $idx;
                    if ($question !== '') $step['params']['question'] = mb_substr($question, 0, 200);
                    $out['steps'][] = $step;
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

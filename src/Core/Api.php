<?php
namespace Arshline\Core;

use WP_REST_Request;
use WP_REST_Response;
use Arshline\Support\Helpers;
use Arshline\Modules\Forms\Form;
use Arshline\Modules\Forms\FormRepository;
use Arshline\Modules\Forms\Submission;
use Arshline\Modules\Forms\SubmissionRepository;
use Arshline\Modules\Forms\SubmissionValueRepository;
use Arshline\Modules\Forms\FormValidator;
use Arshline\Modules\Forms\FieldRepository;

class Api
{
    public static function boot()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        // Serve raw HTML for HTMX routes (avoid JSON-encoding the HTML fragment)
        add_filter('rest_pre_serve_request', [self::class, 'serve_htmx_html'], 10, 4);
    }

    public static function user_can_manage_forms(): bool
    {
        return current_user_can('manage_options') || current_user_can('edit_posts');
    }

    public static function register_routes()
    {
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_forms'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Ensure/generate public token and return it (admin only)
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'ensure_token'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Public, read-only form definition (without requiring admin perms)
        register_rest_route('arshline/v1', '/public/forms/(?P<form_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_form'],
            'permission_callback' => '__return_true',
        ]);
        // Public by-token routes
        register_rest_route('arshline/v1', '/public/forms/by-token/(?P<token>[A-Za-z0-9]{8,24})', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_form_by_token'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('arshline/v1', '/public/forms/by-token/(?P<token>[A-Za-z0-9]{8,24})/submissions', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_submission_by_token'],
            'permission_callback' => '__return_true',
        ]);
        // Public by-token, HTMX-friendly submission endpoint (HTML fragment response)
        register_rest_route('arshline/v1', '/public/forms/by-token/(?P<token>[A-Za-z0-9]{8,24})/submit', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_submission_htmx_by_token'],
            'permission_callback' => '__return_true',
        ]);
        // Public, HTMX-friendly submission endpoint (form-encoded)
        register_rest_route('arshline/v1', '/public/forms/(?P<form_id>\\d+)/submit', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_submission_htmx'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/fields', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_fields'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/meta', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_meta'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/forms', [
            'methods' => 'POST',
            'callback' => [self::class, 'create_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [
                'title' => [ 'type' => 'string', 'required' => false ],
            ],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [self::class, 'delete_form'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/submissions', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_submissions'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create_submission'],
                'permission_callback' => '__return_true',
            ]
        ]);
        // Get a specific submission (with values)
        register_rest_route('arshline/v1', '/submissions/(?P<submission_id>\\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_submission'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Upload image (for admins/editors)
        register_rest_route('arshline/v1', '/upload', [
            'methods' => 'POST',
            'callback' => [self::class, 'upload_image'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [
                'file' => [ 'required' => false ],
            ],
        ]);
    }

    public static function get_forms(WP_REST_Request $request)
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $rows = $wpdb->get_results("SELECT id, status, meta, created_at FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        $data = array_map(function ($r) {
            $meta = json_decode($r['meta'] ?: '{}', true);
            return [
                'id' => (int)$r['id'],
                'title' => $meta['title'] ?? 'بدون عنوان',
                'status' => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }, $rows ?: []);
        return new WP_REST_Response($data, 200);
    }

    public static function create_form(WP_REST_Request $request)
    {
        $title = trim((string)($request->get_param('title') ?? 'فرم بدون عنوان'));
        $formData = [
            'schema_version' => '1.0.0',
            'owner_id' => get_current_user_id(),
            'status' => 'draft',
            'meta' => [ 'title' => $title ],
        ];
        $form = new Form($formData);
        $id = FormRepository::save($form);
        if ($id > 0) {
            return new WP_REST_Response([ 'id' => $id, 'title' => $title, 'status' => 'draft' ], 201);
        }
        global $wpdb;
        $err = $wpdb->last_error ?: 'unknown_db_error';
        return new WP_REST_Response([ 'error' => 'create_failed', 'message' => $err ], 500);
    }

    public static function get_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        // Ensure token exists when an admin/editor opens the builder (backfill on-read)
        if (self::user_can_manage_forms() && empty($form->public_token)) {
            // Save will generate a token if missing
            FormRepository::save($form);
            // Re-fetch to include the generated token
            $form = FormRepository::find($id) ?: $form;
        }
        $fields = FieldRepository::listByForm($id);
        $payload = [
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $form->meta,
            'fields' => $fields,
        ];
        // Expose token only to users who can manage forms (admin/editor)
        if (self::user_can_manage_forms() && !empty($form->public_token)) {
            $payload['token'] = $form->public_token;
        }
        return new WP_REST_Response($payload, 200);
    }

    public static function get_form_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        $fields = FieldRepository::listByForm($form->id);
        return new WP_REST_Response([
            'id' => $form->id,
            'token' => $form->public_token,
            'status' => $form->status,
            'meta' => $form->meta,
            'fields' => $fields,
        ], 200);
    }

    public static function update_fields(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $fields = $request->get_param('fields');
        if (!is_array($fields)) $fields = [];
        FieldRepository::replaceAll($id, $fields);
        return new WP_REST_Response(['ok'=>true], 200);
    }

    public static function update_meta(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        $meta = $request->get_param('meta');
        if (!is_array($meta)) $meta = [];
        $form->meta = array_merge(is_array($form->meta)?$form->meta:[], $meta);
        FormRepository::save($form);
        return new WP_REST_Response(['ok'=>true, 'meta'=>$form->meta], 200);
    }

    public static function get_submissions(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response(['total'=>0, 'rows'=>[]], 200);
        // optional pagination & filters
        $page = (int)($request->get_param('page') ?? 1);
        $per_page = (int)($request->get_param('per_page') ?? 20);
        $debugFlag = (string)($request->get_param('debug') ?? '') === '1';
        // Build filters safely (avoid closures that don't capture $request)
        $statusParam = $request->get_param('status');
        $fromParam = $request->get_param('from');
        $toParam = $request->get_param('to');
        $searchParam = $request->get_param('search');
        $answersParam = $request->get_param('answers');
        $fParam = $request->get_param('f');
        $opParam = $request->get_param('op');
        $fieldFilters = [];
        if (is_array($fParam)){
            foreach ($fParam as $k => $v){
                $fid = (int)$k; $sv = is_scalar($v) ? (string)$v : '';
                if ($fid>0 && $sv !== ''){ $fieldFilters[$fid] = $sv; }
            }
        }
        $fieldOps = [];
        if (is_array($opParam)){
            foreach ($opParam as $k => $v){
                $fid = (int)$k; $sv = is_scalar($v) ? strtolower((string)$v) : '';
                if ($fid>0 && in_array($sv, ['eq','neq','like'], true)) { $fieldOps[$fid] = $sv; }
            }
        }
        $filters = [
            'status' => $statusParam ?: null,
            'from' => $fromParam ?: null,
            'to' => $toParam ?: null,
            'search' => $searchParam !== null ? (string)$searchParam : null,
            // New: full-text search within answers (submission_values.value)
            'answers' => $answersParam !== null ? (string)$answersParam : null,
            'field_filters' => $fieldFilters,
            'field_ops' => $fieldOps,
        ];
        $include = (string)($request->get_param('include') ?? ''); // values,fields
        // export all as CSV when format=csv, or Excel-compatible when format=excel
        $format = (string)($request->get_param('format') ?? '');
        if ($format === 'csv' || $format === 'excel') {
            // For exports, always fetch all (listByFormAll already respects filters and ignores pagination)
            $all = SubmissionRepository::listByFormAll($form_id, $filters);
            // Optional: include answers as separate columns (wide CSV)
            $fields = FieldRepository::listByForm($form_id);
            $fieldOrder = [];
            $fieldLabels = [];
            $choices = [];
            foreach ($fields as $f){
                $fid = (int)$f['id']; $p = is_array($f['props'])? $f['props'] : [];
                $fieldOrder[] = $fid; $fieldLabels[$fid] = $p['question'] ?? ('فیلد #'.$fid);
                if (!empty($p['options']) && is_array($p['options'])){
                    foreach ($p['options'] as $opt){ $val = (string)($opt['value'] ?? $opt['label'] ?? ''); $lab = (string)($opt['label'] ?? $val); if ($val !== ''){ $choices[$fid][$val] = $lab; } }
                }
            }
            $ids = array_map(function($r){ return (int)$r['id']; }, $all);
            $valsMap = SubmissionRepository::listValuesBySubmissionIds($ids);
            $out = [];
            // Drop status per request; include id, created_at, and summary
            $header = ['id','created_at','summary'];
            foreach ($fieldOrder as $fid){ $header[] = $fieldLabels[$fid]; }
            $out[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $header));
            foreach ($all as $r){
                $summary = '';
                if (is_array($r['meta']) && isset($r['meta']['summary'])){ $summary = (string)$r['meta']['summary']; }
                $row = [ $r['id'], (string)($r['created_at'] ?? ''), $summary ];
                $answers = [];
                $vals = isset($valsMap[$r['id']]) ? $valsMap[$r['id']] : [];
                // Map field_id => value (first occurrence)
                $byField = [];
                foreach ($vals as $v){ $fid = (int)$v['field_id']; if (!isset($byField[$fid])){ $byField[$fid] = (string)$v['value']; } }
                foreach ($fieldOrder as $fid){
                    $ans = isset($byField[$fid]) ? $byField[$fid] : '';
                    if (isset($choices[$fid]) && isset($choices[$fid][$ans])){ $ans = $choices[$fid][$ans]; }
                    $answers[] = $ans;
                }
                $row = array_merge($row, $answers);
                $out[] = implode(',', array_map(function($v){ $v = (string)$v; return '"'.str_replace('"','""',$v).'"'; }, $row));
            }
            $csv = "\xEF\xBB\xBF" . implode("\r\n", $out);
            $resp = new WP_REST_Response($csv, 200);
            if ($format === 'excel') {
                // Excel-friendly headers; we still send CSV content for simplicity/compatibility
                $resp->header('Content-Type', 'application/vnd.ms-excel; charset=utf-8');
                $resp->header('Content-Disposition', 'attachment; filename="submissions-'.$form_id.'.xls"');
            } else {
                $resp->header('Content-Type', 'text/csv; charset=utf-8');
                $resp->header('Content-Disposition', 'attachment; filename="submissions-'.$form_id.'.csv"');
            }
            return $resp;
        }
        $res = SubmissionRepository::listByFormPaged($form_id, $page, $per_page, $filters);
        $rows = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
                'summary' => is_array($r['meta']) ? ($r['meta']['summary'] ?? null) : null,
            ];
        }, $res['rows'] ?: []);
        // include values (and fields) when requested, so the dashboard can render full grid
        if ($include === 'values' || $include === 'values,fields' || $include === 'fields,values'){
            $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
            $valsMap = SubmissionRepository::listValuesBySubmissionIds($ids);
            foreach ($rows as &$row){ $row['values'] = isset($valsMap[$row['id']]) ? $valsMap[$row['id']] : []; }
            unset($row);
        }
        $payload = [
            'total' => (int)$res['total'],
            'rows' => $rows,
            'page' => (int)$res['page'],
            'per_page' => (int)$res['per_page'],
        ];
        if (strpos($include, 'fields') !== false){ $payload['fields'] = FieldRepository::listByForm($form_id); }
        // Optional debug details for admins/editors only
        if ($debugFlag && self::user_can_manage_forms()){
            global $wpdb;
            $payload['debug'] = [
                'db_last_error' => (string)($wpdb->last_error ?? ''),
                'db_last_query' => (string)($wpdb->last_query ?? ''),
            ];
        }
        return new WP_REST_Response($payload, 200);
    }

    public static function get_submission(WP_REST_Request $request)
    {
        $sid = (int)$request['submission_id'];
        if ($sid <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $data = SubmissionRepository::findWithValues($sid);
        if (!$data) return new WP_REST_Response(['error'=>'not_found'], 404);
        // Also include field meta to render labels
        $fields = FieldRepository::listByForm((int)$data['form_id']);
        return new WP_REST_Response(['submission' => $data, 'fields' => $fields], 200);
    }

    public static function create_submission(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response(['error' => 'invalid_form_id'], 400);
        $values = $request->get_param('values');
        if (!is_array($values)) $values = [];
        // Load schema for validation
        $fields = FieldRepository::listByForm($form_id);
        $valErrors = FormValidator::validateSubmission($fields, $values);
        if (!empty($valErrors)) {
            return new WP_REST_Response(['error' => 'validation_failed', 'messages' => $valErrors], 422);
        }
        $submissionData = [
            'form_id' => $form_id,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status' => 'pending',
            'meta' => [ 'summary' => 'ایجاد از REST' ],
            'values' => $values,
        ];
        $submission = new Submission($submissionData);
        $id = SubmissionRepository::save($submission);
        foreach ($values as $idx => $entry) {
            $field_id = (int)($entry['field_id'] ?? 0);
            $value = $entry['value'] ?? '';
            if ($field_id > 0) {
                SubmissionValueRepository::save($id, $field_id, $value, $idx);
            }
        }
        return new WP_REST_Response([ 'id' => $id, 'form_id' => $form_id, 'status' => 'pending' ], 201);
    }

    public static function create_submission_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) return new WP_REST_Response(['error' => 'invalid_form_token'], 404);
        $request['form_id'] = $form->id;
        return self::create_submission($request);
    }

    /**
     * Public submission handler for HTMX (accepts form-encoded fields like field_{id}).
     * Returns a small HTML fragment suitable for hx-swap.
     */
    public static function create_submission_htmx(WP_REST_Request $request)
    {
        $form_id = (int)$request['form_id'];
        if ($form_id <= 0) return new WP_REST_Response('<div class="ar-alert ar-alert--err">شناسه فرم نامعتبر است.</div>', 200);

        // Load schema for validation
        $fields = FieldRepository::listByForm($form_id);
        $params = $request->get_params();
        $values = [];
        foreach ($fields as $f) {
            $fid = (int)($f['id'] ?? 0);
            if ($fid <= 0) continue;
            $key = 'field_' . $fid;
            if (isset($params[$key])) {
                $values[] = [ 'field_id' => $fid, 'value' => $params[$key] ];
            }
        }
        $valErrors = FormValidator::validateSubmission($fields, $values);
        if (!empty($valErrors)) {
            $html = '<div class="ar-alert ar-alert--err"><div>خطا در اعتبارسنجی:</div><ul style="margin:6px 0;">';
            foreach ($valErrors as $msg) { $html .= '<li>' . esc_html($msg) . '</li>'; }
            $html .= '</ul></div>';
            return new WP_REST_Response($html, 200);
        }

        $submissionData = [
            'form_id' => $form_id,
            'user_id' => get_current_user_id(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status' => 'pending',
            'meta' => [ 'summary' => 'ایجاد از HTMX' ],
            'values' => $values,
        ];
        $submission = new Submission($submissionData);
        $id = SubmissionRepository::save($submission);
        foreach ($values as $idx => $entry) {
            $field_id = (int)($entry['field_id'] ?? 0);
            $value = $entry['value'] ?? '';
            if ($field_id > 0) {
                SubmissionValueRepository::save($id, $field_id, $value, $idx);
            }
        }
        $ok = '<div class="ar-alert ar-alert--ok">با موفقیت ثبت شد. شناسه: ' . (int)$id . '</div>';
        return new WP_REST_Response($ok, 200);
    }

    /**
     * Public HTMX submission by token (returns HTML fragment like create_submission_htmx)
     */
    public static function create_submission_htmx_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) {
            return new WP_REST_Response('<div class="ar-alert ar-alert--err">فرم یافت نشد.</div>', 200);
        }
        $request['form_id'] = $form->id;
        return self::create_submission_htmx($request);
    }

    /**
     * Output HTML directly for HTMX submit endpoints so that the response is not JSON-encoded.
     * This intercepts REST serving for our specific /public/forms/.../submit routes.
     */
    public static function serve_htmx_html($served, $result, $request, $server)
    {
        try {
            $route = is_object($request) && method_exists($request, 'get_route') ? (string)$request->get_route() : '';
            // Detect HTMX header and/or our submit routes
            $hx = '';
            if (is_object($request) && method_exists($request, 'get_header')) {
                $hx = (string)($request->get_header('hx-request') ?: $request->get_header('HX-Request'));
            }
            $isSubmit = ($route && strpos($route, '/arshline/v1/public/forms/') === 0 && strpos($route, '/submit') !== false) || strtolower($hx) === 'true';
            // Also allow raw CSV passthrough for submissions export
            $isCsv = ($route && preg_match('#/arshline/v1/forms/\d+/submissions$#', $route) && isset($_GET['format']) && $_GET['format'] === 'csv');
            if (!$isSubmit && !$isCsv) { return $served; }
            // Extract string content from response
            if ($result instanceof \WP_REST_Response) {
                $data = $result->get_data();
                $status = (int)$result->get_status();
            } else {
                $data = $result;
                $status = 200;
            }
            if (!is_string($data)) { return $served; }
            // Serve as text/html
            if (method_exists($server, 'send_header')) {
                $ctype = $isCsv ? 'text/csv; charset=utf-8' : 'text/html; charset=utf-8';
                $server->send_header('Content-Type', $ctype);
                $server->send_header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                if ($isCsv) { $server->send_header('Content-Disposition', 'attachment; filename="submissions.csv"'); }
            }
            if (method_exists($server, 'set_status')) { $server->set_status($status); }
            echo $data;
            return true;
        } catch (\Throwable $e) {
            return $served;
        }
    }

    public static function delete_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $ok = FormRepository::delete($id);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok ? 200 : 404);
    }

    /**
     * Ensures a public_token exists for form and returns it. Admin/editor only.
     */
    public static function ensure_token(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        if (empty($form->public_token)) {
            FormRepository::save($form);
            $form = FormRepository::find($id) ?: $form;
        }
        return new WP_REST_Response(['token' => (string)$form->public_token], 200);
    }

    public static function upload_image(WP_REST_Request $request)
    {
        if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
        if (!function_exists('wp_insert_attachment')) require_once(ABSPATH . 'wp-admin/includes/image.php');
        $files = $request->get_file_params();
        if (!isset($files['file'])){
            return new WP_REST_Response(['error' => 'no_file'], 400);
        }
        $file = $files['file'];
        // Allow only images
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
        $type = $file['type'] ?? '';
        if ($type && !in_array($type, $allowed, true)){
            return new WP_REST_Response(['error' => 'invalid_type'], 415);
        }
        add_filter('upload_dir', function($dirs){
            $dirs['subdir'] = '/arshline';
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']  = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        });
        $overrides = [ 'test_form' => false ];
        $movefile = wp_handle_upload($file, $overrides);
        remove_all_filters('upload_dir');
        if (!$movefile || isset($movefile['error'])){
            return new WP_REST_Response(['error' => 'upload_failed', 'message' => $movefile['error'] ?? ''], 500);
        }
        // Return URL only
        return new WP_REST_Response([ 'url' => $movefile['url'] ], 201);
    }
}

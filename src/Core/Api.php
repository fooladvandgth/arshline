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
use Arshline\Core\Ai\Hoshyar;
use Arshline\Support\Audit;

class Api
{
    /**
     * Normalize and sanitize supported meta keys to expected types.
     * Unknown keys are left as-is to keep flexibility, but callers should prefer known keys.
     */
    protected static function sanitize_meta_input(array $in): array
    {
        $out = $in;
        // Booleans
        if (array_key_exists('anti_spam_honeypot', $in)) {
            $out['anti_spam_honeypot'] = (bool)$in['anti_spam_honeypot'];
        }
        if (array_key_exists('captcha_enabled', $in)) {
            $out['captcha_enabled'] = (bool)$in['captcha_enabled'];
        }
        // Integers with bounds
        if (array_key_exists('min_submit_seconds', $in)) {
            $out['min_submit_seconds'] = max(0, (int)$in['min_submit_seconds']);
        }
        if (array_key_exists('rate_limit_per_min', $in)) {
            $out['rate_limit_per_min'] = max(0, (int)$in['rate_limit_per_min']);
        }
        if (array_key_exists('rate_limit_window_min', $in)) {
            $out['rate_limit_window_min'] = max(1, (int)$in['rate_limit_window_min']);
        }
        // Captcha keys (allow limited charset)
        $allowKey = function($v) {
            $v = is_scalar($v) ? (string)$v : '';
            $v = trim($v);
            // Allow common recaptcha key charset
            $v = preg_replace('/[^A-Za-z0-9_\-\.:]/', '', $v);
            // Limit length
            return substr($v, 0, 200);
        };
        if (array_key_exists('captcha_site_key', $in)) {
            $out['captcha_site_key'] = $allowKey($in['captcha_site_key']);
        }
        if (array_key_exists('captcha_secret_key', $in)) {
            $out['captcha_secret_key'] = $allowKey($in['captcha_secret_key']);
        }
        if (array_key_exists('captcha_version', $in)) {
            $v = is_scalar($in['captcha_version']) ? (string)$in['captcha_version'] : 'v2';
            $v = ($v === 'v3') ? 'v3' : 'v2';
            $out['captcha_version'] = $v;
        }
        // Design keys
        $sanitize_color = function($v, $fallback) {
            $s = is_scalar($v) ? (string)$v : '';
            $s = trim($s);
            if (preg_match('/^#([A-Fa-f0-9]{6})$/', $s)) return $s;
            return $fallback;
        };
        if (array_key_exists('design_primary', $in)) {
            $out['design_primary'] = $sanitize_color($in['design_primary'], '#1e40af');
        }
        if (array_key_exists('design_bg', $in)) {
            $out['design_bg'] = $sanitize_color($in['design_bg'], '#f5f7fb');
        }
        if (array_key_exists('design_theme', $in)) {
            $v = is_scalar($in['design_theme']) ? (string)$in['design_theme'] : 'light';
            $v = ($v === 'dark') ? 'dark' : 'light';
            $out['design_theme'] = $v;
        }
        return $out;
    }
    /**
     * Sanitize global settings payload and enforce defaults/limits.
     */
    protected static function sanitize_settings_input(array $in): array
    {
        $out = self::sanitize_meta_input($in);
        // Upload constraints
        if (array_key_exists('upload_max_kb', $in)) {
            $kb = (int)$in['upload_max_kb'];
            $out['upload_max_kb'] = max(50, min(4096, $kb));
        }
        if (array_key_exists('block_svg', $in)) {
            $out['block_svg'] = (bool)$in['block_svg'];
        }
        // AI options
        if (array_key_exists('ai_enabled', $in)) {
            $out['ai_enabled'] = (bool)$in['ai_enabled'];
        }
        if (array_key_exists('ai_spam_threshold', $in)) {
            $t = is_numeric($in['ai_spam_threshold']) ? (float)$in['ai_spam_threshold'] : 0.5;
            $out['ai_spam_threshold'] = max(0.0, min(1.0, $t));
        }
        if (array_key_exists('ai_model', $in)) {
            $m = is_scalar($in['ai_model']) ? trim((string)$in['ai_model']) : '';
            // keep arbitrary model name but constrain length and charset
            $m = preg_replace('/[^A-Za-z0-9_\-\.:\/]/', '', $m);
            $out['ai_model'] = substr($m, 0, 100);
        }
        return $out;
    }

    /**
     * Load global settings from WP options with defaults and sanitization.
     */
    protected static function get_global_settings(): array
    {
        $defaults = [
            'anti_spam_honeypot' => false,
            'min_submit_seconds' => 0,
            'rate_limit_per_min' => 0,
            'rate_limit_window_min' => 1,
            'captcha_enabled' => false,
            'captcha_site_key' => '',
            'captcha_secret_key' => '',
            'captcha_version' => 'v2',
            'upload_max_kb' => 300,
            'block_svg' => true,
            'ai_enabled' => false,
            'ai_spam_threshold' => 0.5,
            'ai_model' => 'gpt-4o-mini',
        ];
        $raw = get_option('arshline_settings', []);
        $arr = is_array($raw) ? $raw : [];
        $san = self::sanitize_settings_input($arr);
        return array_merge($defaults, $san);
    }
    protected static function flag(array $meta, string $key, bool $default=false): bool
    {
        if (!array_key_exists($key, $meta)) return $default;
        return (bool)$meta[$key];
    }
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
        // Basic dashboard/reporting stats
        register_rest_route('arshline/v1', '/stats', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_stats'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [
                'days' => [ 'type' => 'integer', 'required' => false ],
            ],
        ]);
        // Global settings (admin-only)
        register_rest_route('arshline/v1', '/settings', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_settings'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_settings'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ]
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
            // Use a public-safe getter that enforces status gating
            'callback' => [self::class, 'get_public_form'],
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
        // Update form (status toggle: draft|published|disabled)
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)', [
            'methods' => 'PUT',
            'callback' => [self::class, 'update_form'],
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
        // AI configuration and agent endpoints (admin-only)
        register_rest_route('arshline/v1', '/ai/config', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_ai_config'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_ai_config'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ]
        ]);
        register_rest_route('arshline/v1', '/ai/test', [
            'methods' => 'POST',
                'callback' => [self::class, 'test_ai_connect'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/ai/capabilities', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_ai_capabilities'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        register_rest_route('arshline/v1', '/ai/agent', [
            'methods' => 'POST',
            'callback' => [self::class, 'ai_agent'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
        ]);
        // Audit log and undo endpoints (admin-only)
        register_rest_route('arshline/v1', '/ai/audit', [
            'methods' => 'GET',
            'callback' => [self::class, 'list_audit'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [ 'limit' => [ 'type' => 'integer', 'required' => false ] ],
        ]);
        register_rest_route('arshline/v1', '/ai/undo', [
            'methods' => 'POST',
            'callback' => [self::class, 'undo_by_token'],
            'permission_callback' => [self::class, 'user_can_manage_forms'],
            'args' => [ 'token' => [ 'type' => 'string', 'required' => true ] ],
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

    /**
     * GET /stats — Returns simple KPI counts and a submissions time series for last N days (default 30)
     */
    public static function get_stats(WP_REST_Request $request)
    {
        global $wpdb;
        $forms = Helpers::tableName('forms');
        $subs = Helpers::tableName('submissions');

        // Counts
        $total_forms = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$forms}");
        $draft_forms = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$forms} WHERE status = %s", 'draft'));
        $disabled_forms = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$forms} WHERE status = %s", 'disabled'));
        // Active is strictly published; we also surface disabled separately
        $active_forms = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$forms} WHERE status = %s", 'published'));
        $total_submissions = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$subs}");
        // WP users
        $users_count = 0;
        if (function_exists('count_users')) {
            $cu = count_users();
            $users_count = isset($cu['total_users']) ? (int)$cu['total_users'] : 0;
        } else {
            // Fallback minimal query if needed
            $users_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        }

        // Time series for submissions per day
        $days = (int)$request->get_param('days');
        if ($days <= 0) $days = 30;
        $days = min(max($days, 7), 180); // clamp 7..180
        // Build date buckets in PHP to include days with zero
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $start = $now->sub(new \DateInterval('P'.($days-1).'D'));
        $labels = [];
        $map = [];
        for ($i = 0; $i < $days; $i++){
            $d = $start->add(new \DateInterval('P'.$i.'D'));
            $key = $d->format('Y-m-d');
            $labels[] = $key;
            $map[$key] = 0;
        }
        // Query grouped counts from DB (UTC assumed by MySQL; we use DATE of created_at)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$subs} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC",
            $start->format('Y-m-d 00:00:00')
        ), ARRAY_A) ?: [];
        foreach ($rows as $r){
            $d = (string)$r['d'];
            $c = (int)$r['c'];
            if (isset($map[$d])) $map[$d] = $c;
        }
        $series = array_values($map);

        $out = [
            'counts' => [
                'forms' => $total_forms,
                'forms_active' => $active_forms,
                'forms_draft' => $draft_forms,
                'forms_disabled' => $disabled_forms,
                'submissions' => $total_submissions,
                'users' => $users_count,
            ],
            'series' => [
                'labels' => $labels,
                'submissions_per_day' => $series,
            ],
        ];
        return new WP_REST_Response($out, 200);
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
            // Log audit entry with undo token
            $undo = Audit::log('create_form', 'form', $id, [], [ 'form' => [ 'id'=>$id, 'title'=>$title, 'status'=>'draft' ] ]);
            return new WP_REST_Response([ 'id' => $id, 'title' => $title, 'status' => 'draft', 'undo_token' => $undo ], 201);
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
        // Ensure token exists for published forms only (avoid generating for drafts/disabled)
        if (self::user_can_manage_forms() && $form->status === 'published' && empty($form->public_token)) {
            FormRepository::save($form);
            $form = FormRepository::find($id) ?: $form;
        }
        $fields = FieldRepository::listByForm($id);
        $payload = [
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $form->meta,
            'fields' => $fields,
        ];
        // Expose token only for published forms to users who can manage
        if (self::user_can_manage_forms() && $form->status === 'published' && !empty($form->public_token)) {
            $payload['token'] = $form->public_token;
        }
        return new WP_REST_Response($payload, 200);
    }

    /**
     * Public-safe: only returns form definition when status is published.
     * Hides token field and returns 403 for draft/disabled forms.
     */
    public static function get_public_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
        $fields = FieldRepository::listByForm($id);
        $payload = [
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $form->meta,
            'fields' => $fields,
        ];
        return new WP_REST_Response($payload, 200);
    }

    /**
     * GET /settings (admin-only)
     */
    public static function get_settings(WP_REST_Request $request)
    {
        $settings = self::get_global_settings();
        return new WP_REST_Response(['settings' => $settings], 200);
    }

    /**
     * PUT /settings (admin-only)
     */
    public static function update_settings(WP_REST_Request $request)
    {
        $data = $request->get_param('settings');
        if (!is_array($data)) $data = [];
        $san = self::sanitize_settings_input($data);
        $current = get_option('arshline_settings', []);
        $cur = is_array($current) ? $current : [];
        $merged = array_merge($cur, $san);
        update_option('arshline_settings', $merged, false);
        $out = self::get_global_settings();
        return new WP_REST_Response(['ok'=>true, 'settings'=>$out], 200);
    }

    // ---- AI config helpers & endpoints ----
    protected static function get_ai_settings(): array
    {
        $raw = get_option('arshline_settings', []);
        $arr = is_array($raw) ? $raw : [];
        $base = isset($arr['ai_base_url']) && is_scalar($arr['ai_base_url']) ? trim((string)$arr['ai_base_url']) : '';
        // normalize base URL (no trailing spaces, keep as-is otherwise to allow custom paths)
        $base = substr($base, 0, 500);
        $key = isset($arr['ai_api_key']) && is_scalar($arr['ai_api_key']) ? trim((string)$arr['ai_api_key']) : '';
        $key = substr($key, 0, 2000);
        $enabled = isset($arr['ai_enabled']) ? (bool)$arr['ai_enabled'] : false;
        $model = isset($arr['ai_model']) && is_scalar($arr['ai_model']) ? (string)$arr['ai_model'] : 'gpt-4o-mini';
    $parser = isset($arr['ai_parser']) && is_scalar($arr['ai_parser']) ? (string)$arr['ai_parser'] : 'hybrid'; // 'internal' | 'hybrid' | 'llm'
    $parser = in_array($parser, ['internal','hybrid','llm'], true) ? $parser : 'hybrid';
        return [ 'base_url' => $base, 'api_key' => $key, 'enabled' => $enabled, 'model' => $model, 'parser' => $parser ];
    }
    public static function get_ai_config(WP_REST_Request $request)
    {
        return new WP_REST_Response(['config' => self::get_ai_settings()], 200);
    }
    public static function update_ai_config(WP_REST_Request $request)
    {
        $cfg = $request->get_param('config');
        if (!is_array($cfg)) $cfg = [];
        $base = is_scalar($cfg['base_url'] ?? '') ? trim((string)$cfg['base_url']) : '';
        $key  = is_scalar($cfg['api_key'] ?? '') ? trim((string)$cfg['api_key']) : '';
        $enabled = (bool)($cfg['enabled'] ?? false);
        $model = is_scalar($cfg['model'] ?? '') ? trim((string)$cfg['model']) : '';
    $parser = is_scalar($cfg['parser'] ?? '') ? trim((string)$cfg['parser']) : '';
        $cur = get_option('arshline_settings', []);
        $arr = is_array($cur) ? $cur : [];
        $arr['ai_base_url'] = substr($base, 0, 500);
        $arr['ai_api_key']  = substr($key, 0, 2000);
        $arr['ai_enabled']  = $enabled;
        if ($model !== ''){ $arr['ai_model'] = substr(preg_replace('/[^A-Za-z0-9_\-\.:\/]/', '', $model), 0, 100); }
    if ($parser !== ''){ $arr['ai_parser'] = in_array($parser, ['internal','hybrid','llm'], true) ? $parser : 'hybrid'; }
        update_option('arshline_settings', $arr, false);
        return new WP_REST_Response(['ok'=>true, 'config'=> self::get_ai_settings()], 200);
    }
    public static function test_ai_connect(WP_REST_Request $request)
    {
        $s = self::get_ai_settings();
        if (!$s['enabled'] || !$s['base_url'] || !$s['api_key']){
            return new WP_REST_Response(['ok'=>false, 'error'=>'missing_config'], 400);
        }
        $url = rtrim($s['base_url'], '/').'/';
        $resp = wp_remote_get($url, [ 'timeout'=>5, 'headers'=> [ 'Authorization' => 'Bearer '.$s['api_key'] ] ]);
        if (is_wp_error($resp)) return new WP_REST_Response(['ok'=>false, 'error'=>'network'], 502);
        $code = (int)wp_remote_retrieve_response_code($resp);
        return new WP_REST_Response(['ok'=> ($code>=200 && $code<500), 'status'=>$code ], 200);
    }
    public static function get_ai_capabilities(WP_REST_Request $request)
    {
        $caps = [
            'navigation' => [
                'title' => 'مسیر‌یابی',
                'items' => [
                    [ 'id' => 'open_tab', 'label' => 'باز کردن تب‌ها', 'params' => ['tab' => 'dashboard|forms|reports|users|settings', 'section?' => 'security|ai|users'], 'confirm' => false, 'examples' => [ 'باز کردن تنظیمات', 'باز کردن فرم‌ها' ] ],
                    [ 'id' => 'open_builder', 'label' => 'باز کردن ویرایشگر فرم', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'ویرایش فرم 12' ] ],
                    [ 'id' => 'open_editor', 'label' => 'ویرایش یک پرسش خاص', 'params' => ['id' => 'number', 'index' => 'number (0-based)'], 'confirm' => false, 'examples' => [ 'ویرایش پرسش 1 فرم 12' ] ],
                    [ 'id' => 'public_link', 'label' => 'دریافت لینک عمومی فرم', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'لینک عمومی فرم 7' ] ],
                ],
            ],
            'forms' => [
                'title' => 'فرم‌ها',
                'items' => [
                    [ 'id' => 'list_forms', 'label' => 'نمایش لیست فرم‌ها', 'params' => [], 'confirm' => false, 'examples' => [ 'لیست فرم ها' ] ],
                    [ 'id' => 'create_form', 'label' => 'ایجاد فرم جدید', 'params' => ['title' => 'string'], 'confirm' => true, 'examples' => [ 'ایجاد فرم با عنوان فرم تست' ] ],
                    [ 'id' => 'delete_form', 'label' => 'حذف فرم', 'params' => ['id' => 'number'], 'confirm' => true, 'examples' => [ 'حذف فرم 5' ] ],
                    [ 'id' => 'open_form', 'label' => 'فعال کردن فرم (انتشار)', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'فعال کن فرم 3', 'انتشار فرم 3' ] ],
                    [ 'id' => 'close_form', 'label' => 'بستن/غیرفعال کردن فرم', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'غیرفعال کن فرم 8', 'بستن فرم 8' ] ],
                    [ 'id' => 'draft_form', 'label' => 'بازگرداندن به پیش‌نویس', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'پیش‌نویس کن فرم 4' ] ],
                    [ 'id' => 'update_form_title', 'label' => 'تغییر عنوان فرم', 'params' => ['id' => 'number', 'title' => 'string'], 'confirm' => true, 'examples' => [ 'عنوان فرم 2 را به فرم مشتریان تغییر بده' ] ],
                    [ 'id' => 'export_csv', 'label' => 'خروجی CSV از ارسال‌ها', 'params' => ['id' => 'number'], 'confirm' => false, 'examples' => [ 'خروجی فرم 5' ] ],
                ],
            ],
            'settings' => [
                'title' => 'تنظیمات',
                'items' => [
                    [ 'id' => 'set_setting', 'label' => 'تغییر تنظیمات سراسری', 'params' => ['key' => 'ai_enabled|min_submit_seconds|rate_limit_per_min|block_svg|ai_model|ai_parser', 'value' => 'string|number|boolean'], 'confirm' => true, 'examples' => [ 'فعال کردن هوش مصنوعی', 'مدل را روی gpt-5-mini بگذار', 'تحلیل دستورات با اوپن‌ای‌آی', 'تحلیلگر را هیبرید کن' ] ],
                    [ 'id' => 'ui', 'label' => 'اقدامات رابط کاربری', 'params' => ['target' => 'toggle_theme|undo|go_back|open_editor_index', 'index?' => 'number (0-based)'], 'confirm' => false, 'examples' => [ 'تم را تغییر بده', 'یک قدم برگرد', 'بازگردانی کن', 'پرسش 1 را باز کن' ] ],
                ],
            ],
            'ui' => [
                'title' => 'تعاملات UI',
                'items' => [
                    [ 'id' => 'toggle_theme', 'label' => 'روشن/تاریک', 'params' => [], 'confirm' => false, 'examples' => [ 'حالت تاریک را فعال کن' ] ],
                    [ 'id' => 'open_ai_terminal', 'label' => 'باز کردن ترمینال هوش مصنوعی', 'params' => [], 'confirm' => false, 'examples' => [ 'ترمینال هوش مصنوعی را باز کن' ] ],
                ],
            ],
            'help' => [
                'title' => 'کمک',
                'items' => [
                    [ 'id' => 'help', 'label' => 'نمایش راهنما و لیست توانمندی‌ها', 'params' => [], 'confirm' => false, 'examples' => [ 'کمک', 'لیست دستورات' ] ],
                ],
            ],
        ];
        return new WP_REST_Response(['ok' => true, 'capabilities' => $caps], 200);
    }
    public static function ai_agent(WP_REST_Request $request)
    {
        // Helper closures for fuzzy matching titles and normalizing Persian strings
        $normalize = function(string $s): string {
            $s = trim($s);
            $s = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $s); // ZWNJ,RLM
            $s = preg_replace('/\s+/u',' ', $s);
            return mb_strtolower($s, 'UTF-8');
        };
        $score_title = function(string $q, string $t) use ($normalize): float {
            $q = $normalize($q); $t = $normalize($t);
            if ($q === '' || $t === '') return 0.0;
            if (mb_strpos($t, $q, 0, 'UTF-8') !== false) return 1.0; // strong contains
            // fallback: normalized similarity (similar_text is byte-based; acceptable here)
            $a = $q; $b = $t; $pct = 0.0; similar_text($a, $b, $pct); return max(0.0, min(1.0, $pct/100.0));
        };
        $find_by_title = function(string $q, int $limit = 5) use ($score_title){
            $forms = self::get_forms_list();
            $scored = [];
            foreach ($forms as $f){
                $s = $score_title($q, (string)($f['title'] ?? ''));
                if ($s > 0){ $scored[] = [ 'id'=>(int)$f['id'], 'title'=>(string)$f['title'], 'score'=>$s ]; }
            }
            usort($scored, function($x,$y){ return $y['score'] <=> $x['score']; });
            return array_slice($scored, 0, max(1, $limit));
        };
        // New structured intents for Hoshyar (هوشیار)
        $intentName = (string)($request->get_param('intent') ?? '');
        $intentName = trim($intentName);
        if ($intentName !== ''){
            try {
                $params = $request->get_param('params');
                if (!is_array($params)) $params = [];
                $out = Hoshyar::agent([ 'intent' => $intentName, 'params' => $params ]);
                return new WP_REST_Response($out, 200);
            } catch (\Throwable $e) {
                return new WP_REST_Response(['ok'=>false,'error'=>'hoshyar_error'], 200);
            }
        }
        // Legacy command-based flow (backward-compat)
        $cmd = (string)($request->get_param('command') ?? '');
        $cmd = trim($cmd);
        // Execute previously confirmed action directly (accept legacy 'type' as well)
        $confirmPayload = $request->get_param('confirm_action');
        if (is_array($confirmPayload)){
            if (!isset($confirmPayload['action']) && isset($confirmPayload['type'])){
                $confirmPayload['action'] = (string)$confirmPayload['type'];
            }
            if (isset($confirmPayload['action'])){
                return self::execute_confirmed_action($confirmPayload);
            }
        }
    if ($cmd === '') return new WP_REST_Response(['ok'=>false,'error'=>'empty_command'], 200);
        try {
            // Help / capabilities
            if (preg_match('/^(کمک|راهنما|لیست\s*دستورات)$/u', $cmd)){
                $caps = self::get_ai_capabilities($request);
                $data = $caps instanceof WP_REST_Response ? $caps->get_data() : ['capabilities'=>[]];
                return new WP_REST_Response(['ok'=>true, 'action'=>'help', 'capabilities'=>$data['capabilities'] ?? []], 200);
            }
            // Create form: "ایجاد فرم با عنوان X"
            if (preg_match('/^ایجاد\s*فرم\s*با\s*عنوان\s*(.+)$/u', $cmd, $m)){
                $title = trim($m[1]);
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'ایجاد فرم جدید با عنوان «'.$title.'» تایید می‌کنید؟',
                    'confirm_action'=> [ 'action'=>'create_form', 'params'=>['title'=>$title] ]
                ], 200);
            }
            // Delete form: "حذف فرم <id>"
            if (preg_match('/^حذف\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'حذف فرم شماره '.$fid.' تایید می‌کنید؟ این عمل قابل بازگشت نیست.',
                    'confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$fid] ]
                ], 200);
            }
            // Delete form without id -> clarify
            if (preg_match('/^حذف\s*فرم\s*$/u', $cmd)){
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=>(int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را حذف کنم؟','param_key'=>'id','options'=>$list,'clarify_action'=>['action'=>'delete_form']], 200);
            }
            // Public link: "لینک عمومی فرم <id>"
            if (preg_match('/^لینک\s*عمومی\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('GET', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $res = self::get_form($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $status = isset($data['status']) ? (string)$data['status'] : '';
                $token = isset($data['token']) ? (string)$data['token'] : '';
                $url = ($token && $status==='published') ? home_url('/?arshline='.rawurlencode($token)) : '';
                return new WP_REST_Response(['ok'=> (bool)$url, 'url'=>$url, 'token'=>$token], 200);
            }
            // Activate/publish form: "فعال کن فرم <id>" | "انتشار فرم <id>"
            if (preg_match('/^(?:فعال\s*کن|فعال\s*کردن|انتشار)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'published']);
                $res = self::update_form($req);
                if ($res instanceof WP_REST_Response){ return $res; }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            // Disable/close form: "غیرفعال کن فرم <id>" | "بستن فرم <id>"
            if (preg_match('/^(?:غیرفعال\s*کن|غیرفعال\s*کردن|بستن)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'disabled']);
                $res = self::update_form($req);
                if ($res instanceof WP_REST_Response){ return $res; }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            // Draft form: "پیش نویس کن فرم <id>" | "بازگرداندن به پیش‌نویس فرم <id>"
            if (preg_match('/^(?:پیش\s*نویس\s*کن|پیش‌نویس\s*کن|بازگرداندن\s*به\s*پیش‌نویس)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'draft']);
                $res = self::update_form($req);
                if ($res instanceof WP_REST_Response){ return $res; }
                return new WP_REST_Response(['ok'=>true], 200);
            }
            // Update title: "عنوان فرم <id> را به X تغییر بده/بذار/کن"
            if (preg_match('/^عنوان\s*فرم\s*(\d+)\s*(?:را)?\s*به\s*(.+)\s*(?:تغییر\s*بده|تغییر\s*ده|بگذار|بذار|قرار\s*ده|کن)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                $title = trim((string)$m[2]);
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'عنوان فرم '.$fid.' به «'.$title.'» تغییر داده شود؟',
                    'confirm_action'=> [ 'action'=>'update_form_title', 'params'=>['id'=>$fid, 'title'=>$title] ]
                ], 200);
            }
            // Title-based open builder: "ویرایش/ادیت فرم {title}" when no numeric id
            if (preg_match('/^(?:ویرایش|ادیت|edit|باز\s*کردن|بازش\s*کن|سازنده)\s*فرم\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // Attempt Persian digit to int first
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    $m1 = $matches[0];
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                        'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                    ], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
                // fallthrough to unknown later
            }
            // Title-based open builder with form-first order: "فرم {title} رو ادیت/ویرایش کن"
            if (preg_match('/^فرم\s+(.+?)\s*(?:را|رو)?\s*(?:ویرایش|ادیت|edit)(?:\s*کن)?$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    $m1 = $matches[0];
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                        'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                    ], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
            }
            // Title-only to edit (implicit "فرم" omitted): "{title} رو ادیت کن" | "{title} را ویرایش کن"
            if (preg_match('/^(.+?)\s*(?:را|رو)\s*(?:ویرایش|ادیت|edit)(?:\s*کن)?$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // Ignore obvious generic words to reduce false positives
                if (!preg_match('/^(?:فرم|forms?)$/iu', $name)){
                    $matches = $find_by_title($name, 5);
                    if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                        $m1 = $matches[0];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                    if (!empty($matches)){
                        $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                        return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                    }
                }
            }
            // Title-based delete: "حذف فرم {title}"
            if (preg_match('/^(?:حذف|پاک(?:\s*کردن)?|delete)\s*فرم\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'حذف فرم شماره '.$num.' تایید می‌کنید؟ این عمل قابل بازگشت نیست.',
                        'confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$num] ]
                    ], 200);
                }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    $m1 = $matches[0];
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'آیا منظورتان حذف «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟ حذف غیرقابل بازگشت است.',
                        'confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$m1['id']] ]
                    ], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را حذف کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'delete_form']], 200);
                }
            }
            // List forms: "لیست فرم ها" | "نمایش فرم ها"
            if (preg_match('/^(لیست|نمایش)\s*فرم(?:\s*ها)?$/u', $cmd)){
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                // Normalize minimal list
                $list = array_map(function($r){ return [ 'id'=>(int)($r['id']??0), 'title'=>(string)($r['title']??'') ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true, 'forms'=>$list], 200);
            }
            // Open builder: "باز کردن فرم <id>" | "ویرایش فرم <id>"
            if (preg_match('/^(باز\s*کردن|ویرایش)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[2];
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$fid], 200);
            }
            // Open a specific question editor by index: "پرسش 1 را ویرایش کن" (optionally "در فرم 12")
            if (preg_match('/^پرسش\s*(\d+)\s*(?:را|رو)?\s*(?:ویرایش|ادیت|edit)\s*(?:کن)?(?:\s*در\s*فرم\s*(\d+))?$/u', $cmd, $m)){
                $qIndexHuman = (int)$m[1];
                $fid = isset($m[2]) ? (int)$m[2] : 0;
                $index = max(0, $qIndexHuman - 1); // convert to 0-based
                if ($fid > 0){
                    // We can go directly to editor if form id is specified
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_editor', 'id'=>$fid, 'index'=>$index], 200);
                }
                // Without form id, if currently in a builder context, UI can handle it; otherwise ask to clarify form
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'ui',
                    'target'=>'open_editor_index',
                    'index'=>$index,
                    'message'=>'در صورتی که در صفحه ویرایش فرم هستید، ویرایشگر پرسش '+($index+1)+' باز می‌شود؛ در غیر اینصورت لطفاً شماره فرم را مشخص کنید (مثلا: پرسش '+($index+1)+' فرم 12).'
                ], 200);
            }
            // Back/Undo: "برگرد" | "یک قدم برگرد" | "بازگردانی" | "آن‌دو" | "undo"
            if (preg_match('/^(?:برگرد|یک\s*قدم\s*برگرد|بازگردانی|آن‌?دو|undo)$/iu', $cmd)){
                return new WP_REST_Response(['ok'=>true, 'action'=>'ui', 'target'=>'go_back'], 200);
            }
            if (preg_match('/^(?:بازگردانی\s*کن|undo\s*کن|بازگردانی)$/iu', $cmd)){
                return new WP_REST_Response(['ok'=>true, 'action'=>'ui', 'target'=>'undo'], 200);
            }
            // Open builder without id -> clarify
            if (preg_match('/^(باز\s*کردن|ویرایش)\s*فرم\s*$/u', $cmd)){
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=>(int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'فرم مورد نظر برای باز کردن؟','param_key'=>'id','options'=>$list,'clarify_action'=>['action'=>'open_builder']], 200);
            }
            // Open tab: "باز کردن تنظیمات" | "باز کردن گزارشات" | "باز کردن فرم ها"
            if (preg_match('/^باز\s*کردن\s*(داشبورد|فرم\s*ها|گزارشات|کاربران|تنظیمات)$/u', $cmd, $m)){
                $map = [ 'داشبورد'=>'dashboard', 'فرم ها'=>'forms', 'فرمها'=>'forms', 'گزارشات'=>'reports', 'کاربران'=>'users', 'تنظیمات'=>'settings' ];
                $raw = (string)$m[1]; $raw = str_replace('‌',' ', $raw);
                $tab = $map[$raw] ?? ($raw === 'فرم ها' ? 'forms' : 'dashboard');
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$tab], 200);
            }
            // Open tab without target -> clarify
            if (preg_match('/^باز\s*کردن\s*$/u', $cmd)){
                $opts = [
                    ['label'=>'داشبورد', 'value'=>'dashboard'],
                    ['label'=>'فرم‌ها', 'value'=>'forms'],
                    ['label'=>'گزارشات', 'value'=>'reports'],
                    ['label'=>'کاربران', 'value'=>'users'],
                    ['label'=>'تنظیمات', 'value'=>'settings'],
                ];
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام بخش را باز کنم؟','param_key'=>'tab','options'=>$opts,'clarify_action'=>['action'=>'open_tab']], 200);
            }
            // Export CSV: "خروجی فرم <id>" | "دانلود csv فرم <id>"
            if (preg_match('/^(خروجی|دانلود)\s*(csv\s*)?فرم\s*(\d+)$/iu', $cmd, $m)){
                $fid = (int)$m[3];
                $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            // Open results by id: "نتایج فرم <id>" | "نمایش نتایج فرم <id>"
            if (preg_match('/^(?:نتایج|نمایش\s*نتایج)\s*فرم\s*(\d+)$/u', $cmd, $m)){
                $fid = (int)$m[1];
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>$fid], 200);
            }
            // Open results by title (no id)
            if (preg_match('/^(?:نتایج|نمایش\s*نتایج)\s*فرم\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    $m1 = $matches[0];
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'نتایج «'.$m1['title'].'» (شناسه '.$m1['id'].') نمایش داده شود؟',
                        'confirm_action'=> [ 'action'=>'open_results', 'params'=>['id'=>$m1['id']] ]
                    ], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'نتایج کدام فرم را نمایش دهم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_results']], 200);
                }
            }
            // Heuristic colloquial navigation: "منوی فرم‌ها رو باز کن"، "برو به فرم‌ها"، "منو تنظیمات"
            {
                $plain = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $cmd); // remove ZWNJ, RLM
                $hasNavVerb = preg_match('/(منو|منوی|باز\s*کن|باز|وا\s*کن|واکن|برو\s*به|برو|نمایش|نشون\s*بده)/u', $plain) === 1;
                $syns = [
                    'forms' => ['فرم ها','فرمها','فرم‌ها','فرم'],
                    'dashboard' => ['داشبورد','خانه'],
                    'reports' => ['گزارشات','گزارش','آمار'],
                    'users' => ['کاربران','کاربر','اعضا'],
                    'settings' => ['تنظیمات','تنظیم','ستینگ','پیکربندی'],
                ];
                $foundTab = '';
                foreach ($syns as $tabKey => $words){
                    foreach ($words as $w){ if ($w !== '' && mb_strpos($plain, $w) !== false){ $foundTab = $tabKey; break 2; } }
                }
                // Extra tolerance for 'forms' and 'dashboard' if not matched strictly
                if (!$foundTab){
                    // Any occurrence of 'فرم' (with/without half-space/plurals) → forms
                    if (preg_match('/فرم[\s‌]*ها?/u', $plain) || mb_strpos($plain, 'فرم') !== false){ $foundTab = 'forms'; }
                    // Any token starting with 'داشب' → dashboard
                    if (!$foundTab && mb_strpos($plain, 'داشب') !== false){ $foundTab = 'dashboard'; }
                }
                if ($foundTab && ($hasNavVerb || mb_strpos($plain, 'منو') !== false)){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$foundTab], 200);
                }
                // Tolerate common typos for dashboard: e.g., "داشبودر" → detect via prefix 'داشب'
                if (!$foundTab && ($hasNavVerb || mb_strpos($plain, 'منو') !== false)){
                    if (mb_strpos($plain, 'داشب') !== false){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>'dashboard'], 200);
                    }
                }
            }
            // Colloquial Persian digit map for id parsing
            $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
            $toInt = function($s) use ($fa2en){ return (int) strtr(preg_replace('/\D+/u','', (string)$s), $fa2en); };

            // Colloquial: open/edit form builder by id
                        if (preg_match('/^(ویرایش|ادیت|edit|باز(?:\s*کن)?|بازش\s*کن|سازنده)\s*فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)
                            || preg_match('/^فرم\s*([0-9۰-۹]+)\s*(?:را|رو)?\s*(ویرایش|ادیت|edit|باز)(?:\s*کن)?$/iu', $cmd, $m)){
                $fid = $toInt($m[2] ?? $m[1]);
                if ($fid > 0){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$fid], 200);
                }
            }

            // Colloquial: delete form by id (confirmation)
            if (preg_match('/^(حذف|پاک(?:\s*کردن)?|دلیت|delete)\s*فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)
              || preg_match('/^فرم\s*([0-9۰-۹]+)\s*(?:را|رو)?\s*(حذف|پاک)(?:\s*کن)?$/iu', $cmd, $m)){
                $fid = $toInt($m[2] ?? $m[1]);
                if ($fid > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>sprintf(__('آیا از حذف فرم %d مطمئنید؟','arshline'), $fid),
                        'confirm_action'=>['type'=>'delete_form','params'=>['id'=>$fid]]
                    ], 200);
                }
            }

            // Colloquial: create form with name
            if (preg_match('/^(بساز|ایجاد|درست\s*کن)\s*فرم(?:\s*جدید)?\s*(?:با\s*عنوان|به\s*نام)?\s*(.+)$/iu', $cmd, $m)){
                $name = trim($m[2]);
                $name = trim($name, " \"'\x{200C}\x{200F}");
                if ($name !== ''){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>sprintf(__('فرم جدید با عنوان "%s" ساخته شود؟','arshline'), $name),
                        'confirm_action'=>['type'=>'create_form','params'=>['name'=>$name]]
                    ], 200);
                }
            }

            // Create a new blank form and open builder (no title specified)
            // Examples: "یک فرم جدید باز کن", "فرم جدید باز کن", "فرم جدید بساز", "یک فرم جدید بساز"
            if (preg_match('/^(?:یک\s*)?فرم\s*جدید\s*(?:باز\s*کن|بساز|ایجاد\s*کن)$/u', $cmd)
                || preg_match('/^(?:باز\s*کردن\s*)?فرم\s*جدید$/u', $cmd)){
                // Create a draft form with default title and return open_builder
                $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                // Title left empty to let create_form apply its default (or we could pass 'فرم جدید')
                $res = self::create_form($req);
                if ($res instanceof WP_REST_Response){
                    $data = $res->get_data();
                    $newId = isset($data['id']) ? (int)$data['id'] : 0;
                    if ($newId > 0){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$newId, 'undo_token'=>($data['undo_token']??null)], 200);
                    }
                }
                return new WP_REST_Response(['ok'=>false, 'error'=>'create_failed'], 500);
            }

            // Colloquial: export csv by id
            if (preg_match('/^(اکسپورت|خروجی|دانلود)\s*(?:csv\s*)?(?:از\s*)?فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)
              || preg_match('/^csv\s*فرم\s*([0-9۰-۹]+)/iu', $cmd, $m)){
                $fid = $toInt($m[2] ?? $m[1]);
                if ($fid > 0){
                    $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                    return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
                }
            }

            // Colloquial: list forms
            if (preg_match('/^(لیست|فهرست|نمایش|نشون\s*بده)\s*فرم(?:\s*ها)?$/iu', $cmd)){
                $forms = self::get_forms_list();
                return new WP_REST_Response(['ok'=>true, 'action'=>'list_forms', 'forms'=>$forms], 200);
            }
            // If not matched, try LLM-assisted parsing when configured
            $s = self::get_ai_settings();
            // Use LLM parsing only when enabled, configured, and parser is 'llm' or 'hybrid'
            $parserMode = $s['parser'] ?? 'hybrid';
            if ($s['enabled'] && in_array($parserMode, ['llm','hybrid'], true) && $s['base_url'] && $s['api_key']){
                $intent = self::llm_parse_command($cmd, $s);
                if (is_array($intent) && isset($intent['action'])){
                    $action = (string)$intent['action'];
                    if ($action === 'create_form' && !empty($intent['title'])){
                        $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                        $req->set_body_params(['title'=>(string)$intent['title']]);
                        $res = self::create_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'delete_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('DELETE', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $res = self::delete_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'public_link' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('GET', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $res = self::get_form($req);
                        $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                        $status = isset($data['status']) ? (string)$data['status'] : '';
                        $token = isset($data['token']) ? (string)$data['token'] : '';
                        $url = ($token && $status==='published') ? home_url('/?arshline='.rawurlencode($token)) : '';
                        return new WP_REST_Response(['ok'=> (bool)$url, 'url'=>$url, 'token'=>$token], 200);
                    }
                    if ($action === 'list_forms'){
                        $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                        $res = self::get_forms($req);
                        $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                        $list = array_map(function($r){ return [ 'id'=>(int)($r['id']??0), 'title'=>(string)($r['title']??'') ]; }, is_array($rows)?$rows:[]);
                        return new WP_REST_Response(['ok'=>true, 'forms'=>$list], 200);
                    }
                    if ($action === 'open_builder' && !empty($intent['id'])){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>(int)$intent['id']], 200);
                    }
                    if ($action === 'open_tab' && !empty($intent['tab'])){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>(string)$intent['tab']], 200);
                    }
                    if ($action === 'export_csv' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                        return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
                    }
                    if ($action === 'open_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $req->set_body_params(['status'=>'published']);
                        $res = self::update_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'close_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $req->set_body_params(['status'=>'disabled']);
                        $res = self::update_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'draft_form' && !empty($intent['id'])){
                        $fid = (int)$intent['id'];
                        $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                        $req->set_url_params(['form_id'=>$fid]);
                        $req->set_body_params(['status'=>'draft']);
                        $res = self::update_form($req);
                        return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
                    }
                    if ($action === 'update_form_title' && !empty($intent['id']) && isset($intent['title'])){
                        $fid = (int)$intent['id'];
                        $title = (string)$intent['title'];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'عنوان فرم '.$fid.' به «'.$title.'» تغییر داده شود؟',
                            'confirm_action'=>['action'=>'update_form_title','params'=>['id'=>$fid,'title'=>$title]]
                        ], 200);
                    }
                }
            }
            // Final fallback: suggest next steps instead of plain unknown
            $plain = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $cmd);
            $hasEdit = preg_match('/(ویرایش|ادیت|edit|باز\s*کردن|بازش\s*کن|سازنده)/u', $plain) === 1;
            $hasDel = preg_match('/(حذف|پاک(?:\s*کردن)?|delete)/iu', $plain) === 1;
            if ($hasEdit || $hasDel){
                // Try last token after the word "فرم" as a possible name
                if (preg_match('/فرم\s+(.+)$/u', $plain, $mm)){
                    $guess = trim((string)$mm[1]);
                    $matches = $find_by_title($guess, 5);
                    if (!empty($matches)){
                        $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                        $act = $hasDel ? 'delete_form' : 'open_builder';
                        $msg = $hasDel ? 'کدام فرم را حذف کنم؟' : 'کدام فرم را ویرایش کنم؟';
                        return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>$msg,'param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>$act]], 200);
                    }
                }
            }
            return new WP_REST_Response(['ok'=>false,'error'=>'unknown_command','message'=>'دستور واضح نیست. نمونه‌ها: «ویرایش فرم 12»، «حذف فرم 5»، «لیست فرم‌ها»'], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok'=>false,'error'=>'agent_error'], 200);
        }
    }

    /**
     * Execute a previously confirmed action sent by the UI terminal.
     */
    protected static function execute_confirmed_action(array $payload)
    {
        $action = (string)($payload['action'] ?? '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        try {
            if ($action === 'create_form' && !empty($params['title'])){
                $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                $req->set_body_params(['title'=>(string)$params['title']]);
                $res = self::create_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'open_builder' && !empty($params['id'])){
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>(int)$params['id']], 200);
            }
            if ($action === 'open_tab' && !empty($params['tab'])){
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>(string)$params['tab']], 200);
            }
            if ($action === 'open_results' && !empty($params['id'])){
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>(int)$params['id']], 200);
            }
            if ($action === 'export_csv' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $url = rest_url('arshline/v1/forms/'.$fid.'/submissions?format=csv');
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            if ($action === 'delete_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('DELETE', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $res = self::delete_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'set_setting' && !empty($params['key'])){
                $key = (string)$params['key']; $value = $params['value'] ?? null;
                $allowed = ['ai_enabled','ai_model','min_submit_seconds','rate_limit_per_min','block_svg','ai_parser'];
                if (!in_array($key, $allowed, true)){
                    return new WP_REST_Response(['ok'=>false, 'error'=>'invalid_setting'], 200);
                }
                if ($key === 'ai_model' || $key === 'ai_enabled'){
                    $cur = self::get_ai_settings();
                    $cfg = [
                        'base_url' => $cur['base_url'],
                        'api_key' => $cur['api_key'],
                        'enabled' => ($key === 'ai_enabled') ? (bool)$value : $cur['enabled'],
                        'model' => ($key === 'ai_model') ? (string)$value : $cur['model'],
                        'parser' => $cur['parser'],
                    ];
                    $before = ['config'=>$cur];
                    $r = new WP_REST_Request('PUT', '/arshline/v1/ai/config');
                    $r->set_body_params(['config'=>$cfg]);
                    $resp = self::update_ai_config($r);
                    $undo = Audit::log('set_setting', 'settings', null, $before, ['config'=>$cfg]);
                    if ($resp instanceof WP_REST_Response){ $data = $resp->get_data(); $data['undo_token'] = $undo; return new WP_REST_Response($data, $resp->get_status()); }
                    return $resp;
                } elseif ($key === 'ai_parser'){
                    $cur = self::get_ai_settings();
                    $cfg = [
                        'base_url' => $cur['base_url'],
                        'api_key' => $cur['api_key'],
                        'enabled' => $cur['enabled'],
                        'model' => $cur['model'],
                        'parser' => in_array((string)$value, ['internal','hybrid','llm'], true) ? (string)$value : 'hybrid',
                    ];
                    $before = ['config'=>$cur];
                    $r = new WP_REST_Request('PUT', '/arshline/v1/ai/config');
                    $r->set_body_params(['config'=>$cfg]);
                    $resp = self::update_ai_config($r);
                    $undo = Audit::log('set_setting', 'settings', null, $before, ['config'=>$cfg]);
                    if ($resp instanceof WP_REST_Response){ $data = $resp->get_data(); $data['undo_token'] = $undo; return new WP_REST_Response($data, $resp->get_status()); }
                    return $resp;
                } else {
                    $cur = get_option('arshline_settings', []);
                    $arr = is_array($cur) ? $cur : [];
                    $before = ['settings'=>$arr];
                    if ($key === 'min_submit_seconds') $arr['min_submit_seconds'] = max(0, (int)$value);
                    if ($key === 'rate_limit_per_min') $arr['rate_limit_per_min'] = max(0, (int)$value);
                    if ($key === 'block_svg') $arr['block_svg'] = (bool)$value;
                    update_option('arshline_settings', $arr, false);
                    $undo = Audit::log('set_setting', 'settings', null, $before, ['settings'=>$arr]);
                    return new WP_REST_Response(['ok'=>true, 'settings'=> self::get_global_settings(), 'undo_token'=>$undo], 200);
                }
            }
            if ($action === 'open_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'published']);
                $res = self::update_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'close_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'disabled']);
                $res = self::update_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'draft_form' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid);
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['status'=>'draft']);
                $res = self::update_form($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            if ($action === 'update_form_title' && !empty($params['id']) && isset($params['title'])){
                $fid = (int)$params['id'];
                $title = (string)$params['title'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid.'/meta');
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['meta'=>['title'=>$title]]);
                $res = self::update_meta($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok'=>false, 'error'=>'confirm_execute_failed'], 200);
        }
        return new WP_REST_Response(['ok'=>false, 'error'=>'unknown_confirm_action'], 200);
    }

    /**
     * Minimal forms list for agent suggestions: [{id,title}]
     */
    protected static function get_forms_list(): array
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $rows = $wpdb->get_results("SELECT id, status, meta FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r){
            $meta = json_decode($r['meta'] ?: '{}', true);
            $out[] = [ 'id'=>(int)$r['id'], 'title'=>(string)($meta['title'] ?? 'بدون عنوان') ];
        }
        return $out;
    }

    /**
     * Use an OpenAI-compatible chat/completions endpoint to parse a natural-language command
     * into a structured intent. Expected JSON output schema:
     * { action: "create_form"|"delete_form"|"public_link", title?: string, id?: number }
     */
    protected static function llm_parse_command(string $cmd, array $s)
    {
        try {
            $base = rtrim((string)$s['base_url'], '/');
            $model = (string)($s['model'] ?? 'gpt-4o-mini');
            $url = $base . '/v1/chat/completions';
          $sys = 'You are a deterministic command parser for the Arshline dashboard. '
              . 'Your ONLY job is to convert Persian admin commands into a single strict JSON object. '
              . 'Do NOT chat, do NOT add explanations, do NOT ask follow-up questions. Output JSON ONLY. '
              . 'Schema: '
              . '{"action":"create_form|delete_form|public_link|list_forms|open_builder|open_editor|open_tab|open_results|export_csv|help|set_setting|ui|open_form|close_form|draft_form|update_form_title","title?":string,"id?":number,"index?":number,"tab?":"dashboard|forms|reports|users|settings","section?":"security|ai|users","key?":"ai_enabled|min_submit_seconds|rate_limit_per_min|block_svg|ai_model","value?":(string|number|boolean),"target?":"toggle_theme|open_ai_terminal|undo|go_back","params?":object}. '
              . 'Examples: '
              . '"ایجاد فرم با عنوان فرم تست" => {"action":"create_form","title":"فرم تست"}. '
              . '"حذف فرم 12" => {"action":"delete_form","id":12}. '
              . '"لینک عمومی فرم 7" => {"action":"public_link","id":7}. '
              . '"لیست فرم ها" => {"action":"list_forms"}. '
              . '"باز کردن فرم 9" => {"action":"open_builder","id":9}. '
              . '"باز کردن تنظیمات" => {"action":"open_tab","tab":"settings"}. '
              . '"خروجی فرم 5" => {"action":"export_csv","id":5}. '
              . '"کمک" => {"action":"help"}. '
              . '"مدل را روی gpt-4o-mini بگذار" => {"action":"set_setting","key":"ai_model","value":"gpt-4o-mini"}. '
              . '"حالت تاریک را فعال کن" => {"action":"ui","target":"toggle_theme","params":{"mode":"dark"}}. '
              . '"فعال کن فرم 3" => {"action":"open_form","id":3}. '
              . '"غیرفعال کن فرم 8" => {"action":"close_form","id":8}. '
              . '"پیش‌نویس کن فرم 4" => {"action":"draft_form","id":4}. '
              . '"عنوان فرم 2 را به فرم مشتریان تغییر بده" => {"action":"update_form_title","id":2,"title":"فرم مشتریان"}. '
              . 'If unclear, reply {"action":"unknown"}.';
            $body = [
                'model' => $model,
                'messages' => [
                    [ 'role' => 'system', 'content' => $sys ],
                    [ 'role' => 'user', 'content' => $cmd ],
                ],
                'temperature' => 0,
                'response_format' => [ 'type' => 'json_object' ],
            ];
            $resp = wp_remote_post($url, [
                'timeout' => 10,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . (string)$s['api_key'],
                ],
                'body' => wp_json_encode($body),
            ]);
            if (is_wp_error($resp)) return null;
            $code = (int)wp_remote_retrieve_response_code($resp);
            if ($code < 200 || $code >= 300) return null;
            $json = json_decode(wp_remote_retrieve_body($resp), true);
            $content = $json['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || $content === '') return null;
            $parsed = json_decode($content, true);
            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) { return null; }
    }

    public static function get_form_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
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
        // Normalize and sanitize incoming meta before merging
        $meta = self::sanitize_meta_input($meta);
        $beforeAll = is_array($form->meta)? $form->meta : [];
        // Capture only the keys being changed for audit diff
        $beforeSubset = [];
        foreach ($meta as $k => $_){ $beforeSubset[$k] = $beforeAll[$k] ?? null; }
        $form->meta = array_merge($beforeAll, $meta);
        FormRepository::save($form);
        $afterSubset = [];
        foreach ($meta as $k => $_){ $afterSubset[$k] = $form->meta[$k] ?? null; }
        // Log audit with undo token
        $undo = Audit::log('update_form_meta', 'form', $id, ['meta'=>$beforeSubset], ['meta'=>$afterSubset]);
        return new WP_REST_Response(['ok'=>true, 'meta'=>$form->meta, 'undo_token'=>$undo], 200);
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
            // Only include answerable field types
            $allowedTypes = ['short_text','long_text','multiple_choice','dropdown','rating'];
            $fields = array_values(array_filter($fields, function($f) use ($allowedTypes){
                $p = is_array($f['props']) ? $f['props'] : [];
                $t = isset($p['type']) ? (string)$p['type'] : '';
                return in_array($t, $allowedTypes, true);
            }));
            $fieldOrder = [];
            $fieldLabels = [];
            $choices = [];
            foreach ($fields as $f){
                $fid = (int)$f['id']; $p = is_array($f['props'])? $f['props'] : [];
                $fieldOrder[] = $fid; $fieldLabels[$fid] = (string)($p['question'] ?? $p['label'] ?? ('فیلد #'.$fid));
                if (!empty($p['options']) && is_array($p['options'])){
                    foreach ($p['options'] as $opt){ $val = (string)($opt['value'] ?? $opt['label'] ?? ''); $lab = (string)($opt['label'] ?? $val); if ($val !== ''){ $choices[$fid][$val] = $lab; } }
                }
            }
            $ids = array_map(function($r){ return (int)$r['id']; }, $all);
            $valsMap = SubmissionRepository::listValuesBySubmissionIds($ids);
            $out = [];
            // Drop status per request; include only id and created_at (requested)
            $header = ['id','created_at'];
            foreach ($fieldOrder as $fid){ $header[] = $fieldLabels[$fid]; }
            $out[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $header));
            foreach ($all as $r){
                $row = [ $r['id'], (string)($r['created_at'] ?? '') ];
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
            // Stream directly to avoid JSON encoding
            if (!headers_sent()) {
                if ($format === 'excel') {
                    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
                    header('Content-Disposition: attachment; filename="submissions-'.$form_id.'.xls"');
                } else {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="submissions-'.$form_id.'.csv"');
                }
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            }
            // Clear any buffered output to prevent stray bytes
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo $csv;
            exit;
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
        if (strpos($include, 'fields') !== false){
            $allFields = FieldRepository::listByForm($form_id);
            $allowedTypes = ['short_text','long_text','multiple_choice','dropdown','rating'];
            $payload['fields'] = array_values(array_filter($allFields, function($f) use ($allowedTypes){
                $p = is_array($f['props']) ? $f['props'] : [];
                $t = isset($p['type']) ? (string)$p['type'] : '';
                return in_array($t, $allowedTypes, true);
            }));
        }
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
        // Load form to access meta settings
        $form = FormRepository::find($form_id);
        if (!$form) return new WP_REST_Response(['error' => 'invalid_form_id'], 400);
        // Gate submissions to published forms only
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error' => 'form_disabled'], 403);
        }
    $global = self::get_global_settings();
    $meta = array_merge($global, is_array($form->meta) ? $form->meta : []);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $now = time();
        // 1) Honeypot: reject if value present
        if (!empty($meta['anti_spam_honeypot'])){
            $hp = (string)($request->get_param('hp') ?? '');
            if ($hp !== ''){ return new WP_REST_Response(['error'=>'rejected', 'reason'=>'honeypot'], 429); }
        }
        // 2) Minimum seconds from render to submit
        $minSec = isset($meta['min_submit_seconds']) ? max(0, (int)$meta['min_submit_seconds']) : 0;
        if ($minSec > 0){
            $ts = (int)($request->get_param('ts') ?? 0);
            if ($ts > 0 && ($now - $ts) < $minSec){ return new WP_REST_Response(['error'=>'rejected', 'reason'=>'too_fast'], 429); }
        }
        // 3) Optional reCAPTCHA validation
        if (!empty($meta['captcha_enabled'])){
            $site = (string)($meta['captcha_site_key'] ?? '');
            $secret = (string)($meta['captcha_secret_key'] ?? '');
            $version = (string)($meta['captcha_version'] ?? 'v2');
            // Tokens come in params: g-recaptcha-response (v2) or ar_recaptcha_token (v3)
            $token = (string)($request->get_param('g-recaptcha-response') ?? $request->get_param('ar_recaptcha_token') ?? '');
            if ($secret && $token){
                // Server-side verify (best-effort; avoid external call if blocked)
                $ok = false; $scoreOk = true;
                $resp = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
                    'timeout' => 5,
                    'body' => [ 'secret' => $secret, 'response' => $token, 'remoteip' => $ip ],
                ]);
                if (!is_wp_error($resp)){
                    $body = wp_remote_retrieve_body($resp);
                    $j = json_decode($body, true);
                    if (is_array($j) && !empty($j['success'])){
                        $ok = true;
                        if ($version === 'v3' && isset($j['score'])){ $scoreOk = ((float)$j['score'] >= 0.5); }
                    }
                }
                if (!$ok || !$scoreOk){ return new WP_REST_Response(['error'=>'captcha_failed'], 429); }
            } else {
                return new WP_REST_Response(['error'=>'captcha_missing'], 400);
            }
        }
        // 4) Rate limiting per IP + form
        $perMin = isset($meta['rate_limit_per_min']) ? max(0, (int)$meta['rate_limit_per_min']) : 0;
        $windowMin = isset($meta['rate_limit_window_min']) ? max(1, (int)$meta['rate_limit_window_min']) : 1;
        if ($perMin > 0){
            $key = 'arsh_rl_'.md5($form_id.'|'.$ip);
            $entry = get_transient($key);
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now + ($windowMin*60)];
            if ($now >= (int)$data['reset']){ $data = ['count'=>0,'reset'=>$now + ($windowMin*60)]; }
            if ((int)$data['count'] >= $perMin){ return new WP_REST_Response(['error'=>'rate_limited','retry_after'=>max(0, (int)$data['reset'] - $now)], 429); }
            $data['count'] = (int)$data['count'] + 1;
            set_transient($key, $data, $windowMin * 60);
        }
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
            'ip' => $ip,
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
        // Block submissions when not published
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error' => 'form_disabled'], 403);
        }
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
        $form = FormRepository::find($form_id);
        if (!$form) return new WP_REST_Response('<div class="ar-alert ar-alert--err">فرم یافت نشد.</div>', 200);
    $global = self::get_global_settings();
    $meta = array_merge($global, is_array($form->meta) ? $form->meta : []);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $now = time();
        // Honeypot (htmx submit may include hp/ts fields)
        if (!empty($meta['anti_spam_honeypot'])){
            $hp = (string)($request->get_param('hp') ?? '');
            if ($hp !== ''){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">درخواست نامعتبر است.</div>', 200); }
        }
        // Min seconds
        $minSec = isset($meta['min_submit_seconds']) ? max(0, (int)$meta['min_submit_seconds']) : 0;
        if ($minSec > 0){
            $ts = (int)($request->get_param('ts') ?? 0);
            if ($ts > 0 && ($now - $ts) < $minSec){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">ارسال خیلی سریع بود. لطفاً کمی صبر کنید.</div>', 200); }
        }
        // Optional captcha
        if (!empty($meta['captcha_enabled'])){
            $secret = (string)($meta['captcha_secret_key'] ?? '');
            $version = (string)($meta['captcha_version'] ?? 'v2');
            $token = (string)($request->get_param('g-recaptcha-response') ?? $request->get_param('ar_recaptcha_token') ?? '');
            if ($secret && $token){
                $ok = false; $scoreOk = true;
                $resp = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [ 'timeout'=>5, 'body'=>[ 'secret'=>$secret, 'response'=>$token, 'remoteip'=>$ip ] ]);
                if (!is_wp_error($resp)){
                    $body = wp_remote_retrieve_body($resp); $j = json_decode($body, true);
                    if (is_array($j) && !empty($j['success'])){ $ok = true; if ($version==='v3' && isset($j['score'])) $scoreOk = ((float)$j['score'] >= 0.5); }
                }
                if (!$ok || !$scoreOk){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">احراز انسان بودن شکست خورد.</div>', 200); }
            } else {
                return new WP_REST_Response('<div class="ar-alert ar-alert--err">احراز هویت ربات فعال است اما توکن دریافت نشد.</div>', 200);
            }
        }
        // Rate limit
        $perMin = isset($meta['rate_limit_per_min']) ? max(0, (int)$meta['rate_limit_per_min']) : 0;
        $windowMin = isset($meta['rate_limit_window_min']) ? max(1, (int)$meta['rate_limit_window_min']) : 1;
        if ($perMin > 0){
            $key = 'arsh_rl_'.md5($form_id.'|'.$ip); $entry = get_transient($key);
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now + ($windowMin*60)];
            if ($now >= (int)$data['reset']){ $data = ['count'=>0,'reset'=>$now + ($windowMin*60)]; }
            if ((int)$data['count'] >= $perMin){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">محدودیت نرخ ارسال فعال شد. لطفاً بعداً دوباره تلاش کنید.</div>', 200); }
            $data['count'] = (int)$data['count'] + 1; set_transient($key, $data, $windowMin*60);
        }

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
            'ip' => $ip,
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
        if ($form->status !== 'published'){
            return new WP_REST_Response('<div class="ar-alert ar-alert--err">این فرم در حال حاضر فعال نیست.</div>', 200);
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
            $rr = isset($_GET['rest_route']) ? (string)$_GET['rest_route'] : '';
            // Normalize route
            $route = is_string($route) ? $route : '';
            if ($route && $route[0] !== '/') { $route = '/'.$route; }
            $route = rtrim($route, '/');
            $rr = is_string($rr) ? $rr : '';
            if ($rr && $rr[0] !== '/') { $rr = '/'.$rr; }
            $rr = rtrim($rr, '/');

            // Detect HTMX header and/or our submit routes
            $hx = '';
            if (is_object($request) && method_exists($request, 'get_header')) {
                $hx = (string)($request->get_header('hx-request') ?: $request->get_header('HX-Request'));
            }
            $isSubmit = ((($route && strpos($route, '/arshline/v1/public/forms/') === 0) || ($rr && strpos($rr, '/arshline/v1/public/forms/') === 0))
                        && (strpos($route, '/submit') !== false || strpos($rr, '/submit') !== false))
                        || strtolower($hx) === 'true';

            // Also allow raw CSV/Excel passthrough for exports when format param is present
            $fmt = '';
            if (isset($_GET['format'])) { $fmt = (string)$_GET['format']; }
            elseif (is_object($request) && method_exists($request, 'get_param')) { $fmt = (string)($request->get_param('format') ?? ''); }
            $fmt = strtolower($fmt);
            $isExport = in_array($fmt, ['csv','excel'], true);
            if (!$isSubmit && !$isExport) { return $served; }

            // Extract string content from response
            if ($result instanceof \WP_REST_Response) {
                $data = $result->get_data();
                $status = (int)$result->get_status();
            } else {
                $data = $result;
                $status = 200;
            }
            if (!is_string($data)) { return $served; }

            // Serve as text/html or CSV/Excel directly
            if (method_exists($server, 'send_header')) {
                $ctype = $isExport ? ($fmt==='excel' ? 'application/vnd.ms-excel; charset=utf-8' : 'text/csv; charset=utf-8') : 'text/html; charset=utf-8';
                $server->send_header('Content-Type', $ctype);
                $server->send_header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                if ($isExport) {
                    // Try extracting form_id from either route for filename
                    $fid = null;
                    if ($route && preg_match('#/forms/(\d+)/submissions$#', $route, $m)) { $fid = (int)$m[1]; }
                    elseif ($rr && preg_match('#/forms/(\d+)/submissions$#', $rr, $m)) { $fid = (int)$m[1]; }
                    $ext = ($fmt==='excel') ? 'xls' : 'csv';
                    $name = 'submissions' . ($fid?('-'.$fid):'') . '.' . $ext;
                    $server->send_header('Content-Disposition', 'attachment; filename="'.$name.'"');
                }
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
        // Take snapshot before destructive delete
        $before = FormRepository::snapshot($id);
        $ok = FormRepository::delete($id);
        if ($ok) {
            $undo = Audit::log('delete_form', 'form', $id, $before ?: [], []);
            return new WP_REST_Response(['ok' => true, 'undo_token' => $undo], 200);
        }
        return new WP_REST_Response(['ok' => false], 404);
    }

    /**
     * GET /ai/audit — list recent audit entries (admin-only)
     */
    public static function list_audit(WP_REST_Request $request)
    {
        $limit = (int)($request->get_param('limit') ?? 50);
        $items = Audit::list(max(1, min(200, $limit)));
        return new WP_REST_Response(['ok'=>true, 'items'=>$items], 200);
    }

    /**
     * POST /ai/undo — undo a single action by token (idempotent)
     */
    public static function undo_by_token(WP_REST_Request $request)
    {
        $token = (string)($request->get_param('token') ?? '');
        if ($token === '') return new WP_REST_Response(['ok'=>false,'error'=>'missing_token'], 400);
        $row = Audit::findByToken($token);
        if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'not_found'], 404);
        if (!empty($row['undone'])) return new WP_REST_Response(['ok'=>false,'error'=>'already_undone'], 409);
        $action = (string)$row['action'];
        $scope = (string)$row['scope'];
        $before = is_array($row['before'] ?? null) ? $row['before'] : [];
        $after = is_array($row['after'] ?? null) ? $row['after'] : [];
        try {
            if ($scope === 'form'){
                if ($action === 'delete_form'){
                    // Restore full form snapshot
                    $restored = FormRepository::restore($before);
                    if ($restored > 0){ Audit::markUndone($token); return new WP_REST_Response(['ok'=>true, 'restored_id'=>$restored], 200); }
                    return new WP_REST_Response(['ok'=>false,'error'=>'restore_failed'], 500);
                }
                if ($action === 'create_form'){
                    // Remove the created form
                    $fid = 0;
                    if (isset($after['form']) && is_array($after['form'])){ $fid = (int)($after['form']['id'] ?? 0); }
                    if ($fid > 0){
                        $ok = FormRepository::delete($fid);
                        if ($ok){ Audit::markUndone($token); return new WP_REST_Response(['ok'=>true, 'deleted_id'=>$fid], 200); }
                        return new WP_REST_Response(['ok'=>false,'error'=>'delete_failed'], 500);
                    }
                }
                if ($action === 'update_form_status'){
                    $fid = (int)($row['target_id'] ?? 0);
                    if ($fid > 0){
                        $form = FormRepository::find($fid);
                        if ($form){
                            $prev = isset($before['status']) ? (string)$before['status'] : 'draft';
                            $form->status = in_array($prev, ['draft','published','disabled'], true) ? $prev : 'draft';
                            FormRepository::save($form);
                            Audit::markUndone($token);
                            return new WP_REST_Response(['ok'=>true, 'restored_status'=>$form->status], 200);
                        }
                    }
                    return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'], 400);
                }
                if ($action === 'update_form_meta'){
                    $fid = (int)($row['target_id'] ?? 0);
                    if ($fid > 0){
                        $form = FormRepository::find($fid);
                        if ($form){
                            $metaPrev = is_array($before['meta'] ?? null) ? $before['meta'] : [];
                            $metaAll = is_array($form->meta) ? $form->meta : [];
                            foreach ($metaPrev as $k => $v){
                                if ($v === null) { unset($metaAll[$k]); }
                                else { $metaAll[$k] = $v; }
                            }
                            $form->meta = $metaAll;
                            FormRepository::save($form);
                            Audit::markUndone($token);
                            return new WP_REST_Response(['ok'=>true, 'restored_meta_keys'=>array_keys($metaPrev)], 200);
                        }
                    }
                    return new WP_REST_Response(['ok'=>false,'error'=>'invalid_target'], 400);
                }
            }
            if ($scope === 'settings' && $action === 'set_setting'){
                // Swap entire settings/config back using before snapshot
                $before = is_array($before) ? $before : [];
                if (isset($before['config'])){
                    $cfg = $before['config'];
                    $r = new WP_REST_Request('PUT', '/arshline/v1/ai/config');
                    $r->set_body_params(['config'=>$cfg]);
                    self::update_ai_config($r);
                    Audit::markUndone($token);
                    return new WP_REST_Response(['ok'=>true, 'restored'=>'ai_config'], 200);
                }
                if (isset($before['settings'])){
                    $arr = $before['settings'];
                    if (is_array($arr)) update_option('arshline_settings', $arr, false);
                    Audit::markUndone($token);
                    return new WP_REST_Response(['ok'=>true, 'restored'=>'settings'], 200);
                }
                return new WP_REST_Response(['ok'=>false,'error'=>'invalid_before_state'], 400);
            }
            return new WP_REST_Response(['ok'=>false,'error'=>'unsupported_undo'], 400);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['ok'=>false,'error'=>'undo_error'], 500);
        }
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
        // Do not issue tokens for non-published forms to enforce gating
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
        if (empty($form->public_token)) {
            FormRepository::save($form);
            $form = FormRepository::find($id) ?: $form;
        }
        return new WP_REST_Response(['token' => (string)$form->public_token], 200);
    }

    /**
     * PUT /forms/{id} — update form attributes (currently only status)
     */
    public static function update_form(WP_REST_Request $request)
    {
        $id = (int)$request['form_id'];
        if ($id <= 0) return new WP_REST_Response(['error'=>'invalid_id'], 400);
        $form = FormRepository::find($id);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        $status = (string)($request->get_param('status') ?? '');
        $undo = null;
        if ($status !== ''){
            $allowed = ['draft','published','disabled'];
            if (!in_array($status, $allowed, true)){
                return new WP_REST_Response(['error'=>'invalid_status'], 400);
            }
            $beforeStatus = $form->status;
            $form->status = $status;
            // Only log when status actually changes
            if ($beforeStatus !== $status){
                $undo = Audit::log('update_form_status', 'form', $id, ['status'=>$beforeStatus], ['status'=>$status]);
            }
        }
        FormRepository::save($form);
        $resp = ['ok'=>true, 'id'=>$form->id, 'status'=>$form->status];
        if ($undo){ $resp['undo_token'] = $undo; }
        return new WP_REST_Response($resp, 200);
    }

    public static function upload_image(WP_REST_Request $request)
    {
        if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
        if (!function_exists('wp_insert_attachment')) require_once(ABSPATH . 'wp-admin/includes/image.php');
        $files = $request->get_file_params();
        if (!isset($files['file'])){
            return new WP_REST_Response(['error' => 'no_file'], 400);
        }
        // Simple per-IP rate limit for uploads (10 per دقیقه)
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $now = time();
            $key = 'arsh_up_rl_'.md5($ip ?: '');
            $entry = get_transient($key);
            $windowSec = 60; $limit = 10;
            $data = is_array($entry) ? $entry : ['count'=>0,'reset'=>$now + $windowSec];
            if ($now >= (int)$data['reset']){ $data = ['count'=>0,'reset'=>$now + $windowSec]; }
            if ((int)$data['count'] >= $limit){ return new WP_REST_Response(['error'=>'rate_limited','retry_after'=>max(0,(int)$data['reset']-$now)], 429); }
            $data['count'] = (int)$data['count'] + 1; set_transient($key, $data, $windowSec);
        } catch (\Throwable $e) { /* ignore RL errors */ }
        $file = $files['file'];
    // Basic size limit (server-side double-check) from global settings
    $gs = self::get_global_settings();
    $maxKB = isset($gs['upload_max_kb']) ? max(50, min(4096, (int)$gs['upload_max_kb'])) : 300;
    $maxBytes = $maxKB * 1024;
        $size = isset($file['size']) ? (int)$file['size'] : 0;
        if ($size <= 0 || $size > $maxBytes){
            return new WP_REST_Response(['error' => 'invalid_size', 'message' => 'File too large or invalid. Max 300KB'], 413);
        }
    // Allow only common raster image types by extension and real MIME (SVG excluded for security)
    $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp'];
        $clientType = (string)($file['type'] ?? '');
        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowedExt = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowedExt, true)){
            return new WP_REST_Response(['error' => 'invalid_extension'], 415);
        }
        // Real MIME sniffing using finfo when available
        $realMime = '';
        if (function_exists('finfo_open') && is_readable($file['tmp_name'] ?? '')){
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f){ $realMime = (string)finfo_file($f, $file['tmp_name']); finfo_close($f); }
        }
        $mimeToCheck = $realMime ?: $clientType;
        if ($mimeToCheck && !in_array($mimeToCheck, $allowedMimes, true)){
            return new WP_REST_Response(['error' => 'invalid_type'], 415);
        }
        // Block SVG uploads entirely when enabled (risk of embedded scripts)
        $blockSvg = !isset($gs['block_svg']) || (bool)$gs['block_svg'];
        if ($blockSvg && $ext === 'svg'){
            return new WP_REST_Response(['error' => 'invalid_type'], 415);
        }
        // Enforce max dimensions (<=2048x2048 and <=4MP)
        try {
            $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
            if ($tmp && is_readable($tmp)){
                $info = @getimagesize($tmp);
                if (is_array($info)){
                    $w = (int)($info[0] ?? 0); $h = (int)($info[1] ?? 0);
                    $maxW = 2048; $maxH = 2048; $maxPixels = 4000000;
                    if ($w <= 0 || $h <= 0 || $w > $maxW || $h > $maxH || ($w*$h) > $maxPixels){
                        return new WP_REST_Response(['error'=>'invalid_dimensions','message'=>'Image dimensions exceed limits'], 415);
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
        add_filter('upload_dir', function($dirs){
            $dirs['subdir'] = '/arshline';
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']  = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        });
        // Enforce type/size checks in WordPress as well
    $overrides = [ 'test_form' => false, 'mimes' => [ 'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp' ], 'unique_filename_callback' => null ];
        $movefile = wp_handle_upload($file, $overrides);
        remove_all_filters('upload_dir');
        if (!$movefile || isset($movefile['error'])){
            return new WP_REST_Response(['error' => 'upload_failed', 'message' => $movefile['error'] ?? ''], 500);
        }
        // Return URL only
        return new WP_REST_Response([ 'url' => $movefile['url'] ], 201);
    }
}

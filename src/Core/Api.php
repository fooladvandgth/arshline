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
use Arshline\Modules\UserGroups\GroupRepository;
use Arshline\Modules\UserGroups\MemberRepository;
use Arshline\Modules\UserGroups\FormGroupAccessRepository;
use Arshline\Modules\UserGroups\FieldRepository as UGFieldRepo;
use Arshline\Modules\UserGroups\Field as UGField;
use Arshline\Core\AccessControl;

class Api
{
    /**
     * Read AI analysis configuration from options with sane defaults and filters.
     * This aids Hybrid/Efficient/AI-Heavy modes and caps while UI is pending.
     */
    protected static function get_ai_analysis_config(): array
    {
        $gs = get_option('arshline_settings', []);
        $mode = is_scalar($gs['ai_mode'] ?? null) ? (string)$gs['ai_mode'] : 'hybrid'; // efficient|hybrid|ai-heavy
        $maxRows = isset($gs['ai_max_rows']) && is_numeric($gs['ai_max_rows']) ? max(50, min(1000, (int)$gs['ai_max_rows'])) : 400;
        $allowPII = !empty($gs['ai_allow_pii']);
        $tokTypical = isset($gs['ai_tok_typical']) && is_numeric($gs['ai_tok_typical']) ? max(1000, min(16000, (int)$gs['ai_tok_typical'])) : 8000;
        $tokMax = isset($gs['ai_tok_max']) && is_numeric($gs['ai_tok_max']) ? max($tokTypical, min(32000, (int)$gs['ai_tok_max'])) : 32000;
        $res = [
            'mode' => $mode,
            'max_rows' => $maxRows,
            'allow_pii' => $allowPII,
            'token_typical' => $tokTypical,
            'token_max' => $tokMax,
        ];
        // Allow integrators to tune programmatically
        if (function_exists('apply_filters')){
            $res = apply_filters('arshline_ai_analysis_config', $res);
        }
        return $res;
    }
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
            'ai_model' => 'gpt-4o',
        ];
        $raw = get_option('arshline_settings', []);
    $arr = is_array($raw) ? $raw : [];
        $san = self::sanitize_settings_input($arr);
        return array_merge($defaults, $san);
    }
    /** Get SMS settings (separate option to keep credentials isolated). */
    protected static function get_sms_settings_store(): array
    {
        $raw = get_option('arshline_sms_settings', []);
        $arr = is_array($raw) ? $raw : [];
        $out = [
            'enabled' => !empty($arr['enabled']),
            'provider' => ($arr['provider'] ?? 'sms_ir') === 'sms_ir' ? 'sms_ir' : 'sms_ir',
            'api_key' => is_string($arr['api_key'] ?? '') ? (string)$arr['api_key'] : '',
            'line_number' => is_string($arr['line_number'] ?? '') ? (string)$arr['line_number'] : '',
        ];
        // sanitize
        $out['api_key'] = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', $out['api_key']), 0, 200);
        $out['line_number'] = substr(preg_replace('/[^0-9]/', '', $out['line_number']), 0, 20);
        return $out;
    }
    protected static function put_sms_settings_store(array $in): array
    {
        $cur = self::get_sms_settings_store();
        $next = [
            'enabled' => array_key_exists('enabled', $in) ? (bool)$in['enabled'] : $cur['enabled'],
            'provider' => 'sms_ir',
            'api_key' => array_key_exists('api_key', $in) ? (string)$in['api_key'] : $cur['api_key'],
            'line_number' => array_key_exists('line_number', $in) ? (string)$in['line_number'] : $cur['line_number'],
        ];
        // Sanitize
        $next['api_key'] = substr(preg_replace('/[^A-Za-z0-9_\-\.]/', '', $next['api_key']), 0, 200);
        $next['line_number'] = substr(preg_replace('/[^0-9]/', '', $next['line_number']), 0, 20);
        update_option('arshline_sms_settings', $next, false);
        return $next;
    }

    public static function get_sms_settings(\WP_REST_Request $r)
    {
        return new \WP_REST_Response(self::get_sms_settings_store(), 200);
    }
    public static function update_sms_settings(\WP_REST_Request $r)
    {
        $p = $r->get_json_params(); if (!is_array($p)) $p = $r->get_params();
        $saved = self::put_sms_settings_store($p);
        return new \WP_REST_Response($saved, 200);
    }

    /** Compose message from template and member data. Supports #name/#نام, #phone, #link/#لینک placeholders. */
    protected static function compose_sms_template(string $tpl, array $member, string $link = ''): string
    {
        // Normalize Persian synonyms to canonical placeholders
        $tpl = str_replace(['#نام', '#لینک'], ['#name', '#link'], $tpl);
        $repl = [
            'name' => (string)($member['name'] ?? ''),
            'phone' => (string)($member['phone'] ?? ''),
            'link' => (string)$link,
        ];
        // include custom fields from member[data]
        $data = is_array($member['data'] ?? null) ? $member['data'] : [];
        foreach ($data as $k=>$v){
            if (is_scalar($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)){
                $repl[(string)$k] = (string)$v;
            }
        }
        $msg = preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k, $repl) ? (string)$repl[$k] : $m[0]; }, $tpl);
        return self::ensure_sms_suffix($msg);
    }

    /** Ensure required SMS suffix (opt-out phrase) is appended once. */
    protected static function ensure_sms_suffix(string $msg): string
    {
        $suffix = ' لغو11';
        $m = rtrim($msg);
        // Avoid double-adding: if message already ends with suffix (allowing whitespace), skip
        if (preg_match('/'.preg_quote($suffix, '/').'\s*$/u', $m)) return $m;
        return $m.$suffix;
    }

    /** Build personal link for form + member if requested. */
    protected static function build_member_form_link(int $formId, array $member): string
    {
        $form = FormRepository::find($formId);
        if (!$form || $form->status !== 'published' || empty($form->public_token)) return '';
        $formToken = (string)$form->public_token;
        // Ensure member token
        $tok = MemberRepository::ensureToken((int)($member['id'] ?? 0));
        if (!$tok) return '';
        $base = add_query_arg('arshline', rawurlencode($formToken), home_url('/'));
        $url = add_query_arg('member_token', rawurlencode((string)$tok), $base);
        return esc_url_raw($url);
    }

    /** Send SMS via SMS.IR for a single recipient (simple bulk endpoint with single mobile). */
    protected static function smsir_send_single(string $apiKey, string $lineNumber, string $mobile, string $message, ?string $sendDateTime): bool
    {
        $apiKey = trim($apiKey); $lineNumber = trim($lineNumber); $mobile = preg_replace('/[^0-9]/', '', (string)$mobile);
        if ($apiKey === '' || $lineNumber === '' || $mobile === '' || $message === '') return false;
        $url = 'https://api.sms.ir/v1/send/bulk';
        $body = [
            'lineNumber' => $lineNumber,
            'messageText' => $message,
            'mobiles' => [$mobile],
        ];
        if ($sendDateTime){ $body['sendDateTime'] = $sendDateTime; }
        $args = [
            'headers' => [ 'x-api-key' => $apiKey, 'Content-Type' => 'application/json' ],
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ];
        $resp = wp_remote_post($url, $args);
        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) return true;
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (is_array($json) && isset($json['status']) && (int)$json['status'] === 1) return true;
        return false;
    }

    /** Basic SMS.IR test: if phone provided, attempt to send; else, just validate config presence. */
    public static function test_sms_connect(\WP_REST_Request $r)
    {
        $cfg = self::get_sms_settings_store();
        $phone = trim((string)($r->get_param('phone') ?? ''));
        $msg = trim((string)($r->get_param('message') ?? 'آزمایش ارتباط پیامک عرشلاین'));
        $msg = self::ensure_sms_suffix($msg);
        if ($phone !== ''){
            $ok = self::smsir_send_single($cfg['api_key'], $cfg['line_number'], $phone, $msg, null);
            return new \WP_REST_Response(['ok' => $ok], $ok ? 200 : 500);
        }
        $ok = ($cfg['api_key'] !== '' && $cfg['line_number'] !== '');
        return new \WP_REST_Response(['ok' => $ok], $ok ? 200 : 422);
    }

    /** POST /sms/send — create a job or send immediately. */
    public static function sms_send(\WP_REST_Request $r)
    {
    $cfg = self::get_sms_settings_store();
    if (!$cfg['enabled']) return new \WP_REST_Response(['error'=>'sms_disabled','message'=>'ارسال پیامک غیرفعال است. لطفاً در تنظیمات پیامک فعال کنید.'], 422);
    if ($cfg['api_key'] === '' || $cfg['line_number'] === '') return new \WP_REST_Response(['error'=>'missing_config','message'=>'تنظیمات پیامک ناقص است (API Key یا شماره خط).'], 422);

        $formId = (int)($r->get_param('form_id') ?? 0);
        $includeLink = !empty($r->get_param('include_link')) && $formId > 0;
        $groupIds = $r->get_param('group_ids'); if (!is_array($groupIds)) $groupIds = [];
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        // Enforce group scope for current user
        $groupIds = AccessControl::filterGroupIdsByCurrentUser($groupIds);
        $template = trim((string)($r->get_param('message') ?? ''));
        // Normalize Persian synonyms in template for early validations
        $templateNorm = str_replace(['#نام', '#لینک'], ['#name', '#link'], $template);
    if (empty($groupIds)) return new \WP_REST_Response(['error'=>'no_groups','message'=>'حداقل یک گروه را انتخاب کنید.'], 422);
    if ($template === '') return new \WP_REST_Response(['error'=>'empty_message','message'=>'متن پیام خالی است.'], 422);
        // If template uses #link but includeLink not requested (no form), abort
        if (strpos($templateNorm, '#link') !== false && !$includeLink){
            return new \WP_REST_Response(['error'=>'link_placeholder_without_form','message'=>'در متن از #لینک استفاده شده ولی فرمی انتخاب نشده است.'], 422);
        }

        // If a form link is requested, enforce that the form is mapped to the selected groups
        if ($includeLink){
            $allowedGroups = FormGroupAccessRepository::getGroupIds($formId);
            // Require explicit mapping to avoid sending links to groups without access
            if (empty($allowedGroups)){
                return new \WP_REST_Response(['error'=>'form_not_mapped','message'=>'فرم انتخابی به هیچ گروهی متصل نشده است. ابتدا در «اتصال فرم‌ها» گروه(ها) را برای این فرم تنظیم کنید.'], 422);
            }
            $invalid = array_values(array_diff($groupIds, $allowedGroups));
            if (!empty($invalid)){
                return new \WP_REST_Response([
                    'error' => 'form_not_allowed_for_groups',
                    'message' => 'فرم انتخابی به برخی از گروه‌های انتخابی متصل نیست.',
                    'invalid_group_ids' => $invalid,
                ], 422);
            }
        }

        // Resolve recipients from DB
        global $wpdb; $t = \Arshline\Support\Helpers::tableName('user_group_members');
        $in = implode(',', array_map('intval', $groupIds));
        $rows = $wpdb->get_results("SELECT id, group_id, name, phone, data FROM {$t} WHERE group_id IN ($in) AND phone IS NOT NULL AND phone <> ''", ARRAY_A) ?: [];
        // Deduplicate by phone
        $byPhone = [];
        foreach ($rows as $row){ $ph = preg_replace('/[^0-9]/', '', (string)$row['phone']); if ($ph==='') continue; $row['phone'] = $ph; $row['data'] = json_decode($row['data'] ?: '[]', true) ?: []; $byPhone[$ph] = $row; }
        $recipients = array_values($byPhone);
    if (empty($recipients)) return new \WP_REST_Response(['error'=>'no_recipients','message'=>'هیچ مخاطبی با شماره معتبر در گروه‌های انتخابی یافت نشد.'], 422);

        // Build messages with optional personal link
        $payload = [];
        foreach ($recipients as $m){
            $link = $includeLink ? self::build_member_form_link($formId, [ 'id'=>(int)$m['id'], 'name'=>$m['name'], 'phone'=>$m['phone'], 'data'=>$m['data'] ]) : '';
            // If link placeholder is used but we couldn't build a link, abort
            if ($includeLink && strpos($templateNorm, '#link') !== false && $link === ''){
                return new \WP_REST_Response(['error'=>'link_build_failed','member_id'=>(int)$m['id'], 'message'=>'ساخت لینک اختصاصی برای یکی از اعضا ناموفق بود. فرم باید فعال و دارای توکن عمومی باشد.'], 422);
            }
            $msg = self::compose_sms_template($template, [ 'id'=>(int)$m['id'], 'name'=>$m['name'], 'phone'=>$m['phone'], 'data'=>$m['data'] ], $link);
            $payload[] = [ 'phone'=>$m['phone'], 'message'=> self::ensure_sms_suffix($msg), 'vars' => [ 'name'=>$m['name'], 'phone'=>$m['phone'], 'link'=>$link ] ];
        }

        // Schedule or send now
        $scheduleAt = $r->get_param('schedule_at');
        $ts = null;
        if (is_numeric($scheduleAt)) { $ts = (int)$scheduleAt; }
        else if (is_string($scheduleAt) && $scheduleAt !== '') { $ts = strtotime($scheduleAt); }
        $now = time(); if ($ts !== null && $ts < ($now+60)) { $ts = $now + 60; }

        $maxImmediate = 50; // limit to avoid timeouts
        if ($ts === null && count($payload) <= $maxImmediate){
            $okCount = 0; $failCount = 0;
            foreach ($payload as $i=>$it){ $ok = self::smsir_send_single($cfg['api_key'], $cfg['line_number'], $it['phone'], $it['message'], null); if ($ok) $okCount++; else $failCount++; }
            return new \WP_REST_Response(['ok'=>true, 'sent'=>$okCount, 'failed'=>$failCount], 200);
        }
        // Create job in options and schedule cron
        $seq = (int)get_option('arsh_sms_job_seq', 0) + 1; update_option('arsh_sms_job_seq', $seq, false);
        $jobId = $seq;
        $job = [ 'id'=>$jobId, 'provider'=>'sms_ir', 'created_at'=>current_time('timestamp'), 'schedule_at'=> ($ts ?? ($now+5)), 'config'=>[ 'api_key'=>$cfg['api_key'], 'line_number'=>$cfg['line_number'] ], 'payload'=>$payload, 'cursor'=>0 ];
        update_option('arsh_sms_job_'.$jobId, $job, false);
        wp_schedule_single_event($job['schedule_at'], 'arshline_process_sms_job', [ $jobId ]);
        return new \WP_REST_Response(['ok'=>true, 'job_id'=>$jobId, 'schedule_at'=>$job['schedule_at'], 'recipients'=>count($payload)], 200);
    }

    /** Cron handler to process a queued SMS job in batches. */
    public static function process_sms_job($jobId)
    {
        $key = 'arsh_sms_job_'.(int)$jobId; $job = get_option($key, null);
        if (!$job || !is_array($job)) return;
        $cfg = $job['config'] ?? ['api_key'=>'','line_number'=>''];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $cursor = (int)($job['cursor'] ?? 0);
        $batch = array_slice($payload, $cursor, 40);
        foreach ($batch as $i=>$it){ self::smsir_send_single((string)$cfg['api_key'], (string)$cfg['line_number'], (string)$it['phone'], (string)$it['message'], null); }
        $cursor += count($batch);
        if ($cursor >= count($payload)){
            // Persist a trimmed result for auditing
            $result = [
                'job_id' => (int)$jobId,
                'finished_at' => time(),
                'total' => count($payload),
                'entries' => array_map(function($it){ return [ 'phone'=>$it['phone'], 'vars'=>($it['vars'] ?? []), 'message'=>$it['message'] ]; }, $payload),
            ];
            update_option('arsh_sms_result_'.(int)$jobId, $result, false);
            delete_option($key);
        } else {
            $job['cursor'] = $cursor; update_option($key, $job, false);
            wp_schedule_single_event(time()+30, 'arshline_process_sms_job', [ (int)$jobId ]);
        }
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
        // Background SMS job processor (runs via WP-Cron)
        add_action('arshline_process_sms_job', [self::class, 'process_sms_job'], 10, 1);
    }

    public static function user_can_manage_forms(): bool
    {
        return AccessControl::currentUserCanFeature('forms');
    }

    public static function user_can_manage_groups(): bool
    {
        return AccessControl::currentUserCanFeature('groups');
    }
    public static function user_can_send_sms(): bool
    {
        return AccessControl::currentUserCanFeature('sms');
    }
    public static function user_can_manage_settings(): bool
    {
        return AccessControl::currentUserCanFeature('settings');
    }
    public static function user_can_manage_users(): bool
    {
        return AccessControl::currentUserCanFeature('users');
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
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('reports'); },
            'args' => [
                'days' => [ 'type' => 'integer', 'required' => false ],
            ],
        ]);
        // Global settings (admin-only)
        register_rest_route('arshline/v1', '/settings', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_settings'],
                'permission_callback' => [self::class, 'user_can_manage_settings'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_settings'],
                'permission_callback' => [self::class, 'user_can_manage_settings'],
            ]
        ]);

        // Analytics (هوشنگ) endpoints
        register_rest_route('arshline/v1', '/analytics/config', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'get_analytics_config'],
        ]);
        // Simple LLM chat (minimal proxy). No grounding, no form data, just chat.
        register_rest_route('arshline/v1', '/ai/simple-chat', [
            'methods' => 'POST',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'ai_simple_chat'],
        ]);
        register_rest_route('arshline/v1', '/analytics/analyze', [
            'methods' => 'POST',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'analytics_analyze'],
        ]);
        // Chat history endpoints
        register_rest_route('arshline/v1', '/analytics/sessions', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'list_chat_sessions'],
        ]);
        register_rest_route('arshline/v1', '/analytics/sessions/(?P<session_id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'get_chat_messages'],
        ]);
        register_rest_route('arshline/v1', '/analytics/sessions/(?P<session_id>\d+)/export', [
            'methods' => 'GET',
            'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_options'); },
            'callback' => [self::class, 'export_chat_session'],
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
                'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_ai_config'],
                'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
            ]
        ]);
        register_rest_route('arshline/v1', '/ai/test', [
            'methods' => 'POST',
                'callback' => [self::class, 'test_ai_connect'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
        ]);
        register_rest_route('arshline/v1', '/ai/capabilities', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_ai_capabilities'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
        ]);
        register_rest_route('arshline/v1', '/ai/agent', [
            'methods' => 'POST',
            'callback' => [self::class, 'ai_agent'],
            'permission_callback' => function(){ return AccessControl::currentUserCanFeature('ai'); },
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

        // User Groups Management (feature-gated)
        register_rest_route('arshline/v1', '/user-groups', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ug_list_groups'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'ug_create_group'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
                'args' => [
                    'name' => [ 'type' => 'string', 'required' => true ],
                    'meta' => [ 'required' => false ],
                ]
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'ug_update_group'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'ug_delete_group'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ug_list_members'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'ug_add_members'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
                'args' => [ 'members' => [ 'required' => true ] ]
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members/(?P<member_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'ug_update_member'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'ug_delete_member'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members/(?P<member_id>\\d+)/token', [
            'methods' => 'POST',
            'callback' => [self::class, 'ug_ensure_member_token'],
            'permission_callback' => [self::class, 'user_can_manage_groups'],
        ]);
        // Bulk ensure tokens for a group's members
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/members/ensure-tokens', [
            'methods' => 'POST',
            'callback' => [self::class, 'ug_bulk_ensure_tokens'],
            'permission_callback' => [self::class, 'user_can_manage_groups'],
        ]);

        // Group custom fields
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/fields', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'ug_list_fields'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'ug_create_field'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);
        register_rest_route('arshline/v1', '/user-groups/(?P<group_id>\\d+)/fields/(?P<field_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'ug_update_field'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'ug_delete_field'],
                'permission_callback' => [self::class, 'user_can_manage_groups'],
            ],
        ]);

        // Form ↔ Group access mapping (admin-only)
        register_rest_route('arshline/v1', '/forms/(?P<form_id>\\d+)/access/groups', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_form_access_groups'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'set_form_access_groups'],
                'permission_callback' => [self::class, 'user_can_manage_forms'],
                'args' => [ 'group_ids' => [ 'required' => true ] ],
            ],
        ]);
        // Messaging (SMS)
        register_rest_route('arshline/v1', '/sms/settings', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_sms_settings'],
                'permission_callback' => [self::class, 'user_can_send_sms'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_sms_settings'],
                'permission_callback' => [self::class, 'user_can_send_sms'],
            ],
        ]);
        register_rest_route('arshline/v1', '/sms/test', [
            'methods' => 'POST',
            'callback' => [self::class, 'test_sms_connect'],
            'permission_callback' => [self::class, 'user_can_send_sms'],
        ]);
        register_rest_route('arshline/v1', '/sms/send', [
            'methods' => 'POST',
            'callback' => [self::class, 'sms_send'],
            'permission_callback' => [self::class, 'user_can_send_sms'],
        ]);

        // Role policies (super admin only)
        register_rest_route('arshline/v1', '/roles/policies', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'get_role_policies'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_role_policies'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
        ]);

        // Roles info (list roles + feature caps) — super admin only
        register_rest_route('arshline/v1', '/roles', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_roles_info'],
            'permission_callback' => function(){ return current_user_can('manage_options'); },
        ]);

        // Users management (super admin only)
        register_rest_route('arshline/v1', '/users', [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'list_users'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => [ 'search' => [ 'required' => false ], 'role' => [ 'required' => false ] ],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'create_user'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
                'args' => [ 'user_email' => [ 'required' => true ], 'user_login' => [ 'required' => true ], 'role' => [ 'required' => false ] ],
            ],
        ]);
        register_rest_route('arshline/v1', '/users/(?P<user_id>\\d+)', [
            [
                'methods' => 'PUT',
                'callback' => [self::class, 'update_user'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
            [
                'methods' => 'DELETE',
                'callback' => [self::class, 'delete_user'],
                'permission_callback' => function(){ return current_user_can('manage_options'); },
            ],
        ]);
    }

    // ========== User Groups Handlers ==========
    public static function ug_list_groups(WP_REST_Request $r)
    {
        // Enforce group scope for non-admins: filter returned groups to allowed set
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed === null) {
            $rows = GroupRepository::all();
        } else {
            $rows = array_values(array_filter(GroupRepository::all(), function($g) use ($allowed){ return in_array((int)$g->id, $allowed, true); }));
        }
        // Build member counts for listed groups in a single query
        $counts = [];
        try {
            global $wpdb; $t = \Arshline\Support\Helpers::tableName('user_group_members');
            $ids = array_map(fn($g)=> (int)$g->id, $rows);
            if (!empty($ids)){
                $in = implode(',', array_map('intval', $ids));
                $sql = "SELECT group_id, COUNT(*) AS c FROM {$t} WHERE group_id IN ($in) GROUP BY group_id";
                $rs = $wpdb->get_results($sql, ARRAY_A) ?: [];
                foreach ($rs as $r){ $counts[(int)$r['group_id']] = (int)$r['c']; }
            }
        } catch (\Throwable $e) { /* noop */ }
        $out = array_map(function($g) use ($counts){ return [
            'id' => $g->id,
            'name' => $g->name,
            'parent_id' => $g->parent_id,
            'meta' => $g->meta,
            'member_count' => isset($counts[$g->id]) ? (int)$counts[$g->id] : 0,
            'created_at' => $g->created_at,
            'updated_at' => $g->updated_at,
        ]; }, $rows);
        return new WP_REST_Response($out, 200);
    }

    // ===== Roles/users helpers =====
    public static function get_roles_info(WP_REST_Request $r)
    {
        global $wp_roles;
        if (!isset($wp_roles)) $wp_roles = wp_roles();
        $roles = [];
        foreach ($wp_roles->roles as $k=>$v){ $roles[] = [ 'key'=>$k, 'name'=>$v['name'] ?? $k ]; }
        return new WP_REST_Response(['roles'=>$roles, 'features'=>array_keys(AccessControl::featureCaps())], 200);
    }
    public static function list_users(WP_REST_Request $r)
    {
        $args = [ 'number' => 50 ];
        $search = (string)($r->get_param('search') ?? ''); if ($search !== ''){ $args['search'] = '*'.esc_attr($search).'*'; }
        $role = (string)($r->get_param('role') ?? ''); if ($role !== ''){ $args['role'] = $role; }
        $users = get_users($args);
        $items = array_map(function($u){
            $disabled = (bool) get_user_meta($u->ID, 'arsh_disabled', true);
            return [
                'id' => $u->ID,
                'user_login' => $u->user_login,
                'display_name' => $u->display_name,
                'email' => $u->user_email,
                'roles' => $u->roles,
                'disabled' => $disabled,
            ];
        }, $users);
        return new WP_REST_Response([ 'items'=>$items, 'count'=>count($items) ], 200);
    }
    public static function create_user(WP_REST_Request $r)
    {
        $email = sanitize_email((string)$r->get_param('user_email'));
        $login = sanitize_user((string)$r->get_param('user_login'));
        $role = (string)($r->get_param('role') ?? '');
        if (!$email || !$login) return new WP_REST_Response(['message'=>'invalid_input'], 422);
        $pass = wp_generate_password(12, true);
        $uid = wp_create_user($login, $pass, $email);
        if (is_wp_error($uid)) return new WP_REST_Response(['message'=>$uid->get_error_message()], 400);
        if ($role !== ''){ $u = new \WP_User($uid); $u->set_role($role); }
        return new WP_REST_Response(['id'=>$uid], 201);
    }
    public static function update_user(WP_REST_Request $r)
    {
        $uid = (int)$r['user_id']; $u = get_user_by('id', $uid); if (!$u) return new WP_REST_Response(['message'=>'not_found'], 404);
        $role = $r->get_param('role'); if (is_string($role) && $role!==''){ $u = new \WP_User($uid); $u->set_role($role); }
        $roles = $r->get_param('roles'); if (is_array($roles)){
            $u = new \WP_User($uid);
            foreach ($u->roles as $r0){ $u->remove_role($r0); }
            foreach ($roles as $r1){ if (is_string($r1) && $r1!=='') $u->add_role($r1); }
        }
        if ($r->offsetExists('disabled')){
            $disabled = (bool)$r->get_param('disabled');
            if ($disabled) { update_user_meta($uid, 'arsh_disabled', 1); }
            else { delete_user_meta($uid, 'arsh_disabled'); }
        }
        return new WP_REST_Response(['ok'=>true], 200);
    }
    public static function delete_user(WP_REST_Request $r)
    {
        $uid = (int)$r['user_id'];
        if ($uid <= 0) return new WP_REST_Response(['message'=>'invalid_id'], 422);
        $reassign = null;
        $ok = wp_delete_user($uid, $reassign);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok?200:404);
    }
    public static function ug_create_group(WP_REST_Request $r)
    {
        $name = trim((string)$r->get_param('name'));
        if ($name === '') return new WP_REST_Response(['message' => 'نام گروه الزامی است.'], 422);
        $meta = $r->get_param('meta'); if (!is_array($meta)) $meta = [];
        $parent_id = $r->get_param('parent_id');
        $pid = is_numeric($parent_id) ? (int)$parent_id : null; if ($pid === 0) { $pid = null; }
        if ($pid !== null) { if (!GroupRepository::find($pid)) { return new WP_REST_Response(['message' => 'گروه مادر نامعتبر است.'], 422); } }
        $g = new \Arshline\Modules\UserGroups\Group(['name' => $name, 'parent_id' => $pid, 'meta' => $meta]);
        $id = GroupRepository::save($g);
        return new WP_REST_Response(['id' => $id], 201);
    }
    public static function ug_update_group(WP_REST_Request $r)
    {
        $id = (int)$r['group_id'];
        $g = GroupRepository::find($id);
        if (!$g) return new WP_REST_Response(['message' => 'گروه یافت نشد.'], 404);
        $name = $r->get_param('name'); if (is_string($name)) $g->name = trim($name);
        if ($r->offsetExists('parent_id')){
            $parent_id = $r->get_param('parent_id');
            $pid = (is_numeric($parent_id) ? (int)$parent_id : null);
            if ($pid === 0) $pid = null;
            if ($pid === $g->id) { return new WP_REST_Response(['message' => 'نمی‌توانید گروه را مادر خودش قرار دهید.'], 422); }
            if ($pid !== null && !GroupRepository::find($pid)) { return new WP_REST_Response(['message' => 'گروه مادر نامعتبر است.'], 422); }
            $g->parent_id = $pid;
        }
        $meta = $r->get_param('meta'); if (is_array($meta)) $g->meta = $meta;
        GroupRepository::save($g);
        return new WP_REST_Response(['ok' => true], 200);
    }
    public static function ug_delete_group(WP_REST_Request $r)
    {
        $id = (int)$r['group_id'];
        $ok = GroupRepository::delete($id);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok?200:404);
    }
    public static function ug_list_members(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        // Enforce group scope
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        // Support pagination and search for large datasets
        $per_page = (int)($r->get_param('per_page') ?? 20);
        if ($per_page <= 0) $per_page = 20; if ($per_page > 200) $per_page = 200;
        $page = (int)($r->get_param('page') ?? 1); if ($page <= 0) $page = 1;
        $search = trim((string)($r->get_param('search') ?? ''));
        $orderby = (string)($r->get_param('orderby') ?? 'id');
        $order = (string)($r->get_param('order') ?? 'DESC');

        // Compute total first, then fetch page
        $total = MemberRepository::countAll($gid, $search);
        $rows = MemberRepository::paginated($gid, $per_page, $page, $search, $orderby, $order);
        $items = array_map(function($m){ return [
            'id' => $m->id,
            'group_id' => $m->group_id,
            'name' => $m->name,
            'phone' => $m->phone,
            'data' => $m->data,
            'token' => $m->token,
            'created_at' => $m->created_at,
        ];}, $rows);
        $resp = [
            'items' => $items,
            'total' => (int)$total,
            'page' => (int)$page,
            'per_page' => (int)$per_page,
            'total_pages' => (int)max(1, (int)ceil(($total ?: 0) / max(1, $per_page)))
        ];
        return new WP_REST_Response($resp, 200);
    }
    public static function ug_add_members(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $members = $r->get_param('members');
        if (!is_array($members)) return new WP_REST_Response(['message' => 'members باید آرایه باشد.'], 422);
        $n = MemberRepository::addBulk($gid, $members);
        return new WP_REST_Response(['inserted' => $n], 201);
    }
    public static function ug_delete_member(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $mid = (int)$r['member_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $ok = MemberRepository::delete($gid, $mid);
        return new WP_REST_Response(['ok' => (bool)$ok], $ok?200:404);
    }
    public static function ug_update_member(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $mid = (int)$r['member_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $name = $r->get_param('name');
        $phone = $r->get_param('phone');
        $data = $r->get_param('data');
        $fields = [];
        if (is_string($name)) $fields['name'] = $name;
        if (is_string($phone)) $fields['phone'] = $phone;
        if (is_array($data)) $fields['data'] = $data;
        if (empty($fields)) return new WP_REST_Response(['ok'=>false,'message'=>'no_fields'], 422);
        $ok = MemberRepository::update($gid, $mid, $fields);
        if ($ok) { try { MemberRepository::ensureToken($mid); } catch (\Throwable $e) { /* noop */ } }
        return new WP_REST_Response(['ok'=>(bool)$ok], $ok?200:404);
    }
    public static function ug_ensure_member_token(WP_REST_Request $r)
    {
        $mid = (int)$r['member_id'];
        // Gate by member's group scope
        $tok = null;
        try {
            $m = MemberRepository::find($mid);
            $allowed = AccessControl::allowedGroupIdsForCurrentUser();
            if ($m && ($allowed === null || in_array((int)$m->group_id, $allowed, true))){
                $tok = MemberRepository::ensureToken($mid);
            }
        } catch (\Throwable $e) { /* noop */ }
        if (!$tok) return new WP_REST_Response(['message' => 'عضو یافت نشد.'], 404);
        return new WP_REST_Response(['token' => $tok], 200);
    }

    public static function ug_bulk_ensure_tokens(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $count = 0;
        try {
            $members = MemberRepository::list($gid, 50000);
            foreach ($members as $m){ $tok = $m->token; if (!$tok){ $tok2 = MemberRepository::ensureToken((int)$m->id); if ($tok2) $count++; } }
        } catch (\Throwable $e) { /* noop */ }
        return new WP_REST_Response(['ok'=>true, 'generated' => $count], 200);
    }

    // ========== Group custom fields ==========
    public static function ug_list_fields(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $rows = UGFieldRepo::listByGroup($gid);
        $out = array_map(function($f){ return [
            'id' => $f->id,
            'group_id' => $f->group_id,
            'name' => $f->name,
            'label' => $f->label,
            'type' => $f->type,
            'options' => $f->options,
            'required' => $f->required,
            'sort' => $f->sort,
        ]; }, $rows);
        return new WP_REST_Response($out, 200);
    }
    public static function ug_create_field(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $name = trim((string)$r->get_param('name'));
        $label = trim((string)$r->get_param('label'));
        $type = trim((string)($r->get_param('type') ?? 'text'));
        $options = $r->get_param('options'); if (!is_array($options)) $options = [];
        $required = (bool)$r->get_param('required');
        $sort = (int)($r->get_param('sort') ?? 0);
        if ($gid<=0 || $name==='') return new WP_REST_Response(['message'=>'invalid'], 422);
        $f = new UGField([ 'group_id'=>$gid, 'name'=>$name, 'label'=>$label?:$name, 'type'=>$type, 'options'=>$options, 'required'=>$required, 'sort'=>$sort ]);
        $id = UGFieldRepo::save($f);
        return new WP_REST_Response(['id'=>$id], 201);
    }
    public static function ug_update_field(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $fid = (int)$r['field_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        $rows = UGFieldRepo::listByGroup($gid);
        $target = null; foreach ($rows as $f){ if ((int)$f->id === $fid) { $target = $f; break; } }
        if (!$target) return new WP_REST_Response(['message'=>'not_found'], 404);
        $p = $r->get_params();
        if (isset($p['name'])) $target->name = trim((string)$p['name']);
        if (isset($p['label'])) $target->label = trim((string)$p['label']);
        if (isset($p['type'])) $target->type = trim((string)$p['type']);
        if (isset($p['options']) && is_array($p['options'])) $target->options = $p['options'];
        if (isset($p['required'])) $target->required = (bool)$p['required'];
        if (isset($p['sort'])) $target->sort = (int)$p['sort'];
        UGFieldRepo::save($target);
        return new WP_REST_Response(['ok'=>true], 200);
    }
    public static function ug_delete_field(WP_REST_Request $r)
    {
        $gid = (int)$r['group_id']; $fid = (int)$r['field_id'];
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null && !in_array($gid, $allowed, true)){
            return new WP_REST_Response(['message' => 'دسترسی مجاز نیست.'], 403);
        }
        // Ensure it belongs to the group
        $rows = UGFieldRepo::listByGroup($gid);
        $ok = false; foreach ($rows as $f){ if ((int)$f->id === $fid) { $ok = UGFieldRepo::delete($fid); break; } }
        return new WP_REST_Response(['ok'=>(bool)$ok], $ok?200:404);
    }

    // ===== Roles/Policies endpoints (super admin) =====
    public static function get_role_policies(WP_REST_Request $r)
    {
        $pol = AccessControl::getPolicies();
        return new WP_REST_Response(['policies' => $pol], 200);
    }
    public static function update_role_policies(WP_REST_Request $r)
    {
        $body = $r->get_json_params(); if (!is_array($body)) $body = $r->get_params();
        $pol = is_array($body['policies'] ?? null) ? $body['policies'] : [];
        $saved = AccessControl::updatePolicies($pol);
        return new WP_REST_Response(['ok'=>true, 'policies'=>$saved], 200);
    }

    // ========== Form ↔ Group access mapping (admin-only) ==========
    public static function get_form_access_groups(WP_REST_Request $r)
    {
        $fid = (int)$r['form_id'];
        if ($fid <= 0) return new WP_REST_Response(['group_ids' => []], 200);
        $ids = FormGroupAccessRepository::getGroupIds($fid);
        $allowed = AccessControl::allowedGroupIdsForCurrentUser();
        if ($allowed !== null){
            $ids = array_values(array_intersect(array_map('intval', $ids), array_map('intval', $allowed)));
        }
        return new WP_REST_Response(['group_ids' => array_values($ids)], 200);
    }
    public static function set_form_access_groups(WP_REST_Request $r)
    {
        $fid = (int)$r['form_id'];
        if ($fid <= 0) return new WP_REST_Response(['error'=>'invalid_form_id'], 400);
        $arr = $r->get_param('group_ids');
        if (!is_array($arr)) $arr = [];
        $ids = array_values(array_unique(array_map('intval', $arr)));
        // Enforce group scope: only allow mapping within current user's allowed groups
        $ids = AccessControl::filterGroupIdsByCurrentUser($ids);
        FormGroupAccessRepository::setGroupIds($fid, $ids);
        return new WP_REST_Response(['ok'=>true, 'group_ids'=>$ids], 200);
    }

    // ===== Access control helpers =====
    /**
     * Check whether the current requester can access the given form via either:
     * - Logged-in WP user belonging to at least one allowed group (via filter hook), or
     * - A valid member token provided as request param `member_token` or header `X-Arsh-Member-Token`.
     * Returns [allowed:boolean, member?:array].
     */
    protected static function enforce_form_group_access($formId, WP_REST_Request $r): array
    {
        $formId = (int)$formId;
        $allowedGroups = FormGroupAccessRepository::getGroupIds($formId);
        // If no mapping exists, treat as public for backward-compat
        if (empty($allowedGroups)) return [ true, null ];

        // 1) Member token path (preferred for visitors)
        $tok = '';
        $p = $r->get_param('member_token'); if (is_string($p)) $tok = trim($p);
        if ($tok === ''){
            // Try headers
            if (method_exists($r, 'get_header')){
                $tok = (string)($r->get_header('X-Arsh-Member-Token') ?: $r->get_header('x-arsh-member-token'));
                $tok = trim($tok);
            }
        }
        if ($tok !== ''){
            $m = MemberRepository::verifyToken($tok);
            if ($m && in_array((int)$m->group_id, $allowedGroups, true)){
                // Attach member to request context for later personalization
                return [ true, [
                    'id' => (int)$m->id,
                    'group_id' => (int)$m->group_id,
                    'name' => (string)$m->name,
                    'phone' => (string)$m->phone,
                    'data' => is_array($m->data) ? $m->data : [],
                ] ];
            }
        }

        // 2) Logged-in WP user path — allow integrators to declare user→group mapping via filter
        $uid = get_current_user_id();
        if ($uid > 0 && function_exists('apply_filters')){
            $userGroupIds = apply_filters('arshline_user_group_ids', [], $uid);
            if (is_array($userGroupIds)){
                $userGroupIds = array_map('intval', $userGroupIds);
                foreach ($userGroupIds as $gid){ if (in_array($gid, $allowedGroups, true)) return [ true, null ]; }
            }
        }
        return [ false, null ];
    }

    /** Replace #placeholders using member data for title/description meta (server-side hydration). */
    protected static function hydrate_meta_with_member(array $meta, ?array $member): array
    {
        if (!$member) return $meta;
        $repl = [];
        // Flatten member for simple replacement keys
        $repl['name'] = (string)($member['name'] ?? '');
        $repl['phone'] = (string)($member['phone'] ?? '');
        $data = is_array($member['data'] ?? null) ? ($member['data']) : [];
        foreach ($data as $k => $v){ if (is_scalar($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)) { $repl[(string)$k] = (string)$v; } }
        $replaceIn = function($s) use ($repl){ if (!is_string($s) || $s==='') return $s; return preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k,$repl) ? (string)$repl[$k] : $m[0]; }, $s); };
        if (isset($meta['title'])) $meta['title'] = $replaceIn($meta['title']);
        if (isset($meta['description'])) $meta['description'] = $replaceIn($meta['description']);
        return $meta;
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
        // Enforce group-based access if any mapping exists
        list($ok, $member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
        $fields = FieldRepository::listByForm($id);
        // Minimal personalization via GET params for title/description placeholders like #name
        $meta = $form->meta;
        $params = $request->get_params();
        $repl = [];
        foreach ($params as $k=>$v){ if (is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)) { $repl[$k] = $v; } }
        $replaceIn = function($s) use ($repl){ if (!is_string($s) || $s==='') return $s; return preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k,$repl) ? (string)$repl[$k] : $m[0]; }, $s); };
        if (isset($meta['title'])) $meta['title'] = $replaceIn($meta['title']);
        if (isset($meta['description'])) $meta['description'] = $replaceIn($meta['description']);
        // Then apply member-based hydration for server-side personalization
        $meta = self::hydrate_meta_with_member($meta, $member);
        $payload = [
            'id' => $form->id,
            'status' => $form->status,
            'meta' => $meta,
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
        $model = isset($arr['ai_model']) && is_scalar($arr['ai_model']) ? (string)$arr['ai_model'] : 'gpt-4o';
    $parser = isset($arr['ai_parser']) && is_scalar($arr['ai_parser']) ? (string)$arr['ai_parser'] : 'hybrid'; // 'internal' | 'hybrid' | 'llm'
        $parser = in_array($parser, ['internal','hybrid','llm'], true) ? $parser : 'hybrid';
    // Analytics defaults
    $anaMaxTok = isset($arr['ai_ana_max_tokens']) && is_numeric($arr['ai_ana_max_tokens']) ? max(16, min(4096, (int)$arr['ai_ana_max_tokens'])) : 1200;
    $anaChunkSize = isset($arr['ai_ana_chunk_size']) && is_numeric($arr['ai_ana_chunk_size']) ? max(50, min(2000, (int)$arr['ai_ana_chunk_size'])) : 800;
    $anaAutoFmt = isset($arr['ai_ana_auto_format']) ? (bool)$arr['ai_ana_auto_format'] : true;
    $anaShowAdv = isset($arr['ai_ana_show_advanced']) ? (bool)$arr['ai_ana_show_advanced'] : false;
        // Hoshang-specific optional overrides
        $hoshModel = isset($arr['ai_hosh_model']) && is_scalar($arr['ai_hosh_model']) ? (string)$arr['ai_hosh_model'] : '';
        $hoshMode  = isset($arr['ai_hosh_mode']) && is_scalar($arr['ai_hosh_mode']) ? (string)$arr['ai_hosh_mode'] : 'hybrid';
        $hoshMode  = in_array($hoshMode, ['llm','structured','hybrid'], true) ? $hoshMode : 'hybrid';
        // New: allowlist for AI-accessible menus/actions
        $allowedMenus = isset($arr['ai_allowed_menus']) && is_array($arr['ai_allowed_menus']) ? array_values(array_unique(array_filter(array_map('strval', $arr['ai_allowed_menus'])))) : ['dashboard','forms'];
        $allowedActions = isset($arr['ai_allowed_actions']) && is_array($arr['ai_allowed_actions']) ? array_values(array_unique(array_filter(array_map('strval', $arr['ai_allowed_actions'])))) : [];
    return [ 'base_url' => $base, 'api_key' => $key, 'enabled' => $enabled, 'model' => $model, 'parser' => $parser, 'hosh_model' => $hoshModel, 'hosh_mode' => $hoshMode, 'ana_max_tokens' => $anaMaxTok, 'ana_chunk_size' => $anaChunkSize, 'ana_auto_format' => $anaAutoFmt, 'ana_show_advanced' => $anaShowAdv, 'allowed_menus' => $allowedMenus, 'allowed_actions' => $allowedActions ];
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
    $anaMaxTok = isset($cfg['ana_max_tokens']) && is_numeric($cfg['ana_max_tokens']) ? max(16, min(4096, (int)$cfg['ana_max_tokens'])) : null;
    $anaChunkSize = isset($cfg['ana_chunk_size']) && is_numeric($cfg['ana_chunk_size']) ? max(50, min(2000, (int)$cfg['ana_chunk_size'])) : null;
    $anaAutoFmt = isset($cfg['ana_auto_format']) ? (bool)$cfg['ana_auto_format'] : null;
    $anaShowAdv = isset($cfg['ana_show_advanced']) ? (bool)$cfg['ana_show_advanced'] : null;
        $hoshModel = is_scalar($cfg['hosh_model'] ?? '') ? trim((string)$cfg['hosh_model']) : '';
        $hoshMode  = is_scalar($cfg['hosh_mode'] ?? '') ? trim((string)$cfg['hosh_mode']) : '';
        $allowedMenus = isset($cfg['allowed_menus']) && is_array($cfg['allowed_menus']) ? array_values(array_unique(array_filter(array_map('strval', $cfg['allowed_menus'])))) : null;
        $allowedActions = isset($cfg['allowed_actions']) && is_array($cfg['allowed_actions']) ? array_values(array_unique(array_filter(array_map('strval', $cfg['allowed_actions'])))) : null;
        $cur = get_option('arshline_settings', []);
        $arr = is_array($cur) ? $cur : [];
        $arr['ai_base_url'] = substr($base, 0, 500);
        $arr['ai_api_key']  = substr($key, 0, 2000);
        $arr['ai_enabled']  = $enabled;
        if ($model !== ''){ $arr['ai_model'] = substr(preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', $model), 0, 100); }
    if ($parser !== ''){ $arr['ai_parser'] = in_array($parser, ['internal','hybrid','llm'], true) ? $parser : 'hybrid'; }
    if ($anaMaxTok !== null){ $arr['ai_ana_max_tokens'] = $anaMaxTok; }
    if ($anaChunkSize !== null){ $arr['ai_ana_chunk_size'] = $anaChunkSize; }
    if ($anaAutoFmt !== null){ $arr['ai_ana_auto_format'] = (bool)$anaAutoFmt; }
    if ($anaShowAdv !== null){ $arr['ai_ana_show_advanced'] = (bool)$anaShowAdv; }
        if ($hoshModel !== ''){ $arr['ai_hosh_model'] = substr(preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', $hoshModel), 0, 100); }
        if ($hoshMode  !== ''){ $arr['ai_hosh_mode']  = in_array($hoshMode, ['llm','structured','hybrid'], true) ? $hoshMode : 'hybrid'; }
        if ($allowedMenus !== null){ $arr['ai_allowed_menus'] = $allowedMenus; }
        if ($allowedActions !== null){ $arr['ai_allowed_actions'] = $allowedActions; }
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
                    [ 'id' => 'set_setting', 'label' => 'تغییر تنظیمات سراسری', 'params' => ['key' => 'ai_enabled|min_submit_seconds|rate_limit_per_min|block_svg|ai_model|ai_parser', 'value' => 'string|number|boolean'], 'confirm' => true, 'examples' => [ 'فعال کردن هوش مصنوعی', 'مدل را روی gpt-5 بگذار', 'تحلیل دستورات با اوپن‌ای‌آی', 'تحلیلگر را هیبرید کن' ] ],
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
                if ($s > 0){ $scored[] = [ 'id' => (int)$f['id'], 'title' => (string)$f['title'], 'score' => $s ]; }
            }
            usort($scored, function($x,$y){ return $y['score'] <=> $x['score']; });
            return array_slice($scored, 0, max(1, $limit));
        };
        // Allow site owner to control auto-confirm threshold for non-destructive title matches
        $get_auto_confirm_threshold = function(): float {
            $default = 0.75; // if >= this score and exactly one match, execute directly
            if (function_exists('apply_filters')){
                $val = apply_filters('arshline_ai_title_auto_confirm_threshold', $default);
                $num = is_numeric($val) ? (float)$val : $default;
                return max(0.0, min(1.0, $num));
            }
            return $default;
        };
        // UI context for page-aware LLM prompting
        $ui_tab = (string)($request->get_param('ui_tab') ?? '');
        $ui_tab = $ui_tab !== '' ? $ui_tab : 'dashboard';
        $ui_route = (string)($request->get_param('ui_route') ?? '');
        // Build a concise capability shortlist relevant to the current tab
        $capsResp = self::get_ai_capabilities($request);
        $capsData = $capsResp instanceof WP_REST_Response ? $capsResp->get_data() : ['capabilities'=>[]];
        $allCaps = $capsData['capabilities'] ?? [];
        $filterCaps = function(array $all, string $tab) {
            $takeIds = [];
            $tab = strtolower($tab);
            if ($tab === 'forms' || strpos($tab, 'builder') !== false){
                $takeIds = ['open_tab','open_builder','open_editor','public_link','list_forms','create_form','delete_form','open_form','close_form','draft_form','update_form_title','export_csv','ui','help'];
            } elseif ($tab === 'reports'){
                $takeIds = ['open_tab','export_csv','list_forms','ui','help'];
            } elseif ($tab === 'settings'){
                $takeIds = ['open_tab','set_setting','ui','help'];
            } else {
                $takeIds = ['open_tab','list_forms','open_builder','public_link','ui','help'];
            }
            $out = [];
            foreach ($all as $group){
                if (!is_array($group['items'] ?? null)) continue;
                foreach (($group['items'] ?? []) as $it){
                    $id = (string)($it['id'] ?? '');
                    if ($id !== '' && in_array($id, $takeIds, true)){
                        $out[] = [
                            'id' => $id,
                            'label' => (string)($it['label'] ?? $id),
                            'examples' => is_array($it['examples'] ?? null) ? array_slice($it['examples'], 0, 2) : [],
                        ];
                    }
                }
            }
            return $out;
        };
        $kb = $filterCaps(is_array($allCaps) ? $allCaps : [], $ui_tab);
        // New structured intents for Hoshyar (هوشیار)
        $intentName = (string)($request->get_param('intent') ?? '');
        $intentName = trim($intentName);
        if ($intentName !== ''){
            try {
                $params = $request->get_param('params');
                if (!is_array($params)) $params = [];
                $out = Hoshyar::agent([ 'intent' => $intentName, 'params' => $params ]);
                return new \WP_REST_Response($out, 200);
            } catch (\Throwable $e) {
                return new \WP_REST_Response(['ok'=>false,'error'=>'hoshyar_error'], 200);
            }
        }

        // Plan-based flow: { plan: {...}, confirm?: bool }
        $planPayload = $request->get_param('plan');
        if (is_array($planPayload)){
            try {
                $confirm = (bool)$request->get_param('confirm');
                $out = Hoshyar::agent([ 'plan' => $planPayload, 'confirm' => $confirm ]);
                return new \WP_REST_Response($out, 200);
            } catch (\Throwable $e) {
                return new \WP_REST_Response(['ok'=>false,'error'=>'hoshyar_error'], 200);
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
            // 0) Prefer LLM plan parsing when configured — this enables multi-step flows
            $s0 = self::get_ai_settings();
            $allowedMenus0 = array_map('strval', $s0['allowed_menus'] ?? []);
            $allowedActions0 = array_map('strval', $s0['allowed_actions'] ?? []);
            $parserMode0 = $s0['parser'] ?? 'hybrid';
            $llmReady0 = ($s0['enabled'] && !empty($s0['base_url']) && !empty($s0['api_key']));
            $hadPlan0 = false;
            if ($llmReady0 && in_array($parserMode0, ['hybrid','llm'], true)){
                $plan0 = self::llm_parse_plan($cmd, $s0, [ 'ui_tab' => $ui_tab, 'ui_route' => $ui_route, 'kb' => $kb ]);
                if (is_array($plan0) && !empty($plan0['steps'])){
                    $hadPlan0 = true;
                    // Validate/preview via Hoshyar (will refuse invalid/unknown actions)
                    try {
                        // If allowlist of actions is configured, filter/deny before preview
                        if (!empty($allowedActions0)){
                            foreach (($plan0['steps'] ?? []) as $st){ $aa = (string)($st['action'] ?? ''); if ($aa && !in_array($aa, $allowedActions0, true)) { return new \WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'این عملیات مجاز نیست: '.$aa], 403); } }
                        }
                        $out0 = Hoshyar::agent([ 'plan' => $plan0, 'confirm' => false ]);
                        if (is_array($out0) && !empty($out0['ok'])){
                            return new \WP_REST_Response($out0, 200);
                        }
                    } catch (\Throwable $e) { /* fall through to other parsers */ }
                }
            }
            // 0.5) Internal heuristic multi-step parser as fallback (no external LLM required)
            // Handles phrases like: "فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن، اسمش را چکاپ سه بگذار"
            // Produces a plan: create_form (with extracted title) + N x add_field (inferred types)
            $tryInternalPlan = (!$llmReady0 || in_array($parserMode0, ['hybrid','internal'], true) || ($llmReady0 && $parserMode0==='llm' && !$hadPlan0));
            if ($tryInternalPlan){
                $iplan = self::internal_parse_plan($cmd);
                if (is_array($iplan) && !empty($iplan['steps']) && count($iplan['steps']) >= 1){
                    try {
                        if (!empty($allowedActions0)){
                            foreach (($iplan['steps'] ?? []) as $st){ $aa = (string)($st['action'] ?? ''); if ($aa && !in_array($aa, $allowedActions0, true)) { return new \WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'این عملیات مجاز نیست: '.$aa], 403); } }
                        }
                        $outPrev = Hoshyar::agent([ 'plan' => $iplan, 'confirm' => false ]);
                        if (is_array($outPrev) && !empty($outPrev['ok'])){
                            return new \WP_REST_Response($outPrev, 200);
                        }
                    } catch (\Throwable $e) { /* ignore */ }
                }
            }
            // Parser routing based on AI mode
            $s = self::get_ai_settings();
            $parserMode = $s['parser'] ?? 'hybrid';
            $llmReady = ($s['enabled'] && !empty($s['base_url']) && !empty($s['api_key']));
            if ($llmReady && $parserMode === 'llm'){
                $intent = self::llm_parse_command($cmd, $s, [ 'ui_tab' => $ui_tab, 'ui_route' => $ui_route, 'kb' => $kb ]);
                if (is_array($intent) && isset($intent['action'])){
                    $action = (string)$intent['action'];
                    // Map add_field with title->id when needed
                    if ($action === 'add_field'){
                        $itype = (string)($intent['type'] ?? '');
                        if (!empty($intent['title']) && empty($intent['id'])){
                            $matches = $find_by_title((string)$intent['title'], 5);
                            if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                            elseif (!empty($matches)){
                                $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'add_field','type'=>$itype?:'short_text']], 200);
                            }
                        }
                        if (!empty($intent['id'])){
                            $fid = (int)$intent['id']; $itype = $itype ?: 'short_text';
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'confirm',
                                'message'=>'یک سوال '+($itype==='short_text'?'پاسخ کوتاه':'از نوع '+$itype)+' به فرم '+$fid+' اضافه شود؟',
                                'confirm_action'=>['action'=>'add_field','params'=>['id'=>$fid,'type'=>$itype]]
                            ], 200);
                        }
                    }
                    // Map title->id if id is missing but title is present
                    if (!empty($intent['title']) && empty($intent['id'])){
                        $matches = $find_by_title((string)$intent['title'], 5);
                        if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                        elseif (!empty($matches)){
                            $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                            $msg = ($action==='open_results') ? 'نتایج کدام فرم را باز کنم؟' : 'کدام فرم را ویرایش کنم؟';
                            $clarifyTarget = ($action==='open_results') ? 'open_results' : 'open_builder';
                            return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>$msg,'param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>$clarifyTarget]], 200);
                        }
                    }
                    // Pass-through common actions
                    if (!empty($intent['action'])){
                        if ($action === 'open_tab' && !empty($intent['tab'])){
                            $tab = (string)$intent['tab'];
                            if (!empty($allowedMenus0) && !in_array($tab, $allowedMenus0, true)) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'دسترسی به این منو مجاز نیست: '.$tab], 403);
                            return new WP_REST_Response(['ok'=>true,'action'=>'open_tab','tab' => $tab], 200);
                        }
                        if (!empty($allowedActions0) && !in_array($action, $allowedActions0, true)) return new WP_REST_Response(['ok'=>false,'error'=>'forbidden','message'=>'این عملیات مجاز نیست: '.$action], 403);
                        if ($action === 'open_builder' && !empty($intent['id'])) return new WP_REST_Response(['ok'=>true,'action'=>'open_builder','id' => (int)$intent['id']], 200);
                        if ($action === 'open_results' && !empty($intent['id'])) return new WP_REST_Response(['ok'=>true,'action'=>'open_results','id' => (int)$intent['id']], 200);
                        if ($action === 'open_editor' && !empty($intent['id'])){ $idx = isset($intent['index']) ? (int)$intent['index'] : 0; return new WP_REST_Response(['ok'=>true,'action'=>'open_editor','id' => (int)$intent['id'],'index'=>$idx], 200); }
                        if ($action === 'create_form' && !empty($intent['title'])) return new WP_REST_Response(['ok'=>true,'action'=>'confirm','message'=>'ایجاد فرم جدید با عنوان «'.(string)$intent['title'].'» تایید می‌کنید؟','confirm_action'=> [ 'action'=>'create_form', 'params'=>['title' => (string)$intent['title']] ]], 200);
                        if ($action === 'delete_form' && !empty($intent['id'])){ $fid = (int)$intent['id']; return new WP_REST_Response(['ok'=>true,'action'=>'confirm','message'=>'حذف فرم شماره '.$fid.' تایید می‌کنید؟ این عمل قابل بازگشت نیست.','confirm_action'=> [ 'action'=>'delete_form', 'params'=>['id'=>$fid] ]], 200); }
                        if ($action === 'list_forms'){ $forms = self::get_forms_list(); return new WP_REST_Response(['ok'=>true, 'action'=>'list_forms', 'forms'=>$forms], 200); }
                    }
                }
                // else continue to internal parsing
            }
            // Add field (short_text) — examples:
            // "(یک )?سوال (پاسخ کوتاه|کوتاه|short_text) (در فرم (\d+|{title}))? اضافه کن|بساز"
            // New phrasing support: "افزودن سوال پاسخ کوتاه (به|در) فرم X"
            if (preg_match('/^(?:یه|یک)?\s*سوال\s*(?:پاسخ\s*کوتاه|کوتاه|short[_\s-]*text)\s*(?:را|رو)?\s*(?:اضافه\s*کن|بساز)(?:\s*در\s*فرم\s*(.+))?$/iu', $cmd, $m)
                || preg_match('/^(?:افزودن|اضافه\s*کردن)\s*سوال\s*(?:پاسخ\s*کوتاه|کوتاه|short[_\s-]*text)(?:\s*(?:به|در)\s*فرم\s*(.+))?$/iu', $cmd, $m)){
                $target = isset($m[1]) ? trim((string)$m[1]) : '';
                $fid = 0;
                if ($target !== ''){
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $num = (int) strtr(preg_replace('/\D+/u','', $target), $fa2en);
                    if ($num > 0){ $fid = $num; }
                    else {
                        $matches = $find_by_title($target, 5);
                        if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                            $fid = (int)$matches[0]['id'];
                        } elseif (!empty($matches)){
                            $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                            return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'add_field','type'=>'short_text']], 200);
                        }
                    }
                }
                if ($fid > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'یک سوال پاسخ کوتاه به فرم '.$fid.' اضافه شود؟',
                        'confirm_action'=> [ 'action'=>'add_field', 'params'=>['id'=>$fid, 'type'=>'short_text'] ]
                    ], 200);
                }
                // No target provided: ask to choose a form
                $req = new WP_REST_Request('GET', '/arshline/v1/forms');
                $res = self::get_forms($req);
                $rows = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=> (int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$list,'clarify_action'=>['action'=>'add_field','type'=>'short_text']], 200);
            }
            // Help / capabilities
            if (preg_match('/^(کمک|راهنما|لیست\s*دستورات)$/u', $cmd)){
                $caps = self::get_ai_capabilities($request);
                $data = $caps instanceof WP_REST_Response ? $caps->get_data() : ['capabilities'=>[]];
                return new WP_REST_Response(['ok'=>true, 'action'=>'help', 'capabilities'=>$data['capabilities'] ?? []], 200);
            }
            // User Groups (UG) intents — lightweight internal parser
            // 1) Open UG panel (optionally a specific tab)
            if (preg_match('/^(?:برو\s*به\s*)?(?:کاربران\s*\/\s*)?(گروه(?:‌|\s|-)*های\s*کاربری)(?:\s*،?\s*(?:تب)?\s*(گروه(?:‌|\s|-)*ها|اعضا|اتصال|نقشه|فیلد(?:های)?\s*سفارشی))?$/iu', $cmd, $mUG)){
                $tabWord = isset($mUG[2]) ? trim((string)$mUG[2]) : '';
                $tab = 'groups';
                if ($tabWord !== ''){
                    $w = mb_strtolower($tabWord, 'UTF-8');
                    if (preg_match('/^اعضا$/u', $w)) $tab = 'members';
                    elseif (preg_match('/^(اتصال|نقشه)$/u', $w)) $tab = 'mapping';
                    elseif (preg_match('/^فیلد/u', $w)) $tab = 'custom_fields';
                    else $tab = 'groups';
                }
                return new WP_REST_Response(['ok'=>true,'action'=>'open_ug','tab'=>$tab], 200);
            }
            // Small helper: find groups by fuzzy name
            $find_group_candidates = function(string $needle, int $limit = 5): array {
                $needle = trim($needle);
                if ($needle === '') return [];
                try { $rows = \Arshline\Modules\UserGroups\GroupRepository::paginated($limit, 1, $needle, 'name', 'ASC'); }
                catch (\Throwable $e) { $rows = []; }
                $nl = function($s){ return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s); };
                $n = $nl($needle);
                $out = [];
                foreach ($rows as $g){
                    $name = (string)$g->name;
                    $nameNL = $nl($name);
                    $pos = ($n !== '' && $nameNL !== '' && ($p = mb_stripos($nameNL, $n, 0, 'UTF-8')) !== false) ? (int)$p : -1;
                    $score = $pos >= 0 ? (0.9 - min(0.6, $pos*0.05)) : 0.4; // rough heuristic
                    $out[] = [ 'id'=>(int)$g->id, 'name'=>$name, 'score'=>$score ];
                }
                usort($out, function($a,$b){ return $b['score'] <=> $a['score']; });
                return array_slice($out, 0, max(1,$limit));
            };
            // 2) Create group by name
            if (preg_match('/^(?:ایجاد|بساز|درست\s*کن|ساختن)\s*گروه\s*(?:جدید\s*)?(?:به\s*نام|با\s*نام)?\s*(.+)$/iu', $cmd, $mGCreate)){
                $name = trim((string)$mGCreate[1]);
                $name = trim($name, '\"\'\u{00AB}\u{00BB}'); // strip quotes if any
                if ($name !== ''){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'ایجاد گروه کاربری با نام «'.$name.'» تایید می‌کنید؟',
                        'confirm_action'=> [ 'action'=>'ug_create_group', 'params'=>['name'=>$name] ]
                    ], 200);
                }
            }
            // 3) Rename/update group name by id: "نام گروه 12 را به X تغییر بده"
            if (preg_match('/^نام\s*گروه\s*(\d+)\s*(?:را|رو)?\s*(?:به|به\s*نام)\s*(.+)\s*(?:تغییر\s*بده|تغییر\s*ده|بگذار|بذار|قرار\s*ده|کن)$/iu', $cmd, $mGRename)){
                $gid = (int)$mGRename[1]; $newName = trim((string)$mGRename[2]);
                if ($gid > 0 && $newName !== ''){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'نام گروه '.$gid.' به «'.$newName.'» تغییر داده شود؟',
                        'confirm_action'=> [ 'action'=>'ug_update_group', 'params'=>['id'=>$gid,'name'=>$newName] ]
                    ], 200);
                }
            }
            // 3b) Rename group by name (no id): "نام گروه فروش را به مشتریان تغییر بده"
            if (preg_match('/^نام\s*گروه\s+(.+?)\s*(?:را|رو)?\s*(?:به|به\s*نام)\s*(.+)\s*(?:تغییر\s*بده|تغییر\s*ده|بگذار|بذار|قرار\s*ده|کن)$/iu', $cmd, $mGRenameByName)){
                $gname = trim((string)$mGRenameByName[1]); $newName = trim((string)$mGRenameByName[2]);
                if ($gname !== '' && $newName !== ''){
                    $cands = $find_group_candidates($gname, 5);
                    if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.65){
                        $gid = (int)$cands[0]['id'];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'نام گروه '.$gid.' («'.($cands[0]['name']??'').'») به «'.$newName.'» تغییر داده شود؟',
                            'confirm_action'=> [ 'action'=>'ug_update_group', 'params'=>['id'=>$gid,'name'=>$newName] ]
                        ], 200);
                    }
                    if (!empty($cands)){
                        $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'clarify',
                            'kind'=>'options',
                            'message'=>'نام کدام گروه را تغییر بدهم؟',
                            'param_key'=>'id',
                            'options'=>$opts,
                            'clarify_action'=> [ 'action'=>'ug_update_group', 'params'=> ['name'=>$newName] ]
                        ], 200);
                    }
                }
            }
            // 4) Ensure tokens for a group id
            if (preg_match('/^(?:تولید|ایجاد|بساز)\s*(?:توکن|token)(?:\s*برای)?\s*اعضا(?:ی)?\s*گروه\s*(\d+)$/iu', $cmd, $mTok)){
                $gid = (int)$mTok[1];
                if ($gid > 0){
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'توکن اعضای گروه '.$gid.' تولید شود؟',
                        'confirm_action'=> [ 'action'=>'ug_ensure_tokens', 'params'=>['group_id'=>$gid] ]
                    ], 200);
                }
            }
            // 4b) Ensure tokens by group name
            if (preg_match('/^(?:تولید|ایجاد|بساز)\s*(?:توکن|token)\s*(?:برای)?\s*اعضا(?:ی)?\s*گروه\s+(.+)$/iu', $cmd, $mTokName)){
                $gname = trim((string)$mTokName[1]);
                if ($gname !== ''){
                    $cands = $find_group_candidates($gname, 5);
                    if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.65){
                        $gid = (int)$cands[0]['id'];
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'توکن اعضای گروه '.$gid.' («'.($cands[0]['name']??'').'») تولید شود؟',
                            'confirm_action'=> [ 'action'=>'ug_ensure_tokens', 'params'=>['group_id'=>$gid] ]
                        ], 200);
                    }
                    if (!empty($cands)){
                        $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'clarify',
                            'kind'=>'options',
                            'message'=>'برای کدام گروه توکن بسازم؟',
                            'param_key'=>'group_id',
                            'options'=>$opts,
                            'clarify_action'=> [ 'action'=>'ug_ensure_tokens' ]
                        ], 200);
                    }
                }
            }
            // 5) Download members template for group id
            if (preg_match('/^(?:دانلود|بگیر)\s*(?:فایل|نمونه|تمپلیت)\s*اعضا(?:ی)?\s*گروه\s*(\d+)$/iu', $cmd, $mTpl)){
                $gid = (int)$mTpl[1];
                if ($gid > 0){ return new WP_REST_Response(['ok'=>true,'action'=>'ug_download_members_template','group_id'=>$gid], 200); }
            }
            // 6) Export per-member links for a group id OR a form id
            if (preg_match('/^(?:خروجی|دانلود)\s*(?:لینک(?:\s*های)?|پیوند(?:\s*ها)?)\s*اعضا(?:ی)?\s*(?:گروه\s*(\d+)|برای\s*فرم\s*(\d+))$/iu', $cmd, $mExp)){
                $gid = isset($mExp[1]) ? (int)$mExp[1] : 0; $fid = isset($mExp[2]) ? (int)$mExp[2] : 0;
                if ($gid > 0){ return new WP_REST_Response(['ok'=>true,'action'=>'ug_export_links','group_id'=>$gid], 200); }
                if ($fid > 0){ return new WP_REST_Response(['ok'=>true,'action'=>'ug_export_links','form_id'=>$fid], 200); }
            }
            // 6b) Export links by group name
            if (preg_match('/^(?:خروجی|دانلود)\s*(?:لینک(?:\s*های)?|پیوند(?:\s*ها)?)\s*اعضا(?:ی)?\s*گروه\s+(.+)$/iu', $cmd, $mExpName)){
                $gname = trim((string)$mExpName[1]);
                if ($gname !== ''){
                    $cands = $find_group_candidates($gname, 5);
                    if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.65){
                        $gid = (int)$cands[0]['id'];
                        return new WP_REST_Response(['ok'=>true,'action'=>'ug_export_links','group_id'=>$gid], 200);
                    }
                    if (!empty($cands)){
                        $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'clarify',
                            'kind'=>'options',
                            'message'=>'خروجی لینک‌های اعضای کدام گروه؟',
                            'param_key'=>'group_id',
                            'options'=>$opts,
                            'clarify_action'=> [ 'action'=>'ug_export_links' ]
                        ], 200);
                    }
                }
            }
            // 7) Set form access groups by numeric ids: "برای فرم 5 گروه‌های 2،3 را مجاز کن"
            if (preg_match('/^برای\s*فرم\s*(\d+)\s*گروه(?:‌|\s|-)*های\s*([\d\s,،و]+)\s*(?:را)?\s*(?:مجاز|فعال)\s*کن$/iu', $cmd, $mMap)){
                $fid = (int)$mMap[1]; $list = (string)$mMap[2];
                if ($fid > 0){
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $list = strtr($list, $fa2en);
                    preg_match_all('/\d+/', $list, $mm);
                    $gids = array_values(array_unique(array_map('intval', $mm[0] ?? [])));
                    return new WP_REST_Response([
                        'ok'=>true,
                        'action'=>'confirm',
                        'message'=>'دسترسی فرم '.$fid.' برای گروه‌ها ['.implode(', ', $gids).'] تنظیم شود؟',
                        'confirm_action'=> [ 'action'=>'ug_set_form_access', 'params'=>['form_id'=>$fid, 'group_ids'=>$gids] ]
                    ], 200);
                }
            }
            // 7b) Set form access groups by NAMES: "برای فرم 5 گروه‌های فروش، مشتریان را مجاز کن"
            if (preg_match('/^برای\s*فرم\s*(\d+)\s*گروه(?:‌|\s|-)*های\s+(.+?)\s*(?:را)?\s*(?:مجاز|فعال)\s*کن$/iu', $cmd, $mMapNames)){
                $fid = (int)$mMapNames[1]; $namesStr = trim((string)$mMapNames[2]);
                if ($fid > 0 && $namesStr !== ''){
                    // Split by Persian/English commas and "و"
                    $parts = preg_split('/\s*(?:,|،|\s+و\s+)\s*/u', $namesStr);
                    $parts = array_values(array_filter(array_map('trim', is_array($parts)?$parts:[]))); // clean
                    $resolved = []; $unresolved = [];
                    foreach ($parts as $nm){
                        $cands = $find_group_candidates($nm, 5);
                        if (count($cands) === 1 && ($cands[0]['score'] ?? 0) >= 0.7){ $resolved[] = (int)$cands[0]['id']; continue; }
                        if (!empty($cands)){
                            // Return clarify for the first unresolved name; UI will loop with remaining later if needed
                            $opts = array_map(function($r){ return [ 'label'=> ((int)$r['id']).' - '.((string)$r['name']).' ('.round((float)$r['score']*100).'%)', 'value'=> (int)$r['id'] ]; }, $cands);
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'clarify',
                                'kind'=>'options',
                                'message'=>'کدام گروه منظور است؟ «'.$nm.'»',
                                'param_key'=>'group_id',
                                'options'=>$opts,
                                'clarify_action'=> [ 'action'=>'ug_set_form_access', 'params'=> ['form_id'=>$fid, 'group_ids'=>$resolved] ]
                            ], 200);
                        }
                        $unresolved[] = $nm;
                    }
                    if (!empty($resolved)){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'دسترسی فرم '.$fid.' برای گروه‌ها ['.implode(', ', $resolved).'] تنظیم شود؟',
                            'confirm_action'=> [ 'action'=>'ug_set_form_access', 'params'=>['form_id'=>$fid, 'group_ids'=>$resolved] ]
                        ], 200);
                    }
                }
            }
            // New Form (natural phrases): e.g., "فرم جدید می خوام", "یک فرم جدید میخوام", "میخوام یک فرم جدید"
            // We treat these as a request to create a new form with a sensible default title.
            if (
                preg_match('/^(?:می\s*خوام|میخوام)\s*(?:یه|یک)?\s*فرم\s*جدید$/u', $cmd)
                || preg_match('/^(?:یه|یک)?\s*فرم\s*جدید\s*(?:می\s*خوام|میخوام)$/u', $cmd)
                || preg_match('/^(?:ایجاد|ساختن|بساز)\s*(?:یه|یک)?\s*فرم\s*جدید$/u', $cmd)
                || preg_match('/^فرم\s*جدید$/u', $cmd)
            ){
                $defTitle = apply_filters('arshline_ai_new_form_default_title', 'فرم جدید');
                return new WP_REST_Response([
                    'ok'=>true,
                    'action'=>'confirm',
                    'message'=>'ایجاد فرم جدید با عنوان «'.(string)$defTitle.'» تایید می‌کنید؟',
                    'confirm_action'=> [ 'action'=>'create_form', 'params'=>['title' => (string)$defTitle] ]
                ], 200);
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
                $list = array_map(function($r){ return [ 'label'=> ((int)($r['id']??0)).' - '.((string)($r['title']??'')), 'value'=> (int)($r['id']??0) ]; }, is_array($rows)?$rows:[]);
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
                    'confirm_action'=>['action'=>'update_form_title','params'=>['id'=>$fid,'title'=>$title]]
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
                if (count($matches) === 1){
                    $m1 = $matches[0];
                    if ($m1['score'] >= $get_auto_confirm_threshold()){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                    }
                    if ($m1['score'] >= 0.6){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
            }
            // Title-first open builder: "{title} رو باز کن/وا کن" (implicit form)
            if (preg_match('/^(.+?)\s*(?:را|رو)\s*(?:باز\s*کن|باز\s*کردن|وا\s*کن)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // Guard: if the requested name is an app tab, prefer opening that tab instead of treating it as a form title
                $raw = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $name); // remove ZWNJ/RLM
                $raw = preg_replace('/\s+/u', ' ', $raw);
                $tabMap = [
                    'داشبورد' => 'dashboard', 'خانه' => 'dashboard',
                    'فرم ها' => 'forms', 'فرم‌ها' => 'forms', 'فرمها' => 'forms', 'فرم' => 'forms',
                    'گزارشات' => 'reports', 'گزارش' => 'reports', 'آمار' => 'reports',
                    'کاربران' => 'users', 'کاربر' => 'users', 'اعضا' => 'users',
                    'تنظیمات' => 'settings', 'تنظیم' => 'settings', 'ستینگ' => 'settings', 'پیکربندی' => 'settings',
                ];
                // Normalize some common variants
                $rawNorm = str_replace(['‌'], ' ', $raw); // Persian half-space to normal space
                $rawNorm = trim($rawNorm);
                if (isset($tabMap[$rawNorm])){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$tabMap[$rawNorm]], 200);
                }
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
            // Results by title: "نتایج [فرم] {title}"
            if (preg_match('/^نتایج\s*(?:فرم)?\s+(.+)$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1 && $matches[0]['score'] >= 0.6){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_results', 'id'=>$matches[0]['id']], 200);
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'نتایج کدام فرم را باز کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_results']], 200);
                }
            }
            // Title-based open builder with form-first order: "فرم {title} رو ادیت/ویرایش کن"
            if (preg_match('/^فرم\s+(.+?)\s*(?:را|رو)?\s*(?:ویرایش|ادیت|edit)(?:\s*کن)?$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1){
                    $m1 = $matches[0];
                    if ($m1['score'] >= $get_auto_confirm_threshold()){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                    }
                    if ($m1['score'] >= 0.6){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را ویرایش کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
            }
            // Title-only to edit (implicit "فرم" omitted): "{title} رو ادیت کن" | "{title} را ویرایش کن"
            if (preg_match('/^(.+?)\s*(?:را|رو)\s*(?:ویرایش|ادیت|edit)(?:\s*کن)?$/iu', $cmd, $m)){
                $name = trim((string)$m[1]);
                // If user meant a known tab name, ignore this rule to avoid confusion
                $knownTabs = ['داشبورد','dashboard','settings','reports','users','فرم‌ها','فرمها','forms'];
                $nl = function(string $s){ return mb_strtolower($s, 'UTF-8'); };
                $nameNL = $nl($name);
                foreach ($knownTabs as $t){ if ($nl($t) === $nameNL){ $name = ''; break; } }
                if ($name !== ''){
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                    if ($num > 0){ return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200); }
                    $matches = $find_by_title($name, 5);
                    if (count($matches) === 1){
                        $m1 = $matches[0];
                        if ($m1['score'] >= $get_auto_confirm_threshold()){
                            return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                        }
                        if ($m1['score'] >= 0.6){
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'confirm',
                                'message'=>'آیا منظورتان ویرایش «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                                'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                            ], 200);
                        }
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
            // Colloquial navigation verbs: "بازش کن", "واکن", "ببر به X", "برو تو X"
            if (preg_match('/^(?:بازش\s*کن|وا\s*کن|واکن|ببر\s*به|برو\s*تو|برو\s*به)\s*(داشبورد|فرم\s*ها|فرم|گزارشات|کاربران|تنظیمات)$/u', $cmd, $m)){
                $raw = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', (string)$m[1]);
                $raw = preg_replace('/\s+/u', ' ', $raw);
                $map = [ 'داشبورد'=>'dashboard', 'فرم ها'=>'forms', 'فرم'=>'forms', 'گزارشات'=>'reports', 'کاربران'=>'users', 'تنظیمات'=>'settings' ];
                $tab = $map[$raw] ?? ($raw === 'فرم ها' || $raw === 'فرم' ? 'forms' : 'dashboard');
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_tab', 'tab'=>$tab], 200);
            }
            // Colloquial: "برو تو فرم <id>" | "برو تو فرم {title}"
            if (preg_match('/^(?:برو\s*تو|برو\s*به|ببر\s*به)\s*فرم\s*(.+)$/u', $cmd, $m)){
                $name = trim((string)$m[1]);
                $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                $num = (int) strtr(preg_replace('/\D+/u','', $name), $fa2en);
                if ($num > 0){
                    return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$num], 200);
                }
                $matches = $find_by_title($name, 5);
                if (count($matches) === 1){
                    $m1 = $matches[0];
                    if ($m1['score'] >= $get_auto_confirm_threshold()){
                        return new WP_REST_Response(['ok'=>true, 'action'=>'open_builder', 'id'=>$m1['id']], 200);
                    }
                    if ($m1['score'] >= 0.6){
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'confirm',
                            'message'=>'آیا منظورتان باز کردن «'.$m1['title'].'» (شناسه '.$m1['id'].') بود؟',
                            'confirm_action'=> [ 'action'=>'open_builder', 'params'=>['id'=>$m1['id']] ]
                        ], 200);
                    }
                }
                if (!empty($matches)){
                    $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                    return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'کدام فرم را باز کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'open_builder']], 200);
                }
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
            // Heuristic colloquial navigation: "منوی فرم‌ها رو باز کن"، "برو به فرم‌ها", "منو تنظیمات" + tolerate "بازش کن" and "واکن"
            {
                $plain = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $cmd); // remove ZWNJ, RLM
                $hasNavVerb = preg_match('/(منو|منوی|باز\s*کن|بازش\s*کن|باز|وا\s*کن|واکن|برو\s*به|برو\s*تو|برو|ببر\s*به|نمایش|نشون\s*بده)/u', $plain) === 1;
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
            // If not matched, try LLM-assisted parsing when configured (hybrid fallback)
            $s = self::get_ai_settings();
            $parserMode = $s['parser'] ?? 'hybrid';
            if ($s['enabled'] && $parserMode === 'hybrid' && $s['base_url'] && $s['api_key']){
                $intent = self::llm_parse_command($cmd, $s, [ 'ui_tab' => $ui_tab, 'ui_route' => $ui_route, 'kb' => $kb ]);
                if (is_array($intent) && isset($intent['action'])){
                    $action = (string)$intent['action'];
                    // Map add_field with title->id when needed
                    if ($action === 'add_field'){
                        $itype = (string)($intent['type'] ?? '');
                        if (!empty($intent['title']) && empty($intent['id'])){
                            $matches = $find_by_title((string)$intent['title'], 5);
                            if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                            elseif (!empty($matches)){
                                $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                                return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>'سوال را به کدام فرم اضافه کنم؟','param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>'add_field','type'=>$itype?:'short_text']], 200);
                            }
                        }
                        if (!empty($intent['id'])){
                            $fid = (int)$intent['id']; $itype = $itype ?: 'short_text';
                            return new WP_REST_Response([
                                'ok'=>true,
                                'action'=>'confirm',
                                'message'=>'یک سوال '+($itype==='short_text'?'پاسخ کوتاه':'از نوع '+$itype)+' به فرم '+$fid+' اضافه شود؟',
                                'confirm_action'=>['action'=>'add_field','params'=>['id'=>$fid,'type'=>$itype]]
                            ], 200);
                        }
                    }
                    // Map title->id if id is missing but title is present
                    if (!empty($intent['title']) && empty($intent['id'])){
                        $matches = $find_by_title((string)$intent['title'], 5);
                        if (count($matches) === 1 && $matches[0]['score'] >= 0.6){ $intent['id'] = $matches[0]['id']; }
                        elseif (!empty($matches)){
                            $opts = array_map(function($r){ return [ 'label'=> $r['id'].' - '.$r['title'], 'value'=> (int)$r['id'] ]; }, $matches);
                            $msg = ($action==='open_results') ? 'نتایج کدام فرم را باز کنم؟' : 'کدام فرم را ویرایش کنم؟';
                            $clarifyTarget = ($action==='open_results') ? 'open_results' : 'open_builder';
                            return new WP_REST_Response(['ok'=>true,'action'=>'clarify','kind'=>'options','message'=>$msg,'param_key'=>'id','options'=>$opts,'clarify_action'=>['action'=>$clarifyTarget]], 200);
                        }
                    }
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
                    // If LLM says unknown, fall through to suggestions
                    if ($action === 'unknown'){ /* handled below */ }
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
            // Provide suggestions instead of bare unknown
            $suggest = [ 'نمونه‌ها' => ['ویرایش فرم 12','فرم مشتریان رو ادیت کن','نتایج فرم 5','لیست فرم‌ها'] ];
            return new WP_REST_Response(['ok'=>false,'error'=>'unknown_command','message'=>'دستور واضح نیست.','suggestions'=>$suggest], 200);
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
            // UG: create group
            if ($action === 'ug_create_group' && !empty($params['name'])){
                $req = new WP_REST_Request('POST', '/arshline/v1/user-groups');
                $body = ['name'=>(string)$params['name']]; if (isset($params['parent_id'])) $body['parent_id'] = (int)$params['parent_id'];
                $req->set_body_params($body);
                $res = self::ug_create_group($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                $gid = (int)($data['id'] ?? 0);
                return new WP_REST_Response(['ok'=> ($gid>0), 'action'=>'open_ug', 'tab'=>'groups', 'group_id'=>$gid, 'undo_token'=>($data['undo_token'] ?? null)], 200);
            }
            // UG: update group
            if ($action === 'ug_update_group' && !empty($params['id'])){
                $gid = (int)$params['id'];
                $req = new WP_REST_Request('PUT', '/arshline/v1/user-groups/'.$gid);
                $req->set_url_params(['group_id'=>$gid]);
                $req->set_body_params(array_diff_key($params, ['id'=>true]));
                $res = self::ug_update_group($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true, 'action'=>'open_ug', 'tab'=>'groups', 'group_id'=>$gid], 200);
            }
            // UG: ensure tokens
            if ($action === 'ug_ensure_tokens' && !empty($params['group_id'])){
                $gid = (int)$params['group_id'];
                $req = new WP_REST_Request('POST', '/arshline/v1/user-groups/'.$gid.'/members/ensure-tokens');
                $req->set_url_params(['group_id'=>$gid]);
                $res = self::ug_bulk_ensure_tokens($req);
                $data = $res instanceof WP_REST_Response ? $res->get_data() : [];
                return new WP_REST_Response(['ok'=> $res instanceof WP_REST_Response, 'action'=>'open_ug', 'tab'=>'members', 'group_id'=>$gid, 'generated'=>(int)($data['generated'] ?? 0)], 200);
            }
            // UG: set form access
            if ($action === 'ug_set_form_access' && !empty($params['form_id']) && is_array($params['group_ids'] ?? null)){
                $fid = (int)$params['form_id']; $gids = array_values(array_map('intval', (array)$params['group_ids']));
                $req = new WP_REST_Request('PUT', '/arshline/v1/forms/'.$fid.'/access/groups');
                $req->set_url_params(['form_id'=>$fid]);
                $req->set_body_params(['group_ids'=>$gids]);
                $res = self::set_form_access_groups($req);
                return $res instanceof WP_REST_Response ? $res : new WP_REST_Response(['ok'=>true], 200);
            }
            // UG: export links (download URL)
            if ($action === 'ug_export_links' && (!empty($params['group_id']) || !empty($params['form_id']))){
                $gid = isset($params['group_id']) ? (int)$params['group_id'] : 0;
                $fid = isset($params['form_id']) ? (int)$params['form_id'] : 0;
                $params2 = []; if ($gid>0) $params2['group_id']=$gid; if ($fid>0) $params2['form_id']=$fid;
                $url = add_query_arg(array_merge(['action'=>'arshline_export_group_links', '_wpnonce'=>wp_create_nonce('arshline_export_group_links')], $params2), admin_url('admin-post.php'));
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            // UG: download members template
            if ($action === 'ug_download_members_template' && !empty($params['group_id'])){
                $gid = (int)$params['group_id'];
                $url = add_query_arg(['action'=>'arshline_download_members_template', '_wpnonce'=>wp_create_nonce('arshline_download_members_template'), 'group_id'=>$gid], admin_url('admin-post.php'));
                return new WP_REST_Response(['ok'=>true, 'action'=>'download', 'format'=>'csv', 'url'=>$url], 200);
            }
            if ($action === 'add_field' && !empty($params['id'])){
                $fid = (int)$params['id'];
                $type = isset($params['type']) ? (string)$params['type'] : 'short_text';
                // Load current fields and snapshot for audit
                $before = FieldRepository::listByForm($fid);
                $fields = $before;
                // Compute insert index (append at end)
                $insertAt = count($fields);
                // Default props for short_text (server-side mirror of tool-defaults)
                $defaults = [ 'type'=>'short_text', 'label'=>'پاسخ کوتاه', 'format'=>'free_text', 'required'=>false, 'show_description'=>false, 'description'=>'', 'placeholder'=>'', 'question'=>'', 'numbered'=>true ];
                if ($type !== 'short_text') { $defaults['type'] = $type; }
                $fields[] = [ 'props' => $defaults ];
                FieldRepository::replaceAll($fid, $fields);
                $after = FieldRepository::listByForm($fid);
                $undo = Audit::log('update_form_fields', 'form', $fid, ['fields'=>$before], ['fields'=>$after]);
                // Determine new index by comparing lengths
                $newIndex = max(0, count($after) - 1);
                return new WP_REST_Response(['ok'=>true, 'action'=>'open_editor', 'id'=>$fid, 'index'=>$newIndex, 'undo_token'=>$undo], 200);
            }
            if ($action === 'create_form' && !empty($params['title'])){
                $req = new WP_REST_Request('POST', '/arshline/v1/forms');
                $req->set_body_params(['title'=>(string)$params['title']]);
                $res = self::create_form($req);
                if ($res instanceof WP_REST_Response){
                    $data = $res->get_data();
                    if (is_array($data) && !empty($data['id'])){
                        // Navigate to builder for the new form and surface undo token
                        return new WP_REST_Response([
                            'ok'=>true,
                            'action'=>'open_builder',
                            'id'=>(int)$data['id'],
                            'undo_token'=> ($data['undo_token'] ?? '')
                        ], 200);
                    }
                    return $res;
                }
                return new WP_REST_Response(['ok'=>true], 200);
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
    protected static function llm_parse_command(string $cmd, array $s, array $ctx = [])
    {
        try {
            $base = rtrim((string)$s['base_url'], '/');
            $model = (string)($s['model'] ?? 'gpt-4o');
            $url = $base . '/v1/chat/completions';
          $sys = 'You are a deterministic command parser for the Arshline dashboard. '
              . 'Your ONLY job is to convert Persian admin commands into a single strict JSON object. '
              . 'Do NOT chat, do NOT add explanations, do NOT ask follow-up questions. Output JSON ONLY. '
              . 'Schema: '
              . '{"action":"create_form|delete_form|public_link|list_forms|open_builder|open_editor|open_tab|open_results|export_csv|help|set_setting|ui|open_form|close_form|draft_form|update_form_title|add_field","title?":string,"id?":number,"index?":number,"tab?":"dashboard|forms|reports|users|settings","section?":"security|ai|users","key?":"ai_enabled|min_submit_seconds|rate_limit_per_min|block_svg|ai_model","value?":(string|number|boolean),"target?":"toggle_theme|open_ai_terminal|undo|go_back","type?":"short_text|long_text|multiple_choice|dropdown|rating","params?":object}. '
              . 'Examples: '
              . '"ایجاد فرم با عنوان فرم تست" => {"action":"create_form","title":"فرم تست"}. '
              . '"حذف فرم 12" => {"action":"delete_form","id":12}. '
              . '"لینک عمومی فرم 7" => {"action":"public_link","id":7}. '
              . '"لیست فرم ها" => {"action":"list_forms"}. '
              . '"باز کردن فرم 9" => {"action":"open_builder","id":9}. '
              . '"فرم مشتریان رو ادیت کن" => {"action":"open_builder","title":"فرم مشتریان"}. '
              . '"آزمایش جدید رو باز کن" => {"action":"open_builder","title":"آزمایش جدید"}. '
              . '"نتایج فرم مشتریان" => {"action":"open_results","title":"فرم مشتریان"}. '
              . '"باز کردن تنظیمات" => {"action":"open_tab","tab":"settings"}. '
              . '"خروجی فرم 5" => {"action":"export_csv","id":5}. '
              . '"کمک" => {"action":"help"}. '
              . '"مدل را روی gpt-4o بگذار" => {"action":"set_setting","key":"ai_model","value":"gpt-4o"}. '
              . '"حالت تاریک را فعال کن" => {"action":"ui","target":"toggle_theme","params":{"mode":"dark"}}. '
              . '"فعال کن فرم 3" => {"action":"open_form","id":3}. '
              . '"غیرفعال کن فرم 8" => {"action":"close_form","id":8}. '
              . '"پیش‌نویس کن فرم 4" => {"action":"draft_form","id":4}. '
              . '"عنوان فرم 2 را به فرم مشتریان تغییر بده" => {"action":"update_form_title","id":2,"title":"فرم مشتریان"}. '
              . '"یک سوال پاسخ کوتاه در فرم 5 اضافه کن" => {"action":"add_field","id":5,"type":"short_text"}. '
              . 'Context: you may use the following current UI hints to disambiguate. '
              . 'UI Tab: ' . (!empty($ctx['ui_tab']) ? $ctx['ui_tab'] : 'unknown') . '. '
              . 'UI Route: ' . (!empty($ctx['ui_route']) ? $ctx['ui_route'] : '') . '. '
              . 'Capabilities shortlist (IDs + labels; choose closest): ' . wp_json_encode($ctx['kb'] ?? []) . '. '
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

    /**
     * Use an OpenAI-compatible endpoint to convert a natural-language command into a multi-step plan.
     * Returns array { version:1, steps:[{action,params}] } or null when not a clear multi-step.
     * Only emits actions supported by PlanValidator. Steps that require an id must include a numeric id
     * extracted from the user's command; if an id can't be determined, do NOT generate a plan for that step.
     * For add_field immediately after create_form, omit the id to refer to the last created form.
     */
    protected static function llm_parse_plan(string $cmd, array $s, array $ctx = [])
    {
        try {
            $base = rtrim((string)$s['base_url'], '/');
            $model = (string)($s['model'] ?? 'gpt-4o');
            $url = $base . '/v1/chat/completions';
            $sys = 'You are a deterministic planner for Arshline admin. Output ONLY a strict JSON object with no prose. '
                . 'When the user request implies multiple sequential actions, produce a plan JSON of the form: '
                . '{"version":1,"steps":[{"action":"create_form|add_field|update_form_title|open_builder|open_editor|open_results|publish_form|draft_form","params":{...}}, ...]}. '
                . 'Rules: '
                . '1) Allowed actions: create_form, add_field, update_form_title, open_builder, open_editor, open_results, publish_form, draft_form. '
                . '2) For create_form: params: {"title": string (default to "فرم جدید" if missing)}. '
                . '3) For add_field: params: {"id"?: number, "type": "short_text|long_text|multiple_choice|dropdown|rating", "question"?: string, "required"?: boolean, "index"?: number}. '
                . '   If add_field immediately follows create_form, omit id to refer to the last created form. Otherwise include id only if a numeric id is explicitly present in the user command. Do NOT guess ids. '
                . '4) For open_builder/open_results: include numeric "id" ONLY if explicitly provided in the command; otherwise do not produce a plan. '
                . '5) For open_editor: include numeric "id" and 0-based "index" only if explicitly provided; otherwise do not produce a plan. '
                . '6) For publish_form/draft_form/update_form_title: include numeric "id" only if explicitly provided in the command. '
                . '7) If the request is single-step or unclear to produce a valid multi-step plan, reply {"none":true} instead. '
                . '8) Never include unknown keys, never include title references for existing forms except for create_form title or update_form_title title. '
                . 'Context: UI Tab: ' . (!empty($ctx['ui_tab']) ? $ctx['ui_tab'] : 'unknown') . '; UI Route: ' . (!empty($ctx['ui_route']) ? $ctx['ui_route'] : '') . '.';
            $examples = [
                // Persian examples to guide the planner
                '"یک فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن" => {"version":1,"steps":[{"action":"create_form","params":{"title":"فرم جدید"}},{"action":"add_field","params":{"type":"short_text"}},{"action":"add_field","params":{"type":"short_text"}}]}',
                '"یک فرم با عنوان دریافت بازخورد بساز و یک سوال امتیازدهی اضافه کن" => {"version":1,"steps":[{"action":"create_form","params":{"title":"دریافت بازخورد"}},{"action":"add_field","params":{"type":"rating"}}]}',
                '"عنوان فرم 3 را به فرم مشتریان تغییر بده" => {"version":1,"steps":[{"action":"update_form_title","params":{"id":3,"title":"فرم مشتریان"}}]}',
                '"نتایج فرم 5 را باز کن" => {"none":true}',
            ];
            $sys .= ' Examples: ' . implode(' ', $examples) . ' If not multi-step or id is missing for required steps, output {"none":true}.';
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
            if (!is_array($parsed)) return null;
            if (!empty($parsed['none'])) return null;
            // Minimal normalization
            $ver = isset($parsed['version']) ? (int)$parsed['version'] : 0;
            $steps = is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [];
            if ($ver !== 1 || empty($steps)) return null;
            // Strip any unknown keys in steps params defensively
            $allowedActions = ['create_form','add_field','update_form_title','open_builder','open_editor','open_results','publish_form','draft_form'];
            $outSteps = [];
            foreach ($steps as $s1){
                if (!is_array($s1)) continue;
                $a = isset($s1['action']) ? (string)$s1['action'] : '';
                if (!in_array($a, $allowedActions, true)) continue;
                $p = is_array($s1['params'] ?? null) ? $s1['params'] : [];
                // keep only known params per action
                if ($a === 'create_form'){
                    $title = isset($p['title']) && is_scalar($p['title']) ? (string)$p['title'] : '';
                    $outSteps[] = [ 'action'=>'create_form', 'params'=> [ 'title' => $title !== '' ? $title : apply_filters('arshline_ai_new_form_default_title', 'فرم جدید') ] ];
                } elseif ($a === 'add_field'){
                    $params = [];
                    if (isset($p['id']) && is_numeric($p['id'])){ $params['id'] = (int)$p['id']; }
                    $type = isset($p['type']) && is_scalar($p['type']) ? (string)$p['type'] : 'short_text';
                    $params['type'] = $type;
                    if (isset($p['question']) && is_scalar($p['question'])){ $params['question'] = (string)$p['question']; }
                    if (isset($p['required'])){ $params['required'] = (bool)$p['required']; }
                    if (isset($p['index']) && is_numeric($p['index'])){ $params['index'] = (int)$p['index']; }
                    $outSteps[] = [ 'action'=>'add_field', 'params'=>$params ];
                } elseif ($a === 'update_form_title'){
                    if (isset($p['id']) && is_numeric($p['id']) && isset($p['title']) && is_scalar($p['title'])){
                        $outSteps[] = [ 'action'=>'update_form_title', 'params'=> [ 'id'=>(int)$p['id'], 'title'=>(string)$p['title'] ] ];
                    }
                } elseif ($a === 'open_builder' || $a === 'open_results'){
                    if (isset($p['id']) && is_numeric($p['id'])){ $outSteps[] = [ 'action'=>$a, 'params'=> [ 'id'=>(int)$p['id'] ] ]; }
                } elseif ($a === 'open_editor'){
                    if (isset($p['id']) && is_numeric($p['id']) && isset($p['index']) && is_numeric($p['index'])){
                        $outSteps[] = [ 'action'=>'open_editor', 'params'=> [ 'id'=>(int)$p['id'], 'index'=>(int)$p['index'] ] ];
                    }
                } elseif ($a === 'publish_form' || $a === 'draft_form'){
                    if (isset($p['id']) && is_numeric($p['id'])){ $outSteps[] = [ 'action'=>$a, 'params'=> [ 'id'=>(int)$p['id'] ] ]; }
                }
            }
            if (empty($outSteps)) return null;
            return [ 'version' => 1, 'steps' => $outSteps ];
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Internal heuristic multi-step plan builder for common Persian phrases.
     * Targets commands like:
     *  - "یک فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن، اسم فرم را چکاپ سه بگذار"
     *  - "فرم جدید بساز، اسمش را \"فرم چکاپ 3\" بگذار و دو سوال کوتاه اضافه کن"
     *  - "یک فرم جدید با عنوان X بساز و دو سوال کوتاه اضافه کن"
     * Returns plan {version:1, steps:[...]} or null if not applicable.
     */
    protected static function internal_parse_plan(string $cmd)
    {
        $plain = trim($cmd);
        if ($plain === '') return null;
    // Normalize Arabic/Persian control chars and punctuation/whitespace
    $sep = str_replace(["\xE2\x80\x8C","\xE2\x80\x8F"], ' ', $plain); // ZWNJ, RLM
    $sep = preg_replace('/\s+/u', ' ', $sep);
    $sep = preg_replace('/[،,]+/u', '،', $sep);
    $sep = trim($sep);
        // Detect intent to create a new form — tolerate interleaving "با عنوان ..." before the verb
        $hasCreate =
            // "فرم جدید ... بساز/ایجاد کن"
            preg_match('/فرم\s*جدید(?:\s*با\s*(?:عنوان|نام)\s*.+?)?\s*(?:را|رو)?\s*(?:بساز|ایجاد\s*کن)/u', $sep) === 1
            // "یک/یه فرم جدید ... بساز/ایجاد کن"
            || preg_match('/(?:یه|یک)\s*فرم\s*جدید(?:\s*با\s*(?:عنوان|نام)\s*.+?)?\s*(?:را|رو)?\s*(?:بساز|ایجاد\s*کن)/u', $sep) === 1
            // Verb-first
            || preg_match('/(?:ایجاد|ساختن|بساز)\s*(?:یه|یک)?\s*فرم\s*جدید/u', $sep) === 1
            // Minimal: just "فرم جدید" anywhere
            || preg_match('/فرم\s*جدید/u', $sep) === 1
            // Explicit: "ایجاد فرم با عنوان X"
            || preg_match('/ایجاد\s*فرم\s*با\s*(?:عنوان|نام)/u', $sep) === 1;
        if (!$hasCreate) return null;
        // Extract a title if present: patterns like "اسم(ش| فرم) را X بگذار/بذار" or "با عنوان X"
        $title = '';
        if (preg_match('/(?:اسم(?:\s*فرم)?|عنوان(?:\s*فرم)?|نام(?:\s*فرم)?)\s*(?:را|رو)?\s*(.+?)\s*(?:بگذار|بذار|قرار\s*ده|کن)/u', $sep, $m)){
            $title = trim((string)$m[1]);
        } elseif (preg_match('/با\s*(?:عنوان|نام)\s*(.+?)(?=\s*(?:بساز|ایجاد\s*کن)|[،,]|$)/u', $sep, $m)){
            $title = trim((string)$m[1]);
        }
        // Clean wrapping quotes/half-space and cut trailing verbs/noise if any leaked in
        $title = trim($title, " \"'\x{200C}\x{200F}");
        if ($title !== ''){
            // Stop at first occurrence of verbs or separators that indicate end of title
            $title = preg_replace('/\s*(?:بساز|ایجاد\s*کن|اضافه\s*کن|سوال|پرسش)(.|\n)*$/u', '', $title);
            $title = trim($title);
        }
        // Extract an explicit question text if provided, e.g., "متن سوال این باشه: X" or "سوال این باشه: X"
        $question = '';
        if (preg_match('/(?:متن\s*(?:سوال|پرسش)|(?:سوال|پرسش)\s*متن|(?:سوال|پرسش))\s*(?:این\s*باشه|باشد|باشه)?\s*[:\-]?\s*"?(.+?)"?(?=[،,]|$)/u', $sep, $mq)){
            $question = trim((string)$mq[1], " \"'\x{200C}\x{200F}");
        }
        if ($title === ''){
            $title = apply_filters('arshline_ai_new_form_default_title', 'فرم جدید');
        }
        // Count how many questions to add; support Persian digits and words for 1..5
        $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
        $count = 0;
        // Numeric like "دو سوال" or explicit digits
        if (preg_match('/([0-9۰-۹]+)\s*(?:تا\s*)?\s*(?:سوال|پرسش)/u', $sep, $mm)){
            $n = (int) strtr($mm[1], $fa2en);
            if ($n > 0 && $n <= 20) $count = $n;
        } else {
            $mapWords = [ 'یک'=>1, 'یه'=>1, 'دو'=>2, 'سه'=>3, 'چهار'=>4, 'پنج'=>5 ];
            foreach ($mapWords as $w=>$n){ if (preg_match('/\b'.$w.'\s*(?:تا\s*)?\s*(?:سوال|پرسش)/u', $sep)){ $count = $n; break; } }
        }
        // Recognize type: default short_text; allow long_text, rating
        $type = 'short_text';
        if (preg_match('/\b(پاسخ\s*کوتاه|کوتاه|short[_\s-]*text)\b/iu', $sep)) $type = 'short_text';
        elseif (preg_match('/\b(پاسخ\s*بلند|متن\s*بلند|long[_\s-]*text)\b/iu', $sep)) $type = 'long_text';
        elseif (preg_match('/\b(امتیاز|ستاره|rating)\b/iu', $sep)) $type = 'rating';
        // Build the steps. Always include create_form even if no fields requested.
        $steps = [ [ 'action'=>'create_form', 'params'=> [ 'title' => $title ] ] ];
        $maxN = max(1, min(12, (int) apply_filters('arshline_ai_plan_internal_max_fields', 6)));
        $n = min(max(0, $count), $maxN);
        for ($i=0; $i<$n; $i++){
            $p = [ 'type' => $type ];
            if ($i === 0 && $question !== ''){ $p['question'] = mb_substr($question, 0, 200); }
            $steps[] = [ 'action' => 'add_field', 'params' => $p ];
        }
        return [ 'version' => 1, 'steps' => $steps ];
    }

    public static function get_form_by_token(WP_REST_Request $request)
    {
        $token = (string)$request['token'];
        $form = FormRepository::findByToken($token);
        if (!$form) return new WP_REST_Response(['error'=>'not_found'], 404);
        if ($form->status !== 'published'){
            return new WP_REST_Response(['error'=>'forbidden','message'=>'form_not_published'], 403);
        }
        // When accessing by public token, still enforce group mapping if present (require member token or user membership)
        list($ok, $member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
        $fields = FieldRepository::listByForm($form->id);
        // Minimal personalization via GET params for title/description
        $meta = $form->meta;
        $params = $request->get_params();
        $repl = [];
        foreach ($params as $k=>$v){ if (is_string($v) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,31}$/', (string)$k)) { $repl[$k] = $v; } }
        $replaceIn = function($s) use ($repl){ if (!is_string($s) || $s==='') return $s; return preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)/', function($m) use ($repl){ $k=$m[1]; return array_key_exists($k,$repl) ? (string)$repl[$k] : $m[0]; }, $s); };
        if (isset($meta['title'])) $meta['title'] = $replaceIn($meta['title']);
        if (isset($meta['description'])) $meta['description'] = $replaceIn($meta['description']);
        $meta = self::hydrate_meta_with_member($meta, $member);
        return new WP_REST_Response([
            'id' => $form->id,
            'token' => $form->public_token,
            'status' => $form->status,
            'meta' => $meta,
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
        // Enforce group-based access for submissions as well
        list($ok, $member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
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
        // Optionally include member context into submission meta for later personalization
        if (is_array($member)){
            $submissionData['meta']['member'] = [
                'id' => (int)$member['id'],
                'group_id' => (int)$member['group_id'],
                'name' => (string)$member['name'],
                'phone' => (string)$member['phone'],
            ];
        }
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
        // Enforce group-based access (member token or logged-in membership)
        list($ok, $_member) = self::enforce_form_group_access($form->id, $request);
    if (!$ok){ return new WP_REST_Response(['error'=>'forbidden','message'=>'دسترسی مجاز نیست.'], 403); }
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
        // Enforce access
        list($ok, $_member) = self::enforce_form_group_access($form->id, $request);
        if (!$ok){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">دسترسی مجاز نیست.</div>', 200); }
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
        // Enforce access
        list($ok, $_member) = self::enforce_form_group_access($form->id, $request);
        if (!$ok){ return new WP_REST_Response('<div class="ar-alert ar-alert--err">دسترسی مجاز نیست.</div>', 200); }
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
                if ($action === 'update_form_fields'){
                    $fid = (int)($row['target_id'] ?? 0);
                    if ($fid > 0){
                        $prevFields = is_array($before['fields'] ?? null) ? $before['fields'] : [];
                        FieldRepository::replaceAll($fid, $prevFields);
                        Audit::markUndone($token);
                        return new WP_REST_Response(['ok'=>true, 'restored_fields'=>true], 200);
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
     * GET /analytics/config — current AI settings relevant to analytics (هوشنگ)
     */
    public static function get_analytics_config(WP_REST_Request $request)
    {
        $gs = self::get_global_settings();
        $base = (string)($gs['ai_base_url'] ?? '');
    $model = (string)($gs['ai_model'] ?? 'gpt-4o');
        $enabled = !empty($gs['ai_enabled']);
        return new WP_REST_Response(['enabled'=>$enabled,'base_url'=>$base,'model'=>$model], 200);
    }

    /**
     * POST /analytics/analyze — multi-form analysis with chunking; logs token usage
     */
    public static function analytics_analyze(WP_REST_Request $request)
    {
        $p = $request->get_json_params(); if (!is_array($p)) $p = $request->get_params();
    $form_ids = array_values(array_filter(array_map('intval', (array)($p['form_ids'] ?? [])), function($v){ return $v>0; }));
        if (empty($form_ids)) return new WP_REST_Response([ 'error' => 'form_ids_required' ], 400);
    // Persona requires using only one selected form; restrict to the first
    if (count($form_ids) > 1) { $form_ids = [ $form_ids[0] ]; }
        $question = is_scalar($p['question'] ?? null) ? trim((string)$p['question']) : '';
        if ($question === '') return new WP_REST_Response([ 'error' => 'question_required' ], 400);
        $session_id = isset($p['session_id']) ? max(0, (int)$p['session_id']) : 0;
    $max_rows = isset($p['max_rows']) && is_numeric($p['max_rows']) ? max(50, min(10000, (int)$p['max_rows'])) : 2000;
    $chunk_size = null; // compute after loading config
    $model = is_scalar($p['model'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9_\-:\.\/]/', '', (string)$p['model']), 0, 100) : '';
    // Respect per-site analytics default (configurable), allow request override; clamp to 4096
    $max_tokens = null; // computed after loading AI settings below
    $voice = is_scalar($p['voice'] ?? null) ? substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['voice']), 0, 50) : '';
    $format = is_scalar($p['format'] ?? null) ? strtolower(substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['format']), 0, 20)) : '';
    $mode = is_scalar($p['mode'] ?? null) ? strtolower(substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['mode']), 0, 20)) : '';
    $phase = is_scalar($p['phase'] ?? null) ? strtolower(substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$p['phase']), 0, 20)) : '';
    $chunk_index = isset($p['chunk_index']) && is_numeric($p['chunk_index']) ? max(1, (int)$p['chunk_index']) : 1;
        $structuredParam = isset($p['structured']) ? (bool)$p['structured'] : null;
        // Optional chat history: array of {role:'user'|'assistant'|'system', content:string}
        $history = [];
        if (isset($p['history']) && is_array($p['history'])){
            foreach ($p['history'] as $h){
                if (!is_array($h)) continue;
                $role = (string)($h['role'] ?? ''); $content = (string)($h['content'] ?? '');
                if ($content === '') continue;
                if (!in_array($role, ['user','assistant','system'], true)) $role = 'user';
                $history[] = [ 'role'=>$role, 'content'=>$content ];
            }
        }

        // Load AI config
        $cur = get_option('arshline_settings', []);
        $base = is_scalar($cur['ai_base_url'] ?? null) ? trim((string)$cur['ai_base_url']) : '';
        $api_key = is_scalar($cur['ai_api_key'] ?? null) ? (string)$cur['ai_api_key'] : '';
        $enabled = !empty($cur['ai_enabled']);
        // Prefer Hoshang-specific model if set; else fall back to global ai_model
        $default_model = is_scalar($cur['ai_hosh_model'] ?? null) && (string)$cur['ai_hosh_model'] !== ''
            ? (string)$cur['ai_hosh_model']
            : ( (is_scalar($cur['ai_model'] ?? null) ? (string)$cur['ai_model'] : 'gpt-4o') );
        if (!$enabled || $base === '' || $api_key === ''){
            return new WP_REST_Response([ 'error' => 'ai_disabled' ], 400);
        }
        // Resolve default max_tokens from config (ai_ana_max_tokens), then honor request override
    $cfgMaxTok = isset($cur['ai_ana_max_tokens']) && is_numeric($cur['ai_ana_max_tokens']) ? max(16, min(4096, (int)$cur['ai_ana_max_tokens'])) : 1200;
    $cfgChunkSize = isset($cur['ai_ana_chunk_size']) && is_numeric($cur['ai_ana_chunk_size']) ? max(50, min(2000, (int)$cur['ai_ana_chunk_size'])) : 800;
        $reqMaxTok = isset($p['max_tokens']) && is_numeric($p['max_tokens']) ? (int)$p['max_tokens'] : 0;
        $max_tokens = $reqMaxTok > 0 ? max(16, min(4096, $reqMaxTok)) : $cfgMaxTok;
    // Resolve chunk_size from request override or site default
    $reqChunk = isset($p['chunk_size']) && is_numeric($p['chunk_size']) ? (int)$p['chunk_size'] : 0;
    $chunk_size = $reqChunk > 0 ? max(50, min(2000, $reqChunk)) : $cfgChunkSize;
        $use_model = $model !== '' ? $model : $default_model;
        // LLM-only mode: disable any local structural shortcuts regardless of params/options/filters.
    $allowStructural = false;
    // New: structured JSON mode (config-aware) + auto-format routing
    $hoshMode = is_scalar($cur['ai_hosh_mode'] ?? null) ? (string)$cur['ai_hosh_mode'] : 'hybrid';
    $hoshMode = in_array($hoshMode, ['llm','structured','hybrid'], true) ? $hoshMode : 'hybrid';
    $autoFormat = isset($cur['ai_ana_auto_format']) ? (bool)$cur['ai_ana_auto_format'] : true;
    $clientWantsStructured = ($structuredParam === true) || ($mode === 'structured') || ($format === 'json');
    // Base routing: honor hard setting first; in hybrid, honor explicit client request even if auto-format is enabled
    if ($hoshMode === 'structured') { $isStructured = true; }
    elseif ($hoshMode === 'llm') { $isStructured = false; }
    else /* hybrid */ { $isStructured = $clientWantsStructured ? true : false; }
    $autoStructured = false; $structTrigger = '';

        // Always delegate answers to the model (no local greeting or canned responses)
    $ql = mb_strtolower($question, 'UTF-8');
    // Derive requested output format hint from question
    $isTableOut = (bool)(preg_match('/\btable\b/i', $ql) || preg_match('/جدول/u', $ql));
    $isListOut  = (bool)(preg_match('/\blist\b|bullet|bulleted/i', $ql) || preg_match('/(?:فهرست|لیست|بولت|نقطه(?:‌|)ای)/u', $ql));
    $out_format = $isTableOut ? 'table' : ($isListOut ? 'list' : 'plain');
    // Detect greeting/ambiguous openers (to avoid dumping data on "سلام")
    $isGreeting = (bool)preg_match('/^(?:\s*(?:سلام|درود|hi|hello|hey)\s*[!،,.]?)$/ui', trim($question));

        // Hybrid auto-switch: when auto-format is enabled and question looks heavy/analytical, use structured automatically
        if (!$isStructured && $hoshMode === 'hybrid' && $autoFormat){
            $isHeavyQ = (bool)(
                preg_match('/\b(compare|correlat|trend|distribution|variance|std|median|quartile|regression|cluster|segment|chart|bar|pie|line)\b/i', $ql)
                || preg_match('/(?:مقایسه|همبستگی|روند|میانگین|میانه|نمودار|نمودار(?:\s*میله|\s*دایره|\s*خط)|واریانس|انحراف\s*معیار)/u', $ql)
            );
            if ($isHeavyQ || ($out_format === 'table' && preg_match('/(?:نمودار|chart|trend|روند|compare|مقایسه)/ui', $ql))) {
                $isStructured = true; $autoStructured = true; $structTrigger = $isHeavyQ ? 'heavy-query' : 'tabular-intent';
            }
        }

        // Ensure chat session exists and store the user message immediately
        try {
            global $wpdb; $uid = get_current_user_id();
            $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $tblMsg  = \Arshline\Support\Helpers::tableName('ai_chat_messages');
            if ($session_id <= 0){
                $wpdb->insert($tblSess, [
                    'user_id' => $uid ?: null,
                    'title' => mb_substr($question, 0, 190),
                    'meta' => wp_json_encode([ 'form_ids'=>$form_ids ], JSON_UNESCAPED_UNICODE),
                    'last_message_at' => current_time('mysql'),
                ]);
                $session_id = (int)$wpdb->insert_id;
            }
            // record user turn
            if ($session_id > 0){
                $wpdb->insert($tblMsg, [
                    'session_id' => $session_id,
                    'role' => 'user',
                    'content' => $question,
                    'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>$format, 'mode'=>'llm' ], JSON_UNESCAPED_UNICODE),
                ]);
                // touch session
                $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
            }
        } catch (\Throwable $e) { /* ignore persistence errors */ }

        // Collect data rows per form, capped by max_rows total, and include minimal fields meta for grounding
        $total_rows = 0; $tables = [];
        foreach ($form_ids as $fid){
            $remaining = max(0, $max_rows - $total_rows); if ($remaining <= 0) break;
            $rows = SubmissionRepository::listByFormAll($fid, [], min($remaining, $chunk_size));
            $total_rows += count($rows);
            // fields_meta: id, label, type (from props)
            $fmeta = [];
            try {
                $fieldsForMeta = FieldRepository::listByForm($fid);
                foreach (($fieldsForMeta ?: []) as $f){
                    $p = is_array($f['props'] ?? null) ? $f['props'] : [];
                    // Prefer common builder keys for question/label; fall back through sensible aliases
                    $label = (string)($p['question'] ?? $p['label'] ?? $p['title'] ?? $p['name'] ?? '');
                    $type = (string)($p['type'] ?? '');
                    $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label, 'type' => $type ];
                }
            } catch (\Throwable $e) { /* ignore meta errors */ }
            $tables[] = [ 'form_id' => $fid, 'rows' => $rows, 'fields_meta' => $fmeta ];
        }
        if ($total_rows === 0){
            return new WP_REST_Response([ 'summary' => 'داده‌ای برای تحلیل یافت نشد.', 'chunks' => [], 'usage' => [] ], 200);
        }

    // Quick structural intent: answer "how many items/questions/fields" without LLM
        $isCountIntent = (bool) (
            // English variants
            preg_match('/\bhow\s+many\s+(?:questions?|items?|fields?)\b/i', $ql)
            || preg_match('/\bcount\s+(?:questions?|items?|fields?)\b/i', $ql)
            || preg_match('/\bnumber\s+of\s+(?:questions?|items?|fields?)\b/i', $ql)
            // Persian variants: "چند" / "چند تا" / "تعداد" + noun
            || preg_match('/(?:(?:چند\s*تا|چند|تعداد)\s*(?:سوال|سؤال|آیتم|گزینه|فیلد)s?)/u', $ql)
        );
        if ($allowStructural && $isCountIntent){
            $supported = [ 'short_text'=>1,'long_text'=>1,'multiple_choice'=>1,'dropdown'=>1,'rating'=>1 ];
            $lines = [];
            foreach ($form_ids as $fid){
                try {
                    $fields = FieldRepository::listByForm($fid);
                    $cnt = 0;
                    foreach (($fields ?: []) as $f){
                        $p = isset($f['props']) && is_array($f['props']) ? $f['props'] : [];
                        $type = (string)($p['type'] ?? '');
                        if (isset($supported[$type])) $cnt++;
                    }
                    $lines[] = "فرم " . $fid . ": " . $cnt . " آیتم";
                } catch (\Throwable $e) { $lines[] = "فرم " . $fid . ": نامشخص"; }
            }
            $sum = implode("\n", $lines);
            return new WP_REST_Response([ 'summary' => $sum, 'chunks' => [], 'usage' => [], 'voice' => $voice ], 200);
        }

        // Quick structural intent: count submissions ("چند نفر فرم رو پر کردند؟", "تعداد ارسال‌ها")
        $isSubmitCountIntent = (bool)(
            preg_match('/\bhow\s+many\s+(?:submissions|responses|entries)\b/i', $ql)
            || preg_match('/\b(count|number)\s+of\s+(?:submissions|responses|entries)\b/i', $ql)
            || preg_match('/(?:چند\s*نفر|تعداد)\s*(?:فرم|ارسال|پاسخ|ورودی)/u', $ql)
        );
        if ($allowStructural && $isSubmitCountIntent){
            $lines = [];
            foreach ($form_ids as $fid){
                try {
                    $all = SubmissionRepository::listByFormAll($fid, [], 1_000_000);
                    $lines[] = "فرم " . $fid . ": " . count($all) . " ارسال";
                } catch (\Throwable $e) { $lines[] = "فرم " . $fid . ": نامشخص"; }
            }
            $sum = implode("\n", $lines);
            return new WP_REST_Response([ 'summary' => $sum, 'chunks' => [], 'usage' => [], 'voice' => $voice ], 200);
        }

        // Quick structural intent: list names of submitters (detect name-like fields and list their values)
        $isNamesIntent = (bool) (
            preg_match('/(?:لیست|فهرست)?\s*(?:اسامی|اسم|نام)/u', $ql)
            || preg_match('/\bnames?\b/i', $ql)
        );
        // Avoid misclassifying queries like "اسم میوه" as a person-name intent
        if ($isNamesIntent) {
            if (preg_match('/(?:^|\s)اسم\s+([\p{L}\s‌]+)/u', $ql, $m)){
                $tok = trim($m[1]);
                if (preg_match('/^(?:میوه|ایمیل|شماره|تلفن|کد\s*ملی|سوال|سؤال|فرم)\b/u', $tok)){
                    $isNamesIntent = false;
                }
            }
        }
        // Help the model by using table format for intents that benefit from tabular grounding
        $isFieldsIntent = (bool)(
            preg_match('/\b(fields?|questions?)\b/i', $ql) || preg_match('/فیلد(?:های)?\s*فرم/u', $ql)
        );
        $isShowAllIntent = (bool)(
            preg_match('/\b(all\s+data|show\s+all|dump)\b/i', $ql)
            || preg_match('/تمام\s*اطلاعات|همه\s*داده|لیست\s*اطلاعات(?:\s*فرم)?|خلاصه\s*اطلاعات(?:\s*فرم)?/u', $ql)
        );
        $isAnswersIntent = (bool)(preg_match('/پاسخ(?:‌|\s*)ها|جواب(?:‌|\s*)ها|نتایج|ارسال(?:‌|\s*)ها/u', $ql));
        // Extract a simple field hint from phrases like "لیست X ها" or "اسم|نام X"
        $field_hint = '';
        if (preg_match('/(?:لیست|فهرست)\s+([\p{L}\s‌]+?)(?:\s*ها|\s*های)?\b/u', $ql, $mm)){
            $field_hint = trim($mm[1]);
        }
        if ($field_hint === '' && preg_match('/(?:اسم|نام)\s+([\p{L}\s‌]+)$/u', $ql, $mm2)){
            $field_hint = trim($mm2[1]);
        }
        if ($format !== 'table' && ($isFieldsIntent || $isShowAllIntent || $isAnswersIntent || $isNamesIntent || $out_format==='table')) {
            $format = 'table';
        }
    if ($allowStructural && $isNamesIntent){
            $lines = [];
            foreach ($tables as $t){
                $fid = (int)$t['form_id'];
                $fmeta = is_array($t['fields_meta'] ?? null) ? $t['fields_meta'] : [];
                // find name-like fields
                $nameFieldIds = [];
                foreach ($fmeta as $fm){
                    $label = mb_strtolower((string)($fm['label'] ?? ''), 'UTF-8');
                    $type  = (string)($fm['type'] ?? '');
                    if ($label === '') continue;
                    if (preg_match('/\bname\b|first\s*name|last\s*name|full\s*name|surname|family/i', $label)
                        || preg_match('/نام(?:\s*خانوادگی)?|اسم/u', $label)){
                        $nameFieldIds[] = (int)($fm['id'] ?? 0);
                    }
                }
                $nameFieldIds = array_values(array_unique(array_filter($nameFieldIds, function($v){ return $v>0; })));
                if (empty($nameFieldIds)){
                    $lines[] = "فرم " . $fid . ": نامشخص (فیلد نام یافت نشد)";
                    continue;
                }
                // fetch values for current table rows in batch
                $sids = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, ($t['rows'] ?? [])), function($v){ return $v>0; }));
                $valuesMap = SubmissionRepository::listValuesBySubmissionIds($sids);
                $names = [];
                foreach ($sids as $sid){
                    $vals = $valuesMap[$sid] ?? [];
                    $parts = [];
                    foreach ($vals as $v){
                        $fidv = (int)($v['field_id'] ?? 0);
                        if (in_array($fidv, $nameFieldIds, true)){
                            $val = trim((string)($v['value'] ?? ''));
                            if ($val !== '') $parts[] = $val;
                        }
                    }
                    $name = trim(implode(' ', $parts));
                    if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
                }
                if (!empty($names)){
                    $lines[] = "فرم " . $fid . ":\n- " . implode("\n- ", $names);
                } else {
                    $lines[] = "فرم " . $fid . ": نامشخص (هیچ نامی در داده‌های انتخاب‌شده یافت نشد)";
                }
            }
            $sum = implode("\n\n", $lines);
            return new WP_REST_Response([ 'summary' => $sum, 'chunks' => [], 'usage' => [], 'voice' => $voice ], 200);
        }

    // For each table, build chunks and call LLM with a compact prompt
        $baseUrl = rtrim($base, '/');
        // Normalize base URL to avoid double /v1 when configured as https://host/v1
        if (preg_match('#/v\d+$#', $baseUrl)) {
            $baseUrl = preg_replace('#/v\d+$#', '', $baseUrl);
        }
        // Allow full endpoint override when a complete chat/completions path is provided
        if (preg_match('#/chat/(?:completions|completion)$#', $baseUrl)) {
            $endpoint = $baseUrl;
        } else {
            $endpoint = $baseUrl . '/v1/chat/completions';
        }
    $headers = [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ];
    $http_timeout = 45; // allow a bit more time for larger tables
        $agentName = 'hoshang';
        $usages = [];
        $answers = [];
        $debug = !empty($p['debug']);
        $debugInfo = [];
        // Phased pipeline for full-dataset structured analytics
        if ($isStructured && $phase !== ''){
            $fid = $form_ids[0];
            // Helper: detect field roles from labels/types for better guidance (names, mood text, mood score)
            $detect_field_roles = function(array $fmeta){
                $roles = [ 'name'=>[], 'mood_text'=>[], 'mood_score'=>[], 'phone'=>[] ];
                foreach (($fmeta ?: []) as $fm){
                    $lab = (string)($fm['label'] ?? '');
                    $labL = mb_strtolower($lab, 'UTF-8');
                    $type = (string)($fm['type'] ?? '');
                    if ($lab === '') continue;
                    // name candidates
                    if (preg_match('/\bname\b|first\s*name|last\s*name|full\s*name|surname|family/i', $lab)
                        || preg_match('/نام(?:\s*و\s*نام\s*خانوادگی)?|نام\s*خانوادگی|اسم/u', $labL)){
                        $roles['name'][] = $lab;
                    }
                    // phone candidates
                    if (preg_match('/\bphone\b|mobile|cell/i', $lab) || preg_match('/شماره\s*(?:تلفن|همراه)/u', $labL)){
                        $roles['phone'][] = $lab;
                    }
                    // mood text candidates
                    if (preg_match('/\b(mood|feeling|status|wellbeing)\b/i', $lab) || preg_match('/حال|حالت|اوضاع|احوال|روحیه|امروز\s*.*چطور/u', $labL)){
                        $roles['mood_text'][] = $lab;
                    }
                    // mood score candidates (rating/score 1-10)
                    if ($type === 'rating' || preg_match('/\b(score|rating)\b/i', $lab) || preg_match('/امتیاز|نمره|رتبه|از\s*(?:۱|1)\s*تا\s*(?:۱?0|۱۰)/u', $labL)){
                        $roles['mood_score'][] = $lab;
                    }
                }
                // de-dup
                foreach ($roles as $k=>$arr){ $roles[$k] = array_values(array_unique(array_filter(array_map('strval', $arr), function($s){ return $s!==''; }))); }
                return $roles;
            };
            // Heuristic: classify question as heavy/light for chunk/tokens
            $qLower = mb_strtolower($question, 'UTF-8');
            $isHeavy = (bool)(
                preg_match('/\b(compare|correlat|trend|distribution|variance|std|median|quartile|regression|cluster|segment|chart|bar|pie|line)\b/i', $qLower)
                || preg_match('/(?:مقایسه|همبستگی|روند|میانگین|میانه|نمودار|نمودار(?:\s*میله|\s*دایره|\s*خط)|واریانس|انحراف\s*معیار)/u', $qLower)
            );
            $cfgChunkSize = isset($cur['ai_ana_chunk_size']) && is_numeric($cur['ai_ana_chunk_size']) ? max(200, min(2000, (int)$cur['ai_ana_chunk_size'])) : 800;
            $reqChunk = isset($p['chunk_size']) && is_numeric($p['chunk_size']) ? (int)$p['chunk_size'] : 0;
            $useChunk = $reqChunk > 0 ? max(200, min(2000, $reqChunk)) : $cfgChunkSize;
            // Auto-tune chunk size slightly
            if ($reqChunk <= 0){ $useChunk = $isHeavy ? max($useChunk, 1000) : min($useChunk, 600); }
            // Determine token budget suggestion
            $suggestedMaxTok = $isHeavy ? min(1200, max(800, $max_tokens)) : min(500, max(300, $max_tokens));
            if ($phase === 'plan'){
                // Use paged listing to get total quickly
                $pg = \Arshline\Modules\Forms\SubmissionRepository::listByFormPaged($fid, 1, 1, []);
                $total = (int)($pg['total'] ?? 0);
                $n = $useChunk>0 ? (int)ceil($total / $useChunk) : 1;
                // fields_meta
                $fmeta = [];
                try {
                    $fieldsForMeta = \Arshline\Modules\Forms\FieldRepository::listByForm($fid);
                    foreach (($fieldsForMeta ?: []) as $f){
                        $p0 = is_array($f['props'] ?? null) ? $f['props'] : [];
                        $label0 = (string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? '');
                        $type0 = (string)($p0['type'] ?? '');
                        $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                    }
                } catch (\Throwable $e) { /* ignore */ }
                $field_roles = $detect_field_roles($fmeta);
                // New: Classify relevant fields based on the question and available headers (labels)
                $relevant_fields = [];
                // New: Extract lightweight entities (person names) from the question for downstream chunk matching
                $entities = [];
                try {
                    $qtext = (string)$question;
                    $qnorm = mb_strtolower($qtext, 'UTF-8');
                    // Prefer quoted forms: «نام» or "نام"
                    $cand = '';
                    if (preg_match('/«([^»]+)»/u', $qtext, $mm)) { $cand = trim($mm[1]); }
                    if ($cand === '' && preg_match('/"([^"\n]{2,})"/u', $qtext, $mm2)) { $cand = trim($mm2[1]); }
                    // Persian pattern after "حال|احوال" up to common endings
                    if ($cand === '' && preg_match('/(?:حال|احوال)\s+([\p{L}‌\s]{2,})/u', $qnorm, $mm3)){
                        $cand = trim($mm3[1]);
                        $cand = preg_replace('/\s*(چطوره|چطور|هست|است)\s*$/u', '', (string)$cand);
                        $cand = preg_replace('/[\?\؟]+$/u', '', (string)$cand);
                    }
                    // Remove honorifics and keep at most first 2 tokens
                    $cand = (string)$cand;
                    $cand = preg_replace('/\x{200C}/u', '', $cand); // ZWNJ
                    $cand = str_replace(['ي','ك','ة'], ['ی','ک','ه'], $cand);
                    $cand = trim($cand);
                    if ($cand !== ''){
                        $parts = preg_split('/\s+/u', $cand, -1, PREG_SPLIT_NO_EMPTY);
                        $titles = ['آقای','آقا','خانم','دکتر','مهندس','استاد'];
                        $parts = array_values(array_filter($parts, function($t) use ($titles){ return !in_array($t, $titles, true); }));
                        if (!empty($parts)){
                            $cand = implode(' ', array_slice($parts, 0, 2));
                            if ($cand !== ''){ $entities[] = [ 'type' => 'person', 'value' => $cand ]; }
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Detect intents
                $isMoodIntent = (bool)(preg_match('/\b(mood|wellbeing)\b/i', $qLower)
                    || preg_match('/حال|احوال|روحیه|رضایت|خوشحال|غمگین/u', $qLower));
                $isPhoneIntent = (bool)(preg_match('/\b(phone|mobile|cell)\b/i', $qLower)
                    || preg_match('/شماره\s*(?:تلفن|همراه|تماس)|موبایل/u', $qLower));
                try {
                    $labelsOnly = array_values(array_filter(array_map(function($fm){ $lab = (string)($fm['label'] ?? ''); return $lab!=='' ? $lab : null; }, $fmeta)));
                    $clsSys = 'You are Hoshang. Given a user question and a list of form field headings (labels), select the most relevant headings to answer the question.'
                        . ' Output STRICT JSON: {"relevant_fields":["<label>",...], "reason":"<fa>"}. Use label strings exactly as provided. Use Persian for reason.';
                    $clsUser = [ 'question'=>$question, 'headings'=>$labelsOnly ];
                    $clsMsgs = [ [ 'role'=>'system','content'=>$clsSys ], [ 'role'=>'user','content'=> wp_json_encode($clsUser, JSON_UNESCAPED_UNICODE) ] ];
                    $clsReq = [ 'model' => (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'messages'=>$clsMsgs, 'temperature'=>0.0, 'max_tokens'=>300 ];
                    $rC = wp_remote_post($endpoint, [ 'timeout'=>20, 'headers'=>$headers, 'body'=> wp_json_encode($clsReq) ]);
                    $bC = is_wp_error($rC) ? null : json_decode((string)wp_remote_retrieve_body($rC), true);
                    $txtC = '';
                    if (is_array($bC)){
                        if (isset($bC['choices'][0]['message']['content']) && is_string($bC['choices'][0]['message']['content'])) $txtC = (string)$bC['choices'][0]['message']['content'];
                        elseif (isset($bC['choices'][0]['text']) && is_string($bC['choices'][0]['text'])) $txtC = (string)$bC['choices'][0]['text'];
                        elseif (isset($bC['output_text']) && is_string($bC['output_text'])) $txtC = (string)$bC['output_text'];
                    }
                    $cls = $txtC ? json_decode($txtC, true) : null;
                    if (is_array($cls) && is_array($cls['relevant_fields'] ?? null)){
                        $relevant_fields = array_values(array_unique(array_filter(array_map('strval', $cls['relevant_fields']))));
                    }
                    // Attach classifier debug (safe preview)
                    if ($debug){
                        $dbg[] = [ 'phase'=>'plan:classifier', 'request_preview'=>[ 'model'=> (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'messages'=>$clsMsgs ], 'raw'=> (strlen($txtC)>1200? (substr($txtC,0,1200).'…[truncated]') : $txtC) ];
                    }
                } catch (\Throwable $e) { /* ignore classifier errors */ }
                // Force-include fields for specific intents
                if ($isMoodIntent){
                    foreach (['mood_text','mood_score','name'] as $rk){
                        foreach (($field_roles[$rk] ?? []) as $lab){ if (is_string($lab) && $lab!==''){ $relevant_fields[] = $lab; } }
                    }
                }
                if ($isPhoneIntent){
                    foreach (['phone','name'] as $rk){
                        foreach (($field_roles[$rk] ?? []) as $lab){ if (is_string($lab) && $lab!==''){ $relevant_fields[] = $lab; } }
                    }
                }
                $relevant_fields = array_values(array_unique($relevant_fields));
                $dbg = [];
                if ($debug){ $dbg[] = [ 'phase'=>'plan', 'total_rows'=>$total, 'chunk_size'=>$useChunk, 'number_of_chunks'=>$n, 'field_roles'=>$field_roles, 'relevant_fields'=>$relevant_fields, 'entities'=>$entities, 'routing'=>[ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ] ]; }
                return new WP_REST_Response([
                    'phase' => 'plan',
                    'plan' => [ 'total_rows'=>$total, 'chunk_size'=>$useChunk, 'number_of_chunks'=>$n, 'suggested_max_tokens'=>$suggestedMaxTokens ?? $suggestedMaxTok, 'field_roles'=>$field_roles, 'relevant_fields'=>$relevant_fields, 'entities'=>$entities ],
                    'fields_meta' => $fmeta,
                    'usage' => [],
                    'debug' => $dbg,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                    'session_id' => $session_id,
                ], 200);
            }
            if ($phase === 'chunk'){
                $page = $chunk_index;
                $t0 = microtime(true);
                $res = \Arshline\Modules\Forms\SubmissionRepository::listByFormPaged($fid, $page, $useChunk, []);
                $rows = is_array($res['rows'] ?? null) ? $res['rows'] : [];
                // Build fields meta
                $fmeta = [];
                try {
                    $fieldsForMeta = \Arshline\Modules\Forms\FieldRepository::listByForm($fid);
                    foreach (($fieldsForMeta ?: []) as $f){
                        $p0 = is_array($f['props'] ?? null) ? $f['props'] : [];
                        $label0 = (string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? '');
                        $type0 = (string)($p0['type'] ?? '');
                        $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Allow client to pass relevant_fields chosen during plan
                $reqRelevant = [];
                try {
                    $reqRelevant = is_array($p['relevant_fields'] ?? null) ? array_values(array_unique(array_filter(array_map('strval', $p['relevant_fields'])))) : [];
                } catch (\Throwable $e) { $reqRelevant = []; }
                $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $rows), function($v){ return $v>0; }));
                $valuesMap = \Arshline\Modules\Forms\SubmissionRepository::listValuesBySubmissionIds($sliceIds);
                // Header labels and CSV build (apply relevant_fields if provided); always include id, created_at at the start
                $labels = [];
                $idToLabel = [];
                foreach ($fmeta as $fm){
                    $fidm=(int)($fm['id'] ?? 0);
                    $labm=(string)($fm['label'] ?? '');
                    if ($labm==='') $labm='فیلد #'.$fidm;
                    $idToLabel[$fidm]=$labm;
                    $labels[]=$labm;
                }
                $rowsById = [];
                foreach ($rows as $r){ $rowsById[(int)($r['id'] ?? 0)] = $r; }
                $labelsSel = $labels;
                if (!empty($reqRelevant)){
                    $norm = function($s){
                        $s = (string)$s;
                        $s = str_replace(["\xE2\x80\x8C", "\xC2\xA0"], ['', ' '], $s); // ZWNJ, NBSP
                        $s = str_replace(["ي","ك"],["ی","ک"], $s); // Arabic to Persian
                        $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s); // punctuation to space
                        $s = preg_replace('/\s+/u',' ', $s); // collapse spaces
                        return trim(mb_strtolower($s, 'UTF-8'));
                    };
                    $set = [];
                    foreach ($reqRelevant as $rf){ $set[$norm($rf)] = true; }
                    $labelsSel = array_values(array_filter($labelsSel, function($lab) use ($norm,$set){ return isset($set[$norm($lab)]); }));
                    // Fallback: if after filtering nothing remains (besides id/created_at to be injected), include detected name/mood fields
                    $rolesTmp = $detect_field_roles($fmeta);
                    $roleWanted = array_merge($rolesTmp['name'] ?? [], $rolesTmp['mood_text'] ?? [], $rolesTmp['mood_score'] ?? [], $rolesTmp['phone'] ?? []);
                    if (empty($labelsSel) && !empty($roleWanted)){
                        // keep only labels that exist in original labels
                        $labelsSel = array_values(array_intersect($roleWanted, $labels));
                    }
                }
                // Ensure id/created_at are present at the beginning
                $labelsSel = array_values(array_filter($labelsSel, function($h){ return $h !== 'id' && $h !== 'created_at'; }));
                array_unshift($labelsSel, 'created_at');
                array_unshift($labelsSel, 'id');
                // Extract a target name hint from entities (preferred) or question (before prefilter)
                $nameHint = '';
                $reqEntities = [];
                // Prefer plan-provided entities if available
                try {
                    $reqEntities = is_array($p['entities'] ?? null) ? $p['entities'] : [];
                    if (!empty($reqEntities)){
                        foreach ($reqEntities as $en){
                            $typ = (string)($en['type'] ?? '');
                            $val = trim((string)($en['value'] ?? ''));
                            if ($typ === 'person' && $val !== ''){ $nameHint = $val; break; }
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
                try {
                    if ($nameHint === '' && preg_match('/«([^»]+)»/u', $question, $mm)) { $nameHint = trim($mm[1]); }
                    $qLowerLocal = mb_strtolower($question, 'UTF-8');
                    if ($nameHint === '' && preg_match('/حال\s+([\p{L}‌\s]+)/u', $qLowerLocal, $mm2)) { $nameHint = trim($mm2[1]); }
                    // strip common trailing words and punctuation (e.g., "چطوره", "چطور", "هست", "است", question marks)
                    $nameHint = preg_replace('/\s*(چطوره|چطور|هست|است)\s*$/u', '', (string)$nameHint);
                    $nameHint = preg_replace('/[\?\؟]+$/u', '', (string)$nameHint);
                    // keep only first 1-2 tokens for robustness
                    $partsNH = preg_split('/\s+/u', (string)$nameHint, -1, PREG_SPLIT_NO_EMPTY);
                    if (is_array($partsNH) && !empty($partsNH)){
                        $nameHint = implode(' ', array_slice($partsNH, 0, 2));
                    }
                    $nameHint = trim(mb_substr((string)$nameHint, 0, 60, 'UTF-8'));
                } catch (\Throwable $e) { $nameHint = ''; }
                // Detect roles for guidance (needed for prefilter and prompts)
                $field_roles = $detect_field_roles($fmeta);
                // Optional: prefilter rows by name hint using detected name fields (with Persian-aware normalization and token + Levenshtein-like similarity)
                $idsForCsv = $sliceIds;
                $matchedIds = [];
                $filteredByName = false;
                $prefilterNotes = [];
                $fallbackApplied = false; $fallbackRowId = 0; $fallbackReason = '';
                try {
                    // Persian-aware normalization
                    $faNorm = function($s){
                        $s = (string)$s;
                        $s = str_replace(["\xE2\x80\x8C", "\xC2\xA0"], ['', ' '], $s); // ZWNJ, NBSP
                        $s = str_replace(["ي","ك","ة"],["ی","ک","ه"], $s); // Arabic->Persian
                        $s = preg_replace('/\p{Mn}+/u', '', $s); // remove diacritics
                        $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s); // punct to space
                        $s = preg_replace('/\s+/u',' ', $s);
                        $s = trim($s);
                        $s = mb_strtolower($s, 'UTF-8');
                        return $s;
                    };
                    $titles = [ 'آقای', 'خانم', 'دکتر', 'مهندس', 'استاد' ];
                    $stripTitles = function(array $toks) use ($titles){
                        $set = [];
                        foreach ($titles as $w){ $set[$w]=true; $set[mb_strtolower($w,'UTF-8')]=true; }
                        $out = [];
                        foreach ($toks as $t){ if ($t!=='' && !isset($set[$t])) $out[] = $t; }
                        return $out;
                    };
                    $tokenize = function($s) use ($faNorm,$stripTitles){
                        $n = $faNorm($s);
                        $t = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
                        return $stripTitles($t);
                    };
                    $hintTokens = [];
                    if ($nameHint !== ''){ $hintTokens = $tokenize($nameHint); }
                    // similarity between token sets (Jaccard + exact/partial bonuses)
                    $tokSim = function(array $A, array $B){
                        if (empty($A) || empty($B)) return 0.0;
                        $A = array_values(array_unique($A));
                        $B = array_values(array_unique($B));
                        $Ai = []; foreach ($A as $a){ $Ai[$a]=true; }
                        $Bi = []; foreach ($B as $b){ $Bi[$b]=true; }
                        $inter = array_values(array_intersect(array_keys($Ai), array_keys($Bi)));
                        $unionCount = count(array_unique(array_merge(array_keys($Ai), array_keys($Bi))));
                        $j = $unionCount>0 ? (count($inter)/$unionCount) : 0.0;
                        $exact = count($inter);
                        $partial = 0;
                        foreach ($A as $ta){
                            foreach ($B as $tb){
                                if ($ta===$tb) continue;
                                if (mb_strlen($ta,'UTF-8')>=3 && mb_strlen($tb,'UTF-8')>=3){
                                    if (mb_strpos($ta,$tb,0,'UTF-8')!==false || mb_strpos($tb,$ta,0,'UTF-8')!==false){ $partial++; break; }
                                }
                            }
                        }
                        if ($exact>=2) return 1.0;
                        $score = 0.6*$j + 0.2*($exact>0?1:0) + 0.2*($partial>0?1:0);
                        return ($score>1.0)?1.0:$score;
                    };
                    $bestScore = 0.0; $bestId = 0; $rowMatchDbg = [];
                    // Dynamic threshold: single-token names are often noisy, be a bit more permissive
                    $tokCount = is_array($hintTokens) ? count($hintTokens) : 0;
                    $threshold = ($tokCount <= 1) ? 0.50 : (($tokCount === 2) ? 0.65 : 0.7);
                    $prefilterNotes[] = 'name_threshold_'.str_replace('.', '_', (string)$threshold);
                    // Helper: pseudo-Levenshtein similarity tolerant to UTF-8 via similar_text fallback
                    $simLev = function(string $a, string $b) {
                        $aN = $a; $bN = $b;
                        // try ASCII transliteration for better granularity; fallback to raw
                        if (function_exists('iconv')){
                            $aT = @iconv('UTF-8', 'ASCII//TRANSLIT', $aN);
                            $bT = @iconv('UTF-8', 'ASCII//TRANSLIT', $bN);
                            if (is_string($aT) && $aT !== '') $aN = $aT;
                            if (is_string($bT) && $bT !== '') $bN = $bT;
                        }
                        $pct = 0.0; similar_text($aN, $bN, $pct);
                        return max(0.0, min(1.0, $pct / 100.0));
                    };
                    if (!empty($hintTokens)){
                        $nameLabels = array_values(array_unique((array)($field_roles['name'] ?? [])));
                        if (!empty($nameLabels)){
                            $setName = []; foreach ($nameLabels as $nl){ $setName[$nl] = true; }
                            foreach ($sliceIds as $sid){
                                $vals = $valuesMap[$sid] ?? [];
                                $nameParts = [];
                                foreach ($vals as $v){
                                    $fidv=(int)($v['field_id'] ?? 0);
                                    $lab=(string)($idToLabel[$fidv] ?? '');
                                    if ($lab!=='' && isset($setName[$lab])){
                                        $val=trim((string)($v['value'] ?? ''));
                                        if ($val!=='') $nameParts[]=$val;
                                    }
                                }
                                if (!empty($nameParts)){
                                    $rowTokens = [];
                                    foreach ($nameParts as $np){ $rowTokens = array_merge($rowTokens, $tokenize($np)); }
                                    $rowTokens = array_values(array_unique($rowTokens));
                                    $scTok = $tokSim($hintTokens, $rowTokens);
                                    // Combine token similarity with Levenshtein-like score over full strings
                                    $rowFull = $faNorm(implode(' ', $nameParts));
                                    $hintFull = $faNorm(implode(' ', $hintTokens));
                                    $scLev = $simLev($hintFull, $rowFull);
                                    // First-name bonus: if first hint token equals or is prefix of any row token, boost score a bit
                                    $bonus = 0.0;
                                    $firstHint = isset($hintTokens[0]) ? (string)$hintTokens[0] : '';
                                    $prefReason = '';
                                    if ($firstHint !== ''){
                                        foreach ($rowTokens as $rt){
                                            if ($rt === $firstHint){ $bonus = 0.20; $prefReason = 'first_name_exact'; break; }
                                            if ((mb_strlen($rt,'UTF-8')>=3 || mb_strlen($firstHint,'UTF-8')>=3) && (mb_strpos($rt,$firstHint,0,'UTF-8')===0 || mb_strpos($firstHint,$rt,0,'UTF-8')===0)){ $bonus = max($bonus, 0.15); $prefReason = $prefReason ?: 'first_name_partial'; }
                                        }
                                    }
                                    $sc = 0.65*$scTok + 0.35*$scLev + $bonus;
                                    if ($sc > 1.0) $sc = 1.0;
                                    if ($sc >= $threshold){ $matchedIds[] = $sid; }
                                    if ($sc > $bestScore){ $bestScore=$sc; $bestId=$sid; }
                                    // Row-level debug (normalized without spaces for readability)
                                    $rowNormNoSpace = str_replace(' ', '', $rowFull);
                                    $hintNormNoSpace = str_replace(' ', '', $hintFull);
                                    $rowMatchDbg[] = [
                                        'row_id' => $sid,
                                        'normalized_query' => $hintNormNoSpace,
                                        'normalized_row' => $rowNormNoSpace,
                                        'prefilter_reason' => ($prefReason ?: '—'),
                                        'match_score' => $sc,
                                        'threshold_used' => $threshold,
                                        'bonus_applied' => $bonus,
                                        'final_match' => ($sc >= $threshold)
                                    ];
                                }
                            }
                            if (!empty($matchedIds)){
                                // If multiple matches, select the most recent by created_at deterministically
                                if (count($matchedIds) > 1){
                                    $bestRecentId = 0; $bestTs = -PHP_INT_MAX;
                                    foreach ($matchedIds as $mid){
                                        $rowObj = $rowsById[$mid] ?? [];
                                        $ts = strtotime((string)($rowObj['created_at'] ?? '')) ?: 0;
                                        if ($ts > $bestTs){ $bestTs = $ts; $bestRecentId = $mid; }
                                    }
                                    $idsForCsv = $bestRecentId ? [ $bestRecentId ] : [ $matchedIds[0] ];
                                } else {
                                    $idsForCsv = $matchedIds;
                                }
                                $filteredByName = true;
                                $prefilterNotes[] = 'name_prefilter_matched';
                            } else {
                                // No confident match — fall back to best candidate if reasonably close
                                if ($bestId > 0 && $bestScore >= max(0.5, $threshold - 0.1)){
                                    $idsForCsv = [ $bestId ];
                                    $filteredByName = true;
                                    $prefilterNotes[] = 'name_prefilter_best_fallback';
                                    $fallbackApplied = true; $fallbackRowId = (int)$bestId; $fallbackReason = 'best_score_close';
                                } else {
                                    // Deterministic fallback: prefer highest-score row, else most recent
                                    if ($bestId > 0){
                                        $idsForCsv = [ $bestId ];
                                        $prefilterNotes[] = 'fallback_to_highest_score_1';
                                        $prefilterNotes[] = 'fallback_row_id:'.(string)$bestId;
                                        $fallbackApplied = true; $fallbackRowId = (int)$bestId; $fallbackReason = 'highest_score_overall';
                                    } else {
                                        $bestRecentId = 0; $bestTs = -PHP_INT_MAX;
                                        foreach ($sliceIds as $sid0){
                                            $rowObj = $rowsById[$sid0] ?? [];
                                            $ts = strtotime((string)($rowObj['created_at'] ?? '')) ?: 0;
                                            if ($ts > $bestTs){ $bestTs = $ts; $bestRecentId = $sid0; }
                                        }
                                        if ($bestRecentId > 0){
                                            $idsForCsv = [ $bestRecentId ];
                                            $prefilterNotes[] = 'recent_rows_fallback_1';
                                            $prefilterNotes[] = 'fallback_row_id:'.(string)$bestRecentId;
                                            $fallbackApplied = true; $fallbackRowId = (int)$bestRecentId; $fallbackReason = 'recent_created_at';
                                        } else { $idsForCsv = []; }
                                    }
                                    $filteredByName = true;
                                    $prefilterNotes[] = 'name_prefilter_no_confident_match';
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) { /* ignore prefilter errors */ }
                // Canonicalize headers for LLM grounding while preserving originals for display/debug
                $canonicalMap = [];// original => canonical
                $usedCanon = [];// to avoid duplicates like name,name_2
                $canonOf = function(string $lab) use ($field_roles, &$usedCanon){
                    $role = '';
                    if (in_array($lab, (array)($field_roles['name'] ?? []), true)) $role = 'name';
                    elseif (in_array($lab, (array)($field_roles['phone'] ?? []), true)) $role = 'phone';
                    elseif (in_array($lab, (array)($field_roles['mood_text'] ?? []), true)) $role = 'mood_text';
                    elseif (in_array($lab, (array)($field_roles['mood_score'] ?? []), true)) $role = 'mood_score';
                    if ($role === ''){
                        // Fallback: safe ascii-ish key
                        $base = 'field'; $i = 1; $key = $base;
                        while (isset($usedCanon[$key])){ $i++; $key = $base.'_'.$i; }
                        $usedCanon[$key] = true; return $key;
                    }
                    $key = $role;
                    if (isset($usedCanon[$key])){
                        $i = 2; $cand = $key.'_'.$i;
                        while (isset($usedCanon[$cand])){ $i++; $cand = $key.'_'.$i; }
                        $key = $cand;
                    }
                    $usedCanon[$key] = true; return $key;
                };
                // Build canonical header list mirroring labelsSel
                $headersCanonical = [];
                foreach ($labelsSel as $h){
                    if ($h === 'id' || $h === 'created_at'){ $headersCanonical[] = $h; continue; }
                    $c = $canonOf((string)$h);
                    $canonicalMap[(string)$h] = $c;
                    $headersCanonical[] = $c;
                }
                // Reverse map canonical => list of originals
                $canonToOriginals = [];
                foreach ($canonicalMap as $orig=>$can){ if (!isset($canonToOriginals[$can])) $canonToOriginals[$can]=[]; $canonToOriginals[$can][] = $orig; }
                // Build CSV rows with canonical headers
                $rowsCsv = [];
                if (!empty($headersCanonical)){
                    // header
                    $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $headersCanonical));
                    foreach ($idsForCsv as $sid){
                        $vals = $valuesMap[$sid] ?? [];
                        // Build original-label value map first
                        $origMap = [];
                        foreach ($vals as $v){
                            $fidv=(int)($v['field_id'] ?? 0);
                            $lab=(string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab='فیلد #'.$fidv;
                            $val=trim((string)($v['value'] ?? ''));
                            if(!isset($origMap[$lab])) $origMap[$lab]=[];
                            if($val!=='') $origMap[$lab][]=$val;
                        }
                        // Inject core submission info
                        $rowObj = $rowsById[$sid] ?? [];
                        $rowOut = [];
                        foreach ($headersCanonical as $hc){
                            if ($hc === 'id'){ $rowOut[$hc] = [ (string)($rowObj['id'] ?? $sid) ]; continue; }
                            if ($hc === 'created_at'){ $rowOut[$hc] = [ (string)($rowObj['created_at'] ?? '') ]; continue; }
                            $valsAgg = [];
                            foreach ((array)($canonToOriginals[$hc] ?? []) as $orig){
                                foreach ((array)($origMap[$orig] ?? []) as $vv){ if ($vv!=='') $valsAgg[] = $vv; }
                            }
                            $rowOut[$hc] = !empty($valsAgg) ? [ implode(' | ', $valsAgg) ] : [];
                        }
                        $rowsCsv[] = implode(',', array_map(function($h) use ($rowOut){ $v = isset($rowOut[$h]) ? implode(' | ', (array)$rowOut[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $headersCanonical));
                    }
                }
                $tableCsv = implode("\r\n", $rowsCsv);
                // Chunk prompt expecting partials (focus on extracting signal, not copying raw text)
                $sys = 'You are Hoshang, a Persian analytics assistant. Analyze ONLY the provided CSV rows (this chunk).'
                    . ' Use name columns to match the requested person (accept partial matches, tolerate spacing/diacritics). If multiple rows match, prefer the most recent by created_at.'
                    . ' If the question is about mood/wellbeing, you MUST combine evidence from textual mood columns and numeric rating columns (1–10) when both exist.'
                    . ' If the question asks for contact info like phone/mobile, extract phone numbers from phone-like columns (deduplicate), associate with names when possible, and add pairs as partial_insights like {"نام":"…","تلفن":"…"}.'
                    . ' Do NOT copy long raw texts; extract sentiment/intent concisely. Output STRICT JSON with keys: '
                    . '{"aggregations":{...},"partial_insights":[...],"partial_chart_data":[...],"outliers":[...],"fields_used":[...],"chunk_summary":{"row_count":<int>,"notes":[...]}}.'
                    . ' No prose. JSON only. Use Persian for insights/notes.';
                $user = [ 'question' => $question, 'table_csv' => $tableCsv, 'field_roles' => $field_roles, 'guidance' => [ 'avoid_verbatim' => true, 'combine_mood_text_and_score' => true, 'prefer_latest' => true, 'target_name_hint' => $nameHint ] ];
                $msgs = [ [ 'role'=>'system','content'=>$sys ], [ 'role'=>'user','content'=> wp_json_encode($user, JSON_UNESCAPED_UNICODE) ] ];
                $modelName = $use_model; if ($isHeavy && preg_match('/mini|3\.5|4o\-mini/i', (string)$modelName)) $modelName = 'gpt-4o';
                $req = [ 'model'=>$modelName, 'messages'=>$msgs, 'temperature'=>0.1, 'max_tokens'=>$suggestedMaxTok ];
                $jsonReq = wp_json_encode($req);
                $r = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$jsonReq ]);
                $status = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
                $raw = is_wp_error($r) ? ($r->get_error_message() ?: '') : (string)wp_remote_retrieve_body($r);
                $ok = ($status === 200);
                $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                $usage = is_array($body) && isset($body['usage']) ? $body['usage'] : null;
                $text = '';
                if (is_array($body)){
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) $text = (string)$body['choices'][0]['message']['content'];
                    elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) $text = (string)$body['choices'][0]['text'];
                    elseif (isset($body['output_text']) && is_string($body['output_text'])) $text = (string)$body['output_text'];
                }
                $partial = $text ? json_decode($text, true) : null;
                $repaired = false;
                // Ensure chunk_summary.row_count reflects actual candidate rows we used
                $actualRowCount = is_array($idsForCsv) ? count($idsForCsv) : count($rows);
                if (!is_array($partial)){
                    // Default empty partial to a valid schema
                    $partial = [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ];
                    // JSON repair mini pass
                    $repairSys = 'Fix the following model output into VALID JSON only (no code fences, no text). Keep only keys: aggregations, partial_insights, partial_chart_data, outliers, fields_used, chunk_summary.';
                    $repairMsgs = [ [ 'role'=>'system','content'=>$repairSys ], [ 'role'=>'user','content'=>$text ] ];
                    $repairReq = [ 'model' => (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'messages'=>$repairMsgs, 'temperature'=>0.0, 'max_tokens'=>400 ];
                    $r2 = wp_remote_post($endpoint, [ 'timeout'=>20, 'headers'=>$headers, 'body'=> wp_json_encode($repairReq) ]);
                    $raw2 = is_wp_error($r2) ? '' : (string)wp_remote_retrieve_body($r2);
                    $b2 = json_decode($raw2, true);
                    $txt2 = '';
                    if (is_array($b2)){
                        if (isset($b2['choices'][0]['message']['content']) && is_string($b2['choices'][0]['message']['content'])) $txt2 = (string)$b2['choices'][0]['message']['content'];
                        elseif (isset($b2['choices'][0]['text']) && is_string($b2['choices'][0]['text'])) $txt2 = (string)$b2['choices'][0]['text'];
                        elseif (isset($b2['output_text']) && is_string($b2['output_text'])) $txt2 = (string)$b2['output_text'];
                    }
                    $partial = $txt2 ? json_decode($txt2, true) : null;
                    if (is_array($partial)) $repaired = true;
                }
                if (!is_array($partial)){
                    // default already handled above
                    $partial = [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ];
                } else {
                    if (!isset($partial['chunk_summary']) || !is_array($partial['chunk_summary'])){ $partial['chunk_summary'] = [ 'row_count' => $actualRowCount, 'notes' => [] ]; }
                    if (!isset($partial['chunk_summary']['row_count']) || (int)$partial['chunk_summary']['row_count'] === 0){ $partial['chunk_summary']['row_count'] = $actualRowCount; }
                    if (!isset($partial['fields_used']) || !is_array($partial['fields_used'])){
                        // fallback fields_used to canonical headers we sent (excluding id/created_at)
                        $fu = array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; }));
                        $partial['fields_used'] = $fu;
                    }
                    // Attach prefilter notes if any
                    if (!isset($partial['chunk_summary']['notes']) || !is_array($partial['chunk_summary']['notes'])){ $partial['chunk_summary']['notes'] = []; }
                    foreach ($prefilterNotes as $nt){ $partial['chunk_summary']['notes'][] = $nt; }
                }
                // Attach meta for final phase to understand requested person and fallback details
                if (!isset($partial['meta']) || !is_array($partial['meta'])){ $partial['meta'] = []; }
                $partial['meta']['requested_person'] = $nameHint;
                $partial['meta']['fallback_applied'] = $fallbackApplied;
                $partial['meta']['fallback_row_id'] = $fallbackRowId;
                $partial['meta']['fallback_reason'] = $fallbackReason;
                $partial['meta']['entities'] = $reqEntities;
                // Deterministic fallbacks for phone and mood intents when model returns empty
                $qLowerLocal2 = mb_strtolower($question, 'UTF-8');
                $isPhoneIntentLocal = (bool)(preg_match('/\b(phone|mobile|cell)\b/i', $qLowerLocal2) || preg_match('/شماره\s*(?:تلفن|همراه|تماس)|موبایل/u', $qLowerLocal2));
                $isMoodIntentLocal = (bool)(preg_match('/\b(mood|wellbeing)\b/i', $qLowerLocal2) || preg_match('/حال|احوال|روحیه|رضایت/u', $qLowerLocal2));
                if (!is_array($partial)) { $partial = [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ]; }
                // Helper: normalize Persian digits and standardize phone to 09XXXXXXXXX
                $stdPhone = function(string $s): string {
                    $fa2en = ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'];
                    $d = strtr($s, $fa2en);
                    $d = preg_replace('/[^0-9]/', '', $d ?? '');
                    if ($d === null) $d = '';
                    // pick the last 11-digit starting with 09 if embedded
                    if (strlen($d) > 11){ if (preg_match('/(09\d{9})$/', $d, $m)) $d = $m[1]; }
                    if (strlen($d) === 10 && substr($d, 0, 1) === '9') $d = '0'.$d;
                    if (strlen($d) === 11 && substr($d, 0, 2) === '09') return $d;
                    return $d;
                };
                if ($isPhoneIntentLocal){
                    $phones = [];
                    // When filtered by name, use those ids; otherwise aggregate across this chunk
                    $scanIds = !empty($idsForCsv) ? $idsForCsv : $sliceIds;
                    $nameLabels = array_values(array_unique((array)($field_roles['name'] ?? [])));
                    $phoneLabels = array_values(array_unique((array)($field_roles['phone'] ?? [])));
                    $setName = []; foreach ($nameLabels as $nl){ $setName[$nl]=true; }
                    $setPhone = []; foreach ($phoneLabels as $pl){ $setPhone[$pl]=true; }
                    foreach ($scanIds as $sid){
                        $vals = $valuesMap[$sid] ?? [];
                        $nmParts = []; $phVals = [];
                        foreach ($vals as $v){
                            $fidv=(int)($v['field_id'] ?? 0);
                            $lab=(string)($idToLabel[$fidv] ?? '');
                            $val=trim((string)($v['value'] ?? ''));
                            if ($val==='') continue;
                            if ($lab!=='' && isset($setName[$lab])) $nmParts[] = $val;
                            if ($lab!=='' && isset($setPhone[$lab])) $phVals[] = $val;
                        }
                        $nm = trim(implode(' ', $nmParts));
                        // Prefer requested person in display when a fallback row was used
                        $outNm = ($fallbackApplied && $nameHint !== '' && $nameHint !== $nm) ? $nameHint : $nm;
                        foreach ($phVals as $pval){
                            $pp = $stdPhone($pval);
                            if ($pp!==''){
                                $entry = [ 'name'=>$outNm, 'phone'=>$pp ];
                                if ($outNm !== $nm && $nm !== ''){ $entry['source_row_name'] = $nm; }
                                $phones[$pp] = $entry;
                            }
                        }
                    }
                    if (!empty($phones)){
                        // Overwrite/augment partial_insights
                        $partial['partial_insights'] = array_values($phones);
                        if (!isset($partial['chunk_summary']['notes'])) $partial['chunk_summary']['notes'] = [];
                        $partial['chunk_summary']['notes'][] = 'server_fallback_phone_applied';
                    }
                }
                if ($isMoodIntentLocal && !empty($idsForCsv)){
                    // Use the single selected (latest) row when available
                    $sid = is_array($idsForCsv) ? (int)reset($idsForCsv) : 0;
                    if ($sid > 0){
                        $vals = $valuesMap[$sid] ?? [];
                        $nameLabels = array_values(array_unique((array)($field_roles['name'] ?? [])));
                        $textLabels = array_values(array_unique((array)($field_roles['mood_text'] ?? [])));
                        $scoreLabels = array_values(array_unique((array)($field_roles['mood_score'] ?? [])));
                        $setN = []; foreach ($nameLabels as $x){ $setN[$x]=true; }
                        $setT = []; foreach ($textLabels as $x){ $setT[$x]=true; }
                        $setS = []; foreach ($scoreLabels as $x){ $setS[$x]=true; }
                        $nmParts=[]; $txParts=[]; $scoreVals=[];
                        foreach ($vals as $v){
                            $fidv=(int)($v['field_id'] ?? 0);
                            $lab=(string)($idToLabel[$fidv] ?? '');
                            $val=trim((string)($v['value'] ?? ''));
                            if ($val==='') continue;
                            if ($lab!=='' && isset($setN[$lab])) $nmParts[]=$val;
                            if ($lab!=='' && isset($setT[$lab])) $txParts[]=$val;
                            if ($lab!=='' && isset($setS[$lab])){
                                // extract first 1-2 digit number
                                if (preg_match('/(\d{1,2})/u', strtr($val, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']), $m)){
                                    $n=(int)$m[1]; if ($n>=0 && $n<=10) $scoreVals[]=$n;
                                }
                            }
                        }
                        if (empty($partial['partial_insights'])){
                            $rowName = trim(implode(' ', $nmParts));
                            // If fallback applied and requested person differs from the selected row, keep requested person in insights to avoid wrong name in final
                            $outName = ($fallbackApplied && $nameHint !== '' && $nameHint !== $rowName) ? $nameHint : $rowName;
                            $ins = [ 'name'=>$outName ];
                            if ($outName !== $rowName && $rowName !== '') $ins['source_row_name'] = $rowName;
                            if (!empty($txParts)) $ins['mood_text'] = implode(' | ', $txParts);
                            if (!empty($scoreVals)) $ins['mood_score'] = max($scoreVals);
                            if (count($ins) > 1){
                                $partial['partial_insights'] = [ $ins ];
                                if (!isset($partial['chunk_summary']['notes'])) $partial['chunk_summary']['notes'] = [];
                                $partial['chunk_summary']['notes'][] = 'server_fallback_mood_applied';
                            }
                        }
                    }
                }
                $t1 = microtime(true);
                $dbg = [];
                $debugLocal = !empty($p['debug']);
                // Build a minimal debug object (always) and enrich when debugLocal=true
                $nearIdsComputed = array_values(array_filter(array_map(function($it){
                    if (!is_array($it)) return null;
                    $rid = isset($it['row_id']) ? (int)$it['row_id'] : 0;
                    $ms = isset($it['match_score']) ? (float)$it['match_score'] : null;
                    $th = isset($it['threshold_used']) ? (float)$it['threshold_used'] : null;
                    if ($rid>0 && $ms!==null && $th!==null && $ms >= ($th - 0.05) && $ms < $th) return $rid;
                    return null;
                }, $rowMatchDbg), function($v){ return is_int($v) && $v>0; }));
                // Tag matches (exact + partial)
                $matchedIdsTagged = array_map('strval', $matchedIds);
                foreach ($nearIdsComputed as $nid){ $matchedIdsTagged[] = ((string)$nid).'_partial'; }
                // Surface partial near-match notes into partial summary for visibility
                if (!empty($nearIdsComputed)){
                    if (!isset($partial['chunk_summary']['notes'])) $partial['chunk_summary']['notes'] = [];
                    foreach ($nearIdsComputed as $nid){ $partial['chunk_summary']['notes'][] = 'partial_match_applied_id_'.(string)$nid; }
                }
                // Compute an ambiguity score (0..1) for telemetry only — no behavior change
                try {
                    $gap = isset($threshold) ? max(0.0, (float)$threshold - (float)($bestScore ?? 0.0)) : 0.0;
                    $gapNorm = min(1.0, $gap / 0.3);
                    $candCount = is_array($matchedIds) ? count($matchedIds) : 0;
                    $multi = ($candCount >= 3) ? 1.0 : (($candCount === 2) ? 0.5 : (($candCount === 1) ? 0.1 : 0.8));
                    $partialCount = is_array($nearIdsComputed) ? count($nearIdsComputed) : 0;
                    $partialFactor = ($partialCount >= 2) ? 0.6 : (($partialCount === 1) ? 0.3 : 0.0);
                    $ambiguityScore = max(0.0, min(1.0, 0.5*$gapNorm + 0.3*$multi + 0.2*$partialFactor));
                    $ambiguityScore = (float)round($ambiguityScore, 2);
                } catch (\Throwable $e) { $ambiguityScore = null; $candCount = is_array($matchedIds)?count($matchedIds):0; $partialCount = is_array($nearIdsComputed)?count($nearIdsComputed):0; }
                $aiDecision = [
                    'route' => 'server',
                    'reason' => 'scaffold_only',
                    'metrics' => [
                        'ambiguity_score' => $ambiguityScore,
                        'best_score' => ($bestScore ?? null),
                        'threshold_used' => (isset($threshold)?$threshold:null),
                        'matched_count' => $candCount,
                        'partial_count' => $partialCount,
                    ]
                ];
                // Read non-sensitive AI analysis config preview for debug/telemetry only
                $aiCfg = self::get_ai_analysis_config();
                // Build a safe subset preview (no raw data) for observability only
                $intent = 'generic';
                try {
                    $ql = function_exists('mb_strtolower') ? mb_strtolower((string)$question, 'UTF-8') : strtolower((string)$question);
                    if ($nameHint !== '') { $intent = 'person_mood'; }
                    elseif (preg_match('/(?:مقایسه|روند|compare|trend|میانگین|متوسط|گروه)/u', $ql)) { $intent = 'compare_trend'; }
                    elseif (preg_match('/(?:شماره|تلفن|موبایل|ایمیل|contact|phone|email)/ui', $ql)) { $intent = 'contact_info'; }
                } catch (\Throwable $e) { $intent = 'generic'; }
                try {
                    $colsWh = \Arshline\Support\AiSubsetPackager::columnWhitelist($intent, !empty($aiCfg['allow_pii']));
                } catch (\Throwable $e) { $colsWh = ['name','created_at']; }
                $rowsTotal = is_array($rows) ? count($rows) : 0;
                $rowsCap = (int)min(max(0, $rowsTotal), (int)($aiCfg['max_rows'] ?? 400));
                $dbgBasic = [
                    'phase' => 'chunk',
                    'chunk_index' => $chunk_index,
                    'page' => $page,
                    'per_page' => $useChunk,
                    'rows' => count($rows),
                    'row_ids' => $sliceIds,
                    'candidate_row_ids' => $idsForCsv,
                    'matched_row_ids' => $matchedIds,
                    'partial_match_row_ids' => $nearIdsComputed,
                    'matched_ids_tagged' => $matchedIdsTagged,
                    'filtered_by_name' => $filteredByName,
                    'name_threshold' => (isset($threshold) ? $threshold : null),
                    'best_match_id' => ($bestId??0),
                    'best_match_score' => ($bestScore??0.0),
                    'requested_person' => $nameHint,
                    'fallback_applied' => $fallbackApplied,
                    'fallback_row_id' => $fallbackRowId,
                    'fallback_reason' => $fallbackReason,
                    'ambiguity_score' => $ambiguityScore,
                    'ai_decision' => $aiDecision,
                    'ai_config' => $aiCfg,
                    'subset_preview' => [
                        'intent' => $intent,
                        'columns' => array_values($colsWh),
                        'row_count_total' => $rowsTotal,
                        'row_count_capped' => $rowsCap,
                    ],
                    'observability' => [
                        'route' => 'server',
                        'duration_ms' => (int)round(($t1-$t0)*1000),
                        'usage' => $usage,
                    ],
                    'headers_canonical' => $headersCanonical,
                    'canonical_map' => $canonicalMap,
                    'duration_ms' => (int)round(($t1-$t0)*1000),
                    'json_repaired' => $repaired,
                    'http_status' => $status,
                    'applied_fields' => $reqRelevant,
                    'usage' => $usage,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ]
                ];
                if ($debugLocal){
                    // Safe request preview: truncate system; show user preview with headers + counts (no raw CSV)
                    $sysPrev = (strlen($sys)>480? (substr($sys,0,480).'…[truncated]') : $sys);
                    $userPrev = [ 'question'=>$question, 'field_roles'=>$field_roles, 'applied_fields'=>$reqRelevant, 'headers_used'=>$headersCanonical, 'canonical_map'=>$canonicalMap, 'name_hint'=>$nameHint, 'entities'=>$reqEntities, 'csv_header'=> (isset($rowsCsv[0]) ? $rowsCsv[0] : ''), 'row_count'=>count($rows) ];
                    // Also promote canonical mapping to top-level debug for easier client access
                    $dbgEnriched = $dbgBasic;
                    $dbgEnriched['near_match_row_ids'] = $nearIdsComputed;
                    $dbgEnriched['row_match_debug'] = $rowMatchDbg;
                    $dbgEnriched['request_preview'] = [ 'model'=>$modelName, 'max_tokens'=>$suggestedMaxTok, 'messages'=> [ ['role'=>'system','content'=>$sysPrev], ['role'=>'user','content'=>$userPrev] ] ];
                    $dbg[] = $dbgEnriched;
                } else {
                    $dbg[] = $dbgBasic;
                }
                // Emit an observation event for integrators (no-op if not hooked)
                if (function_exists('do_action')){
                    try { do_action('arshline_ai_observe', [ 'phase'=>'chunk', 'route'=>'server', 'ambiguity_score'=>$ambiguityScore, 'matched_count'=>$candCount, 'partial_count'=>$partialCount, 'duration_ms'=>(int)round(($t1-$t0)*1000), 'usage'=>$usage ]); } catch (\Throwable $e) { /* ignore */ }
                }
                // Surface fields_used to the top-level for convenience (fallback to canonical headers without id/created_at)
                $fieldsUsedTop = [];
                if (is_array($partial) && is_array($partial['fields_used'] ?? null)){
                    $fieldsUsedTop = array_values(array_map('strval', $partial['fields_used']));
                } else {
                    $fieldsUsedTop = array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; }));
                }
                return new WP_REST_Response([
                    'phase' => 'chunk',
                    'chunk_index' => $chunk_index,
                    'partial' => is_array($partial)? $partial : [ 'aggregations'=>new \stdClass(), 'partial_insights'=>[], 'partial_chart_data'=>[], 'outliers'=>[], 'fields_used'=>array_values(array_filter($headersCanonical, function($h){ return $h!=='id' && $h!=='created_at'; })), 'chunk_summary'=>[ 'row_count'=>$actualRowCount, 'notes'=>['No matching data found'] ] ],
                    // New: convenience mirrors for client summaries
                    'fields_used' => $fieldsUsedTop,
                    'headers_canonical' => $headersCanonical,
                    'canonical_map' => $canonicalMap,
                    'chunk_rows' => $actualRowCount,
                    'usage' => [],
                    'debug' => $dbg,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                    'session_id' => $session_id,
                ], 200);
            }
            if ($phase === 'final'){
                $partials = is_array($p['partials'] ?? null) ? $p['partials'] : [];
                // Aggregate requested_person and fallback info from partials
                $requestedPerson = '';
                $fallbackAppliedAny = false; $fallbackRowIds = [];
                try {
                    foreach (($partials ?: []) as $pt){
                        $meta = is_array($pt['meta'] ?? null) ? $pt['meta'] : [];
                        $rp = trim((string)($meta['requested_person'] ?? ''));
                        if ($requestedPerson === '' && $rp !== ''){ $requestedPerson = $rp; }
                        if (!empty($meta['fallback_applied'])){ $fallbackAppliedAny = true; }
                        $fr = (int)($meta['fallback_row_id'] ?? 0); if ($fr > 0){ $fallbackRowIds[] = $fr; }
                    }
                    $fallbackRowIds = array_values(array_unique(array_filter($fallbackRowIds, function($v){ return (int)$v>0; })));
                } catch (\Throwable $e) { /* ignore */ }
                // Compose final merge request with summaries only
                $mergeIn = [ 'question'=>$question, 'partials'=>$partials, 'requested_person'=>$requestedPerson, 'fallback_info'=>[ 'applied'=>$fallbackAppliedAny, 'row_ids'=>$fallbackRowIds ] ];
                $sys = 'You are Hoshang. Merge the provided partial chunk analytics. If the user asked about a person\'s mood/wellbeing, synthesize an analysis by combining textual mood descriptions and numeric ratings (1–10).'
                    . ' If the user asked for contact info like phone/mobile, aggregate unique name/phone pairs across partials and summarize them briefly.'
                    . ' Accept partial name matches and prefer the most recent row by created_at when multiple matches exist.'
                    . ' If requested_person is provided in the user content, ALWAYS use that exact person name in the answer text (do not replace it with a different row name). If a different row name appears as source_row_name in insights, you may mention it briefly in parentheses.'
                    . ' Do NOT paste verbatim long texts from the data. Provide a concise, high-signal Persian answer (≤ 2 جمله).'
                    . ' Output STRICT JSON: {"answer":"<fa>","fields_used":[],"aggregations":{},"chart_data":[],"outliers":[],"insights":[],"confidence":"high|medium|low"}. JSON only.';
                $msgs = [ [ 'role'=>'system','content'=>$sys ], [ 'role'=>'user','content'=> wp_json_encode($mergeIn, JSON_UNESCAPED_UNICODE) ] ];
                $modelName = $use_model; if (preg_match('/mini/i', (string)$modelName)) $modelName = 'gpt-4o';
                $req = [ 'model'=>$modelName, 'messages'=>$msgs, 'temperature'=>0.2, 'max_tokens'=> min(1200, max(600, $max_tokens)) ];
                $t0f = microtime(true);
                $r = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=> wp_json_encode($req) ]);
                $status = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
                $raw = is_wp_error($r) ? ($r->get_error_message() ?: '') : (string)wp_remote_retrieve_body($r);
                $ok = ($status === 200);
                $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                $usage = is_array($body) && isset($body['usage']) ? $body['usage'] : null;
                $durationFinalMs = (int)round((microtime(true)-$t0f)*1000);
                $text = '';
                if (is_array($body)){
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])) $text = (string)$body['choices'][0]['message']['content'];
                    elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) $text = (string)$body['choices'][0]['text'];
                    elseif (isset($body['output_text']) && is_string($body['output_text'])) $text = (string)$body['output_text'];
                }
                $final = $text ? json_decode($text, true) : null;
                $repaired = false;
                if (!is_array($final)){
                    // Repair
                    $repairSys = 'Fix to VALID JSON only with keys: answer, fields_used, aggregations, chart_data, outliers, insights, confidence.';
                    $repairMsgs = [ [ 'role'=>'system','content'=>$repairSys ], [ 'role'=>'user','content'=>$text ] ];
                    $repairReq = [ 'model' => (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini'), 'messages'=>$repairMsgs, 'temperature'=>0.0, 'max_tokens'=>400 ];
                    $r2 = wp_remote_post($endpoint, [ 'timeout'=>20, 'headers'=>$headers, 'body'=> wp_json_encode($repairReq) ]);
                    $raw2 = is_wp_error($r2) ? '' : (string)wp_remote_retrieve_body($r2);
                    $b2 = json_decode($raw2, true);
                    $txt2 = '';
                    if (is_array($b2)){
                        if (isset($b2['choices'][0]['message']['content']) && is_string($b2['choices'][0]['message']['content'])) $txt2 = (string)$b2['choices'][0]['message']['content'];
                        elseif (isset($b2['choices'][0]['text']) && is_string($b2['choices'][0]['text'])) $txt2 = (string)$b2['choices'][0]['text'];
                        elseif (isset($b2['output_text']) && is_string($b2['output_text'])) $txt2 = (string)$b2['output_text'];
                    }
                    $final = $txt2 ? json_decode($txt2, true) : null;
                    if (is_array($final)) $repaired = true;
                }
                // Compute diagnostics early (candidate rows and matched columns)
                $candidateRows = 0; $matchedColumns = [];
                try {
                    foreach (($partials ?: []) as $p0){
                        $rc = (int)($p0['chunk_summary']['row_count'] ?? 0); $candidateRows += $rc;
                        $fields = is_array($p0['fields_used'] ?? null) ? $p0['fields_used'] : [];
                        foreach ($fields as $f){ if (is_string($f) && $f !== '' && !in_array($f, $matchedColumns, true)) $matchedColumns[] = $f; }
                    }
                } catch (\Throwable $e) { }
                // Minimal final debug always
                $dbg = [];
                // Compute ambiguity score across partials for telemetry only
                try {
                    $bestScores = [];
                    $thresholds = [];
                    $matchedCounts = 0; $partialCounts = 0;
                    foreach (($partials ?: []) as $pt){
                        $dbgl = is_array($pt['debug'] ?? null) ? $pt['debug'] : [];
                        foreach ($dbgl as $d0){
                            if (isset($d0['best_match_score'])) $bestScores[] = (float)$d0['best_match_score'];
                            if (isset($d0['threshold_used'])) $thresholds[] = (float)$d0['threshold_used'];
                            if (isset($d0['matched_row_ids']) && is_array($d0['matched_row_ids'])) $matchedCounts += count($d0['matched_row_ids']);
                            if (isset($d0['partial_match_row_ids']) && is_array($d0['partial_match_row_ids'])) $partialCounts += count($d0['partial_match_row_ids']);
                        }
                    }
                    $bestAvg = !empty($bestScores) ? array_sum($bestScores)/count($bestScores) : 0.0;
                    $thrAvg = !empty($thresholds) ? array_sum($thresholds)/count($thresholds) : 0.0;
                    $gap = max(0.0, $thrAvg - $bestAvg);
                    $gapNorm = min(1.0, $gap / 0.3);
                    $multi = ($matchedCounts >= 6) ? 1.0 : (($matchedCounts >= 3) ? 0.6 : (($matchedCounts >= 1) ? 0.2 : 0.8));
                    $partialFactor = ($partialCounts >= 4) ? 0.6 : (($partialCounts >= 1) ? 0.3 : 0.0);
                    $ambFinal = max(0.0, min(1.0, 0.5*$gapNorm + 0.3*$multi + 0.2*$partialFactor));
                    $ambFinal = (float)round($ambFinal, 2);
                } catch (\Throwable $e) { $ambFinal = null; }
                $aiDecisionFinal = [
                    'route' => 'server',
                    'reason' => 'scaffold_only',
                    'metrics' => [
                        'ambiguity_score' => $ambFinal,
                        'partials_count' => count($partials),
                        'candidate_rows' => null,
                    ]
                ];
                // Read non-sensitive AI analysis config for debug/telemetry
                $aiCfgF = self::get_ai_analysis_config();
                $dbgBasicFinal = [
                    'phase' => 'final',
                    'partials_count' => count($partials),
                    'requested_person' => $requestedPerson,
                    'fallback_applied' => $fallbackAppliedAny,
                    'fallback_row_ids' => $fallbackRowIds,
                    'candidate_rows' => $candidateRows,
                    'matched_columns' => $matchedColumns,
                    'ambiguity_score' => $ambFinal,
                    'ai_decision' => $aiDecisionFinal,
                    'ai_config' => $aiCfgF,
                    'observability' => [
                        'route' => 'server',
                        'duration_ms' => $durationFinalMs,
                        'usage' => $usage,
                    ],
                    'json_repaired' => $repaired,
                    'http_status' => $status,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ]
                ];
                if ($debug){
                    // Safe request preview
                    $sysPrev = (strlen($sys)>480? (substr($sys,0,480).'…[truncated]') : $sys);
                    $userPrev = [ 'question'=>$question, 'partials_count'=>count($partials) ];
                    $dbgBasicFinal['usage'] = $usage;
                    $dbgBasicFinal['request_preview'] = [ 'model'=>$modelName, 'max_tokens'=> min(1200, max(600, $max_tokens)), 'messages'=> [ ['role'=>'system','content'=>$sysPrev], ['role'=>'user','content'=>$userPrev] ] ];
                }
                $res = is_array($final)? $final : [ 'answer'=>'تحلیلی یافت نشد.', 'fields_used'=>[], 'aggregations'=>new \stdClass(), 'chart_data'=>[], 'outliers'=>[], 'insights'=>[], 'confidence'=>'low' ];
                // Add final notes and possible adjustments based on partial/fallback tagging
                $hasPartial = false;
                try {
                    foreach (($partials?:[]) as $pt){
                        // look in notes or debug matched_ids_tagged
                        $notes0 = is_array($pt['chunk_summary']['notes'] ?? null) ? $pt['chunk_summary']['notes'] : [];
                        foreach ($notes0 as $n0){ if (is_string($n0) && strpos($n0, 'partial_match_applied_id_') === 0){ $hasPartial = true; break; } }
                        if ($hasPartial) break;
                        $dbg0 = is_array($pt['debug'] ?? null) ? $pt['debug'] : [];
                        foreach ($dbg0 as $d0){
                            $tags = is_array($d0['matched_ids_tagged'] ?? null) ? $d0['matched_ids_tagged'] : [];
                            foreach ($tags as $tg){ if (is_string($tg) && strpos($tg, '_partial') !== false){ $hasPartial = true; break 2; } }
                        }
                    }
                } catch (\Throwable $e) { }
                $finalNotes = [];
                if ($hasPartial) $finalNotes[] = 'answer_adjusted_for_partial';
                if ($fallbackAppliedAny) $finalNotes[] = 'answer_context_fallback_seen';
                // Optionally adjust clearly empty answers into a deterministic Persian line
                $ansStr = is_string($res['answer'] ?? null) ? trim((string)$res['answer']) : '';
                if ($ansStr === '' || mb_strpos($ansStr, 'تحلیلی یافت نشد', 0, 'UTF-8') !== false || mb_strpos($ansStr, 'اطلاعاتی موجود نیست', 0, 'UTF-8') !== false){
                    if ($requestedPerson !== '' && $candidateRows > 0){
                        if ($hasPartial){ $res['answer'] = 'تحلیل جزئی برای ' . $requestedPerson . ' یافت شد، اما برای نتیجهگیری قطعی کافی نیست.'; }
                        elseif ($fallbackAppliedAny){ $res['answer'] = 'اطلاعاتی برای تحلیل مستقیم ' . $requestedPerson . ' یافت نشد؛ نزدیکترین داده بررسی شد.'; }
                        else { $res['answer'] = 'اطلاعاتی برای تحلیل حال ' . $requestedPerson . ' موجود نیست.'; }
                    }
                }
                if (!empty($finalNotes)) $dbgBasicFinal['final_notes'] = $finalNotes;
                $dbg[] = $dbgBasicFinal;
                $diagnostics = null;
                if ($candidateRows === 0){ $diagnostics = [ 'candidate_rows' => 0, 'matched_columns' => $matchedColumns ]; }
                // Emit observation hook for integrators
                if (function_exists('do_action')){
                    try { do_action('arshline_ai_observe', [ 'phase'=>'final', 'route'=>'server', 'ambiguity_score'=>$ambFinal, 'partials_count'=>count($partials), 'candidate_rows'=>$candidateRows, 'duration_ms'=>$durationFinalMs, 'usage'=>$usage ]); } catch (\Throwable $e) { /* ignore */ }
                }
                return new WP_REST_Response([
                    'phase' => 'final',
                    'result' => $res,
                    'diagnostics' => $diagnostics,
                    'usage' => [],
                    'debug' => $dbg,
                    'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                    'session_id' => $session_id,
                ], 200);
            }
            return new WP_REST_Response([ 'error'=>'invalid_phase' ], 400);
        }
        // Fast path: structured JSON mode — single-call analysis with strict JSON output (legacy)
        if ($isStructured) {
            // Restrict to the (only) selected form (enforced earlier)
            $fid = $form_ids[0];
            // Fetch up to max_rows for a single grounded CSV
            $rowsAll = \Arshline\Modules\Forms\SubmissionRepository::listByFormAll($fid, [], $max_rows);
            if (empty($rowsAll)){
                $payloadOut = [ 'result' => [ 'answer' => 'No matching data found.', 'fields_used'=>[], 'aggregations'=>new \stdClass(), 'chart_data'=>[], 'confidence'=>'low' ], 'summary' => 'No matching data found.', 'usage' => [], 'voice' => $voice, 'session_id' => $session_id ];
                return new WP_REST_Response($payloadOut, 200);
            }
            // Build fields meta
            $fmeta = [];
            try {
                $fieldsForMeta = \Arshline\Modules\Forms\FieldRepository::listByForm($fid);
                foreach (($fieldsForMeta ?: []) as $f){
                    $p0 = is_array($f['props'] ?? null) ? $f['props'] : [];
                    $label0 = (string)($p0['question'] ?? $p0['label'] ?? $p0['title'] ?? $p0['name'] ?? '');
                    $type0 = (string)($p0['type'] ?? '');
                    $fmeta[] = [ 'id' => (int)($f['id'] ?? 0), 'label' => $label0, 'type' => $type0 ];
                }
            } catch (\Throwable $e) { /* ignore */ }
            // Compose a single table CSV grounding across rows (with optional pre-filter via LLM planning)
            $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $rowsAll), function($v){ return $v>0; }));
            $valuesMap = \Arshline\Modules\Forms\SubmissionRepository::listValuesBySubmissionIds($sliceIds);

            // Lightweight server-side name disambiguation (clarify candidates)
            $clarify = null;
            try {
                // Detect name-like field ids
                $nameFieldIds = [];
                foreach ($fmeta as $fm){
                    $lab = mb_strtolower((string)($fm['label'] ?? ''), 'UTF-8');
                    if ($lab === '') continue;
                    if (preg_match('/\bname\b|first\s*name|last\s*name|full\s*name|surname|family/i', $lab)
                        || preg_match('/نام(?:\s*خانوادگی)?|اسم/u', $lab)){
                        $nameFieldIds[] = (int)($fm['id'] ?? 0);
                    }
                }
                $nameFieldIds = array_values(array_unique(array_filter($nameFieldIds, function($v){ return $v>0; })));
                // Build distinct names from filtered ids for CSV (or all slice ids if not filtered)
                $idsForClar = !empty($filteredIds) ? $filteredIds : $sliceIds;
                $normalize = function($s){
                    $s = is_scalar($s) ? (string)$s : '';
                    $s = str_replace(["\xE2\x80\x8C"], [''], $s); // ZWNJ
                    $s = str_replace(["ي","ك"],["ی","ک"], $s);
                    $s = preg_replace('/\s+/u',' ', $s);
                    return trim(mb_strtolower($s, 'UTF-8'));
                };
                $distinctNames = [];
                if (!empty($nameFieldIds)){
                    foreach ($idsForClar as $sid){
                        $vals = $valuesMap[$sid] ?? [];
                        $byField = [];
                        foreach ($vals as $v){ $fidv=(int)($v['field_id'] ?? 0); if (!in_array($fidv, $nameFieldIds, true)) continue; $val=(string)($v['value'] ?? ''); if ($val==='') continue; $byField[$fidv][] = $val; }
                        foreach ($byField as $arr){
                            foreach ($arr as $raw){
                                // split by common joiner
                                $parts = array_map('trim', explode('|', str_replace(' | ', '|', $raw)));
                                foreach ($parts as $p){ if ($p==='') continue; $n=$normalize($p); if ($n!==''){ $distinctNames[$n] = $p; } }
                            }
                        }
                    }
                }
                // Extract candidate token from question
                $qtext = $question;
                $qnorm = $normalize($qtext);
                // pick the longest token >= 2 not a common stopword
                $tok = '';
                if (preg_match_all('/[\p{L}]{2,}/u', $qnorm, $mm)){
                    $cands = $mm[0] ?? [];
                    $stops = ['حال','چطوره','هست','چه','روند','امتیاز','میانگین','اسامی','اسم','نام','شماره','تلفن','نمره','امروز','اوضاع','احوال','چقدر','کد','ملی','فرم','پاسخ','ارسال'];
                    $best = '';
                    foreach ($cands as $w){ if (mb_strlen($w,'UTF-8')>=2 && !in_array($w, $stops, true)){ if (mb_strlen($w,'UTF-8') > mb_strlen($best,'UTF-8')) $best=$w; } }
                    $tok = $best;
                }
                if ($tok !== '' && count($distinctNames) > 0){
                    $hits = [];
                    foreach ($distinctNames as $n => $orig){ if (strpos($n, $tok) !== false) $hits[$n] = $orig; }
                    // If multiple candidates (2..6) and no exact single match, propose clarify
                    $count = count($hits);
                    if ($count >= 2 && $count <= 6){ $clarify = [ 'type' => 'name', 'candidates' => array_values(array_unique(array_slice(array_values($hits), 0, 6))) ]; }
                }
            } catch (\Throwable $e) { /* ignore clarify errors */ }

            // Two-stage planning (LLM-guided light filtering) to reduce grounding size and improve precision
            $filteredIds = $sliceIds; $planObj = null; $planUsage = null; $planningApplied = false; $planningModel = '';
            try {
                // Build a tiny planning prompt to extract filters and target fields
                $planSys = 'You are Hoshang planning assistant. Task: Given Persian question and fields_meta, return a strict JSON plan to FILTER rows (no analysis). Rules:\n'
                    . '1) Output JSON ONLY, no text. Keys in English.\n'
                    . '2) Detect name/phone/mood and other field intents from synonyms.\n'
                    . '3) Plan schema: { "filters": [{"field":"<best label>", "op":"contains", "value":"..."}], "columns_needed": ["label1","label2"], "target_fields": ["label?/semantic" ] }.\n'
                    . '4) Use simple contains filters with normalized Persian (ی/ي, ک/ك, remove ZWNJ) and case-insensitive.\n'
                    . '5) Do NOT answer the question.\n';
                $planUser = [ 'question' => $question, 'fields_meta' => $fmeta ];
                $planMsgs = [ [ 'role'=>'system','content'=>$planSys ], [ 'role'=>'user', 'content' => json_encode($planUser, JSON_UNESCAPED_UNICODE) ] ];
                // Prefer a mini model for planning when available
                $planningModel = (preg_match('/mini/i', $use_model) ? $use_model : 'gpt-4o-mini');
                // Fallback to current model if mini may be unavailable
                if ($planningModel === 'gpt-4o-mini' && $use_model === 'gpt-4o-mini') { /* same */ }
                $makePlanReq = function($modelName) use ($endpoint, $http_timeout, $headers, $planMsgs){
                    $pl = [ 'model'=>$modelName, 'messages'=>$planMsgs, 'temperature'=>0.0, 'max_tokens'=>300 ];
                    $plJson = wp_json_encode($pl);
                    $t0 = microtime(true);
                    $resp = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$plJson ]);
                    $status = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
                    $raw = is_wp_error($resp) ? ($resp->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp);
                    $ok = ($status === 200);
                    $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                    $u = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms' => (int) round((microtime(true)-$t0)*1000) ];
                    return [ $ok, $status, $raw, $body, $u, $plJson ];
                };
                [ $pok, $pstatus, $praw, $pbody, $pusage, $pjson ] = $makePlanReq($planningModel);
                if (!$pok && in_array((int)$pstatus, [400,404,422], true) && $planningModel !== $use_model){
                    [ $pok2, $pstatus2, $praw2, $pbody2, $pusage2, $pjson2 ] = $makePlanReq($use_model);
                    if ($pok2){ $pok=true; $pstatus=$pstatus2; $praw=$praw2; $pbody=$pbody2; $pusage=$pusage2; $pjson=$pjson2; $planningModel=$use_model; }
                }
                // Extract plan text
                $planText = '';
                if (is_array($pbody)){
                    try {
                        if (isset($pbody['choices'][0]['message']['content']) && is_string($pbody['choices'][0]['message']['content'])){
                            $planText = (string)$pbody['choices'][0]['message']['content'];
                        } elseif (isset($pbody['choices'][0]['text']) && is_string($pbody['choices'][0]['text'])){
                            $planText = (string)$pbody['choices'][0]['text'];
                        } elseif (isset($pbody['output_text']) && is_string($pbody['output_text'])){
                            $planText = (string)$pbody['output_text'];
                        }
                    } catch (\Throwable $e) { $planText=''; }
                }
                $planDecoded = $planText ? json_decode($planText, true) : null;
                if (is_array($planDecoded)){
                    $planObj = $planDecoded; $planUsage = $pusage;
                    // Minimal light filtering: supports only op=contains over selected columns
                    $filters = is_array($planObj['filters'] ?? null) ? $planObj['filters'] : [];
                    // Build normalized label => field_ids map (labels may repeat across forms, but here only one form)
                    $normalize = function($s){
                        $s = is_scalar($s) ? (string)$s : '';
                        $s = str_replace(["\xE2\x80\x8C"], [''], $s); // ZWNJ
                        $s = str_replace(["ي","ك"],["ی","ک"], $s);
                        $s = preg_replace('/\s+/u',' ', $s);
                        $s = trim(mb_strtolower($s, 'UTF-8'));
                        return $s;
                    };
                    $labelToIds = [];
                    foreach ($fmeta as $fm){ $lab = (string)($fm['label'] ?? ''); $fidm = (int)($fm['id'] ?? 0); if ($lab===''||$fidm<=0) continue; $labN = $normalize($lab); if (!isset($labelToIds[$labN])) $labelToIds[$labN] = []; $labelToIds[$labN][] = $fidm; }
                    $activeFilters = [];
                    foreach ($filters as $flt){
                        $flab = $normalize($flt['field'] ?? ''); $val = $normalize($flt['value'] ?? ''); $op = strtolower((string)($flt['op'] ?? 'contains'));
                        if ($flab !== '' && $val !== '' && ($op === 'contains')){
                            $fids = $labelToIds[$flab] ?? [];
                            if (!empty($fids)) $activeFilters[] = [ 'field_ids'=>$fids, 'value'=>$val ];
                        }
                    }
                    if (!empty($activeFilters)){
                        $keep = [];
                        foreach ($sliceIds as $sid){
                            $vals = $valuesMap[$sid] ?? [];
                            $byField = [];
                            foreach ($vals as $v){ $fidv=(int)($v['field_id'] ?? 0); $val=(string)($v['value'] ?? ''); if ($fidv>0){ if (!isset($byField[$fidv])) $byField[$fidv] = []; if ($val!=='') $byField[$fidv][] = $val; } }
                            $okAll = true;
                            foreach ($activeFilters as $af){
                                $hit = false;
                                foreach ($af['field_ids'] as $fidNeed){
                                    $cell = isset($byField[$fidNeed]) ? implode(' | ', $byField[$fidNeed]) : '';
                                    $cellN = $normalize($cell);
                                    if ($cellN !== '' && strpos($cellN, (string)$af['value']) !== false){ $hit = true; break; }
                                }
                                if (!$hit){ $okAll = false; break; }
                            }
                            if ($okAll) $keep[] = $sid;
                        }
                        if (!empty($keep)){
                            $filteredIds = $keep; $planningApplied = true;
                        }
                    }
                }
            } catch (\Throwable $e) { /* planning step optional; ignore errors */ }
            // Header labels
            $labels = [];
            $idToLabel = [];
            foreach ($fmeta as $fm){
                $fidm = (int)($fm['id'] ?? 0);
                $labm = (string)($fm['label'] ?? '');
                if ($labm === '') { $labm = 'فیلد #' . $fidm; }
                $idToLabel[$fidm] = $labm;
                $labels[] = $labm;
            }
            $rowsCsv = [];
            $idsForCsv = !empty($filteredIds) ? $filteredIds : $sliceIds;
            if (!empty($labels)){
                $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $labels));
                foreach ($idsForCsv as $sid){
                    $vals = $valuesMap[$sid] ?? [];
                    $map = [];
                    foreach ($vals as $v){
                        $fidv = (int)($v['field_id'] ?? 0);
                        $lab = (string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab = 'فیلد #'.$fidv;
                        $val = trim((string)($v['value'] ?? ''));
                        if (!isset($map[$lab])) $map[$lab] = [];
                        if ($val !== '') $map[$lab][] = $val;
                    }
                    $rowsCsv[] = implode(',', array_map(function($h) use ($map){ $v = isset($map[$h]) ? implode(' | ', $map[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $labels));
                }
            }
            $tableCsv = implode("\r\n", $rowsCsv);
            // Heuristic model selection for cost vs depth
            $qLower = mb_strtolower($question, 'UTF-8');
            $isHeavy = (bool)(
                preg_match('/\b(compare|correlat|trend|distribution|variance|std|median|quartile|regression|cluster|segment|chart|bar|pie|line)\b/i', $qLower)
                || preg_match('/(?:مقایسه|همبستگی|روند|میانگین|میانه|نمودار|نمودار(?:\s*میله|\s*دایره|\s*خط)|واریانس|انحراف\s*معیار)/u', $qLower)
            );
            $use_model_struct = $use_model;
            if ($isHeavy) {
                // If configured model looks like a mini/cheap variant, upgrade to gpt-4o for this call
                if (preg_match('/mini|3\.5|4o\-mini/i', (string)$use_model_struct)){
                    $use_model_struct = 'gpt-4o';
                }
            }
            // Build structured system prompt
            $sys = 'You are Hoshang, a Persian analytics assistant. Strict rules:\n'
                . '1) Map column labels to semantic concepts when needed. Examples (not exhaustive):\n'
                . '   - name: "نام"، "اسم"، "نام و نام خانوادگی"، "first name"، "last name"، "full name".\n'
                . '   - phone: "شماره تلفن"، "شماره تماس"، "موبایل"، "تلفن"، "تلفن همراه"، "mobile"، "phone".\n'
                . '   - mood_text: "امروز اوضاع و احوالتون چطوره"، "حال و احوال"، "حال"، "روحیه".\n'
                . '   - mood_score: "به حال دلت چه امتیازی میدی"، "امتیاز حال دل"، "نمره حال".\n'
                . '2) Normalize Persian text when matching: treat ی/ي and ک/ك as the same; remove zero-width joiners (U+200C) and diacritics; ignore punctuation and extra spaces; match case-insensitively; allow partial substring matches.\n'
                . '3) When searching by a person/entity name in the question (e.g., "نیما"), locate rows where any name-like column contains that name (after normalization), then extract the requested field (e.g., phone, mood) from the same row.\n'
                . '   - Table cells may contain multiple values joined by a delimiter like " | "; split and inspect each value.\n'
                . '   - For phone answers: extract and return the phone number digits for the best-matching row.\n'
                . '4) Interpret the question and link it to the most relevant fields; perform comparisons, summaries, and basic aggregations only from provided data.\n'
                . '5) Never hallucinate values; if insufficient data: return answer="No matching data found."\n'
                . '6) Return JSON ONLY (no markdown/text outside JSON). Keys in English. Values/text (answer) in Persian.\n'
                . '7) If charts are implied, include minimal chart_data array of objects (e.g., name/label and value/score).\n'
                . '8) Keep outputs concise.';
            $messages = [ [ 'role' => 'system', 'content' => $sys ] ];
            foreach ($history as $h){ $messages[] = $h; }
            $payloadUser = [
                'question' => $question,
                'form_id' => $fid,
                'data_format' => 'table',
                'table_csv' => $tableCsv,
                'fields_meta' => $fmeta,
                // Define desired JSON schema explicitly
                'output_schema' => [
                    'answer' => 'string (Persian)',
                    'fields_used' => ['string'],
                    'aggregations' => new \stdClass(),
                    'chart_data' => [ new \stdClass() ],
                    'confidence' => 'low|medium|high'
                ]
            ];
            $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
            $payload = [ 'model' => $use_model_struct, 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $max_tokens ];
            $payloadJson = wp_json_encode($payload);
            if ($debug){
                $prevMsgs = [];
                foreach ($messages as $m){ $c=(string)($m['content'] ?? ''); if (strlen($c)>1800) $c=substr($c,0,1800)."\n…[truncated]"; $prevMsgs[] = [ 'role'=>$m['role'] ?? '', 'content'=>$c ]; }
                $dbgEntry = [ 'form_id'=>$fid, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$use_model_struct, 'max_tokens'=>$max_tokens, 'temperature'=>0.2, 'messages'=>$prevMsgs ], 'routing' => [ 'hosh_mode'=>$hoshMode, 'structured'=>true, 'auto'=>$autoStructured, 'trigger'=>$structTrigger ] ];
                if ($planningApplied || $planObj){ $dbgEntry['planning'] = [ 'applied' => (bool)$planningApplied, 'model' => (string)$planningModel, 'plan' => $planObj, 'filtered_rows' => is_array($idsForCsv)? count($idsForCsv) : 0, 'total_rows' => count($sliceIds) ]; }
                $debugInfo[] = $dbgEntry;
            }
            // Perform request with 1-shot fallback to gpt-4o on 400/404/422
            $makeReq = function($modelName) use ($endpoint, $http_timeout, $headers, $messages, $max_tokens){
                $pl = [ 'model'=>$modelName, 'messages'=>$messages, 'temperature'=>0.2, 'max_tokens'=>$max_tokens ];
                $plJson = wp_json_encode($pl);
                $t0 = microtime(true);
                $resp = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$plJson ]);
                $status = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
                $raw = is_wp_error($resp) ? ($resp->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp);
                $ok = ($status === 200);
                $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                $usage = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms' => (int) round((microtime(true)-$t0)*1000) ];
                return [ $ok, $status, $raw, $body, $usage, $plJson ];
            };
            [ $ok, $status, $rawBody, $body, $usage, $plJson ] = $makeReq($use_model_struct);
            $finalModel = $use_model_struct;
            if (!$ok && in_array((int)$status, [400,404,422], true) && $use_model_struct !== 'gpt-4o'){
                [ $ok2, $status2, $rawBody2, $body2, $usage2, $plJson2 ] = $makeReq('gpt-4o');
                if ($ok2){ $ok=true; $status=$status2; $rawBody=$rawBody2; $body=$body2; $usage=$usage2; $plJson=$plJson2; $finalModel='gpt-4o'; }
            }
            // Extract text and usage
            $text = '';
            if (is_array($body)){
                try {
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])){
                        $text = (string)$body['choices'][0]['message']['content'];
                    } elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])){
                        $text = (string)$body['choices'][0]['text'];
                    } elseif (isset($body['output_text']) && is_string($body['output_text'])){
                        $text = (string)$body['output_text'];
                    }
                } catch (\Throwable $e) { $text=''; }
                $u = $body['usage'] ?? [];
                $in = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? ($u['input'] ?? 0));
                $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? ($u['output'] ?? 0));
                $tot = (int)($u['total_tokens'] ?? $u['total'] ?? 0);
                if ((!$in && !$out && !$tot) && is_array($body)){
                    // try headers
                    // Note: $resp isn’t available here; usage already has duration. Estimate instead.
                }
                if (!$in && !$out && !$tot){
                    $promptApprox = strlen((string)$plJson);
                    $compApprox = strlen((string)$text);
                    $in=(int)ceil($promptApprox/4); $out=(int)ceil($compApprox/4); $tot=$in+$out;
                }
                $usage['input']=$in; $usage['output']=$out; $usage['total']=$tot;
            }
            // Parse structured JSON from model content
            $result = null;
            if (is_string($text) && $text !== ''){
                $decoded = json_decode($text, true);
                if (is_array($decoded)){
                    $result = $decoded;
                } else {
                    // Stage 3: JSON repair mini-call — extract/fix JSON if model returned fenced or noisy content
                    $repairDbg = null;
                    try {
                        $repairModel = (preg_match('/mini/i', (string)$use_model_struct)) ? $use_model_struct : 'gpt-4o-mini';
                        $schema = [
                            'answer' => 'string (Persian)',
                            'fields_used' => ['string'],
                            'aggregations' => new \stdClass(),
                            'chart_data' => [ new \stdClass() ],
                            'confidence' => 'low|medium|high'
                        ];
                        $repSys = 'You are a strict JSON repair tool. Input may include markdown fences or surrounding text. Task: extract and FIX a single JSON object matching the expected schema (keys in English). Output ONLY the JSON with no backticks or commentary.';
                        $repUser = [ 'schema' => $schema, 'text' => (string)$text ];
                        $repMsgs = [ [ 'role'=>'system','content'=>$repSys ], [ 'role'=>'user','content'=>json_encode($repUser, JSON_UNESCAPED_UNICODE) ] ];
                        $makeRepairReq = function($modelName) use ($endpoint, $http_timeout, $headers, $repMsgs){
                            $pl = [ 'model'=>$modelName, 'messages'=>$repMsgs, 'temperature'=>0.0, 'max_tokens'=>600 ];
                            $plJson = wp_json_encode($pl);
                            $t0 = microtime(true);
                            $resp = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$plJson ]);
                            $status = is_wp_error($resp) ? 0 : (int)wp_remote_retrieve_response_code($resp);
                            $raw = is_wp_error($resp) ? ($resp->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp);
                            $ok = ($status === 200);
                            $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                            $usage = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=>(int)round((microtime(true)-$t0)*1000) ];
                            return [ $ok, $status, $raw, $body, $usage, $plJson ];
                        };
                        [ $rok, $rstatus, $rraw, $rbody, $rusage, $rjson ] = $makeRepairReq($repairModel);
                        $repairFinalModel = $repairModel;
                        if (!$rok && in_array((int)$rstatus, [400,404,422], true) && $repairModel !== $use_model_struct){
                            [ $rok2, $rstatus2, $rraw2, $rbody2, $rusage2, $rjson2 ] = $makeRepairReq($use_model_struct);
                            if ($rok2){ $rok=true; $rstatus=$rstatus2; $rraw=$rraw2; $rbody=$rbody2; $rusage=$rusage2; $rjson=$rjson2; $repairFinalModel=$use_model_struct; }
                        }
                        $repaired = '';
                        if (is_array($rbody)){
                            try {
                                if (isset($rbody['choices'][0]['message']['content']) && is_string($rbody['choices'][0]['message']['content'])){ $repaired = (string)$rbody['choices'][0]['message']['content']; }
                                elseif (isset($rbody['choices'][0]['text']) && is_string($rbody['choices'][0]['text'])){ $repaired = (string)$rbody['choices'][0]['text']; }
                                elseif (isset($rbody['output_text']) && is_string($rbody['output_text'])){ $repaired = (string)$rbody['output_text']; }
                            } catch (\Throwable $e) { $repaired=''; }
                        }
                        $fixed = $repaired !== '' ? json_decode($repaired, true) : null;
                        if (is_array($fixed)){
                            $result = $fixed;
                            // Log repair usage separately
                            self::log_ai_usage('hoshang-repair', $repairFinalModel, $rusage, [ 'form_id'=>$fid, 'repair'=>1 ]);
                            if ($debug){
                                $repairDbg = [ 'model'=>$repairFinalModel, 'http_status'=>$rstatus, 'ok'=>true ];
                                try { $repairDbg['raw'] = (strlen((string)$rraw)>1200? substr((string)$rraw,0,1200)."\n…[truncated]" : (string)$rraw); } catch (\Throwable $e) { /* noop */ }
                            }
                        } else {
                            // Fallback: wrap original plain text into schema
                            $result = [ 'answer' => $text, 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
                            if ($debug){ $repairDbg = [ 'model'=>$repairModel, 'http_status'=>$rstatus, 'ok'=>false ]; }
                        }
                    } catch (\Throwable $e) {
                        // Final fallback when repair stage fails unexpectedly
                        $result = [ 'answer' => $text, 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
                    }
                }
            } else {
                $result = [ 'answer' => 'No matching data found.', 'fields_used' => [], 'aggregations' => new \stdClass(), 'chart_data' => [], 'confidence' => 'low' ];
            }
            // Attach clarify if available and not already present
            if (is_array($clarify) && empty($result['clarify'])){ $result['clarify'] = $clarify; }
            $summary = (string)($result['answer'] ?? '');
            // Log usage (include planning call if available) and persist assistant turn
            self::log_ai_usage($agentName, $finalModel, $usage, [ 'form_id'=>$fid, 'rows'=>count($rowsAll) ]);
            if (is_array($planUsage)){ self::log_ai_usage('hoshang-plan', $planningModel ?: $use_model, $planUsage, [ 'form_id'=>$fid, 'rows'=>count($rowsAll) ]); }
            $usages[] = [ 'form_id'=>$fid, 'usage'=>$usage ];
            try {
                if ($session_id > 0){
                    global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                    $wpdb->insert($tblMsg, [
                        'session_id' => $session_id,
                        'role' => 'assistant',
                        'content' => $summary,
                        'usage_input' => max(0, (int)$usage['input']),
                        'usage_output' => max(0, (int)$usage['output']),
                        'usage_total' => max(0, (int)$usage['total']),
                        'duration_ms' => max(0, (int)$usage['duration_ms']),
                        'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>'json', 'structured'=>true ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
                }
            } catch (\Throwable $e) { /* ignore */ }
            $respPayload = [ 'result' => $result, 'summary' => $summary, 'usage' => $usages, 'voice' => $voice, 'session_id' => $session_id, 'model' => $finalModel ];
            if ($debug){
                $dbg = [ 'form_id'=>$fid, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$finalModel, 'max_tokens'=>$max_tokens, 'temperature'=>0.2 ], 'final_model'=>$finalModel, 'routing' => [ 'hosh_mode'=>$hoshMode, 'structured'=>true, 'auto'=>$autoStructured, 'trigger'=>$structTrigger, 'auto_format'=>$autoFormat ] ];
                try { $dbg['raw'] = (strlen((string)$rawBody)>1800? substr((string)$rawBody,0,1800)."\n…[truncated]" : (string)$rawBody); } catch (\Throwable $e) { /* noop */ }
                if (!empty($clarify)) { $dbg['clarify'] = $clarify; }
                // Attach planning and optional repair meta when present
                $respPayload['debug'] = [ $dbg ];
            }
            return new WP_REST_Response($respPayload, 200);
        }
        foreach ($tables as $t){
            $rows = $t['rows']; $fid = $t['form_id'];
            // Simple chunking by N rows; we serialize minimally to reduce tokens
            for ($i=0; $i<count($rows); $i+=$chunk_size){
                $slice = array_slice($rows, $i, $chunk_size);
                // Fetch submission values in batch for this slice to ground the model
                $sliceIds = array_values(array_filter(array_map(function($r){ return (int)($r['id'] ?? 0); }, $slice), function($v){ return $v>0; }));
                $valuesMap = SubmissionRepository::listValuesBySubmissionIds($sliceIds);
                // Optionally build a tabular CSV (header=field labels, rows=submissions) to help the model
                $tableCsv = '';
                // Allow tabular grounding for all non-greeting questions to improve extraction (entity lookups, filters, etc.)
                // The system prompt forbids dumping raw CSV unless explicitly requested, so this is safe.
                $allowTableGrounding = ($format === 'table') || (!$isGreeting);
                if ($allowTableGrounding){
                    $labels = [];
                    $idToLabel = [];
                    foreach (($t['fields_meta'] ?? []) as $fm){
                        $fidm = (int)($fm['id'] ?? 0);
                        $labm = (string)($fm['label'] ?? '');
                        if ($labm === '') { $labm = 'فیلد #' . $fidm; }
                        $idToLabel[$fidm] = $labm;
                    }
                    $labels = array_values(array_map(function($fm){
                        $fidm = (int)($fm['id'] ?? 0);
                        $labm = (string)($fm['label'] ?? '');
                        return $labm !== '' ? $labm : ('فیلد #'.$fidm);
                    }, ($t['fields_meta'] ?? [])));
                    if (!empty($labels)){
                        $rowsCsv = [];
                        $rowsCsv[] = implode(',', array_map(function($h){ return '"'.str_replace('"','""',$h).'"'; }, $labels));
                        foreach ($sliceIds as $sid){
                            $vals = $valuesMap[$sid] ?? [];
                            $map = [];
                            foreach ($vals as $v){
                                $fidv = (int)($v['field_id'] ?? 0);
                                $lab = (string)($idToLabel[$fidv] ?? ''); if ($lab==='') $lab = 'فیلد #'.$fidv;
                                $val = trim((string)($v['value'] ?? ''));
                                if (!isset($map[$lab])) $map[$lab] = [];
                                if ($val !== '') $map[$lab][] = $val;
                            }
                            $rowsCsv[] = implode(',', array_map(function($h) use ($map){ $v = isset($map[$h]) ? implode(' | ', $map[$h]) : ''; return '"'.str_replace('"','""',$v).'"'; }, $labels));
                        }
                        $tableCsv = implode("\r\n", $rowsCsv);
                    }
                }

                // Build chat messages: system + history + current user payload (grounded data)
                $messages = [
                    [ 'role' => 'system', 'content' => 'You are a Persian-only answering model. Follow strictly:
1) پاسخ فقط و فقط بر اساس داده‌های همین فرم ارسالی (fields_meta, rows, values یا table_csv). هیچ دانش خارجی، مثال عمومی، یا حدس مجاز نیست.
2) زبان خروجی: فقط فارسی.
3) بدون مقدمه یا توضیح اضافی؛ فقط پاسخ مستقیم طبق قالب خواسته‌شده.
4) اگر پاسخ با اتکا به داده‌های فرم ممکن نیست، دقیقاً بنویس: «اطلاعات لازم در فرم پیدا نمی‌کنم».
5) اگر intent یا out_format داده شد، همان را رعایت کن (list_names, list_fields, list_field_values, field_value, show_all_compact_preview | list/table/plain).
6) اگر پرسش مبهم یا صرفاً خوش‌وبش/سلام بود، یک خوشامدگویی خیلی کوتاه بده و یک سؤال روشن‌کنندهٔ کوتاه بپرس؛ از نمایش خام داده‌ها یا جدول خودداری کن مگر کاربر صریحاً درخواست «نمایش/لیست/جدول» کرده باشد.
راهنمای جست‌وجوی مقدار:
- اگر سؤال شامل نام فرد/موجودیت بود (مثل «نیما»)، ستون‌های شبیه نام (label: نام/اسم/name/first/last/full) را پیدا کن و با تطبیق جزئی (case-insensitive) ردیف/ردیف‌های مرتبط را بیاب؛ سپس مقدار ستون مرتبط با سؤال را بازگو کن.
- توجه: واژهٔ «اسم» به‌تنهایی به معنی «نام شخص» نیست. فقط اگر نام خاصی در سؤال آمده باشد (مثلاً «نیما»)، از ستون‌های نام اشخاص استفاده کن. اگر عبارت «اسم X» آمده باشد (مثل «اسم میوه»)، منظور ستون «X» است نه نام شخص.
- اگر در سؤال به یک فیلد اشاره شد (مثل «میوه»)، از fields_meta برچسب‌های شامل آن واژه را پیدا کن (با نادیده‌گرفتن فاصله/HTML entities مثل &nbsp;) و از همان ستون مقدار را استخراج کن.
- خروجی را کوتاه و دقیق بنویس (مثلاً فقط نام میوه)، مگر explicitly «لیست/جدول» خواسته شده باشد.
راهنمای فرمت جدول:
- فقط وقتی data_format=table و کاربر واقعاً درخواست «لیست/نمایش/جدول» کرده، CSV را مبنای پاسخ قرار بده. هرگز CSV خام، هدرها یا داده‌ها را بدون درخواست صریح چاپ نکن؛ صرفاً خروجی خواسته‌شده را فشرده و کاربردی ارائه کن.
داده‌ها:
- fields_meta: [{id,label,type}]، rows: [{id,...}]، values: {submission_id: [{field_id,value}]}
'
                    ]
                ];
                if (!empty($history)){
                    foreach ($history as $h){ $messages[] = $h; }
                }
                // Derive intent with field-aware hints
                $intent = null;
                if ($isShowAllIntent || $isAnswersIntent) {
                    $intent = 'show_all_compact_preview';
                } elseif ($isFieldsIntent) {
                    $intent = 'list_fields';
                } elseif ($field_hint !== '' && $isListOut) {
                    $intent = 'list_field_values';
                } elseif ($field_hint !== '') {
                    $intent = 'field_value';
                } elseif ($isNamesIntent) {
                    $intent = 'list_names';
                }
                if ($allowTableGrounding && $tableCsv !== ''){
                    $payloadUser = [ 'question'=>$question, 'form_id'=>$fid, 'data_format'=>'table', 'table_csv'=>$tableCsv, 'fields_meta'=>$t['fields_meta'] ?? [] ];
                    if ($intent) { $payloadUser['intent'] = $intent; }
                    if ($out_format) { $payloadUser['out_format'] = $out_format; }
                    if ($field_hint !== '') { $payloadUser['field_hint'] = $field_hint; }
                    $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
                } else {
                    $payloadUser = [ 'question'=>$question, 'form_id'=>$fid, 'fields_meta'=>$t['fields_meta'] ?? [], 'rows'=>$slice, 'values'=>$valuesMap ];
                    if ($intent) { $payloadUser['intent'] = $intent; }
                    if ($out_format) { $payloadUser['out_format'] = $out_format; }
                    if ($field_hint !== '') { $payloadUser['field_hint'] = $field_hint; }
                    $messages[] = [ 'role' => 'user', 'content' => json_encode($payloadUser, JSON_UNESCAPED_UNICODE) ];
                }
                $payload = [
                    'model' => $use_model,
                    'messages' => $messages,
                    'temperature' => 0.2,
                    'max_tokens' => $max_tokens,
                ];
                $payloadJson = wp_json_encode($payload);
                $t0 = microtime(true);
                if ($debug){
                    // Attach a lightweight request preview (truncate large fields) for debugging
                    $prevMsgs = [];
                    foreach ($messages as $m){
                        $c = (string)($m['content'] ?? '');
                        if (strlen($c) > 1800) { $c = substr($c, 0, 1800) . "\n…[truncated]"; }
                        $prevMsgs[] = [ 'role'=>$m['role'] ?? '', 'content'=>$c ];
                    }
                    $debugInfo[] = [ 'form_id'=>$fid, 'chunk_index'=>$i, 'endpoint'=>$endpoint, 'request_preview'=>[ 'model'=>$use_model, 'max_tokens'=>$max_tokens, 'temperature'=>0.2, 'messages'=>$prevMsgs ] ];
                }
                $resp = wp_remote_post($endpoint, [ 'timeout'=> $http_timeout, 'headers'=>$headers, 'body'=> $payloadJson ]);
                $ok = is_array($resp) && !is_wp_error($resp) && (int)wp_remote_retrieve_response_code($resp) === 200;
                $rawBody = is_array($resp) ? (string)wp_remote_retrieve_body($resp) : '';
                $body = $ok ? json_decode($rawBody, true) : null;
                $text = '';
                $usage = [ 'input'=>0,'output'=>0,'total'=>0,'cost'=>null,'duration_ms'=> (int) round((microtime(true)-$t0)*1000) ];
                if (is_array($body)){
                    // Try to extract text from multiple common shapes
                    $text = '';
                    try {
                        if (isset($body['choices']) && is_array($body['choices']) && isset($body['choices'][0])){
                            $c0 = $body['choices'][0];
                            if (isset($c0['message']['content'])){
                                $mc = $c0['message']['content'];
                                if (is_string($mc)) { $text = (string)$mc; }
                                elseif (is_array($mc)) { // parts array
                                    $parts = [];
                                    foreach ($mc as $part){
                                        if (is_string($part)) $parts[] = $part;
                                        elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) $parts[] = $part['text'];
                                    }
                                    $text = trim(implode("\n", $parts));
                                }
                            } elseif (isset($c0['text']) && is_string($c0['text'])) {
                                $text = (string)$c0['text'];
                            }
                        }
                        if ($text === '' && isset($body['output_text']) && is_string($body['output_text'])) $text = (string)$body['output_text'];
                        if ($text === '' && isset($body['message']) && is_string($body['message'])) $text = (string)$body['message'];
                        if ($text === '' && isset($body['content']) && is_string($body['content'])) $text = (string)$body['content'];
                    } catch (\Throwable $e) { $text = ''; }
                    $u = $body['usage'] ?? [];
                    // Support multiple usage shapes (OpenAI-style and others)
                    $in = 0; $out = 0; $tot = 0;
                    if (is_array($u)){
                        $in = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? ($u['input'] ?? 0));
                        $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? ($u['output'] ?? 0));
                        $tot = (int)($u['total_tokens'] ?? $u['total'] ?? 0);
                        // Nested tokens object
                        if (!$in && is_array($u['tokens'] ?? null)){
                            $in = (int)($u['tokens']['input'] ?? 0);
                        }
                        if (!$out && is_array($u['tokens'] ?? null)){
                            $out = (int)($u['tokens']['output'] ?? 0);
                        }
                        if (!$tot && is_array($u['tokens'] ?? null)){
                            $tot = (int)($u['tokens']['total'] ?? 0);
                        }
                    }
                    // If body.usage missing or zeros, try headers (provider-specific)
                    if ((!$in && !$out && !$tot) && is_array($resp)){
                        $hdrs = wp_remote_retrieve_headers($resp);
                        $hdrsArr = [];
                        if (is_object($hdrs) && method_exists($hdrs, 'getAll')){ $hdrsArr = $hdrs->getAll(); }
                        elseif (is_array($hdrs)){ $hdrsArr = $hdrs; }
                        if (is_array($hdrsArr)){
                            foreach ($hdrsArr as $hk => $hv){
                                $k = strtolower((string)$hk); $v = is_array($hv)? implode(',', $hv) : (string)$hv;
                                if (!$in && preg_match('/(prompt|input).*tokens/', $k)) { $in = (int)preg_replace('/\D+/', '', $v); }
                                if (!$out && preg_match('/(completion|output).*tokens/', $k)) { $out = (int)preg_replace('/\D+/', '', $v); }
                                if (!$tot && preg_match('/total.*tokens/', $k)) { $tot = (int)preg_replace('/\D+/', '', $v); }
                            }
                        }
                    }
                    // As a very rough fallback, estimate tokens from characters (approx 4 chars per token)
                    if (!$in && !$out && !$tot){
                        $promptApprox = strlen((string)$payloadJson);
                        $compApprox = strlen($text);
                        $in = (int) ceil($promptApprox / 4);
                        $out = (int) ceil($compApprox / 4);
                        $tot = $in + $out;
                    }
                    $usage['input'] = max(0, $in);
                    $usage['output'] = max(0, $out);
                    $usage['total'] = max(0, $tot ?: ($usage['input'] + $usage['output']));
                }
                // If provider returned no body or usage still zero, estimate from payload/response text
                if ((!$usage['input'] && !$usage['output'] && !$usage['total'])){
                    $promptApprox = strlen((string)$payloadJson);
                    $compApprox = strlen((string)$text);
                    $usage['input'] = max(1, (int) ceil($promptApprox / 4));
                    $usage['output'] = max(0, (int) ceil($compApprox / 4));
                    $usage['total'] = $usage['input'] + $usage['output'];
                }
                if ($text === '' || !is_string($text)){
                    $text = 'اطلاعات لازم در فرم پیدا نمی‌کنم';
                }
                $answers[] = [ 'form_id'=>$fid, 'chunk'=> [ 'index'=>$i, 'size'=>count($slice) ], 'text'=>$text ];
                // Log usage
                self::log_ai_usage($agentName, $use_model, $usage, [ 'form_id'=>$fid, 'rows'=>count($slice) ]);
                $usages[] = [ 'form_id'=>$fid, 'usage'=>$usage ];
                if ($debug || !$ok || $text === ''){
                    $dbg = [
                        'form_id' => $fid,
                        'chunk_index' => $i,
                        'rows' => count($slice),
                        'endpoint' => $endpoint,
                        'model' => $use_model,
                        'http_status' => is_array($resp) ? (int)wp_remote_retrieve_response_code($resp) : null,
                        'usage' => $usage,
                    ];
                    try { $dbg['raw'] = (strlen($rawBody) > 1800) ? (substr($rawBody, 0, 1800) . "\n…[truncated]") : $rawBody; } catch (\Throwable $e) { /* noop */ }
                    $debugInfo[] = $dbg;
                }
            }
        }
        // Merge answers: no headings or preface; join texts directly
        $summary = implode("\n\n", array_map(function($a){ return trim((string)$a['text']); }, $answers));
        $respPayload = [ 'summary' => $summary, 'chunks' => $answers, 'usage' => $usages, 'voice' => $voice, 'session_id' => $session_id ];
        // Persist assistant turn with aggregated usage
        try {
            if ($session_id > 0){
                $tot = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=>0 ];
                foreach ($usages as $u){ $uu = $u['usage'] ?? []; $tot['input'] += (int)($uu['input'] ?? 0); $tot['output'] += (int)($uu['output'] ?? 0); $tot['total'] += (int)($uu['total'] ?? 0); $tot['duration_ms'] += (int)($uu['duration_ms'] ?? 0); }
                global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                $wpdb->insert($tblMsg, [
                    'session_id' => $session_id,
                    'role' => 'assistant',
                    'content' => $summary,
                    'usage_input' => max(0, (int)$tot['input']),
                    'usage_output' => max(0, (int)$tot['output']),
                    'usage_total' => max(0, (int)$tot['total']),
                    'duration_ms' => max(0, (int)$tot['duration_ms']),
                    'meta' => wp_json_encode([ 'form_ids'=>$form_ids, 'format'=>$format ], JSON_UNESCAPED_UNICODE),
                ]);
                $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
            }
        } catch (\Throwable $e) { /* ignore persistence errors */ }
    if ($debug || !empty($debugInfo)){ $respPayload['debug'] = $debugInfo; }
        return new WP_REST_Response($respPayload, 200);
    }

    /**
     * POST /ai/simple-chat — Minimal chat proxy to LLM.
     * Body: { message: string, history?: [{role, content}], model?, max_tokens?, temperature? }
     * Returns: { reply: string, usage?: {...}, debug?: {...} }
     */
    public static function ai_simple_chat(WP_REST_Request $request)
    {
        try {
            $p = $request->get_json_params(); if (!is_array($p)) $p = $request->get_params();
            $message = is_scalar($p['message'] ?? null) ? trim((string)$p['message']) : '';
            if ($message === '') return new WP_REST_Response(['error'=>'message_required'], 400);
            $session_id = isset($p['session_id']) && is_numeric($p['session_id']) ? (int)$p['session_id'] : 0;
            // Optional grounding: allow a single reference form id (sent as [id]) to answer strictly from form data
            $form_ids = [];
            if (isset($p['form_ids'])){
                $form_ids = array_values(array_filter(array_map('intval', (array)$p['form_ids']), function($v){ return $v>0; }));
                if (count($form_ids) > 1) { $form_ids = [ $form_ids[0] ]; }
            }
            $history = [];
            if (isset($p['history']) && is_array($p['history'])){
                foreach ($p['history'] as $h){
                    if (!is_array($h)) continue; $role = (string)($h['role'] ?? ''); $content = (string)($h['content'] ?? '');
                    if ($content==='') continue; if (!in_array($role, ['user','assistant','system'], true)) $role='user';
                    $history[] = [ 'role'=>$role, 'content'=>$content ];
                }
            }
            $model = is_scalar($p['model'] ?? null) ? (string)$p['model'] : '';
            $max_tokens = isset($p['max_tokens']) && is_numeric($p['max_tokens']) ? max(16, min(2048, (int)$p['max_tokens'])) : 800;
            $temperature = isset($p['temperature']) && is_numeric($p['temperature']) ? max(0.0, min(2.0, (float)$p['temperature'])) : 0.3;
            $wantDebug = !empty($p['debug']);

            // If a reference form is provided, route through analytics to keep answers grounded on form data
            if (!empty($form_ids)){
                try {
                    $r = new \WP_REST_Request('POST', '/arshline/v1/analytics/analyze');
                    $r->set_body_params([
                        'form_ids'   => $form_ids,
                        'question'   => $message,
                        'session_id' => $session_id,
                        'max_tokens' => $max_tokens,
                        'debug'      => $wantDebug,
                    ]);
                    /** @var \WP_REST_Response $resp */
                    $resp = self::analytics_analyze($r);
                    $code = $resp instanceof \WP_REST_Response ? (int)$resp->get_status() : 500;
                    $data = $resp instanceof \WP_REST_Response ? $resp->get_data() : null;
                    if ($code === 200 && is_array($data)){
                        $reply = '';
                        if (isset($data['result']) && is_array($data['result']) && isset($data['result']['answer'])){
                            $reply = (string)$data['result']['answer'];
                        }
                        if ($reply === ''){ $reply = (string)($data['summary'] ?? ''); }
                        if ($reply === ''){ $reply = 'اطلاعات لازم در فرم پیدا نمی‌کنم'; }
                        // Consolidate usage if provided as an array
                        $usageAgg = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=>0 ];
                        try {
                            $u = $data['usage'] ?? [];
                            if (is_array($u)){
                                // usage might be an array of items, or a single dict
                                if (isset($u['input']) || isset($u['total'])){
                                    $usageAgg['input'] = (int)($u['input'] ?? 0);
                                    $usageAgg['output']= (int)($u['output'] ?? 0);
                                    $usageAgg['total'] = (int)($u['total'] ?? 0);
                                    $usageAgg['duration_ms'] = (int)($u['duration_ms'] ?? 0);
                                } else {
                                    foreach ($u as $it){ $uu = is_array($it) ? ($it['usage'] ?? $it) : []; $usageAgg['input'] += (int)($uu['input'] ?? 0); $usageAgg['output'] += (int)($uu['output'] ?? 0); $usageAgg['total'] += (int)($uu['total'] ?? 0); $usageAgg['duration_ms'] += (int)($uu['duration_ms'] ?? 0); }
                                }
                            }
                        } catch (\Throwable $e) { /* ignore */ }
                        // Prefer session id from analytics (it may create one)
                        $sid = isset($data['session_id']) && is_numeric($data['session_id']) ? (int)$data['session_id'] : $session_id;
                        $res = [ 'reply' => $reply, 'usage' => $usageAgg, 'model' => (string)($data['model'] ?? 'analytics'), 'session_id' => $sid ];
                        if ($debug){
                            $preview = null;
                            try {
                                // Build a small preview to aid console debugging without flooding logs
                                $preview = [
                                    'summary' => isset($data['summary']) ? (string)$data['summary'] : null,
                                    'result' => isset($data['result']) && is_array($data['result']) ? [
                            'phase' => 'final',
                                        'confidence' => (string)($data['result']['confidence'] ?? ''),
                                        'clarify' => isset($data['result']['clarify']) ? $data['result']['clarify'] : null,
                                    ] : null,
                            'debug' => $dbg,
                            'routing' => [ 'structured'=>true, 'auto'=>$autoStructured, 'mode'=>$hoshMode, 'client_requested'=>$clientWantsStructured ],
                                    'usage' => isset($data['usage']) ? $data['usage'] : null,
                                    'model' => isset($data['model']) ? (string)$data['model'] : null,
                                ];
                            } catch (\Throwable $e) { $preview = null; }
                            $res['debug'] = [ 'routed' => 'analytics', 'analytics_debug' => ($data['debug'] ?? null), 'analytics_preview' => $preview ];
                        }
                        return new WP_REST_Response($res, 200);
                    }
                } catch (\Throwable $e) {
                    // Fall back to plain chat if analytics routing fails
                }
            }

            // Load AI config
            $gs = get_option('arshline_settings', []);
            $base = is_scalar($gs['ai_base_url'] ?? null) ? trim((string)$gs['ai_base_url']) : '';
            $api_key = is_scalar($gs['ai_api_key'] ?? null) ? (string)$gs['ai_api_key'] : '';
            $enabled = !empty($gs['ai_enabled']);
            $default_model = is_scalar($gs['ai_model'] ?? null) ? (string)$gs['ai_model'] : 'gpt-4o';
            if (!$enabled || $base === '' || $api_key === ''){
                return new WP_REST_Response([ 'error' => 'ai_disabled' ], 400);
            }
            $use_model = $model !== '' ? $model : $default_model;

            // Normalize base URL to avoid double /v1 when admins paste a base ending with /v1
            $baseNorm = rtrim($base, '/');
            if (preg_match('#/v\d+$#', $baseNorm)) {
                $baseNorm = preg_replace('#/v\d+$#', '', $baseNorm);
            }
            // If someone provided a full endpoint already, respect it
            if (preg_match('#/chat/(?:completions|completion)$#', $baseNorm)) {
                $endpoint = $baseNorm;
            } else {
                $endpoint = $baseNorm . '/v1/chat/completions';
            }
            $headers = [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ];
            $http_timeout = 45;

            // Build conversation history intelligently: prefer persisted session history; fall back to provided history
            $messages = [ [ 'role' => 'system', 'content' => 'فقط فارسی پاسخ بده. پاسخ را کوتاه و مستقیم بنویس. اگر سوال مبهم بود، یک سوال کوتاه برای روشن‌تر شدن بپرس.' ] ];
            $persisted = [];
            $includedHistoryPreview = [];
            try {
                if ($session_id > 0){
                    global $wpdb; $uid = get_current_user_id();
                    $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
                    $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                    $s = $wpdb->get_row($wpdb->prepare("SELECT id, user_id FROM {$tblSess} WHERE id = %d", $session_id), ARRAY_A);
                    if (!$s || ($uid && (int)($s['user_id'] ?? 0) && (int)$s['user_id'] !== (int)$uid)){
                        // Invalid or foreign session — drop it silently
                        $session_id = 0;
                    }
                    // Fetch last 20 messages for this session (ascending by id for chronological order)
                    if ($session_id > 0){
                        $persisted = $wpdb->get_results($wpdb->prepare("SELECT role, content FROM {$tblMsg} WHERE session_id = %d ORDER BY id ASC", $session_id), ARRAY_A) ?: [];
                    }
                }
            } catch (\Throwable $e) { $persisted = []; }
            // Heuristic: include more history for follow-ups or short/elliptic prompts
            $ql = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
            $isFollowUp = (bool)preg_match('/(?:ادامه|بیشتر|جزییات|جزئیات|قبلی|بالا|همان|همین|این|آن|قبلی|توضیح|چرا|چطور|continue|more|explain|previous|again|same|that|it|they|he|she|follow\s*up)/ui', $ql);
            $shortPrompt = strlen($message) < 36;
            $maxTurns = ($isFollowUp || $shortPrompt) ? 16 : 8; // turns = individual messages
            // Merge: use persisted first; if empty, consider provided transient history
            $merged = [];
            if (!empty($persisted)){
                $merged = $persisted;
            } elseif (!empty($history)){
                $merged = $history;
            }
            // Trim to last N messages and append to messages
            if (!empty($merged)){
                $slice = array_slice($merged, -$maxTurns);
                foreach ($slice as $m){
                    $role = in_array(($m['role'] ?? ''), ['user','assistant','system'], true) ? ($m['role'] ?? 'user') : 'user';
                    $content = (string)($m['content'] ?? ''); if ($content==='') continue;
                    $messages[] = [ 'role'=>$role, 'content'=>$content ];
                    if ($wantDebug){ $includedHistoryPreview[] = [ 'role'=>$role, 'content' => (strlen($content)>400? (substr($content,0,400).'…[truncated]') : $content) ]; }
                }
            }
            // Current user message last
            $messages[] = [ 'role' => 'user', 'content' => $message ];

            $makeAttempt = function(string $modelName) use ($messages, $temperature, $max_tokens, $endpoint, $headers, $http_timeout) {
                $payload = [ 'model'=>$modelName, 'messages'=>$messages, 'temperature'=>$temperature, 'max_tokens'=>$max_tokens ];
                $payloadJson = wp_json_encode($payload);
                $t0 = microtime(true);
                $resp0 = wp_remote_post($endpoint, [ 'timeout'=>$http_timeout, 'headers'=>$headers, 'body'=>$payloadJson ]);
                $status = is_wp_error($resp0) ? 0 : (int)wp_remote_retrieve_response_code($resp0);
                $raw = is_wp_error($resp0) ? ($resp0->get_error_message() ?: '') : (string)wp_remote_retrieve_body($resp0);
                $ok = ($status === 200);
                $body = $ok ? json_decode($raw, true) : (json_decode($raw, true) ?: null);
                $text = '';
                $usage = [ 'input'=>0,'output'=>0,'total'=>0,'duration_ms'=> (int) round((microtime(true)-$t0)*1000) ];
                return [ $ok, $status, $raw, $body, $text, $usage, $payloadJson ];
            };

            // First attempt with the requested/default model
            [ $ok, $status, $rawBody, $body, $text, $usage, $payloadJson ] = $makeAttempt($use_model);

            // Optional one-shot fallback to a known model if model/path is invalid
            $finalModel = $use_model;
            if (!$ok && in_array((int)$status, [400, 404, 422], true)){
                $fallback = 'gpt-4o';
                if ($fallback !== $use_model){
                    [ $ok2, $status2, $rawBody2, $body2, $text2, $usage2, $payloadJson2 ] = $makeAttempt($fallback);
                    if ($ok2){
                        $ok = true; $status = $status2; $rawBody = $rawBody2; $body = $body2; $text = $text2; $usage = $usage2; $payloadJson = $payloadJson2; $finalModel = $fallback;
                    } else {
                        // Keep the first attempt’s artifacts but expose the second in debug attempts
                    }
                }
            }

            if (is_array($body)){
                try {
                    if (isset($body['choices'][0]['message']['content']) && is_string($body['choices'][0]['message']['content'])){
                        $text = (string)$body['choices'][0]['message']['content'];
                    } elseif (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) {
                        $text = (string)$body['choices'][0]['text'];
                    } elseif (isset($body['output_text']) && is_string($body['output_text'])) {
                        $text = (string)$body['output_text'];
                    }
                } catch (\Throwable $e) { $text=''; }
                $u = $body['usage'] ?? [];
                $in = (int)($u['prompt_tokens'] ?? $u['input_tokens'] ?? ($u['input'] ?? 0));
                $out = (int)($u['completion_tokens'] ?? $u['output_tokens'] ?? ($u['output'] ?? 0));
                $tot = (int)($u['total_tokens'] ?? $u['total'] ?? 0);
                // Fallback: rough approximation if provider didn't return usage
                if (!$in && !$out && !$tot){ $promptApprox=strlen((string)$payloadJson); $compApprox=strlen((string)$text); $in=(int)ceil($promptApprox/4); $out=(int)ceil($compApprox/4); $tot=$in+$out; }
                $usage['input']=$in; $usage['output']=$out; $usage['total']=$tot;
            }
            // Ensure session persistence: create session on demand and store turns
            try {
                global $wpdb; $uid = get_current_user_id();
                $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
                $tblMsg  = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                if ($session_id <= 0){
                    $wpdb->insert($tblSess, [
                        'user_id' => $uid ?: null,
                        'title' => function_exists('mb_substr') ? mb_substr($message, 0, 190) : substr($message,0,190),
                        'meta' => wp_json_encode([ 'agent' => 'hoshang-chat' ], JSON_UNESCAPED_UNICODE),
                        'last_message_at' => current_time('mysql'),
                    ]);
                    $session_id = (int)$wpdb->insert_id;
                }
                if ($session_id > 0){
                    // Save user turn
                    $wpdb->insert($tblMsg, [ 'session_id'=>$session_id, 'role'=>'user', 'content'=>$message, 'meta'=>wp_json_encode(['agent'=>'hoshang-chat'], JSON_UNESCAPED_UNICODE) ]);
                }
            } catch (\Throwable $e) { /* ignore */ }

            // Save assistant turn and usage; also log usage aggregate
            try {
                global $wpdb; $tblSess = \Arshline\Support\Helpers::tableName('ai_chat_sessions'); $tblMsg = \Arshline\Support\Helpers::tableName('ai_chat_messages');
                if ($session_id > 0){
                    $wpdb->insert($tblMsg, [
                        'session_id' => $session_id,
                        'role' => 'assistant',
                        'content' => ($text !== '' ? $text : '—'),
                        'usage_input' => max(0, (int)($usage['input'] ?? 0)),
                        'usage_output' => max(0, (int)($usage['output'] ?? 0)),
                        'usage_total' => max(0, (int)($usage['total'] ?? 0)),
                        'duration_ms' => max(0, (int)($usage['duration_ms'] ?? 0)),
                        'meta' => wp_json_encode([ 'agent'=>'hoshang-chat' ], JSON_UNESCAPED_UNICODE),
                    ]);
                    $wpdb->update($tblSess, [ 'last_message_at' => current_time('mysql') ], [ 'id'=>$session_id ]);
                }
            } catch (\Throwable $e) { /* ignore */ }
            self::log_ai_usage('hoshang-chat', $finalModel, $usage, [ 'session_id' => $session_id ]);

            $res = [ 'reply' => ($text !== '' ? $text : '—') , 'usage' => $usage, 'model' => $finalModel, 'session_id' => $session_id ];
            if ($wantDebug){
                $prevMsgs = [];
                foreach ($messages as $m){ $c = (string)($m['content'] ?? ''); if (strlen($c) > 1800) $c = substr($c,0,1800) . "\n…[truncated]"; $prevMsgs[] = [ 'role'=>$m['role'] ?? '', 'content'=>$c ]; }
                $dbg = [
                    'endpoint' => $endpoint,
                    'request_preview' => [ 'model'=>$use_model, 'max_tokens'=>$max_tokens, 'temperature'=>$temperature, 'messages'=>$prevMsgs ],
                    'http_status' => $ok ? 200 : (int)$status,
                    'raw' => (strlen((string)$rawBody)>1800? substr((string)$rawBody,0,1800)."\n…[truncated]": (string)$rawBody),
                    'final_model' => $finalModel,
                    'used_history_count' => count($messages) - 2, // excluding system + current user
                    'session_id' => $session_id,
                    'included_history_preview' => $includedHistoryPreview,
                ];
                $res['debug'] = $dbg;
            }
            return new WP_REST_Response($res, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([ 'error' => 'server_error' ], 500);
        }
    }

    // ===== Chat history endpoints =====
    public static function list_chat_sessions(\WP_REST_Request $r)
    {
        try {
            global $wpdb; $uid = get_current_user_id();
            $t = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, title, last_message_at, created_at, updated_at FROM {$t} WHERE user_id %s ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 200", $uid ? '=' : 'IS', $uid ?: null), ARRAY_A);
            if (!is_array($rows)) $rows = [];
            return new \WP_REST_Response([ 'items' => $rows ], 200);
        } catch (\Throwable $e) { return new \WP_REST_Response([ 'items'=>[] ], 200); }
    }
    public static function get_chat_messages(\WP_REST_Request $r)
    {
        $sid = (int)$r['session_id']; if ($sid<=0) return new \WP_REST_Response(['error'=>'invalid_session'], 400);
        try {
            global $wpdb; $uid = get_current_user_id();
            $t = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $tm= \Arshline\Support\Helpers::tableName('ai_chat_messages');
            $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $sid), ARRAY_A);
            if (!$s) return new \WP_REST_Response(['error'=>'not_found'], 404);
            if ($uid && (int)($s['user_id'] ?? 0) && (int)$s['user_id'] !== $uid){ return new \WP_REST_Response(['error'=>'forbidden'], 403); }
            $msgs = $wpdb->get_results($wpdb->prepare("SELECT id, role, content, usage_input, usage_output, usage_total, duration_ms, created_at FROM {$tm} WHERE session_id = %d ORDER BY id ASC", $sid), ARRAY_A) ?: [];
            return new \WP_REST_Response(['session'=>['id'=>$sid,'title'=>$s['title'] ?? ''],'messages'=>$msgs], 200);
        } catch (\Throwable $e) { return new \WP_REST_Response(['error'=>'server_error'], 500); }
    }
    public static function export_chat_session(\WP_REST_Request $r)
    {
        $sid = (int)$r['session_id']; if ($sid<=0) return new \WP_REST_Response(['error'=>'invalid_session'], 400);
        $format = strtolower((string)($r->get_param('format') ?? 'json'));
        try {
            global $wpdb; $uid = get_current_user_id();
            $t = \Arshline\Support\Helpers::tableName('ai_chat_sessions');
            $tm= \Arshline\Support\Helpers::tableName('ai_chat_messages');
            $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $sid), ARRAY_A);
            if (!$s) return new \WP_REST_Response(['error'=>'not_found'], 404);
            if ($uid && (int)($s['user_id'] ?? 0) && (int)$s['user_id'] !== $uid){ return new \WP_REST_Response(['error'=>'forbidden'], 403); }
            $msgs = $wpdb->get_results($wpdb->prepare("SELECT role, content, usage_input, usage_output, usage_total, duration_ms, created_at FROM {$tm} WHERE session_id = %d ORDER BY id ASC", $sid), ARRAY_A) ?: [];
            if ($format === 'csv'){
                $rows = [ ['created_at','role','usage_input','usage_output','usage_total','duration_ms','content'] ];
                foreach ($msgs as $m){ $rows[] = [ $m['created_at'],$m['role'],(int)$m['usage_input'],(int)$m['usage_output'],(int)$m['usage_total'],(int)$m['duration_ms'],$m['content'] ]; }
                $csv = '';
                foreach ($rows as $r0){ $csv .= implode(',', array_map(function($v){ $s=is_string($v)?$v:json_encode($v); return '"'.str_replace('"','""',$s).'"'; }, $r0)) . "\r\n"; }
                $resp = new \WP_REST_Response($csv, 200); $resp->header('Content-Type','text/csv; charset=UTF-8'); $resp->header('Content-Disposition','attachment; filename="chat-session-'.$sid.'.csv"'); return $resp;
            }
            // default json
            $resp = new \WP_REST_Response([ 'session'=>['id'=>$sid,'title'=>$s['title'] ?? ''],'messages'=>$msgs ], 200);
            $resp->header('Content-Disposition','attachment; filename="chat-session-'.$sid.'.json"');
            return $resp;
        } catch (\Throwable $e) { return new \WP_REST_Response(['error'=>'server_error'], 500); }
    }

    /** Insert a row into ai_usage table. */
    protected static function log_ai_usage(string $agent, string $model, array $usage, array $meta = []): void
    {
        try {
            global $wpdb; $table = Helpers::tableName('ai_usage');
            $user_id = get_current_user_id();
            $wpdb->insert($table, [
                'user_id' => $user_id ?: null,
                'agent' => substr($agent, 0, 32),
                'model' => substr($model, 0, 100),
                'tokens_input' => max(0, (int)($usage['input'] ?? 0)),
                'tokens_output' => max(0, (int)($usage['output'] ?? 0)),
                'tokens_total' => max(0, (int)($usage['total'] ?? 0)),
                'cost' => isset($usage['cost']) ? (float)$usage['cost'] : null,
                'duration_ms' => max(0, (int)($usage['duration_ms'] ?? 0)),
                'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) { /* ignore */ }
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

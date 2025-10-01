<?php
// Fallback runner when WP-CLI isn't available. Usage (from web or CLI):
// php wp-content/plugins/arshline/tools/run_analytics.php "حال نیما چطوره؟"

$root = realpath(__DIR__ . '/../../../..');
if (!$root || !file_exists($root . '/wp-load.php')) { echo "Cannot locate WordPress root.\n"; exit(1); }
// Provide minimal server vars for CLI to satisfy wp-config assumptions
if (empty($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
if (empty($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = 'localhost';
if (empty($_SERVER['SERVER_PORT'])) $_SERVER['SERVER_PORT'] = '80';
if (empty($_SERVER['REQUEST_SCHEME'])) $_SERVER['REQUEST_SCHEME'] = 'http';
if (empty($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $root . '/wp-load.php';

// Elevate current user to admin for testing
if (!function_exists('wp_set_current_user')){ require_once ABSPATH . WPINC . '/pluggable.php'; }
$user = get_users([ 'role__in' => ['administrator'], 'number' => 1 ]);
if (!empty($user)) { wp_set_current_user($user[0]->ID); }

// Pick a published form id if not provided
$argvQ = isset($argv[1]) ? (string)$argv[1] : 'حال نیما چطوره؟';
$formId = 0;
try {
  global $wpdb; $tbl = \Arshline\Support\Helpers::tableName('forms');
  // Try any form first (published preferred). If none, create a minimal one.
  $fid = (int)$wpdb->get_var("SELECT id FROM {$tbl} WHERE status='published' ORDER BY id ASC LIMIT 1");
  if ($fid <= 0) { $fid = (int)$wpdb->get_var("SELECT id FROM {$tbl} ORDER BY id ASC LIMIT 1"); }
  if ($fid <= 0) {
    $wpdb->insert($tbl, [
      'schema_version' => '1.0.0',
      'owner_id' => get_current_user_id() ?: null,
      'status' => 'draft',
      'public_token' => null,
      'meta' => '{}',
      'created_at' => current_time('mysql'),
      'updated_at' => current_time('mysql'),
    ]);
    $fid = (int)$wpdb->insert_id;
  }
  $formId = $fid ?: 0;
} catch (Throwable $e) { $formId = 0; }
if ($formId <= 0) { echo "No form found or created.\n"; exit(2); }

// Ensure AI settings enabled so endpoint doesn't return ai_disabled
$gs = get_option('arshline_settings', []);
if (!is_array($gs)) $gs = [];
$gs['ai_enabled'] = true;
if (empty($gs['ai_base_url'])) $gs['ai_base_url'] = 'https://api.openai.com';
if (empty($gs['ai_api_key'])) $gs['ai_api_key'] = 'test'; // dummy; call will fail but endpoint will return fallback
if (empty($gs['ai_model'])) $gs['ai_model'] = 'gpt-4o-mini';
if (empty($gs['ai_hosh_mode'])) $gs['ai_hosh_mode'] = 'hybrid';
update_option('arshline_settings', $gs, false);

// Helper for printing
function out($k, $v){ echo $k . ': ' . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) . "\n"; }

// 1) PLAN
$plan = new WP_REST_Request('POST', '/arshline/v1/analytics/analyze');
$plan->set_body_params([
  'form_ids' => [$formId],
  'question' => $argvQ,
  'structured' => true,
  'phase' => 'plan',
  'debug' => true,
]);
$respPlan = rest_do_request($plan);
$bodyPlan = $respPlan->get_data();
$sid = (int)($bodyPlan['session_id'] ?? 0);
out('PLAN status', $respPlan->get_status());
out('PLAN session', $sid);

// 2) CHUNK (first chunk)
$chunk = new WP_REST_Request('POST', '/arshline/v1/analytics/analyze');
$chunk->set_body_params([
  'form_ids' => [$formId],
  'question' => $argvQ,
  'structured' => true,
  'phase' => 'chunk',
  'chunk_index' => 1,
  'session_id' => $sid,
  'debug' => true,
]);
$respChunk = rest_do_request($chunk);
$bodyChunk = $respChunk->get_data();
out('CHUNK status', $respChunk->get_status());
out('CHUNK rows', $bodyChunk['chunk_rows'] ?? 0);
out('CHUNK candidates', isset($bodyChunk['debug'][0]['candidate_row_ids']) ? count($bodyChunk['debug'][0]['candidate_row_ids']) : 0);

// 3) FINAL (load partials via transient)
$final = new WP_REST_Request('POST', '/arshline/v1/analytics/analyze');
$final->set_body_params([
  'form_ids' => [$formId],
  'question' => $argvQ,
  'structured' => true,
  'phase' => 'final',
  'session_id' => $sid,
  'debug' => true,
]);
$respFinal = rest_do_request($final);
$status = $respFinal->get_status();
$body = $respFinal->get_data();

echo "Status: {$status}\n";
$diag = is_array($body['diagnostics'] ?? null) ? $body['diagnostics'] : [];
out('Routed', $diag['routed'] ?? 'n/a');
// The runner prints from result payload
$ansBlock = is_array($body['result'] ?? null) ? $body['result'] : $body;
if (!empty($ansBlock['insights'][0]['evidence_ids'])) out('Evidence IDs', implode(',', array_map('intval', (array)$ansBlock['insights'][0]['evidence_ids'])));
$answer = (string)($ansBlock['answer'] ?? '');
if ($answer === '') $answer = json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
echo "Answer:\n{$answer}\n";

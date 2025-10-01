<?php
// Usage: wp eval-file wp-content/plugins/arshline/tools/wpcli_test_analytics.php -- [form_id] [question]
// Example: wp eval-file ... 1 "حال نیما چطوره؟"
if (!defined('WP_CLI')) { echo "Run inside WP-CLI (wp eval-file)\n"; return; }

list($script, $formIdArg, $questionArg) = array_pad($GLOBALS['argv'], 3, null);
$formId = (int)($formIdArg ?? 0);
$question = (string)($questionArg ?? 'حال نیما چطوره؟');
if ($formId <= 0) { WP_CLI::error('Provide a numeric form_id as first arg'); return; }

$payload = [
  'form_ids' => [$formId],
  'question' => $question,
  'structured' => true,
  'phase' => 'final',
  'debug' => true,
  'max_rows' => 2000,
];

// Build /arshline/v1/analytics/analyze request
$r = new WP_REST_Request('POST', '/arshline/v1/analytics/analyze');
$r->set_body_params($payload);
$res = rest_do_request($r);
$status = $res->get_status();
$body = $res->get_data();

WP_CLI::line('Status: ' . $status);
WP_CLI::line('Routed: ' . (($body['debug']['routed'] ?? $body['diagnostics']['routed'] ?? 'n/a')));
WP_CLI::line('Requested person: ' . ($body['requested_person'] ?? ''));
if (!empty($body['insights'][0]['evidence_ids'])){
  WP_CLI::line('Evidence IDs: ' . implode(',', array_map('intval', (array)$body['insights'][0]['evidence_ids'])));
}
WP_CLI::line('Answer:');
WP_CLI::line((string)($body['answer'] ?? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

// Print fallback reason and ambiguity if present
if (!empty($body['diagnostics'])){
  $diag = $body['diagnostics'];
  if (!empty($diag['ai_decision'])){
    WP_CLI::line('AI decision: ' . json_encode($diag['ai_decision'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  }
  if (!empty($diag['ambiguity_score'])){
    WP_CLI::line('Ambiguity: ' . $diag['ambiguity_score']);
  }
}

<?php
// Usage: php wp-content/plugins/arshline/tools/seed_sample_form.php
$root = realpath(__DIR__ . '/../../../..');
if (!$root || !file_exists($root . '/wp-load.php')) { echo "Cannot locate WordPress root.\n"; exit(1); }
if (empty($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = 'localhost';
if (empty($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = 'localhost';
if (empty($_SERVER['SERVER_PORT'])) $_SERVER['SERVER_PORT'] = '80';
if (empty($_SERVER['REQUEST_SCHEME'])) $_SERVER['REQUEST_SCHEME'] = 'http';
if (empty($_SERVER['REMOTE_ADDR'])) $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $root . '/wp-load.php';
\Arshline\Support\Helpers::class; // ensure autoload
if (!function_exists('wp_set_current_user')){ require_once ABSPATH . WPINC . '/pluggable.php'; }
$user = get_users([ 'role__in' => ['administrator'], 'number' => 1 ]);
if (!empty($user)) { wp_set_current_user($user[0]->ID); }

global $wpdb;
$tblForms = \Arshline\Support\Helpers::tableName('forms');
$tblFields = \Arshline\Support\Helpers::tableName('fields');
$tblSubs = \Arshline\Support\Helpers::tableName('submissions');
$tblVals = \Arshline\Support\Helpers::tableName('submission_values');

// Create or find a form
$fid = (int)$wpdb->get_var("SELECT id FROM {$tblForms} WHERE status='published' ORDER BY id ASC LIMIT 1");
if ($fid <= 0){
  $wpdb->insert($tblForms, [
    'schema_version' => '1.0.0',
    'owner_id' => get_current_user_id() ?: null,
    'status' => 'published',
    'public_token' => NULL,
    'meta' => '{}',
    'created_at' => current_time('mysql'),
    'updated_at' => current_time('mysql'),
  ]);
  $fid = (int)$wpdb->insert_id;
}

// Define fields with exact labels
$fields = [
  [ 'label' => 'نام و نام خانوادگی :', 'type' => 'text' ],
  [ 'label' => 'شماره تلفن همراه :', 'type' => 'text' ],
  [ 'label' => 'امروز اوضاع و احوالتون چطوره ؟', 'type' => 'textarea' ],
  [ 'label' => 'به حال دلت از یک تا ده چه امتیازی میدی ؟', 'type' => 'number' ],
  [ 'label' => 'بین میوه ها ترجیحت کدومه ', 'type' => 'text' ],
];
// Upsert fields (replace all)
$wpdb->query($wpdb->prepare("DELETE FROM {$tblFields} WHERE form_id=%d", $fid));
$sort = 0; $fidMap = [];
foreach ($fields as $f){
  $wpdb->insert($tblFields, [
    'form_id' => $fid,
    'sort' => $sort++,
    'props' => json_encode([ 'type'=>$f['type'], 'label'=>$f['label'], 'question'=>$f['label'] ], JSON_UNESCAPED_UNICODE),
    'created_at' => current_time('mysql'),
  ]);
  $fidMap[$f['label']] = (int)$wpdb->insert_id;
}

// Helper to add a submission with values map label=>value
$addSub = function(array $values) use ($fid, $tblSubs, $tblVals, $fidMap, $wpdb){
  echo "Adding submission with fidMap: " . json_encode($fidMap, JSON_UNESCAPED_UNICODE) . "\n";
  $wpdb->insert($tblSubs, [ 'form_id'=>$fid, 'user_id'=>get_current_user_id() ?: null, 'ip'=>'127.0.0.1', 'status'=>'submitted', 'meta'=>'{}' ]);
  if ($wpdb->last_error) {
    echo "Submission insert error: " . $wpdb->last_error . "\n";
  }
  $sid = (int)$wpdb->insert_id;
  echo "Created submission ID: $sid\n";
  $idx = 0;
  foreach ($values as $label=>$val){
    $field_id = (int)($fidMap[$label] ?? 0);
    echo "Label: '$label' -> Field ID: $field_id\n";
    if ($field_id <= 0) {
      echo "  SKIPPING - no field ID\n";
      continue;
    }
    $result = $wpdb->insert($tblVals, [ 'submission_id'=>$sid, 'field_id'=>$field_id, 'value'=>(string)$val, 'idx'=>$idx++ ]);
    echo "  Insert result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
    if ($wpdb->last_error) {
      echo "  Error: " . $wpdb->last_error . "\n";
    }
  }
  return $sid;
};

// Insert two sample rows (as provided)
$addSub([
  'نام و نام خانوادگی :' => 'فهیمه کرم الهی',
  'شماره تلفن همراه :' => '9166632400',
  'امروز اوضاع و احوالتون چطوره ؟' => 'بی نظیره ، محشره محشر',
  'به حال دلت از یک تا ده چه امتیازی میدی ؟' => '7',
]);
$addSub([
  'نام و نام خانوادگی :' => 'نیما سیدعزیزی',
  'شماره تلفن همراه :' => '9397710151',
  'امروز اوضاع و احوالتون چطوره ؟' => 'امروز خوب از خواب بیدار نشدم، حالم گرفته است .',
  'به حال دلت از یک تا ده چه امتیازی میدی ؟' => '2',
]);

echo "Seeded sample form_id={$fid}\n";

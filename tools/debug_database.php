<?php
echo "Starting debug...\n";
// Load WordPress directly
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
echo "WordPress loaded...\n";

global $wpdb;
// Check if tables exist
$tables = ['arsh_forms', 'arsh_fields', 'arsh_submissions', 'arsh_submission_values'];
foreach ($tables as $table) {
    $full_table = $wpdb->prefix . $table;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'");
    echo "Table $full_table: " . ($exists ? "EXISTS" : "NOT EXISTS") . "\n";
}

$results = $wpdb->get_results("SELECT id, form_id FROM {$wpdb->prefix}arsh_submissions ORDER BY id DESC LIMIT 10", ARRAY_A);
echo "Recent submissions:\n";
foreach ($results as $row) {
    echo "ID: {$row['id']}, Form ID: {$row['form_id']}\n";
}

// Check submission values
$values = $wpdb->get_results("SELECT sv.submission_id, sv.field_id, sv.value FROM {$wpdb->prefix}arsh_submission_values sv ORDER BY sv.submission_id DESC LIMIT 10", ARRAY_A);
echo "\nRecent submission values:\n";
foreach ($values as $val) {
    echo "Submission: {$val['submission_id']}, Field: {$val['field_id']}, Value: {$val['value']}\n";
}

echo "\nTesting listValuesBySubmissionIds for IDs [15, 14]:\n";
$valuesMap = \Arshline\Modules\Forms\SubmissionRepository::listValuesBySubmissionIds([15, 14]);
var_dump($valuesMap);
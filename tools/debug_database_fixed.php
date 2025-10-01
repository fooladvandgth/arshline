<?php
echo "Starting debug...\n";
// Load WordPress directly
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
echo "WordPress loaded...\n";

global $wpdb;

// Check tables with correct prefix
$tables = ['forms', 'fields', 'submissions', 'submission_values'];
foreach ($tables as $table) {
    $full_table = \Arshline\Support\Helpers::tableName($table);
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'");
    echo "Table $full_table: " . ($exists ? "EXISTS" : "NOT EXISTS") . "\n";
}

// Check recent submissions
$tbl_subs = \Arshline\Support\Helpers::tableName('submissions');
$results = $wpdb->get_results("SELECT id, form_id FROM {$tbl_subs} ORDER BY id DESC LIMIT 10", ARRAY_A);
echo "\nRecent submissions:\n";
foreach ($results as $row) {
    echo "ID: {$row['id']}, Form ID: {$row['form_id']}\n";
}

// Check submission values
$tbl_vals = \Arshline\Support\Helpers::tableName('submission_values');
$values = $wpdb->get_results("SELECT sv.submission_id, sv.field_id, sv.value FROM {$tbl_vals} sv ORDER BY sv.submission_id DESC LIMIT 10", ARRAY_A);
echo "\nRecent submission values:\n";
foreach ($values as $val) {
    echo "Submission: {$val['submission_id']}, Field: {$val['field_id']}, Value: {$val['value']}\n";
}

// Check table schema
$tbl_vals = \Arshline\Support\Helpers::tableName('submission_values');
echo "\nSubmission values table schema:\n";
$schema = $wpdb->get_results("DESCRIBE {$tbl_vals}", ARRAY_A);
foreach ($schema as $col) {
    echo "{$col['Field']}: {$col['Type']} {$col['Null']} {$col['Key']}\n";
}

// Check existing fields
$tbl_fields = \Arshline\Support\Helpers::tableName('fields');
echo "\nExisting fields:\n";
$fields = $wpdb->get_results("SELECT id, form_id, props FROM {$tbl_fields} ORDER BY id", ARRAY_A);
foreach ($fields as $field) {
    $props = json_decode($field['props'], true);
    $label = $props['label'] ?? 'No label';
    echo "Field ID: {$field['id']}, Form: {$field['form_id']}, Label: {$label}\n";
}

echo "\nTesting manual insert into submission_values:\n";
$valid_field_id = !empty($fields) ? $fields[0]['id'] : 1;
$test_insert = $wpdb->insert($tbl_vals, [
    'submission_id' => 15,
    'field_id' => $valid_field_id,
    'value' => 'Test Value',
    'idx' => 0
]);
echo "Insert result: " . ($test_insert ? "SUCCESS" : "FAILED") . "\n";
if ($wpdb->last_error) {
    echo "DB Error: " . $wpdb->last_error . "\n";
}

echo "\nTesting listValuesBySubmissionIds for IDs [15, 14]:\n";
$valuesMap = \Arshline\Modules\Forms\SubmissionRepository::listValuesBySubmissionIds([15, 14]);
var_dump($valuesMap);
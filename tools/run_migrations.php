<?php
echo "Starting migration...\n";
// Load WordPress directly
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
echo "WordPress loaded...\n";

// Run migrations
try {
    $migrations = \Arshline\Database\Migrations::up();
    echo "Found migrations for: " . implode(', ', array_keys($migrations)) . "\n";
    
    foreach ($migrations as $key => $sql) {
        $table = \Arshline\Support\Helpers::tableName($key);
        $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
        echo "\nCreating table for key '$key':\n";
        echo "Table name: $table\n";
        echo "SQL preview: " . substr($sql, 0, 100) . "...\n";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        echo "dbDelta result: " . print_r($result, true) . "\n";
    }
    echo "Migrations completed...\n";
} catch (\Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

// Check tables again
global $wpdb;
$tables = ['arsh_forms', 'arsh_fields', 'arsh_submissions', 'arsh_submission_values'];
foreach ($tables as $table) {
    $full_table = $wpdb->prefix . $table;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'");
    echo "Table $full_table: " . ($exists ? "EXISTS" : "NOT EXISTS") . "\n";
}

// Check for tables with different prefixes
echo "\nLooking for tables with pattern 'arsh':\n";
$all_tables = $wpdb->get_results("SHOW TABLES LIKE '%arsh%'", ARRAY_N);
foreach ($all_tables as $table) {
    echo "Found: " . $table[0] . "\n";
}
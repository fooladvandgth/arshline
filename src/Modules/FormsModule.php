<?php
namespace Arshline\Modules;

use Arshline\Support\Helpers;
use Arshline\Database\Migrations;

class FormsModule
{
    public static function boot()
    {
        // اجرای مهاجرت دیتابیس هنگام فعال‌سازی افزونه
        register_activation_hook(__FILE__, [self::class, 'migrate']);
        add_action('init', [self::class, 'maybe_migrate']);
    }

    public static function migrate()
    {
        global $wpdb;
        $migrations = Migrations::up();
        foreach ($migrations as $key => $sql) {
            $table = Helpers::tableName($key);
            $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    public static function maybe_migrate(): void
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            self::migrate();
            return;
        }
        // Lightweight schema upgrade: ensure public_token column exists
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'public_token'));
        if (!$col) {
            $sql = Migrations::up()['forms'] ?? '';
            if ($sql) {
                $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);
            }
            // Fallback: if dbDelta didn't add the column (environment differences), use ALTER TABLE
            $col2 = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'public_token'));
            if (!$col2) {
                // Extra safety: Validate table identifier strictly to avoid injection via unexpected prefixes.
                // We cannot parameterize identifiers with $wpdb->prepare, so we whitelist characters and backtick-quote.
                $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
                if ($safeTable === '' || $safeTable !== $table) {
                    // If sanitization changed the name, bail to avoid risky query on an unexpected identifier.
                    return;
                }
                // Add column
                $wpdb->query("ALTER TABLE `{$safeTable}` ADD `public_token` VARCHAR(24) NULL");
                // Add unique index if missing
                $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = %s", 'public_token_unique'));
                if (!$idx) {
                    $wpdb->query("ALTER TABLE `{$safeTable}` ADD UNIQUE KEY `public_token_unique` (`public_token`)");
                }
            }
        }

        // Ensure status column exists (default 'draft') and normalize existing rows
        $hasStatus = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'status'));
        if (!$hasStatus) {
            $sql = Migrations::up()['forms'] ?? '';
            if ($sql) {
                $sql = str_replace('{prefix}', $wpdb->prefix, $sql);
                require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
                dbDelta($sql);
            }
            $hasStatus2 = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'status'));
            if (!$hasStatus2) {
                $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', (string)$table);
                if ($safeTable === '' || $safeTable !== $table) { return; }
                $wpdb->query("ALTER TABLE `{$safeTable}` ADD `status` VARCHAR(20) DEFAULT 'draft'");
            }
        }
        // Normalize invalid/NULL statuses to 'draft'
        try {
            $wpdb->query("UPDATE {$table} SET status='draft' WHERE status IS NULL OR status='' ");
        } catch (\Throwable $e) { /* ignore */ }

        // Ensure new module tables exist (user groups, mapping). If plugin was activated before these tables
        // were introduced, they may be missing in existing installs.
        $required = [ 'user_groups', 'user_group_fields', 'user_group_members', 'form_group_access' ];
        $missing = [];
        foreach ($required as $key){
            $t = Helpers::tableName($key);
            $ex = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));
            if ($ex !== $t) { $missing[] = $key; }
        }
        if (!empty($missing)){
            $defs = Migrations::up();
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            foreach ($missing as $k){
                if (!empty($defs[$k])){
                    $sql = str_replace('{prefix}', $wpdb->prefix, $defs[$k]);
                    dbDelta($sql);
                }
            }
        }
    }
}

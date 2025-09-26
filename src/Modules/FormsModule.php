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
                // Add column
                $wpdb->query("ALTER TABLE `{$table}` ADD `public_token` VARCHAR(24) NULL");
                // Add unique index if missing
                $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'public_token_unique'));
                if (!$idx) {
                    $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `public_token_unique` (`public_token`)");
                }
            }
        }
    }
}

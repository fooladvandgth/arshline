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
        }
    }
}

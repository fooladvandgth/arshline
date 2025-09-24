<?php
namespace Arshline\Modules;

use Arshline\Support\Helpers;
use Arshline\Database\Migrations;

class FormsModule
{
    private const OPTION_SCHEMA_VERSION = 'arshline_forms_schema_version';
    private const SCHEMA_VERSION = '2025-09-23';

    public static function boot(): void
    {
        add_action('init', [self::class, 'ensure_schema'], 5);
    }

    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (Migrations::up() as $key => $sql) {
            $table = Helpers::tableName($key);
            $prepared = str_replace('{prefix}', $wpdb->prefix, $sql);
            dbDelta($prepared);
        }
        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
    }

    public static function ensure_schema(): void
    {
        $current = (string) get_option(self::OPTION_SCHEMA_VERSION, '');
        if ($current !== self::SCHEMA_VERSION) {
            self::migrate();
        }
    }
}

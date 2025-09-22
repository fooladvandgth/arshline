<?php
namespace Arshline\Support;

class Helpers
{
    public static function tableName(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'x_' . $name;
    }
}

<?php
namespace Arshline\Support;

class Helpers
{
    public static function tableName(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'x_' . $name;
    }

    public static function randomToken(int $bytes = 9): string
    {
        // Base62 from random bytes; 9 bytes ≈ 12 chars
        $raw = random_bytes(max(4, $bytes));
        $b64 = rtrim(strtr(base64_encode($raw), '+/', 'AZ'), '=');
        // Remove non-alnum just in case and trim to 12 chars
        $token = preg_replace('/[^A-Za-z0-9]/', '', $b64);
        return substr($token, 0, 12);
    }
}

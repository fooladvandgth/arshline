<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;

class FieldRepository
{
    public static function listByForm(int $form_id): array
    {
        global $wpdb;
        $table = Helpers::tableName('fields');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, sort, props, created_at FROM {$table} WHERE form_id=%d ORDER BY sort ASC, id ASC", $form_id), ARRAY_A);
        return array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'sort' => (int)$r['sort'],
                'props' => json_decode($r['props'] ?: '{}', true),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows ?: []);
    }

    public static function replaceAll(int $form_id, array $fields): void
    {
        global $wpdb;
        $table = Helpers::tableName('fields');
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE form_id=%d", $form_id));
        $sort = 0;
        foreach ($fields as $f) {
            $props = isset($f['props']) ? $f['props'] : $f;
            $wpdb->insert($table, [
                'form_id' => $form_id,
                'sort' => $sort++,
                'props' => json_encode($props, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}

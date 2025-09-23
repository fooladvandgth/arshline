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
        // Fetch existing field IDs for this form
        $existing_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE form_id=%d", $form_id));
        $existing_lookup = [];
        foreach ($existing_ids as $eid) { $existing_lookup[(int)$eid] = true; }

        $seen_ids = [];
        $sort = 0;
        foreach ($fields as $f) {
            $props = isset($f['props']) ? $f['props'] : $f;
            $id = isset($f['id']) ? (int)$f['id'] : 0;
            if ($id > 0 && isset($existing_lookup[$id])) {
                // Update existing row: keep id, update sort and props
                $wpdb->update($table, [
                    'sort' => $sort,
                    'props' => json_encode($props, JSON_UNESCAPED_UNICODE),
                ], [
                    'id' => $id,
                    'form_id' => $form_id,
                ]);
                $seen_ids[] = $id;
            } else {
                // Insert new row
                $wpdb->insert($table, [
                    'form_id' => $form_id,
                    'sort' => $sort,
                    'props' => json_encode($props, JSON_UNESCAPED_UNICODE),
                ]);
                $new_id = (int)$wpdb->insert_id;
                if ($new_id) { $seen_ids[] = $new_id; $existing_lookup[$new_id] = true; }
            }
            $sort++;
        }
        // Delete rows no longer present
        if (!empty($existing_ids)) {
            $to_delete = array_values(array_diff(array_map('intval', $existing_ids), array_map('intval', $seen_ids)));
            if (!empty($to_delete)) {
                // Build placeholders for IN clause
                $placeholders = implode(',', array_fill(0, count($to_delete), '%d'));
                $sql = $wpdb->prepare("DELETE FROM {$table} WHERE form_id=%d AND id IN ($placeholders)", array_merge([$form_id], $to_delete));
                $wpdb->query($sql);
            }
        }
    }
}

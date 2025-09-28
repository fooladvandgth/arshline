<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;

class FormRepository
{
    public static function save(Form $form): int
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        // Ensure public token exists only for published forms (draft/disabled should not auto-generate tokens)
        if ($form->status === 'published' && !$form->public_token) {
            // try until unique
            do { $tok = Helpers::randomToken(9); $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE public_token=%s LIMIT 1", $tok)); } while ($exists);
            $form->public_token = $tok;
        }
        $data = [
            'schema_version' => $form->schema_version,
            'owner_id' => $form->owner_id,
            'status' => $form->status,
            'public_token' => $form->public_token,
            'meta' => json_encode($form->meta, JSON_UNESCAPED_UNICODE),
        ];
        if ($form->id > 0) {
            $wpdb->update($table, $data, ['id' => $form->id]);
            return $form->id;
        } else {
            $wpdb->insert($table, $data);
            return (int)$wpdb->insert_id;
        }
    }

    public static function find(int $id): ?Form
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        if ($row) {
            return new Form($row);
        }
        return null;
    }

    public static function findByToken(string $token): ?Form
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE public_token = %s LIMIT 1", $token), ARRAY_A);
        return $row ? new Form($row) : null;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $forms = Helpers::tableName('forms');
        $fields = Helpers::tableName('fields');
        $subs = Helpers::tableName('submissions');
        $subVals = Helpers::tableName('submission_values');
        // delete submission values -> submissions -> fields -> form
        $wpdb->query($wpdb->prepare("DELETE sv FROM {$subVals} sv INNER JOIN {$subs} s ON sv.submission_id = s.id WHERE s.form_id=%d", $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$subs} WHERE form_id=%d", $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$fields} WHERE form_id=%d", $id));
        $res = $wpdb->delete($forms, ['id' => $id]);
        return (bool)$res;
    }

    /**
     * Capture a lightweight snapshot of a form and its fields for audit/undo.
     */
    public static function snapshot(int $id): ?array
    {
        global $wpdb;
        $forms = Helpers::tableName('forms');
        $fields = Helpers::tableName('fields');
        $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$forms} WHERE id=%d", $id), ARRAY_A);
        if (!$form) return null;
        $fs = $wpdb->get_results($wpdb->prepare("SELECT sort, props, created_at FROM {$fields} WHERE form_id=%d ORDER BY sort ASC, id ASC", $id), ARRAY_A) ?: [];
        return [ 'form' => $form, 'fields' => $fs ];
    }

    /**
     * Restore a previously captured snapshot. Returns restored form id on success.
     */
    public static function restore(array $snapshot): int
    {
        global $wpdb;
        if (!isset($snapshot['form']) || !is_array($snapshot['form'])) return 0;
        $forms = Helpers::tableName('forms');
        $fields = Helpers::tableName('fields');
        $f = $snapshot['form'];
        // Prepare columns explicitly to allow inserting with original id and timestamps
        $data = [
            'id' => (int)$f['id'],
            'schema_version' => $f['schema_version'] ?? '1.0.0',
            'owner_id' => isset($f['owner_id']) ? (int)$f['owner_id'] : null,
            'status' => $f['status'] ?? 'draft',
            'public_token' => $f['public_token'] ?? null,
            'meta' => is_string($f['meta'] ?? null) ? $f['meta'] : json_encode($f['meta'] ?? [], JSON_UNESCAPED_UNICODE),
            'created_at' => $f['created_at'] ?? current_time('mysql'),
            'updated_at' => $f['updated_at'] ?? current_time('mysql'),
        ];
        // Insert form row with explicit columns
        $wpdb->query("SET FOREIGN_KEY_CHECKS=0");
        $ok = $wpdb->insert($forms, $data);
        $restoredId = $ok ? (int)$data['id'] : 0;
        if ($restoredId <= 0) { $wpdb->query("SET FOREIGN_KEY_CHECKS=1"); return 0; }
        // Restore fields (let auto ids generate)
        $fieldsRows = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        foreach ($fieldsRows as $row) {
            $wpdb->insert($fields, [
                'form_id' => $restoredId,
                'sort' => (int)($row['sort'] ?? 0),
                'props' => is_string($row['props'] ?? null) ? $row['props'] : json_encode($row['props'] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => $row['created_at'] ?? current_time('mysql'),
            ]);
        }
        $wpdb->query("SET FOREIGN_KEY_CHECKS=1");
        return $restoredId;
    }
}

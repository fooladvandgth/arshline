<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;

class FormRepository
{
    public static function save(Form $form): int
    {
        global $wpdb;
        $table = Helpers::tableName('forms');
        // Ensure public token exists
        if (!$form->public_token) {
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
}

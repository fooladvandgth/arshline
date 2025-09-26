<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;

class SubmissionRepository
{
    public static function save(Submission $submission): int
    {
        global $wpdb;
        $table = Helpers::tableName('submissions');
        $data = [
            'form_id' => $submission->form_id,
            'user_id' => $submission->user_id,
            'ip' => $submission->ip,
            'status' => $submission->status,
            'meta' => json_encode($submission->meta, JSON_UNESCAPED_UNICODE),
        ];
        if ($submission->id > 0) {
            $wpdb->update($table, $data, ['id' => $submission->id]);
            return $submission->id;
        } else {
            $wpdb->insert($table, $data);
            return (int)$wpdb->insert_id;
        }
    }

    public static function listByForm(int $form_id, int $limit = 100): array
    {
        global $wpdb;
        $table = Helpers::tableName('submissions');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, status, meta, created_at FROM {$table} WHERE form_id=%d ORDER BY id DESC LIMIT %d", $form_id, $limit), ARRAY_A);
        return array_map(function($r){
            return [
                'id' => (int)$r['id'],
                'status' => (string)$r['status'],
                'meta' => json_decode($r['meta'] ?: '{}', true),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows ?: []);
    }

    public static function findWithValues(int $submission_id): array
    {
        global $wpdb;
        $subs = Helpers::tableName('submissions');
        $vals = Helpers::tableName('submission_values');
        $s = $wpdb->get_row($wpdb->prepare("SELECT id, form_id, status, meta, created_at FROM {$subs} WHERE id=%d", $submission_id), ARRAY_A);
        if (!$s) return [];
        $values = $wpdb->get_results($wpdb->prepare("SELECT field_id, value, idx FROM {$vals} WHERE submission_id=%d ORDER BY idx ASC, id ASC", $submission_id), ARRAY_A);
        return [
            'id' => (int)$s['id'],
            'form_id' => (int)$s['form_id'],
            'status' => (string)$s['status'],
            'meta' => json_decode($s['meta'] ?: '{}', true),
            'created_at' => $s['created_at'] ?? null,
            'values' => array_map(function($v){ return [ 'field_id' => (int)$v['field_id'], 'value' => $v['value'], 'idx' => (int)$v['idx'] ]; }, $values ?: []),
        ];
    }
}

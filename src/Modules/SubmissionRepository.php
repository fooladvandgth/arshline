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
}

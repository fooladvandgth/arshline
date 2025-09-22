<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;

class SubmissionValueRepository
{
    public static function save(int $submission_id, int $field_id, $value, int $idx = 0): int
    {
        global $wpdb;
        $table = Helpers::tableName('submission_values');
        $data = [
            'submission_id' => $submission_id,
            'field_id' => $field_id,
            'value' => $value,
            'idx' => $idx,
        ];
        $wpdb->insert($table, $data);
        return (int)$wpdb->insert_id;
    }
}

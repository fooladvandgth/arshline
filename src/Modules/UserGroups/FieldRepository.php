<?php
namespace Arshline\Modules\UserGroups;

use Arshline\Support\Helpers;

class FieldRepository
{
    public static function listByGroup(int $groupId): array
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_fields');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE group_id=%d ORDER BY sort ASC, id ASC", $groupId), ARRAY_A) ?: [];
        return array_map(fn($r)=> new Field($r), $rows);
    }

    public static function save(Field $f): int
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_fields');
        $data = [
            'group_id' => $f->group_id,
            'name' => $f->name,
            'label' => $f->label,
            'type' => $f->type,
            'options' => json_encode($f->options, JSON_UNESCAPED_UNICODE),
            'required' => $f->required ? 1 : 0,
            'sort' => $f->sort,
        ];
        if ($f->id > 0) { $wpdb->update($t, $data, ['id' => $f->id]); return $f->id; }
        $wpdb->insert($t, $data); return (int)$wpdb->insert_id;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_fields');
        return (bool)$wpdb->delete($t, ['id' => $id]);
    }
}

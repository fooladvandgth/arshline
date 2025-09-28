<?php
namespace Arshline\Modules\UserGroups;

use Arshline\Support\Helpers;

class GroupRepository
{
    public static function all(): array
    {
        global $wpdb;
        $t = Helpers::tableName('user_groups');
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC LIMIT 200", ARRAY_A) ?: [];
        return array_map(fn($r) => new Group($r), $rows);
    }

    public static function find(int $id): ?Group
    {
        global $wpdb;
        $t = Helpers::tableName('user_groups');
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id), ARRAY_A);
        return $r ? new Group($r) : null;
    }

    public static function save(Group $g): int
    {
        global $wpdb;
        $t = Helpers::tableName('user_groups');
        $data = [ 'name' => $g->name, 'meta' => json_encode($g->meta, JSON_UNESCAPED_UNICODE) ];
        if ($g->id > 0) { $wpdb->update($t, $data, ['id' => $g->id]); return $g->id; }
        $wpdb->insert($t, $data); return (int)$wpdb->insert_id;
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        $t = Helpers::tableName('user_groups');
        return (bool)$wpdb->delete($t, ['id' => $id]);
    }
}

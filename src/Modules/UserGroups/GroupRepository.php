<?php
namespace Arshline\Modules\UserGroups;

use Arshline\Support\Helpers;

class GroupRepository
{
    public static function countAll(string $search = ''): int
    {
        global $wpdb;
        $t = Helpers::tableName('user_groups');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE name LIKE %s", $like));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
    }

    public static function paginated(int $per_page, int $page, string $search = '', string $orderby = 'id', string $order = 'DESC'): array
    {
        global $wpdb;
        $t = Helpers::tableName('user_groups');
        $offset = max(0, ($page - 1) * $per_page);
        $orderby = in_array($orderby, ['id','name'], true) ? $orderby : 'id';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $where = '';
        $params = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = 'WHERE name LIKE %s';
            $params[] = $like;
        }
    $sql = "SELECT * FROM {$t} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $per_page; $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
        return array_map(fn($r) => new Group($r), $rows);
    }

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
    $data = [ 'name' => $g->name, 'parent_id' => $g->parent_id, 'meta' => json_encode($g->meta, JSON_UNESCAPED_UNICODE) ];
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

<?php
namespace Arshline\Dashboard\ListTables;

use WP_List_Table;
use Arshline\Modules\UserGroups\GroupRepository;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Groups_List_Table extends WP_List_Table
{
    protected $items_per_page = 20;

    public function get_columns(): array
    {
        return [
            'id' => __('ID', 'arshline'),
            'name' => __('نام', 'arshline'),
            'member_count' => __('تعداد اعضا', 'arshline'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'id' => ['id', true],
            'name' => ['name', false],
        ];
    }

    protected function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'id':
                return intval($item['id']);
            case 'name':
                return esc_html($item['name'] ?? '');
            case 'member_count':
                return intval($item['member_count'] ?? 0);
            default:
                return '';
        }
    }

    public function prepare_items(): void
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $paged = max(1, intval($_GET['paged'] ?? 1));
        $per_page = max(1, intval($_GET['per_page'] ?? $this->items_per_page));
        $search = isset($_REQUEST['s']) ? sanitize_text_field((string) $_REQUEST['s']) : '';
        $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'id';
        $order = isset($_GET['order']) ? strtoupper(sanitize_key((string) $_GET['order'])) : 'DESC';
        if (!in_array($orderby, ['id','name'], true)) { $orderby = 'id'; }
        if (!in_array($order, ['ASC','DESC'], true)) { $order = 'DESC'; }

        // Fetch data
        $data = GroupRepository::paginated($per_page, $paged, $search, $orderby, $order);
        $total = GroupRepository::countAll($search);

        $this->items = [];
        foreach ($data as $g) {
            // Compute member count per group (acceptable for 20 rows/page)
            $this->items[] = [
                'id' => $g->id,
                'name' => $g->name,
                'member_count' => $g->member_count ?? $this->countMembers($g->id),
            ];
        }

        $this->set_pagination_args([
            'total_items' => intval($total),
            'per_page' => intval($per_page),
            'total_pages' => max(1, (int) ceil($total / $per_page)),
        ]);
    }

    protected function countMembers(int $group_id): int
    {
        global $wpdb;
        $t = \Arshline\Support\Helpers::tableName('user_group_members');
        $cnt = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE group_id=%d", $group_id));
        return $cnt;
    }
}

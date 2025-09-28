<?php
namespace Arshline\Dashboard\ListTables;

use WP_List_Table;
use Arshline\Modules\UserGroups\MemberRepository;
use Arshline\Modules\UserGroups\FieldRepository;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Members_List_Table extends WP_List_Table
{
    protected $group_id;
    protected $items_per_page = 20;
    protected $custom_fields = [];

    public function __construct(int $group_id, array $args = [])
    {
        parent::__construct($args);
        $this->group_id = $group_id;
        $this->custom_fields = FieldRepository::listByGroup($group_id);
    }

    public function get_columns(): array
    {
        $cols = [
            'id' => __('ID', 'arshline'),
            'name' => __('نام', 'arshline'),
            'phone' => __('شماره همراه', 'arshline'),
        ];
        foreach ($this->custom_fields as $f) {
            $label = (is_string($f->label) && $f->label !== '') ? $f->label : $f->name;
            $cols['cf_' . $f->id] = esc_html($label);
        }
        return $cols;
    }

    protected function get_sortable_columns(): array
    {
        return [
            'id' => ['id', true],
            'name' => ['name', false],
            'phone' => ['phone', false],
        ];
    }

    protected function column_default($item, $column_name)
    {
        if ($column_name === 'id') return intval($item['id']);
        if ($column_name === 'name') return esc_html($item['name'] ?? '');
        if ($column_name === 'phone') return esc_html($item['phone'] ?? '');
        if (strpos($column_name, 'cf_') === 0) {
            $fid = intval(substr($column_name, 3));
            $data = $item['data'] ?? [];
            $field = null;
            foreach ($this->custom_fields as $f) { if ($f->id === $fid) { $field = $f; break; } }
            $key = $field ? $field->name : '';
            $val = ($key && isset($data[$key]) && is_scalar($data[$key])) ? (string) $data[$key] : '';
            return esc_html($val);
        }
        return '';
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
        if (!in_array($orderby, ['id','name','phone'], true)) { $orderby = 'id'; }
        if (!in_array($order, ['ASC','DESC'], true)) { $order = 'DESC'; }

        $data = MemberRepository::paginated($this->group_id, $per_page, $paged, $search, $orderby, $order);
        $total = MemberRepository::countAll($this->group_id, $search);

        $this->items = [];
        foreach ($data as $m) {
            $this->items[] = [
                'id' => $m->id,
                'name' => $m->name,
                'phone' => $m->phone,
                'data' => is_array($m->data) ? $m->data : (json_decode($m->data ?? '[]', true) ?: []),
            ];
        }

        $this->set_pagination_args([
            'total_items' => intval($total),
            'per_page' => intval($per_page),
            'total_pages' => max(1, (int) ceil($total / $per_page)),
        ]);
    }
}

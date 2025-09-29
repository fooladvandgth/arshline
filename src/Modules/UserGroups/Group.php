<?php
namespace Arshline\Modules\UserGroups;

class Group
{
    public int $id = 0;
    public string $name = '';
    public ?int $parent_id = null;
    public array $meta = [];
    public string $created_at = '';
    public string $updated_at = '';

    public function __construct(array $row = [])
    {
        $this->id = isset($row['id']) ? (int)$row['id'] : 0;
        $this->name = isset($row['name']) ? (string)$row['name'] : '';
    $this->parent_id = isset($row['parent_id']) && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
        $m = $row['meta'] ?? [];
        if (is_string($m)) {
            $d = json_decode($m, true);
            $m = is_array($d) ? $d : [];
        }
        $this->meta = is_array($m) ? $m : [];
        $this->created_at = isset($row['created_at']) ? (string)$row['created_at'] : '';
        $this->updated_at = isset($row['updated_at']) ? (string)$row['updated_at'] : '';
    }
}

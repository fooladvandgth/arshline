<?php
namespace Arshline\Modules\UserGroups;

class Field
{
    public int $id = 0;
    public int $group_id = 0;
    public string $name = '';
    public string $label = '';
    public string $type = 'text';
    public array $options = [];
    public bool $required = false;
    public int $sort = 0;
    public ?string $created_at = null;

    public function __construct(array $row = [])
    {
        $this->id = (int)($row['id'] ?? 0);
        $this->group_id = (int)($row['group_id'] ?? 0);
        $this->name = (string)($row['name'] ?? '');
        $this->label = (string)($row['label'] ?? '');
        $this->type = (string)($row['type'] ?? 'text');
        $opts = $row['options'] ?? [];
        if (is_string($opts)) { $opts = json_decode($opts, true) ?: []; }
        $this->options = is_array($opts) ? $opts : [];
        $this->required = (bool)($row['required'] ?? false);
        $this->sort = (int)($row['sort'] ?? 0);
        $this->created_at = isset($row['created_at']) ? (string)$row['created_at'] : null;
    }
}

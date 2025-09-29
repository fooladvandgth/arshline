<?php
namespace Arshline\Modules\UserGroups;

class Member
{
    public int $id = 0;
    public int $group_id = 0;
    public string $name = '';
    public string $phone = '';
    public array $data = [];
    public ?string $token = null;
    public ?string $token_hash = null;
    public string $created_at = '';
    public string $updated_at = '';

    public function __construct(array $row = [])
    {
        $this->id = isset($row['id']) ? (int)$row['id'] : 0;
        $this->group_id = isset($row['group_id']) ? (int)$row['group_id'] : 0;
        $this->name = isset($row['name']) ? (string)$row['name'] : '';
        $this->phone = isset($row['phone']) ? (string)$row['phone'] : '';
        $d = $row['data'] ?? [];
        if (is_string($d)) {
            $dd = json_decode($d, true);
            $d = is_array($dd) ? $dd : [];
        }
        $this->data = is_array($d) ? $d : [];
        $this->token = isset($row['token']) ? (string)$row['token'] : null;
        $this->token_hash = isset($row['token_hash']) ? (string)$row['token_hash'] : null;
        $this->created_at = isset($row['created_at']) ? (string)$row['created_at'] : '';
        $this->updated_at = isset($row['updated_at']) ? (string)$row['updated_at'] : '';
    }
}

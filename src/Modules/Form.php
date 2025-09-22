<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;
use Arshline\Database\Migrations;

class Form
{
    public int $id;
    public string $schema_version;
    public int $owner_id;
    public string $status;
    public array $meta;
    public array $fields;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? 0;
        $this->schema_version = $data['schema_version'] ?? '1.0.0';
        $this->owner_id = $data['owner_id'] ?? 0;
        $this->status = $data['status'] ?? 'draft';
        $this->meta = isset($data['meta']) ? json_decode($data['meta'], true) : [];
        $this->fields = $data['fields'] ?? [];
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        return new self($data);
    }

    public function toJson(): string
    {
        return json_encode([
            'id' => $this->id,
            'schema_version' => $this->schema_version,
            'owner_id' => $this->owner_id,
            'status' => $this->status,
            'meta' => $this->meta,
            'fields' => $this->fields,
        ], JSON_UNESCAPED_UNICODE);
    }
}

<?php
namespace Arshline\Modules\Forms;

class Submission
{
    public int $id;
    public int $form_id;
    public int $user_id;
    public string $ip;
    public string $status;
    public array $meta;
    public array $values;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? 0;
        $this->form_id = $data['form_id'] ?? 0;
        $this->user_id = $data['user_id'] ?? 0;
        $this->ip = $data['ip'] ?? '';
        $this->status = $data['status'] ?? 'pending';
        if (!isset($data['meta'])) $this->meta = [];
        elseif (is_array($data['meta'])) $this->meta = $data['meta'];
        else $this->meta = json_decode((string)$data['meta'] ?: '{}', true) ?: [];
        $this->values = is_array($data['values'] ?? null) ? $data['values'] : [];
    }
}

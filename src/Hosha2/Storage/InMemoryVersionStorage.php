<?php
namespace Arshline\Hosha2\Storage;

use RuntimeException;

/**
 * Simple in-memory storage for tests & fallback.
 * Not persistent between requests; IDs are globally incrementing.
 */
class InMemoryVersionStorage implements Hosha2VersionStorageInterface
{
    /** @var array<int,array{form_id:int,config:array,metadata:array,created_at:string}> */
    private array $store = [];
    /** @var array<int,int[]> formId => list of version ids newest→oldest */
    private array $index = [];
    private int $nextId = 1;

    public function save(int $formId, array $config, array $metadata = []): int
    {
        $id = $this->nextId++;
        $created = gmdate('Y-m-d H:i:s');
        // Defensive deep copy (avoid external mutation)
        $snapshot = [
            'form_id' => $formId,
            'config' => $config,
            'metadata' => $metadata,
            'created_at' => $created,
        ];
        $this->store[$id] = $snapshot;
        if (!isset($this->index[$formId])) $this->index[$formId] = [];
        array_unshift($this->index[$formId], $id); // newest first
        return $id;
    }

    public function get(int $versionId): ?array
    {
        if (!isset($this->store[$versionId])) return null;
        $s = $this->store[$versionId];
        // return deep copy semantics
        return [
            'form_id' => $s['form_id'],
            'config' => $s['config'],
            'metadata' => $s['metadata'],
            'created_at' => $s['created_at'],
        ];
    }

    public function list(int $formId, int $limit = 10, int $offset = 0): array
    {
        $ids = $this->index[$formId] ?? [];
        if ($offset < 0) $offset = 0;
        $slice = array_slice($ids, $offset, $limit);
        $out = [];
        foreach ($slice as $id) {
            $snap = $this->store[$id];
            $out[] = [
                'version_id' => $id,
                'created_at' => $snap['created_at'],
                'metadata' => $snap['metadata'],
            ];
        }
        return $out;
    }

    public function count(int $formId): int
    {
        return isset($this->index[$formId]) ? count($this->index[$formId]) : 0;
    }

    public function prune(int $formId, int $keepLast = 5): int
    {
        if ($keepLast < 0) throw new RuntimeException('keepLast must be >= 0');
        $ids = $this->index[$formId] ?? [];
        $count = count($ids);
        if ($count <= $keepLast) return 0;
        $toDelete = array_slice($ids, $keepLast); // tail (older)
        $this->index[$formId] = array_slice($ids, 0, $keepLast);
        $deleted = 0;
        foreach ($toDelete as $id) {
            unset($this->store[$id]);
            $deleted++;
        }
        return $deleted;
    }
}

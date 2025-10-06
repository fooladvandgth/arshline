<?php
namespace Arshline\Hosha2\Storage;

/**
 * Storage abstraction for Hosha2 version snapshots.
 * Implementations must guarantee monotonically increasing integer version IDs per save()
 * and stable retrieval until pruned/deleted.
 */
interface Hosha2VersionStorageInterface
{
    /**
     * Persist a snapshot.
     * @param int $formId
     * @param array $config Arbitrary form config (will be JSON encoded by adapter if needed)
     * @param array $metadata Arbitrary metadata (user_prompt, tokens_used, created_by, diff_applied, diff_sha, etc.)
     * @return int Newly assigned version id (unique across all versions – or at least per form) 
     * @throws \RuntimeException on failure
     */
    public function save(int $formId, array $config, array $metadata = []): int;

    /**
     * Fetch a snapshot by version id.
     * @param int $versionId
     * @return array|null [ 'form_id'=>int, 'config'=>array, 'metadata'=>array, 'created_at'=>string ] or null if not found
     */
    public function get(int $versionId): ?array;

    /**
     * List versions for a form ordered newest→oldest.
     * @param int $formId
     * @param int $limit Max rows (default 10)
     * @return array Each: [ 'version_id'=>int, 'created_at'=>string, 'metadata'=>array ]
     */
    public function list(int $formId, int $limit = 10): array;

    /**
     * Prune old versions keeping the most recent $keepLast entries.
     * @param int $formId
     * @param int $keepLast Number of most recent versions to keep
     * @return int Deleted count
     */
    public function prune(int $formId, int $keepLast = 5): int;
}

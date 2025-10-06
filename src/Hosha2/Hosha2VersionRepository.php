<?php
namespace Arshline\Hosha2;

use RuntimeException;
use Arshline\Hosha2\Storage\{Hosha2VersionStorageInterface, InMemoryVersionStorage};

/**
 * Refactored VersionRepository (F5) delegating persistence to a storage adapter.
 * - Maintains previous public API (saveSnapshot/getSnapshot/listVersions/cleanupOldVersions)
 * - Provides backward compatibility via in-memory fallback when no storage injected.
 */
class Hosha2VersionRepository
{
    private ?Hosha2LoggerInterface $logger;
    private Hosha2VersionStorageInterface $storage;

    public function __construct(?Hosha2LoggerInterface $logger = null, ?Hosha2VersionStorageInterface $storage = null)
    {
        $this->logger = $logger;
        if ($storage === null) {
            // Backward compatibility fallback
            $storage = new InMemoryVersionStorage();
            if ($this->logger) {
                $this->logger->log('version_storage_fallback', [ 'adapter' => 'in_memory' ], 'WARN');
            }
        }
        $this->storage = $storage;
    }

    /**
     * Persist a version snapshot of generated form config.
     * @param int $formId
     * @param array $config Full form structure (fields/settings/...)
     * @param array $metadata Arbitrary extra info (prompt, tokens, created_by, diff_applied)
     * @param string|null $diffSha SHA1 hash of original diff array (pre-apply) if available
     */
    public function saveSnapshot(int $formId, array $config, array $metadata = [], ?string $diffSha = null): int
    {
        // Aggregate legacy shaped metadata into flat array (storage implementations keep opaque)
        $meta = [
            'user_prompt'  => $metadata['user_prompt'] ?? ($metadata['prompt'] ?? ''),
            'tokens_used'  => (int)($metadata['tokens_used'] ?? 0),
            'created_by'   => (int)($metadata['created_by'] ?? 0),
            'diff_applied' => !empty($metadata['diff_applied']),
        ];
        if ($diffSha !== null) {
            $meta['diff_sha'] = $diffSha;
        }
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode config JSON');
        }
        $id = $this->storage->save($formId, $config, $meta);
        if ($this->logger) {
            $this->logger->log('version_saved', [
                'version_id'   => $id,
                'form_id'      => $formId,
                'config_size'  => strlen($json),
                'has_metadata' => !empty($meta),
            ], 'INFO');
        }
        return $id;
    }

    public function getSnapshot(int $versionId): ?array
    {
        $snap = $this->storage->get($versionId);
        if ($snap === null) {
            if ($this->logger) $this->logger->log('version_retrieved', ['version_id'=>$versionId,'found'=>false], 'INFO');
            return null;
        }
        if ($this->logger) $this->logger->log('version_retrieved', ['version_id'=>$versionId,'found'=>true,'config_size'=>strlen(json_encode($snap['config'], JSON_UNESCAPED_UNICODE))], 'INFO');
        // Return in legacy structure (config, metadata, created_at)
        return [
            'config'     => $snap['config'],
            'metadata'   => $this->mapMetadataToLegacy($snap['metadata'] ?? [], $snap['form_id'] ?? null),
            'created_at' => $snap['created_at'] ?? '',
        ];
    }

    public function listVersions(int $formId, int $limit = 10): array
    {
        $list = $this->storage->list($formId, $limit);
        $out = [];
        foreach ($list as $row) {
            $out[] = [
                'version_id' => $row['version_id'],
                'created_at' => $row['created_at'] ?? '',
                'metadata'   => $this->mapMetadataSubset($row['metadata'] ?? []),
            ];
        }
        if ($this->logger) $this->logger->log('versions_listed', ['form_id'=>$formId,'count'=>count($out),'limit'=>$limit], 'INFO');
        return $out;
    }

    public function cleanupOldVersions(int $formId, int $keepLast = 5): int
    {
        $deleted = $this->storage->prune($formId, $keepLast);
        if ($this->logger) $this->logger->log('versions_cleaned',[ 'form_id'=>$formId,'deleted_count'=>$deleted,'kept_count'=>$keepLast ],'INFO');
        return $deleted;
    }

    private function mapMetadataToLegacy(array $meta, ?int $formId): array
    {
        // Preserve legacy meta key formatting so older callers (if any) remain compatible
        return [
            '_hosha2_form_id'      => $formId ?? ($meta['form_id'] ?? 0),
            '_hosha2_user_prompt'  => $meta['user_prompt'] ?? '',
            '_hosha2_tokens_used'  => $meta['tokens_used'] ?? 0,
            '_hosha2_created_by'   => $meta['created_by'] ?? 0,
            '_hosha2_diff_applied' => !empty($meta['diff_applied']) ? 1 : 0,
            '_hosha2_diff_sha'     => $meta['diff_sha'] ?? '',
        ];
    }

    private function mapMetadataSubset(array $meta): array
    {
        // Only subset used by legacy listVersions tests
        return [
            '_hosha2_user_prompt'  => $meta['user_prompt'] ?? '',
            '_hosha2_tokens_used'  => $meta['tokens_used'] ?? 0,
            '_hosha2_diff_applied' => !empty($meta['diff_applied']) ? 1 : 0,
        ];
    }
}
?>
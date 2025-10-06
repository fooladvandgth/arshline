<?php
namespace Arshline\Hosha2\Storage;

use RuntimeException;

/**
 * WordPress-backed storage adapter (post + meta) with automatic fallback to in-memory when WP functions absent.
 *
 * Design decisions (Phase F5):
 * - Link form via post_parent (no dedicated _hosha2_form_id meta for now – YAGNI/optimization deferred).
 * - Store config JSON in meta key _hosha2_config (post_content left empty for clarity / future indexing strategy).
 * - Store metadata JSON blob in _hosha2_metadata; if a diff_sha provided inside metadata['diff_sha'] also duplicate to _hosha2_diff_sha for quick lookups.
 * - Version ID == post ID.
 */
class TransientMetaVersionStorage implements Hosha2VersionStorageInterface
{
    private ?InMemoryVersionStorage $fallback = null;

    public function __construct()
    {
        if (!function_exists('wp_insert_post')) {
            $this->fallback = new InMemoryVersionStorage();
        }
    }

    private function usingFallback(): bool
    {
        return $this->fallback !== null;
    }

    public function save(int $formId, array $config, array $metadata = []): int
    {
        if ($this->usingFallback()) {
            return $this->fallback->save($formId, $config, $metadata);
        }
        // WP mode
        $postArr = [
            'post_type'   => 'hosha2_version',
            'post_status' => 'private',
            'post_parent' => $formId,
            'post_title'  => 'Version ' . gmdate('Y-m-d H:i:s'),
            'post_content'=> '',
        ];
        $id = wp_insert_post($postArr, true);
        if (function_exists('is_wp_error') && is_wp_error($id)) {
            throw new RuntimeException('Failed to insert version post: ' . $id->get_error_message());
        }
        if (!is_int($id)) {
            throw new RuntimeException('Unexpected non-integer post id');
        }
        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($configJson === false) throw new RuntimeException('Failed to encode config JSON');
        $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) throw new RuntimeException('Failed to encode metadata JSON');
        update_post_meta($id, '_hosha2_config', $configJson);
        update_post_meta($id, '_hosha2_metadata', $metaJson);
        if (isset($metadata['diff_sha'])) update_post_meta($id, '_hosha2_diff_sha', (string)$metadata['diff_sha']);
        return $id;
    }

    public function get(int $versionId): ?array
    {
        if ($this->usingFallback()) return $this->fallback->get($versionId);
        $post = get_post($versionId);
        if (!$post || (isset($post->post_type) && $post->post_type !== 'hosha2_version')) return null;
        $configJson = get_post_meta($versionId, '_hosha2_config', true);
        $metaJson   = get_post_meta($versionId, '_hosha2_metadata', true);
        $config = is_string($configJson) && $configJson !== '' ? json_decode($configJson, true) : [];
        if (!is_array($config)) $config = [];
        $metadata = is_string($metaJson) && $metaJson !== '' ? json_decode($metaJson, true) : [];
        if (!is_array($metadata)) $metadata = [];
        $createdAt = $post->post_date_gmt ?? $post->post_date ?? '';
        return [
            'form_id'    => (int)($post->post_parent ?? 0),
            'config'     => $config,
            'metadata'   => $metadata,
            'created_at' => $createdAt,
        ];
    }

    public function list(int $formId, int $limit = 10): array
    {
        if ($this->usingFallback()) return $this->fallback->list($formId, $limit);
        $posts = get_posts([
            'post_type'      => 'hosha2_version',
            'post_parent'    => $formId,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $out = [];
        foreach ($posts as $p) {
            $metaJson = get_post_meta($p->ID, '_hosha2_metadata', true);
            $metadata = is_string($metaJson) && $metaJson !== '' ? json_decode($metaJson, true) : [];
            if (!is_array($metadata)) $metadata = [];
            $out[] = [
                'version_id' => (int)$p->ID,
                'created_at' => $p->post_date_gmt ?? $p->post_date,
                'metadata'   => $metadata,
            ];
        }
        return $out;
    }

    public function prune(int $formId, int $keepLast = 5): int
    {
        if ($this->usingFallback()) return $this->fallback->prune($formId, $keepLast);
        if ($keepLast < 0) throw new RuntimeException('keepLast must be >= 0');
        $posts = get_posts([
            'post_type'      => 'hosha2_version',
            'post_parent'    => $formId,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $count = count($posts);
        if ($count <= $keepLast) return 0;
        $toDelete = array_slice($posts, $keepLast);
        $deleted = 0;
        foreach ($toDelete as $p) {
            wp_delete_post($p->ID, true);
            $deleted++;
        }
        return $deleted;
    }
}

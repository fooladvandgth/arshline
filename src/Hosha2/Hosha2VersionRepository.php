<?php
namespace Arshline\Hosha2;

use RuntimeException;

class Hosha2VersionRepository
{
    private ?Hosha2LoggerInterface $logger;
    public function __construct(?Hosha2LoggerInterface $logger = null)
    {
        $this->logger = $logger;
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
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode config JSON');
        }
        $title = 'Version ' . date('Y-m-d H:i:s') . ' - Form #' . $formId;
        $postArr = [
            'post_type' => 'hosha2_version',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $json,
        ];
        // Prefer WP functions unless a dedicated test harness (global shim) is present.
        $useFake = !function_exists('wp_insert_post');
        if (function_exists('wp_insert_post') && isset($GLOBALS['hosha2_test_version_repo'])) {
            // In test harness we want to route through the shim rather than real WP
            $useFake = true;
        }
        if ($useFake) {
            $id = $this->fakeInsert($postArr);
        } else {
            $id = wp_insert_post($postArr, true);
            if (function_exists('is_wp_error') && is_wp_error($id)) {
                if ($this->logger) $this->logger->log('version_saved', ['error' => $id->get_error_message(), 'form_id'=>$formId], 'ERROR');
                throw new RuntimeException('Failed to insert version post: ' . $id->get_error_message());
            }
        }
        // Meta fields
        $metaMap = [
            '_hosha2_form_id' => $formId,
            '_hosha2_user_prompt' => $metadata['user_prompt'] ?? '',
            '_hosha2_tokens_used' => (int)($metadata['tokens_used'] ?? 0),
            '_hosha2_created_by' => (int)($metadata['created_by'] ?? 0),
            '_hosha2_diff_applied' => !empty($metadata['diff_applied']) ? 1 : 0,
            '_hosha2_diff_sha' => $diffSha ?? '',
        ];
        foreach ($metaMap as $k=>$v) {
            if (function_exists('update_post_meta')) update_post_meta($id, $k, $v);
            else $this->fakeMeta($id, $k, $v); // test no WP
        }
        if ($this->logger) {
            $this->logger->log('version_saved', [
                'version_id' => $id,
                'form_id' => $formId,
                'config_size' => strlen($json),
                'has_metadata' => !empty($metadata),
            ], 'INFO');
        }
        return (int)$id;
    }

    public function getSnapshot(int $versionId): ?array
    {
    $post = function_exists('get_post') ? get_post($versionId) : $this->fakeGet($versionId);
        if (!$post || (is_object($post) && isset($post->post_type) && $post->post_type !== 'hosha2_version')) {
            if ($this->logger) $this->logger->log('version_retrieved', ['version_id'=>$versionId,'found'=>false], 'INFO');
            return null;
        }
        $json = is_object($post) ? $post->post_content : ($post['post_content'] ?? '');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            if ($this->logger) $this->logger->log('version_retrieved', ['version_id'=>$versionId,'found'=>false,'json_error'=>json_last_error_msg()], 'WARN');
            return null;
        }
    $metaKeys = ['_hosha2_form_id','_hosha2_user_prompt','_hosha2_tokens_used','_hosha2_created_by','_hosha2_diff_applied','_hosha2_diff_sha'];
        $meta = [];
        foreach ($metaKeys as $k) {
            if (function_exists('get_post_meta')) {
                $meta[$k] = get_post_meta($versionId, $k, true);
            } else {
                $meta[$k] = $this->fakeMetaGet($versionId, $k);
            }
        }
        $createdAt = is_object($post) ? ($post->post_date_gmt ?? $post->post_date ?? '') : ($post['post_date'] ?? '');
        if ($this->logger) $this->logger->log('version_retrieved', ['version_id'=>$versionId,'found'=>true,'config_size'=>strlen($json)], 'INFO');
        return [
            'config' => $decoded,
            'metadata' => $meta,
            'created_at' => $createdAt,
        ];
    }

    public function listVersions(int $formId, int $limit = 10): array
    {
        $posts = [];
    if (function_exists('get_posts')) {
            $posts = get_posts([
                'post_type' => 'hosha2_version',
                'posts_per_page' => $limit,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => [
                    [
                        'key' => '_hosha2_form_id',
                        'value' => $formId,
                        'compare' => '=',
                    ]
                ]
            ]);
        } else {
            $posts = $this->fakeQuery($formId, $limit);
        }
        $out = [];
        foreach ($posts as $p) {
            $vid = is_object($p) ? $p->ID : $p['ID'];
            $meta = [];
            foreach (['_hosha2_user_prompt','_hosha2_tokens_used','_hosha2_diff_applied'] as $mk) {
                if (function_exists('get_post_meta')) $meta[$mk] = get_post_meta($vid, $mk, true); else $meta[$mk] = $this->fakeMetaGet($vid,$mk);
            }
            $out[] = [
                'version_id' => $vid,
                'created_at' => is_object($p) ? ($p->post_date_gmt ?? $p->post_date) : $p['post_date'],
                'metadata' => $meta,
            ];
        }
        if ($this->logger) $this->logger->log('versions_listed', ['form_id'=>$formId,'count'=>count($out),'limit'=>$limit], 'INFO');
        return $out;
    }

    public function cleanupOldVersions(int $formId, int $keepLast = 5): int
    {
    $all = function_exists('get_posts') ? get_posts([
            'post_type'=>'hosha2_version','posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC',
            'meta_query'=>[[ 'key'=>'_hosha2_form_id','value'=>$formId,'compare'=>'=' ]]
        ]) : $this->fakeQuery($formId, PHP_INT_MAX);
        $count = count($all);
        if ($count <= $keepLast) {
            if ($this->logger) $this->logger->log('versions_cleaned',['form_id'=>$formId,'deleted_count'=>0,'kept_count'=>$count],'INFO');
            return 0;
        }
        $toDelete = array_slice($all, $keepLast); // older tail
        $deleted = 0;
        foreach ($toDelete as $p) {
            $vid = is_object($p) ? $p->ID : $p['ID'];
            if (function_exists('wp_delete_post')) wp_delete_post($vid, true); else $this->fakeDelete($vid);
            $deleted++;
        }
        if ($this->logger) $this->logger->log('versions_cleaned',[ 'form_id'=>$formId,'deleted_count'=>$deleted,'kept_count'=>$keepLast ],'INFO');
        return $deleted;
    }

    // ----- Test fallback storage (non-WP) -----
    private function fakeInsert(array $post): int
    {
        // If test harness object present, delegate id sequencing to its internal counter by inserting then returning highest id
        if (isset($GLOBALS['hosha2_test_version_repo']) && method_exists($GLOBALS['hosha2_test_version_repo'],'wpInsertPost')) {
            // Call harness shim to ensure its internal structures populate
            $id = $GLOBALS['hosha2_test_version_repo']->wpInsertPost($post, false);
            // Mirror into local fake store for uniform later retrieval
            global $hosha2_fake_versions; if(!isset($hosha2_fake_versions)) $hosha2_fake_versions=[];
            $post['ID']=$id; $post['post_date']=$post['post_date'] ?? date('Y-m-d H:i:s');
            $hosha2_fake_versions[$id]=$post;
            return (int)$id;
        }
        global $hosha2_fake_versions, $hosha2_fake_versions_meta; if(!isset($hosha2_fake_versions)) $hosha2_fake_versions=[]; if(!isset($hosha2_fake_versions_meta)) $hosha2_fake_versions_meta=[];
        $id = count($hosha2_fake_versions)+1; $post['ID']=$id; $post['post_date']=date('Y-m-d H:i:s'); $hosha2_fake_versions[$id]=$post; return $id;
    }
    private function fakeMeta(int $id,string $k,$v): void { global $hosha2_fake_versions_meta; $hosha2_fake_versions_meta[$id][$k]=$v; }
    private function fakeMetaGet(int $id,string $k){ global $hosha2_fake_versions_meta; return $hosha2_fake_versions_meta[$id][$k] ?? ''; }
    private function fakeGet(int $id){ global $hosha2_fake_versions; return $hosha2_fake_versions[$id] ?? null; }
    private function fakeQuery(int $formId,int $limit): array {
        if (isset($GLOBALS['hosha2_test_version_repo']) && method_exists($GLOBALS['hosha2_test_version_repo'],'queryPosts')) {
            $posts = $GLOBALS['hosha2_test_version_repo']->queryPosts(['meta_query'=>[['value'=>$formId]],'posts_per_page'=>$limit]);
            // Also mirror into fake store if not present
            global $hosha2_fake_versions; if(!isset($hosha2_fake_versions)) $hosha2_fake_versions=[];
            foreach ($posts as $p) {
                $arr = is_object($p)? (array)$p : $p;
                $hosha2_fake_versions[$arr['ID']] = $arr + ['post_date'=>$arr['post_date'] ?? date('Y-m-d H:i:s')];
            }
            return $posts;
        }
        global $hosha2_fake_versions,$hosha2_fake_versions_meta; $out=[]; if(!isset($hosha2_fake_versions)) return [];
        foreach ($hosha2_fake_versions as $id=>$p) { $meta=$hosha2_fake_versions_meta[$id]??[]; if (($meta['_hosha2_form_id'] ?? null)==$formId) $out[]=$p; }
        usort($out, function($a,$b){ return strcmp($b['post_date'],$a['post_date']); });
        return array_slice($out,0,$limit);
    }
    private function fakeDelete(int $id): void {
        if (isset($GLOBALS['hosha2_test_version_repo']) && method_exists($GLOBALS['hosha2_test_version_repo'],'deletePost')) {
            $GLOBALS['hosha2_test_version_repo']->deletePost($id); return; }
        global $hosha2_fake_versions,$hosha2_fake_versions_meta; unset($hosha2_fake_versions[$id], $hosha2_fake_versions_meta[$id]); }
}
?>
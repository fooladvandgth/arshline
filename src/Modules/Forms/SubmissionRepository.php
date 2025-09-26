<?php
namespace Arshline\Modules\Forms;

use Arshline\Support\Helpers;

class SubmissionRepository
{
    public static function save(Submission $submission): int
    {
        global $wpdb;
        $table = Helpers::tableName('submissions');
        $data = [
            'form_id' => $submission->form_id,
            'user_id' => $submission->user_id,
            'ip' => $submission->ip,
            'status' => $submission->status,
            'meta' => json_encode($submission->meta, JSON_UNESCAPED_UNICODE),
        ];
        if ($submission->id > 0) {
            $wpdb->update($table, $data, ['id' => $submission->id]);
            return $submission->id;
        } else {
            $wpdb->insert($table, $data);
            return (int)$wpdb->insert_id;
        }
    }

    public static function listByForm(int $form_id, int $limit = 100): array
    {
        global $wpdb;
        $table = Helpers::tableName('submissions');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, status, meta, created_at FROM {$table} WHERE form_id=%d ORDER BY id DESC LIMIT %d", $form_id, $limit), ARRAY_A);
        return array_map(function($r){
            return [
                'id' => (int)$r['id'],
                'status' => (string)$r['status'],
                'meta' => json_decode($r['meta'] ?: '{}', true),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows ?: []);
    }

    /**
     * Paginated listing with optional filters.
     * Filters supported: status (string), from (Y-m-d), to (Y-m-d), search (string or numeric id)
     */
    public static function listByFormPaged(int $form_id, int $page = 1, int $per_page = 20, array $filters = []): array
    {
        global $wpdb;
        $table = Helpers::tableName('submissions');
        $vals = Helpers::tableName('submission_values');
        $where = [ $wpdb->prepare('s.form_id=%d', $form_id) ];
        $params = [];
        // status filter
        if (!empty($filters['status'])){
            $where[] = 's.status = %s';
            $params[] = (string)$filters['status'];
        }
        // date range filters (created_at)
        if (!empty($filters['from'])){
            $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from']) ? ($filters['from'] . ' 00:00:00') : null;
            if ($from){ $where[] = 's.created_at >= %s'; $params[] = $from; }
        }
        if (!empty($filters['to'])){
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to']) ? ($filters['to'] . ' 23:59:59') : null;
            if ($to){ $where[] = 's.created_at <= %s'; $params[] = $to; }
        }
        // search by id or meta like
        if (isset($filters['search']) && $filters['search'] !== ''){
            $q = (string)$filters['search'];
            if (ctype_digit($q)){
                $where[] = 's.id = %d';
                $params[] = (int)$q;
            } else {
                $where[] = 's.meta LIKE %s';
                $params[] = '%' . $wpdb->esc_like($q) . '%';
            }
        }
        // answers full-text within submission_values
        if (!empty($filters['answers'])){
            $ans = '%' . $wpdb->esc_like((string)$filters['answers']) . '%';
            $where[] = "EXISTS (SELECT 1 FROM {$vals} sv WHERE sv.submission_id = s.id AND sv.value LIKE %s)";
            $params[] = $ans;
        }
        // per-field filters: each must match in its own EXISTS
        if (!empty($filters['field_filters']) && is_array($filters['field_filters'])){
            $ops = is_array($filters['field_ops'] ?? null) ? $filters['field_ops'] : [];
            foreach ($filters['field_filters'] as $fid => $val){
                $fid = (int)$fid; $val = (string)$val; if ($fid<=0 || $val==='') continue;
                $op = isset($ops[$fid]) ? $ops[$fid] : 'like';
                if ($op === 'eq'){
                    $where[] = "EXISTS (SELECT 1 FROM {$vals} svf WHERE svf.submission_id = s.id AND svf.field_id = %d AND svf.value = %s)";
                    $params[] = $fid; $params[] = $val;
                } else if ($op === 'neq'){
                    $where[] = "EXISTS (SELECT 1 FROM {$vals} svf WHERE svf.submission_id = s.id AND svf.field_id = %d AND svf.value <> %s)";
                    $params[] = $fid; $params[] = $val;
                } else { // like
                    $where[] = "EXISTS (SELECT 1 FROM {$vals} svf WHERE svf.submission_id = s.id AND svf.field_id = %d AND svf.value LIKE %s)";
                    $params[] = $fid; $params[] = '%' . $wpdb->esc_like($val) . '%';
                }
            }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $page = max(1, $page);
        $per_page = max(1, min($per_page, 100));
        $offset = ($page - 1) * $per_page;

        // total count
        $sqlCount = "SELECT COUNT(*) FROM {$table} s {$whereSql}";
        // Avoid calling prepare() when there are no placeholders/params (prevents WPDB warnings that can surface as 500)
        if (empty($params)) {
            $total = (int)$wpdb->get_var($sqlCount);
        } else {
            $total = (int)$wpdb->get_var($wpdb->prepare($sqlCount, $params));
        }
        // rows
        $sqlRows = "SELECT s.id, s.status, s.meta, s.created_at FROM {$table} s {$whereSql} ORDER BY s.id DESC LIMIT %d OFFSET %d";
        $rowsParams = array_merge($params, [ $per_page, $offset ]);
        $rows = $wpdb->get_results($wpdb->prepare($sqlRows, $rowsParams), ARRAY_A);
        $items = array_map(function($r){
            return [
                'id' => (int)$r['id'],
                'status' => (string)$r['status'],
                'meta' => json_decode($r['meta'] ?: '{}', true),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows ?: []);
        return [ 'total' => $total, 'rows' => $items, 'page' => $page, 'per_page' => $per_page ];
    }

    /**
     * Return all submissions for export, with optional same filters as listByFormPaged. Hard limit to avoid OOM.
     */
    public static function listByFormAll(int $form_id, array $filters = [], int $hard_limit = 5000): array
    {
        global $wpdb;
        $table = Helpers::tableName('submissions');
        $vals = Helpers::tableName('submission_values');
        $where = [ $wpdb->prepare('s.form_id=%d', $form_id) ];
        $params = [];
    if (!empty($filters['status'])){ $where[] = 's.status = %s'; $params[] = (string)$filters['status']; }
        if (!empty($filters['from'])){ $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from']) ? ($filters['from'].' 00:00:00') : null; if($from){ $where[]='s.created_at >= %s'; $params[]=$from; } }
        if (!empty($filters['to'])){ $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to']) ? ($filters['to'].' 23:59:59') : null; if($to){ $where[]='s.created_at <= %s'; $params[]=$to; } }
        if (isset($filters['search']) && $filters['search']!==''){
            $q = (string)$filters['search'];
            if (ctype_digit($q)){ $where[]='s.id = %d'; $params[]=(int)$q; }
            else { $where[]='s.meta LIKE %s'; $params[]='%'.$wpdb->esc_like($q).'%'; }
        }
        if (!empty($filters['answers'])){
            $ans = '%' . $wpdb->esc_like((string)$filters['answers']) . '%';
            $where[] = "EXISTS (SELECT 1 FROM {$vals} sv WHERE sv.submission_id = s.id AND sv.value LIKE %s)";
            $params[] = $ans;
        }
        if (!empty($filters['field_filters']) && is_array($filters['field_filters'])){
            $ops = is_array($filters['field_ops'] ?? null) ? $filters['field_ops'] : [];
            foreach ($filters['field_filters'] as $fid => $val){
                $fid = (int)$fid; $val = (string)$val; if ($fid<=0 || $val==='') continue;
                $op = isset($ops[$fid]) ? $ops[$fid] : 'like';
                if ($op === 'eq'){
                    $where[] = "EXISTS (SELECT 1 FROM {$vals} svf WHERE svf.submission_id = s.id AND svf.field_id = %d AND svf.value = %s)";
                    $params[] = $fid; $params[] = $val;
                } else if ($op === 'neq'){
                    $where[] = "EXISTS (SELECT 1 FROM {$vals} svf WHERE svf.submission_id = s.id AND svf.field_id = %d AND svf.value <> %s)";
                    $params[] = $fid; $params[] = $val;
                } else {
                    $where[] = "EXISTS (SELECT 1 FROM {$vals} svf WHERE svf.submission_id = s.id AND svf.field_id = %d AND svf.value LIKE %s)";
                    $params[] = $fid; $params[] = '%' . $wpdb->esc_like($val) . '%';
                }
            }
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT s.id, s.status, s.meta, s.created_at FROM {$table} s {$whereSql} ORDER BY s.id DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [ $hard_limit ])), ARRAY_A);
        return array_map(function($r){
            return [
                'id' => (int)$r['id'],
                'status' => (string)$r['status'],
                'meta' => json_decode($r['meta'] ?: '{}', true),
                'created_at' => $r['created_at'] ?? null,
            ];
        }, $rows ?: []);
    }

    public static function findWithValues(int $submission_id): array
    {
        global $wpdb;
        $subs = Helpers::tableName('submissions');
        $vals = Helpers::tableName('submission_values');
        $s = $wpdb->get_row($wpdb->prepare("SELECT id, form_id, status, meta, created_at FROM {$subs} WHERE id=%d", $submission_id), ARRAY_A);
        if (!$s) return [];
        $values = $wpdb->get_results($wpdb->prepare("SELECT field_id, value, idx FROM {$vals} WHERE submission_id=%d ORDER BY idx ASC, id ASC", $submission_id), ARRAY_A);
        return [
            'id' => (int)$s['id'],
            'form_id' => (int)$s['form_id'],
            'status' => (string)$s['status'],
            'meta' => json_decode($s['meta'] ?: '{}', true),
            'created_at' => $s['created_at'] ?? null,
            'values' => array_map(function($v){ return [ 'field_id' => (int)$v['field_id'], 'value' => $v['value'], 'idx' => (int)$v['idx'] ]; }, $values ?: []),
        ];
    }

    /**
     * Batch fetch values for multiple submissions. Returns map: submission_id => [ [field_id, value, idx], ... ]
     */
    public static function listValuesBySubmissionIds(array $submission_ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $submission_ids), function($v){ return $v > 0; }));
        if (empty($ids)) return [];
        global $wpdb;
        $vals = Helpers::tableName('submission_values');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare("SELECT submission_id, field_id, value, idx FROM {$vals} WHERE submission_id IN ($placeholders) ORDER BY submission_id ASC, idx ASC, id ASC", $ids);
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r){
            $sid = (int)$r['submission_id'];
            if (!isset($out[$sid])) $out[$sid] = [];
            $out[$sid][] = [ 'field_id' => (int)$r['field_id'], 'value' => $r['value'], 'idx' => (int)$r['idx'] ];
        }
        return $out;
    }
}

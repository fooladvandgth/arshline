<?php
namespace Arshline\Support;

class Audit
{
    public static function log(string $action, string $scope, ?int $target_id, array $before, array $after): string
    {
        global $wpdb;
        $table = Helpers::tableName('audit_log');
        $user_id = get_current_user_id();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '';
        $undo = Helpers::randomToken(16);
        $wpdb->insert($table, [
            'user_id' => $user_id ?: null,
            'ip' => $ip,
            'action' => substr($action, 0, 64),
            'scope' => substr($scope, 0, 32),
            'target_id' => $target_id ?: null,
            'before_state' => wp_json_encode($before, JSON_UNESCAPED_UNICODE),
            'after_state' => wp_json_encode($after, JSON_UNESCAPED_UNICODE),
            'undo_token' => $undo,
        ]);
        return $undo;
    }

    public static function list(int $limit = 50): array
    {
        global $wpdb;
        $table = Helpers::tableName('audit_log');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, ip, action, scope, target_id, before_state, after_state, undo_token, undone, created_at, undone_at FROM {$table} ORDER BY id DESC LIMIT %d", max(1, $limit)), ARRAY_A);
        return array_map(function($r){
            return [
                'id' => (int)$r['id'],
                'user_id' => isset($r['user_id']) ? (int)$r['user_id'] : null,
                'ip' => (string)($r['ip'] ?? ''),
                'action' => (string)$r['action'],
                'scope' => (string)$r['scope'],
                'target_id' => isset($r['target_id']) ? (int)$r['target_id'] : null,
                'before' => json_decode($r['before_state'] ?: 'null', true),
                'after' => json_decode($r['after_state'] ?: 'null', true),
                'undo_token' => (string)$r['undo_token'],
                'undone' => (int)$r['undone'] === 1,
                'created_at' => (string)$r['created_at'],
                'undone_at' => (string)($r['undone_at'] ?? ''),
            ];
        }, $rows ?: []);
    }

    public static function markUndone(string $token): void
    {
        global $wpdb;
        $table = Helpers::tableName('audit_log');
        $wpdb->update($table, [ 'undone' => 1, 'undone_at' => current_time('mysql') ], [ 'undo_token' => $token ]);
    }

    public static function findByToken(string $token): ?array
    {
        global $wpdb;
        $table = Helpers::tableName('audit_log');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE undo_token=%s", $token), ARRAY_A);
        if (!$row) return null;
        return [
            'id' => (int)$row['id'],
            'action' => (string)$row['action'],
            'scope' => (string)$row['scope'],
            'target_id' => isset($row['target_id']) ? (int)$row['target_id'] : null,
            'before' => json_decode($row['before_state'] ?: 'null', true),
            'after' => json_decode($row['after_state'] ?: 'null', true),
            'undone' => (int)$row['undone'] === 1,
        ];
    }
}

<?php
namespace Arshline\Modules\UserGroups;

use Arshline\Support\Helpers;

class MemberRepository
{
    protected static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    protected static function ensureTokenInternal(array $row): array
    {
        if (!empty($row['token']) && !empty($row['token_hash'])) return $row;
        $tok = Helpers::randomToken(12);
        $row['token'] = $tok;
        $row['token_hash'] = self::hashToken($tok);
        return $row;
    }

    public static function list(int $groupId, int $limit = 500): array
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE group_id=%d ORDER BY id DESC LIMIT %d", $groupId, $limit), ARRAY_A) ?: [];
        return array_map(fn($r) => new Member($r), $rows);
    }

    public static function countAll(int $groupId, string $search = ''): int
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE group_id=%d AND (name LIKE %s OR phone LIKE %s)", $groupId, $like, $like));
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE group_id=%d", $groupId));
    }

    public static function paginated(int $groupId, int $per_page, int $page, string $search = '', string $orderby = 'id', string $order = 'DESC'): array
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        $offset = max(0, ($page - 1) * $per_page);
        $orderby = in_array($orderby, ['id','name','phone'], true) ? $orderby : 'id';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $where = 'WHERE group_id=%d';
        $params = [$groupId];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= ' AND (name LIKE %s OR phone LIKE %s)';
            $params[] = $like; $params[] = $like;
        }
        $sql = "SELECT * FROM {$t} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = $per_page; $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
        return array_map(fn($r) => new Member($r), $rows);
    }

    public static function addBulk(int $groupId, array $members): int
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        $inserted = 0;
        foreach ($members as $m) {
            $name = trim((string)($m['name'] ?? ''));
            $phone = trim((string)($m['phone'] ?? ''));
            if ($name === '' || $phone === '') continue;
            $data = $m['data'] ?? [];
            if (!is_array($data)) $data = [];
            $row = [
                'group_id' => $groupId,
                'name' => $name,
                'phone' => $phone,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ];
            $row = self::ensureTokenInternal($row);
            $wpdb->insert($t, $row);
            if ($wpdb->insert_id) $inserted++;
        }
        return $inserted;
    }

    public static function delete(int $groupId, int $memberId): bool
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        return (bool)$wpdb->delete($t, ['id' => $memberId, 'group_id' => $groupId]);
    }

    /**
     * Update basic fields of a member. Fields: name?, phone?, data? (assoc array)
     */
    public static function update(int $groupId, int $memberId, array $fields): bool
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        $row = [];
        if (array_key_exists('name', $fields)) {
            $row['name'] = trim((string)$fields['name']);
        }
        if (array_key_exists('phone', $fields)) {
            $row['phone'] = trim((string)$fields['phone']);
        }
        if (array_key_exists('data', $fields)) {
            $data = $fields['data'];
            if (!is_array($data)) { $data = []; }
            // Only keep scalar values to avoid nested structures
            $clean = [];
            foreach ($data as $k => $v) {
                if (!is_scalar($v)) continue;
                $clean[(string)$k] = (string)$v;
            }
            $row['data'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
        }
        if (empty($row)) return false;
        $affected = $wpdb->update($t, $row, ['id' => $memberId, 'group_id' => $groupId]);
        return $affected !== false;
    }

    public static function ensureToken(int $memberId): ?string
    {
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        $r = $wpdb->get_row($wpdb->prepare("SELECT token, token_hash FROM {$t} WHERE id=%d", $memberId), ARRAY_A);
        if (!$r) return null;
        if (!empty($r['token'])) return (string)$r['token'];
        $tok = Helpers::randomToken(12);
        $wpdb->update($t, [ 'token' => $tok, 'token_hash' => self::hashToken($tok) ], ['id' => $memberId]);
        return $tok;
    }

    /**
     * Find a member by raw token (exact match) or by token hash.
     * Returns a hydrated Member model on success or null when not found.
     */
    public static function findByToken(string $token): ?Member
    {
        $token = trim($token);
        if ($token === '') return null;
        global $wpdb;
        $t = Helpers::tableName('user_group_members');
        // Prefer exact token match when available, otherwise fallback to sha256 hash
        $hash = self::hashToken($token);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t} WHERE token = %s OR token_hash = %s LIMIT 1", $token, $hash),
            ARRAY_A
        );
        return $row ? new Member($row) : null;
    }

    /**
     * Convenience verifier for a member token. Returns Member on success, null on failure.
     */
    public static function verifyToken(string $token): ?Member
    {
        return self::findByToken($token);
    }
}

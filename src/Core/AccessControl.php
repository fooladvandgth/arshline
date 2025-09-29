<?php
namespace Arshline\Core;

/**
 * Centralized access control for Arshline plugin.
 * - Registers custom roles and capabilities for fine-grained control
 * - Stores per-role policies (feature toggles + group visibility scopes) in WP options
 * - Exposes helper methods to check features and compute allowed group IDs for a user
 */
class AccessControl
{
    const OPTION_POLICIES = 'arshline_role_policies';

    /** List of plugin capabilities mapped to features. */
    public static function featureCaps(): array
    {
        return [
            'forms'    => 'arshline_manage_forms',
            'groups'   => 'arshline_manage_groups',
            'sms'      => 'arshline_send_sms',
            'reports'  => 'arshline_view_reports',
            'settings' => 'arshline_manage_settings',
            'users'    => 'arshline_manage_users',
            'ai'       => 'arshline_manage_ai',
        ];
    }

    /** All caps including the super-cap that implies all. */
    public static function allCaps(): array
    {
        return array_merge(array_values(self::featureCaps()), ['arshline_manage_all']);
    }

    /** Default baseline policies per role. Site owner can change via settings API/UI. */
    public static function defaultPolicies(): array
    {
        return [
            'roles' => [
                // مدیر سیستم (عرشلاین): All plugin features; group scope = all
                'arsh_sysadmin' => [
                    'label' => 'مدیر سیستم',
                    'features' => [ 'forms'=>true,'groups'=>true,'sms'=>true,'reports'=>true,'settings'=>true,'users'=>true,'ai'=>true ],
                    'group_scope' => [ 'all' => true, 'ids' => [] ],
                ],
                // پشتیبان: forms, groups, sms, reports
                'arsh_support' => [
                    'label' => 'پشتیبان',
                    'features' => [ 'forms'=>true,'groups'=>true,'sms'=>true,'reports'=>true,'settings'=>false,'users'=>false,'ai'=>false ],
                    'group_scope' => [ 'all' => false, 'ids' => [] ],
                ],
                // استاد: reports only by default
                'arsh_teacher' => [
                    'label' => 'استاد',
                    'features' => [ 'forms'=>false,'groups'=>false,'sms'=>false,'reports'=>true,'settings'=>false,'users'=>false,'ai'=>false ],
                    'group_scope' => [ 'all' => false, 'ids' => [] ],
                ],
                // کوچ: reports only by default
                'arsh_coach' => [
                    'label' => 'کوچ',
                    'features' => [ 'forms'=>false,'groups'=>false,'sms'=>false,'reports'=>true,'settings'=>false,'users'=>false,'ai'=>false ],
                    'group_scope' => [ 'all' => false, 'ids' => [] ],
                ],
                // اپراتور: groups (members) + sms
                'arsh_operator' => [
                    'label' => 'اپراتور',
                    'features' => [ 'forms'=>false,'groups'=>true,'sms'=>true,'reports'=>false,'settings'=>false,'users'=>false,'ai'=>false ],
                    'group_scope' => [ 'all' => false, 'ids' => [] ],
                ],
            ],
        ];
    }

    /** Register custom roles and baseline capabilities. */
    public static function register_roles(): void
    {
        // Define base role map with initial caps; will be kept in sync from policies
        $roles = [
            'arsh_sysadmin' => [ 'label' => 'Arshline Sysadmin', 'caps' => [ 'arshline_manage_all' => true ] ],
            'arsh_support' => [ 'label' => 'Arshline Support', 'caps' => [] ],
            'arsh_teacher' => [ 'label' => 'Arshline Teacher', 'caps' => [] ],
            'arsh_coach' => [ 'label' => 'Arshline Coach', 'caps' => [] ],
            'arsh_operator' => [ 'label' => 'Arshline Operator', 'caps' => [] ],
        ];
        foreach ($roles as $key => $def){
            if (!get_role($key)){
                add_role($key, $def['label'], $def['caps']);
            }
        }
        // Ensure capabilities exist on roles according to current policies
        self::sync_caps_from_policies();
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'register_roles']);
    }

    /** Load stored policies merged with defaults. */
    public static function getPolicies(): array
    {
        $raw = get_option(self::OPTION_POLICIES, []);
        $arr = is_array($raw) ? $raw : [];
        $def = self::defaultPolicies();
        // Merge defaults with stored values (shallow-merge per role)
        $out = $def;
        if (is_array($arr['roles'] ?? null)){
            foreach ($arr['roles'] as $role => $pol){
                if (!isset($out['roles'][$role])){ $out['roles'][$role] = $pol; continue; }
                $cur = $out['roles'][$role];
                if (isset($pol['label'])) $cur['label'] = (string)$pol['label'];
                if (isset($pol['features']) && is_array($pol['features'])){ $cur['features'] = array_merge($cur['features'], array_map('boolval', $pol['features'])); }
                if (isset($pol['group_scope']) && is_array($pol['group_scope'])){
                    $gs = $pol['group_scope'];
                    $cur['group_scope'] = [
                        'all' => !empty($gs['all']),
                        'ids' => array_values(array_unique(array_map('intval', is_array($gs['ids'] ?? null) ? $gs['ids'] : []))),
                    ];
                }
                $out['roles'][$role] = $cur;
            }
        }
        return $out;
    }

    /** Persist policies after sanitization and sync role capabilities accordingly. */
    public static function updatePolicies(array $policies): array
    {
        $cur = self::getPolicies();
        if (!is_array($policies['roles'] ?? null)){
            update_option(self::OPTION_POLICIES, $cur, false);
            return $cur;
        }
        foreach ($policies['roles'] as $role => $pol){
            if (!isset($cur['roles'][$role])){ $cur['roles'][$role] = [ 'label' => (string)($pol['label'] ?? $role), 'features' => [], 'group_scope' => [ 'all'=>false, 'ids'=>[] ] ]; }
            $rec = $cur['roles'][$role];
            if (isset($pol['label'])) $rec['label'] = (string)$pol['label'];
            if (isset($pol['features']) && is_array($pol['features'])){
                // Only accept known features
                $known = array_keys(self::featureCaps());
                $feat = [];
                foreach ($pol['features'] as $k=>$v){ if (in_array($k, $known, true)) { $feat[$k] = (bool)$v; } }
                $rec['features'] = array_merge($rec['features'] ?? [], $feat);
            }
            if (isset($pol['group_scope']) && is_array($pol['group_scope'])){
                $gs = $pol['group_scope'];
                $rec['group_scope'] = [
                    'all' => !empty($gs['all']),
                    'ids' => array_values(array_unique(array_map('intval', is_array($gs['ids'] ?? null) ? $gs['ids'] : []))),
                ];
            }
            $cur['roles'][$role] = $rec;
        }
        update_option(self::OPTION_POLICIES, $cur, false);
        self::sync_caps_from_policies();
        return $cur;
    }

    /** Ensure WP roles have caps aligned to feature toggles in policies. */
    public static function sync_caps_from_policies(): void
    {
        $pol = self::getPolicies();
        $map = self::featureCaps();
        foreach ($pol['roles'] as $roleKey => $info){
            $wpRole = get_role($roleKey);
            if (!$wpRole) continue;
            $features = is_array($info['features'] ?? null) ? $info['features'] : [];
            foreach ($map as $feature => $cap){
                if (!empty($features[$feature])){ $wpRole->add_cap($cap, true); }
                else { $wpRole->remove_cap($cap); }
            }
            // Sysadmin role always implies all
            if ($roleKey === 'arsh_sysadmin'){
                $wpRole->add_cap('arshline_manage_all', true);
            }
        }
    }

    /** Check if current user can perform a feature. Super-admins (manage_options) are always allowed. */
    public static function currentUserCanFeature(string $feature): bool
    {
        if (current_user_can('manage_options')) return true;
        $map = self::featureCaps();
        $cap = $map[$feature] ?? '';
        if ($cap && current_user_can($cap)) return true;
        // Super-cap grants all
        if (current_user_can('arshline_manage_all')) return true;
        // Back-compat: editors can manage forms
        if ($feature === 'forms' && current_user_can('edit_posts')) return true;
        return false;
    }

    /** Resolve allowed group IDs for a given user based on their roles' policies. Null means unrestricted (all). */
    public static function allowedGroupIdsForUser(int $userId): ?array
    {
        if ($userId <= 0) return null; // guests: unrestricted for public routes
        if (user_can($userId, 'manage_options') || user_can($userId, 'arshline_manage_all')) return null;
        $u = get_userdata($userId);
        if (!$u || !is_array($u->roles ?? null)) return null;
        $roles = $u->roles;
        $pol = self::getPolicies();
        $all = false; $accum = [];
        foreach ($roles as $r){
            $p = $pol['roles'][$r] ?? null; if (!$p) continue;
            $gs = is_array($p['group_scope'] ?? null) ? $p['group_scope'] : [];
            if (!empty($gs['all'])){ $all = true; break; }
            $ids = is_array($gs['ids'] ?? null) ? $gs['ids'] : [];
            foreach ($ids as $id){ $accum[$id] = true; }
        }
        if ($all) return null; // unrestricted
        return array_map('intval', array_keys($accum));
    }

    /** Convenience: allowed groups for current user. */
    public static function allowedGroupIdsForCurrentUser(): ?array
    {
        return self::allowedGroupIdsForUser(get_current_user_id());
    }

    /**
     * Filter provided group IDs by current user's allowed scope. If scope is unrestricted (null), returns input as-is.
     * If scope is empty array and input is non-empty, returns empty array.
     */
    public static function filterGroupIdsByCurrentUser(?array $groupIds): array
    {
        $groupIds = is_array($groupIds) ? array_values(array_unique(array_map('intval', $groupIds))) : [];
        $allowed = self::allowedGroupIdsForCurrentUser();
        if ($allowed === null) return $groupIds;
        $allowedMap = array_fill_keys($allowed, true);
        $out = [];
        foreach ($groupIds as $id){ if (isset($allowedMap[$id])) $out[] = $id; }
        return $out;
    }
}

?>

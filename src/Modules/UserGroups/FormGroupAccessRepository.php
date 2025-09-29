<?php
namespace Arshline\Modules\UserGroups;

use Arshline\Support\Helpers;

class FormGroupAccessRepository
{
    public static function getGroupIds(int $formId): array
    {
        global $wpdb;
        $t = Helpers::tableName('form_group_access');
        $rows = $wpdb->get_col($wpdb->prepare("SELECT group_id FROM {$t} WHERE form_id=%d ORDER BY group_id ASC", $formId)) ?: [];
        return array_map('intval', $rows);
    }

    public static function setGroupIds(int $formId, array $groupIds): void
    {
        global $wpdb;
        $t = Helpers::tableName('form_group_access');
        $groupIds = array_values(array_unique(array_map('intval', $groupIds)));
        // Compute existing and desired
        $existing = self::getGroupIds($formId);
        $toAdd = array_diff($groupIds, $existing);
        $toDel = array_diff($existing, $groupIds);
        // Delete
        if (!empty($toDel)) {
            foreach ($toDel as $gid) { $wpdb->delete($t, ['form_id' => $formId, 'group_id' => (int)$gid]); }
        }
        // Insert
        foreach ($toAdd as $gid) {
            $wpdb->insert($t, ['form_id' => $formId, 'group_id' => (int)$gid]);
        }
    }
}

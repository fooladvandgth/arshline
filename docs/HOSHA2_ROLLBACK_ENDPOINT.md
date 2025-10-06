# Hoosha2 Rollback Endpoint (F8)

## Overview
Rollback creates a new version cloning a target historical version snapshot. Optionally a backup (snapshot of current latest version) is created before rollback.

## Endpoint
POST /wp-json/hosha2/v1/forms/{form_id}/versions/{version_id}/rollback

## Parameters
- form_id (path) — owning form
- version_id (path) — target version to rollback TO
- create_backup (query or JSON body, optional, bool) — if true, a backup version is saved (best-effort)

## Behavior
1. Validate form_id & version_id > 0
2. Fetch target version (404 if not found or ownership mismatch)
3. Optionally identify latest version (previous_version_id) for metadata
4. If create_backup=true and previous_version_id != target_version_id: save backup snapshot (metadata: backup_of, parent_version_id)
5. Save new rollback version cloning target snapshot with rollback metadata
6. Respond with references (target, new, previous, backup ids)

## Success Response (200)
```
{
  "success": true,
  "data": {
    "target_version_id": 120,
    "new_version_id": 133,
    "previous_version_id": 132,
    "backup_version_id": 134,
    "rolled_back": true
  }
}
```

## Error Responses
| Code | HTTP | Meaning |
|------|------|---------|
| invalid_form_id | 400 | form_id must be positive integer |
| invalid_version_id | 400 | version_id must be positive integer |
| version_not_found | 404 | Target version missing or form mismatch |
| internal_error | 500 | Unexpected server exception |

## Metadata Stored (rollback version)
```
{
  "rollback": true,
  "target_version_id": <targetVersionId>,
  "previous_version_id": <latestBeforeRollback>,
  "rolled_back_at": "2025-10-06T12:34:56Z",
  "rolled_back_by": 1,
  "backup_version_id": <backupVersionId|null>
}
```

## Backup Version Metadata
```
{
  "diff_applied": false,
  "parent_version_id": <previousVersionId>,
  "backup_of": <previousVersionId>,
  "backup_created_at": "2025-10-06T12:34:56Z"
}
```

## Notes
- Backup creation is best-effort; failures are swallowed (degradation) but rollback still proceeds.
- Ownership mismatch returns version_not_found (non-leaking).
- No diff is generated; this is a full snapshot clone.

## Example
```
curl -X POST \
  -H "Authorization: Bearer TOKEN" \
  https://example.com/wp-json/hosha2/v1/forms/55/versions/120/rollback?create_backup=1
```

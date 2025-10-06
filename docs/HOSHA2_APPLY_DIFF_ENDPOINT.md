# Hoosha2 Apply Diff Endpoint (F7)

## Overview
Allows applying a JSON Patch (RFC6902 subset: add, remove, replace) to an existing stored version snapshot of a form, producing a new version or a dry-run preview.

## Endpoint
POST /wp-json/hosha2/v1/forms/{form_id}/versions/{version_id}/apply-diff

## Query Parameters
- dry_run (optional, bool) — If true (or 1) the diff is applied in-memory only; no new version is persisted and `new_version_id` is null.

## Request Body (application/json)
```
{
  "diff": [
    { "op": "replace", "path": "/fields/0/label", "value": "New Label" },
    { "op": "add", "path": "/fields/1", "value": { "label": "Email", "type": "short_text" } }
  ]
}
```

Supported ops: add, remove, replace
Unsupported ops (move, copy, test) => 400 unsupported_operation

## Success Responses
### 200 (Normal Apply)
```
{
  "success": true,
  "data": {
    "base_version_id": 42,
    "new_version_id": 43,
    "diff_sha": "a1b2c3...",
    "operation_count": 2,
    "dry_run": false,
    "snapshot": { ... updated form config ... }
  }
}
```

### 200 (Dry Run)
```
{
  "success": true,
  "data": {
    "base_version_id": 42,
    "new_version_id": null,
    "diff_sha": "a1b2c3...",
    "operation_count": 2,
    "dry_run": true,
    "snapshot": { ... virtual updated form config ... }
  }
}
```

## Error Responses
| Code | HTTP | Meaning |
|------|------|---------|
| invalid_form_id | 400 | form_id must be positive integer |
| invalid_version_id | 400 | version_id must be positive integer |
| invalid_diff | 400 | Diff missing / malformed (op/path invalid) |
| empty_diff | 400 | Diff array provided but empty |
| unsupported_operation | 400 | An op not in add/remove/replace was found |
| patch_failed | 400 | Failed applying path to snapshot (e.g. missing element) |
| version_not_found | 404 | Base version missing or form mismatch |
| internal_error | 500 | Unexpected server exception |

## Metadata Persistence (when not dry_run)
New version stores metadata:
```
{
  "diff_applied": true,
  "diff_sha": "...",
  "parent_version_id": <base_version_id>,
  "applied_at": "2025-10-06T12:34:56Z",
  "applied_by": 1,
  "operation_count": 2
}
```

## Notes
- Path interpretation follows JSON Pointer (RFC6901) unescaping for `~0` and `~1`.
- Array insertion supports adding at exact index or append via index == current length.
- Replace and remove require the target path to exist.
- Add will create intermediate containers (objects/arrays) where necessary only for parent traversal; invalid numeric branches reject.
- Ownership check: responding with version_not_found on mismatch prevents leakage.

## Examples
### Replace Label
```
curl -X POST \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TOKEN" \
  -d '{"diff":[{"op":"replace","path":"/fields/0/label","value":"Updated"}]}' \
  https://example.com/wp-json/hosha2/v1/forms/123/versions/456/apply-diff
```

### Dry Run Add Field
```
curl -X POST "https://example.com/wp-json/hosha2/v1/forms/123/versions/456/apply-diff?dry_run=1" \
  -H "Content-Type: application/json" \
  -d '{"diff":[{"op":"add","path":"/fields/1","value":{"label":"Email","type":"short_text"}}]}'
```

### Unsupported Operation
```
{
  "success": false,
  "error": { "code": "unsupported_operation", "message": "Only add/remove/replace supported" }
}
```

# Hoosha2 Versions Endpoint (F6-1, F6-2)

## List Form Versions
GET /wp-json/hosha2/v1/forms/{form_id}/versions

Returns paginated list of stored generated form versions (newest → oldest).

### Query Parameters
- `limit` (optional, int, 1–100, default: 10)
- `offset` (optional, int, >=0, default: 0)

### Success Response (200)
```
{
  "success": true,
  "data": {
    "versions": [
      {
        "version_id": 123,
        "form_id": 456,
        "created_at": "2025-10-06T12:34:56+00:00",
        "metadata": {
          "_hosha2_user_prompt": "...",
            "_hosha2_tokens_used": 150,
            "_hosha2_diff_applied": 0
        }
      }
    ],
    "total": 42,
    "limit": 10,
    "offset": 0,
    "returned": 10
  }
}
```

### Error Responses
| Code | HTTP | Meaning |
|------|------|---------|
| invalid_form_id | 400 | `form_id` must be positive integer |
| invalid_limit | 400 | `limit` must be 1–100 |
| invalid_offset | 400 | `offset` must be >= 0 |
| internal_error | 500 | Unexpected server exception |

### Example (curl)
```bash
curl -X GET "https://example.com/wp-json/hosha2/v1/forms/123/versions?limit=5&offset=0" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Notes
- `created_at` is ISO8601 (UTC) for client-friendly parsing.
- `metadata` keys retain legacy underscore-prefixed structure for backward compatibility.
- For per-version details (full config), use the single version endpoint below.

---

## Get Single Version (F6-2)
GET /wp-json/hosha2/v1/forms/{form_id}/versions/{version_id}

Returns the full snapshot (entire form config) plus metadata for a single stored version.

### Path Parameters
- `form_id` (int, path) – Owning form identifier.
- `version_id` (int, path) – Target version id.

### Success Response (200)
```
{
  "success": true,
  "data": {
    "version_id": 789,
    "form_id": 456,
    "created_at": "2025-10-06T12:34:56Z",
    "metadata": {
      "_hosha2_form_id": 456,
      "_hosha2_user_prompt": "...",
      "_hosha2_tokens_used": 150,
      "_hosha2_created_by": 12,
      "_hosha2_diff_applied": 0,
      "_hosha2_diff_sha": "a1b2c3..."
    },
    "snapshot": {
      "fields": [ { "id": "f1", "type": "short_text", "label": "..." } ],
      "settings": { }
    }
  }
}
```

### Error Responses
| Code | HTTP | Meaning |
|------|------|---------|
| invalid_form_id | 400 | `form_id` must be positive integer |
| invalid_version_id | 400 | `version_id` must be positive integer |
| version_not_found | 404 | Version does not exist or does not belong to form |
| internal_error | 500 | Unexpected server exception |

### Notes
- `created_at` normalized to ISO8601 UTC (`Z` suffix) for consistent client parsing.
- Ownership mismatch (version belongs to different form) deliberately responds with `version_not_found` (avoids leaking existence of other form versions).
- Returned `snapshot` is the full stored configuration at generation time (immutable historical record).

### Example (curl)
```bash
curl -X GET "https://example.com/wp-json/hosha2/v1/forms/456/versions/789" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

# Hosha2 (هوشا۲) Specification

Version: 0.1-DRAFT
Branch: feature/hosha2-na-omid
Status: In Progress (F1 complete)

## 1. Mission
AI-only form generation & evolution pipeline (generate, edit, validate, optimize, translate) with 100% dependency on OpenAI responses. No local business rules beyond structural safety (JSON parse + minimal structural guards).

## 2. Principles
- Single source of truth: OpenAI output.
- Deterministic logging (NDJSON) for every phase & event.
- Zero manual fallback; outage => explicit UI error + retry.
- Immutable version snapshots; reversible.
- Security & privacy: keys redacted, no PII persisted in logs.

## 3. High-Level Flow
1. Admin submits (question|prompt) => /generate.
2. Build capabilities_map (cached) + envelope.
3. Send to OpenAI (intent auto / provided).
4. Secondary validation request (intent=validate) if enabled.
5. Store draft form + version=1 (source=hosha2).
6. Render preview + progress updates.
7. Smart edit => /edit (diff or full form) -> validate -> new version.

## 4. Roles & Access
- Capability required: manage_options (Phase 1) -> future: manage_arshline_forms.
- Nonce per action: hosha2_generate_nonce, hosha2_edit_nonce, hosha2_cancel_nonce.

## 5. Data Contracts (Envelopes)
### 5.1 Capabilities Map (v1)
```json
{
  "schema_version":"1.0",
  "form_types":["quiz","questionnaire","survey","registration","feedback","poll"],
  "fields":[
    {"type":"text","id":"text","validation":["required","pattern","minLength","maxLength"],"ui":{"placeholder":true,"helpText":true,"prefix":true,"suffix":true}},
    {"type":"textarea","id":"textarea","validation":["required","minLength","maxLength"],"ui":{"placeholder":true,"helpText":true,"rows":true}},
    {"type":"email","id":"email","validation":["required","email","maxLength"],"ui":{"placeholder":true}},
    {"type":"number","id":"number","validation":["required","min","max","step"],"ui":{"placeholder":true}},
    {"type":"radio","id":"radio","options":{"source":["static","dynamic"],"minSelect":1,"maxSelect":1}},
    {"type":"checkbox","id":"checkbox","options":{"source":["static"],"minSelect":0,"maxSelect":"n"}},
    {"type":"select","id":"select","options":{"multiple":true,"searchable":true}},
    {"type":"date","id":"date","validation":["required","format"],"variants":["gregorian","jalali"]},
    {"type":"file","id":"file","constraints":["mime","size","count"]},
    {"type":"rating","id":"rating","scale":{"min":1,"max":5}},
    {"type":"matrix","id":"matrix","dimensions":{"rows":[],"cols":[]}},
    {"type":"section","id":"section","ui":{"collapsible":true}},
    {"type":"html","id":"html","raw_allowed":false}
  ],
  "logic":{
    "conditional":[{"when":{"fieldId":"...","op":"equals","value":"..."},"show":["fieldA","fieldB"]}],
    "scoring":{"enabled":true,"rules":[{"fieldId":"...","answer":"...","score":10}]},
    "branching":{"pages":[],"rules":[]},
    "calculation":{"expressions":[],"variables":[{"fieldId":"..."}]}
  },
  "layout":{"pages":[{"id":"p1","title":"Page 1","sections":[]}],"progress":"steps"},
  "validation":{"global":{"onSubmit":true,"onBlur":false}},
  "appearance":{"theme":["light","dark","system"],"rtl":true},
  "submission":{"storage":"db","notifications":{"email":true,"webhook":true},"webhooks":[{"url":"https://...","method":"POST"}]},
  "i18n":{"defaultLocale":"fa_IR","locales":["fa_IR","en_US"],"translateLabels":true},
  "limits":{"maxFields":200,"maxPages":20}
}
```

### 5.2 OpenAI Request Envelope (v1)
```json
{
  "meta":{
    "intent":"auto|generate|edit|extend|translate|validate|optimize",
    "taskHints":["quiz"],
    "complexity":"low|medium|high",
    "model_pref":"auto",
    "locale":"fa_IR",
    "user_role":"admin",
    "secondary_validation":true
  },
  "user_input":{
    "question":"...",
    "prompt":"..."
  },
  "capabilities":{ /* see capabilities map */ },
  "current_form":{ /* optional when editing */ }
}
```

### 5.3 OpenAI Response Envelope (v1)
```json
{
  "intent":"detected_intent",
  "diagnostics":{"notes":[],"riskFlags":[]},
  "final_form":{ "version":"arshline_form@v1","fields":[],"layout":{},"meta":{} },
  "diff": [ {"op":"add","path":"/fields/3","value":{}} ],
  "ui_hints":{"previewTips":[],"emptyStates":[]},
  "token_usage":{"prompt":0,"completion":0,"total":0}
}
```

### 5.4 Error Envelope
```json
{"error":{"code":"MODEL_LIMIT|SCHEMA_INVALID|UNSAFE_OUTPUT|TIMEOUT|RATE_LIMIT|OPENAI_UNAVAILABLE|DIFF_APPLY_FAIL","message":"...","retryAfterSec":5}}
```

## 6. Error Codes & Meanings
| Code | Meaning | Action |
|------|---------|--------|
| TIMEOUT | Model exceeded timeout | Offer retry |
| MODEL_LIMIT | Request too large | Suggest shorter prompt |
| SCHEMA_INVALID | Output fails schema | Show issues + retry validate |
| UNSAFE_OUTPUT | Potential unsafe HTML/JS | Block + warn |
| RATE_LIMIT | Too many requests | Retry after n |
| OPENAI_UNAVAILABLE | Network/down | Retry/backoff |
| DIFF_APPLY_FAIL | JSON Patch invalid | Fallback full replace |

## 7. Logging (NDJSON)
File: hooshyar2-log.txt
Fields: ts,lvl,phase,evt,req_id,user,model,tokens_in,tokens_out,latency_ms,error_code,diff_sha
PII Redaction: email=> <REDACT_EMAIL>, phone=> <REDACT_PHONE>
Rotation: >5MB => rename .1
Levels: DEBUG,INFO,WARN,ERROR

## 8. Progress Tracking
Phases weights: analyze_input(0.10), build_capabilities(0.15), openai_send(0.20), validate_response(0.25), persist_form(0.15), render_preview(0.15)
Transient key: hosha2_progress_{req_id}
Cancel flag: hosha2_cancel_{req_id} = 1

## 9. Versioning
- Each saved form: post_meta hosha2_version (int), hosha2_source=hosha2
- VersionRepository: snapshots table/post_meta key hosha2_versions (JSON array of last N) OR individual meta rows.

## 10. Security
- Capability: manage_options
- Nonces per action
- Validate Base URL (whitelist https scheme)
- Strip raw HTML if html.raw_allowed=false

## 11. Rate Limiting
- 10 requests / 60s / user (transient counters)
- On breach => error RATE_LIMIT + log WARN

## 12. Secondary Validation
- If meta.secondary_validation = true -> second OpenAI call with intent=validate & final_form
- On mismatch -> SCHEMA_INVALID

## 13. No Local Fallback Policy
- If any OpenAI error -> immediate error response; no partial rescue
- Local code only ensures: JSON parse & non-empty fields array (structural) else drop

## 14. Open Questions
- Storage backend form (confirm existing). 
- Need for purge old versions strategy (>10)?
- UI theming alignment with core plugin theme classes.

## 15. Next (F1)
- Implement Logger Interface + FileLogger
- Add helper: LoggerContextFactory
- Provide global function hosha2_logger()

---
END OF SPEC DRAFT

## F1 Delivery (Logger Framework)
Implemented components:
- `Hosha2LoggerInterface` with log/phase/summary/context/rotation methods.
- `Hosha2LogRedactor` masking api keys, tokens, emails, phones (center masking + digit masking).
- `Hosha2FileLogger` NDJSON writer (one JSON object per line, UTF-8), size-based rotation (default 5MB) with pruning (keep last 5), flock-based write safety.
- Bootstrap helpers: `hoosha2_logger()` singleton (stored under uploads/arshline_logs) and `hoosha2_log()` convenience.
- Initial phase hook: emits `phase: bootstrap_init` on `init` action.
- PHPUnit tests `Hosha2FileLoggerTest` covering: NDJSON structure, redaction, rotation triggering with small threshold.

Logger now aligned with spec updates: includes `lvl`, filename standardized to `hooshyar2-log.txt`.

Next (F2 Target Adjusted): integrate logger into forthcoming OpenAI request pipeline + progress emission. Spec will be updated when those phases begin.

## F2 Delivery (Capabilities & Envelope)
Components implemented:
- `Hosha2CapabilitiesBuilder` with transient cache (graceful degrade when WP funcs absent), static baseline fields (Phase 1 scope), logged phases: `capabilities_build_start` / `capabilities_build_end` and cache hits.
- `Hosha2OpenAIEnvelopeFactory` producing generate/edit envelopes with logged phases: `envelope_create_start`, `envelope_create_end`, plus `envelope_create` size event.
- Logger updated to include `lvl` and new default file `hooshyar2-log.txt`.
- Tests: `Hosha2CapabilitiesAndEnvelopeTest` validating capabilities shape and envelope meta.intent.

Notes:
- Future dynamic scan will replace `baseline()` implementation in builder.
- Redaction precedes any size logging; size metric is computed pre-redaction (acceptable: only metadata size, not persisted confidential content).
- WP caching functions are optional in pure PHPUnit context (guarded via function_exists).

Next (F3 tentative scope): OpenAI client abstraction stub (no network), progress tracker scaffolding, and diff validator placeholder.

## F3 Delivery (Client Stub, Progress, Diff, Orchestrator)
Implemented:
- `Hosha2OpenAIClientInterface` + `Hosha2OpenAIClientStub` (mock latency, token_usage, logging openai_request_start/end).
- `Hosha2ProgressTracker` (weights aligned with spec, logs `progress_update`, transient fallback).
- `Hosha2DiffValidator` (basic JSON Patch op/path structural validation with start/end log events).
- `Hosha2GenerateService` orchestrating: analyze_input → build_capabilities → envelope_create → openai_send → validate_response → persist_form → render_preview with summary emission.
- Logging integration: events added (openai_request_start, openai_request_end, diff_validate_start, diff_validate_end, progress_update, summary).
- Tests (`Hosha2F3ComponentsTest`): client stub, progress accumulation, diff invalid detection, full pipeline achieving progress=1.0.

Notes & Deviations:
- Validation is structural only; semantic schema validation deferred to F4 (output shape assertions minimal).
- No persistence layer yet (marked mock persist_form phase only).
- Diff validator does not enforce JSON Pointer correctness or array index bounds (future enhancement).

Risks Forward:
- Without real OpenAI client abstraction error taxonomy paths not exercised (to add in F4 when HTTP layer introduced).
- Progress tracker does not yet support cancellation flag check (planned with UI polling integration).

Next (F4 Proposal): Real HTTP OpenAI client wrapper (timeout, error mapping), cancellation check integration, version snapshot stub, enhanced diff semantic validation, and initial REST endpoint scaffolding.

## F4 Delivery (HTTP Client, Endpoint, Rate Limit, Cancellation, Version Snapshot)

### Overview
Phase F4 adds the production-grade outward interface for generation plus reliability & observability layers:
1. Real HTTP OpenAI client (`Hosha2OpenAIHttpClient`) with error taxonomy mapping (network / timeout / invalid_api_key / rate_limit / invalid_request / model_overloaded / server_error / unknown).
2. REST Endpoint: `POST /hosha2/v1/forms/{form_id}/generate` implemented by `Hosha2GenerateController`.
3. Sliding window rate limiting (default 10 requests / 60s per user/request-id context) via `Hosha2RateLimiter`.
4. Cancellation via transient flag `hosha2_cancel_{req_id}` returning HTTP 499 (client cancelled) at any of 3 checkpoints.
5. Version snapshot persistence stub (`Hosha2VersionRepository`) with diff_sha meta and logging events.
6. `diff_sha` (sha1 of original diff array) surfaced in success response + logger summary.
7. Progress contract refined: `progress` (ordered phase list) + `progress_percent` (0..1 float).

### Endpoint: Generate Form
`POST /hosha2/v1/forms/{form_id}/generate`

Headers: `Content-Type: application/json`

Request Body:
```json
{
  "prompt": "Describe a simple registration form",
  "options": {"locale":"fa_IR"},
  "req_id": "(optional hex 6-32 for testing)"
}
```

Validation Errors (HTTP 400):
| code | condition |
|------|-----------|
| invalid_form_id | path param <= 0 |
| missing_prompt | no prompt key present |
| empty_prompt | prompt blank after trim |
| invalid_options_type | options exists but not an object |

Rate Limit (HTTP 429):
```json
{"success":false,"error":{"code":"rate_limited","message":"Rate limit exceeded"},"request_id":"..."}
```

Cancellation (HTTP 499):
```json
{
  "success": false,
  "cancelled": true,
  "error": {"code": "request_cancelled", "message": "Request cancelled by user"},
  "request_id": "ab12cd34"
}
```

Success (HTTP 200):
```json
{
  "success": true,
  "request_id": "ab12cd34",
  "version_id": 1201,
  "diff_sha": "d41d8cd98f00b204e9800998ecf8427e",
  "data": {
    "final_form": {"version":"arshline_form@v1","fields":[{"id":"f1","type":"text"}],"layout":[],"meta":{}},
    "diff": [{"op":"add","path":"/fields/0","value":{"id":"f1","type":"text"}}],
    "token_usage": {"prompt": 120, "completion": 85, "total": 205},
    "progress": ["analyze_input","build_capabilities","openai_send","validate_response","persist_form","render_preview"],
    "progress_percent": 1.0
  }
}
```

Runtime / Fatal Error (HTTP 500):
```json
{"success":false,"error":{"code":"runtime_error","message":"<details>"},"request_id":"..."}
```

### Response Field Notes
| field | type | notes |
|-------|------|-------|
| success | bool | global success flag |
| request_id | string hex | stable per request (auto if not provided) |
| version_id | int|null | snapshot ID or null when persistence disabled |
| diff_sha | string(40)| sha1(diff) or null if diff empty/invalid |
| data.final_form | object | canonical form model |
| data.diff | array | JSON-patch like operations (validated) |
| data.token_usage | object | prompt/completion/total counts |
| data.progress | string[] | ordered completed phases |
| data.progress_percent | float | cumulative weighted progress |

### Progress & Cancellation
Phases (ordered, weights in parentheses):
`analyze_input (0.10)` → `build_capabilities (0.15)` → `openai_send (0.20)` → `validate_response (0.25)` → `persist_form (0.15)` → `render_preview (0.15)`.

Cancellation checkpoints:
1. before_capabilities
2. before_openai
3. after_openai (pre diff validation)

Setting transient `hosha2_cancel_{req_id}` before a checkpoint yields HTTP 499. Progress list reflects only phases completed prior to cancellation.

### Rate Limiting
Sliding window memory (transient-based) storing timestamps. Default policy: 10 requests / 60s. Exceed → short-circuit before heavy work (no OpenAI call).

### Error Taxonomy (Internal OpenAI Client)
Internal codes (not all surfaced directly at endpoint):
`ERR_NETWORK_ERROR`, `ERR_TIMEOUT`, `ERR_RATE_LIMIT`, `ERR_INVALID_API_KEY`, `ERR_MODEL_OVERLOADED`, `ERR_INVALID_REQUEST`, `ERR_SERVER_ERROR`, `ERR_UNKNOWN`.
They are logged in `openai_request_end` events. Endpoint maps high-level runtime issues to: `rate_limited`, `runtime_error`, `fatal`, `request_cancelled`.

### diff_sha Rules
- Computed only if `diff` is a non-empty array prior to validation changes.
- If diff invalid → normalized to empty array and `diff_sha` set null.
- Stored in version snapshot meta `_hosha2_diff_sha`.

### Version Snapshot
`Hosha2VersionRepository::saveSnapshot` meta keys:
`_hosha2_form_id`, `_hosha2_user_prompt`, `_hosha2_tokens_used`, `_hosha2_created_by`, `_hosha2_diff_applied`, `_hosha2_diff_sha`.

### Logging Additions (F4)
Events: `rate_limit_exceeded`, `request_cancelled`, `version_saved`, `versions_listed`, `versions_cleaned`, `openai_request_start/end` (with `diff_sha`), `summary` includes `diff_sha`.

### نمونه خلاصه (فارسی)
در فاز F4: endpoint تولید فرم، محدودسازی نرخ، لغو با وضعیت 499، محاسبه diff_sha و گزارش پیشرفت مرحله‌ای (progress + progress_percent) پیاده‌سازی شد. خطاهای OpenAI به کدهای پایدار ERR_* نگاشت و در لایه API به کدهای ساده‌تر بازتاب داده شدند.


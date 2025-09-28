# Hoshyar (هوشیار) — AI Architecture and Capability Map

This document outlines the modular AI (Hoshyar) strategy for the Arshline plugin, aligning with WordPress standards and a clean, extensible architecture.

## Architecture (Modular, WordPress-friendly)

- UI Module: `assets/js/ui/ai-terminal.js`
  - Provides floating panel UI, persistence, and a thin API: `window.HOSHYAR` (alias of `window.ARSH_AI` for backward-compat)
  - No business logic; only calls REST endpoints with nonce
- Backend Endpoint: `POST /arshline/v1/ai/agent`
  - Stateless command handler with explicit actions and safe confirmation flow
  - Returns structured responses: `{ ok: boolean, action?: string, ... }`
- Capabilities Registry (proposed): `src/Core/FeatureFlags.php` or a new `src/Core/Capabilities.php`
  - Declarative list of available actions + permission checks (WP roles/caps)
- Orchestrator (proposed): `src/Core/Ai/Hoshyar.php`
  - Maps natural-language intents to actions in modules (forms, submissions, reports, users, settings)
  - Enforces validation, confirmation, and audit logging

## Current UI Actions

- Toggle theme (dark/light)
- Open tabs: dashboard, forms, reports, users, settings
- Open form builder/editor/preview/results by ID or title (fuzzy)
- Open/download URLs
- Confirm/clarify prompts (two-step operations)

## Recently added (server-backed, undoable)

These actions are executed via REST with audit logging and return an `undo_token` that you can revert through `POST /arshline/v1/ai/undo`:

- Forms lifecycle
   - Open/Publish form: examples — «فعال کن فرم 3», «انتشار فرم 3» → `PUT /forms/{id} { status: "published" }`
   - Close/Disable form: examples — «غیرفعال کن فرم 8», «بستن فرم 8» → `PUT /forms/{id} { status: "disabled" }`
   - Draft form: examples — «پیش‌نویس کن فرم 4» → `PUT /forms/{id} { status: "draft" }`
- Edit form meta
   - Update title: example — «عنوان فرم 2 را به فرم مشتریان تغییر بده» → `PUT /forms/{id}/meta { meta: { title: "فرم مشتریان" } }`

All four actions log an audit entry (`update_form_status` or `update_form_meta`) and include `undo_token` in the JSON response.

## Parser modes and LLM constraints

- Modes: `internal` | `llm` | `hybrid` (default)
   - internal: deterministic regex/heuristics (fast, offline)
   - llm: strict JSON-only responses; rejected if non-JSON
   - hybrid: try internal first, then fallback to llm if configured
- Typo tolerance and colloquial verbs for navigation are supported (e.g., «داشبودر»≈«داشبورد», «وا کن/واکن»≈«باز کن»).

## Navigation and editor intents

- Create and open builder: «یک فرم جدید باز کن» → creates a draft and opens builder immediately.
- Open results by id/title: «نتایج فرم 12» یا «نتایج فرم مشتریان» (fuzzy match on title).
- Open editor for a question:
   - «پرسش 1 را ویرایش کن» → `{ action: "open_editor", index: 1 }` (current form context inferred when possible)
   - «پرسش 1 فرم 24 را ادیت کن» → `{ action: "open_editor", id: 24, index: 1 }`
   - «برو به ادیت پرسش 2» while in builder/24 → `{ action: "ui", target: "open_editor_index", index: 2 }`
- UI commands:
   - «undo» / «بازگردانی»: `{ action: "ui", target: "undo" }` (first UI stack, then server `undo_token` if available)
   - «برگرد»: `{ action: "ui", target: "go_back" }` (UI stack pop or browser history)

## Settings UX

- Parser selection is surfaced in settings, defaulting to `hybrid`.
- If the API key has been set previously, the UI prefills it from `/ai/config`.

## Clarify, Did‑you‑mean, and Confirmation

- Clarify (disambiguation): When a command references a form by name/title instead of ID (e.g., «فرم نیو رو ادیت کن»), the agent performs fuzzy matching on existing form titles and responds with `action: "clarify"` and an options list. Pick one to proceed. If a single strong match is found, you'll get a confirmation prompt for that exact form.
- Did‑you‑mean: The fuzzy matcher is tolerant to spacing, ZWNJ, and minor typos; it suggests closest matches when intent is clear (edit/delete by name).
- Confirmation gates: Destructive or state‑changing operations request confirmation first. Examples:
   - Delete: «حذف فرم مشتریان» → clarify/confirm, then `delete_form` on selection.
   - Rename: «عنوان فرم 12 را به "فرم مشتریان" تغییر بده» → `action: "confirm"` then executes `update_form_title` upon approval and returns `undo_token`.
   - Status changes (publish/disable/draft) execute directly and still return `undo_token` for safety.

## Plugin Functional Areas (Candidates for AI)

1. Dashboard
   - Read KPIs (forms count, active/disabled, submissions, users)
   - Refresh/rebuild charts
2. Forms
   - Create a new form (title)
   - Search/filter forms (title, status, date range)
   - Open builder/editor for a form
   - Activate/disable/draft forms (publish/close/draft)
   - Rename form (update title)
   - Delete form (with confirmation)
3. Builder / Editor
   - Add a question (type, label, help text)
   - Reorder questions
   - Toggle per-question options (required, randomize, layout)
   - Edit messages (welcome/thank_you) with image upload
   - Remove a question
   - Save and return to builder
4. Preview
   - Render a form by ID
   - Switch theme for preview
5. Results (Submissions)
   - List submissions for a form (paging)
   - Filter by field (eq/neq/like), status, date range
   - Export CSV/Excel (respecting filters)
   - Toggle wrap/nowrap preference
6. Users
   - List/search users, filter by role
   - Invite user (email), set role (admin/editor/viewer)
   - Remove user (with confirmation)
7. Settings
   - Get/set plugin options (e.g., defaults, feature toggles)
   - Theme, RTL/LTR toggles, masks
8. System / Utilities
   - Help: list capabilities, show examples
   - Open docs/links
   - Debug: enable/disable ARSH debug flags

## Permission & Safety

- Every mutating action must check capability (WP roles/caps)
- Confirmation required for destructive ops (delete form/question, remove user)
- Log AI-triggered changes (user id, time, action, params)

## Proposed Next Steps

- Backend: Implement `Hoshyar` orchestrator with a capability map and intent routing
- REST: Harden `/ai/agent` to accept `{ intent, params }`, emit `{ confirm_action }` where needed
- Frontend: Command presets and quick actions (buttons) for frequent tasks
- Docs: Provide examples for each action with Persian phrasing

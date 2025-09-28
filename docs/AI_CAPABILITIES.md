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
- Open form builder/editor/preview/results by ID
- Open/download URLs
- Confirm/clarify prompts (two-step operations)

## Plugin Functional Areas (Candidates for AI)

1. Dashboard
   - Read KPIs (forms count, active/disabled, submissions, users)
   - Refresh/rebuild charts
2. Forms
   - Create a new form (title)
   - Search/filter forms (title, status, date range)
   - Open builder/editor for a form
   - Duplicate/rename/archive/activate/deactivate forms
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

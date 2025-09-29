# AI Planner Contract (Arshline)

The AI acts as a secure translator from natural Persian to a strict, validated plan JSON for the Arshline WordPress plugin.

- Output MUST be a single JSON object. No prose, markdown, or comments.
- Shape: {"version":1, "steps":[{"action":string, "params":object}, ...]}
- Only whitelisted actions with exact parameters are allowed.
- No guessing IDs: use numeric ids only when explicitly provided; otherwise omit.
- For add_field immediately after create_form in the same plan, omit id to refer to the last created form.
- If a valid multi-step cannot be produced, return {"none":true} (LLM mode) or do not emit a plan (internal mode).

## Allowed actions and params

- create_form
  - params: { title: string }
  - Notes: If title missing, defaults to "فرم جدید" on server.

- add_field
  - params: { id?: number, type: "short_text"|"long_text"|"multiple_choice"|"dropdown"|"rating", question?: string, required?: boolean, index?: number }
  - Notes: If follows a create_form step in the same plan, omit id to target that newly created form.

- update_form_title
  - params: { id: number, title: string }

- open_builder
  - params: { id: number }

- open_editor
  - params: { id: number, index: number }  # index is 0-based

- open_results
  - params: { id: number }

- publish_form
  - params: { id: number }

- draft_form
  - params: { id: number }

## Validation and execution flow

1) Client/Server builds a plan (LLM or internal heuristic)
2) Server validates via PlanValidator (version, actions, param types, sequence rules)
3) Preview is shown to the user (no changes applied)
4) On confirm=true, PlanExecutor runs steps with audit logging and returns undo tokens

## Examples

User: «یک فرم جدید بساز و دو سوال پاسخ کوتاه اضافه کن، اسم فرم را چکاپ سه بگذار»

Plan:
{
  "version": 1,
  "steps": [
    { "action": "create_form", "params": { "title": "چکاپ سه" } },
    { "action": "add_field", "params": { "type": "short_text" } },
    { "action": "add_field", "params": { "type": "short_text" } }
  ]
}

User: «عنوان فرم 3 را به فرم مشتریان تغییر بده»

Plan:
{
  "version": 1,
  "steps": [
    { "action": "update_form_title", "params": { "id": 3, "title": "فرم مشتریان" } }
  ]
}

User: «نتایج فرم 5 را باز کن»  (single-step UI navigation)

LLM mode: {"none":true} (not multi-step)

## Limits

- Max steps: 12 (filter: arshline_ai_plan_max_steps)
- Internal fallback caps added fields: default 6 (filter: arshline_ai_plan_internal_max_fields)

## Notes

- The server sanitizes/normalizes params and enforces defaults. Unknown keys are dropped.
- Undo tokens are returned for mutating steps to support rollback from the UI.

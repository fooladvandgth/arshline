# Hybrid AI Analysis Strategy (هوشیار)

This document states the agreed strategy for analytics queries (e.g., “حال نیما چطوره؟”) balancing cost, speed, privacy, and flexibility.

## One-line position
Adopt a hybrid by default: keep server-side preprocessing for efficiency and privacy, escalate to an AI-driven subset only when ambiguity or complexity is detected.

## Default path: server-side (fast/cheap/private)
Stay in server mode when:
- Unique, confident match (e.g., best_score ≥ 0.75, or a single exact match after normalization).
- Few strong candidates (≤ 2) with clear recency preference.
- Deterministic intents (counts, fields listing, simple aggregates).

Rationale: lower latency and token use, less data exposure, Persian-aware matching (faNorm, stripTitles, tokSim, simLev) already strong.

## Escalation: AI-driven subset (flexible/creative)
Escalate if one or more hold:
- Ambiguity: multiple partials ("_partial"), best_score in 0.50–0.75 with 3+ candidates.
- Low confidence: below threshold or fallback reason indicates no clear name match; empty/thin chunk results.
- Complex ask: comparisons/trends/group summaries.

What to send (subset, not full table):
- Columns: only relevant to the intent (e.g., name, mood_text, mood_score, created_at). Add phone/email only when explicitly asked.
- Rows: cap at 300–500; for person queries, include all candidate persons with last N entries (e.g., N=3–5). For trends, sample fairly across recent windows.
- Sanitization: mask/hash PII by default; never send unrelated sensitive columns.
- Instructions: ask model to disambiguate names, prefer most recent rows, and output strict JSON.

## Rare full-table sends (opt-in)
Only for small datasets or admin override; enforce hard token ceilings and auto-downgrade to subset when exceeded.

## Guardrails & SLAs
- Token budgets: typical ≤ 8k input, max ≤ 32k on explicit override.
- Latency: < 3s server path, < 8s escalated.
- Privacy: column whitelists per intent, default masking for PII, admin-only debug visibility.

## Telemetry & observability
Attach to debug payloads:
- ambiguity_score (from best_score vs threshold, match counts, partial tags, candidate_rows).
- ai_decision: { route: 'server'|'subset-ai'|'full-ai', reason, metrics }.
- Preserve matched_ids_tagged, fallback_reason, candidate_rows, matched_columns; add final_notes when adjustments are applied.

## Settings
- AI Mode: Efficient | Hybrid (default) | AI-Heavy.
- Max rows per AI prompt, PII allowance toggle (off by default), token ceilings.

## Rollout plan
1) Ship hybrid behind a feature flag; collect metrics (accuracy, tokens, latency, route distribution).
2) Tune thresholds (e.g., keep ≥ 0.75; escalate 0.50–0.75; clarify/escalate < 0.50).
3) Expand coverage for complex intents and refine column whitelists.

## Success metrics
- Match/answer success rate, p95 latency, tokens per request, privacy incidents (target zero), and escalation rate (~10–20%).

## User-facing summary (pasteable)
- «به‌طور پیش‌فرض، تحلیل‌ها با پیش‌پردازش سمت‌سرور انجام می‌شود تا سریع، کم‌هزینه و خصوصی باشد. فقط وقتی ابهام یا پیچیدگی تشخیص دهیم (مثل چند «نیما» نزدیک یا درخواست‌های مقایسه‌ای)، بخشی از داده‌های مرتبط (ستون‌ها و ردیف‌های محدود، بدون اطلاعات حساس) به مدل ارسال می‌شود تا خودش تشخیص و جمع‌بندی کند. این رویکرد ترکیبی، بهترین دقت و سرعت را با حداقل ریسک و هزینه فراهم می‌کند.»

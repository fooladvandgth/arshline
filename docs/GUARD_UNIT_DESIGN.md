# Guard Unit Design (Hoosha Form Builder)

> Examples are symptomatic only, not the targets of the fix. All logic must derive from plugin capabilities, not hardcoded sample cases.

## Mission
A self-aware validation + correction layer between AI refinement output and final schema acceptance. Enforces 1:1 mapping, semantic alignment, capability compliance, and safe automatic correction.

## High-Level Contract
**Input**
```
{
  user_questions: string[],         // raw or preprocessed user questions
  model_schema: { fields: [...] , meta?: {...} },
  baseline_schema?: { fields: [...] },
  settings?: array,                 // WP option arshline_settings
  capabilities?: { types:[], formats:[], rules:[] },
  mode: "diagnostic" | "corrective",
  request_id?: string
}
```
**Output**
```
{
  status: "approved" | "rejected",
  schema: { fields: [...] },
  issues: string[],                // includes structured codes e.g. E_COUNT_MISMATCH(...)
  notes: string[],                 // diagnostic annotations (guard:* tokens)
  metrics: {
    input_count: int,              // number of user_questions
    output_count: int,             // surviving fields after merges/prunes
    hallucinations_removed: int,   // pruned due to low similarity (corrective only)
    hallucination_count: int,      // total flagged hallucinations (diagnostic + corrective)
    semantic_merges: int,          // number of removed duplicates after canonical selection
    duplicate_semantic_count: int, // total extra semantic duplicates detected (pre-removal)
    type_corrections: int,         // type or format adjustments applied
    format_corrections: int,       // (reserved) future split of pure format changes
    similarity_avg: float,         // mean similarity over matched fields
    coverage_rate: float,          // distinct matched questions / input_count
    ai_intents: int,               // AI intent items returned
    ai_actions_applied: int,       // AI reasoning actions applied (drop/replace/merge)
    ai_validation_confidence: float|null // final validation model confidence
  },
  debug?: {...} // only if HOOSHA_GUARD_DEBUG (includes internal fieldInternal array)
}
```

## Phases
1. Capability Load (dynamic) ← scan plugin types + formats (extensible via filter `arshline_guard_capabilities`).
2. Meta Preflight (enforce meta counts if present).
3. Field Normalization (produce structured internal vector of fields with norm_label + tokens). Persian normalization pipeline:
  - Lowercase
  - Unify Persian/Arabic digits (۰١٢۳۴۵۶۷۸۹ -> 0-9)
  - Unify Arabic letter variants (ك→ک, ي→ی, ة/ۀ→ه, ئ→ی, أ/إ/آ→ا)
  - Strip tatweel (ـ)
  - Replace ellipsis (…) with '...'
  - Remove Arabic diacritics (harakat range 0610–061A, 064B–065F, etc.)
  - Normalize ZWNJ / zero-width spaces to a single space
  - Replace punctuation (؟ ? ، , ؛ ; . ! - :) with spaces
  - Collapse multiple spaces
  - Apply typo map & fuzzy short-token Levenshtein (distance=1) self-normalization
  - Remove polite/stop tokens (configurable minimal list)
4. Semantic Matching (each field → best question index + similarity; fuzzy Persian normalization + typo map & Levenshtein for short tokens).
5. Conflict Resolution / Canonical Merge (multi-fields mapped to same question → select canonical (highest similarity, tie: shortest norm label) and mark others merged; diagnostics only logs in diagnostic mode).
6. Hallucination Detection (below threshold & no semantic candidate → mark / remove in corrective mode).
7. Type & Format Enforcement (rule pipeline referencing capabilities map; no hardcoded examples).
8. One-to-One Enforcement (strict: final count MUST equal question count; if mismatch → reject with E_COUNT_MISMATCH; no placeholder padding yet).
9. Final Validation (no unknown types, no placeholder labeling, no unresolved duplicates).
10. Emit Logs & Summarize.

### Intent Rules (Structural Hallucination Mitigation)
Auto-injection of certain canonical fields (e.g., «ترجیح شما برای شیوه تماس؟») was removed to prevent structural hallucinations. Instead:
 - A lightweight `IntentRules` module defines intents (id, trigger_keywords, default_schema, auto_inject=false).
 - GuardUnit performs passive detection and only appends a note `intent_detected:<id>` to `notes`.
 - No field is injected unless future policy explicitly enables `auto_inject=true` (currently all false).
Benefits: prevents phantom duplicates, preserves strict 1:1 fidelity, defers schema changes to explicit model or user input rather than hardcoded assumptions.

## Mode Behavior
- diagnostic: No destructive changes; adds `guard:would_*` notes.
- corrective: Applies merges/prunes/corrections.
- ai-assist (optional overlay): When enabled (`HOOSHA_GUARD_AI_ASSIST` or option `guard_ai_assist`), three auxiliary phases run: intent analysis, reasoning (conflict actions), validation. High-confidence (≥ threshold) suggestions can adjust type/format before pruning.

## Logging (GuardLogger)
NDJSON lines (redacted as needed) with shape:
```
{ ts, ev, ...payload }
```
Event kinds:
- phase: { ev:"phase", phase:"capability_scan"|"count_mismatch"|... }
- field: { ev:"field", field_idx, action, score?, question_idx?, reason?, details? }
- ai_analysis / ai_analysis_start / ai_analysis_end
- ai_reasoning / ai_reasoning_start / ai_reasoning_end
- ai_validation / ai_validation_start / ai_validation_end
- summary: { ev:"summary", metrics:{...}, issues:[], notes:[] }
Toggle: `HOOSHA_GUARD_DEBUG` (constant) or `arshline_settings['guard_debug']`.

## Similarity
Base: token Jaccard over normalized Persian labels.
Config threshold: `guard_semantic_similarity_min` (0.8 default).
Hook: `arshline_guard_similarity` to override or adjust per pair.

## Hallucination Policy
- If similarity < threshold to all questions → hallucination.
- Corrective: remove; Diagnostic: note `guard:would_prune(label)`.
- Tolerance: 0 unless `allow_ai_additions` true.

## Type / Format Enforcement
Rules are derived from capability dictionary, e.g. (pseudo):
```
if containsKeyword(label, capability.format_keywords['national_id_ir']) => enforce format=national_id_ir
```
Dictionary can be assembled by scanning existing source arrays / registration functions.

## Fail Conditions
- E_COUNT_MISMATCH: Field count mismatch after merges/prunes (no auto padding strategy yet).
- Unknown/unsupported type or format persists.
- Hallucination present when not allowed (tolerance=0 unless allow_ai_additions).
- Meta declared counts invalid (input vs output) and not recoverable.

## Extensibility
- Filters: `arshline_guard_capabilities`, `arshline_guard_similarity`, `arshline_guard_result`.
- Future: embedding similarity module drop-in.

## Internal Structures
```
FieldInternal {
  idx, raw_label, norm_label, orig_type, working_type, props, matched_question?, similarity, status // original|merged|hallucination|corrected
}
```
Metrics accumulate per transition.

## Migration Plan
Phase 1: Run GuardUnit in diagnostic parallel to legacy GuardService.
Phase 2: Switch to corrective + deprecate GuardService usage.
Phase 3: Full replacement & expand for direct text→schema.

## Open Questions
- Should we pad missing fields (when model under-produces) with placeholders? (Current: reject)
- Where to store capability cache? (Option or transient) – postpone until performance measurable.

## Next Implementation Steps
1. Implement `GuardUnit` skeleton with run().
2. Implement capability loader stub.
3. Integrate semantic matching using existing `SemanticTools`.
4. Add GuardLogger.
5. Wire optional parallel invocation in `maybe_guard`.

---

## Implementation Status (Phase 1 Delivered)
| Feature | Status | Notes |
|---------|--------|-------|
| GuardUnit Skeleton | ✅ | run() with phases meta→semantic→hallucination→count |
| Capability Scanner | ✅ | Dynamic extraction + transient cache + phase log |
| Semantic Similarity | ✅ | Jaccard token (normalized Persian) |
| Threshold Config | ✅ | Option `guard_semantic_similarity_min` (0.5–0.95) |
| Threshold Override (Test) | ✅ | `SemanticTools::setOverrideThreshold()` |
| Logging (Field-Level) | ✅ | `GuardLogger` phase/field/summary NDJSON |
| Mode Switch | ✅ | Constant or option (`diagnostic`/`corrective`) |
| Type/Format Correction | ✅ | Age, date, national id, phone, yes/no intent |
| Meta Enforcement | ✅ | Early in `OpenAIModelClient::refine` + Guard meta notes |
| Parallel Integration | ✅ | `maybe_guard` runs GuardUnit (diagnostic) alongside GuardService |
| Benchmark Summary | ✅ | Standard `BENCH_SUMMARY ...` line in bench script |
| Tests (Initial) | ✅ | SemanticTools + GuardService semantic merge test |

## Deviations & Deferred Items
| Design Element | Deviation | Rationale / Plan |
|----------------|-----------|------------------|
| Conflict Resolution merging multi-fields | Partial | Current phase stops at marking hallucination + basic corrections; rich merge semantics (prop merging) deferred to Phase 2. |
| One-to-one recovery (padding placeholders) | Not Implemented | Chosen to reject mismatch instead of synthetic placeholder to surface model weakness early. |
| Embedding Similarity Hook | Not Implemented | Await baseline KPI stabilization; maintain lightweight dependency footprint. |
| Filters (`arshline_guard_*`) | Not Yet Added | Scanning + logging stable first; planned next for external customization. |
| Advanced rule dictionary generation | Heuristic only | Extend to structured rule map in Phase 2. |

## Logging Schema (Updated)
Field Event Examples:
```
{ "ev":"field", "field_idx":2, "action":"semantic_link", "question_idx":1, "score":0.87 }
{ "ev":"field", "field_idx":3, "action":"flag_hallucination", "score":0.41 }
{ "ev":"field", "field_idx":3, "action":"prune", "reason":"low_similarity" }
{ "ev":"field", "field_idx":4, "action":"type_format_correct", "details":["type=number","format=mobile_ir"] }
```
AI Phase Events:
```
{ "ev":"ai_analysis_start", "request_id":"..." }
{ "ev":"ai_analysis", "label":"سن شما؟", "intent":"age", "confidence":0.94 }
{ "ev":"ai_analysis_end", "intent_count":6 }
{ "ev":"ai_reasoning_start" }
{ "ev":"ai_reasoning", "action":"drop", "target":"شماره موبایل قدیمی", "confidence":0.91, "reason":"duplicate semantic" }
{ "ev":"ai_reasoning_end", "actions_applied":1 }
{ "ev":"ai_validation_start" }
{ "ev":"ai_validation", "approved":true, "confidence":0.88 }
{ "ev":"ai_validation_end", "confidence":0.88 }
```
Phase Events:
```
{ "ev":"phase", "phase":"capability_scan", "types":18, "formats":26, "rules":6 }
{ "ev":"phase", "phase":"semantic_merge", "question_idx":3, "removed":1 }
{ "ev":"phase", "phase":"count_mismatch", "fields":5, "questions":6, "code":"E_COUNT_MISMATCH" }
```
Summary Example:
```
{ "ev":"summary", "metrics":{ "input_count":6, "output_count":6, "coverage_rate":1.0, "similarity_avg":0.82, "hallucination_count":1, "hallucinations_removed":1, "duplicate_semantic_count":1, "semantic_merges":1 }, "issues":[], "notes":["guard:semantic_merge(q=3,removed=1)"] }
```

## Metrics Definitions (Updated)
| Metric | Definition |
|--------|-----------|
| input_count | Number of user questions provided |
| output_count | Final surviving field count after merges/prunes |
| similarity_avg | Mean similarity across fields with a matched question |
| coverage_rate | Distinct matched questions / input_count |
| hallucination_count | Total fields flagged as hallucination (any mode) |
| hallucinations_removed | Fields actually pruned (corrective mode) |
| duplicate_semantic_count | Extra semantic duplicates detected (pre-merge count minus 1 per cluster) |
| semantic_merges | Duplicates removed after canonical selection |
| type_corrections | Type/format enforcement changes applied |
| format_corrections | Reserved for future pure-format change tracking |
| ai_intents | AI intent items returned |
| ai_actions_applied | Reasoning actions applied with confidence ≥ threshold |
| ai_validation_confidence | Confidence from AI validation phase (null if unavailable) |

## Benchmark Integration
Script: `tools/run_guard_bench.php` emits per scenario JSON + final line:
```
BENCH_SUMMARY total_scenarios=<N> fidelity_ok=<0|1> avg_hallucination_rate=<float>
```
CI Parser Logic (example pseudocode):
```
line.startswith('BENCH_SUMMARY') -> parse key=value -> if fidelity_ok=0 or avg_hallucination_rate>0 fail
```

## Future Roadmap (Phase 2 & 3)
1. Conflict Grouping & Canonical Merge: cluster multiple candidate fields referencing same question; merge props/options.
2. Adaptive Threshold: dynamic lowering for short labels (≤2 tokens) to reduce false negatives; raising for long descriptive fields.
3. Embedding Similarity Fallback: when Jaccard < (threshold - margin) but token overlap sparse; call lightweight local embedding or remote service.
4. Rich Capability Rules: structured DSL: e.g. `rule { if label_has:["کد","ملی"] enforce format=national_id_ir confidence=0.95 }`.
5. Soft Suggestion Mode: instead of prune, propose patch diff `{ op:"prune", field_idx:3 }` for UI approval.
6. Historical Drift Monitoring: store rolling similarity_avg & hallucination_rate to flag regressions after prompt/model updates.
7. Formal Filter API: `arshline_guard_similarity`, `arshline_guard_capabilities`, `arshline_guard_decision`, `arshline_guard_result`.
8. Structured Error Codes: replace plain issues strings with stable codes + severity levels to drive UI badges.
9. Multi-Language Extension: normalization pipeline pluggable for other locales.
10. Performance Profiling: per-phase timings (`phase_timing_ms`) appended into summary line.

## Validation Checklist (Current Build)
- [x] Field-level logging active (enable flag tested)
- [x] Capability scan event present
- [x] Meta violations attach notes
- [x] Hallucination pruning only in corrective mode
- [x] Type corrections logged with detailed list
- [x] Threshold override modifies clustering (test asserts merge)
- [x] Benchmark emits BENCH_SUMMARY
- [x] README updated with architecture & operations

## Risk & Mitigation
| Risk | Impact | Mitigation |
|------|--------|-----------|
| Over-pruning legitimate low-sim questions | Data loss | Start in diagnostic, adjustable threshold, logging retained |
| Capability drift (new formats) not captured | Misclassification | Dynamic scanner on each cache expiry; add filter for external injection |
| Logging overhead on large forms | Latency | Flag-controlled; can disable in production |
| Jaccard insufficiency for synonyms | Missed merges | Plan embedding fallback + extended synonym lists |
| Regex heuristic false positives (date/phone) | Wrong type corrections | Keep changes minimal & reversible, add future confidence scoring |

## Summary
Current build delivers:
- Fuzzy Persian normalization (typo map + Levenshtein) feeding semantic similarity.
- Canonical semantic merge with metrics for duplicates vs merges.
- Expanded metrics (hallucination_count, duplicate_semantic_count, coverage_rate) and strict error code `E_COUNT_MISMATCH`.
- Structured AI assist logging with start/end events for analysis, reasoning, validation.
- Deterministic pruning & merge behavior in corrective mode; diagnostic retains observability without destructive edits.

Next focus: optional placeholder recovery strategy, embedding fallback, richer DSL rules, and structured severity levels for issue codes.

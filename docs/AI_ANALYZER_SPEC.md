# Analyzer Pipeline Redesign (Production Spec)

نسخه: 1.0 · تاریخ: 1404-07-11 · دامنه: افزونه فرم‌ساز/آنالیزور عرشلاین

هدف: بازنویسی کامل منطق ارسال/دریافت/تحلیل برای پرس‌وجوی کاربر روی «فرم‌ها» با رویکرد کم‌هزینه، سریع، امن (حداقل داده)، و قابل‌اتکا با این اجزا:

- قراردادهای JSON دقیق و قطعی (I/O) برای هر فاز
- سیاست‌های مسیر‌یابی/مدل (cost/latency-aware) با آستانه‌های عددی
- کاهش داده ارسالی (pruning ستون/سطر) با قوانین مشخص
- سیاست fallback/escalation برای 4xx/5xx و عدم‌قطعیت
- پرامپت‌های فرعی برای Mapper (فاز ۱) و Analyzer (فاز ۲)
- پوشش چندزبانه (فارسی/مخلوط)
- لاگ و ردیابی کم‌ریسک (بدون نشت داده)
- تست‌های واحد و ادغام حداقلی


## 1) مفاهیم و فرض‌ها

- Form Header = ردیف بالای جدول شامل سوال/نام ستون‌ها. هر ستون: id, key, title, type?, semantic_tags?
- Rows = رکوردها (افراد/شهرها/آیتم‌ها ...). سلول‌ها به‌وسیله column_id اشاره می‌شوند.
- Query = متن کاربر (ممکن است فارسی/مخلوط باشد). هدف: پاسخ به پرسش یا اجرای تحلیل محدود.
- حریم خصوصی: فقط ستون‌های «مرتبط» و فقط «سطر(های) لازم» به مدل ارسال می‌شود.
- هزینه/تاخیر: برای پرسش‌های ساده از مدل ارزان، برای پیچیده به‌صورت پلکانی ارتقا.
- قطعیّت: هر فاز خروجی JSON با schema صریح می‌دهد؛ فاز بعد فقط بر اساس همان JSON تصمیم می‌گیرد.


## 2) خط لوله (Pipeline)

ترتیب اجرا:

0. Pre-Guard: نرمال‌سازی ورودی، تشخیص زبان، برش طول پرسش (hard-cap)، محاسبه توکن تخمینی.
1. Phase-1 (Column Mapping): نگاشت ستون‌های مرتبط + استخراج موجودیت/قصد + احتمال‌ها.
2. Scope Decision: تعیین دامنه ارسال فاز ۲ (row-scoped vs subset vs broad) بر اساس قطعیت/قصد.
3. Model Routing: انتخاب مدل تحلیل بر پایه پیچیدگی، عدم‌قطعیت، و بودجه.
4. Phase-2 (Scoped Analysis): ارسال داده‌های prune‌شده، تولید پاسخ ساخت‌یافته با استناد.
5. Post-Process: بررسی اعتبار خروجی، fallback در خطا/عدم‌قطعیت، صورت‌حساب توکن، تلمتری کم‌ریسک.


## 3) قراردادهای JSON (Schemas)

یادداشت:
- انواع عددی احتمال و اعتماد در بازه [0,1].
- تمام تاریخ/ساعت ISO8601. 
- رشته‌های زبان (`language`) از BCP-47 (مانند "fa", "en").

### 3.1 Phase-1: Column Mapping

Input Schema (MapperRequest):

```
type: object
required: [query, header]
properties:
  query: { type: string, minLength: 1, maxLength: 4000 }
  language_hint: { type: string, nullable: true }
  header:
    type: array
    minItems: 1
    items:
      type: object
      required: [id, title]
      properties:
        id: { type: [string, integer] }
        key: { type: [string, "null"], maxLength: 190 }
        title: { type: string, minLength: 1 }
        type: { type: [string, "null"], enum: ["text","number","date","city","person","bool","other", null] }
        semantic_tags: { type: [array, "null"], items: { type: string } }
```

Output Schema (MapperResponse):

```
type: object
required: [language, columns, intents, entities, confidence]
properties:
  language: { type: string }
  columns:
    type: array
    items:
      type: object
      required: [column_id, probability]
      properties:
        column_id: { type: [string, integer] }
        probability: { type: number, minimum: 0, maximum: 1 }
        reason_code: { type: [string, "null"], enum: ["lexical_match","semantic_match","type_match","synonym","other", null] }
  intents:
    type: array
    items:
      type: object
      required: [name, probability]
      properties:
        name: { type: string, enum: [
          "lookup","compare","aggregate","filter","trend","sentiment","mood","state","ask_definition","other"
        ] }
        probability: { type: number, minimum: 0, maximum: 1 }
  entities:
    type: array
    items:
      type: object
      required: [text, type, probability]
      properties:
        text: { type: string }
        normalized: { type: [string, "null"] }
        type: { type: string, enum: ["person","city","org","product","date","number","location","other"] }
        subtype: { type: [string, "null"] }
        probability: { type: number, minimum: 0, maximum: 1 }
        span: { type: [object, "null"], properties: { start:{type:integer}, end:{type:integer} } }
  confidence: { type: number, minimum: 0, maximum: 1 }
  meta: { type: object, additionalProperties: true }
```

توضیحات:
- entities شامل «mood/state» به‌صورت intent است، نه entity. اما اگر جمله حامل احساس نسبت به شخص باشد، entity.type=person و intent=mood/state.
- columns احتمال‌های مستقل هستند؛ در تصمیم‌گیری با آستانه/Top-K استفاده می‌شوند.

### 3.2 Phase-2: Scoped Analysis

Input Schema (AnalyzerRequest):

```
type: object
required: [query, language, header, selected_columns, scope]
properties:
  query: { type: string }
  language: { type: string }
  header: { $ref: "MapperRequest#/properties/header" }
  selected_columns:
    type: array
    minItems: 1
    items:
      type: object
      required: [column_id, source_probability]
      properties:
        column_id: { type: [string, integer] }
        source_probability: { type: number, minimum: 0, maximum: 1 }
  scope:
    type: object
    required: [mode]
    properties:
      mode: { type: string, enum: ["row","subset","broad"] }
      row:
        type: [object, "null"]
        properties:
          id: { type: [string, integer] }
          cells: { type: object, additionalProperties: true }
      rows_subset:
        type: [array, "null"]
        items:
          type: object
          properties:
            id: { type: [string, integer] }
            cells: { type: object, additionalProperties: true }
  limits:
    type: object
    properties:
      max_input_tokens: { type: integer, minimum: 1024 }
      redact_rules: { type: [array, "null"], items: { type: string } }
  routing:
    type: object
    properties:
      model: { type: string }
      budget_tier: { type: string, enum: ["low","medium","high"] }
      retry: { type: integer, minimum: 0, maximum: 3 }
```

Output Schema (AnalyzerResponse):

```
type: object
required: [answer, confidence, citations]
properties:
  answer: { type: string, minLength: 1, maxLength: 4000 }
  confidence: { type: number, minimum: 0, maximum: 1 }
  citations:
    type: array
    items:
      type: object
      required: [row_id]
      properties:
        row_id: { type: [string, integer] }
        columns: { type: [array, "null"], items: { type: [string, integer] } }
        excerpts: { type: [array, "null"], items: { type: string, maxLength: 200 } }
  findings: { type: [object, "null"], additionalProperties: true }
  followup_needed: { type: boolean, default: false }
  followup_reason: { type: [string, "null"], maxLength: 500 }
  route_feedback: { type: [string, "null"], enum: ["need_more_rows","need_more_columns","ambiguous_intent", null] }
  meta: { type: object, additionalProperties: true }
```


## 4) تصمیم دامنه (Scope Decision)

ورودی: MapperResponse.columns/intents/entities + قواعد زیر.

- آستانه ستون مرتبط: include اگر probability ≥ 0.35. اگر مجموع پوشش < 0.8 → Top-K تا رسیدن به 0.8 (حداکثر K=6).
- شناسایی «entity هدف سطری»: اگر entity.type ∈ {person, product, org, city} و probability ≥ 0.7 و در داده شناسه/کلید مطابق داریم → mode="row" با همان سطر.
- اگر intent ∈ {aggregate, trend, compare} یا ambiguity بالا (MapperResponse.confidence < 0.55) → mode="subset" یا "broad".
  - subset پیش‌فرض: rows_subset حداکثر 200 ردیف، فقط selected_columns. 
  - broad فقط وقتی که محدوده خیلی مبهم است یا پاسخ قبلی نامرضی بوده (feedback)؛ در broad نیز فقط selected_columns ارسال می‌شوند.
- قانون ویژه «شخص/موجودیت + ستون خالی»: اگر intent حاکی از حالت/احساس(person-mood/state) باشد و matched_columns خالی یا بسیار ضعیف باشد اما entity شخص با اطمینان ≥ 0.7 شناسایی شد → mode="subset" با ستون‌های عمومی مرتبط (مثلا notes, status, last_update اگر موجود) برای جلوگیری از پاسخ تهی.


## 5) مدل‌ها و مسیر‌یابی (Routing Policy)

کتابخانه مدل (قابل‌پیکربندی از تنظیمات افزونه):

- tiny: gpt-4o-mini (پیش‌فرض)
- base: gpt-4o
- deep: gpt-4o (با سقف توکن بالاتر و دستورالعمل reasoning دقیق‌تر)

نکته: نام‌های کاربر یا قدیمی نرمال‌سازی شوند (مثال: "gpt-5-mini" → tiny). قانون نرمال‌سازی باید نگاشت امن تعریف کند: {"gpt-5-mini"→"gpt-4o-mini"} و دیگر نا-موجودها → نزدیک‌ترین پشتیبانی‌شده.

امتیاز پیچیدگی (complexity_score ∈ [0,1]):

- طول پرسش (توکن): <60 → +0.0, 60–200 → +0.2, 200–600 → +0.4, >600 → +0.6
- عملگرها/قصدها: aggregate/compare/trend → +0.3; filter چندگانه → +0.2; sentiment/mood → +0.1
- چند موجودیتی/ابهام: ≥2 entity با نوع متفاوت → +0.2; Mapper.confidence <0.55 → +0.2
- نیاز به چند سطر: scope=subset/broad → +0.2

امتیاز عدم‌قطعیت (uncertainty ∈ [0,1]):

- uncertainty = 1 - Mapper.confidence, capped [0,1]
- اگر selected_columns پس از آستانه کمتر از 2 ستون باشد و intent نه‌چندان واضح → +0.2 (clamped)

آستانه‌ها و بودجه (budget_tier):

- low: اجازه tiny فقط؛ اگر complexity>0.65 یا uncertainty>0.6 → tiny با محدودیت ورودی/خروجی و توصیه followup.
- medium: 
  - اگر complexity≤0.35 و uncertainty≤0.3 → tiny
  - اگر complexity≤0.65 یا uncertainty≤0.5 → base
  - در غیر این‌صورت → deep
- high: 
  - complexity≤0.25 و uncertainty≤0.25 → tiny
  - complexity≤0.55 → base
  - else → deep

سقف توکن‌ها (پیشنهادی):

- tiny: input≤6k, output≤512
- base: input≤20k, output≤1024
- deep: input≤40k, output≤1536

سیاست fallback/retry:

- 4xx (400/404/422 Schema): 1 بار re-prompt با دستور «JSON-only + schema» → اگر باز خطا: downgrade یک سطح مدل.
- 429/5xx: backoff (0.5s, 1s, 2s) تا 3 بار؛ اگر شکست: fallback به مدل پایین‌تر با همان ورودی؛ اگر باز هم شکست: ساده‌سازی دامنه (subset→tiny، حذف ستون‌های کم‌اهمیت) و پاسخ حداقلی + followup_needed=true.
- پاسخ با confidence<0.5 و scope=row: ارتقا مدل یک سطح و تکرار یک‌بار؛ اگر باز <0.5 → پیشنهاد broad/subset در route_feedback.


## 6) پرامپت‌ها (Templates)

تمام پرامپت‌ها: خروجی «فقط JSON» مطابق schema. از توضیح آزاد خودداری شود.

### 6.1 Mapper (Phase-1)

System:

```
You are a fast column-mapper and intent/entity classifier. 
Language can be Persian or mixed. Respond ONLY in strict JSON complying with MapperResponse schema. 
Return probabilities in [0,1]. Do not add any text outside JSON. 
If uncertain, reflect it in confidence and probabilities; do not invent columns.
```

User Template:

```
QUERY:
{{query}}

HEADER COLUMNS (id | title | type? | tags?):
{{#each header}}
- {{id}} | {{title}} | {{type}} | {{semantic_tags}}
{{/each}}

REQUIRED OUTPUT JSON KEYS: language, columns, intents, entities, confidence
```

Notes:
- زبان پاسخ را مطابق تشخیص برگردانید (fa برای فارسی).
- intents شامل: lookup, compare, aggregate, filter, trend, sentiment, mood, state, ask_definition.

### 6.2 Analyzer (Phase-2)

System:

```
You are a precise data analyst. Use only provided columns/rows. 
No assumptions beyond data. Respond ONLY as strict JSON complying with AnalyzerResponse. 
Keep answer concise, in user's language. Include citations with row_id and related columns.
If data is insufficient, set followup_needed=true and route_feedback accordingly.
```

User Template (row scope):

```
QUERY:
{{query}}

SELECTED COLUMNS:
{{selected_columns}}

ROW (id={{row.id}}):
{{#each selected_columns}}
{{this}}: {{row.cells[this]}}
{{/each}}

OUTPUT KEYS: answer, confidence, citations, findings?, followup_needed?, followup_reason?, route_feedback?, meta?
```

User Template (subset/broad):

```
QUERY:
{{query}}

SELECTED COLUMNS:
{{selected_columns}}

ROWS (each row lists only selected columns; max N={{N}}):
{{#each rows_subset}}
- id={{id}} | {{col1}}={{cells[col1]}}, {{col2}}={{cells[col2]}}, ...
{{/each}}

If aggregations/comparisons are implied by the query, compute them from provided rows. 
If rows are insufficient to answer confidently, indicate followup_needed=true and route_feedback.
```


## 7) ادغام با افزونه (Integration Notes)

نقاط اتصال در PHP (`src/Core/Api.php`):

پیشنهاد توابع داخلی:

```php
// 0) پیش‌پردازش
function ar_ai_pre_guard(string $query): array { /* detect language, trim, token estimate */ }

// 1) نگاشت ستون‌ها
function ar_ai_map_columns(array $header, string $query, ?string $lang_hint): MapperResponse { /* call tiny model with Mapper prompt */ }

// 2) تعیین دامنه
function ar_ai_decide_scope(MapperResponse $m, array $header, callable $row_lookup): array {
  // returns ['mode' => 'row|subset|broad', 'selected_columns' => [...], 'row' => ?, 'rows_subset' => ?]
}

// 3) مسیر‌یابی مدل
function ar_ai_pick_model(float $complexity, float $uncertainty, string $budget): string { /* per section 5 */ }

// 4) تحلیل فاز ۲ + fallback
function ar_ai_analyze(array $analyzerRequest, array $routing): AnalyzerResponse { /* call model; retries; 4xx/5xx rules */ }

// 5) نرمال‌سازی نام مدل
function ar_ai_normalize_model(string $name): string { /* map unsupported -> supported (e.g., gpt-5-mini => gpt-4o-mini) */ }
```

اقدام‌ها در `analytics_analyze`:

1. pre_guard → `lang`, `token_est`.
2. map_columns (tiny) → MapperResponse.
3. decide_scope → mode, selected_columns, rows.
4. complexity/uncertainty محاسبه → pick_model(budget).
5. assemble AnalyzerRequest (pruned data) + routing limits.
6. call model with retries/fallback (شامل 5xx).
7. validate AnalyzerResponse schema; اگر invalid → re-prompt یا downgrade.
8. برگرداندن پاسخ + telemetry سبک (route/model/latency/token_estimates).

Telemetry سبک (log):
- route_mode, model, budget, token_in/out (تخمینی)، retries, http_status.
- عدم ذخیره payload کامل برای حفظ حریم خصوصی.


## 8) سیاست حریم خصوصی و کاهش داده

- فقط ستون‌هایی که در selected_columns قرار می‌گیرند ارسال شوند.
- در scope=row فقط همان سطر؛ در subset حداکثر 200 سطر؛ در broad نیز فقط selected_columns.
- ماسک‌کردن مقادیر حساس (email/phone/national_id) با الگوهای ساده مگر اینکه پرسش مستقیماً به آن‌ها اشاره کند با intent=lookup و دسترسی مجاز.
- برش طول مقادیر طولانی (excerpts ≤ 200 کاراکتر در citations).


## 9) تست‌ها (نمونه‌ها)

قالب‌ها خلاصه شده‌اند؛ هدف پوشش منطق است. انتظار می‌رود confidence در بازه ذکرشده باشد.

1) شخص + احساس (fa):
- Query: "حال نیما چطور است؟"
- Mapper: entities=[{type:person, text:"نیما", p>=0.8}], intents includes mood/state p>=0.7; columns احتمالی: [notes/status/last_update]
- Scope: اگر سطر نیما یافت شد → row؛ در غیر این‌صورت subset.
- Model: tiny یا base بر اساس عدم‌قطعیت.

2) شهر + وضعیت (fa):
- Query: "وضعیت خرم‌آباد چیه؟"
- Disambiguation: city vs person. اگر city با p>=0.7 → columns مرتبط (weather/status/city).
- Scope: subset.

3) مقایسه دو فرم/دو شخص:
- intents شامل compare (p>=0.6). Scope=subset، مدل=base.

4) جمع‌زنی/میانگین:
- aggregate (p>=0.7). اگر selected_columns نوع number دارد → base؛ در فقدان → followup_needed=true.

5) مبهم + columns کم:
- Mapper.confidence<0.55 → scope=subset, model=base.

6) matched_columns خالی اما entity شخص قوی:
- Rule ویژه فعال → subset با ستون‌های عمومی.

7) پرسش کوتاه ساده:
- complexity≤0.25 و uncertainty≤0.25 → tiny.

8) زبان مخلوط:
- language="fa" یا "fa-Latn" برگردد؛ پاسخ به زبان کاربر.

9) 5xx از ارائه‌دهنده:
- سه تلاش با backoff؛ سپس fallback به مدل پایین‌تر؛ در نهایت پاسخ حداقلی + followup_needed.

10) 422 (JSON invalid):
- re-prompt یکبار؛ سپس downgrade.

11) بودجه کم:
- budget=low → همیشه tiny، حتی اگر پیچیده؛ با route_feedback مناسب.

12) broad پس از نارضایتی کاربر:
- اگر prior_feedback=unsatisfied → scope=broad با همان selected_columns و مدل base.


## 10) معیارهای موفقیت

- دقت نگاشت ستون‌ها: ≥ 90% در تست‌های نمونه.
- کاهش داده: ≤ 25% ستون‌ها به‌طور متوسط ارسال شوند؛ در row فقط 1 سطر.
- هزینه: ≥ 70% درخواست‌ها با tiny پاسخ می‌گیرند؛ Escalation کنترل‌شده.
- تأخیر: پرسش‌های ساده ≤ 1.2s (محلی/کَش)؛ پیچیده ≤ 4s (به‌تناسب زیرساخت).
- پایداری: 5xx/429 به پاسخ حداقلی و راهکار fall back ختم شود، نه سکوت.


## 11) گام‌های مهاجرت

1. افزودن نرمال‌ساز نام مدل در لایه تنظیمات (`ai_model`, `ai_model_mode=auto`).
2. پیاده‌سازی `ar_ai_map_columns` با tiny.
3. افزودن Scope Decision طبق بخش 4 (از جمله قانون ویژه شخص/ستون خالی).
4. اعمال Routing Policy (بخش 5) + backoff/fallback برای 5xx.
5. به‌روزرسانی `analytics_analyze` برای تولید AnalyzerRequest و اعتبارسنجی خروجی.
6. افزودن تلماتری سبک + counters.
7. نوشتن تست‌های واحد برای Mapper و Analyzer با payload فرضی.


## 12) الحاقات (پیکربندی)

تنظیمات جدید/بهبود:

- ai_model_mode: { auto|manual } (پیش‌فرض: auto)
- ai_budget_tier: { low|medium|high } (پیش‌فرض: medium)
- ai_model_tiny: default "gpt-4o-mini"
- ai_model_base: default "gpt-4o"
- ai_model_deep: default "gpt-4o"
- ai_max_rows_subset: default 200
- ai_col_prob_threshold: default 0.35
- ai_col_coverage_target: default 0.8
- ai_retry_max: default 3
- ai_backoff_ms: [500, 1000, 2000]


— پایان سند —
